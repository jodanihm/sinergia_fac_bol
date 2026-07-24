<?php

declare(strict_types=1);

namespace Plantiflex\Integration\Facturacion;

use RuntimeException;

/**
 * Se intento crear/actualizar un producto con un codigo (SKU) que ya existe en
 * la misma cuenta (viola UNIQUE(cuenta_id, codigo)).
 *
 * La lanza MySqlProductoRepository al traducir el error de duplicado del driver
 * (errno 1062) a una excepcion de dominio liviana, para que el handler la
 * distinga de un error de BD real. Cualquier otro error de PDO se propaga tal
 * cual.
 */
class ProductoDuplicadoException extends RuntimeException
{
}
