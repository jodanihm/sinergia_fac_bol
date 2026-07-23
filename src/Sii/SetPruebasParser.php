<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Sii;

use Plantiflex\FacturacionCl\Dto\CasoLibroComprasSetPruebas;
use Plantiflex\FacturacionCl\Dto\CasoSetBasico;
use Plantiflex\FacturacionCl\Dto\ItemCasoSetPruebas;
use Plantiflex\FacturacionCl\Dto\SetPruebasParseado;
use RuntimeException;

/**
 * Parsea el archivo SIISetDePruebas<RUT>.txt que entrega el SII (texto plano
 * ISO-8859-1, columnas separadas por tabs) a una estructura tipada.
 *
 * El punto mas critico es la conversion de codificacion: un caracter mal
 * decodificado (ej. "Cajon" en vez de "Cajon" con tilde) ya provoco un
 * rechazo real del SII por contenido no coincidente. Por eso la conversion
 * ISO-8859-1 -> UTF-8 se hace explicitamente con iconv/mb_convert_encoding,
 * nunca se asume que el archivo ya viene en UTF-8.
 *
 * Los casos especiales del Libro de Compras (IVA uso comun, entrega
 * gratuita, retencion total del IVA, descuento a documento referenciado) se
 * detectan por coincidencia de texto en la observacion del propio archivo,
 * sin inventar reglas nuevas.
 *
 * Solo parsea: no construye ningun payload de emision ni se comunica con el SII.
 */
final class SetPruebasParser
{
    public function parse(string $bytesIso88591): SetPruebasParseado
    {
        $texto = $this->normalizarTexto($bytesIso88591);
        $lineas = explode("\n", $texto);

        $advertencias = [];

        $numeroAtencionSetBasico = $this->extraerNumeroAtencion($texto, 'SET BASICO');
        if ($numeroAtencionSetBasico === null) {
            throw new RuntimeException(
                'SetPruebasParser: no se encontro "SET BASICO - NUMERO DE ATENCION" en el archivo; '
                . 'no corresponde al formato de SIISetDePruebas<RUT>.txt esperado.'
            );
        }
        $numeroAtencionLibroVentas = $this->extraerNumeroAtencion($texto, 'SET LIBRO DE VENTAS');
        $numeroAtencionLibroCompras = $this->extraerNumeroAtencion($texto, 'SET LIBRO DE COMPRAS');

        $casos = $this->parsearCasosSetBasico($lineas, $advertencias);
        $casosLibroCompras = $this->parsearLibroCompras($lineas, $advertencias);
        $factorProporcionalidad = $this->extraerFactorProporcionalidad($texto);

        return new SetPruebasParseado(
            numeroAtencionSetBasico: $numeroAtencionSetBasico,
            numeroAtencionLibroVentas: $numeroAtencionLibroVentas,
            numeroAtencionLibroCompras: $numeroAtencionLibroCompras,
            casos: $casos,
            casosLibroCompras: $casosLibroCompras,
            factorProporcionalidadIvaUsoComun: $factorProporcionalidad,
            advertencias: $advertencias,
        );
    }

    /**
     * ISO-8859-1 -> UTF-8 preservando tildes/enes exactas, y normaliza fin de
     * linea CRLF/CR a LF (el archivo real del SII viene con \r\n).
     */
    private function normalizarTexto(string $bytesIso88591): string
    {
        $utf8 = iconv('ISO-8859-1', 'UTF-8', $bytesIso88591);
        if ($utf8 === false) {
            $utf8 = mb_convert_encoding($bytesIso88591, 'UTF-8', 'ISO-8859-1');
        }

        return str_replace(["\r\n", "\r"], "\n", $utf8);
    }

