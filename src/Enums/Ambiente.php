<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Enums;

enum Ambiente: string
{
    case Produccion = 'produccion';
    case Certificacion = 'certificacion';

    public function esProduccion(): bool
    {
        return $this === self::Produccion;
    }
}
