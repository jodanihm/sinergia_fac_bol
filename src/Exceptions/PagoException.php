<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Exceptions;

/**
 * Raiz de todo lo que puede salir mal cobrando. Existe para que un llamador
 * pueda capturar "cualquier problema de pago" sin enumerar las tres hijas, y
 * para que ninguna de ellas se cuele como un Throwable cualquiera en el runner
 * de correos, donde la diferencia entre esperar y seguir la marca justamente el
 * TIPO de la excepcion.
 */
abstract class PagoException extends FacturacionException
{
}
