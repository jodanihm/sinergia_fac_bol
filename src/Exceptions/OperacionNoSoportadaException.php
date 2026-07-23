<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Exceptions;

/**
 * El proveedor concreto todavia no implementa esta operacion (endpoint sin
 * verificar contra ambiente real, o feature no disponible en este proveedor).
 *
 * Se prefiere fallar explicitamente antes que emitir contra un endpoint
 * adivinado.
 */
class OperacionNoSoportadaException extends FacturacionException
{
}
