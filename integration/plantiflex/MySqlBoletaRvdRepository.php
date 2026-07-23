<?php

declare(strict_types=1);

namespace Plantiflex\Integration\Facturacion;

use PDO;
use Plantiflex\FacturacionCl\Enums\Ambiente;

/**
 * Persistencia del RVD (ConsumoFolios) de boleta enviado al SII (tabla
 * dte_boleta_rvd, migracion 012). El RVD no es un DTE (sin TipoDTE/Folio
 * propio): no puede vivir en dte_emitido, mismo motivo por el que dte_libro
 * es una tabla aparte -- ver MySqlLibroRepository.
 */
final class MySqlBoletaRvdRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function registrar(
        string $rutEmisor,
        Ambiente $ambiente,
        string $fechaRvd,
        ?string $trackId,
        string $estado,
        string $xml,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO dte_boleta_rvd (rut_emisor, ambiente, fecha_rvd, track_id, estado, xml) '
            . 'VALUES (:rut, :amb, :fecha, :track, :estado, :xml)'
        );
        $stmt->execute([
            ':rut'    => $rutEmisor,
            ':amb'    => $ambiente->value,
            ':fecha'  => $fechaRvd,
            ':track'  => $trackId,
            ':estado' => $estado,
            ':xml'    => $xml,
        ]);
    }

    /** @return array<string,mixed>|null El ultimo RVD enviado para este tenant, o null si nunca se envio. */
    public function ultimo(string $rutEmisor, Ambiente $ambiente): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT fecha_rvd, track_id, estado, created_at FROM dte_boleta_rvd '
            . 'WHERE rut_emisor = :rut AND ambiente = :amb ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':rut' => $rutEmisor, ':amb' => $ambiente->value]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);

        return $fila !== false ? $fila : null;
    }
}
