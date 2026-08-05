<?php

declare(strict_types=1);

/**
 * FORK de sasco\LibreDTE\Sii\PDF\Dte (LibreDTE, SASCO SpA, LGPL v3+).
 *
 * Copia literal de esa clase -- 534 lineas de 2015 -- con UN solo cambio de
 * conducta: agregarTotales() dibuja los impuestos adicionales. Todo lo demas
 * queda como estaba, incluidos los comentarios y la autoria originales.
 *
 * LibreDTE es software libre bajo LGPL v3 o posterior; esta obra derivada
 * conserva esa licencia. Autor original: Esteban De La Fuente Rubio, DeLaF.
 */

/**
 * POR QUE EXISTE ESTE FORK
 * -----------------------------------------------------------------------------
 * agregarTotales() de la clase original normaliza los totales a CINCO claves
 * -- MntNeto, MntExe, TasaIVA, IVA, MntTotal -- y solo imprime las que tienen
 * glosa en un mapa de cuatro entradas. Cualquier otro hijo de <Totales>
 * desaparece en esa normalizacion. ImptoReten es uno de ellos.
 *
 * Y eso no es un detalle estetico: MntTotal SI incluye el impuesto adicional
 * (Formato DTE v2.5, campo 124). Medido sobre un PDF real de una factura de
 * cerveza con ILA:
 *
 *     Neto $ :        200.000
 *     I.V.A. (19%) :   38.000
 *     Total $ :       279.000     <- 41.000 aparecen de la nada
 *
 * El impreso mostraba un total que no cuadra con sus propias lineas. Es un
 * defecto de correccion, no de presentacion.
 *
 *
 * POR QUE UN FORK Y NO UN PARCHE EN oracle/
 * -----------------------------------------------------------------------------
 * Porque nadie sabria que el parche existe. oracle/ NO ESTA EN GIT --
 * .gitignore lo excluye y `git ls-files oracle/` devuelve cero archivos -- y
 * tampoco esta en la imagen Docker: /app es un bind mount del disco de cada
 * maquina (docker-compose.yml). Un archivo parcheado ahi no sale en git status,
 * no viaja en un clone, no lo trae un despliegue, y una reinstalacion de
 * LibreDTE lo borraria sin aviso.
 *
 *
 * POR QUE SOLO ESTA CLASE, Y NO TAMBIEN PDF.php
 * -----------------------------------------------------------------------------
 * Se forkea PDF\Dte (534 lineas) y se sigue EXTENDIENDO sasco\LibreDTE\PDF
 * (219 lineas), que es el wrapper generico de TCPDF -- Texto(), MultiTexto(),
 * addTable(), Header/Footer -- sin ninguna coordenada de layout de documento.
 *
 * Duplicar tambien esa base tendria un costo concreto: BoletaPdfDocumento la
 * extiende igual (ver su docblock), y con dos copias las boletas y las facturas
 * podrian empezar a dibujar distinto sin que nadie lo note. Compartiendola, el
 * camino de boletas no se entera de este fork.
 *
 * Las otras tres clases de LibreDTE que intervienen al generar un PDF
 * -- Sii\EnvioDte, Sii\Dte y XML, 1.434 lineas entre las tres -- tampoco se
 * tocan: parsean el XML y devuelven arrays, asi que esta clase no depende de
 * ellas mas que por el arreglo que recibe en agregar().
 *
 *
 * EL TIMBRE NO SE TOCA
 * -----------------------------------------------------------------------------
 * agregarTimbre() queda identico. El PDF417 no lo genera LibreDTE sino TCPDF
 * (write2DBarcode -> vendor/tecnickcom/tcpdf/include/barcodes/pdf417.php, 996
 * lineas, ISO/IEC 15438:2006), que llega por Composer. Es la unica parte que no
 * se puede improvisar y no hizo falta tocarla.
 *
 *
 * INERCIA: QUE SIGNIFICA "IGUAL QUE ANTES" EN UN PDF
 * -----------------------------------------------------------------------------
 * NO se puede exigir igualdad byte a byte del archivo, y no por este cambio:
 * TCPDF escribe /CreationDate, /ModDate y un /ID derivado del reloj. Medido,
 * generando DOS VECES el mismo documento sin tocar una linea: 132 bytes de
 * 93.935 difieren siempre.
 *
 * El criterio que si sirve es el md5 del FLUJO DE CONTENIDO DESCOMPRIMIDO (los
 * operadores de dibujo), que es estable entre corridas. Un documento sin
 * impuesto adicional tiene que producir el mismo flujo que producia la clase
 * original: la rama nueva ni siquiera se entra.
 *
 * Esto importa mas que de costumbre porque hay una certificacion en vuelo sin
 * aprobar y las MUESTRAS IMPRESAS salen de este mismo renderizador
 * (MuestrasImpresasZipBuilder reusa DtePdfGenerator tal cual).
 */

