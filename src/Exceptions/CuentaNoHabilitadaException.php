<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Exceptions;

/**
 * El proveedor respondio HTTP 402 Payment Required.
 *
 * En API Gateway esto significa que la cuenta no esta habilitada para emitir
 * o no tiene creditos suficientes. NO es un fallo de credenciales: la auth
 * fue aceptada, pero la cuenta no puede operar.
 */
class CuentaNoHabilitadaException extends FacturacionException
{
}
