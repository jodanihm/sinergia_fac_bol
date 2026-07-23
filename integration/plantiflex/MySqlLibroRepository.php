<?php

declare(strict_types=1);

namespace Plantiflex\Integration\Facturacion;

use PDO;
use Plantiflex\FacturacionCl\Contracts\LibroRepositoryInterface;
use Plantiflex\FacturacionCl\Enums\Ambiente;

/**
 * Implementacion MySQL de {@see LibroRepositoryInterface} (tabla dte_libro).
 *
 * El XML se guarda en CLARO, igual que dte_emitido: el libro es un reporte
 * tributario que el propio emisor puede necesitar revisar, no material
 * secreto (a diferencia del CAF/certificado, que si se cifran).
 */
final class MySqlLibroRepository implements LibroRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function registrar(
        string $rutEmisor,
        Ambiente $ambiente,
        string $tipoOperacion,
        string $periodoTributario,
        string $tipoLibro,
        string $tipoEnvio,
        ?int $folioNotificacion,
        ?string $trackId,
        string $estado,
        string $xml,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO dte_libro '
            . '(rut_emisor, ambiente, tipo_operacion, periodo_tributario, tipo_libro, tipo_envio, '
            . ' folio_notificacion, track_id, estado, xml) '
            . 'VALUES (:rut, :amb, :tipoOp, :periodo, :tipoLibro, :tipoEnvio, '
            . ' :folioNotif, :track, :estado, :xml)'
        );
        $stmt->execute([
            ':rut'        => $rutEmisor,
            ':amb'        => $ambiente->value,
            ':tipoOp'     => $tipoOperacion,
            ':periodo'    => $periodoTributario,
            ':tipoLibro'  => $tipoLibro,
            ':tipoEnvio'  => $tipoEnvio,
            ':folioNotif' => $folioNotificacion,
            ':track'      => $trackId,
            ':estado'     => $estado,
            ':xml'        => $xml,
        ]);
    }

    public function existeTrackId(string $rutEmisor, Ambiente $ambiente, string $trackId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM dte_libro WHERE rut_emisor = :rut AND ambiente = :amb AND track_id = :track LIMIT 1'
        );
        $stmt->execute([':rut' => $rutEmisor, ':amb' => $ambiente->value, ':track' => $trackId]);

        return $stmt->fetchColumn() !== false;
    }

    public function actualizarEstado(string $rutEmisor, Ambiente $ambiente, string $trackId, string $estado): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE dte_libro SET estado = :estado '
            . 'WHERE rut_emisor = :rut AND ambiente = :amb AND track_id = :track'
        );
        $stmt->execute([
            ':estado' => $estado,
            ':rut'    => $rutEmisor,
            ':amb'    => $ambiente->value,
            ':track'  => $trackId,
        ]);
    }
}
