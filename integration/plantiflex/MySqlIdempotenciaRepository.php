<?php

declare(strict_types=1);

namespace Plantiflex\Integration\Facturacion;

use PDO;
use PDOException;
use Plantiflex\FacturacionCl\Enums\Ambiente;

/**
 * Idempotencia de emision por (ambiente, Idempotency-Key) -> tabla dte_idempotencia.
 *
 * Garantiza at-most-once por clave: el INSERT contra la PK (ambiente, clave) actua
 * como candado. Un claim sin folio = emision en curso o servidor caido a mitad; un
 * TTL permite reactivarlo para no bloquear para siempre.
 */
final class MySqlIdempotenciaRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Reclama la clave (INSERT). Devuelve false si ya existe (PK duplicada). */
    public function reclamar(Ambiente $ambiente, string $clave): bool
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO dte_idempotencia (ambiente, clave) VALUES (:amb, :clave)'
            )->execute([':amb' => $ambiente->value, ':clave' => $clave]);

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
    public function obtener(Ambiente $ambiente, string $clave): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT folio, http_status, respuesta_json, '
            . 'TIMESTAMPDIFF(SECOND, created_at, NOW()) AS edad '
            . 'FROM dte_idempotencia WHERE ambiente = :amb AND clave = :clave'
        );
        $stmt->execute([':amb' => $ambiente->value, ':clave' => $clave]);
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
     * Reactiva un claim MUERTO: sin folio y mas viejo que el TTL (servidor caido a
     * mitad). Lo borra (rowCount de DELETE es fiable, a diferencia del de UPDATE que
     * cuenta filas cambiadas) y lo vuelve a reclamar con created_at fresco. Atomico:
     * solo un proceso gana el DELETE; el resto recibe false -> 409. true si reactivo.
     */
    public function reactivarSiMuerto(Ambiente $ambiente, string $clave, int $ttlSegundos): bool
    {
        $del = $this->pdo->prepare(
            'DELETE FROM dte_idempotencia '
            . 'WHERE ambiente = :amb AND clave = :clave AND folio IS NULL '
            . '  AND TIMESTAMPDIFF(SECOND, created_at, NOW()) >= :ttl'
        );
        $del->execute([':amb' => $ambiente->value, ':clave' => $clave, ':ttl' => $ttlSegundos]);
        if ($del->rowCount() === 0) {
            return false;
        }

        return $this->reclamar($ambiente, $clave);
    }

    /** Guarda el resultado de una emision exitosa para reintentos con la misma clave. */
    public function completar(
        Ambiente $ambiente,
        string $clave,
        int $tipoDte,
        int $folio,
        string $respuestaJson,
        int $httpStatus,
    ): void {
        $this->pdo->prepare(
            'UPDATE dte_idempotencia '
            . 'SET tipo_dte = :tipo, folio = :folio, respuesta_json = :json, http_status = :status '
            . 'WHERE ambiente = :amb AND clave = :clave'
        )->execute([
            ':tipo'   => $tipoDte,
            ':folio'  => $folio,
            ':json'   => $respuestaJson,
            ':status' => $httpStatus,
            ':amb'    => $ambiente->value,
            ':clave'  => $clave,
        ]);
    }
}
