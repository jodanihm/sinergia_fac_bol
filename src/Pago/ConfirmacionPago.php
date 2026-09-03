<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Pago;

use Closure;
use GuzzleHttp\Client;
use PDO;
use Plantiflex\FacturacionCl\Dto\CredencialesPasarela;
use Plantiflex\FacturacionCl\Enums\AmbientePasarela;
use Plantiflex\FacturacionCl\Exceptions\PasarelaTransitoriaException;
use Plantiflex\FacturacionCl\Providers\FlowPasarelaPago;
use Throwable;

/**
 * Decide que hacer con un aviso de pago de la pasarela.
 *
 * POR QUE VIVE AQUI Y NO DENTRO DEL HANDLER DEL PANEL
 * -----------------------------------------------------------------------------
 * Porque es la superficie de mayor consecuencia del modulo -- decide si una
 * factura se da por pagada -- y dentro de panel/public/index.php no se podia
 * probar: ese archivo es un front controller de 19.000 lineas que arranca
 * sesion y router al incluirlo. Una auditoria lo dejo por escrito: el handler
 * tenia CERO tests.
 *
 * Aqui recibe todo por parametro -- PDO, el POST, como descifrar, el cliente
 * HTTP -- y devuelve un codigo y un cuerpo. El handler del panel se limita a
 * emitirlos. Asi cada escenario (firma mala, cuenta ajena, monto distinto,
 * consulta caida) es un test.
 *
 *
 * LO QUE ESTA CLASE NO SE CREE
 * -----------------------------------------------------------------------------
 * Que el aviso signifique que hubo pago. La pasarela avisa IGUAL cuando el pago
 * se rechaza, y su aviso solo trae un identificador. Por eso siempre se le
 * vuelve a preguntar el estado real, y solo un 'pagada' con el MONTO EXACTO
 * marca la factura.
 */
