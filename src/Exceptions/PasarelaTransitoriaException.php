<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Exceptions;

/**
 * La pasarela no pudo atender AHORA, pero probablemente si mas tarde: timeout,
 * error de red, 5xx, 429.
 *
 * El correo del documento se queda esperando y se reintenta con espera
 * creciente. Nadie tiene que hacer nada: o vuelve la pasarela, o pasadas las
 * horas de alarma alguien decide soltar el correo sin link.
 */
final class PasarelaTransitoriaException extends PagoException
{
}
