<?php

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Shared\Date as FechaSpreadsheet;

/**
 * Lectura de fechas desde un .xlsx de carga masiva.
 *
 * Excel guarda una fecha como un NUMERO DE SERIE (dias desde 1900), no como
 * texto. El texto que se ve en pantalla lo produce el formato de la celda, que
 * depende del locale de quien creo el archivo. Por eso una celda que mostraba
 * 2026-07-25 podia llegar al validador como "7/25/2025": no era la fecha, era
 * su representacion.
 *
 * De ahi la separacion de esta clase:
 *
 *   esCeldaDeFecha() + aIso()  -> camino EXACTO, desde el numero de serie. No
 *                                 depende del formato ni del idioma. Es el que
 *                                 usa Excel por defecto al escribir una fecha.
 *   normalizar()               -> camino TOLERANTE, para celdas que quedaron
 *                                 como texto (usuario que forzo formato texto,
 *                                 CSV convertido, copiar/pegar).
 *
 * NO reemplaza a fechaValida() del panel: esa valida ISO estricto y se usa en
 * los filtros de documentos y en los formularios de empresa, donde el dato
 * viene de un <input type="date"> y siempre es ISO.
 */
final class FechaExcel
{
    /** Formatos que acepta normalizar(), para los mensajes de error y la plantilla. */
    public const FORMATOS = 'AAAA-MM-DD, DD-MM-AAAA o DD/MM/AAAA';

    /**
     * True si la celda es una fecha real de Excel (valor numerico + formato de
     * fecha). Solo en ese caso tiene sentido convertir desde el numero de serie.
     */
    public static function esCeldaDeFecha(Cell $celda): bool
    {
        return is_numeric($celda->getValue()) && FechaSpreadsheet::isDateTime($celda);
    }

    /**
     * Convierte una celda de fecha de Excel a 'Y-m-d'. Solo llamar cuando
     * esCeldaDeFecha() dio true.
     */
    public static function aIso(Cell $celda): string
    {
        return FechaSpreadsheet::excelToDateTimeObject((float) $celda->getValue())->format('Y-m-d');
    }

    /**
     * Normaliza una fecha ESCRITA COMO TEXTO a 'Y-m-d', o null si no es valida.
     *
     * Acepta:
     *   AAAA-MM-DD / AAAA/MM/DD  -> el ano va primero, no hay ambiguedad
     *   DD-MM-AAAA / DD/MM/AAAA  -> convencion chilena
     *
     * AMBIGUEDAD, explicita: en "05/07/2026" el 05 puede ser dia o mes. Se
     * interpreta como DIA (chileno). Si el primer numero es mayor que 12 no hay
     * ambiguedad posible y se lee igual de bien. El caso que queda mal leido es
     * un texto producido por un Excel en locale ingles (MM/DD): alla
     * "05/07/2026" es 7 de mayo y aca se guardaria como 5 de julio, sin aviso.
     *
     * Ese riesgo se acota por otro lado, no aqui: las fechas escritas en celdas
     * de fecha (lo normal) no pasan por esta funcion, y la plantilla formatea
     * las dos columnas de fecha para empujar al usuario a ese camino.
     */
    public static function normalizar(string $valor): ?string
    {
        $v = trim($valor);
        if ($v === '') {
            return null;
        }

        if (preg_match('#^(\d{4})[-/](\d{1,2})[-/](\d{1,2})$#', $v, $m)) {
            [$anio, $mes, $dia] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[-/](\d{1,2})[-/](\d{4})$#', $v, $m)) {
            [$dia, $mes, $anio] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }

        if (! checkdate($mes, $dia, $anio)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
    }
}
