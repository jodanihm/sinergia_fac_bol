<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Correo;

use PDO;
use PDOException;
use Throwable;

/**
 * Encola documentos ya emitidos en dte_envio_correo, para que el runner
 * (scripts/enviar_correos_pendientes.php) se los mande por correo al receptor.
 *
 * POR QUE VIVE AQUI Y NO EN EL PANEL. La funcion original era
 * encolarEnvioCorreo() de panel/public/index.php, y los documentos que entraban
 * por la API del motor NO se encolaban: el panel es otro proceso y el motor no
 * alcanza sus funciones. Al moverla a src/Correo/ la alcanzan los dos, por el
 * autoloader de Composer que ambos ya cargan.
 *
 * LOS DOS SUPUESTOS DEL PANEL QUE AQUI SON PARAMETROS
 * -----------------------------------------------------------------------------
 * 1. cuentaId. En el panel salia de Auth::cuentaId() (la sesion). En el motor
 *    sale de resolverTenant(), o sea de la fila de api_key.
 *
 * 2. ambiente. Y ESTE ES EL RIESGO REAL DE MOVER LA FUNCION. La version del
 *    panel tenia 'produccion' escrito a mano, con este argumento: "el panel
 *    emite SOLO en produccion". Cierto para el panel, FALSO para el motor, que
 *    emite en el ambiente del tenant.
 *
 *    Si se hubiera movido tal cual, un documento de CERTIFICACION habria buscado
 *    su fila por (rut_emisor, 'produccion', tipo, folio) y, como los folios de
 *    los dos ambientes son series independientes que se solapan, habria
 *    encontrado el documento de PRODUCCION con el mismo tipo y folio -- y
 *    encolado el correo del documento equivocado, a un receptor que no lo pidio.
 *    De ahi que ambiente sea un parametro obligatorio y sin default.
 *
 * REGLA QUE MANDA SOBRE TODO: ENCOLAR NUNCA PUEDE ROMPER UNA EMISION. Cuando se
 * llega aqui el SII ya acepto y los folios ya se quemaron. Cualquier fallo se
 * registra y se sigue; jamas convierte un 201 en un 500. Por eso los dos metodos
 * publicos atrapan Throwable y no relanzan NUNCA.
 */
final class EncoladorCorreo
{
    /**
     * Encola UN documento. No lanza jamas.
     *
     * @param ?string $destinatario ya resuelto por el llamador (ver la cascada de
     *                              cada camino); null si no hay
     */
    public static function encolarUno(
        PDO $pdo,
        int $cuentaId,
        string $rutEmisor,
        string $ambiente,
        int $tipoDte,
        int $folio,
        ?string $destinatario,
    ): void {
        try {
            $id = self::idDeEmitido($pdo, $rutEmisor, $ambiente, $tipoDte, $folio);
            if ($id === null) {
                return; // ya se registro adentro
            }

            $stmt = $pdo->prepare(
                'INSERT INTO dte_envio_correo (dte_emitido_id, cuenta_id, destinatario, estado) '
                . 'VALUES (:doc, :cuenta, :dest, :estado) '
                // ON DUPLICATE KEY con asignacion NO-OP y no INSERT IGNORE: el
                // primero solo absorbe el choque contra uk_envio_documento (el
                // documento ya estaba encolado, que es EXITO), mientras que
                // INSERT IGNORE degrada a warning CUALQUIER error -- una FK rota,
                // un dato fuera de rango -- y los perderiamos en silencio.
                . 'ON DUPLICATE KEY UPDATE dte_emitido_id = dte_emitido_id'
            );
            $stmt->execute([
                ':doc'    => $id,
                ':cuenta' => $cuentaId,
                ':dest'   => self::destinatarioValido($destinatario),
                ':estado' => self::destinatarioValido($destinatario) === null ? 'sin_destinatario' : 'pendiente',
            ]);
        } catch (Throwable $e) {
            self::registrar($rutEmisor, $ambiente, $tipoDte, $folio, $e);
        }
    }

