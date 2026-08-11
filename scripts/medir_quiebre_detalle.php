<?php

declare(strict_types=1);

/**
 * ARNES DE MEDICION: donde se rompe el detalle del PDF del DTE.
 *
 * NO CAMBIA NADA. Mide y reporta, para poder proponer el arreglo con numeros en
 * vez de con una estimacion.
 *
 * TRES COSAS:
 *   1. EL PUNTO DE QUIEBRE REAL: con cuantas lineas el detalle empieza a
 *      dibujarse sobre el mobiliario del pie (timbre en y=190, acuse 190-230,
 *      CEDIBLE en 245).
 *   2. LA LINEA BASE DE INERCIA: md5 del flujo descomprimido de documentos de 1,
 *      2 y 3 lineas. Sin esto no se puede demostrar despues que el arreglo no
 *      movio lo que ya funciona.
 *   3. Si ext-intl esta cargada, que decide si el "monto en palabras" se puede
 *      hacer sin escribir 80 lineas a mano.
 *
 * -----------------------------------------------------------------------------
 * COMO SE IDENTIFICA "LA TABLA" -- Y POR QUE NO POR COORDENADAS
 *
 * El mobiliario del pie y la tabla SE SOLAPAN EN X (la tabla va de 10 a ~195; el
 * acuse de 93 a 143), asi que clasificar por posicion seria adivinar. En vez de
 * eso, LA SIEMBRA SE MARCA: cada item se llama "ITEM-001", "ITEM-002"... y se
 * buscan los operadores cuyo texto empieza asi. No hay heuristica que pueda
 * equivocarse.
 *
 * Es la leccion de medirCabecera(): no afinar una heuristica, cambiar el dato.
 * -----------------------------------------------------------------------------
 */

// Techo de memoria: este arnes genera del orden de 20 PDF, uno por cada N que
// prueba. Se libera entre medio, pero el techo va igual.
ini_set('memory_limit', '256M');

$RAIZ = dirname(__DIR__);
require $RAIZ . '/vendor/autoload.php';

use Plantiflex\FacturacionCl\Pdf\DtePdfDocumento;
use Plantiflex\FacturacionCl\Pdf\DtePdfGenerator;

function titulo(string $t): void
{
    echo "\n", str_repeat('=', 78), "\n", $t, "\n", str_repeat('=', 78), "\n";
}
function ok(string $m): void
{
    echo "  [OK]      {$m}\n";
}
function dato(string $m): void
{
    echo "  [DATO]    {$m}\n";
}
function morir(string $m): never
{
    echo "\n*** ABORTADO: {$m}\n";
    exit(2);
}

// ===========================================================================
// 3. ext-intl -- VA PRIMERO PORQUE NO CUESTA NADA
//
// Se mira desde PHP y no con "php -m": es el MISMO proceso que generaria el
// monto en palabras, asi que responde por el interprete que importa. Un php -m
// desde otro contenedor podria decir otra cosa.
// ===========================================================================
titulo('EXT-INTL (punto 3 de la lista)');

$intl = extension_loaded('intl');
printf("  extension_loaded('intl'): %s\n", $intl ? 'SI' : 'NO');
printf("  class_exists('NumberFormatter'): %s\n", class_exists('NumberFormatter') ? 'SI' : 'NO');
if ($intl) {
    $f = new NumberFormatter('es', NumberFormatter::SPELLOUT);
    printf("  prueba: 1234567 -> \"%s\"\n", $f->format(1234567));
    ok('ext-intl ESTA disponible: el monto en palabras se puede hacer con '
        . 'NumberFormatter::SPELLOUT, sin escribir la conversion a mano.');
} else {
    echo "  [AVISO]   ext-intl NO esta. El monto en palabras exigiria escribir la\n";
    echo "            conversion a mano (~80 lineas de casos: decenas, centenas,\n";
    echo "            'veintiun', 'cien' vs 'ciento', millones en plural). Es codigo\n";
    echo "            fragil que nadie va a querer mantener: la recomendacion es NO\n";
    echo "            hacerlo hasta que la extension este.\n";
    echo "            Para habilitarla: docker-php-ext-install intl en la imagen.\n";
}
printf("  extensiones cargadas: %d\n", count(get_loaded_extensions()));

// ===========================================================================
// UTILIDADES DE MEDICION
// ===========================================================================

