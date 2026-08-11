<?php

declare(strict_types=1);

namespace Plantiflex\Integration\Facturacion;

use RuntimeException;

/**
 * Se intento crear o actualizar un proveedor con un RUT que ya existe en la
 * misma cuenta (viola UNIQUE(cuenta_id, rut_proveedor)).
 *
 * Misma forma liviana que ClienteDuplicadoException: la lanza el repositorio al
 * traducir el error de duplicado del driver (errno 1062), para que el handler la
 * distinga de un error de base real y muestre un mensaje util. Cualquier otro
 * error de PDO se propaga tal cual.
 *
 * OJO CON LO QUE **NO** SIGNIFICA: que un RUT sea proveedor no impide que sea
 * cliente. Son dos maestros distintos, con dos UNIQUE distintos, y ese es el
 * motivo por el que proveedor es una tabla aparte y no un tipo dentro de
 * cliente.
 */
class ProveedorDuplicadoException extends RuntimeException
{
}