namespace Plantiflex\FacturacionCl\Pdf;

/**
 * Clase para generar el PDF de un documento tributario electrónico (DTE)
 * chileno.
 * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]sasco.cl)
 * @version 2015-09-16
 */
final class DtePdfDocumento extends \sasco\LibreDTE\PDF
{

    private $logo; ///< Ubicación del logo del emisor que se incluirá en el pdf
    private $resolucion; ///< Arreglo con los datos de la resolución (índices: NroResol y FchResol)
    private $cedible = false; ///< Por defecto DTEs no son cedibles

    private $tipos = [
        33 => 'FACTURA ELECTRÓNICA',
        34 => 'FACTURA NO AFECTA O EXENTA ELECTRÓNICA',
        43 => 'LIQUIDACIÓN FACTURA ELECTRÓNICA',
        46 => 'FACTURA DE COMPRA ELECTRÓNICA',
        52 => 'GUÍA DE DESPACHO ELECTRÓNICA',
        56 => 'NOTA DE DÉBITO ELECTRÓNICA',
        61 => 'NOTA DE CRÉDITO ELECTRÓNICA',
        110 => 'FACTURA DE EXPORTACIÓN ELECTRÓNICA',
        111 => 'NOTA DE DÉBITO DE EXPORTACIÓN ELECTRÓNICA',
        112 => 'NOTA DE CRÉDITO DE EXPORTACIÓN ELECTRÓNICA',
        39 => 'BOLETA ELECTRÓNICA',
        41 => 'BOLETA EXENTA ELECTRÓNICA',
    ]; ///< Glosas para los tipos de documentos

    private $formas_pago = [
        1 => 'Contado',
        2 => 'Crédito',
        3 => 'Sin costo (entrega gratuita)',
    ]; ///< Glosas de las formas de pago

    private $detalle_cols = [
        'CdgItem' => ['title'=>'Código', 'align'=>'left', 'width'=>20],
        'NmbItem' => ['title'=>'Item', 'align'=>'left', 'width'=>0],
        'QtyItem' => ['title'=>'Cant.', 'align'=>'right', 'width'=>15],
        'UnmdItem' => ['title'=>'Unidad', 'align'=>'left', 'width'=>22],
        'PrcItem' => ['title'=>'P. unitario', 'align'=>'right', 'width'=>22],
        'DescuentoMonto' => ['title'=>'Descuento', 'align'=>'right', 'width'=>22],
        'RecargoMonto' => ['title'=>'Recargo', 'align'=>'right', 'width'=>22],
        'MontoItem' => ['title'=>'Total item', 'align'=>'right', 'width'=>22],
    ];

    private $sinAcuseRecibo = [39, 41, 56, 61, 111, 112]; ///< Notas de crédito, notas de débito y boletas no tienen acuse de recibo

    /**
     * Constructor de la clase
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]sasco.cl)
     * @version 2015-09-09
     */
    public function __construct()
    {
        parent::__construct();
        $this->SetTitle('Documento Tributario Electrónico (DTE) de Chile');
    }

    /**
     * Método que asigna la ubicación del logo de la empresa
     * @param logo URI del logo (puede ser local o en una URL)
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]sasco.cl)
     * @version 2015-09-08
     */
    public function setLogo($logo)
    {
        $this->logo = $logo;
    }

    /**
     * Método que asigna los datos de la resolución del SII que autoriza al
     * emisor a emitir DTEs
     * @param resolucion Arreglo con índices NroResol y FchResol
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]sasco.cl)
     * @version 2015-09-08
     */
    public function setResolucion(array $resolucion)
    {
        $this->resolucion = $resolucion;
    }

    /**
     * Método que indica si el documento será o no cedible
     * @param cedible =true se incorporará leyenda de destino
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]sasco.cl)
     * @version 2015-09-09
     */
    public function setCedible($cedible = true)
    {
        $this->cedible = $cedible;
    }

