<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Pago;

use GuzzleHttp\Client;
use PDO;
use PDOException;
use Plantiflex\FacturacionCl\Dto\CredencialesPasarela;
use Plantiflex\FacturacionCl\Dto\OrdenPagoCreada;
use Plantiflex\FacturacionCl\Dto\SolicitudPago;
use Plantiflex\FacturacionCl\Exceptions\PagoException;
use Plantiflex\FacturacionCl\Exceptions\PasarelaPermanenteException;
use Plantiflex\FacturacionCl\Exceptions\PasarelaTransitoriaException;
use Plantiflex\FacturacionCl\Sii\Rut;
use Throwable;

/**
 * Decide si un correo de la cola tiene que llevar link de pago y, si toca, lo
 * consigue.
 *
 * POR QUE ESTA CLASE EXISTE Y NO ESTA DENTRO DE PreparadorEnvio
 * -----------------------------------------------------------------------------
 * Dos motivos, y el segundo es el que de verdad manda.
 *
 * El primero es de contrato: PreparadorEnvio declara que "no envia ni decide
 * politica". Crear una orden de cobro contra un tercero es exactamente decidir
 * politica.
 *
 * El segundo es que --dry-run EJECUTA PreparadorEnvio::preparar(). Ese modo
 * promete no tocar nada y se usa para mirar que saldria. Si la llamada a la
 * pasarela viviera ahi, un --dry-run habria creado ordenes de cobro REALES,
 * silenciosamente. Aqui afuera, el runner simplemente no llama al resolutor
 * cuando va en seco.
 *
 *
 * LOS TRES VERDICTOS
 * -----------------------------------------------------------------------------
 *   'no_aplica'  este correo no lleva link. Sigue su camino normal, hoy mismo.
 *   'listo'      ya hay link guardado. Sigue su camino y lo llevara.
 *   'esperar'    corresponde link pero todavia no se pudo conseguir. El correo
 *                NO se manda y la fila de la cola se queda INTACTA.
 *
 * "Intacta" es literal y es la parte importante: el runner hace `continue` sin
 * pasar por PreparadorEnvio, asi que no se toca `estado` ni se sube `intentos`.
 * Si el fallo de la pasarela pasara por el camino normal de error, tres caidas
 * dejarian la factura en estado 'error' con intentos=3, y el runner ya no la
 * volveria a mirar NUNCA: una factura que no se envia jamas, por un problema de
 * cobro. Ese es el agujero que este diseno evita.
 *
 *
 * "SIN CONFIGURAR" NO ES "NO CORRESPONDE"
 * -----------------------------------------------------------------------------
 * Si la empresa NO quiere cobrar en linea, el verdicto es 'no_aplica' y el correo
 * sale normal. Pero si la empresa SI lo quiere y su configuracion esta rota --
 * credenciales a medias, llave maestra ilegible, proveedor inexistente -- el
 * verdicto es 'esperar', nunca 'no_aplica'. Degradar en silencio convertiria un
 * error de configuracion en facturas enviadas sin cobro, y nadie se enteraria.
 */
final class ResolutorLinkPago
{
    /** Los tipos que se cobran. Espejo de PreparadorEnvio::TIPOS_CON_LINK_PAGO. */
    private const TIPOS_QUE_SE_COBRAN = [33, 34];

    /**
     * Espera creciente entre intentos, en minutos, segun cuantos van fallando.
     *
     * El correo espera a que haya link y el runner pasa cada 5 minutos: sin
     * freno, una pasarela caida costaria 12 llamadas por hora POR CADA documento
     * retenido, y alargaria cada corrida con peticiones condenadas. A partir del
     * ultimo tramo se queda en una hora.
     */
    private const BACKOFF_MINUTOS = [0, 5, 15, 30, 60];

    /**
     * Un fallo PERMANENTE se aparca un dia entero. Reintentar cada cinco minutos
     * una clave mal pegada no la va a arreglar: lo unico que la arregla es que
     * una persona la corrija, y para eso tiene que verla en la pantalla, no
     * enterrada bajo cien lineas de log identicas.
     */
    private const BACKOFF_PERMANENTE_MINUTOS = 1440;

