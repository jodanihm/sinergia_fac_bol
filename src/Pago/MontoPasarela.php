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
 * Se acepta un entero, o un float que represente EXACTAMENTE una cantidad entera
 * (49990.0). Se rechaza todo lo demas: 49990.5, NAN, INF, null, booleanos,
 * cadenas y cualquier valor fuera del rango de un entero.
 *
 * NO SE ACEPTAN CADENAS, y no es una omision. json_decode() sin flags devuelve
 * int o float para un numero de JSON, nunca string; una cadena solo llegaria si
 * la pasarela mandara `"49990"` entre comillas, lo que contradice el tipo
 * `number` que ella misma documenta. Aceptarla seria ensanchar la puerta sin una
 * sola evidencia de que haga falta.
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

        // string, bool, null, array, object: no.
        return null;
    }
}