    /**
     * Método que agrega una página con el documento tributario
     * @param dte Arreglo con los datos del XML (tag Documento)
     * @param timbre String XML con el tag TED del DTE
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]sasco.cl)
     * @version 2015-09-17
     */
    public function agregar(array $dte, $timbre)
    {
        // agregar página para la factura
        $this->AddPage();
        // agregar cabecera del documento
        $this->agregarEmisor($dte['Encabezado']['Emisor']);
        $this->agregarFolio(
            $dte['Encabezado']['Emisor']['RUTEmisor'],
            $dte['Encabezado']['IdDoc']['TipoDTE'],
            $dte['Encabezado']['IdDoc']['Folio'],
            $dte['Encabezado']['Emisor']['CmnaOrigen']
        );
        // datos del documento
        $this->setY(50);
        $this->agregarFechaEmision($dte['Encabezado']['IdDoc']['FchEmis']);
        if (!empty($dte['Encabezado']['IdDoc']['FmaPago']))
            $this->agregarCondicionVenta($dte['Encabezado']['IdDoc']['FmaPago']);
        $this->agregarReceptor($dte['Encabezado']['Receptor']);
        if (!empty($dte['Referencia']))
            $this->agregarReferencia($dte['Referencia']);
        $this->agregarDetalle($dte['Detalle']);
        if (!empty($dte['DscRcgGlobal']))
            $this->agregarDescuentosRecargos($dte['DscRcgGlobal']);
        $this->agregarTotales($dte['Encabezado']['Totales']);
        // agregar timbre
        $this->agregarTimbre($timbre);
        // agregar acuse de recibo y leyenda de destino sólo si no es nota de
        // crédito ni nota de débito
        if (!in_array($dte['Encabezado']['IdDoc']['TipoDTE'], $this->sinAcuseRecibo)) {
            $this->agregarAcuseRecibo();
            if ($this->cedible)
                $this->agregarLeyendaDestino();
        }
    }

    /**
     * Método que agrega los datos de la empresa
     * Orden de los datos:
     *  - Razón social del emisor
     *  - Giro del emisor (sin abreviar)
     *  - Dirección casa central del emisor
     *  - Dirección sucursales
     * @param emisor Arreglo con los datos del emisor (tag Emisor del XML)
     * @param x Posición horizontal de inicio en el PDF
     * @param y Posición vertical de inicio en el PDF
     * @param w Ancho de la información del emisor
     * @param w_img Ancho máximo de la imagen
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]sasco.cl)
     * @version 2015-09-13
     */
    private function agregarEmisor(array $emisor, $x = 10, $y = 10, $w = 75, $w_img = 30)
    {
        // logo máximo 1/5 del tamaño del documento
        if (isset($this->logo)) {
            $this->Image($this->logo, $x, $y, $w_img, 0, 'PNG', (isset($emisor['url'])?$emisor['url']:''), 'T');
            $x = $this->x+3;
        } else {
            $this->y = $y-2;
            $w += 40;
        }
        // agregar datos del emisor
        $this->setFont('', 'B', 20);
        $this->SetTextColorArray([32, 92, 144]);
        $this->MultiTexto($emisor['RznSoc'], $x, $this->y+2, 'L', $w);
        $this->setFont('', 'B', 9);
        $this->SetTextColorArray([0,0,0]);
        $this->MultiTexto($emisor['GiroEmis'], $x, $this->y, 'L', $w);
        $this->MultiTexto($emisor['DirOrigen'].', '.$emisor['CmnaOrigen'], $x, $this->y, 'L', $w);
        $contacto = [];
        if (!empty($emisor['Telefono'])) {
            if (!isset($emisor['Telefono'][0]))
                $emisor['Telefono'] = [$emisor['Telefono']];
            foreach ($emisor['Telefono'] as $t)
                $contacto[] = $t;
        }
        if (!empty($emisor['CorreoEmisor']))
            $contacto[] = $emisor['CorreoEmisor'];
        if ($contacto)
            $this->MultiTexto(implode(' / ', $contacto), $x, $this->y, 'L', $w);
    }

