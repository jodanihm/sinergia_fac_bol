<?php

declare(strict_types=1);

/**
 * Validacion y normalizacion de RUT chileno (modulo 11). Mismo algoritmo que
 * rutDvValido() en public/index.php.
 */
final class Rut
{
    /** Normaliza a MAYUSCULA sin puntos/espacios: "12.345.678-k" -> "12345678-K". */
    public static function normalizar(string $rut): string
    {
        return strtoupper(str_replace(['.', ' '], '', trim($rut)));
    }

    /** Valida formato NNNNNNNN-DV y digito verificador (modulo 11). Espera el RUT ya normalizado. */
    public static function valido(string $rut): bool
    {
        if (! preg_match('/^(\d{7,8})-([\dK])$/', $rut, $m)) {
            return false;
        }
        [$num, $dv] = [$m[1], $m[2]];
        $suma = 0;
        $mul  = 2;
        for ($i = strlen($num) - 1; $i >= 0; $i--) {
            $suma += ((int) $num[$i]) * $mul;
            $mul = $mul === 7 ? 2 : $mul + 1;
        }
        $resto = 11 - ($suma % 11);
        $calc  = $resto === 11 ? '0' : ($resto === 10 ? 'K' : (string) $resto);

        return $calc === $dv;
    }

    /**
     * Valida solo la FORMA de un RUT ya reducido a digitos+K (sin puntos,
     * espacios ni guion): 7 a 9 caracteres, todos digitos salvo el ultimo que
     * puede ser digito o 'K'. NO valida el digito verificador (para eso esta
     * valido()) -- se usa para reconocer un RUT embebido en texto libre (ej.
     * el serialNumber de un certificado) sin exigir que el DV calce el
     * modulo 11, ya que ese texto puede venir con formato no estandar.
     */
    public static function formaValida(string $rutSinSeparadores): bool
    {
        return (bool) preg_match('/^\d{6,8}[0-9K]$/', $rutSinSeparadores);
    }
}