final class ConfirmacionPago
{
    /**
     * @param array<string,mixed> $post   lo que llego, tal cual
     * @param Closure             $descifrar  (string $cifrado): string
     *
     * @return array{codigo:int, cuerpo:string, motivo:string}
     *         'motivo' NO viaja al cliente: es para el log. El cuerpo que ve la
     *         pasarela es siempre una palabra sin informacion.
     */
    public static function procesar(
        PDO $pdo,
        int $cuentaId,
        array $post,
        Closure $descifrar,
        ?Client $http = null,
    ): array {
        // SIN "AND habilitado = 1", Y ES UNA CORRECCION.
        //
        // El interruptor gobierna la CREACION de links nuevos, no la recepcion de
        // avisos de links ya creados. Con el filtro puesto, una empresa que
        // desactivara el cobro -- algo que la propia pantalla recomienda para
        // desatascar la cola -- empezaba a responder 403 a la pasarela: sus
        // clientes seguian pudiendo pagar los links ya emitidos y esos pagos no
        // se registraban jamas. Apagar el grifo no tira el agua que ya salio.
        $stmt = $pdo->prepare(
            'SELECT proveedor, ambiente, credencial_publica, credencial_cifrada '
            . 'FROM pago_pasarela_cuenta WHERE cuenta_id = :c LIMIT 1'
        );
        $stmt->execute([':c' => $cuentaId]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);

        // Una cuenta sin pasarela nunca pudo emitir un link, asi que un aviso
        // para ella no existe. Misma respuesta que ante una firma mala:
        // cualquier otra delataria si esa cuenta existe.
        if ($config === false) {
            return self::r(403, 'no', 'cuenta sin pasarela configurada');
        }

        try {
            $secreto = $descifrar((string) $config['credencial_cifrada']);
        } catch (Throwable $e) {
            return self::r(500, 'error', 'no se pudo descifrar el secreto');
        }

        // is_scalar filtra arrays anidados: strval() sobre un array emite warning
        // y produce "Array", que rompe la firma de una forma que cuesta explicar.
        $recibido = array_filter($post, 'is_scalar');
        $firma    = (string) ($recibido['s'] ?? '');
        unset($recibido['s']);

        // hash_equals y no ===: comparar firmas con == filtra por el tiempo que
        // tarda en fallar cuantos caracteres iniciales acerto quien lo intente.
        $esperada = FlowPasarelaPago::firmar(array_map('strval', $recibido), $secreto);
        if ($firma === '' || ! hash_equals($esperada, $firma)) {
            return self::r(403, 'no', 'firma invalida');
        }

        $token = trim((string) ($recibido['token'] ?? ''));
        if ($token === '') {
            return self::r(200, 'ok', 'aviso sin token');
        }

        // LA FILA SE BUSCA POR TOKEN Y POR CUENTA, antes de preguntar nada.
        //
        // cuenta_id en el WHERE es lo que impide que un aviso firmado por una
        // empresa toque la orden de otra. El token es lo unico que trae el aviso,
        // asi que es por donde hay que entrar -- y por eso la orden se guarda con
        // el token como identificador externo.
        $stmt = $pdo->prepare(
            'SELECT id, monto FROM dte_pago_link WHERE cuenta_id = :c AND orden_externa = :t LIMIT 1'
        );
        $stmt->execute([':c' => $cuentaId, ':t' => $token]);
        $link = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($link === false) {
            // O no es nuestra, o es la carrera de que el aviso llegue antes de
            // que terminemos de guardar la orden. 503 para que la pasarela
            // reintente: si era la carrera se resuelve sola, y si no lo era, la
            // pasarela se rinde tras sus propios reintentos.
            return self::r(503, 'reintenta', 'orden desconocida para esta cuenta');
        }

        $linkId = (int) $link['id'];

        try {
            $estado = FabricaPasarela::crear((string) $config['proveedor'], $http)->consultarEstado(
                $token,
                new CredencialesPasarela(
                    apiKey:   (string) $config['credencial_publica'],
                    secreto:  $secreto,
                    ambiente: AmbientePasarela::desde($config['ambiente'] ?? null),
                )
            );
        } catch (PasarelaTransitoriaException $e) {
            // NO SE RESPONDE 200, y este es el punto que mas importa de la clase.
            // Antes si: la pasarela daba el aviso por entregado, no lo repetia, y
            // el pago quedaba cobrado de verdad y sin registrar, sin nada que
            // volviera a mirarlo. Con 503 reintenta, y ademas queda la marca local
            // por si acaba rindiendose.
            self::marcarPendiente($pdo, $linkId, 'no se pudo consultar el estado: ' . $e->getMessage());

            return self::r(503, 'reintenta', 'consulta de estado transitoriamente caida');
        } catch (Throwable $e) {
            // Permanente o inesperado: reintentar no lo arregla. Se acusa recibo
            // y queda la marca para mirarlo a mano.
            self::marcarPendiente($pdo, $linkId, 'fallo permanente al consultar: ' . $e->getMessage());

            return self::r(200, 'ok', 'consulta de estado fallo de forma permanente');
        }

        if ($estado['pagada'] !== true) {
            // Pendiente, rechazada o anulada: la pasarela avisa igual. No se toca
            // la fila -- el link sigue vivo y pagable, que es lo correcto.
            return self::r(200, 'ok', 'el pago no esta confirmado');
        }

        // --- EL MONTO TIENE QUE CUADRAR EXACTO --------------------------------
        //
        // Un pago por un importe distinto al cobrado no se da por bueno. No se
        // marca pagado -- seria mentir sobre lo que se recibio -- ni se deja como
        // si nada -- ocultaria el incidente. Va a 'error', que hasta esta entrega
        // era un valor del ENUM que no escribia nadie.
        $esperado = (int) $link['monto'];
        $pagado   = $estado['monto'];

        if (! is_int($pagado) || $pagado !== $esperado) {
            $pdo->prepare(
                "UPDATE dte_pago_link SET estado = 'error', ultimo_error = :e, "
                . 'confirmacion_pendiente_at = :ahora WHERE id = :id'
            )->execute([
                ':e'     => sprintf('monto pagado %s distinto del cobrado %d', var_export($pagado, true), $esperado),
                ':ahora' => date('Y-m-d H:i:s'),
                ':id'    => $linkId,
            ]);

            return self::r(200, 'ok', sprintf(
                'MONTO DISTINTO: pagado %s, cobrado %d. NO se marca pagada.',
                var_export($pagado, true),
                $esperado
            ));
        }

        // estado <> 'pagado' hace idempotente un aviso repetido.
        $pdo->prepare(
            "UPDATE dte_pago_link SET estado = 'pagado', pagado_at = :ahora, "
            . "confirmacion_pendiente_at = NULL WHERE id = :id AND estado <> 'pagado'"
        )->execute([':ahora' => date('Y-m-d H:i:s'), ':id' => $linkId]);

        return self::r(200, 'ok', 'pago confirmado');
    }

    /**
     * Deja constancia de un aviso que no se pudo resolver.
     *
     * NO ES UN SISTEMA DE CONCILIACION -- eso seria otra cosa. Es el minimo para
     * que "que avisos quedaron sin mirar" se conteste con un SELECT en vez de con
     * un grep del log:
     *
     *   SELECT * FROM dte_pago_link
     *    WHERE confirmacion_pendiente_at IS NOT NULL AND estado <> 'pagado';
     *
     * No toca el estado: la orden sigue siendo lo que era.
     */
    private static function marcarPendiente(PDO $pdo, int $linkId, string $motivo): void
    {
        try {
            $pdo->prepare(
                'UPDATE dte_pago_link SET confirmacion_pendiente_at = :ahora, ultimo_error = :e WHERE id = :id'
            )->execute([
                ':ahora' => date('Y-m-d H:i:s'),
                ':e'     => mb_substr($motivo, 0, 500),
                ':id'    => $linkId,
            ]);
        } catch (Throwable $e) {
            // Si ni siquiera se puede anotar, no se puede hacer mas desde aqui.
            // El codigo de respuesta ya le dice a la pasarela que reintente.
        }
    }

    /** @return array{codigo:int, cuerpo:string, motivo:string} */
    private static function r(int $codigo, string $cuerpo, string $motivo): array
    {
        return ['codigo' => $codigo, 'cuerpo' => $cuerpo, 'motivo' => $motivo];
    }
}