/**
 * Los flujos de contenido, UNO POR PAGINA y no concatenados.
 *
 * Hace falta separarlos: cuando el detalle pagina, los items de la pagina 2
 * vuelven a tener Y baja, y sumarlos todos daria un maximo que no significa
 * nada. La pregunta es "hasta donde llega la tabla EN LA PAGINA 1".
 *
 * @return list<string>
 */
function flujosPorPagina(string $pdf): array
{
    $paginas = [];
    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m) === 0) {
        return [];
    }
    foreach ($m[1] as $bruto) {
        $plano = @gzuncompress($bruto);
        if ($plano === false) {
            $plano = @gzinflate($bruto);
        }
        // Un stream que no descomprime es una fuente o una imagen. Y uno sin
        // operadores de texto no es una pagina de contenido.
        if ($plano !== false && (str_contains($plano, 'TJ') || str_contains($plano, 'Tj'))) {
            $paginas[] = $plano;
        }
    }

    return $paginas;
}

/** El flujo entero, para el md5 de inercia. */
function flujoDescomprimido(string $pdf): string
{
    $out = '';
    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m) === 0) {
        return '';
    }
    foreach ($m[1] as $bruto) {
        $plano = @gzuncompress($bruto);
        if ($plano === false) {
            $plano = @gzinflate($bruto);
        }
        if ($plano !== false) {
            $out .= $plano . "\n";
        }
    }

    return $out;
}

/**
 * Operadores de texto de un flujo, con su X e Y crudas.
 *
 * TCPDF emite "BT x y Td [(texto)] TJ ET" EN UNA SOLA LINEA (tcpdf.php:5584),
 * con BT y las coordenadas DELANTE del texto: por eso el patron NO se ancla al
 * principio de linea. Anclarlo fue el defecto que una vez devolvio cero
 * operandos.
 *
 * @return list<array{texto:string, x:float, y:float}>
 */
function operadores(string $flujo): array
{
    $ops = [];
    foreach (preg_split('/\r?\n/', $flujo) ?: [] as $linea) {
        $n = preg_match_all(
            '/BT\s+([\d.-]+)\s+([\d.-]+)\s+Td\s*(\[.*?\]\s*TJ|<[0-9A-Fa-f]+>\s*Tj|\(.*?\)\s*Tj)\s*ET/s',
            $linea, $bloques, PREG_SET_ORDER
        );
        if ($n === 0) {
            continue;
        }
        foreach ($bloques as $b) {
            $texto = cargaUtil($b[3]);
            if (trim($texto) !== '') {
                $ops[] = ['texto' => $texto, 'x' => (float) $b[1], 'y' => (float) $b[2]];
            }
        }
    }

    return $ops;
}

/** Texto de un operador, en cualquiera de las tres formas que emite TCPDF. */
function cargaUtil(string $op): string
{
    $texto = '';
    if (preg_match_all('/<([0-9A-Fa-f]+)>/', $op, $mm)) {
        foreach ($mm[1] as $h) {
            $texto .= binAUtf((string) hex2bin(strlen($h) % 2 === 0 ? $h : '0' . $h));
        }
    }
    // El [^\\\\)] evita cortar en un \) escapado.
    if (preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)/s', $op, $mm)) {
        foreach ($mm[1] as $t) {
            $texto .= binAUtf(desescapar($t));
        }
    }

    return $texto;
}

/** Desescapa una cadena literal de PDF: \( \) \\ y octales \ddd. */
function desescapar(string $s): string
{
    return (string) preg_replace_callback(
        '/\\\\([nrtbf()\\\\]|[0-7]{1,3})/',
        static fn (array $m): string => match ($m[1]) {
            'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C",
            '(' => '(', ')' => ')', '\\' => '\\',
            default => chr(octdec($m[1])),
        },
        $s
    );
}

/** Con fuente unicode TCPDF escribe los bytes en UTF-16BE dentro del literal. */
function binAUtf(string $bin): string
{
    if (str_contains($bin, "\x00")) {
        $conv = @iconv('UTF-16BE', 'UTF-8//IGNORE', $bin);
        if ($conv !== false) {
            return $conv;
        }
    }

    return $bin;
}

// ===========================================================================
// GENERACION
// ===========================================================================
titulo('GENERACION');

$escala   = null;
$altoHoja = null;

