<?php

declare(strict_types=1);

namespace Plantiflex\Integration\Facturacion;

use PDO;
use PDOException;
use Plantiflex\FacturacionCl\Enums\Ambiente;

/**
 * Idempotencia de emision por (rut_emisor, ambiente, Idempotency-Key) -> tabla
 * dte_idempotencia.
 *
 * Garantiza at-most-once por clave: el INSERT contra la PK actua como candado.
 * Un claim sin folio = emision en curso o servidor caido a mitad; un TTL
 * permite reactivarlo para no bloquear para siempre.
 *
 * ALCANCE POR TENANT
 * La PK es (rut_emisor, ambiente, clave) desde la migracion 001, que agrego
 * rut_emisor NOT NULL justamente para que dos cuentas puedan usar la misma
 * Idempotency-Key sin colisionar. Por eso los CUATRO metodos reciben
 * $rutEmisor y lo llevan en el INSERT o en el WHERE.
 *
 * Omitirlo no era solo un INSERT invalido: un SELECT o un UPDATE filtrado solo
 * por (ambiente, clave) puede alcanzar la fila de OTRO emisor. Como obtener()
 * devuelve la respuesta guardada de una emision previa, eso significaria
 * entregarle a un contribuyente el folio de otro. Ademas rut_emisor es la
 * columna mas a la izquierda de la PK: sin ella ninguna de esas consultas
 * puede usar el indice.
 *
 * $rutEmisor va PRIMERO en las cuatro firmas, en el mismo orden que la PK, y
 * para que cualquier caller que no se haya actualizado falle ruidosamente en
 * vez de colocar un valor en la posicion equivocada.
 */
