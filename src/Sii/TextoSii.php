<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Sii;

/**
 * Texto que se puede firmar y despues enviar al SII sin que cambie.
 *
 * ------------------------------------------------------------------------
 * DE DONDE SALE ESTA CLASE: de un documento rechazado en produccion.
 *
 * El 12-11-2026 la factura folio 43 de EASY AGENDA SPA volvio del SII con
 * RFR ("Rechazado por Error en Firma"). El certificado estaba bien (el mismo
 * firmo el folio 42, aceptado). El problema estaba en la direccion del
 * receptor, copiada de algun lado con un apostrofo TIPOGRAFICO:
 *
 *     Bernardo O’Higgins Nº580        (’ = U+2019)
 *
 * La firma se calcula sobre el C14N en UTF-8, donde ese caracter existe. Pero
 * el archivo que se sube va en ISO-8859-1, y ese juego NO tiene ’: al
 * convertir, mb_convert_encoding lo cambio por "?". El SII recibio
 * "O?Higgins", recalculo el digest sobre un texto distinto del firmado y
 * rechazo el envio. El folio quedo gastado.
 *
 * Las tildes y la ñ nunca dieron problema porque SI existen en ISO-8859-1.
 * Lo que rompe es lo que no existe ahi: comillas y apostrofos curvos, rayas,
 * puntos suspensivos, espacios raros, emojis... todo lo que mete Word o el
 * autocorrector del celular.
 *
 * La regla, entonces: TODO texto que entra a un XML para el SII pasa por
 * normalizar() ANTES de firmarse, para que lo firmado y lo enviado sean
 * exactamente lo mismo. Lo que tiene equivalente simple se reemplaza
 * (’ -> ', “ -> ", — -> -, … -> ...); lo que no lo tiene se quita.
 * ------------------------------------------------------------------------
 */
final class TextoSii
{
    /** Equivalentes simples de lo que no existe en ISO-8859-1. */
    private const REEMPLAZOS = [
        // apostrofos y comillas simples
        "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'", "\u{201B}" => "'",
        "\u{2032}" => "'", "\u{02BC}" => "'", "\u{02B9}" => "'",
        // comillas dobles
        "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"', "\u{201F}" => '"',
        "\u{2033}" => '"',
        // guiones y rayas
        "\u{2010}" => '-', "\u{2011}" => '-', "\u{2012}" => '-', "\u{2013}" => '-',
        "\u{2014}" => '-', "\u{2015}" => '-', "\u{2212}" => '-',
        // otros signos comunes al copiar de Word o de la web
        "\u{2026}" => '...', "\u{2022}" => '-', "\u{20AC}" => 'EUR', "\u{2122}" => 'TM',
        // espacios especiales -> espacio normal
        "\u{2000}" => ' ', "\u{2001}" => ' ', "\u{2002}" => ' ', "\u{2003}" => ' ',
        "\u{2004}" => ' ', "\u{2005}" => ' ', "\u{2006}" => ' ', "\u{2007}" => ' ',
        "\u{2008}" => ' ', "\u{2009}" => ' ', "\u{200A}" => ' ', "\u{202F}" => ' ',
        "\u{205F}" => ' ', "\u{3000}" => ' ',
        // invisibles -> nada
        "\u{200B}" => '', "\u{200C}" => '', "\u{200D}" => '', "\u{2060}" => '',
        "\u{FEFF}" => '',
    ];

    /**
     * Letra + acento "suelto" (U+0300..U+036F) -> letra acentuada de un solo
     * caracter. Es lo que escribe un Mac o lo que trae un texto copiado de un
     * PDF: "e" + U+0301 en vez de "é". Sin esto el acento se perderia.
     */
    private const MARCAS = [
        "\u{0301}" => ['a' => 'á', 'e' => 'é', 'i' => 'í', 'o' => 'ó', 'u' => 'ú', 'y' => 'ý',
                       'A' => 'Á', 'E' => 'É', 'I' => 'Í', 'O' => 'Ó', 'U' => 'Ú', 'Y' => 'Ý'],
        "\u{0300}" => ['a' => 'à', 'e' => 'è', 'i' => 'ì', 'o' => 'ò', 'u' => 'ù',
                       'A' => 'À', 'E' => 'È', 'I' => 'Ì', 'O' => 'Ò', 'U' => 'Ù'],
        "\u{0308}" => ['a' => 'ä', 'e' => 'ë', 'i' => 'ï', 'o' => 'ö', 'u' => 'ü', 'y' => 'ÿ',
                       'A' => 'Ä', 'E' => 'Ë', 'I' => 'Ï', 'O' => 'Ö', 'U' => 'Ü'],
        "\u{0303}" => ['n' => 'ñ', 'a' => 'ã', 'o' => 'õ', 'N' => 'Ñ', 'A' => 'Ã', 'O' => 'Õ'],
        "\u{0302}" => ['a' => 'â', 'e' => 'ê', 'i' => 'î', 'o' => 'ô', 'u' => 'û',
                       'A' => 'Â', 'E' => 'Ê', 'I' => 'Î', 'O' => 'Ô', 'U' => 'Û'],
        "\u{0327}" => ['c' => 'ç', 'C' => 'Ç'],
    ];

    /**
     * Devuelve el texto en UTF-8, pero usando SOLO caracteres que existen en
     * ISO-8859-1, para que la conversion al subirlo no cambie nada.
     */
    public static function normalizar(string $texto): string
    {
        if ($texto === '' || ! preg_match('/[^\x00-\x7F]/', $texto)) {
            return $texto; // ASCII puro: nada que hacer (el caso comun).
        }
        if (! mb_check_encoding($texto, 'UTF-8')) {
            $texto = mb_convert_encoding($texto, 'UTF-8', 'ISO-8859-1');
        }

        // 1. Acentos sueltos -> letra acentuada.
        foreach (self::MARCAS as $marca => $letras) {
            if (str_contains($texto, $marca)) {
                $texto = strtr($texto, array_combine(
                    array_map(static fn (string $l): string => $l . $marca, array_keys($letras)),
                    array_values($letras),
                ));
            }
        }

        // 2. Equivalentes simples.
        $texto = strtr($texto, self::REEMPLAZOS);

        // 3. Lo que siga fuera de ISO-8859-1 (emojis, acentos sueltos sobre
        //    otras letras, alfabetos no latinos) se quita: es eso o un "?".
        return (string) preg_replace('/[^\x{0000}-\x{00FF}]/u', '', $texto);
    }
}