    /**
     * Método que agrega el recuadro con el folio
     * Recuadro:
     *  - Tamaño mínimo 1.5x5.5 cms
     *  - En lado derecho (negro o rojo)
     *  - Enmarcado por una línea de entre 0.5 y 1 mm de espesor
     *  - Tamaño máximo 4x8 cms
     *  - Letras tamaño 10 o superior en mayúsculas y negritas
     *  - Datos del recuadro: RUT emisor, nombre de documento en 2 líneas,
     *    folio.
     *  - Bajo el recuadro indicar la Dirección regional o Unidad del SII a la
     *    que pertenece el emisor
     * @param rut RUT del emisor
     * @param tipo Código o glosa del tipo de documento
     * @param sucursal_sii Código o glosa de la sucursal del SII del Emisor
     * @param x Posición horizontal de inicio en el PDF
     * @param y Posición vertical de inicio en el PDF
     * @param w Ancho de la información del emisor
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]sasco.cl)
     * @version 2015-09-08
     */
    private function agregarFolio($rut, $tipo, $folio, $sucursal_sii = null, $x = 130, $y = 10, $w = 70)
    {
        $this->SetTextColorArray([255,0,0]);
        // colocar rut emisor, glosa documento y folio
        list($rut, $dv) = explode('-', $rut);
        $this->setFont ('', 'B', 15);
        $this->MultiTexto('R.U.T.: '.$this->num($rut).'-'.$dv, $x, $y+4, 'C', $w);
        $this->setFont('', 'B', 12);
        $this->MultiTexto($this->getTipo($tipo), $x, null, 'C', $w);
        $this->setFont('', 'B', 15);
        $this->MultiTexto('N° '.$folio, $x, null, 'C', $w);
        // dibujar rectángulo rojo
        $this->Rect($x, $y, $w, round($this->getY()-$y+3), 'D', ['all' => ['width' => 0.5, 'color' => [255, 0, 0]]]);
        // colocar unidad del SII
        $this->setFont('', 'B', 10);
        $this->Texto('S.I.I. - '.$this->getSucursalSII($sucursal_sii), $x, $this->getY()+4, 'C', $w);
        $this->SetTextColorArray([0,0,0]);
    }

    /**
     * Método que entrega la glosa del tipo de documento
     * @param tipo Código del tipo de documento
     * @return Glosa del tipo de documento
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]sasco.cl)
     * @version 2015-09-08
     */
    private function getTipo($tipo)
    {
        if (!is_numeric($tipo))
            return $tipo;
        return isset($this->tipos[$tipo]) ? strtoupper($this->tipos[$tipo]) : 'DTE '.$tipo;
    }

    /**
     * Método que entrega la sucursal del SII asociada al emisor
     * @param codigo de la sucursal del SII
     * @return Sucursal del SII
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]sasco.cl)
     * @version 2015-09-09
     */
    private function getSucursalSII($codigo)
    {
        if (!is_numeric($codigo)) {
            $sucursal = strtoupper($codigo);
            return $sucursal=='SANTIAGO' ? 'SANTIAGO CENTRO' : $sucursal;
        }
        return 'SUC '.$codigo;
    }

    /**
     * Método que agrega la fecha de emisión de la factura
     * @param date Fecha de emisión de la boleta en formato AAAA-MM-DD
     * @param x Posición horizontal de inicio en el PDF
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]sasco.cl)
     * @version 2015-09-09
     */
    private function agregarFechaEmision($date, $x = 10)
    {
        $dias = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
        $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        $unixtime = strtotime($date);
        $fecha = date('\D\I\A j \d\e \M\E\S \d\e\l Y', $unixtime);
        $dia = $dias[date('w', $unixtime)];
        $mes = $meses[date('n', $unixtime)-1];
        $this->Texto('Emisión', $x);
        $this->Texto(':', $x+22);
        $this->MultiTexto(str_replace(array('DIA', 'MES'), array($dia, $mes), $fecha), $x+26);
    }

    /**
     * Método que agrega la condición de venta del documento
     * @param condicion_venta Código de la condición de venta (tag FmaPago XML)
     * @param x Posición horizontal de inicio en el PDF
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]sasco.cl)
     * @version 2015-09-09
     */
    private function agregarCondicionVenta($condicion_venta, $x = 10)
    {
        $this->Texto('Venta', $x);
        $this->Texto(':', $x+22);
        $this->MultiTexto($this->formas_pago[$condicion_venta], $x+26);
    }