final class MySqlIdempotenciaRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Reclama la clave (INSERT). Devuelve false si ya existe (PK duplicada). */
    public function reclamar(string $rutEmisor, Ambiente $ambiente, string $clave): bool
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO dte_idempotencia (rut_emisor, ambiente, clave) '
                . 'VALUES (:rut, :amb, :clave)'
            )->execute([
                ':rut'   => $rutEmisor,
                ':amb'   => $ambiente->value,
                ':clave' => $clave,
            ]);

            return true;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') { // clave duplicada
                return false;
            }
            throw $e;
        }
    }

    /**
     * @return array{folio:?int, httpStatus:?int, respuestaJson:?string, edad:int}|null
     */
    public function obtener(string $rutEmisor, Ambiente $ambiente, string $clave): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT folio, http_status, respuesta_json, '
            . 'TIMESTAMPDIFF(SECOND, created_at, NOW()) AS edad '
            . 'FROM dte_idempotencia '
            . 'WHERE rut_emisor = :rut AND ambiente = :amb AND clave = :clave'
        );
        $stmt->execute([':rut' => $rutEmisor, ':amb' => $ambiente->value, ':clave' => $clave]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'folio'         => $row['folio'] !== null ? (int) $row['folio'] : null,
            'httpStatus'    => $row['http_status'] !== null ? (int) $row['http_status'] : null,
            'respuestaJson' => $row['respuesta_json'],
            'edad'          => (int) $row['edad'],
        ];
    }

    /**
     * Reactiva un claim MUERTO: SIN COMPLETAR y mas viejo que el TTL (servidor caido
     * a mitad). Lo borra (rowCount de DELETE es fiable, a diferencia del de UPDATE que
     * cuenta filas cambiadas) y lo vuelve a reclamar con created_at fresco. Atomico:
     * solo un proceso gana el DELETE; el resto recibe false -> 409. true si reactivo.
     *
     * LA COLUMNA TESTIGO ES http_status, NO folio. Las cuatro columnas de resultado
     * (tipo_dte, folio, http_status, respuesta_json) se llenan en el UNICO UPDATE de
     * completar(), asi que cualquiera de ellas sirve de marca de "terminado" -- pero
     * folio MIENTE para un lote, que emite N documentos y no tiene UN folio.
     *
     * SI ESTE WHERE SIGUIERA MIRANDO folio, un lote completado (folio NULL por
     * definicion) seria tratado como claim muerto y BORRADO al pasar el TTL: su
     * replay dejaria de funcionar en silencio a los 300 segundos, y un reintento
     * legitimo del cliente volveria a emitir el lote entero.
     */
    public function reactivarSiMuerto(
        string $rutEmisor,
        Ambiente $ambiente,
        string $clave,
        int $ttlSegundos,
    ): bool {
        $del = $this->pdo->prepare(
            'DELETE FROM dte_idempotencia '
            . 'WHERE rut_emisor = :rut AND ambiente = :amb AND clave = :clave AND http_status IS NULL '
            . '  AND TIMESTAMPDIFF(SECOND, created_at, NOW()) >= :ttl'
        );
        // :ttl se liga como ENTERO explicitamente. execute([...]) liga todo como
        // string, y aunque MySQL coacciona sin problema, la comparacion
        // "entero >= texto" no es equivalente en todos los motores. Con el tipo
        // declarado el predicado dice lo mismo en cualquier parte.
        $del->bindValue(':rut', $rutEmisor);
        $del->bindValue(':amb', $ambiente->value);
        $del->bindValue(':clave', $clave);
        $del->bindValue(':ttl', $ttlSegundos, PDO::PARAM_INT);
        $del->execute();
        if ($del->rowCount() === 0) {
            return false;
        }

        return $this->reclamar($rutEmisor, $ambiente, $clave);
    }

    /**
     * Suelta un claim SIN COMPLETAR de esta misma solicitud, para que un reintento
     * inmediato con la misma clave no choque contra un 409 durante todo el TTL.
     *
     * SOLO PARA FALLOS QUE NO CONSUMIERON NADA. Un claim existe para que un
     * reintento no emita dos veces, asi que soltarlo es seguro UNICAMENTE cuando
     * consta que la solicitud no llego a asignar un folio ni a hablar con el SII
     * -- el caso de "no hay CAF activo", que revienta antes de las dos cosas. Ante
     * cualquier fallo posterior el claim se queda donde esta y expira por TTL: mas
     * vale hacer esperar 5 minutos que arriesgar un documento duplicado, porque los
     * folios no se devuelven.
     *
     * EL WHERE EXIGE http_status IS NULL para no borrar jamas un resultado ya
     * guardado: si otra pasada alcanzo a completar el claim, su replay tiene que
     * seguir funcionando. Y por eso mismo no hay riesgo en llamarlo de mas.
     */
    public function liberar(string $rutEmisor, Ambiente $ambiente, string $clave): void
    {
        $del = $this->pdo->prepare(
            'DELETE FROM dte_idempotencia '
            . 'WHERE rut_emisor = :rut AND ambiente = :amb AND clave = :clave AND http_status IS NULL'
        );
        $del->execute([
            ':rut'   => $rutEmisor,
            ':amb'   => $ambiente->value,
            ':clave' => $clave,
        ]);
    }

    /**
     * Guarda el resultado de una emision exitosa para reintentos con la misma clave.
     *
     * tipoDte y folio son NULLABLES porque un LOTE no tiene un tipo ni un folio: emite
     * N documentos, de tipos que pueden ser distintos, cada uno con su propio folio.
     * Guardar uno de ellos seria mentir, y guardar el primero haria que idx_folio
     * apuntara a un documento que no representa la solicitud. Para el lote los dos
     * quedan en NULL y lo que manda es http_status + respuesta_json, que es donde
     * viven el trackId y la lista completa de {tipoDte, folio}.
     *
     * El unitario los sigue pasando, y por eso idx_folio (ambiente, tipo_dte, folio)
     * conserva su utilidad para ese caso.
     */
    public function completar(
        string $rutEmisor,
        Ambiente $ambiente,
        string $clave,
        ?int $tipoDte,
        ?int $folio,
        string $respuestaJson,
        int $httpStatus,
    ): void {
        $this->pdo->prepare(
            'UPDATE dte_idempotencia '
            . 'SET tipo_dte = :tipo, folio = :folio, respuesta_json = :json, http_status = :status '
            . 'WHERE rut_emisor = :rut AND ambiente = :amb AND clave = :clave'
        )->execute([
            ':tipo'   => $tipoDte,
            ':folio'  => $folio,
            ':json'   => $respuestaJson,
            ':status' => $httpStatus,
            ':rut'    => $rutEmisor,
            ':amb'    => $ambiente->value,
            ':clave'  => $clave,
        ]);
    }
}