/**
 * Un DTE sintetico de $n lineas.
 *
 * LA RAZON SOCIAL ES CORTA A PROPOSITO: con una larga, la cascada baja el cuerpo
 * y el bloque del emisor puede pasar de 50 mm, con lo que setY(max(50, ...))
 * toma finEmisor y la calibracion contra 50 dejaria de valer. Con este emisor el
 * piso manda y la Y de "Emision" es exactamente 50,00.
 *
 * SIN TIMBRE ($ted = null): no hace falta un CAF para medir donde llega la tabla,
 * y el PDF417 solo agregaria peso. OJO: el acuse SI se dibuja igual (tipo 33 no
 * esta en $sinAcuseRecibo), que es justamente uno de los elementos que la tabla
 * pisa.
 */
function documento(int $n): string
{
    global $escala, $altoHoja;

    $detalle = [];
    for ($i = 1; $i <= $n; $i++) {
        $detalle[] = [
            // MARCA IDENTIFICABLE: es lo que permite medir sin adivinar por
            // coordenadas, porque la tabla y el acuse se solapan en X.
            'NmbItem'   => sprintf('ITEM-%03d insumo de prueba', $i),
            'QtyItem'   => 1,
            'PrcItem'   => 1000,
            'MontoItem' => 1000,
        ];
    }

    $dte = [
        'Encabezado' => [
            'Emisor' => [
                'RUTEmisor'  => '77724622-4',
                'RznSoc'     => 'SinergIA SpA',
                'GiroEmis'   => 'SERVICIOS',
                'DirOrigen'  => 'CALLE 1',
                'CmnaOrigen' => 'VALDIVIA',
            ],
            'Receptor' => [
                'RUTRecep'    => '76192083-9',
                'RznSocRecep' => 'CLIENTE DE PRUEBA',
                'GiroRecep'   => 'PRUEBAS',
                'DirRecep'    => 'CALLE 2',
                'CmnaRecep'   => 'VALDIVIA',
            ],
            'IdDoc'   => ['TipoDTE' => 33, 'Folio' => 1, 'FchEmis' => '2026-08-10'],
            'Totales' => ['MntNeto' => 1000 * $n, 'TasaIVA' => 19,
                          'IVA' => (int) round(1000 * $n * 0.19), 'MntTotal' => (int) round(1000 * $n * 1.19)],
        ],
        'Detalle' => $detalle,
    ];

    new DtePdfGenerator();   // registra el autoloader de LibreDTE
    $pdf = new DtePdfDocumento();
    $pdf->setResolucion(['NroResol' => '0', 'FchResol' => '2026-01-01']);
    $pdf->agregar($dte, null);

    // ANTES de Output(): despues, _destroy(false) deja h y k en 0 y toda
    // conversion de Y da division por cero o numeros absurdos.
    if ($escala === null) {
        $escala   = $pdf->getScaleFactor();
        $altoHoja = $pdf->getPageHeight();
    }

    $salida = (string) $pdf->Output('', 'S');
    unset($pdf);
    gc_collect_cycles();

    return $salida;
}

$primero = documento(1);
if ($escala === null || $escala <= 0.0 || $altoHoja === null || $altoHoja <= 0.0) {
    morir(sprintf('escala=%s altoHoja=%s: se leyeron DESPUES de Output(). Sin esos dos '
        . 'numeros ninguna Y es interpretable.', var_export($escala, true), var_export($altoHoja, true)));
}
printf("  getScaleFactor() = %.4f puntos/mm\n", $escala);
printf("  getPageHeight()  = %.2f mm\n", $altoHoja);
if (abs($altoHoja - 297.0) < 0.5) {
    ok("la hoja es A4 (297 mm), como quedo medido: el formato 'Letter' que pide "
        . 'LibreDTE no existe con esa capitalizacion y cae en A4.');
} else {
    printf("  [AVISO]   la hoja mide %.2f mm y se esperaba A4.\n", $altoHoja);
}

// --- CALIBRACION DE LA Y ---
//
// El Td no da la Y de la celda: da la LINEA BASE, unos milimetros mas abajo. Se
// mide el desfase contra un texto cuya Y de llamada se conoce: 'Emisión', que
// escribe agregarFechaEmision() justo despues del setY(max(50, finEmisor)).
$paginas = flujosPorPagina($primero);
if ($paginas === []) {
    morir('el documento de 1 linea no produjo ninguna pagina con texto. Fallo del arnes.');
}
$ops = operadores($paginas[0]);
if ($ops === []) {
    morir('CERO operandos de texto. Fallo del arnes (el decodificador), no del PDF: '
        . 'TCPDF emite "BT x y Td [(texto)] TJ ET" en UNA linea, con BT delante.');
}