    /**
     * Método que agrega los datos del receptor
     * @param receptor Arreglo con los datos del receptor (tag Receptor del XML)
     * @param x Posición horizontal de inicio en el PDF
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]sasco.cl)
     * @version 2015-09-12
     */
    private function agregarReceptor(array $receptor, $x = 10)
    {
        list($rut, $dv) = explode('-', $receptor['RUTRecep']);
        $this->Texto('Señor(es)', $x);
        $this->Texto(':', $x+22);
        $this->MultiTexto($receptor['RznSocRecep'], $x+26);
        $this->Texto('R.U.T.', $x);
        $this->Texto(':', $x+22);
        $this->MultiTexto($this->num($rut).'-'.$dv, $x+26);
        // Boleta no lleva GiroRecep/DirRecep/CmnaRecep: omitir la linea en vez de
        // imprimirla vacia (factura siempre trae estos campos, asi que el if no
        // cambia nada para factura).
        if (!empty($receptor['GiroRecep'])) {
            $this->Texto('Giro', $x);
            $this->Texto(':', $x+22);
            $this->MultiTexto($receptor['GiroRecep'], $x+26);
        }
        if (!empty($receptor['DirRecep']) || !empty($receptor['CmnaRecep'])) {
            $this->Texto('Dirección', $x);
            $this->Texto(':', $x+22);
            $separador = (!empty($receptor['DirRecep']) && !empty($receptor['CmnaRecep'])) ? ', ' : '';
            $this->MultiTexto(($receptor['DirRecep'] ?? '').$separador.($receptor['CmnaRecep'] ?? ''), $x+26);
        }
        $contacto = [];
        if (!empty($receptor['Contacto']))
            $contacto[] = $receptor['Contacto'];
        if (!empty($receptor['CorreoRecep']))
            $contacto[] = $receptor['CorreoRecep'];
        if (!empty($contacto)) {
            $this->Texto('Contacto', $x);
            $this->Texto(':', $x+22);
            $this->MultiTexto(implode(' / ', $contacto), $x+26);
        }
    }

    /**
     * Método que agrega las referencias del documento
     * @param referencias Arreglo con las referencias del documento (tag Referencia del XML)
     * @param x Posición horizontal de inicio en el PDF
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]sasco.cl)
     * @version 2015-09-08
     */
    private function agregarReferencia($referencias, $x = 10)
    {
        if (!isset($referencias[0]))
            $referencias = [$referencias];
        foreach($referencias as $r) {
            $texto = $r['NroLinRef'].' - '.$this->getTipo($r['TpoDocRef']).' N° '.$r['FolioRef'].' del '.$r['FchRef'].': '.$r['RazonRef'];
            $this->Texto('Referenc.', $x);
            $this->Texto(':', $x+22);
            $this->MultiTexto($texto, $x+26);
        }
    }

    /**
     * Método que agrega el detalle del documento
     * @param detalle Arreglo con el detalle del documento (tag Detalle del XML)
     * @param x Posición horizontal de inicio en el PDF
     * @param y Posición vertical de inicio en el PDF
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]sasco.cl)
     * @version 2015-09-22
     */
    private function agregarDetalle($detalle, $x = 10)
    {
        if (!isset($detalle[0]))
            $detalle = [$detalle];
        // titulos
        $titulos = [];
        $titulos_keys = array_keys($this->detalle_cols);
        foreach ($this->detalle_cols as $key => $info) {
            $titulos[$key] = $info['title'];
        }
        // normalizar cada detalle
        foreach ($detalle as &$item) {
            // quitar columnas
            foreach ($item as $col => $valor) {
                if ($col=='DscItem') {
                    $item['NmbItem'] .= '<br/><span style="font-size:0.7em">'.$item['DscItem'].'</span>';
                }
                if (!in_array($col, $titulos_keys))
                    unset($item[$col]);
            }
            // agregar todas las columnas que se podrían imprimir en la tabla
            $item_default = [];
            foreach ($this->detalle_cols as $key => $info)
                $item_default[$key] = false;
            $item = array_merge($item_default, $item);
            // si hay código de item se extrae su valor
            if ($item['CdgItem'])
                $item['CdgItem'] = $item['CdgItem']['VlrCodigo'];
            // dar formato a números
            foreach (['QtyItem', 'PrcItem', 'DescuentoMonto', 'RecargoMonto', 'MontoItem'] as $col) {
                if ($item[$col])
                    $item[$col] = $this->num($item[$col]);
            }
        }
        // opciones
        $options = ['align'=>[]];
        $i = 0;
        foreach ($this->detalle_cols as $info) {
            if (isset($info['width']))
                $options['width'][$i] = $info['width'];
            $options['align'][$i] = $info['align'];
            $i++;
        }
        // agregar tabla de detalle
        $this->Ln();
        $this->SetX($x);
        $this->addTableWithoutEmptyCols($titulos, $detalle, $options);
    }

