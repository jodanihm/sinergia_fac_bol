<?php

declare(strict_types=1);

namespace Plantiflex\Integration\Facturacion;

use RuntimeException;

/**
 * Una perilla de la consulta de ventas no se reconoce, o su combinacion no se
 * puede responder con los datos que hay.
 *
 * MISMA FORMA QUE validarDocumentoDte() DESPUES DE CERRAR SU LISTA BLANCA: lo
 * que no se reconoce REVIENTA, no se descarta en silencio. Alli el costo de
 * ignorar una clave era emitir un documento incompleto con el folio quemado;
 * aqui es responder un numero que contesta OTRA pregunta -- y un total que no
 * es el que se pidio es peor que un error, porque nadie lo revisa.
 *
 * DOS FAMILIAS DE MOTIVO, Y SE DISTINGUEN A PROPOSITO:
 *
 *   - perillaDesconocida()/valorInvalido(): quien llama se equivoco. La capa de
 *     IA que venga despues tiene que corregir y reintentar.
 *   - noSePuedeResponder(): la pregunta es legitima pero los datos NO EXISTEN.
 *     El caso concreto y previsto es cualquier cosa a nivel de PRODUCTO: el
 *     detalle de un DTE emitido vive dentro del XML, no en columnas. Un usuario
 *     VA a preguntar "que producto vendi mas", y el chat tiene que saber DECIR
 *     QUE NO PUEDE en vez de inventar o devolver otra cosa parecida.
 */
class ConsultaVentasInvalidaException extends RuntimeException
{
    public static function perillaDesconocida(array $desconocidas, array $conocidas): self
    {
        return new self(sprintf(
            'perillas que la consulta no reconoce (%s). No se ignoran: corrige la peticion. '
            . 'Perillas validas: %s.',
            implode(', ', $desconocidas),
            implode(', ', $conocidas),
        ));
    }

    public static function valorInvalido(string $perilla, string $valor, array $validos): self
    {
        return new self(sprintf(
            "la perilla '%s' no acepta el valor '%s'. Valores validos: %s.",
            $perilla,
            $valor,
            implode(', ', $validos),
        ));
    }

    /** La pregunta se entiende, pero no hay datos para contestarla. */
    public static function noSePuedeResponder(string $que, string $porque): self
    {
        return new self(sprintf('no se puede responder %s: %s', $que, $porque));
    }
}
