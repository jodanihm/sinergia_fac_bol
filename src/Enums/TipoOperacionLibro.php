<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Enums;

/**
 * Tipo de operacion de un Libro Electronico de Compra/Venta (IECV).
 * El value es el texto que el SII espera en <TipoOperacion>.
 */
enum TipoOperacionLibro: string
{
    case Compra = 'COMPRA';
    case Venta = 'VENTA';
}