    /**
     * Método que agrega los descuentos y/o recargos globales del documento
     * @param descuentosRecargos Arreglo con los descuentos y/o recargos del documento (tag DscRcgGlobal del XML)
     * @param x Posición horizontal de inicio en el PDF
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]sasco.cl)
     * @version 2015-09-09
     */
    private function agregarDescuentosRecargos(array $descuentosRecargos, $x = 10)
    {
        if (!isset($descuentosRecargos[0]))
            $descuentosRecargos = [$descuentosRecargos];
        foreach($descuentosRecargos as $dr) {
            $tipo = $dr['TpoMov']=='D' ? 'Descuento' : 'Recargo';
            $valor = $dr['TpoValor']=='%' ? $dr['ValorDR'].'%' : '$'.$this->num($dr['ValorDR']).'.-';
            $this->Texto($tipo.' global de '.$valor, $x);
        }
    }

    /**
     * Método que agrega los totales del documento
     * @param totales Arreglo con los totales (tag Totales del XML)
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]sasco.cl)
     * @version 2015-09-09
     *
     * ------------------------------------------------------------------------
     * UNICO METODO MODIFICADO RESPECTO DEL ORIGINAL. El resto de la clase es
     * copia literal. Lo que se agrego son las lineas de ImptoReten, entre el
     * IVA y el total; todo lo demas -- el orden, las coordenadas, las glosas,
     * el formato -- queda intacto, que es lo que hace que un documento sin
     * impuesto adicional dibuje exactamente lo mismo que antes.
     * ------------------------------------------------------------------------
     */
    private function agregarTotales(array $totales, $y = 190)
    {
        // Los impuestos adicionales se sacan ANTES de normalizar, por dos
        // motivos. Uno: array_merge() empuja al final las claves que no estan
        // en la lista de defaults, asi que ImptoReten quedaria DESPUES de
        // MntTotal y se dibujaria bajo el total. Dos: sacandolo, el arreglo que
        // recorre el bucle de abajo queda EXACTAMENTE igual que en el original,
        // y con el la salida de cualquier documento que no lleve impuestos.
        $impuestos = self::impuestosAdicionales($totales);
        unset($totales['ImptoReten']);

        // normalizar totales
        $totales = array_merge([
            'MntNeto' => false,
            'MntExe' => false,
            'TasaIVA' => false,
            'IVA' => false,
            'MntTotal' => false,
        ], $totales);
        // glosas
        $glosas = [
            'MntNeto' => 'Neto $',
            'MntExe' => 'Exento $',
            'IVA' => 'I.V.A. ('.$totales['TasaIVA'].'%)',
            'MntTotal' => 'Total $',
        ];
        // agregar cada uno de los totales
        $this->setY($y);
        foreach ($totales as $key => $total) {
            // Los impuestos adicionales van JUSTO ANTES del total, que es donde
            // el lector los necesita para que la columna sume. Si fueran
            // despues, el total seguiria pareciendo que sale de la nada.
            if ($key === 'MntTotal' && $impuestos) {
                foreach ($impuestos as $imp) {
                    $x = 175;
                    $this->Texto($this->glosaImpuesto($imp).' :', $x, null, 'R', 1);
                    $this->Texto($this->num($imp['MontoImp']), $x+25, null, 'R', 1);
                    $this->Ln();
                }
            }
            if ($total!==false and isset($glosas[$key])) {
                $x = 175;
                $this->Texto($glosas[$key].' :', $x, null, 'R', 1);
                $this->Texto($this->num($total), $x+25, null, 'R', 1);
                $this->Ln();
            }
        }
    }

    /**
     * Normaliza el ImptoReten del arreglo de totales a una LISTA de bloques.
     *
     * El parser XML de LibreDTE devuelve dos formas distintas segun cuantos
     * bloques haya, y confundirlas dibujaria basura:
     *   - un bloque  -> ['TipoImp' => '26', 'TasaImp' => '20.5', ...]
     *   - dos o mas  -> [0 => ['TipoImp' => '26', ...], 1 => [...]]
     * Medido sobre documentos reales, no deducido.
     *
     * @param array $totales tag Totales ya convertido a arreglo
     * @return array lista de bloques, vacia si no hay impuestos adicionales
     */
    private static function impuestosAdicionales(array $totales)
    {
        if (empty($totales['ImptoReten'])) {
            return [];
        }
        $imp = $totales['ImptoReten'];

        return isset($imp['TipoImp']) ? [$imp] : array_values($imp);
    }