    public function __construct(
        private readonly PDO $pdo,
        /** Descifra pago_pasarela_cuenta.credencial_cifrada. */
        private readonly \Closure $descifrar,
        /**
         * Base de la url a la que la pasarela avisa del pago. El resolutor le
         * pega la cuenta: .../confirmacion/7.
         *
         * VA POR CUENTA Y NO PUEDE NO IRLO. El aviso llega firmado con el
         * secreto de la empresa, y para verificar esa firma hay que saber DE QUE
         * EMPRESA es antes de creerse nada. Con una url unica para todos, el
         * receptor del aviso no tendria forma de elegir el secreto correcto: o
         * los prueba todos -- que es un oraculo para adivinar secretos -- o no
         * verifica, que es peor.
         */
        private readonly string $urlConfirmacionBase,
        private readonly ?Client $http = null,
    ) {
    }

    /**
     * @return array{verdicto:string, motivo:string}
     */
    public function resolver(int $envioId): array
    {
        $fila = $this->cargar($envioId);
        if ($fila === null) {
            return self::v('no_aplica', 'la fila no existe');
        }

        // --- Guardas baratas primero. Ninguna toca la red. -------------------

        // Una decision humana de soltar el correo sin link manda sobre todo lo
        // demas: es lo que desatasca una cola retenida.
        if (($fila['pago_estado'] ?? null) === 'omitido') {
            return self::v('no_aplica', 'alguien decidio mandarlo sin link');
        }

        // Link ya conseguido: no se vuelve a llamar a la pasarela nunca.
        if (($fila['pago_estado'] ?? null) === 'creado' && trim((string) ($fila['pago_url'] ?? '')) !== '') {
            return self::v('listo', 'el link ya estaba');
        }

        // Pagada: el webhook ya la marco. No se le vuelve a ofrecer pagar.
        if (($fila['pago_estado'] ?? null) === 'pagado') {
            return self::v('no_aplica', 'ya esta pagada');
        }

        $tipoDte = (int) $fila['tipo_dte'];
        if (! in_array($tipoDte, self::TIPOS_QUE_SE_COBRAN, true)) {
            return self::v('no_aplica', "el tipo {$tipoDte} no se cobra por esta via");
        }

        // En certificacion no se le cobra a nadie. La pasarela no tiene columna
        // de ambiente porque la cuenta de cobro de una empresa es una sola y
        // mueve dinero de verdad; la proteccion es esta linea.
        if ((string) $fila['ambiente'] !== 'produccion') {
            return self::v('no_aplica', 'no es un documento de produccion');
        }

        $total = (int) $fila['total'];
        if ($total <= 0) {
            return self::v('no_aplica', 'el documento no tiene monto que cobrar');
        }

        $config = $this->configuracionPasarela((int) $fila['cuenta_id']);
        if ($config === null || (int) $config['habilitado'] !== 1) {
            return self::v('no_aplica', 'la empresa no tiene el cobro en linea activo');
        }

        if ($this->clienteExcluido((int) $fila['cuenta_id'], (string) $fila['receptor_rut'])) {
            return self::v('no_aplica', 'a este cliente no se le manda link');
        }

        $destinatario = trim((string) ($fila['destinatario'] ?? ''));
        if ($destinatario === '') {
            // Sin correo no hay a quien mandarle el link, y la pasarela lo pide
            // para su propio comprobante. El correo tampoco va a salir.
            return self::v('no_aplica', 'no hay destinatario');
        }

        // Backoff: todavia no toca reintentar.
        $reintentarAt = $fila['pago_reintentar_despues_at'] ?? null;
        if ($reintentarAt !== null && strtotime((string) $reintentarAt) > time()) {
            return self::v('esperar', 'esperando el momento del proximo intento');
        }

        // --- A partir de aqui SI se habla con la pasarela ---------------------

        $referencia = self::referencia((int) $fila['cuenta_id'], $tipoDte, (int) $fila['folio']);
        $linkId     = $this->reservar(
            (int) $fila['dte_emitido_id'],
            (int) $fila['cuenta_id'],
            (string) $config['proveedor'],
            $referencia,
            $total
        );

        try {
            $cred = $this->credenciales($config);
            $orden = FabricaPasarela::crear((string) $config['proveedor'], $this->http)->crearOrden(
                new SolicitudPago(
                    referencia: $referencia,
                    monto: $total,
                    asunto: sprintf('Documento %d N %d', $tipoDte, (int) $fila['folio']),
                    emailPagador: $destinatario,
                    urlConfirmacion: rtrim($this->urlConfirmacionBase, '/') . '/' . (int) $fila['cuenta_id'],
                    urlRetorno: trim((string) ($config['url_retorno'] ?? '')) ?: null,
                ),
                $cred
            );
        } catch (PasarelaPermanenteException $e) {
            $this->registrarFallo($linkId, $e->getMessage(), self::BACKOFF_PERMANENTE_MINUTOS);

            return self::v('esperar', 'la pasarela rechazo la orden: ' . $e->getMessage());
        } catch (PagoException | Throwable $e) {
            // Todo lo demas -- transitorio, configuracion rota, un fallo que no
            // se supo clasificar -- se trata como transitorio. Errar hacia
            // "esperar" retiene un correo; errar hacia "no_aplica" lo manda sin
            // el link que la empresa pidio, y eso no se puede deshacer.
            $minutos = $this->backoff($linkId);
            $this->registrarFallo($linkId, $e->getMessage(), $minutos);

            return self::v('esperar', 'no se pudo crear la orden: ' . $e->getMessage());
        }

        $this->confirmar($linkId, $orden);

        return self::v('listo', 'link recien creado');
    }