$desfase = null;
foreach ($ops as $o) {
    // 'Emisi' SIN TILDE: el texto viaja en UTF-16BE y la tilde ya salio rota en
    // volcados anteriores. Es el prefijo mas corto que identifica la etiqueta.
    if (stripos($o['texto'], 'Emisi') !== false) {
        $desfase = ($altoHoja - $o['y'] / $escala) - 50.0;
        break;
    }
}
if ($desfase === null) {
    echo "\n  TEXTOS DECODIFICADOS (para diagnosticar):\n";
    foreach (array_slice($ops, 0, 20) as $i => $o) {
        printf("      %2d  %s\n", $i, mb_substr(trim($o['texto']), 0, 60));
    }
    morir("no se encontro 'Emisi' para calibrar. Sin calibrar, ninguna Y es interpretable.");
}
printf("  DESFASE DE LINEA BASE (contra Y de llamada 50,00): %+.2f mm\n", $desfase);
if (abs($desfase) > 8.0) {
    printf("  [AVISO]   desfase de %.2f mm: comprueba que el bloque del emisor no pase de 50.\n", $desfase);
}

/** Y en mm desde arriba, ya calibrada. */
function yMm(float $yTd): float
{
    global $escala, $altoHoja, $desfase;

    return $altoHoja - ($yTd / $escala) - $desfase;
}

// ===========================================================================
// 1. EL PUNTO DE QUIEBRE
// ===========================================================================
titulo('1. PUNTO DE QUIEBRE DEL DETALLE');

// Las coordenadas del mobiliario, leidas del codigo y no supuestas:
//   agregarTimbre(..., $y = 190)          -> el PDF417 arranca en 190
//   agregarAcuseRecibo($x=93, $y=190, $h=40) -> el recuadro ocupa 190..230
//   agregarLeyendaDestino($y = 245)       -> CEDIBLE
const Y_MOBILIARIO = 190.0;

echo "  El mobiliario del pie arranca en y=" . Y_MOBILIARIO . " mm (timbre y acuse).\n";
echo "  Se busca el primer N cuyo detalle CRUZA esa linea en la pagina 1.\n\n";
echo "      N   paginas  ultimo ITEM en pag.1  Y max (mm)  cruza 190\n";
echo "      --  -------  --------------------  ----------  ---------\n";

$quiebre = null;
$maximos = [];

foreach (range(1, 45) as $n) {
    $pdf     = documento($n);
    $paginas = flujosPorPagina($pdf);
    $ops     = operadores($paginas[0] ?? '');

    $yMax = 0.0;
    $ultimo = '';
    foreach ($ops as $o) {
        if (stripos(trim($o['texto']), 'ITEM-') !== 0) {
            continue;
        }
        $y = yMm($o['y']);
        if ($y > $yMax) {
            $yMax = $y;
            $ultimo = trim($o['texto']);
        }
    }
    $maximos[$n] = $yMax;
    $cruza = $yMax > Y_MOBILIARIO;

    printf("      %2d  %7d  %-20s  %10.1f  %s\n",
        $n, count($paginas), mb_substr($ultimo, 0, 20) ?: '(ninguno)', $yMax, $cruza ? 'SI' : 'no');

    if ($cruza && $quiebre === null) {
        $quiebre = $n;
    }
    unset($pdf, $paginas, $ops);
    gc_collect_cycles();

    // Con dos paginas ya se vio lo que habia que ver: si TCPDF empezo a paginar,
    // la pagina 1 deja de crecer.
    if (count($maximos) > 3 && $quiebre !== null && $n >= $quiebre + 3) {
        break;
    }
}

echo "\n";
if ($quiebre === null) {
    echo "  [DATO]    con hasta 45 lineas el detalle NUNCA cruzo y=190 en la pagina 1.\n";
    echo "            O TCPDF ya esta paginando antes, o el defecto necesita mas\n";
    echo "            lineas de las probadas. Mira la columna 'paginas'.\n";
} else {
    dato("EL PUNTO DE QUIEBRE ES N = {$quiebre}: con esa cantidad de lineas el detalle "
        . 'empieza a dibujarse sobre el timbre y el acuse.');
    printf("            Con %d lineas el detalle llega a %.1f mm; el mobiliario arranca en %.0f.\n",
        $quiebre, $maximos[$quiebre], Y_MOBILIARIO);
    printf("            Invasion: %.1f mm.\n", $maximos[$quiebre] - Y_MOBILIARIO);
    echo "            OJO: este N es una COTA SUPERIOR, no el primer documento afectado.\n";
    echo "            La Y de la columna es la linea base del TEXTO del ultimo item; el\n";
    echo "            borde inferior de la tabla queda unos milimetros mas abajo, asi que\n";
    echo "            hay documentos mas cortos que ya rozan el pie sin que su texto cruce\n";
    echo "            190. El umbral que manda para el arreglo es el de la verificacion 1,\n";
    echo "            que mide donde queda el pie en vez de donde llega el texto.\n";
}

