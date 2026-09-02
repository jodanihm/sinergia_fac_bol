<?php

declare(strict_types=1);

use Plantiflex\FacturacionCl\Sii\Rut as RutSii;

/**
 * Validacion y normalizacion de RUT chileno, para el panel.
 *
 * YA NO IMPLEMENTA EL ALGORITMO: delega en Plantiflex\FacturacionCl\Sii\Rut,
 * que es ahora la unica copia. Este envoltorio se conserva porque el panel lo
 * llama por nombre corto en dieciseis sitios y porque vive fuera del autoload
 * PSR-4 (panel/src/ se carga con require, igual que Auth/Db/Csrf).
 *
 * ESE require VA ANTES DEL AUTOLOADER DE COMPOSER y no pasa nada: la clase de
 * src/ solo se resuelve al LLAMAR a estos metodos, no al declarar esta clase.
 * Si algun dia esto pasara a extender la otra, habria que moverlo despues del
 * autoload -- que es justo lo que documenta el require de InformePdf.
 *
 * POR QUE SE UNIFICO. El docblock anterior de esta clase ya decia "mismo
 * algoritmo que rutDvValido() en public/index.php": dos copias que habia que
 * mantener a la par. No se mantuvieron. Ninguna de las dos miraba el formato
 * que se ENVIA, y un RUT con puntos llego al XML y el SII rechazo el documento
 * -- con el folio ya gastado. La historia completa esta en el docblock de la
 * clase de src/.
 */
final class Rut
{
    /** Normaliza a MAYUSCULA sin puntos/espacios: "12.345.678-k" -> "12345678-K". */
    public static function normalizar(string $rut): string
    {
        return RutSii::normalizar($rut);
    }

    /** Valida formato NNNNNNNN-DV y digito verificador (modulo 11). Espera el RUT ya normalizado. */
    public static function valido(string $rut): bool
    {
        return RutSii::valido($rut);
    }

    /** True si el SII aceptaria este RUT en un documento. No mira el digito verificador. */
    public static function bienFormado(string $rut): bool
    {
        return RutSii::bienFormado($rut);
    }

    /**
     * Valida solo la FORMA de un RUT ya reducido a digitos+K (sin puntos,
     * espacios ni guion). NO valida el digito verificador: se usa para
     * reconocer un RUT embebido en texto libre, como el serialNumber de un
     * certificado.
     */
    public static function formaValida(string $rutSinSeparadores): bool
    {
        return RutSii::formaValida($rutSinSeparadores);
    }
}
