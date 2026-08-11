<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Pdf;

/**
 * Representacion impresa de una COTIZACION.
 *
 * =============================================================================
 * CLASE HERMANA, NO UN FORK DE DtePdfDocumento
 * =============================================================================
 *
 * Extiende \sasco\LibreDTE\PDF directamente, igual que BoletaPdfDocumento, que
 * es el precedente de la casa: cuando hizo falta un segundo formato no se forzo
 * el primero, se escribio una clase con su propia geometria.
 *
 * Y aqui forzar DtePdfDocumento no era una opcion siquiera: su agregar() recibe
 * un arreglo con forma de DTE (Encabezado.Emisor, Encabezado.IdDoc.TipoDTE,
 * Encabezado.Totales, Detalle...) y TODOS sus metodos de dibujo son private, asi
 * que o se le arma un DTE falso o no se usa.
 *
 * LO QUE NO LLEVA, porque una cotizacion NO pasa por el SII:
 *   - timbre PDF417 (no hay TED)
 *   - acuse de recibo y leyenda de destino (no es cedible)
 *   - el recuadro rojo del folio del SII ni la unidad del SII
 *   - resolucion que autoriza a emitir
 *
 * LO QUE SI REUSA:
 *   - las primitivas del padre: Texto(), MultiTexto(), addTable()
 *   - LogoEmpresa, que es independiente del DTE
 *   - POR COPIA (no por herencia, porque son private en la otra clase): la
 *     cascada de tamaño de la razon social y el criterio
 *     setY(max(PISO, fin del emisor)) del bloque que fluye.
 *
 * =============================================================================
 * LA TABLA LARGA: AQUI SE PAGINA, Y ES MAS SIMPLE QUE EN EL DTE
 * =============================================================================
 *
 * En el DTE una tabla larga se dibuja ENCIMA del acuse de recibo y del timbre, y
 * esta anotado como defecto sin arreglar en DtePdfDocumento::agregarDetalle().
 * El motivo es que ahi el timbre y el acuse se dibujan en coordenadas FIJAS
 * (y=190) DESPUES de la tabla: no hay forma de que la tabla los respete.
 *
 * En una cotizacion no hay nada clavado. Nada se dibuja en una coordenada fija
 * despues del detalle, asi que el salto de pagina automatico de TCPDF basta:
 * la tabla fluye, los totales se dibujan donde termine y las notas despues.
 * Una cotizacion de 40 lineas simplemente ocupa dos paginas.
 *
 * Y LA CABECERA DE LA TABLA SE REPITE SOLA, pero solo si se le da el ancho de
 * TODAS las columnas: PDF::addTable() emite <thead> unicamente cuando
 * count($options['width']) == count($headers) (lib/PDF.php:180), y writeHTML()
 * de TCPDF repite el <thead> en cada salto. Por eso ANCHOS tiene una entrada por
 * columna y ninguna en 0 -- que es justo lo que el DTE no puede hacer, porque su
 * columna "Item" va en width 0 y ademas usa addTableWithoutEmptyCols(), que
 * elimina columnas y descuadra ese conteo.
 *
 * NO SE ACOTA EL NUMERO DE LINEAS. Truncar un documento comercial seria peor que
 * dar vuelta la hoja.
 */
final class CotizacionPdfDocumento extends \sasco\LibreDTE\PDF
{
    /** Piso del bloque de datos, igual criterio que el DTE (ver setY mas abajo). */
    private const PISO_BLOQUE = 50;

    /** Ancho del logo. Se toma de LogoEmpresa para que no se desincronice. */
    private const LOGO_MM = LogoEmpresa::ANCHO_DIBUJO_MM;

    /** Ancho de la razon social cuando hay logo. Ver tamanoRazonSocial(). */
    private const RZN_SOC_ANCHO_CON_LOGO = 65;
    private const RZN_SOC_ANCHO_SIN_LOGO = 115;

    /** Copiado de DtePdfDocumento: mismo argumento normativo, mismo piso. */
    private const RZN_SOC_TAMANOS = [20, 18, 16, 14];
    private const RZN_SOC_MAX_LINEAS = 2;

    /**
     * Anchos de la tabla, UNO POR COLUMNA Y NINGUNO EN 0.
     * Es lo que hace que addTable() emita <thead> y TCPDF repita la cabecera en
     * cada pagina. Suman 185 mm, que es el ancho util entre x=10 y x=195.
     */
    private const ANCHOS = [70, 14, 16, 24, 18, 24, 19];

    private ?string $logo = null;

    public function __construct()
    {
        parent::__construct();
        $this->SetTitle('Cotizacion');
    }