// ===========================================================================
// 2. LA LINEA BASE DE INERCIA
// ===========================================================================
titulo('2. LINEA BASE DE INERCIA (1, 2 y 3 lineas)');

echo "  ESTOS md5 SON EL CONTRATO DEL ARREGLO: despues de tocar agregar(), un\n";
echo "  documento de 1, 2 o 3 lineas tiene que producir EXACTAMENTE el mismo\n";
echo "  flujo. Si cambia uno solo, el arreglo movio algo que ya funcionaba.\n\n";
echo "      N   bytes flujo  md5\n";
echo "      --  -----------  --------------------------------\n";

$base = [];
foreach ([1, 2, 3] as $n) {
    $flujo = flujoDescomprimido(documento($n));
    if ($flujo === '') {
        morir("con {$n} lineas el flujo salio VACIO. Fallo del arnes (descompresion).");
    }
    $base[$n] = md5($flujo);
    printf("      %2d  %11d  %s\n", $n, strlen($flujo), $base[$n]);
    unset($flujo);
    gc_collect_cycles();
}

// Determinismo: si dos corridas iguales no coinciden, estos md5 no sirven de
// linea base para nada.
$repeticion = md5(flujoDescomprimido(documento(2)));
if ($repeticion === $base[2]) {
    ok('el flujo es DETERMINISTA: dos corridas de 2 lineas dan el mismo md5. '
        . 'La linea base es utilizable.');
} else {
    echo "  [FALLA]   dos corridas iguales dan md5 distintos ({$base[2]} vs {$repeticion}).\n";
    echo "            Hay algo variable en el dibujo y la inercia no se puede medir.\n";
}

// ===========================================================================
// VERIFICACION DEL ARREGLO
//
// Las cuatro cosas que el cambio de agregar() tiene que cumplir. Se miden sobre
// el MISMO documento sintetico, con la misma calibracion.
// ===========================================================================
titulo('VERIFICACION DEL ARREGLO DEL PIE');

$fallos = 0;
$malito = static function (string $m) use (&$fallos): void {
    $fallos++;
    echo "  [FALLA]   {$m}\n";
};

/**
 * Donde arranca el pie en un documento de $n lineas.
 *
 * SE UBICA POR SU TEXTO Y NO POR SU POSICION -- que es justo lo que se esta
 * midiendo. Buscar por coordenada seria dar por supuesta la respuesta.
 *
 * -------------------------------------------------------------------------
 * LA SONDA ES EL ACUSE, **NO** EL TIMBRE. ESTE ARNES LO TUVO MAL Y POR ESO
 * DIO UN FALLO FALSO.
 *
 * agregarTimbre() escribe su rotulo asi:
 *     $this->write2DBarcode($timbre, 'PDF417', $x, $y, $w, 0, $style, 'B');
 *     $this->Texto('Timbre Electronico SII', $x, $this->y, 'C', $w);
 *                                                 ^^^^^^^^^
 * O sea en $this->y, NO en $y. Normalmente da igual, porque write2DBarcode()
 * con align 'B' deja el cursor justo bajo el codigo de barras. Pero la
 * siembra de este arnes NO LLEVA TIMBRE ($ted = null), y tcpdf.php:15711
 * arranca con:
 *     if (TCPDF_STATIC::empty_string(trim($code))) { return; }
 * Retorna ANTES de tocar $this->y. Asi que el rotulo cae donde lo dejo lo
 * ultimo que se dibujo -- agregarTotales() -- y no donde arranca el pie.
 *
 * ESA ERA LA MEDICION: el "202,5 constante" que se reporto como el defecto
 * del arreglo anterior no era la posicion del pie, era el fin del bloque de
 * totales dibujado desde 190. Y el "214,1" de N=25 es ese mismo desfase
 * aplicado a un pie que en realidad estaba mucho mas arriba.
 *
 * El acuse no tiene ese problema. agregarAcuseRecibo() hace:
 *     $this->Texto('Acuse de recibo', $x, $y+1, 'C', $w);
 * con la Y EXPLICITA. Restarle 1 da el $yPie exacto con el que se llamo.
 * -------------------------------------------------------------------------
 *
 * @return array{pie:?float, timbre:?float, acuse:?float, cedible:?float, paginas:int, yMaxItem:float, paginaPie:int}
 */
