<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Exceptions;

use Throwable;

/**
 * No se pudo traducir la pregunta del usuario a perillas.
 *
 * MOTIVO ENUMERADO, mismo criterio que ConsultaContribuyenteException: la
 * pantalla tiene que decir cosas distintas porque la accion del usuario es
 * distinta en cada caso.
 *
 *   SIN_CLAVE      No hay credencial en el servidor. El usuario no puede hacer
 *                  nada -- es del administrador --, y lo unico util es decirle
 *                  que use los informes, que siguen ahi.
 *   SIN_RESPUESTA  El proveedor no contesto (timeout, corte, 5xx). Reintentar
 *                  mas tarde tiene sentido.
 *   RESPUESTA_ILEGIBLE  Contesto algo que no es el JSON esperado. NO se
 *                  reintenta a ciegas: si el formato cambio, reintentar no
 *                  arregla nada y hay que mirarlo.
 *
 * QUE NO PASA POR AQUI, y es la distincion que importa: una pregunta que NO SE
 * PUEDE responder -- "que producto vendi mas" -- NO es una excepcion. Es una
 * respuesta valida del traductor y viaja como PreguntaTraducida::imposible() con
 * su motivo, porque es informacion que el usuario necesita leer, no un fallo.
 * Mismo criterio que el contribuyente no autorizado, que tampoco es excepcion.
 */
class TraduccionPreguntaException extends FacturacionException
{
    public const SIN_CLAVE          = 'sin_clave';
    public const SIN_RESPUESTA      = 'sin_respuesta';
    public const RESPUESTA_ILEGIBLE = 'respuesta_ilegible';

    public function __construct(
        public readonly string $motivo,
        string $mensaje,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($mensaje, $code, $previous);
    }

    public static function sinClave(string $variable): self
    {
        return new self(
            self::SIN_CLAVE,
            "el chat de consultas no esta configurado en el servidor (falta {$variable}). "
            . 'Los informes siguen disponibles en el menu.',
        );
    }

    public static function sinRespuesta(string $detalle, ?Throwable $previous = null): self
    {
        return new self(self::SIN_RESPUESTA, 'el servicio de consultas no respondio: ' . $detalle, 0, $previous);
    }

    public static function respuestaIlegible(string $detalle): self
    {
        return new self(
            self::RESPUESTA_ILEGIBLE,
            'la respuesta del servicio de consultas no se pudo interpretar: ' . $detalle,
        );
    }
}
