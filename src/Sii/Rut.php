<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Sii;

/**
 * Forma canonica de un RUT chileno: la que exige el esquema del SII.
 *
 * ------------------------------------------------------------------------
 * DE DONDE SALE ESTA CLASE: de un documento rechazado en produccion.
 *
 * El 02-09-2026 la nota de credito folio 5 volvio del SII con
 * RSC ("Rechazado por Error en Schema") y el folio ya gastado. El motivo no
 * era tributario: el RUT del receptor viajo COMO LO ESCRIBIO EL USUARIO, con
 * puntos, y SiiTypes_v10.xsd no los admite:
 *
 *     <xs:pattern value="[0-9]+-([0-9]|K)"/>   maxLength = 10
 *     <RUTRecep>78.159.082-7</RUTRecep>        -> 12 caracteres, patron roto
 *
 * El sistema tenia DOS validaciones de RUT y ninguna sirvio, porque las dos
 * miraban el DIGITO VERIFICADOR y ninguna miraba el FORMATO QUE SE ENVIA:
 * ambas quitaban los puntos para comprobar el modulo 11 y devolvian "valido",
 * dejando intacto el valor con puntos que despues acabo en el XML.
 *
 * De ahi la separacion que hace esta clase, que es toda su razon de ser:
 *
 *     normalizar()  -> devuelve el RUT COMO HAY QUE ESCRIBIRLO. No opina.
 *     bienFormado() -> dice si el SII lo va a aceptar. No mira el DV.
 *     valido()      -> dice si el RUT EXISTE (modulo 11). No transforma.
 *
 * Quien construye un documento llama a normalizar(). Quien recibe un dato del
 * usuario llama a las dos: normaliza y, si no es valido, avisa ANTES de emitir
 * -- que un folio gastado no se recupera.
 *
 * ------------------------------------------------------------------------
 * POR QUE VIVE EN src/ Y NO EN CADA CAPA
 *
 * Este algoritmo estaba escrito tres veces: Rut en panel/src (cuyo docblock ya
 * admitia "mismo algoritmo que rutDvValido() en public/index.php"),
 * rutDvValido() en el motor, y de forma implicita en cada sitio que hacia
 * str_replace('.', '') por su cuenta. Tres copias es tres oportunidades de que
 * una se arregle y las otras no -- que es exactamente lo que paso: el formato
 * se normalizaba al GUARDAR un cliente y al MOSTRARLO, pero no al EMITIR.
 *
 * Ahora las tres capas delegan aqui. panel/src/Rut.php conserva su nombre y su
 * API para no tocar sus llamadores, pero por dentro llama a esta clase.
 */
final class Rut
{
    /**
     * El patron del esquema del SII (SiiTypes_v10.xsd). Se deja explicito y
     * citado: es LA definicion de "se puede enviar", y no se deduce del
     * modulo 11.
     */
    public const PATRON_SII = '/^\d{1,8}-[\dK]$/';

    /**
     * El patron que este sistema exige a un RUT TECLEADO POR UN USUARIO: entre
     * 7 y 8 digitos.
     *
     * ES MAS ESTRICTO QUE EL DEL SII A PROPOSITO, y la diferencia esta aqui
     * escrita en vez de escondida. El esquema admite desde 1 digito; los RUT
     * chilenos por debajo del millon existen pero son rarisimos, y aceptarlos
     * en un formulario significa dejar pasar "123-4" como si fuera un cliente.
     * Es la regla que el panel viene aplicando en sus dieciseis validaciones, y
     * no se toca al arreglar el formato: son dos decisiones distintas y
     * mezclarlas es como se llego al problema que trajo esta clase.
     */
    private const PATRON_TECLEADO = '/^\d{7,8}-[\dK]$/';

    /**
     * Deja el RUT como hay que escribirlo: sin puntos ni espacios y con la K en
     * mayuscula. "78.159.082-7" -> "78159082-7", "12.345.678-k" -> "12345678-K".
     *
     * NO valida nada y NO lanza: si le entra basura, devuelve la basura
     * recortada. Es deliberado -- normalizar y validar son dos preguntas
     * distintas, y mezclarlas fue justo lo que dejo pasar el RUT con puntos.
     * Para la pregunta de si el RUT existe esta valido().
     */
    public static function normalizar(string $rut): string
    {
        return strtoupper(str_replace(['.', ' '], '', trim($rut)));
    }

    /**
     * True si el RUT se puede ENVIAR AL SII sin que el esquema lo rechace.
     *
     * Es la pregunta que faltaba. Las dos validaciones que existian miraban el
     * digito verificador quitando los puntos, asi que decian "valido" de un RUT
     * que el SII no podia aceptar. Esta no mira el DV: mira los caracteres.
     * Espera el RUT YA NORMALIZADO.
     */
    public static function bienFormado(string $rut): bool
    {
        return (bool) preg_match(self::PATRON_SII, $rut);
    }

    /**
     * True si el RUT es aceptable como dato tecleado: forma de 7-8 digitos Y
     * digito verificador correcto (modulo 11). Espera el RUT YA NORMALIZADO;
     * pasarle uno con puntos devuelve false, que es lo correcto -- con puntos
     * no se puede enviar.
     */
    public static function valido(string $rut): bool
    {
        if (! preg_match(self::PATRON_TECLEADO, $rut)) {
            return false;
        }

        [$num, $dv] = explode('-', $rut, 2);

        $suma = 0;
        $mul  = 2;
        for ($i = strlen($num) - 1; $i >= 0; $i--) {
            $suma += ((int) $num[$i]) * $mul;
            $mul   = $mul === 7 ? 2 : $mul + 1;
        }
        $resto = 11 - ($suma % 11);
        $calc  = match (true) {
            $resto === 11 => '0',
            $resto === 10 => 'K',
            default       => (string) $resto,
        };

        return $calc === $dv;
    }

    /**
     * Valida solo la FORMA de un RUT ya reducido a digitos+K (sin puntos,
     * espacios ni guion): NO valida el digito verificador. Se usa para
     * reconocer un RUT embebido en texto libre -- el serialNumber de un
     * certificado, por ejemplo --, donde el texto puede venir con formato no
     * estandar y exigir el modulo 11 descartaria certificados que funcionan.
     */
    public static function formaValida(string $rutSinSeparadores): bool
    {
        return (bool) preg_match('/^\d{6,8}[0-9K]$/', $rutSinSeparadores);
    }
}
