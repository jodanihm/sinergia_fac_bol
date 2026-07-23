<?php

declare(strict_types=1);

namespace Plantiflex\Integration\Facturacion;

use PDO;
use Plantiflex\FacturacionCl\Enums\Ambiente;

/**
 * Confirmacion MANUAL del tenant de que un envio del Set Basico paso la
 * revision de CONTENIDO del SII (SOK). Ver 009_dte_set_basico_sok.sql para
 * el porque de esta tabla: EPR (envio procesado tecnicamente) NO implica SOK
 * (contenido aprobado) -- ese resultado llega por correo aparte del SII y
 * nunca se infiere, solo se marca a mano.
 */
final class MySqlSetBasicoSokRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Idempotente: si ya estaba marcado, no lo duplica ni pisa la fecha original. */
    public function marcar(string $rutEmisor, Ambiente $ambiente, string $trackId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO dte_set_basico_sok (rut_emisor, ambiente, track_id) VALUES (:rut, :amb, :track) '
            . 'ON DUPLICATE KEY UPDATE track_id = track_id'
        );
        $stmt->execute([':rut' => $rutEmisor, ':amb' => $ambiente->value, ':track' => $trackId]);
    }

    /** @return array<string,string> track_id => confirmado_sok_at, de los envios que el tenant ya marco */
    public function confirmadosPorTrackId(string $rutEmisor, Ambiente $ambiente): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT track_id, confirmado_sok_at FROM dte_set_basico_sok WHERE rut_emisor = :rut AND ambiente = :amb'
        );
        $stmt->execute([':rut' => $rutEmisor, ':amb' => $ambiente->value]);

        $mapa = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $mapa[(string) $r['track_id']] = (string) $r['confirmado_sok_at'];
        }

        return $mapa;
    }
}