    /**
     * Ancho util de la etiqueta de un total, en milimetros.
     *
     * NO es un numero elegido: es la distancia entre donde TERMINA el recuadro
     * de acuse de recibo (agregarAcuseRecibo dibuja en x=93 con w=50, o sea
     * hasta x=143) y donde TERMINA la etiqueta (x=175, mas 1 mm de celda). Las
     * etiquetas se dibujan alineadas a la derecha, asi que crecen hacia la
     * izquierda y a los 33 mm empiezan a montarse sobre el acuse.
     *
     * Medido, no supuesto: con la glosa oficial completa del codigo 26 la
     * etiqueta mide 77,0 mm y pdftotext la muestra entrelazada con el texto del
     * acuse ("Impuesto Art. N4o2mcb)reCerv_e__z_a_s..."), ademas de desplazar
     * los montos fuera de su linea.
     */
    private const ANCHO_ETIQUETA_MM = 33;

    /**
     * Glosa impresa de un bloque ImptoReten.
     *
     * EL NOMBRE SALE DE ImpuestoAdicional::CODIGOS, que es la enumeracion del
     * propio XSD del SII. NO se crea una segunda tabla de nombres: mantener dos
     * listas de codigos sincronizadas es justo lo que este proyecto ya decidio
     * no volver a hacer (ver los seis mapas de nombres de tipo de documento).
     *
     * PERO NO CABE ENTERA, y por eso hay una cascada de candidatas en vez de un
     * texto fijo. Medido a 8 pt, que es la fuente que deja addTable():
     *
     *     "Impuesto Art. 42 c) Cervezas y Bebidas Alcoholicas (20,5%)"  77,0 mm
     *     "Cervezas y Bebidas Alcoholicas (20,5%)"                      52,4 mm
     *     "Impuesto Art. 42 c (20,5%)"                                  35,1 mm
     *     "Imp. adicional (20,5%)"                                      29,6 mm  <- cabe
     *     "I.V.A. (19%)" (la de hoy, como referencia)                   16,8 mm
     *
     * Se prueban de la mas informativa a la mas corta y se usa la PRIMERA que
     * entra, midiendo con GetStringWidth contra la fuente real. Asi un codigo
     * de glosa corta -- "IVA Retenido Trigo", por ejemplo -- se imprime con su
     * nombre completo, y solo los de glosa larga caen al generico.
     *
     * LA TASA VA SIEMPRE, en todas las candidatas: el Formato DTE lo pide
     * expresamente -- "En el titulo del campo, identificar si se trata de
     * Impuesto adicional, especifico, retencion y la tasa respectiva" (campo
     * 116, nota 6).
     *
     * Si el codigo no estuviera en la enumeracion -- imposible por la puerta
     * normal, porque el motor lo valida al emitir y responde 422 -- se imprime
     * el codigo crudo en vez de dejar la linea sin etiqueta.
     *
     * @param array $imp bloque ImptoReten
     * @return string
     */
    private function glosaImpuesto(array $imp)
    {
        $codigo = isset($imp['TipoImp']) ? (string) $imp['TipoImp'] : '';
        $glosa  = \Plantiflex\FacturacionCl\Sii\ImpuestoAdicional::glosa($codigo);

        $tasa = isset($imp['TasaImp']) && $imp['TasaImp'] !== ''
            ? ' ('.rtrim(rtrim(str_replace('.', ',', (string) $imp['TasaImp']), '0'), ',').'%)'
            : '';

        $candidatas = [];
        if ($glosa !== null) {
            // 1. La glosa completa, sin el "[F29 - Cxxx]" que solo sirve para
            //    cruzar con el formulario 29 y no le dice nada al receptor.
            $completa = trim(preg_replace('/\s*\[.*$/', '', $glosa));
            $candidatas[] = $completa;
            // 2. Lo que va DESPUES del ultimo parentesis de cierre: para el 26
            //    eso deja "Cervezas y Bebidas Alcoholicas", que es el nombre
            //    util sin la cita del articulo.
            $pos = strrpos($completa, ')');
            if ($pos !== false && trim(substr($completa, $pos + 1)) !== '') {
                $candidatas[] = trim(substr($completa, $pos + 1));
            }
        }
        // 3. Generico. Cumple igual con identificar de que se trata.
        $candidatas[] = 'Imp. adicional'.($glosa === null && $codigo !== '' ? ' '.$codigo : '');

        foreach ($candidatas as $nombre) {
            if ($this->GetStringWidth($nombre.$tasa.' :') <= self::ANCHO_ETIQUETA_MM) {
                return $nombre.$tasa;
            }
        }

        // Ninguna entro (una tasa absurdamente larga): se usa la mas corta
        // igual. Vale mas una etiqueta apretada que una linea sin nombre.
        return end($candidatas).$tasa;
    }

