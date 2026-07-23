<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Dto;

/**
 * Un item dentro de un caso del set basico de pruebas del SII.
 */
final readonly class ItemCasoSetPruebas
{
    public function __construct(
        public string $nombre,
        public int $cantidad,
        public ?int $precioUnitario,
        public ?int $descuentoPorcentaje,
        public bool $exento,
    ) {
    }
}
