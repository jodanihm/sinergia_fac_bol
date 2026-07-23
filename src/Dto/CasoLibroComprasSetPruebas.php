<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Dto;

/**
 * Un documento del set de pruebas de Libro de Compras del SII.
 *
 * ivaUsoComun / ivaNoRecuperable / retencionTotalIva / folioReferenciado se
 * detectan por coincidencia de texto en la observacion (ver
 * SetPruebasParser::parsearCasoLibroCompras), no son reglas inventadas: los
 * tres primeros corresponden a los casos especiales documentados en el
 * manual del SII (docs/40_Casos_Especiales_Registro_Documentos_IECV.pdf).
 */
final readonly class CasoLibroComprasSetPruebas
{
    public function __construct(
        public string $tipoDocumentoTexto,
        public int $folio,
        public string $observacion,
        public int $montoExento,
        public int $montoAfecto,
        public bool $ivaUsoComun,
        public bool $ivaNoRecuperable,
        public bool $retencionTotalIva,
        public ?int $folioReferenciado,
    ) {
    }
}
