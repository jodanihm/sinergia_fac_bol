<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Sii;

use Plantiflex\FacturacionCl\Dto\ActividadEconomicaSii;
use Plantiflex\FacturacionCl\Dto\DatosContribuyenteSii;
use RuntimeException;

/**
 * Parsea el archivo de "Datos para Construccion DTE" que entrega el SII
 * (pe_construccion_dte, texto plano ISO-8859-1): RUT, razon social,
 * direccion/comuna de la casa matriz, actividades economicas y giro.
 *
 * Mismo criterio critico que SetPruebasParser (LECCION #1 del proyecto): la
 * conversion ISO-8859-1 -> UTF-8 se hace explicita (iconv/mb_convert_encoding),
 * nunca se asume UTF-8 ni se limpian/transcriben tildes o enes -- un caracter
 * mal decodificado ya causo un rechazo real del SII en otro archivo de este
 * mismo proyecto.
 *
 * Las etiquetas se ubican por CONTENIDO (regex sobre el texto de cada
 * linea), no por posicion fija: el archivo real puede traer o no
 * sucursales, columnas de actividades en otro orden, etc. -- mismo criterio
 * de robustez que SetPruebasParser.
 *
 * El archivo NO trae fecha ni numero de Resolucion: esos 2 datos siguen
 * siendo responsabilidad manual del usuario, este parser no los resuelve ni
 * los inventa.
 *
 * Solo parsea: no construye ningun payload ni se comunica con el SII.
 */
final class DatosContribuyenteSiiParser
{
    public function parse(string $bytesIso88591): DatosContribuyenteSii
    {
        $texto  = $this->normalizarTexto($bytesIso88591);
        $lineas = explode("\n", $texto);

        $rut = $this->extraerValorSimple($lineas, '/^Rut Contribuyente:\s*$/iu');
        if ($rut === null) {
            throw new RuntimeException(
                'DatosContribuyenteSiiParser: no se encontro "Rut Contribuyente:" en el archivo; '
                . 'no corresponde al formato esperado de Datos para Construccion DTE (pe_construccion_dte).'
            );
        }

        $razonSocial = $this->extraerValorSimple($lineas, '/^Nombre o Raz.n Social:\s*$/iu');
        if ($razonSocial === null) {
            throw new RuntimeException('DatosContribuyenteSiiParser: no se encontro "Nombre o Razon Social:".');
        }

        $direccionLinea = $this->extraerValorSimple($lineas, '/^Direcci.n de la Empresa \(casa Matriz\):\s*$/iu');
        if ($direccionLinea === null) {
            throw new RuntimeException('DatosContribuyenteSiiParser: no se encontro "Direccion de la Empresa (casa Matriz):".');
        }
        [$direccion, $comuna] = $this->separarDireccionYComuna($direccionLinea);

        $giro = $this->extraerValorSimple($lineas, '/^Glosa Descriptiva:\s*$/iu');
        if ($giro === null) {
            throw new RuntimeException('DatosContribuyenteSiiParser: no se encontro "Glosa Descriptiva:".');
        }

        $actividades = $this->parsearActividadesEconomicas($lineas);
        if ($actividades === []) {
            throw new RuntimeException(
                'DatosContribuyenteSiiParser: no se encontro ninguna actividad economica valida en "Actividades Economicas:".'
            );
        }

        return new DatosContribuyenteSii(
            rut: trim($rut),
            razonSocial: trim($razonSocial),
            direccion: $direccion,
            comuna: $comuna,
            giro: trim($giro),
            actividades: $actividades,
        );
    }

    /**
     * ISO-8859-1 -> UTF-8 preservando tildes/enes exactas (identico criterio
     * a SetPruebasParser::normalizarTexto()), y normaliza fin de linea
     * CRLF/CR a LF.
     */
    private function normalizarTexto(string $bytesIso88591): string
    {
        $utf8 = @iconv('ISO-8859-1', 'UTF-8', $bytesIso88591);
        if ($utf8 === false) {
            $utf8 = mb_convert_encoding($bytesIso88591, 'UTF-8', 'ISO-8859-1');
        }

        return str_replace(["\r\n", "\r"], "\n", $utf8);
    }

    /**
     * Busca la linea que matchea $patronEtiqueta (comparada con trim()) y
     * devuelve la linea SIGUIENTE tal cual (sin trim: el llamador decide si
     * necesita preservar espacios, ej. la linea de direccion). Null si la
     * etiqueta no aparece o no hay linea siguiente.
     *
     * @param list<string> $lineas
     */
    private function extraerValorSimple(array $lineas, string $patronEtiqueta): ?string
    {
        $total = count($lineas);
        for ($i = 0; $i < $total; $i++) {
            if (preg_match($patronEtiqueta, trim($lineas[$i]))) {
                return $lineas[$i + 1] ?? null;
            }
        }

        return null;
    }

    /**
     * Separa " <direccion> , Comuna <comuna>" en sus 2 partes, tolerante a
     * espacios extra alrededor de la coma. Si no aparece el patron "Comuna"
     * (variante inesperada del formato), toda la linea queda como direccion
     * y comuna vacia -- no se inventa un valor.
     *
     * @return array{0:string,1:string}
     */
    private function separarDireccionYComuna(string $linea): array
    {
        if (preg_match('/^\s*(.+?)\s*,\s*Comuna\s+(.+?)\s*$/iu', $linea, $m)) {
            return [trim($m[1]), trim($m[2])];
        }

        return [trim($linea), ''];
    }

    /**
     * @param list<string> $lineas
     * @return list<ActividadEconomicaSii>
     */
    private function parsearActividadesEconomicas(array $lineas): array
    {
        $total  = count($lineas);
        $inicio = null;
        for ($i = 0; $i < $total; $i++) {
            if (preg_match('/^Actividades Econ.micas:\s*$/iu', trim($lineas[$i]))) {
                $inicio = $i;
                break;
            }
        }
        if ($inicio === null) {
            return [];
        }

        $cabeceraIdx = $inicio + 1;
        if (! isset($lineas[$cabeceraIdx])) {
            return [];
        }

        // Columnas resueltas por NOMBRE (no por posicion fija): el archivo
        // real podria traerlas en otro orden o con columnas adicionales.
        $columnas = array_map(
            static fn (string $c): string => strtoupper(trim($c)),
            explode(';', $lineas[$cabeceraIdx])
        );
        $idxCodigo      = array_search('CODIGO', $columnas, true);
        $idxDescripcion = array_search('DESCRIPCION', $columnas, true);
        $idxAfecto      = array_search('AFECTO A IVA', $columnas, true);
        if ($idxCodigo === false || $idxDescripcion === false) {
            return [];
        }

        $actividades = [];
        for ($i = $cabeceraIdx + 1; $i < $total; $i++) {
            if (trim($lineas[$i]) === '') {
                break; // linea en blanco: fin de la tabla.
            }

            $campos      = explode(';', $lineas[$i]);
            $codigoTxt   = trim($campos[$idxCodigo] ?? '');
            $descripcion = trim($campos[$idxDescripcion] ?? '');
            if ($codigoTxt === '' || ! ctype_digit($codigoTxt)) {
                continue; // fila no reconocida: se omite sin abortar el resto de la tabla.
            }

            $afectoTxt = $idxAfecto !== false ? strtoupper(trim($campos[$idxAfecto] ?? '')) : '';

            $actividades[] = new ActividadEconomicaSii(
                codigo: (int) $codigoTxt,
                descripcion: $descripcion,
                afectoIva: $afectoTxt === 'SI',
            );
        }

        return $actividades;
    }
}
