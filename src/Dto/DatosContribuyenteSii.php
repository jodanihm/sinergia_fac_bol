<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Dto;

/**
 * Resultado de parsear el archivo de Datos para Construccion DTE
 * (pe_construccion_dte) que entrega el SII: datos de la empresa para
 * precargar la estacion 2 del panel (/empresa). El archivo NO trae fecha ni
 * numero de Resolucion -- esos 2 datos siguen siendo responsabilidad manual
 * del usuario, este DTO no los incluye.
 */
final readonly class DatosContribuyenteSii
{
    /** @param list<ActividadEconomicaSii> $actividades */
    public function __construct(
        public string $rut,
        public string $razonSocial,
        public string $direccion,
        public string $comuna,
        public string $giro,
        public array $actividades,
    ) {
    }

    /**
     * Acteco a precargar en dte_emisor.acteco (INT NOT NULL, un solo valor):
     * el PRIMERO de la tabla de actividades economicas. El archivo del SII
     * no trae ninguna marca de "principal" -- si algun archivo real la
     * trajera, este seria el lugar para respetarla en vez de asumir el
     * primero.
     */
    public function actecoPrincipal(): int
    {
        return $this->actividades[0]->codigo;
    }
}
