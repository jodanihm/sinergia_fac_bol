<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Exceptions;

/**
 * La empresa no tiene pasarela utilizable: sin fila, sin credenciales, o con la
 * llave maestra ilegible.
 *
 * NO ES "no aplica". La diferencia es la que separa "esta empresa no quiere
 * cobrar en linea" (interruptor apagado, y entonces el correo sale normal) de
 * "esta empresa SI quiere y su configuracion esta rota". Lo segundo tiene que
 * retener el correo y avisar, nunca degradar en silencio a mandarlo sin link:
 * eso convertiria un error de configuracion en facturas enviadas sin cobro y sin
 * que nadie se entere.
 */
final class PasarelaNoConfiguradaException extends PagoException
{
}
