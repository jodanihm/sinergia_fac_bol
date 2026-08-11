<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Pdf;

/**
 * Representacion impresa de una ORDEN DE COMPRA.
 *
 * =============================================================================
 * CUARTA CLASE HERMANA. SE COPIA CotizacionPdfDocumento Y SE SABE.
 * =============================================================================
 *
 * Extiende \sasco\LibreDTE\PDF directamente, como DtePdfDocumento,
 * BoletaPdfDocumento y CotizacionPdfDocumento.
 *
 * ESTA ES LA PRIMERA VEZ QUE SE COPIA ALGO CASI IDENTICO, y conviene dejarlo
 * escrito en vez de descubrirlo dentro de seis meses. Boleta y DTE son
 * documentos distintos de verdad (58 mm contra A4, timbre contra nada). Una
 * orden de compra y una cotizacion, en cambio, son el MISMO documento comercial
 * con otro titulo y otra contraparte: cabecera con logo, cascada de razon
 * social, tabla paginada, totales, notas.
 *
 * LA ALTERNATIVA ERA PARAMETRIZAR CotizacionPdfDocumento -- titulo, etiqueta de
 * la contraparte, si muestra vigencia -- y que las dos la usaran. Es lo correcto
 * estructuralmente y habia red de seguridad medible (el md5 del flujo
 * descomprimido demuestra que la cotizacion sale byte a byte igual). SE
 * DESCARTO A PROPOSITO: esa clase esta en produccion y tocarla para agregar un
 * documento nuevo pone en riesgo uno que ya funciona. Riesgo cero sobre lo
 * desplegado le gano a la elegancia.
 *
 * SI ALGUN DIA APARECE UN QUINTO documento comercial, esa decision hay que
 * revisarla: tres copias de la misma cabecera ya no se defienden con "riesgo
 * cero".
 *
 * QUE NO LLEVA, porque no es un DTE: timbre PDF417, acuse de recibo, leyenda de
 * destino, recuadro rojo del folio del SII, resolucion.
 *
 * QUE AGREGA RESPECTO DE LA COTIZACION: la linea de IVA. Una cotizacion muestra
 * el IVA como referencia de lo que le va a llegar al cliente; aqui es una compra
 * y el IVA es parte de lo que se va a pagar.
 */
final class OrdenCompraPdfDocumento extends \sasco\LibreDTE\PDF
{
    private const PISO_BLOQUE = 50;
    private const LOGO_MM = LogoEmpresa::ANCHO_DIBUJO_MM;
    private const RZN_SOC_ANCHO_CON_LOGO = 65;
    private const RZN_SOC_ANCHO_SIN_LOGO = 115;
    private const RZN_SOC_TAMANOS = [20, 18, 16, 14];
    private const RZN_SOC_MAX_LINEAS = 2;

    /**
     * Anchos de la tabla, UNO POR COLUMNA Y NINGUNO EN 0.
     *
     * Es lo que hace que PDF::addTable() emita <thead> -- solo lo hace cuando
     * count($options['width']) == count($headers), lib/PDF.php:180 -- y que
     * writeHTML() de TCPDF repita la cabecera en cada salto de pagina. Por eso
     * se usa addTable() y NO addTableWithoutEmptyCols(), que elimina columnas y
     * descuadra ese conteo. Suman 185 mm, el ancho util entre x=10 y x=195.
     */
    private const ANCHOS = [72, 14, 16, 24, 18, 22, 19];

    private ?string $logo = null;

    public function __construct()
    {
        parent::__construct();
        $this->SetTitle('Orden de compra');
    }

    public function setLogo(?string $logo): void
    {
        $this->logo = $logo;
    }

    /**
     * @param array<string,mixed> $emisor  RznSoc, GiroEmis, DirOrigen, CmnaOrigen, RUTEmisor
     * @param array<string,mixed> $orden   numero, fecha, fecha_entrega, lugar_entrega, proveedor_*, condiciones_pago, notas, neto, exento, iva, total
     * @param list<array<string,mixed>> $lineas
     */
    public function agregar(array $emisor, array $orden, array $lineas): void
    {
        $this->AddPage();

        $this->agregarEmisor($emisor);
        $finEmisor = $this->getY();

        $this->agregarTitulo($orden);

        // MISMO CRITERIO QUE EL DTE Y LA COTIZACION: el bloque de abajo FLUYE.
        // Un piso fijo se monta en cuanto la razon social ocupa varias lineas.
        $this->setY(max(self::PISO_BLOQUE, $finEmisor));

        $this->agregarDatos($orden);
        $this->agregarProveedor($orden);
        $this->agregarDetalle($lineas);
        $this->agregarTotales($orden);
        $this->agregarNotas($orden);
    }

