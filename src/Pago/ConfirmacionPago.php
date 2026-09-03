<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Pago;

use Closure;
use GuzzleHttp\Client;
use PDO;
use Plantiflex\FacturacionCl\Dto\CredencialesPasarela;
use Plantiflex\FacturacionCl\Enums\AmbientePasarela;
use Plantiflex\FacturacionCl\Exceptions\PasarelaTransitoriaException;
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
 * emitirlos. Asi cada escenario (token ajeno, monto distinto, consulta caida) es
 * un test.
 *
 *
 * LO QUE ESTA CLASE NO SE CREE
 * -----------------------------------------------------------------------------
 * Que el aviso signifique que hubo pago. La pasarela avisa IGUAL cuando el pago
 * se rechaza, y su aviso solo trae un identificador. Por eso siempre se le
 * vuelve a preguntar el estado real, y solo un 'pagada' con el MONTO EXACTO
 * marca la factura.
 *
 * EL AVISO NO LLEGA FIRMADO, y no es un descuido nuestro: Flow manda un POST con
 * el cuerpo token=<token> y nada mas. La firma HMAC es de las peticiones que
 * SALEN hacia su API. Exigirla aqui hacia que todo aviso real muriera en un 403
 * -- ver el comentario largo dentro de procesar(). Que la callback no venga
 * autenticada no la vuelve peligrosa porque de ella solo se toma un puntero: la
 * decision se apoya en payment/getStatus, que si va firmado, y en el monto.
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
        // para ella no existe. Se responde 403 y no 200 porque aqui no hay nada
        // que consultar ni que recuperar mas tarde: no es un aviso perdido, es
        // una url que no corresponde a ninguna empresa con cobro configurado.
        if ($config === false) {
            return self::r(403, 'no', 'cuenta sin pasarela configurada');
        }

        try {
            $secreto = $descifrar((string) $config['credencial_cifrada']);
        } catch (Throwable $e) {
            return self::r(500, 'error', 'no se pudo descifrar el secreto');
        }

        // EL AVISO DE FLOW NO VIENE FIRMADO, Y ANTES SE EXIGIA QUE LO ESTUVIERA.
        //
        // Esta clase pedia un parametro 's' y lo comparaba con un HMAC del resto
        // del cuerpo. Flow no manda ese parametro: su documentacion de
        // "Confirmacion de orden" dice que la llamada llega por POST con
        // Content-Type application/x-www-form-urlencoded y el cuerpo es
        // UNICAMENTE token=<token de la transaccion>. La firma 's' es de las
        // peticiones que SALEN hacia su API (payment/create, payment/getStatus),
        // no de las que entran.
        //
        // Consecuencia medida en produccion: Flow llamo a
        // /pagos/flow/confirmacion/1, se le respondio 403 "firma invalida", y el
        // pago del DTE 241 quedo sin registrar -- estado 'creado',
        // estado_pasarela NULL. Con la firma exigida, NINGUN aviso real podia
        // pasar nunca: el 100% de los pagos dependia del conciliador.
        //
        // NO SE PONE UNA FIRMA ALTERNATIVA en su lugar. Inventar un esquema que
        // Flow no implementa solo reproduciria el mismo 403 con otro nombre.
        //
        //
        // ENTONCES QUE AUTENTICA ESTO, SI CUALQUIERA PUEDE POSTEAR AQUI
        // ---------------------------------------------------------------------
        // El aviso no se cree: se usa como AVISO, no como fuente. Del cuerpo solo
        // sale un token, y con el token no se marca nada. Lo que decide es la
        // consulta a payment/getStatus que hacemos nosotros, con la apiKey y el
        // secreto de ESTA cuenta y firmada con secretKey, y despues el monto
        // tiene que cuadrar al peso. Un tercero que postee un token cualquiera
        // consigue, en el mejor de los casos para el, que le preguntemos a Flow
        // por una orden que no cambiara de estado.
        //
        // Es el modelo que Flow prescribe, y es el mismo que ya usa la pagina de
        // retorno (ver EstadoRetornoPago): el navegador y la callback son
        // punteros; la verdad se pide por el canal con credenciales.
        //
        // is_scalar filtra arrays anidados: strval() sobre un array emite warning
        // y produce "Array", que aqui acabaria buscando una orden llamada "Array".
        $recibido = array_filter($post, 'is_scalar');

        $token = trim((string) ($recibido['token'] ?? ''));
        if ($token === '') {
            // Sin token no hay nada que consultar. 200 porque la pasarela no
            // reintenta y un no-200 solo le genera un correo de alerta al
            // comerciante por algo que no puede arreglar.
            return self::r(200, 'ok', 'aviso sin token');
        }

        // LA FILA SE BUSCA POR TOKEN Y POR CUENTA, antes de preguntar nada.
        //
        // cuenta_id en el WHERE es lo que impide que un aviso dirigido a una
        // empresa toque la orden de otra, y con la firma fuera es la unica
        // defensa que queda en este punto: sin el, un token de la cuenta 5
        // posteado a /pagos/flow/confirmacion/1 encontraria su fila. El token es
        // lo unico que trae el aviso, asi que es por donde hay que entrar -- y
        // por eso la orden se guarda con el token como identificador externo.
        $stmt = $pdo->prepare(
            'SELECT id, monto FROM dte_pago_link WHERE cuenta_id = :c AND orden_externa = :t LIMIT 1'
        );
        $stmt->execute([':c' => $cuentaId, ':t' => $token]);
        $link = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($link === false) {
            // O no es nuestra, o es la carrera de que el aviso llegue antes de
            // que terminemos de guardar la orden.
            //
            // SE RESPONDE 200 AUNQUE NO SE HAYA HECHO NADA, y conviene entender
            // por que: la pasarela NO reintenta. Espera un 200 en 15 segundos y,
            // si no lo recibe, manda un correo de alerta de integracion y se
            // olvida. Devolver 503 aqui no conseguiria otra llamada -- solo
            // alarmaria al comerciante por algo que no puede arreglar. Si era la
            // carrera, el conciliador barre esa orden mas tarde igualmente.
            return self::r(200, 'ok', 'orden desconocida para esta cuenta');
        }

        $desenlace = self::resolverOrden(
            $pdo,
            $config,
            $secreto,
            (int) $link['id'],
            (int) $link['monto'],
            $token,
            $http
        );

        // Todo desenlace se acusa con 200 salvo el fallo de descifrado, porque la
        // pasarela no reintenta y un no-200 solo genera ruido. Lo que recupera un
        // aviso que no se pudo resolver es NUESTRO conciliador, no ella.
        return self::r(200, 'ok', $desenlace['motivo']);
    }

    /**
     * Resuelve UNA orden contra la pasarela y actualiza su estado.
     *
     * ES EL NUCLEO COMPARTIDO por el aviso de pago y por el conciliador. Vive
     * separado justamente para que no haya dos copias: si la regla del monto o la
     * del estado se arreglaran en un sitio y no en el otro, el sistema daria una
     * respuesta distinta segun por donde llegara la noticia -- y una de las dos
     * seria la equivocada.
     *
     * @param array<string,mixed> $config fila de pago_pasarela_cuenta
     *
     * @return array{cambio:string, motivo:string}
     *         cambio: 'pagado' | 'no_pagado' | 'monto_distinto' | 'transitorio' | 'permanente'
     */
    public static function resolverOrden(
        PDO $pdo,
        array $config,
        string $secreto,
        int $linkId,
        int $montoEsperado,
        string $token,
        ?Client $http = null,
    ): array {
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
            // Queda marcada para que el conciliador la retome. La pasarela no va
            // a volver a avisar.
            self::marcarPendiente($pdo, $linkId, 'no se pudo consultar el estado: ' . $e->getMessage());

            return ['cambio' => 'transitorio', 'motivo' => 'consulta de estado transitoriamente caida'];
        } catch (Throwable $e) {
            self::marcarPendiente($pdo, $linkId, 'fallo permanente al consultar: ' . $e->getMessage());

            return ['cambio' => 'permanente', 'motivo' => 'consulta de estado fallo de forma permanente'];
        }

        // EL ESTADO DE LA PASARELA SE GUARDA SIEMPRE que se pudo preguntar, haya
        // pago o no. Es lo que le permite al conciliador decidir cada cuanto
        // volver por esta orden: una rechazada no merece la misma frecuencia que
        // una que sigue pendiente de pago.
        self::guardarEstadoPasarela($pdo, $linkId, (string) ($estado['estado'] ?? 'desconocido'));

        if ($estado['pagada'] !== true) {
            // Pendiente, rechazada o anulada. No se toca NUESTRO estado -- el link
            // sigue vivo y pagable, que es lo correcto -- pero SI se limpia la
            // marca de aviso sin resolver.
            //
            // POR QUE SE LIMPIA AUNQUE NO HAYA PAGO, que es la parte discutible:
            // esa marca no significa "hubo un pago". Significa "llego un aviso y
            // NO PUDIMOS RESOLVERLO". Aqui si se pudo: se pregunto y la pasarela
            // contesto. Que la respuesta sea "todavia no esta pagada" es una
            // respuesta, no una falta de respuesta. Dejarla puesta convertiria la
            // marca en "hubo un aviso alguna vez", que es otra cosa y ya la
            // cuenta conciliacion_intentos.
            self::limpiarPendiente($pdo, $linkId);

            return ['cambio' => 'no_pagado', 'motivo' => 'el pago no esta confirmado'];
        }

        // --- EL MONTO TIENE QUE CUADRAR EXACTO --------------------------------
        //
        // $estado['monto'] llega YA normalizado a pesos enteros por el proveedor,
        // o null si lo que informo la pasarela no representa una cantidad entera
        // utilizable. null no es cero: es "este dato no sirve", y tampoco se da
        // por bueno.
        $pagado = $estado['monto'];

        if ($pagado === null || $pagado !== $montoEsperado) {
            $pdo->prepare(
                "UPDATE dte_pago_link SET estado = 'error', ultimo_error = :e, "
                . 'confirmacion_pendiente_at = :ahora WHERE id = :id'
            )->execute([
                ':e'     => sprintf(
                    'monto informado %s (normalizado %s) distinto del cobrado %d',
                    var_export($estado['montoCrudo'] ?? null, true),
                    var_export($pagado, true),
                    $montoEsperado
                ),
                ':ahora' => date('Y-m-d H:i:s'),
                ':id'    => $linkId,
            ]);

            return [
                'cambio' => 'monto_distinto',
                'motivo' => sprintf(
                    'MONTO DISTINTO: informado %s, cobrado %d. NO se marca pagada.',
                    var_export($estado['montoCrudo'] ?? null, true),
                    $montoEsperado
                ),
            ];
        }

        // estado <> 'pagado' hace idempotente que esto se ejecute dos veces, sea
        // por un aviso repetido o porque el aviso y el conciliador coincidan.
        $pdo->prepare(
            "UPDATE dte_pago_link SET estado = 'pagado', pagado_at = :ahora, "
            . "confirmacion_pendiente_at = NULL WHERE id = :id AND estado <> 'pagado'"
        )->execute([':ahora' => date('Y-m-d H:i:s'), ':id' => $linkId]);

        return ['cambio' => 'pagado', 'motivo' => 'pago confirmado'];
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

    /**
     * Guarda lo ultimo que contesto la pasarela sobre esta orden.
     *
     * Es INFORMATIVO y separado de nuestro estado: una orden puede ser 'creado'
     * para nosotros y 'rechazada' para la pasarela al mismo tiempo. Mezclarlos
     * seria decir que una factura rechazada esta pagada.
     */
    private static function guardarEstadoPasarela(PDO $pdo, int $linkId, string $estado): void
    {
        try {
            $pdo->prepare('UPDATE dte_pago_link SET estado_pasarela = :e WHERE id = :id')
                ->execute([':e' => mb_substr($estado, 0, 30), ':id' => $linkId]);
        } catch (Throwable $e) {
            // Sin consecuencia: solo afecta a la frecuencia del proximo barrido.
        }
    }

    /** La pregunta se pudo hacer: ya no hay nada pendiente de mirar. */
    private static function limpiarPendiente(PDO $pdo, int $linkId): void
    {
        try {
            $pdo->prepare(
                'UPDATE dte_pago_link SET confirmacion_pendiente_at = NULL WHERE id = :id'
            )->execute([':id' => $linkId]);
        } catch (Throwable $e) {
            // Sin consecuencia: la orden se volvera a mirar en el proximo barrido.
        }
    }

    /** @return array{codigo:int, cuerpo:string, motivo:string} */
    private static function r(int $codigo, string $cuerpo, string $motivo): array
    {
        return ['codigo' => $codigo, 'cuerpo' => $cuerpo, 'motivo' => $motivo];
    }
}
