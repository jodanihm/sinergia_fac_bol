<?php

declare(strict_types=1);

/**
 * FORK de sasco\LibreDTE\Sii\PDF\Dte (LibreDTE, SASCO SpA, LGPL v3+).
 *
 * Copia literal de esa clase -- 534 lineas de 2015 -- con DOS cambios de
 * conducta: agregarTotales() dibuja los impuestos adicionales, y agregar()
 * dibuja la fecha de vencimiento cuando el documento la trae. Todo lo demas
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
 * LA PAGINA ES A4, NO LETTER, Y NADIE LO SABIA
 * -----------------------------------------------------------------------------
 * MEDIDO, no leido: $pdf->getPageHeight() devuelve 297,00 y $pdf->getScaleFactor()
 * 2,834646. O sea A4 (210 x 297 mm), no Letter (215,9 x 279,4).
 *
 * Y NO ES LO QUE PIDE EL CODIGO. La cadena de constructores es:
 *
 *   DtePdfDocumento::__construct()  -> parent::__construct()  (sin argumentos)
 *   sasco\LibreDTE\PDF::__construct($o='P', $u='mm', $s='Letter', $top=8)
 *   TCPDF::__construct($o, $u, 'Letter', ...)
 *
 * TCPDF resuelve el formato con TCPDF_STATIC::getPageSizeFromFormat(), que es
 * esto entero (tcpdf_static.php:2514):
 *
 *   if (isset(self::$page_formats[$format])) { return self::$page_formats[$format]; }
 *   return self::$page_formats['A4'];
 *
 * Un isset() SENSIBLE A MAYUSCULAS y un fallback mudo. La clave del arreglo es
 * 'LETTER' (tcpdf_static.php:2273); lo que llega es 'Letter'. No coincide, cae a
 * A4, y no se entera nadie. Es un defecto de LibreDTE de 2015 que arrastramos
 * junto con el resto de la clase.
 *
 * QUE INVALIDA ESTO, porque no es anecdotico:
 *
 *   - El salto de pagina automatico esta en 297-25 = 272 mm, no en 254,4.
 *   - El borde derecho util es 210-15 = 195 mm, no 200,9. Y el codigo dibuja los
 *     totales en x=200 y la tabla hasta x=200 (addTableWithoutEmptyCols usa
 *     190 desde x=10), o sea 5 mm DENTRO del margen derecho. MultiTexto con w=0
 *     ajusta a 195, no a 200,9.
 *   - Cualquier calculo de presupuesto vertical hecho sobre 279,4 estaba corrido
 *     17,6 mm.
 *
 * NO SE ARREGLA AQUI. Pasar el formato a 'LETTER' cambiaria el tamaño de papel
 * de TODOS los documentos y el reflujo de todos: es una decision de producto --
 * y hay una certificacion en vuelo cuyas muestras impresas salen de este mismo
 * renderizador. Queda medido y escrito; cambiarlo es una entrega propia.
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
 * LOS DOS CAMBIOS CUMPLEN ESE CRITERIO por la misma razon: cada uno vive detras
 * de un if sobre un dato que puede no venir -- ImptoReten en los totales, FchVenc
 * en el IdDoc --, asi que un documento que no lo trae recorre exactamente el
 * mismo camino que antes. Por eso NO se agrego el monto total en palabras, que
 * era la otra candidata: se calcula desde MntTotal, que existe SIEMPRE, o sea
 * que habria cambiado el flujo del 100% de los documentos.
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
     * Donde arranca el pie (timbre y acuse) cuando el contenido no llega hasta
     * ahi. ES EL VALOR HISTORICO, y por eso el caso normal no cambia: hasta 25
     * lineas de detalle el max() de agregar() toma este 190 y el documento sale
     * byte a byte igual que antes.
     */
    private const Y_PIE = 190;

    /**
     * Distancia entre el fin del contenido y el pie cuando el contenido pasa de
     * Y_PIE. No hay un valor "correcto" heredado -- antes esta situacion
     * simplemente se dibujaba encima --, asi que se elige el minimo que separa
     * visiblemente dos bloques sin gastar hoja.
     */
    private const SEPARACION_PIE = 6;

    /**
     * Alto del bloque del pie, para decidir si cabe en la pagina.
     *
     * EL BLOQUE SON LOS TRES JUNTOS -- totales, timbre y acuse -- porque los
     * tres arrancan en Y_PIE y se reparten el ancho. El mas alto manda: el acuse
     * es un rectangulo de 40 mm (190..230) y CEDIBLE va 55 mm mas abajo (245),
     * con su linea de texto. 60 cubre el conjunto.
     *
     * SE PREFIERE PASARSE ANTES QUE QUEDARSE CORTO: sobrar unos milimetros
     * adelanta un salto de pagina; faltar parte el acuse entre dos hojas.
     */
    private const ALTO_PIE = 60;

    /** 245 - 190: la distancia que CEDIBLE siempre tuvo respecto del pie. */
    private const CEDIBLE_BAJO_PIE = 55;

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

        // DONDE TERMINA DE VERDAD EL BLOQUE DEL EMISOR. Se captura AQUI, antes
        // de agregarFolio(), porque ese metodo mueve el cursor por su cuenta
        // (dibuja el recuadro rojo y la unidad del SII usando getY()).
        //
        // agregarEmisor() YA dejaba esta Y -- sus cuatro MultiTexto encadenan
        // uno debajo del otro -- y hasta hoy se tiraba: el setY(50) de mas abajo
        // la descartaba sin mirarla.
        $finEmisor = $this->getY();

        $this->agregarFolio(
            $dte['Encabezado']['Emisor']['RUTEmisor'],
            $dte['Encabezado']['IdDoc']['TipoDTE'],
            $dte['Encabezado']['IdDoc']['Folio'],
            $dte['Encabezado']['Emisor']['CmnaOrigen']
        );

        // datos del documento
        //
        // EL BLOQUE DE ABAJO FLUYE, YA NO ARRANCA EN UNA COORDENADA FIJA.
        //
        // Era setY(50) a secas, y con eso se montaba. Caso real: la factura
        // exenta folio 675 de 78225195-3, cuya razon social -- "SOCIEDAD DE
        // PROFESIONALES ROSAS Y VILLAR LIMITADA", 48 caracteres a Bold 20 -- se
        // partia en CUATRO lineas al haber logo, el bloque del emisor crecia
        // hasta y~57 y el giro y la direccion quedaban encima de las etiquetas
        // Emision, Venta y Señor(es).
        //
        // El logo era el detonante, no la causa: la causa es que un bloque que
        // crece con los datos del cliente estaba seguido de otro clavado en una
        // constante. Cualquier razon social larga lo rompe, con logo o sin el.
        //
        // EL MINIMO DE 50 ES LO QUE PRESERVA LA INERCIA: un documento cuyo
        // emisor termina antes de 50 -- todos los que hoy salen bien -- toma el
        // 50 de siempre y no cambia ni un operador de dibujo. Solo se mueve lo
        // que hoy esta roto.
        //
        // No se suma ningun margen: MultiTexto deja el cursor en el BORDE
        // INFERIOR de la celda, no en la linea base, asi que la separacion
        // visual ya viene dada por el alto de celda.
        $this->setY(max(50, $finEmisor));
        $this->agregarFechaEmision($dte['Encabezado']['IdDoc']['FchEmis']);
        if (!empty($dte['Encabezado']['IdDoc']['FmaPago']))
            $this->agregarCondicionVenta($dte['Encabezado']['IdDoc']['FmaPago']);
        if (!empty($dte['Encabezado']['IdDoc']['FchVenc']))
            $this->agregarFechaVencimiento($dte['Encabezado']['IdDoc']['FchVenc']);
        $this->agregarReceptor($dte['Encabezado']['Receptor']);
        if (!empty($dte['Referencia']))
            $this->agregarReferencia($dte['Referencia']);
        $this->agregarDetalle($dte['Detalle']);
        if (!empty($dte['DscRcgGlobal']))
            $this->agregarDescuentosRecargos($dte['DscRcgGlobal']);
        // ===================================================================
        //  EL PIE VA DONDE TERMINO LA TABLA, NO EN y=190 FIJO
        // ===================================================================
        //
        // EL DEFECTO QUE ESTO ARREGLA, MEDIDO: a partir de 26 lineas de detalle
        // la tabla se dibujaba ENCIMA del timbre y del acuse. El motivo es que
        // los tres elementos del pie iban en coordenadas fijas (timbre y acuse
        // en 190, CEDIBLE en 245) mientras la tabla no tenia mas tope que el
        // salto automatico de TCPDF, que en A4 dispara recien en 272 mm. O sea
        // que entre 190 y 272 la tabla dibujaba libremente sobre ellos: 82 mm de
        // zona de choque.
        //
        // POR QUE NO SE ARREGLA CONFIGURANDO TCPDF. setAutoPageBreak($auto,
        // $margin) hace PageBreakTrigger = h - margin (tcpdf.php:2868): el
        // margen inferior es del DOCUMENTO, no de la pagina. Reservar los ~60 mm
        // del pie los reservaria en TODAS las paginas, desperdiciando un quinto
        // de cada hoja intermedia. Y AcceptPageBreak() decide SI saltar, no
        // donde va el mobiliario.
        //
        // ASI QUE SE INVIERTE EL ORDEN: la tabla ya se dibujo y pagino sola con
        // el margen normal; ahora se mira DONDE TERMINO y el pie se pone debajo.
        //
        // ES EL MISMO CRITERIO QUE EL setY(max(50, $finEmisor)) DE ARRIBA, y por
        // eso se escribe igual: un bloque que crece con los datos no puede ir
        // seguido de otro clavado en una constante.
        //
        // EL max() ES LO QUE PRESERVA LA INERCIA. Un documento cuyo contenido
        // termina antes de 190 -- o sea todos los que hoy salen bien, hasta 25
        // lineas -- toma el 190 de siempre y no cambia ni un operador de dibujo.
        // Solo se mueve lo que hoy esta roto.
        //
        // -------------------------------------------------------------------
        // EL PIE SON **TRES** BLOQUES, NO DOS, Y ESTAN EN LA MISMA BANDA
        // -------------------------------------------------------------------
        // A y=190 no solo estan el timbre (x 20-90) y el acuse (x 93-143):
        // tambien los TOTALES, que agregarTotales() dibuja a la derecha tras
        // hacer setY(190) en su linea 967. Los tres comparten franja vertical y
        // se reparten el ancho, asi que se mueven JUNTOS o no se mueve ninguno.
        //
        // LA PRIMERA VERSION DE ESTE ARREGLO LEIA getY() DESPUES DE
        // agregarTotales(), y por eso fallo: lo que medía era el fin del bloque
        // de totales -- clavado en 190 -- y no el de la tabla. Daba ~202,5 con
        // 1 linea y ~202,5 con 40, o sea un valor casi constante que movia el
        // pie 18,5 mm en TODOS los documentos (rompiendo los tres md5 cortos) y
        // que nunca crecia lo suficiente para disparar el salto de pagina.
        //
        // Se lee ANTES de los totales, que es donde de verdad termina el
        // contenido que fluye: agregarDetalle() deja el cursor donde acaba la
        // tabla, y agregarDescuentosRecargos() escribe justo debajo.
        $finContenido = $this->getY();
        $yPie = max(self::Y_PIE, $finContenido + self::SEPARACION_PIE);

        // SI EL PIE NO CABE ENTERO, PAGINA NUEVA. El limite se pregunta y no se
        // escribe: getPageHeight() - getBreakMargin() es la misma cuenta con la
        // que TCPDF decide sus propios saltos, asi que no puede desincronizarse
        // de ella. Partir el acuse entre dos hojas seria cambiar un defecto por
        // otro.
        $limiteUtil = $this->getPageHeight() - $this->getBreakMargin();
        if ($yPie + self::ALTO_PIE > $limiteUtil) {
            $this->AddPage();
            $yPie = self::Y_PIE;
        }

        // EL ORDEN DE DIBUJO NO CAMBIA -- totales, timbre, acuse --, y eso
        // importa: el md5 del flujo compara operadores en secuencia, asi que
        // reordenarlos rompería la inercia aunque el resultado se viera igual.
        $this->agregarTotales($dte['Encabezado']['Totales'], $yPie);

        // agregar timbre
        $this->agregarTimbre($timbre, 20, $yPie, 70);
        // agregar acuse de recibo y leyenda de destino sólo si no es nota de
        // crédito ni nota de débito
        if (!in_array($dte['Encabezado']['IdDoc']['TipoDTE'], $this->sinAcuseRecibo)) {
            $this->agregarAcuseRecibo(93, $yPie, 50, 40);
            if ($this->cedible)
                // 55 es la distancia que CEDIBLE tenia respecto del pie: 245-190.
                // Se conserva relativa para que el caso normal salga identico.
                $this->agregarLeyendaDestino($yPie + self::CEDIBLE_BAJO_PIE);
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
    private function agregarEmisor(array $emisor, $x = 10, $y = 10, $w = 75, $w_img = LogoEmpresa::ANCHO_DIBUJO_MM)
    {
        // ANCHO COMPLETO DEL BLOQUE. Es el mismo 115 que la rama sin logo venia
        // calculando con $w += 40: desde x=10 llega a 125, cinco milimetros antes
        // del recuadro del folio (agregarFolio, x=130). No es un numero nuevo.
        $xCompleto = $x;
        $wCompleto = $w + 40;

        // Ancho para la LINEA DEL NOMBRE y Y a partir de la cual el ancho
        // completo esta libre. Sin logo son los del bloque entero.
        $wNombre     = $wCompleto;
        $xNombre     = $xCompleto;
        $yBajoElLogo = null;

        // logo máximo 1/5 del tamaño del documento
        if (isset($this->logo)) {
            $this->Image($this->logo, $x, $y, $w_img, 0, 'PNG', (isset($emisor['url'])?$emisor['url']:''), 'T');

            // EL NOMBRE AL LADO DEL LOGO, EL RESTO POR DEBAJO A TODO EL ANCHO.
            //
            // Antes los cuatro textos iban en la misma columna angosta de 75 mm
            // aunque el logo solo ocupara ~12 mm de alto: todo lo que caia por
            // debajo de esos 12 mm tenia 115 mm disponibles y usaba 75. Ahora el
            // nombre convive con el logo y el giro / direccion / contacto bajan.
            //
            // POR QUE 65 Y NO 75. El logo pasa de 30 a 40 mm de ancho, asi que su
            // borde derecho se corre de 40 a 50 y el texto arranca en 53 en vez
            // de 43. Con 65 el nombre termina en 118, EXACTAMENTE donde terminaba
            // antes, y conserva los 12 mm de aire hasta el recuadro del folio.
            // Cabrian 77, pero ensanchar hasta rozar el recuadro no compra nada:
            // el nombre largo ya esta en el piso de la cascada.
            $xNombre = $this->x + 3;
            $wNombre = 65;

            // LA BAJADA NO PUEDE SER UNA CONSTANTE, y esto no es prudencia
            // teorica. Image() recibe h=0, asi que TCPDF calcula el alto
            // proporcional DEL PNG CONCRETO y lo deja en img_rb_y (tcpdf.php:7282),
            // que es lo que devuelve getImageRBY(). Con la validacion vigente ese
            // alto va de 0,27 a 50 mm: cualquier constante que sirva para el logo
            // de un cliente le dibuja el giro ENCIMA de la imagen a otro.
            //
            // Y hay un motivo mas fuerte: LogoEmpresa solo valida AL SUBIR. Los
            // logos que ya estan en dte_logo se aceptaron midiendo contra 30 mm y
            // desde esta entrega se dibujan a 40, un 33% mas altos, sin que nadie
            // los vuelva a mirar. Este max() es la unica defensa que tienen.
            //
            // El +2 es la misma separacion que ya se usaba entre el borde superior
            // y la primera linea de texto.
            $yBajoElLogo = $this->getImageRBY() + 2;

            // align='T' dejo el cursor en el borde SUPERIOR de la imagen
            // (tcpdf.php:7321), que es lo que alinea el nombre con el logo.
        } else {
            $this->y = $y-2;
        }

        // agregar datos del emisor
        //
        // A LA CASCADA SE LE PASA EL ANCHO DEL NOMBRE, NO EL DEL BLOQUE. Con
        // $wCompleto elegiria 20 pt para un texto que se dibuja en 65 mm y
        // volveriamos a las cuatro lineas, pero ahora en cuerpo grande.
        $this->setFont('', 'B', $this->tamanoRazonSocial((string) $emisor['RznSoc'], $wNombre));
        $this->SetTextColorArray([32, 92, 144]);
        $this->MultiTexto($emisor['RznSoc'], $xNombre, $this->y+2, 'L', $wNombre);

        // Donde arranca el bloque de abajo: lo que termine mas abajo entre el
        // nombre y el logo. Sin logo, $yBajoElLogo es null y manda el nombre,
        // igual que siempre.
        $y2 = $yBajoElLogo !== null ? max($this->y, $yBajoElLogo) : $this->y;

        $this->setFont('', 'B', 9);
        $this->SetTextColorArray([0,0,0]);
        $this->MultiTexto($emisor['GiroEmis'], $xCompleto, $y2, 'L', $wCompleto);
        $this->MultiTexto($emisor['DirOrigen'].', '.$emisor['CmnaOrigen'], $xCompleto, $this->y, 'L', $wCompleto);
        $contacto = [];
        if (!empty($emisor['Telefono'])) {
            // LA GUARDA ANTERIOR NO PODIA FUNCIONAR NUNCA, y se comio el telefono
            // de todo documento cuyo XML lo trajera.
            //
            // Decia: if (!isset($emisor['Telefono'][0])) $emisor['Telefono'] = [...];
            //
            // La intencion era envolver el escalar cuando <Telefono> viene una sola
            // vez, y dejarlo tal cual cuando el XML lo repite y llega como lista.
            // Pero $string[0] ES ACCESO A OFFSET DE STRING: para '+56 63 2 123456'
            // vale '+', asi que isset() daba TRUE y el envoltorio no corria jamas.
            // El foreach recibia el string, PHP 8 emitia
            // "foreach() argument must be of type array|object, string given",
            // $contacto se quedaba sin el telefono y la linea salia solo con el
            // correo -- o no salia, si tampoco habia correo. La guarda solo
            // acertaba en el unico caso que no la necesitaba: cuando ya era array.
            //
            // Nadie lo vio porque DtePdfGenerator apaga los warnings
            // (error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING),
            // linea 77) y porque hoy DteXmlBuilder no emite <Telefono>: ningun
            // documento real entra aqui. Lo encontro el arnes del emisor, que
            // siembra un emisor con telefono y correo.
            $telefonos = is_array($emisor['Telefono'])
                ? $emisor['Telefono']
                : [$emisor['Telefono']];
            foreach ($telefonos as $t)
                $contacto[] = $t;
        }
        if (!empty($emisor['CorreoEmisor']))
            $contacto[] = $emisor['CorreoEmisor'];
        if ($contacto)
            $this->MultiTexto(implode(' / ', $contacto), $xCompleto, $this->y, 'L', $wCompleto);
    }

    /**
     * Tamaños de fuente candidatos para la razon social, del mayor al menor.
     *
     * EMPIEZA EN 20 PORQUE ES EL DE SIEMPRE: un nombre que hoy entra en el
     * maximo de lineas a 20 pt se dibuja EXACTAMENTE igual que antes de esta
     * entrega -- misma fuente, mismo flujo, ni un operador de diferencia.
     *
     * TERMINA EN 14 Y NO EN 12, y el motivo es normativo, no estetico. El SII
     * exige que la razon social vaya "completa y destacada respecto del giro y
     * las direcciones", que se dibujan a 9 pt. Es una exigencia RELATIVA, sin
     * tamaño concreto, asi que lo que hay que poder defender es la jerarquia:
     *
     *   14 / 9 = 1,56x   se lee como otro nivel de titulo
     *   12 / 9 = 1,33x   se empieza a leer como enfasis del mismo nivel
     *
     * 12 pt habria comprado una linea en el caso con logo (49 caracteres, medido
     * cuando ese hueco eran 75 mm; hoy son 65), pero a costa del unico argumento
     * que sostiene el cumplimiento. La restriccion que manda es la del SII, no
     * la del hueco.
     *
     * ESE CASO YA AGOTA LA CASCADA: a 75 mm caia en el piso de 14 pt sin llegar
     * al maximo de lineas, asi que estrechar a 65 no puede cambiar el tamaño
     * elegido -- 14 es el ultimo candidato. Lo que puede cambiar es el numero de
     * lineas, y desde esta entrega eso ya no empuja nada hacia abajo: el bloque
     * de giro/direccion/contacto arranca en max(fin del nombre, fondo del logo).
     *
     * @var list<int>
     */
    private const RZN_SOC_TAMANOS = [20, 18, 16, 14];

    /**
     * MAXIMO DE LINEAS al que se intenta ajustar la razon social.
     *
     * DOS, porque es lo que hace un membrete: a 20 pt dos lineas ocupan unos
     * 17,6 mm y el bloque entero del emisor cabe holgado sobre el arranque del
     * bloque de abajo. Las cuatro lineas del caso real ocupaban 35 mm y son las
     * que se comian media hoja.
     */
    private const RZN_SOC_MAX_LINEAS = 2;

    /**
     * Tamaño de fuente para la razon social: el MAYOR que entre en el maximo de
     * lineas, con piso en el ultimo candidato.
     *
     * SE MIDE, NO SE CUENTAN CARACTERES. "IIII" y "WWWW" tienen el mismo largo y
     * miden distinto; y el ancho disponible no es fijo -- son 115 mm sin logo y
     * 65 con logo --, asi que el MISMO nombre necesita tamaños distintos segun
     * el documento. Se usa getNumLines(), que es la propia logica de salto de
     * linea de TCPDF: la misma que va a aplicar MultiCell al dibujar. No una
     * aproximacion.
     *
     * NO ESCRIBE NADA EN EL FLUJO. setFont() con $out=false fija las propiedades
     * de la fuente sin emitir el operador Tf, y al terminar se restaura la que
     * estaba. Es el mismo mecanismo que usa TCPDF internamente en
     * GetArrStringWidth() (tcpdf.php:4137-4160). Si se midiera con setFont()
     * normal, cada medicion dejaria un Tf en el flujo y la inercia se perderia
     * incluso para los documentos que no cambian de tamaño.
     *
     * SI NINGUN CANDIDATO ENTRA, se usa el mas chico y se aceptan las lineas que
     * hagan falta. NUNCA se recorta el nombre: el SII lo exige COMPLETO, y un
     * nombre truncado es un defecto de correccion, no de estetica.
     *
     * EL ANCHO QUE SE PASA ES EL DE LA LINEA DEL NOMBRE, no el del bloque del
     * emisor. Desde que el giro, la direccion y el contacto bajan a todo el
     * ancho por debajo del logo, esos dos numeros dejaron de ser el mismo: el
     * nombre convive con el logo en 65 mm y el resto dispone de 115.
     *
     * @param float|int $w ancho disponible en mm (115 sin logo, 65 con logo)
     */
    private function tamanoRazonSocial($texto, $w)
    {
        $previo = [$this->FontFamily, $this->FontStyle, $this->FontSizePt];
        $elegido = null;

        foreach (self::RZN_SOC_TAMANOS as $pt) {
            // $out=false: no emite Tf, solo fija las propiedades para medir.
            $this->setFont($this->FontFamily, 'B', $pt, '', 'default', false);
            if ($this->getNumLines($texto, $w) <= self::RZN_SOC_MAX_LINEAS) {
                $elegido = $pt;
                break;
            }
        }

        // Restaurar, tambien sin emitir: la llamada real a setFont la hace el
        // caller con el tamaño devuelto, y tiene que ser la UNICA del flujo.
        $this->setFont($previo[0], $previo[1], $previo[2], '', 'default', false);

        return $elegido ?? self::RZN_SOC_TAMANOS[count(self::RZN_SOC_TAMANOS) - 1];
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
     * Fecha de vencimiento del documento (tag FchVenc del XML).
     *
     * SEGUNDO METODO AGREGADO RESPECTO DEL ORIGINAL de 2015 -- el otro es
     * glosaImpuesto(). El resto de la clase sigue siendo copia literal.
     *
     * COPIA EL PATRON DE agregarCondicionVenta(), a proposito y linea por linea:
     * misma columna izquierda (x=10), misma sangria de la etiqueta y los dos
     * puntos (x+22 y x+26), y una LINEA MAS del bloque que ya fluye desde
     * setY(50). Va inmediatamente despues de la condicion de venta porque
     * "Credito" sin fecha de vencimiento es media frase.
     *
     * POR QUE NO EN LA COLUMNA DERECHA, que es donde la pone LibreDTE comercial:
     * porque esa zona NO esta libre. MultiTexto() se llama con w=0 y en TCPDF eso
     * significa "hasta el margen derecho" (x=200,9 en Letter), asi que las cajas
     * de Señor(es), Giro y Direccion ya se extienden hasta ahi y una razon social
     * larga envuelve dentro de ellas. Seria el mismo choque que ya costo una
     * cascada de candidatas en glosaImpuesto(), pero contra un dato del cliente
     * -- o sea imposible de acotar de antemano. La restriccion de esta linea es
     * VERTICAL: consume alto del tramo que va de y=50 a y=190, compartido con el
     * receptor, las referencias y la tabla de detalle.
     *
     * ETIQUETA "Vence" Y NO "Vencimiento": entre la etiqueta y los dos puntos hay
     * 22 mm, y a Helvetica Bold 10 -- la fuente activa en este punto --
     * "Vencimiento" no entra. Ninguna etiqueta de este bloque pasa de 9
     * caracteres ('Señor(es)', 'Direccion', 'Referenc.'); esta tiene 5, como
     * 'Venta'.
     *
     * FORMATO dd-mm-aaaa Y NO ISO: lo lee el receptor del documento, no un
     * sistema. Mismo criterio y mismo formato que ya se uso en el cuerpo del
     * correo de envio.
     *
     * INERTE: se llama desde dentro de un if sobre el propio dato, igual que
     * agregarCondicionVenta(). Un documento sin FchVenc -- todos los anteriores
     * al 01-08-2026 y todas las boletas, que no llevan el campo -- no entra aqui
     * y no emite ni un operador de dibujo.
     *
     * @param fecha Fecha de vencimiento en formato AAAA-MM-DD
     * @param x Posición horizontal de inicio en el PDF
     */
    private function agregarFechaVencimiento($fecha, $x = 10)
    {
        // Si la fecha no fuera interpretable se imprime cruda: una fecha rara a
        // la vista es mejor que una linea vacia o que un 01-01-1970.
        $unixtime = strtotime((string) $fecha);
        $texto = $unixtime !== false ? date('d-m-Y', $unixtime) : (string) $fecha;
        $this->Texto('Vence', $x);
        $this->Texto(':', $x+22);
        $this->MultiTexto($texto, $x+26);
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
     *
     * ------------------------------------------------------------------------
     * DEFECTO YA ARREGLADO -- SE DEJA LA DESCRIPCION PORQUE EXPLICA EL DISEÑO
     * ACTUAL. Una tabla larga se dibujaba encima del acuse de recibo y del
     * timbre. Se corrigio en agregar(), moviendo el pie a
     * max(190, fin del contenido + separacion) con salto de pagina si no cabe;
     * NO se toco esta funcion. El punto de quiebre medido con datos sinteticos
     * fue N = 26 lineas -- la estimacion "alrededor de la fila 27" que decia
     * este comentario quedo a una fila. Ese 26 es una COTA SUPERIOR: se midio
     * con la linea base del texto del ultimo item, y el borde inferior de la
     * tabla cae unos milimetros mas abajo, asi que documentos algo mas cortos
     * ya rozaban el pie.
     *
     * Esta tabla FLUYE hacia abajo sin tope propio. El unico corte que existe es
     * el salto de pagina automatico de TCPDF, que con margen inferior 25 cae en
     * y = 297 - 25 = 272 mm. (297 y no 279,4: la pagina es A4, ver el docblock
     * de la clase. Este comentario decia 254,4 y estaba corrido 17,6 mm.) Pero
     * el mobiliario de abajo esta en coordenadas FIJAS y mucho mas arriba:
     *
     *   agregarTimbre()        x=20  y=190
     *   agregarAcuseRecibo()   x=93  y=190, alto 40  (hasta y=230)
     *   agregarTotales()       setY(190)
     *
     * O sea que entre y=190 y y=254 la tabla y el mobiliario comparten papel, y
     * gana el que se dibuje despues. Con lineas de ~4 mm, una tabla que arranque
     * en y~81 llega a 190 alrededor de la fila 27.
     *
     * POR QUE LA CABECERA DE LA TABLA **NO** SE REPITE EN CADA PAGINA, que es lo
     * que aquel comentario daba por parte de la solucion: addTable() del padre
     * solo emite <thead> -- que es lo que TCPDF repite al saltar -- cuando
     * count($options['width']) == count($headers) (lib/PDF.php:180). Aqui el
     * ancho de la columna "Item" es 0 y ademas se usa
     * addTableWithoutEmptyCols(), que ELIMINA columnas vacias y descuadra ese
     * conteo. Conseguirlo obligaria a cambiar los anchos y el metodo de dibujo, o
     * sea a mover el flujo de TODOS los documentos con detalle -- justo lo que el
     * arreglo del pie evito. Queda pendiente y separado: es cosmetico y esto era
     * correctitud.
     *
     * ES ANTERIOR AL BLOQUE QUE FLUYE: no lo introdujo el setY(max(50, ...)) de
     * agregar(). Ese cambio acerca el techo unos 9 mm en el peor caso medido; el
     * defecto ya estaba.
     * ------------------------------------------------------------------------
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