    /** @param string|null $logo Lo que devuelve LogoEmpresa::paraTcpdf(), o null. */
    public function setLogo(?string $logo): void
    {
        $this->logo = $logo;
    }

    /**
     * @param array<string,mixed> $emisor    RznSoc, GiroEmis, DirOrigen, CmnaOrigen, RUTEmisor, Telefono?, CorreoEmisor?
     * @param array<string,mixed> $cotizacion numero, fecha, valida_hasta, receptor_*, notas
     * @param list<array<string,mixed>> $lineas nombre, descripcion, unidad, cantidad, precio_unitario, descuento_pct, exento
     */
    public function agregar(array $emisor, array $cotizacion, array $lineas): void
    {
        $this->AddPage();

        $this->agregarEmisor($emisor);
        $finEmisor = $this->getY();

        $this->agregarTitulo($cotizacion);

        // MISMO CRITERIO QUE EL DTE: el bloque de abajo FLUYE. Un piso fijo se
        // monta en cuanto la razon social del emisor ocupa varias lineas, y eso
        // no depende del logo sino de los datos.
        $this->setY(max(self::PISO_BLOQUE, $finEmisor));

        $this->agregarDatos($cotizacion);
        $this->agregarReceptor($cotizacion);
        $this->agregarDetalle($lineas);
        $this->agregarTotales($lineas);
        $this->agregarNotas($cotizacion);
    }

    // =======================================================================
    //  CABECERA
    // =======================================================================

    /**
     * El nombre al lado del logo; el giro, la direccion y el contacto a todo el
     * ancho por debajo. Copiado del criterio de DtePdfDocumento::agregarEmisor()
     * despues de la entrega que lo bajo -- incluido el getImageRBY(), que es lo
     * que impide que un logo alto se coma el giro.
     */
    private function agregarEmisor(array $emisor, float $x = 10, float $y = 10): void
    {
        $xCompleto = $x;
        $wCompleto = self::RZN_SOC_ANCHO_SIN_LOGO;
        $xNombre   = $x;
        $wNombre   = $wCompleto;
        $yBajoLogo = null;

        if ($this->logo !== null && $this->logo !== '') {
            $this->Image($this->logo, $x, $y, self::LOGO_MM, 0, 'PNG', '', 'T');
            $xNombre = $this->x + 3;
            $wNombre = self::RZN_SOC_ANCHO_CON_LOGO;
            // El fondo REAL de la imagen, no una constante: Image() recibe h=0 y
            // TCPDF calcula el alto del PNG concreto.
            $yBajoLogo = $this->getImageRBY() + 2;
        } else {
            $this->y = $y - 2;
        }

        $this->setFont('', 'B', $this->tamanoRazonSocial((string) ($emisor['RznSoc'] ?? ''), $wNombre));
        $this->SetTextColorArray([32, 92, 144]);
        $this->MultiTexto((string) ($emisor['RznSoc'] ?? ''), $xNombre, $this->y + 2, 'L', $wNombre);

        $y2 = $yBajoLogo !== null ? max($this->y, $yBajoLogo) : $this->y;

        $this->setFont('', 'B', 9);
        $this->SetTextColorArray([0, 0, 0]);
        $this->MultiTexto((string) ($emisor['GiroEmis'] ?? ''), $xCompleto, $y2, 'L', $wCompleto);
        $this->MultiTexto(
            trim((string) ($emisor['DirOrigen'] ?? '') . ', ' . (string) ($emisor['CmnaOrigen'] ?? ''), ', '),
            $xCompleto,
            $this->y,
            'L',
            $wCompleto
        );

        $contacto = [];
        if (! empty($emisor['Telefono'])) {
            // is_array() y no isset($x[0]): en un string, $s[0] es su primer
            // caracter y la guarda no dispararia nunca. Mismo defecto que se
            // arreglo en DtePdfDocumento.
            $telefonos = is_array($emisor['Telefono']) ? $emisor['Telefono'] : [$emisor['Telefono']];
            foreach ($telefonos as $t) {
                $contacto[] = $t;
            }
        }
        if (! empty($emisor['CorreoEmisor'])) {
            $contacto[] = $emisor['CorreoEmisor'];
        }
        if ($contacto !== []) {
            $this->MultiTexto(implode(' / ', $contacto), $xCompleto, $this->y, 'L', $wCompleto);
        }
        if (! empty($emisor['RUTEmisor'])) {
            $this->MultiTexto('R.U.T.: ' . $emisor['RUTEmisor'], $xCompleto, $this->y, 'L', $wCompleto);
        }
    }