function medirPie(int $n): array
{
    $pdf     = documento($n);
    $paginas = flujosPorPagina($pdf);
    $r = ['pie' => null, 'timbre' => null, 'acuse' => null, 'cedible' => null,
          'paginas' => count($paginas), 'yMaxItem' => 0.0, 'paginaPie' => 0];

    foreach ($paginas as $idx => $flujo) {
        foreach (operadores($flujo) as $o) {
            $y = yMm($o['y']);
            $t = trim($o['texto']);

            if (stripos($t, 'ITEM-') === 0 && $y > $r['yMaxItem'] && $idx === 0) {
                $r['yMaxItem'] = $y;
            }
            // 'Timbre' sin tilde ni acentos: el texto viaja en UTF-16BE y una
            // tilde rota rompeeria la busqueda. Se sigue leyendo, pero solo
            // como dato informativo: la sonda buena es el acuse.
            if ($r['timbre'] === null && stripos($t, 'Timbre') === 0) {
                $r['timbre'] = $y;
            }
            if ($r['acuse'] === null && stripos($t, 'Acuse de recibo') === 0) {
                // El +1 es el que pone agregarAcuseRecibo() al escribir el
                // titulo dentro del recuadro.
                $r['acuse'] = $y;
                $r['pie'] = $y - 1.0;
                $r['paginaPie'] = $idx + 1;
            }
            if ($r['cedible'] === null && stripos($t, 'CEDIBLE') === 0) {
                $r['cedible'] = $y;
            }
        }
    }
    unset($pdf, $paginas);
    gc_collect_cycles();

    return $r;
}

// --- 1. HASTA DONDE EL PIE NO SE MUEVE, Y QUE ESO SEA MONOTONO ---
//
// LA VERSION ANTERIOR DE ESTA COMPROBACION EXIGIA 190 EN N=25 Y ESO ESTABA MAL.
// Salia de leer la tabla de arriba, donde el ultimo ITEM de N=25 cae en ~186,7 y
// por lo tanto "todavia no cruza 190". Pero esa Y es la LINEA BASE DEL TEXTO del
// ultimo item, no el fin de la tabla: debajo quedan el resto de la celda, el
// borde inferior y el salto de linea que addTable() agrega al cerrar
// (writeHTML($buffer, true, ...) -- el segundo argumento es justamente el Ln).
// El cursor real queda varios milimetros mas abajo, asi que en N=25 el contenido
// YA pasa de 190 y el pie TIENE que bajar. Exigirle 190 era pedirle al arreglo
// que reprodujera el defecto.
//
// Asi que el umbral no se supone: SE MIDE. Se recorre N hacia arriba, se anota
// donde queda el pie y se comprueban las dos propiedades que si son exigibles:
//   - mientras el contenido cabe, el pie esta EXACTAMENTE en 190 (inercia);
//   - de ahi en adelante nunca vuelve a subir (monotonia).
// La inercia byte a byte la firma la verificacion 3, que es la que manda.
echo "  1. UMBRAL DE INERCIA DEL PIE (medido, no supuesto)\n";
echo "     Se busca el ultimo N cuyo pie sigue en y=190.\n\n";
echo "      N   pagina  ultimo ITEM  y del pie  timbre (informativo)\n";
echo "      --  ------  -----------  ---------  --------------------\n";

$pies    = [];
$umbral  = null;
$rompeMonotonia = null;
$previo  = null;
foreach (range(1, 30) as $n) {
    $p = medirPie($n);
    $pies[$n] = $p['pie'];
    printf("      %2d  %6d  %11.1f  %9s  %s\n",
        $n, $p['paginaPie'], $p['yMaxItem'],
        $p['pie'] !== null ? sprintf('%.1f', $p['pie']) : 'NO HALLADO',
        $p['timbre'] !== null ? sprintf('%.1f', $p['timbre']) : '?');

    if ($p['pie'] === null) {
        $malito("N={$n}: no se encontro el acuse, que es la sonda de la posicion del pie.");
        continue;
    }
    if (abs($p['pie'] - Y_MOBILIARIO) < 0.05) {
        $umbral = $n;
    }
    // Solo tiene sentido comparar dentro de la misma pagina: cuando el pie salta
    // de hoja vuelve a 190 por diseño, y eso no es "subir".
    if ($previo !== null && $p['paginaPie'] === 1 && $p['pie'] < $previo - 0.05) {
        $rompeMonotonia ??= $n;
    }
    if ($p['paginaPie'] === 1) {
        $previo = $p['pie'];
    }
    gc_collect_cycles();
}

