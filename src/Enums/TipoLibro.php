<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Enums;

/**
 * Tipo de libro IECV. El value es el texto que el SII espera en <TipoLibro>.
 */
enum TipoLibro: string
{
    case Mensual = 'MENSUAL';
    case Especial = 'ESPECIAL';
    case Rectifica = 'RECTIFICA';
}
