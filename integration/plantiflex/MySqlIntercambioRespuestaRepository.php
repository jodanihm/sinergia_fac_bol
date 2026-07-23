<?php

declare(strict_types=1);

namespace Plantiflex\Integration\Facturacion;

use PDO;
use Plantiflex\FacturacionCl\Enums\Ambiente;

/**
 * Persiste las respuestas de la etapa "Intercambio de Informacion" (tabla
 * dte_intercambio_respuesta). Una sola generacion vigente por
 * rut_emisor+ambiente: guardar() reemplaza la anterior (no hay historial),
 * mismo patron que MySqlSetPruebasArchivoRepository.
 */
final class MySqlIntercambioRespuestaRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function guardar(
        string $rutEmisor,
        Ambiente $ambiente,
        ?int $numeroIntercambio,
        string $archivoEnvioOriginal,
        string $respuestaAcuse,
        string $respuestaResultado,
        string $respuestaRecibos,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO dte_intercambio_respuesta '
            . '(rut_emisor, ambiente, numero_intercambio, archivo_envio_original, respuesta_acuse, respuesta_resultado, respuesta_recibos) '
            . 'VALUES (:rut, :amb, :numero, :original, :acuse, :resultado, :recibos) '
            . 'ON DUPLICATE KEY UPDATE numero_intercambio = VALUES(numero_intercambio), '
            . 'archivo_envio_original = VALUES(archivo_envio_original), '
            . 'respuesta_acuse = VALUES(respuesta_acuse), '
            . 'respuesta_resultado = VALUES(respuesta_resultado), '
            . 'respuesta_recibos = VALUES(respuesta_recibos), '
            . 'created_at = CURRENT_TIMESTAMP'
        );
        $stmt->bindValue(':rut', $rutEmisor);
        $stmt->bindValue(':amb', $ambiente->value);
        $stmt->bindValue(':numero', $numeroIntercambio, $numeroIntercambio === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':original', $archivoEnvioOriginal, PDO::PARAM_LOB);
        $stmt->bindValue(':acuse', $respuestaAcuse, PDO::PARAM_LOB);
        $stmt->bindValue(':resultado', $respuestaResultado, PDO::PARAM_LOB);
        $stmt->bindValue(':recibos', $respuestaRecibos, PDO::PARAM_LOB);
        $stmt->execute();
    }

    /**
     * @return array{numero_intercambio: ?int, archivo_envio_original: string, respuesta_acuse: string, respuesta_resultado: string, respuesta_recibos: string, created_at: string}|null
     */
    public function obtener(string $rutEmisor, Ambiente $ambiente): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT numero_intercambio, archivo_envio_original, respuesta_acuse, respuesta_resultado, respuesta_recibos, created_at '
            . 'FROM dte_intercambio_respuesta WHERE rut_emisor = :rut AND ambiente = :amb LIMIT 1'
        );
        $stmt->execute([':rut' => $rutEmisor, ':amb' => $ambiente->value]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($fila === false) {
            return null;
        }

        $fila['numero_intercambio'] = $fila['numero_intercambio'] !== null ? (int) $fila['numero_intercambio'] : null;

        return $fila;
    }
}