    // =======================================================================
    //  CABECERA
    // =======================================================================

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
            // El fondo REAL de la imagen y no una constante: Image() recibe h=0 y
            // TCPDF calcula el alto del PNG concreto. Con la validacion vigente
            // ese alto va de 0,27 a 50 mm.
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
            $xCompleto, $this->y, 'L', $wCompleto
        );
        if (! empty($emisor['RUTEmisor'])) {
            $this->MultiTexto('R.U.T.: ' . $emisor['RUTEmisor'], $xCompleto, $this->y, 'L', $wCompleto);
        }
    }

    /**
     * El recuadro del numero. Ocupa el sitio del recuadro rojo del SII pero NO
     * lo imita: sin RUT dentro, sin unidad del SII debajo, y en gris -- el rojo
     * identifica un documento tributario y una orden de compra no lo es.
     * Confundirlos ante un proveedor seria un problema, no un detalle.
     */
    private function agregarTitulo(array $orden, float $x = 130, float $y = 10, float $w = 65): void
    {
        $this->SetTextColorArray([60, 60, 60]);
        $this->Rect($x, $y, $w, 22, 'D', ['all' => ['width' => 0.4, 'color' => [60, 60, 60]]]);
        $this->setFont('', 'B', 12);
        $this->Texto('ORDEN DE COMPRA', $x, $y + 4, 'C', $w);
        $this->setFont('', 'B', 16);
        $this->Texto('N° ' . (int) ($orden['numero'] ?? 0), $x, $y + 12, 'C', $w);
        $this->SetTextColorArray([0, 0, 0]);
        $this->setFont('', '', 9);
    }

    private function agregarDatos(array $orden, float $x = 10): void
    {
        $this->setFont('', '', 9);
        foreach ([
            'Fecha'          => $this->fechaLarga((string) ($orden['fecha'] ?? '')),
            'Fecha entrega'  => ! empty($orden['fecha_entrega']) ? $this->fechaCorta((string) $orden['fecha_entrega']) : '',
            'Lugar entrega'  => (string) ($orden['lugar_entrega'] ?? ''),
            'Condiciones'    => (string) ($orden['condiciones_pago'] ?? ''),
        ] as $etiqueta => $valor) {
            if (trim((string) $valor) === '') {
                continue;
            }
            $this->Texto($etiqueta, $x);
            $this->Texto(':', $x + 26);
            $this->MultiTexto((string) $valor, $x + 30, null, 'L', 100);
        }
    }

    /**
     * LA CONTRAPARTE ES UN PROVEEDOR, y la etiqueta lo dice.
     *
     * En la cotizacion este bloque dice "Señor(es)" porque va hacia un cliente.
     * Aqui la orden va HACIA el proveedor: es la unica diferencia semantica del
     * documento y tiene que quedar clara en el papel, no solo en la base.
     */
    private function agregarProveedor(array $o, float $x = 10): void
    {
        $this->Ln();
        $this->setFont('', 'B', 9);
        $this->Texto('Proveedor', $x);
        $this->setFont('', '', 9);
        $this->Texto(':', $x + 26);
        $this->MultiTexto((string) ($o['proveedor_razon_social'] ?? ''), $x + 30, null, 'L', 100);

        $this->Texto('R.U.T.', $x);
        $this->Texto(':', $x + 26);
        $this->MultiTexto((string) ($o['proveedor_rut'] ?? ''), $x + 30);

        foreach ([
            'Giro'      => $o['proveedor_giro'] ?? '',
            'Direccion' => trim((string) ($o['proveedor_direccion'] ?? '') . ', ' . (string) ($o['proveedor_comuna'] ?? ''), ', '),
            'Contacto'  => $o['proveedor_contacto'] ?? '',
        ] as $etiqueta => $valor) {
            if (trim((string) $valor) === '') {
                continue;
            }
            $this->Texto($etiqueta, $x);
            $this->Texto(':', $x + 26);
            $this->MultiTexto((string) $valor, $x + 30, null, 'L', 150);
        }
    }

    // =======================================================================
    //  DETALLE Y TOTALES
    // =======================================================================

    /**
     * LA TABLA SE PAGINA SOLA, y aqui es mas simple que en el DTE.
     *
     * En un DTE una tabla larga se dibuja ENCIMA del acuse y del timbre, porque
     * esos van en coordenadas FIJAS (y=190) DESPUES de la tabla. En una orden de
     * compra no hay nada clavado: la tabla fluye, los totales se dibujan donde
     * termine y las notas despues. Una orden de 40 lineas ocupa dos paginas.
     *
     * NO SE ACOTA EL NUMERO DE LINEAS: truncar lo que se le pide a un proveedor
     * seria peor que dar vuelta la hoja.
     *
     * @param list<array<string,mixed>> $lineas
     */
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
            $neto = $this->neto($l);
            $filas[] = [
                $nombre,
                $this->cantidad((float) $l['cantidad']),
                (string) ($l['unidad'] ?? ''),
                $this->num((float) $l['precio_unitario']),
                ((float) ($l['descuento_pct'] ?? 0)) > 0 ? $this->num((float) $l['descuento_pct']) . '%' : '',
                $this->num($neto),
                ! empty($l['exento']) ? 'Exento' : 'Afecto',
            ];
        }

        $this->Ln();
        $this->SetX($x);
        $this->setFont('', '', 8);
        $this->addTable($titulos, $filas, ['width' => self::ANCHOS, 'align' => [
            'left', 'right', 'left', 'right', 'right', 'right', 'center',
        ]]);
    }

    /**
     * LOS TOTALES SALEN DE LA CABECERA, NO SE RECALCULAN AQUI.
     *
     * MySqlOrdenCompraRepository::totales() los calculo UNA vez al guardar y los
     * dejo en columnas. Volver a sumarlos en el PDF crearia una segunda version
     * de la misma cifra: si el redondeo difiriera en un peso, el papel del
     * proveedor y la pantalla dirian cosas distintas sin que nadie hubiera
     * tocado nada.
     */
    private function agregarTotales(array $orden, float $x = 130, float $w = 65): void
    {
        $this->Ln();
        $this->setFont('', '', 9);

        foreach ([
            'Neto'    => (int) ($orden['neto'] ?? 0),
            'Exento'  => (int) ($orden['exento'] ?? 0),
            'IVA 19%' => (int) ($orden['iva'] ?? 0),
        ] as $etiqueta => $valor) {
            if ($valor <= 0) {
                continue;
            }
            $this->Texto($etiqueta, $x, null, 'R', $w - 25);
            $this->MultiTexto('$ ' . $this->num($valor), $x + $w - 25, $this->getY(), 'R', 25);
        }

        $this->setFont('', 'B', 10);
        $this->Texto('TOTAL', $x, null, 'R', $w - 25);
        $this->MultiTexto('$ ' . $this->num((int) ($orden['total'] ?? 0)), $x + $w - 25, $this->getY(), 'R', 25);
        $this->setFont('', '', 9);
    }

    private function agregarNotas(array $orden, float $x = 10): void
    {
        if (trim((string) ($orden['notas'] ?? '')) === '') {
            return;
        }
        $this->Ln();
        $this->setFont('', 'B', 9);
        $this->MultiTexto('Observaciones', $x, null, 'L', 185);
        $this->setFont('', '', 9);
        $this->MultiTexto((string) $orden['notas'], $x, $this->getY(), 'L', 185);
    }

    // =======================================================================
    //  AUXILIARES
    // =======================================================================

    /** Neto de la linea, con su descuento ya aplicado. */
    private function neto(array $l): float
    {
        $bruto = (float) $l['cantidad'] * (float) $l['precio_unitario'];

        return $bruto * (1 - ((float) ($l['descuento_pct'] ?? 0)) / 100);
    }

    /** Cantidad CON decimales cuando los tiene y sin ellos cuando no. */
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
     * COPIADO de CotizacionPdfDocumento, que a su vez lo copio de
     * DtePdfDocumento porque alli es private. El piso de 14 pt es normativo en el
     * DTE -- la razon social tiene que leerse destacada frente al giro, que va a
     * 9 pt (14/9 = 1,56x) -- y se mantiene aqui por coherencia visual entre los
     * impresos de la misma empresa, no por obligacion.
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
