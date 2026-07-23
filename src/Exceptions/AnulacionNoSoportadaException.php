<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Exceptions;

/**
 * La operacion de anulacion no esta implementada todavia.
 *
 * Construir una NC tributariamente valida exige consultar el DTE original
 * (receptor + montos) via endpoints de LibreDTE que aun no estan verificados
 * contra la doc real, y distinguir el flujo por tipo de DTE original
 * (factura vs boleta). Hasta validarlo, esta clase prefiere fallar
 * explicitamente antes que generar una NC con datos inventados.
 */
class AnulacionNoSoportadaException extends FacturacionException
{
}