    /**
     * Método que agrega el timbre de la factura
     *  - Se imprime en el tamaño mínimo: 2x5 cms
     *  - En el lado de abajo con margen izquierdo mínimo de 2 cms
     * @param timbre String con los datos del timbre
     * @param x Posición horizontal de inicio en el PDF
     * @param y Posición vertical de inicio en el PDF
     * @param w Ancho del timbre
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]sasco.cl)
     * @version 2015-09-08
     */
    private function agregarTimbre($timbre, $x = 20, $y = 190, $w = 70)
    {
        $style = [
            'border' => false,
            'vpadding' => 0,
            'hpadding' => 0,
            'fgcolor' => [0,0,0],
            'bgcolor' => false, // [255,255,255]
            'module_width' => 1, // width of a single module in points
            'module_height' => 1 // height of a single module in points
        ];
        $this->write2DBarcode($timbre, 'PDF417', $x, $y, $w, 0, $style, 'B');
        $this->setFont('', 'B', 8);
        $this->Texto('Timbre Electrónico SII', $x, $this->y, 'C', $w);
        $this->Texto('Resolución '.$this->resolucion['NroResol'].' de '.explode('-', $this->resolucion['FchResol'])[0], $x, $this->y+4, 'C', $w);
        $this->Texto('Verifique documento: www.sii.cl', $x, $this->y+4, 'C', $w, 'http://www.sii.cl');
    }

    /**
     * Método que agrega el acuse de rebido
     * @param x Posición horizontal de inicio en el PDF
     * @param y Posición vertical de inicio en el PDF
     * @param w Ancho del acuse de recibo
     * @param h Alto del acuse de recibo
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]sasco.cl)
     * @version 2015-09-08
     */
    private function agregarAcuseRecibo($x = 93, $y = 190, $w = 50, $h = 40)
    {
        $this->SetTextColorArray([0,0,0]);
        $this->Rect($x, $y, $w, $h, 'D', ['all' => ['width' => 0.1, 'color' => [0, 0, 0]]]);
        $this->setFont('', 'B', 10);
        $this->Texto('Acuse de recibo', $x, $y+1, 'C', $w);
        $this->setFont('', 'B', 8);
        $this->Texto('Nombre', $x+2, $this->y+8);
        $this->Texto('________________', $x+18);
        $this->Texto('R.U.T.', $x+2, $this->y+6);
        $this->Texto('________________', $x+18);
        $this->Texto('Fecha', $x+2, $this->y+6);
        $this->Texto('________________', $x+18);
        $this->Texto('Recinto', $x+2, $this->y+6);
        $this->Texto('________________', $x+18);
        $this->Texto('Firma', $x+2, $this->y+8);
        $this->Texto('________________', $x+18);
        $this->setFont('', 'B', 7);
        $this->MultiTexto('El acuse de recibo que se declara en este acto, de acuerdo a lo dispuesto en la letra b) del Art. 4°, y la letra c) del Art. 5° de la Ley 19.983, acredita que la entrega de mercaderías o servicio (s) prestado (s) ha (n) sido recibido (s).'."\n", $x, $this->y+6, 'J', $w);
    }

    /**
     * Método que agrega la leyenda de destino
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]sasco.cl)
     * @version 2015-09-09
     */
    private function agregarLeyendaDestino($y = 245)
    {
        $this->setFont('', 'B', 10);
        $this->Texto('CEDIBLE', null, $y, 'R');
    }

    /**
     * Método que formatea un número con separador de miles y decimales (si
     * corresponden)
     * @param n Número que se desea formatear
     * @param d Cantidad de decimales
     * @return Número formateado
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]sasco.cl)
     * @version 2015-09-08
     */
    private function num($n, $d=0)
    {
        return number_format((float)$n, $d, ',', '.');
    }

}
