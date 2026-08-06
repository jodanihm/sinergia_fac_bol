<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Exceptions;

use Throwable;

/**
 * No se pudo obtener una respuesta del proveedor de consulta de contribuyentes.
 *
 * LLEVA UN MOTIVO ENUMERADO, Y NO ES ADORNO. La pantalla del alta de empresa
 * tiene que decirle al usuario COSAS DISTINTAS segun por que fallo, porque la
 * accion que le toca es distinta en cada caso:
 *
 *   SIN_TOKEN     No hay credencial configurada en el servidor. El usuario no
 *                 puede hacer nada -- es cosa del administrador -- y lo unico
 *                 sensato es decirle que teclee los datos a mano.
 *   SIN_RESPUESTA El proveedor o el SII no contestaron (timeout, corte, 5xx).
 *                 Aqui SI tiene sentido sugerir reintentar mas tarde.
 *   RESPUESTA_ILEGIBLE  Contesto algo que no se pudo interpretar. No se
 *                 reintenta a ciegas: si el formato cambio, reintentar no
 *                 arregla nada y hay que mirarlo.
 *
 * UN CONTRIBUYENTE NO AUTORIZADO NO PASA POR AQUI. Eso es una respuesta valida
 * del proveedor y viaja como ContribuyenteAutorizado con autorizado=false: es
 * informacion que el usuario necesita, no un fallo de la consulta.
 */
class ConsultaContribuyenteException extends FacturacionException
{
    public const SIN_TOKEN           = 'sin_token';
    public const SIN_RESPUESTA       = 'sin_respuesta';
    public const RESPUESTA_ILEGIBLE  = 'respuesta_ilegible';

    public function __construct(
        public readonly string $motivo,
        string $mensaje,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($mensaje, $code, $previous);
    }

    public static function sinToken(string $variable): self
    {
        return new self(
            self::SIN_TOKEN,
            "no hay credencial de consulta configurada en el servidor (falta {$variable})",
        );
    }

    public static function sinRespuesta(string $detalle, ?Throwable $previous = null): self
    {
        return new self(self::SIN_RESPUESTA, 'el proveedor no respondio: ' . $detalle, 0, $previous);
    }

    public static function respuestaIlegible(string $detalle): self
    {
        return new self(self::RESPUESTA_ILEGIBLE, 'la respuesta del proveedor no se pudo interpretar: ' . $detalle);
    }
}