    private function extraerNumeroAtencion(string $texto, string $etiqueta): ?int
    {
        $patron = '/' . preg_quote($etiqueta, '/') . '\s*-\s*NUMERO DE ATENCION:\s*(\d+)/i';
        if (preg_match($patron, $texto, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    private function extraerFactorProporcionalidad(string $texto): ?float
    {
        // La frase viene partida en dos lineas fisicas en el archivo real
        // ("...FACTOR DE PROPORCIONALIDAD" \n "DEL IVA ES DE 0.60"); al
        // colapsar espacios en blanco (incluye \n) queda en una sola linea.
        $colapsado = preg_replace('/\s+/', ' ', $texto) ?? $texto;
        if (preg_match('/FACTOR\s+DE\s+PROPORCIONALIDAD.*?ES\s+DE\s+(\d+(?:[.,]\d+)?)/i', $colapsado, $m)) {
            return (float) str_replace(',', '.', $m[1]);
        }

        return null;
    }

    /**
     * @param list<string> $lineas
     * @return int|null indice (0-based) de la primera linea que matchea $regex desde $desde, o null.
     */
    private function indiceLinea(array $lineas, string $regex, int $desde = 0): ?int
    {
        $total = count($lineas);
        for ($i = $desde; $i < $total; $i++) {
            if (preg_match($regex, trim($lineas[$i]))) {
                return $i;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function dividirPorTabs(string $linea): array
    {
        return array_map('trim', preg_split('/\t+/', $linea) ?: [$linea]);
    }

    private function tipoDocumentoDesde(string $texto): ?string
    {
        $t = strtoupper(trim($texto));
        if (str_contains($t, 'NOTA DE CREDITO')) {
            return 'NOTA_CREDITO';
        }
        if (str_contains($t, 'NOTA DE DEBITO')) {
            return 'NOTA_DEBITO';
        }
        if (str_contains($t, 'FACTURA')) {
            return 'FACTURA';
        }

        return null;
    }

    /**
     * @param list<string> $lineas
     * @param list<string> $advertencias
     * @return list<CasoSetBasico>
     */
    private function parsearCasosSetBasico(array $lineas, array &$advertencias): array
    {
        $inicio = $this->indiceLinea($lineas, '/^SET BASICO\b/i');
        if ($inicio === null) {
            return [];
        }

        $finExclusivo = $this->indiceLinea($lineas, '/^SET LIBRO DE VENTAS\b/i', $inicio + 1)
            ?? $this->indiceLinea($lineas, '/^SET LIBRO DE COMPRAS\b/i', $inicio + 1)
            ?? count($lineas);

        $indicesCaso = [];
        for ($i = $inicio; $i < $finExclusivo; $i++) {
            if (preg_match('/^CASO\s+\d+-\d+\s*$/i', trim($lineas[$i]))) {
                $indicesCaso[] = $i;
            }
        }

        $casos = [];
        foreach ($indicesCaso as $idx => $inicioCaso) {
            $finCaso = $indicesCaso[$idx + 1] ?? $finExclusivo;
            $bloque = array_slice($lineas, $inicioCaso, $finCaso - $inicioCaso);
            $caso = $this->parsearCaso($bloque, $inicioCaso + 1, $advertencias);
            if ($caso !== null) {
                $casos[] = $caso;
            }
        }

        return $casos;
    }

    /**
     * @param list<string> $lineas bloque completo del caso, empezando en la linea "CASO n-m"
     * @param int $primeraLineaAbs numero de linea (1-based) de $lineas[0] en el archivo original
     * @param list<string> $advertencias
     */
    private function parsearCaso(array $lineas, int $primeraLineaAbs, array &$advertencias): ?CasoSetBasico
    {
        if (!preg_match('/^CASO\s+\d+-(\d+)\s*$/i', trim($lineas[0]), $mc)) {
            $advertencias[] = "Linea {$primeraLineaAbs}: no se pudo interpretar encabezado de caso: '{$lineas[0]}'";
            return null;
        }
        $numeroCaso = (int) $mc[1];

        $tipoDocumento = null;
        $referenciaCaso = null;
        $razonReferencia = null;
        $items = [];
        $descuentoGlobalPct = null;

        $n = count($lineas);
        $i = 1; // saltar la linea "CASO n-m"
        while ($i < $n) {
            $lineaAbs = $primeraLineaAbs + $i;
            $t = trim($lineas[$i]);
            if ($t === '' || preg_match('/^[=-]+$/', $t)) {
                $i++;
                continue;
            }

            $campos = $this->dividirPorTabs($lineas[$i]);
            $etiqueta = strtoupper(trim($campos[0] ?? ''));

            if ($etiqueta === 'DOCUMENTO') {
                $tipoDocumento = $this->tipoDocumentoDesde($campos[1] ?? '');
                if ($tipoDocumento === null) {
                    $advertencias[] = "Linea {$lineaAbs}: tipo de documento no reconocido: '" . ($campos[1] ?? '') . "'";
                }
                $i++;
                continue;
            }

            if ($etiqueta === 'REFERENCIA') {
                $textoRef = $campos[1] ?? '';
                if (preg_match('/CASO\s+\d+-(\d+)/i', $textoRef, $mr)) {
                    $referenciaCaso = (int) $mr[1];
                } else {
                    $advertencias[] = "Linea {$lineaAbs}: no se pudo extraer el caso referenciado de: '{$textoRef}'";
                }
                $i++;
                continue;
            }

            if ($etiqueta === 'RAZON REFERENCIA') {
                $razonReferencia = ($campos[1] ?? '') !== '' ? $campos[1] : null;
                $i++;
                continue;
            }

            if ($etiqueta === 'ITEM') {
                $columnas = array_map(static fn (string $c): string => strtoupper(trim($c)), $campos);
                $i++;
                while ($i < $n) {
                    $filaTrim = trim($lineas[$i]);
                    if ($filaTrim === '' || preg_match('/^DESCUENTO GLOBAL/i', $filaTrim)) {
                        break;
                    }
                    $item = $this->parsearItem($columnas, $this->dividirPorTabs($lineas[$i]), $primeraLineaAbs + $i, $advertencias);
                    if ($item !== null) {
                        $items[] = $item;
                    }
                    $i++;
                }
                continue;
            }

            if ($etiqueta === 'DESCUENTO GLOBAL ITEMES AFECTOS') {
                $pctTxt = $campos[1] ?? '';
                if (preg_match('/(\d+)/', $pctTxt, $mp)) {
                    $descuentoGlobalPct = (int) $mp[1];
                } else {
                    $advertencias[] = "Linea {$lineaAbs}: no se pudo extraer el porcentaje de descuento global: '{$pctTxt}'";
                }
                $i++;
                continue;
            }

            $advertencias[] = "Linea {$lineaAbs}: contenido no reconocido dentro de CASO {$numeroCaso}: '{$t}'";
            $i++;
        }

        if ($tipoDocumento === null) {
            $advertencias[] = "Caso {$numeroCaso} (linea {$primeraLineaAbs}): no se encontro DOCUMENTO; caso omitido.";
            return null;
        }

        return new CasoSetBasico(
            numeroCaso: $numeroCaso,
            tipoDocumento: $tipoDocumento,
            referenciaCaso: $referenciaCaso,
            razonReferencia: $razonReferencia,
            items: $items,
            descuentoGlobalPct: $descuentoGlobalPct,
        );
    }

    /**
     * @param list<string> $columnas cabecera de la tabla de items en mayusculas (ej. ['ITEM','CANTIDAD','PRECIO UNITARIO'])
     * @param list<string> $campos
     * @param list<string> $advertencias
     */
    private function parsearItem(array $columnas, array $campos, int $lineaAbs, array &$advertencias): ?ItemCasoSetPruebas
    {
        $nombre = trim($campos[0] ?? '');
        if ($nombre === '') {
            $advertencias[] = "Linea {$lineaAbs}: fila de item vacia, omitida.";
            return null;
        }

        $cantidadIdx = array_search('CANTIDAD', $columnas, true);
        $precioIdx = array_search('PRECIO UNITARIO', $columnas, true);
        $descIdx = array_search('DESCUENTO ITEM', $columnas, true);

        $cantidadTxt = $cantidadIdx !== false ? ($campos[$cantidadIdx] ?? '') : '';
        if (trim($cantidadTxt) === '') {
            $advertencias[] = "Linea {$lineaAbs}: no se pudo leer la cantidad del item '{$nombre}'.";
            return null;
        }
        $cantidad = (int) trim($cantidadTxt);

        $precioUnitario = null;
        if ($precioIdx !== false && trim($campos[$precioIdx] ?? '') !== '') {
            $precioUnitario = (int) trim($campos[$precioIdx]);
        }

        $descuentoPorcentaje = null;
        if ($descIdx !== false && trim($campos[$descIdx] ?? '') !== '' && preg_match('/(\d+)/', $campos[$descIdx], $md)) {
            $descuentoPorcentaje = (int) $md[1];
        }

        return new ItemCasoSetPruebas(
            nombre: $nombre,
            cantidad: $cantidad,
            precioUnitario: $precioUnitario,
            descuentoPorcentaje: $descuentoPorcentaje,
            exento: str_contains(strtoupper($nombre), 'EXENTO'),
        );
    }

    /**
     * @param list<string> $lineas
     * @param list<string> $advertencias
     * @return list<CasoLibroComprasSetPruebas>
     */
    private function parsearLibroCompras(array $lineas, array &$advertencias): array
    {
        $inicio = $this->indiceLinea($lineas, '/^SET LIBRO DE COMPRAS\b/i');
        if ($inicio === null) {
            return [];
        }

        $total = count($lineas);
        $delims = [];
        for ($i = $inicio; $i < $total; $i++) {
            if (preg_match('/^=+$/', trim($lineas[$i]))) {
                $delims[] = $i;
                if (count($delims) === 3) {
                    break;
                }
            }
        }
        if (count($delims) < 3) {
            $advertencias[] = 'No se encontraron los 3 separadores "====" esperados en SET LIBRO DE COMPRAS; '
                . 'no se pudo parsear la tabla de documentos.';
            return [];
        }
        [, $inicioDatos, $finDatos] = $delims;

        $casos = [];
        $i = $inicioDatos + 1;
        while ($i < $finDatos) {
            while ($i < $finDatos && trim($lineas[$i]) === '') {
                $i++;
            }
            if ($i >= $finDatos) {
                break;
            }

            $inicioRegistro = $i;
            $registro = [];
            while ($i < $finDatos && trim($lineas[$i]) !== '') {
                $registro[] = $lineas[$i];
                $i++;
            }

            if (count($registro) !== 3) {
                $advertencias[] = 'Linea ' . ($inicioRegistro + 1) . ': registro del Libro de Compras con '
                    . count($registro) . ' lineas (se esperaban 3), omitido.';
                continue;
            }

            $caso = $this->parsearCasoLibroCompras($registro, $inicioRegistro + 1, $advertencias);
            if ($caso !== null) {
                $casos[] = $caso;
            }
        }

        return $casos;
    }

    /**
     * @param list<string> $registro exactamente 3 lineas: [tipo+folio, observacion, montos]
     * @param list<string> $advertencias
     */
    private function parsearCasoLibroCompras(array $registro, int $lineaAbs, array &$advertencias): ?CasoLibroComprasSetPruebas
    {
        $camposTipoFolio = $this->dividirPorTabs($registro[0]);
        if (count($camposTipoFolio) !== 2) {
            $advertencias[] = "Linea {$lineaAbs}: no se pudo separar tipo de documento y folio en '{$registro[0]}'.";
            return null;
        }
        [$tipoTxt, $folioTxt] = $camposTipoFolio;
        if (!preg_match('/(\d+)/', $folioTxt, $mf)) {
            $advertencias[] = "Linea {$lineaAbs}: folio no numerico: '{$folioTxt}'.";
            return null;
        }
        $folio = (int) $mf[1];

        $observacion = trim($registro[1]);

        $camposMonto = $this->dividirPorTabs($registro[2]);
        $montoExento = 0;
        $montoAfecto = 0;
        if (count($camposMonto) === 2) {
            $montoExento = $camposMonto[0] !== '' ? (int) $camposMonto[0] : 0;
            $montoAfecto = (int) $camposMonto[1];
        } elseif (count($camposMonto) === 1 && $camposMonto[0] !== '') {
            $montoAfecto = (int) $camposMonto[0];
        } else {
            $advertencias[] = 'Linea ' . ($lineaAbs + 2) . ": no se pudieron leer los montos: '{$registro[2]}'.";
        }

        $obsMayus = strtoupper($observacion);
        $folioReferenciado = null;
        if (preg_match('/DESCUENTO\s+(?:A\s+)?FACTURA(?:\s+ELECTRONICA)?\s+(\d+)/i', $observacion, $mref)) {
            $folioReferenciado = (int) $mref[1];
        }

        return new CasoLibroComprasSetPruebas(
            tipoDocumentoTexto: trim($tipoTxt),
            folio: $folio,
            observacion: $observacion,
            montoExento: $montoExento,
            montoAfecto: $montoAfecto,
            ivaUsoComun: str_contains($obsMayus, 'IVA USO COMUN'),
            ivaNoRecuperable: str_contains($obsMayus, 'ENTREGA GRATUITA'),
            retencionTotalIva: str_contains($obsMayus, 'RETENCION TOTAL'),
            folioReferenciado: $folioReferenciado,
        );
    }
}
