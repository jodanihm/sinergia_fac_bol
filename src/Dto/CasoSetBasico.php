<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Dto;

/**
 * Un caso del set basico de pruebas del SII (SIISetDePruebas<RUT>.txt).
 */
final readonly class CasoSetBasico
{
    /** @param list<ItemCasoSetPruebas> $items */
    public function __construct(
        public int $numeroCaso,
        public string $tipoDocumento, // 'FACTURA' | 'NOTA_CREDITO' | 'NOTA_DEBITO'
        public ?int $referenciaCaso,
        public ?string $razonReferencia,
        public array $items,
        public ?int $descuentoGlobalPct,
    ) {
    }
}