echo "\n";
if ($umbral === null) {
    $malito('NINGUN N deja el pie en 190. El arreglo movio hasta el documento mas corto: '
        . 'el max() no esta preservando la inercia.');
} else {
    dato("hasta N = {$umbral} lineas el pie queda EXACTAMENTE en y=190; desde N = "
        . ($umbral + 1) . ' empieza a bajar con el contenido.');
    // El unico compromiso duro sobre el tramo de inercia: los documentos de 1, 2
    // y 3 lineas -- los del contrato md5 -- tienen que estar dentro de el.
    if ($umbral >= 3) {
        ok('los tres documentos del contrato md5 (1, 2 y 3 lineas) caen dentro del tramo '
            . 'que no se mueve.');
    } else {
        $malito("el umbral es N={$umbral}, por debajo de las 3 lineas del contrato md5: "
            . 'la verificacion 3 no puede pasar.');
    }
}
if ($rompeMonotonia !== null) {
    $malito(sprintf('en N=%d el pie SUBE respecto de N=%d dentro de la misma pagina. La '
        . 'posicion del pie tiene que crecer con el contenido, nunca retroceder.', $rompeMonotonia, $rompeMonotonia - 1));
} else {
    ok('el pie nunca retrocede: crece con el contenido y salta de pagina cuando no cabe.');
}

// --- 1b. EL PIE TIENE QUE MOVERSE CON EL CONTENIDO ---
//
// ESTA COMPROBACION NACE DE UN FALLO REAL: una version del arreglo leia getY()
// DESPUES de agregarTotales() -- que hace setY(190) -- y por lo tanto medía el
// fin del bloque de totales, un valor casi constante, en vez del fin de la tabla.
// Un pie que no se mueve entre 1 y 40 lineas es la firma exacta de ese defecto, y
// ninguna de las otras verificaciones lo veria: miran superposicion, no variacion.
echo "\n  1b. EL PIE VARIA CON EL CONTENIDO (no es casi constante)\n";
$posiciones = [];
foreach ([1, 25, 30, 40] as $n) {
    $p = medirPie($n);
    $posiciones[$n] = $p['pie'];
    printf("      N=%-3d pagina del pie=%d  y del pie=%s\n",
        $n, $p['paginaPie'], $p['pie'] !== null ? sprintf('%.1f', $p['pie']) : '?');
}
$conocidas = array_filter($posiciones, static fn ($v) => $v !== null);
if (count($conocidas) < 2) {
    $malito('no se pudo ubicar el pie en suficientes documentos para comparar.');
} else {
    $rango = max($conocidas) - min($conocidas);
    printf("      rango entre el menor y el mayor: %.1f mm\n", $rango);
    // Con 1 linea el pie va en 190 y con 40 tiene que estar mucho mas abajo o en
    // otra pagina. Si todos caen dentro de un par de milimetros, se esta leyendo
    // una posicion fija.
    if ($rango < 2.0) {
        $malito(sprintf('el pie queda practicamente en la MISMA y (~%.1f) con 1 y con 40 '
            . 'lineas: se esta leyendo una posicion fija, no el fin de la tabla. Revisa que '
            . 'getY() se lea ANTES de agregarTotales().', min($conocidas)));
    } else {
        ok('el pie cambia de sitio segun el contenido: se esta leyendo el fin real de la tabla.');
    }
}

