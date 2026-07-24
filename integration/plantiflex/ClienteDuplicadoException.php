<?php

declare(strict_types=1);

namespace Plantiflex\Integration\Facturacion;

use RuntimeException;

/**
 * Se intento crear/actualizar un cliente con un RUT que ya existe en la misma
 * cuenta (viola UNIQUE(cuenta_id, rut_cliente)).
 *
 * La lanza MySqlClienteRepository al traducir el error de duplicado del driver
 * (errno 1062) a una excepcion de dominio liviana, para que el handler la
 * distinga de un error de BD real. Cualquier otro error de PDO se propaga tal
 * cual.
 */
class ClienteDuplicadoException extends RuntimeException
{
}