    /**
     * El recuadro del numero. Ocupa el sitio del recuadro rojo del SII pero NO
     * lo imita: sin RUT del emisor dentro, sin unidad del SII debajo, y en gris
     * -- el rojo del recuadro del SII identifica un documento tributario y una
     * cotizacion no lo es. Confundirlos seria un problema, no un detalle.
     */
    private function agregarTitulo(array $cotizacion, float $x = 130, float $y = 10, float $w = 65): void
    {
        $this->SetTextColorArray([60, 60, 60]);
        $this->Rect($x, $y, $w, 22, 'D', ['all' => ['width' => 0.4, 'color' => [60, 60, 60]]]);
        $this->setFont('', 'B', 13);
        $this->Texto('COTIZACION', $x, $y + 4, 'C', $w);
        $this->setFont('', 'B', 16);
        $this->Texto('N° ' . (int) ($cotizacion['numero'] ?? 0), $x, $y + 12, 'C', $w);
        $this->SetTextColorArray([0, 0, 0]);
        $this->setFont('', '', 9);
    }

    private function agregarDatos(array $cotizacion, float $x = 10): void
    {
        $this->setFont('', '', 9);
        $this->Texto('Fecha', $x);
        $this->Texto(':', $x + 22);
        $this->MultiTexto($this->fechaLarga((string) ($cotizacion['fecha'] ?? '')), $x + 26);

        if (! empty($cotizacion['valida_hasta'])) {
            $this->Texto('Valida hasta', $x);
            $this->Texto(':', $x + 22);
            $this->MultiTexto($this->fechaCorta((string) $cotizacion['valida_hasta']), $x + 26);
        }
    }

    private function agregarReceptor(array $c, float $x = 10): void
    {
        $this->Ln();
        $this->setFont('', 'B', 9);
        $this->Texto('Señor(es)', $x);
        $this->setFont('', '', 9);
        $this->Texto(':', $x + 22);
        $this->MultiTexto((string) ($c['receptor_razon_social'] ?? ''), $x + 26, null, 'L', 100);

        $this->Texto('R.U.T.', $x);
        $this->Texto(':', $x + 22);
        $this->MultiTexto((string) ($c['receptor_rut'] ?? ''), $x + 26);

        foreach ([
            'Giro'      => $c['receptor_giro'] ?? '',
            'Direccion' => trim((string) ($c['receptor_direccion'] ?? '') . ', ' . (string) ($c['receptor_comuna'] ?? ''), ', '),
        ] as $etiqueta => $valor) {
            if (trim((string) $valor) === '') {
                continue;
            }
            $this->Texto($etiqueta, $x);
            $this->Texto(':', $x + 22);
            $this->MultiTexto((string) $valor, $x + 26, null, 'L', 150);
        }
    }

    // =======================================================================
    //  DETALLE Y TOTALES
    // =======================================================================

    /** @param list<array<string,mixed>> $lineas */
    private function agregarDetalle(array $lineas, float $x = 10): void
    {
        $titulos = ['Item', 'Cant.', 'Unidad', 'P. unitario', 'Desc.', 'Neto', 'IVA'];

        $filas = [];
        foreach ($lineas as $l) {
            $nombre = (string) $l['nombre'];
            if (trim((string) ($l['descripcion'] ?? '')) !== '') {
                $nombre .= '<br/><span style="font-size:0.7em">'
                    . htmlspecialchars((string) $l['descripcion']) . '</span>';
            }
            $filas[] = [
                $nombre,
                $this->cantidad((float) $l['cantidad']),
                (string) ($l['unidad'] ?? ''),
                $this->num($this->neto($l) / max(1e-9, (float) $l['cantidad'])),
                ((float) ($l['descuento_pct'] ?? 0)) > 0 ? $this->num((float) $l['descuento_pct']) . '%' : '',
                $this->num($this->neto($l)),
                ! empty($l['exento']) ? 'Exento' : 'Afecto',
            ];
        }

        $this->Ln();
        $this->SetX($x);
        $this->setFont('', '', 8);
        // addTable() y no addTableWithoutEmptyCols(): quitar columnas vacias
        // descuadraria el conteo de anchos y con eso se perderia el <thead>, o
        // sea la cabecera repetida en la segunda pagina.
        $this->addTable($titulos, $filas, ['width' => self::ANCHOS, 'align' => [
            'left', 'right', 'left', 'right', 'right', 'right', 'center',
        ]]);
    }