// --- 2. SOBRE EL QUIEBRE: YA NO SE SUPERPONEN ---
//
// OJO CON LO QUE MIDE LA COLUMNA "ITEM hasta y": es la linea base del texto del
// ultimo item, no el borde inferior de la tabla, que queda unos milimetros mas
// abajo. O sea que "pie > ultimo ITEM" es condicion NECESARIA pero holgada. Por
// eso se imprime la separacion en crudo: si saliera de 1 o 2 mm habria que
// mirarla aunque el [OK] este puesto.
echo "\n  2. DOCUMENTOS DE 26 LINEAS EN ADELANTE: la tabla ya no pisa el pie\n";
foreach ([26, 30, 40] as $n) {
    $p = medirPie($n);
    $sep = ($p['pie'] !== null && $p['paginaPie'] === 1)
        ? $p['pie'] - $p['yMaxItem'] : null;
    printf("      N=%-3d paginas=%d  ITEM hasta y=%.1f  pie en y=%s (pagina %d)  separacion=%s\n",
        $n, $p['paginas'], $p['yMaxItem'],
        $p['pie'] !== null ? sprintf('%.1f', $p['pie']) : '?',
        $p['paginaPie'],
        $sep !== null ? sprintf('%.1f mm', $sep) : 'pie en otra pagina');

    if ($p['pie'] === null) {
        $malito("N={$n}: no se encontro el pie en ninguna pagina.");
        continue;
    }
    // O el pie esta en otra pagina que los items de la 1, o esta debajo de
    // ellos. Lo que no puede es estar ENCIMA.
    if ($p['paginaPie'] > 1 || $p['pie'] > $p['yMaxItem']) {
        ok("N={$n}: el pie queda por debajo del detalle, sin superposicion.");
    } else {
        $malito(sprintf('N=%d: el pie esta en y=%.1f y el detalle llega a y=%.1f -- SE SIGUEN '
            . 'PISANDO.', $n, $p['pie'], $p['yMaxItem']));
    }
    // Y el acuse tiene que caber entero: si arranca tan abajo que se sale de la
    // hoja util, se cambio un defecto por otro.
    global $altoHoja;
    if ($p['acuse'] !== null && $p['acuse'] + 40 > $altoHoja) {
        $malito(sprintf('N=%d: el acuse arranca en %.1f y con sus 40 mm se sale de la hoja.',
            $n, $p['acuse']));
    }
}

// --- 3. LOS TRES md5 DE CONTRATO ---
//
// Los valores estan escritos aqui porque son EL CONTRATO acordado antes de tocar
// el codigo, no un resultado de esta corrida. Si alguno cambia, el arreglo movio
// el camino normal.
echo "\n  3. LOS md5 DE CONTRATO (1, 2 y 3 lineas)\n";
$contrato = [
    1 => '351fcc25688ae9ab3208adef755b199f',
    2 => 'c3a1f93ce7498878c36388be34c7c2ce',
    3 => 'ce69d2f13cc28570980eff525cef2e03',
];
foreach ($contrato as $n => $esperado) {
    $actual = md5(flujoDescomprimido(documento($n)));
    printf("      %d linea(s)  esperado %s  actual %s  %s\n",
        $n, $esperado, $actual, $actual === $esperado ? 'IGUAL' : 'DISTINTO');
    if ($actual !== $esperado) {
        $malito("con {$n} linea(s) el flujo CAMBIO. El arreglo del pie no puede tocar el "
            . 'camino normal: revisa el max() y la distancia de CEDIBLE.');
    }
    gc_collect_cycles();
}
if ($fallos === 0) {
    ok('los tres documentos cortos salen byte a byte iguales que antes del arreglo.');
}

// --- 4. MEMORIA ---
echo "\n  4. MEMORIA\n";
$pico = memory_get_peak_usage(true) / 1048576;
printf("      pico %.1f MB, limite %s\n", $pico, ini_get('memory_limit'));
if ($pico < 256 * 0.75) {
    ok(sprintf('el pico (%.1f MB) queda holgado bajo el techo de 256 MB.', $pico));
} else {
    echo "  [AVISO]   el pico pasa del 75% del techo: sube el techo CON ESTE DATO o "
        . "reduce los N probados.\n";
}

printf("\n  fallos de la verificacion: %d\n", $fallos);

// ===========================================================================
titulo('RESUMEN');
printf("  ext-intl: %s\n", $intl ? 'DISPONIBLE' : 'NO disponible');
printf("  punto de quiebre: %s\n", $quiebre !== null ? "N = {$quiebre} lineas" : 'no alcanzado con 45');
echo "  md5 base (guardar para comparar despues del arreglo):\n";
foreach ($base as $n => $m) {
    printf("      %d linea(s): %s\n", $n, $m);
}
printf("\n  MEMORIA: pico %.1f MB (limite %s)\n",
    memory_get_peak_usage(true) / 1048576, ini_get('memory_limit'));

echo "\n  ESTE ARNES NO CAMBIA NADA. agregar() sigue intacto.\n";
