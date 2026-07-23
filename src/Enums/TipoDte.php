<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Enums;

enum TipoDte: int
{
    case FacturaElectronica = 33;
    case FacturaExentaElectronica = 34;
    case BoletaElectronica = 39;
    case BoletaExentaElectronica = 41;
    case NotaCreditoElectronica = 61;
    case NotaDebitoElectronica = 56;
    case GuiaDespachoElectronica = 52;

    public function esBoleta(): bool
    {
        return match ($this) {
            self::BoletaElectronica,
            self::BoletaExentaElectronica => true,
            default => false,
        };
    }

    public function esFactura(): bool
    {
        return match ($this) {
            self::FacturaElectronica,
            self::FacturaExentaElectronica => true,
            default => false,
        };
    }

    public function esExento(): bool
    {
        return match ($this) {
            self::FacturaExentaElectronica,
            self::BoletaExentaElectronica => true,
            default => false,
        };
    }
}
