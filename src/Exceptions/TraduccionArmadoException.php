<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Exceptions;

use Throwable;

/**
 * No se pudo traducir el pedido del usuario a un borrador de factura.
 *
 * =============================================================================
 * HEREDA DE TraduccionPreguntaException A PROPOSITO
 * =============================================================================
 *
 * Los TRES MOTIVOS son exactamente los mismos -- sin clave, sin respuesta,
 * respuesta ilegible -- y la POLITICA que el panel aplica sobre ellos tambien:
 * "si no fue por falta de clave, la llamada salio y descuenta cupo". Declarar una
 * segunda enumeracion identica obligaria a mantener dos listas sincronizadas y a
 * escribir el mismo catch dos veces, y el dia que una de las dos se olvide, el
 * cupo se descontaria mal en silencio.
 *
 * Heredando, un `catch (TraduccionPreguntaException $e)` cubre los dos caminos y
 * `$e->motivo` sigue significando lo mismo. Lo unico propio de esta clase es EL
 * TEXTO QUE LEE EL USUARIO: decirle "el chat de consultas no esta configurado"
 * mientras intenta armar una factura lo manda a buscar el problema donde no esta.
 *
 * -----------------------------------------------------------------------------
 * POR QUE SE REDECLARAN LOS TRES CONSTRUCTORES Y NO SE HEREDAN
 * -----------------------------------------------------------------------------
 * No es solo por el texto. `new self(...)` dentro de un metodo estatico resuelve
 * a la clase donde ESTA ESCRITO, no a la que se invoco: heredarlos tal cual haria
 * que TraduccionArmadoException::sinClave() devolviera una instancia de la clase
 * PADRE. El objeto seria del tipo equivocado sin que nada avisara. Redeclararlos
 * es lo que hace que `self` sea esta clase.
 */
final class TraduccionArmadoException extends TraduccionPreguntaException
{
    public static function sinClave(string $variable): self
    {
        return new self(
            self::SIN_CLAVE,
            "el armado de facturas por chat no esta configurado en el servidor (falta {$variable}). "
            . 'Puedes emitir normalmente desde Ventas > Factura electronica.',
        );
    }

    public static function sinRespuesta(string $detalle, ?Throwable $previous = null): self
    {
        return new self(
            self::SIN_RESPUESTA,
            'el servicio no respondio mientras armaba la factura: ' . $detalle
            . '. No se creo ni se emitio nada.',
            0,
            $previous,
        );
    }

    public static function respuestaIlegible(string $detalle): self
    {
        return new self(
            self::RESPUESTA_ILEGIBLE,
            'la respuesta del servicio no se pudo interpretar mientras armaba la factura: ' . $detalle
            . '. No se creo ni se emitio nada.',
        );
    }
}
