<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Pago;

/**
 * Convierte el monto que informa una pasarela en pesos enteros, o dice que no se
 * puede.
 *
 * DE DONDE SALE ESTA CLASE. La version anterior hacia `(int) $monto` y despues
 * exigia `is_int()`. Dos defectos en una linea:
 *
 *   - `(int) '49990.5'` da 49990, y `(int) 'mucho'` da 0: un valor corrupto se
 *     convertia en un numero de aspecto razonable ANTES de que nadie lo mirara.
 *   - Flow documenta amount como `number <float>`, asi que json_decode puede
 *     devolver 49990.0 (float) para un pago perfectamente valido. Exigir is_int()
 *     habria RECHAZADO ese pago y mandado la factura a revision manual.
 *
 * O sea que la comprobacion era estricta donde no debia y laxa donde importaba.
 *
 *
 * LA REGLA
 * -----------------------------------------------------------------------------
 * Se acepta un entero; un float que represente EXACTAMENTE una cantidad entera
 * (49990.0); y una cadena de SOLO DIGITOS ('49990'). Se rechaza todo lo demas:
 * 49990.5, '49990.5', '49.990', '49,990', '$49990', '49990 pesos', '4.999e4',
 * '', '   ', '049990', NAN, INF, null, booleanos, arrays, objetos y cualquier
 * valor fuera del rango de un entero.
 *
 * SI SE ACEPTAN CADENAS DE DIGITOS, Y ANTES NO. Aqui decia que una cadena
 * "contradice el tipo `number` que ella misma documenta" y que aceptarla seria
 * "ensanchar la puerta sin una sola evidencia de que haga falta". La evidencia
 * llego: Flow Sandbox confirmo una orden como `pagada` y devolvio
 * `amount: '1190'`, entre comillas. El monto se rechazo, la comparacion dio
 * NULL != 1190 y la factura quedo en estado 'error' con
 * "monto informado '1190' (normalizado NULL) distinto del cobrado 1190" --
 * un descuadre inventado sobre un pago que estaba perfecto.
 *
 * Es la segunda vez en este modulo que una suposicion razonable sobre lo que
 * manda Flow resulta falsa (la primera: que el aviso viniera firmado). La
 * leccion no es que hubiera que ser mas permisivo desde el principio, es que
 * sobre el formato de un tercero no se afirma nada sin haberlo visto.
 *
 * PERO LA CADENA TIENE QUE SER SOLO DIGITOS, y esa parte no se negocia: ver
 * abajo por que un punto en medio no puede aceptarse jamas.
 *
 * NADA DE TOLERANCIAS. Aqui no se compara con epsilon ni se redondea: los montos
 * del SII son pesos enteros y el peso chileno no tiene decimales. Un 49990.5 no
 * es un problema de precision, es un dato que no cuadra, y tiene que llegar como
 * tal a quien decide si la factura se da por pagada.
 */
final class MontoPasarela
{
    /**
     * @return int|null null si el valor NO representa una cantidad entera de
     *                  pesos utilizable. Nunca se devuelve 0 como forma de decir
     *                  "no se pudo": 0 es un monto, null es la ausencia.
     */
    public static function normalizar(mixed $valor): ?int
    {
        if (is_int($valor)) {
            return $valor;
        }

        if (is_float($valor)) {
            // is_finite descarta NAN e INF: sin esto, (int) NAN da basura.
            if (! is_finite($valor)) {
                return null;
            }
            // Parte decimal distinta de cero -> no es una cantidad de pesos.
            if (fmod($valor, 1.0) !== 0.0) {
                return null;
            }
            // Fuera del rango de int, el cast seria silenciosamente incorrecto.
            if ($valor > (float) PHP_INT_MAX || $valor < (float) PHP_INT_MIN) {
                return null;
            }

            return (int) $valor;
        }

        if (is_string($valor)) {
            return self::desdeCadena($valor);
        }

        // bool, null, array, object: no. Y bool importa: (int) true da 1, que
        // pasaria por un monto de un peso.
        return null;
    }

    /**
     * Una cadena de digitos, o null.
     *
     * POR QUE NO is_numeric() + (int), QUE ES LO QUE PIDE EL CUERPO
     * -------------------------------------------------------------------------
     * Porque is_numeric('1.000') es true y (int) '1.000' es 1. En Chile
     * "1.000" son mil pesos y el punto es separador de miles; con esa pareja de
     * funciones, un cobro de $1.000 se normalizaria a 1, cuadraria contra un
     * monto de 1 peso si alguna vez existiera, y en el mejor de los casos
     * generaria un descuadre absurdo. Lo mismo con '1,190', '1.19e3' o
     * ' 1190 '.
     *
     * TAMPOCO SE ACEPTA UN PUNTO CON DECIMALES EN CERO ('1190.0'), que a primera
     * vista parece inofensivo por simetria con el float 1190.0. No lo es: para
     * distinguir '1190.0' (mil ciento noventa) de '1.000' (mil) hay que saber si
     * el punto es decimal o de miles, y eso NO se puede saber mirando la cadena.
     * La unica regla que no se puede confundir es "ningun punto". Si Flow
     * empezara a mandar '1190.0', se rechazara y la factura ira a revision con su
     * motivo escrito -- molesto y recuperable, que es el lado correcto en el que
     * equivocarse cuando hay dinero.
     *
     * EL PATRON, PIEZA POR PIEZA: `-?` un signo negativo opcional (no se acepta
     * '+'), y `(0|[1-9][0-9]*)` un cero solo o un numero sin ceros a la
     * izquierda. Sin espacios: no se hace trim, porque json_decode no los
     * introduce y un monto con espacios es un dato que alguien deberia mirar.
     *
     * EL SIGNO NEGATIVO SE NORMALIZA, no se valida: -500 es una cantidad y esta
     * clase solo dice si el valor ES una cantidad de pesos. Que un monto negativo
     * no pueda cuadrar con lo cobrado lo decide la comparacion de
     * ConfirmacionPago, que exige igualdad estricta contra un monto guardado como
     * UNSIGNED.
     */
    private static function desdeCadena(string $valor): ?int
    {
        if (preg_match('/^-?(0|[1-9][0-9]*)$/', $valor) !== 1) {
            return null;
        }

        // FUERA DE RANGO: (int) sobre una cadena demasiado grande la satura en
        // PHP_INT_MAX en silencio. El viaje de ida y vuelta lo delata, y es exacto
        // porque el patron ya descarto los ceros a la izquierda y el '+'. De paso
        // caza '-0', que da '0' al volver.
        $entero = (int) $valor;
        if ((string) $entero !== $valor) {
            return null;
        }

        return $entero;
    }
}
