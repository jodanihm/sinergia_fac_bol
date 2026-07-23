<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Enums;

/**
 * Tipo de envio de un libro IECV. El value es el texto que el SII espera en
 * <TipoEnvio>.
 */
enum TipoEnvioLibro: string
{
    case Total = 'TOTAL';
    case Parcial = 'PARCIAL';
    case Ajuste = 'AJUSTE';
}