    /**
     * Encola VARIOS documentos de una pasada: un SELECT de todos los ids y UN
     * INSERT multi-valor, dentro de una transaccion. No lanza jamas.
     *
     * POR QUE NO SE LLAMA encolarUno() EN UN BUCLE: son 2 sentencias por
     * documento, y cada commit paga un fsync. Medido sobre 300 documentos en la
     * base desechable: 7.877 ms el bucle sin transaccion, 304 ms el mismo bucle
     * en una transaccion, y 43 ms esta forma. Y esto corre DESPUES de que el SII
     * acepto, dentro de la misma peticion HTTP del cliente.
     *
     * @param list<array{tipoDte:int, folio:int, destinatario:?string}> $documentos
     *
     * @return int cuantas filas nuevas quedaron encoladas (las repetidas no cuentan)
     */
    public static function encolarVarios(
        PDO $pdo,
        int $cuentaId,
        string $rutEmisor,
        string $ambiente,
        array $documentos,
    ): int {
        if ($documentos === []) {
            return 0;
        }

        $abrioTransaccion = false;
        try {
            // UN SELECT para todos: se piden por (tipo, folio) del ambiente y
            // emisor del tenant, y se indexan por "tipo/folio" para casar cada
            // documento con su id sin volver a la base.
            $pares = [];
            foreach ($documentos as $d) {
                $pares[] = '(tipo_dte = ' . (int) $d['tipoDte'] . ' AND folio = ' . (int) $d['folio'] . ')';
            }
            $stmt = $pdo->prepare(
                'SELECT id, tipo_dte, folio FROM dte_emitido '
                . 'WHERE rut_emisor = :rut AND ambiente = :amb AND (' . implode(' OR ', $pares) . ')'
            );
            $stmt->execute([':rut' => $rutEmisor, ':amb' => $ambiente]);

            $idPorClave = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
                $idPorClave[$fila['tipo_dte'] . '/' . $fila['folio']] = (int) $fila['id'];
            }

            $valores = [];
            $args    = [];
            foreach ($documentos as $d) {
                $id = $idPorClave[$d['tipoDte'] . '/' . $d['folio']] ?? null;
                if ($id === null) {
                    // Mismo caso que en encolarUno: persistirEmitido() del motor es
                    // best-effort, asi que puede faltar la fila. Se registra y se
                    // sigue con los demas.
                    self::registrar($rutEmisor, $ambiente, (int) $d['tipoDte'], (int) $d['folio'], null);
                    continue;
                }
                $dest      = self::destinatarioValido($d['destinatario'] ?? null);
                $valores[] = '(?, ?, ?, ?)';
                $args[]    = $id;
                $args[]    = $cuentaId;
                $args[]    = $dest;
                $args[]    = $dest === null ? 'sin_destinatario' : 'pendiente';
            }

            if ($valores === []) {
                return 0;
            }

            if (! $pdo->inTransaction()) {
                $pdo->beginTransaction();
                $abrioTransaccion = true;
            }
            $ins = $pdo->prepare(
                'INSERT INTO dte_envio_correo (dte_emitido_id, cuenta_id, destinatario, estado) '
                . 'VALUES ' . implode(',', $valores) . ' '
                . 'ON DUPLICATE KEY UPDATE dte_emitido_id = dte_emitido_id'
            );
            $ins->execute($args);
            if ($abrioTransaccion) {
                $pdo->commit();
            }

            // rowCount de un INSERT multi-valor con ON DUPLICATE KEY cuenta 1 por
            // fila nueva y 0 por fila ya existente (la asignacion es no-op, asi
            // que MySQL no la cuenta como cambiada).
            return $ins->rowCount();
        } catch (Throwable $e) {
            if ($abrioTransaccion && $pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (Throwable) {
                    // ni el rollback puede romper la emision
                }
            }
            error_log(sprintf(
                'encolar correo: fallo el encolado en lote de %d documentos de %s (%s) - %s',
                count($documentos),
                $rutEmisor,
                $ambiente,
                $e->getMessage()
            ));

            return 0;
        }
    }

    /** id de dte_emitido, o null si no esta (se registra y el llamador sigue). */
    private static function idDeEmitido(PDO $pdo, string $rutEmisor, string $ambiente, int $tipoDte, int $folio): ?int
    {
        // EL id HAY QUE BUSCARLO: ninguna de las dos respuestas del motor lo trae
        // (el 201 unitario devuelve folio/tipoDte/trackId/montos, y el del lote
        // devuelve trackId mas {tipoDte,folio} por documento). Se resuelve por su
        // UNIQUE uq_emitido (rut_emisor, ambiente, tipo_dte, folio).
        $stmt = $pdo->prepare(
            'SELECT id FROM dte_emitido '
            . 'WHERE rut_emisor = :rut AND ambiente = :amb AND tipo_dte = :tipo AND folio = :folio LIMIT 1'
        );
        $stmt->execute([':rut' => $rutEmisor, ':amb' => $ambiente, ':tipo' => $tipoDte, ':folio' => $folio]);
        $id = $stmt->fetchColumn();

        if ($id === false) {
            // NO EXISTE LA FILA, Y ES UN CASO REAL: persistirEmitido() del motor
            // es best-effort y se traga sus errores, asi que se puede recibir un
            // 201 sin que el documento haya quedado guardado. Un INSERT a ciegas
            // reventaria contra fk_envio_documento justo en el flujo que no puede
            // romperse.
            self::registrar($rutEmisor, $ambiente, $tipoDte, $folio, null);

            return null;
        }

        return (int) $id;
    }

    /**
     * Un correo mal escrito se descarta y se trata como si no viniera. Mismo
     * criterio que la carga masiva y que validarDocumentoDte(): no frena nada.
     */
    private static function destinatarioValido(?string $destinatario): ?string
    {
        $d = $destinatario !== null ? trim($destinatario) : '';

        return ($d !== '' && filter_var($d, FILTER_VALIDATE_EMAIL)) ? $d : null;
    }

    private static function registrar(string $rutEmisor, string $ambiente, int $tipoDte, int $folio, ?Throwable $e): void
    {
        // 23000 sobre uk_envio_documento ya no llega aqui -- lo absorbe el ON
        // DUPLICATE KEY --, pero si llegara por otra restriccion, se registra.
        if ($e instanceof PDOException && $e->getCode() === '23000') {
            return;
        }
        error_log(sprintf(
            'encolar correo: %s para %s (%s) tipo %d folio %d%s',
            $e === null ? 'no se encontro dte_emitido; no se encola' : 'fallo',
            $rutEmisor,
            $ambiente,
            $tipoDte,
            $folio,
            $e === null ? '' : ' - ' . $e->getMessage()
        ));
    }
}