    /** @param list<array<string,mixed>> $lineas */
    private function agregarTotales(array $lineas, float $x = 130, float $w = 65): void
    {
        $neto = 0.0;
        $exento = 0.0;
        foreach ($lineas as $l) {
            if (! empty($l['exento'])) {
                $exento += $this->neto($l);
            } else {
                $neto += $this->neto($l);
            }
        }
        // 19% fijo, igual que DteXmlBuilder::TASA_IVA. Una cotizacion no emite
        // nada al SII, pero el numero que ve el cliente tiene que ser el mismo
        // que le va a llegar en la factura.
        $iva   = round($neto * 0.19);
        $total = round($neto) + round($exento) + $iva;

        $this->Ln();
        $this->setFont('', '', 9);
        foreach ([
            'Neto'   => $neto > 0 ? round($neto) : null,
            'Exento' => $exento > 0 ? round($exento) : null,
            'IVA 19%' => $neto > 0 ? $iva : null,
        ] as $etiqueta => $valor) {
            if ($valor === null) {
                continue;
            }
            $this->Texto($etiqueta, $x, null, 'R', $w - 25);
            $this->MultiTexto('$ ' . $this->num($valor), $x + $w - 25, $this->getY(), 'R', 25);
        }
        $this->setFont('', 'B', 10);
        $this->Texto('TOTAL', $x, null, 'R', $w - 25);
        $this->MultiTexto('$ ' . $this->num($total), $x + $w - 25, $this->getY(), 'R', 25);
        $this->setFont('', '', 9);
    }

    private function agregarNotas(array $cotizacion, float $x = 10): void
    {
        if (trim((string) ($cotizacion['notas'] ?? '')) === '') {
            return;
        }
        $this->Ln();
        $this->setFont('', 'B', 9);
        $this->MultiTexto('Observaciones', $x, null, 'L', 185);
        $this->setFont('', '', 9);
        $this->MultiTexto((string) $cotizacion['notas'], $x, $this->getY(), 'L', 185);
    }

    // =======================================================================
    //  AUXILIARES
    // =======================================================================

    /** Neto de la linea, con su descuento por linea ya aplicado. */
    private function neto(array $l): float
    {
        $bruto = (float) $l['cantidad'] * (float) $l['precio_unitario'];

        return $bruto * (1 - ((float) ($l['descuento_pct'] ?? 0)) / 100);
    }

    /**
     * Cantidad CON decimales cuando los tiene y sin ellos cuando no.
     *
     * El saldo admite decimales (media hora de servicio es legitimo), asi que
     * "1" no puede imprimirse como "1,0000" ni "0,5" como "1".
     */
    private function cantidad(float $c): string
    {
        return abs($c - round($c)) < 0.00005
            ? number_format($c, 0, ',', '.')
            : rtrim(rtrim(number_format($c, 4, ',', '.'), '0'), ',');
    }

    private function num($n): string
    {
        return number_format((float) $n, 0, ',', '.');
    }

    private function fechaCorta(string $f): string
    {
        $t = strtotime($f);

        return $t !== false ? date('d-m-Y', $t) : $f;
    }

    private function fechaLarga(string $f): string
    {
        $t = strtotime($f);
        if ($t === false) {
            return $f;
        }
        $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
                  'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        return date('j', $t) . ' de ' . $meses[(int) date('n', $t) - 1] . ' del ' . date('Y', $t);
    }

    /**
     * El MAYOR tamaño que entre en el maximo de lineas, con piso en el ultimo.
     *
     * COPIADO de DtePdfDocumento::tamanoRazonSocial() y no heredado, porque alli
     * es private. Se mantiene identico a proposito -- incluido el piso de 14 pt,
     * cuyo argumento es normativo y vale igual aqui: la razon social tiene que
     * leerse destacada frente al giro, que va a 9 pt (14/9 = 1,56x).
     *
     * setFont(..., $out: false) fija la fuente SIN emitir el operador Tf, que es
     * el mecanismo que usa el propio TCPDF en GetArrStringWidth(). Medir con
     * setFont() normal dejaria un Tf por medicion en el flujo.
     */
    private function tamanoRazonSocial(string $texto, float $w): int
    {
        $previo  = [$this->FontFamily, $this->FontStyle, $this->FontSizePt];
        $elegido = null;

        foreach (self::RZN_SOC_TAMANOS as $pt) {
            $this->setFont($this->FontFamily, 'B', $pt, '', 'default', false);
            if ($this->getNumLines($texto, $w) <= self::RZN_SOC_MAX_LINEAS) {
                $elegido = $pt;
                break;
            }
        }

        $this->setFont($previo[0], $previo[1], $previo[2], '', 'default', false);

        return $elegido ?? self::RZN_SOC_TAMANOS[count(self::RZN_SOC_TAMANOS) - 1];
    }
}
