<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Dto;

/**
 * Resultado de parsear un archivo SIISetDePruebas<RUT>.txt del SII.
 */
final readonly class SetPruebasParseado
{
    /**
     * @param list<CasoSetBasico>               $casos
     * @param list<CasoLibroComprasSetPruebas>  $casosLibroCompras
     * @param list<string>                      $advertencias lineas que no se pudieron interpretar; el resto del
     *                                                         archivo se parsea igual (no se falla silenciosamente).
     */
    public function __construct(
        public int $numeroAtencionSetBasico,
        public ?int $numeroAtencionLibroVentas,
        public ?int $numeroAtencionLibroCompras,
        public array $casos,
        public array $casosLibroCompras,
        public ?float $factorProporcionalidadIvaUsoComun,
        public array $advertencias,
    ) {
    }
}
