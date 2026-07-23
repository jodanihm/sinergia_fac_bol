<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Dto;

/**
 * Una fila de la tabla "Actividades Economicas" del archivo de Datos para
 * Construccion DTE (pe_construccion_dte) del SII.
 */
final readonly class ActividadEconomicaSii
{
    public function __construct(
        public int $codigo,
        public string $descripcion,
        public bool $afectoIva,
    ) {
    }
}
