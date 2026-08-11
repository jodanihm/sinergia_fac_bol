<?php

declare(strict_types=1);

namespace Plantiflex\Integration\Facturacion;

use RuntimeException;

/**
 * Se intento EDITAR una cotizacion que ya tiene facturacion parcial o total.
 *
 * La lanza MySqlCotizacionRepository::actualizar() DENTRO de la transaccion,
 * despues de bloquear las lineas, aunque el handler ya haya comprobado lo mismo
 * antes de pintar el formulario: entre esa comprobacion y el guardado puede
 * haberse emitido una factura parcial.
 *
 * POR QUE ES UN ERROR Y NO UN MERGE: editar reemplaza las lineas, y las lineas
 * se reemplazan borrando e insertando, lo que cambia sus id. El id de la linea
 * ES el vinculo que una factura parcial guarda para saber de que descuenta
 * saldo; cambiarlo dejaria a las facturas ya emitidas apuntando a lineas que no
 * existen. Mismo criterio liviano que ClienteDuplicadoException: una excepcion
 * de dominio para que el handler la distinga de un error de BD real.
 */
class CotizacionFacturadaException extends RuntimeException
{
}
