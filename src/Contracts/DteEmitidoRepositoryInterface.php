<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Contracts;

use Plantiflex\FacturacionCl\Enums\Ambiente;

/**
 * Persistencia de cada DTE emitido con exito (tabla dte_emitido).
 *
 * Necesaria para reimpresion (PDF), consulta de estado por folio, anulacion por
 * referencia, libros IECV (por eso neto/iva separados) y auditoria. NO guarda
 * material secreto: el XML del DTE es un documento que se entrega al receptor.
 */
interface DteEmitidoRepositoryInterface
{
    /**
     * Registra una emision exitosa. Idempotente por (rut_emisor, ambiente,
     * tipo_dte, folio): si ya existe, actualiza los datos.
     *
     * @param string      $fechaEmision FchEmis en formato YYYY-MM-DD.
     * @param int|null    $folioRef     Folio del documento referenciado (NC/ND); null si no aplica.
     * @param int|null    $tipoDteRef   Tipo del documento referenciado (ej 33); null si no aplica.
     */
    public function registrar(
        string $rutEmisor,
        Ambiente $ambiente,
        int $tipoDte,
        int $folio,
        ?string $trackId,
        string $estado,
        string $xml,
        string $fechaEmision,
        int $neto,
        int $iva,
        int $total,
        string $receptorRut,
        ?int $folioRef,
        ?int $tipoDteRef,
    ): void;

    /**
     * Devuelve el XML del DTE persistido (bytes tal como se enviaron al SII), o
     * null si no existe. Fuente de verdad para consultar estado por folio y PDF.
     */
    public function obtenerXml(
        string $rutEmisor,
        Ambiente $ambiente,
        int $tipoDte,
        int $folio,
    ): ?string;

    /**
     * Indica si ya existe una Nota de Credito (tipo 61) emitida que referencia al
     * documento (tipoDteRef, folioRef). Guarda deterministica contra doble anulacion.
     */
    public function existeAnulacion(
        string $rutEmisor,
        Ambiente $ambiente,
        int $tipoDteRef,
        int $folioRef,
    ): bool;

    /**
     * Actualiza estado (y opcionalmente track_id) tras el polling EPR/DOK.
     */
    public function actualizarEstado(
        string $rutEmisor,
        Ambiente $ambiente,
        int $tipoDte,
        int $folio,
        string $estado,
        ?string $trackId = null,
    ): void;
}