    /**
     * La clave de la orden que viaja a la pasarela.
     *
     * DETERMINISTA, NUNCA ALEATORIA. Es la mitad de la defensa contra el doble
     * cobro: si la orden se creo alla y la respuesta se perdio, el reintento
     * manda ESTA MISMA y la pasarela devuelve la que ya existe en vez de crear
     * otra. Con un valor aleatorio, ese caso genera dos cobros.
     */
    public static function referencia(int $cuentaId, int $tipoDte, int $folio): string
    {
        return sprintf('SIN-%d-%d-%d', $cuentaId, $tipoDte, $folio);
    }

    // -----------------------------------------------------------------------

    /** @return array<string,mixed>|null */
    private function cargar(int $envioId): ?array
    {
        // Todos los JOIN por id numerico, por las dos familias de collation.
        $stmt = $this->pdo->prepare(
            'SELECT q.id, q.cuenta_id, q.dte_emitido_id, q.destinatario, '
            . '       e.tipo_dte, e.folio, e.total, e.receptor_rut, e.ambiente, '
            . '       p.id AS pago_id, p.estado AS pago_estado, p.url AS pago_url, '
            . '       p.intentos AS pago_intentos, p.reintentar_despues_at AS pago_reintentar_despues_at '
            . 'FROM dte_envio_correo q '
            . 'JOIN dte_emitido e ON e.id = q.dte_emitido_id '
            . 'LEFT JOIN dte_pago_link p ON p.dte_emitido_id = q.dte_emitido_id '
            . 'WHERE q.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $envioId]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return $fila === false ? null : $fila;
    }

    /** @return array<string,mixed>|null */
    private function configuracionPasarela(int $cuentaId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT proveedor, habilitado, credencial_publica, credencial_cifrada, url_retorno '
            . 'FROM pago_pasarela_cuenta WHERE cuenta_id = :c LIMIT 1'
        );
        $stmt->execute([':c' => $cuentaId]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return $fila === false ? null : $fila;
    }

    /** @param array<string,mixed> $config */
    private function credenciales(array $config): CredencialesPasarela
    {
        $secreto = ($this->descifrar)((string) ($config['credencial_cifrada'] ?? ''));

        return new CredencialesPasarela(
            apiKey: (string) ($config['credencial_publica'] ?? ''),
            secreto: $secreto,
            sandbox: false,
            urlRetorno: trim((string) ($config['url_retorno'] ?? '')) ?: null,
        );
    }

    /**
     * NO se puede hacer JOIN con cliente, y no es por comodidad.
     *
     * dte_emitido.receptor_rut quedo en la collation del motor
     * (utf8mb4_0900_ai_ci) y cliente.rut_cliente en la del panel
     * (utf8mb4_unicode_ci): cruzarlas por texto da "Illegal mix of collations".
     * Se normaliza en PHP y se consulta con parametro, que no tiene collation.
     *
     * El normalizar ademas hace falta por si mismo: los documentos emitidos antes
     * del arreglo de RUT canonico pueden tener el receptor con puntos, y
     * cliente.rut_cliente siempre estuvo limpio. Sin normalizar, a esos clientes
     * se les mandaria link aunque estuvieran excluidos, y en silencio.
     *
     * UN RECEPTOR SIN FICHA NO ESTA EXCLUIDO: el maestro es opcional y el link es
     * la politica de la empresa. Exigir ficha dejaria fuera a todo receptor
     * tecleado a mano en el formulario de emision.
     */
    private function clienteExcluido(int $cuentaId, string $receptorRut): bool
    {
        $rut = Rut::normalizar($receptorRut);
        if ($rut === '') {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'SELECT pago_link FROM cliente WHERE cuenta_id = :c AND rut_cliente = :rut LIMIT 1'
        );
        $stmt->execute([':c' => $cuentaId, ':rut' => $rut]);
        $valor = $stmt->fetchColumn();

        return $valor !== false && (int) $valor === 0;
    }

    /**
     * Deja la fila ANTES de llamar a la pasarela y devuelve su id.
     *
     * Que exista antes es lo que permite que un corte de red no pierda el rastro:
     * si la orden se creo alla y no nos enteramos, la fila ya esta con su
     * referencia y el reintento manda la misma.
     *
     * El INSERT que choca con el UNIQUE se captura por SQLSTATE 23000 en vez de
     * usar ON DUPLICATE KEY UPDATE: es el mismo patron que
     * MySqlIdempotenciaRepository::reclamar(), y ademas funciona igual en SQLite,
     * que es donde corren los tests.
     */
    private function reservar(int $dteEmitidoId, int $cuentaId, string $proveedor, string $referencia, int $monto): int
    {
        try {
            $ins = $this->pdo->prepare(
                'INSERT INTO dte_pago_link (dte_emitido_id, cuenta_id, proveedor, referencia, monto, estado, intentos) '
                . "VALUES (:d, :c, :p, :r, :m, 'pendiente', 1)"
            );
            $ins->execute([
                ':d' => $dteEmitidoId, ':c' => $cuentaId, ':p' => $proveedor,
                ':r' => $referencia, ':m' => $monto,
            ]);

            return (int) $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }

        // Ya existia: se sube el contador de intentos y se sigue con la misma fila.
        $this->pdo->prepare(
            'UPDATE dte_pago_link SET intentos = intentos + 1 WHERE dte_emitido_id = :d'
        )->execute([':d' => $dteEmitidoId]);

        $stmt = $this->pdo->prepare('SELECT id FROM dte_pago_link WHERE dte_emitido_id = :d LIMIT 1');
        $stmt->execute([':d' => $dteEmitidoId]);

        return (int) $stmt->fetchColumn();
    }

    private function confirmar(int $linkId, OrdenPagoCreada $orden): void
    {
        // Las marcas de tiempo se calculan en PHP y se ligan, en vez de usar
        // NOW() y DATE_ADD(). Dos motivos: INTERVAL no admite parametro ligado en
        // MySQL (habria que interpolar el numero en el SQL, que es justo lo que
        // no se hace aqui), y los tests corren en SQLite, que no tiene ninguna de
        // las dos funciones. La hora del proceso y la del servidor de base son la
        // misma: los dos contenedores comparten reloj con el host.
        $this->pdo->prepare(
            "UPDATE dte_pago_link SET estado = 'creado', orden_externa = :o, url = :u, "
            . 'creado_at = :ahora, ultimo_error = NULL, reintentar_despues_at = NULL WHERE id = :id'
        )->execute([
            ':o'     => $orden->ordenExterna,
            ':u'     => $orden->url,
            ':ahora' => date('Y-m-d H:i:s'),
            ':id'    => $linkId,
        ]);
    }

    private function registrarFallo(int $linkId, string $error, int $minutos): void
    {
        // El estado se queda en 'pendiente', NO pasa a 'error': el correo sigue
        // esperando y la fila sigue siendo candidata. 'error' se reserva para lo
        // que ya no se reintenta solo.
        $this->pdo->prepare(
            'UPDATE dte_pago_link SET ultimo_error = :e, reintentar_despues_at = :hasta WHERE id = :id'
        )->execute([
            ':e'     => mb_substr($error, 0, 500),
            ':hasta' => date('Y-m-d H:i:s', time() + ($minutos * 60)),
            ':id'    => $linkId,
        ]);
    }

    private function backoff(int $linkId): int
    {
        $stmt = $this->pdo->prepare('SELECT intentos FROM dte_pago_link WHERE id = :id');
        $stmt->execute([':id' => $linkId]);
        $intentos = (int) $stmt->fetchColumn();

        $indice = min(max($intentos - 1, 0), count(self::BACKOFF_MINUTOS) - 1);

        return self::BACKOFF_MINUTOS[$indice];
    }

    /** @return array{verdicto:string, motivo:string} */
    private static function v(string $verdicto, string $motivo): array
    {
        return ['verdicto' => $verdicto, 'motivo' => $motivo];
    }
}
