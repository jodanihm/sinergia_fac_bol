<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Contracts;

use Plantiflex\FacturacionCl\Enums\Ambiente;

/**
 * Persistencia de cada Libro IECV enviado con exito (tabla dte_libro).
 *
 * Necesaria para que el tenant vea el estado de sus libros (futura estacion 5
 * del panel: Set Basico + Libro de Ventas + Libro de Compras) y para
 * sincronizar el estado por trackId (GET /api/v1/libro/{trackId}/estado-sii).
 * NO guarda material secreto: el XML del libro es un reporte tributario, no un
 * certificado.
 */
interface LibroRepositoryInterface
{
    /**
     * Registra un envio de libro exitoso (el SII ya respondio OK). Una fila
     * por envio; no es idempotente por trackId (cada POST /api/v1/libro crea
     * una fila nueva).
     */
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
    ): void;

    /**
     * True si existe un libro con este trackId que pertenezca al tenant
     * (rut_emisor + ambiente). Usado para el scope de GET
     * /api/v1/libro/{trackId}/estado-sii antes de consultar el SII.
     */
    public function existeTrackId(string $rutEmisor, Ambiente $ambiente, string $trackId): bool;

    /**
     * Actualiza el estado del libro con este trackId (scope del tenant), tras
     * el polling en vivo al SII.
     */
    public function actualizarEstado(string $rutEmisor, Ambiente $ambiente, string $trackId, string $estado): void;
}
