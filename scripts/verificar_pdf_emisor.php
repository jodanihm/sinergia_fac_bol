<?php

declare(strict_types=1);

/**
 * ARNES DE VERIFICACION: bloque del emisor con logo a 40 mm.
 *
 * El nombre queda al lado del logo con 65 mm; el giro, la direccion y el
 * contacto bajan a todo el ancho (115 mm desde x=10) por debajo de la imagen.
 *
 * NO TOCA NINGUNA BASE, NO LLAMA AL SII Y NO LEE dte_logo: los logos de prueba
 * se GENERAN aqui con GD, en memoria. No escribe ningun archivo permanente.
 *
 * ------------------------------------------------------------------------
 * COMO PREPARARLO (git NO existe dentro del contenedor):
 *
 *   cd Y:/webserver/sinergia_fac_bol
 *   git show HEAD:src/Pdf/DtePdfDocumento.php > scripts/HEAD_DtePdfDocumento.php
 *
 * Y DESPUES SE BORRA A MANO, POR RUTA EXPLICITA:
 *
 *   rm scripts/HEAD_DtePdfDocumento.php
 *
 * Si falta, el script ABORTA EN LA PANTALLA 0. UNA SOLA CORRIDA basta: HEAD y el
 * arbol se comparan dentro del mismo proceso (ver la nota del shim mas abajo).
 *
 * El script escribe UN archivo temporal, src/Pdf/HEAD_AB_DtePdfDocumento.php, y
 * lo borra al terminar pase lo que pase (register_shutdown_function, por ruta
 * explicita). Si una corrida anterior murio dejandolo, ABORTA en vez de
 * pisarlo.
 * ------------------------------------------------------------------------
 *
 * LO QUE ESTE ARNES YA APRENDIO A NO HACER, y esta escrito para que no se
 * repita:
 *
 *  - LA ESCALA SE LEE ANTES DE Output(). Output() llama a Close() y a
 *    _destroy(false), que hace unset de todas las propiedades salvo doce; ni h
 *    ni k estan entre ellas, asi que getPageHeight() y getScaleFactor()
 *    devuelven 0,0 despues de serializar y toda conversion de Y da division por
 *    cero o numeros absurdos.
 *
 *  - LA Y SE CALIBRA CONTRA UN VALOR CONOCIDO antes de creer ninguna medicion.
 *    El operador Td no da la Y de la celda: da la LINEA BASE del texto, que cae
 *    unos milimetros mas abajo. Aqui se mide el desfase con un texto cuya Y de
 *    llamada conocemos y se aplica a todo lo demas.
 *
 *  - SE VUELCA EL DATO CRUDO, NO UNA CLASIFICACION. Cada operador de texto sale
 *    con su tamaño de fuente Y SU X. Una heuristica que decide sola "esto es la
 *    razon social" ya produjo dos veces numeros que se contradecian entre si.
 *
 *  - EL TEXTO DE TCPDF VA EN HEXADECIMAL (<hex> Tj y [<hex> ... ] TJ), no entre
 *    parentesis. Si el decodificador devuelve cero operandos, eso es un FALLO
 *    DEL ARNES y se declara como tal, no como "la linea no esta".
 *
 *  - LA INERCIA SE MIDE SOBRE EL FLUJO DESCOMPRIMIDO, nunca sobre el archivo:
 *    TCPDF escribe /CreationDate, /ModDate e /ID, que cambian en cada corrida.
 *
 *  - HEAD Y EL ARBOL CORREN EN LA MISMA PASADA. Se cargan los bytes de HEAD
 *    sustituyendo UNICAMENTE la linea del namespace, y conviven como
 *    AbEmisorHead\DtePdfDocumento y Plantiflex\FacturacionCl\Pdf\DtePdfDocumento.
 *    Es la misma tecnica de los A/B de certificacion, del panel de emision y del
 *    logo. Que la sustitucion fue la unica se demuestra comparando el md5 del
 *    cuerpo sin esa linea, no se afirma. El shim se escribe en src/Pdf/, a la
 *    MISMA profundidad que el original, y se borra siempre.
 */

// ===========================================================================
// TECHO DE MEMORIA. VA ANTES DE CUALQUIER OTRA COSA.
//
// Este script construye DOCE PDF en un solo proceso (seis variantes por dos
// versiones de la clase), mas los tres de las verificaciones con logo. Cada uno
// arrastra un objeto TCPDF con su PDF417 y, en los casos con logo, una imagen
// decodificada por GD.
//
// POR QUE UN LIMITE EXPLICITO Y NO EL DEL CONTENEDOR. La imagen del motor fija
// memory_limit=256M en conf.d/zz-limites.ini (docker/Dockerfile.motor:48), pero
// esto se corre con `docker exec`, y ahi no se puede dar por sentado que el
// cgroup del contenedor acote al proceso igual que a los que arranca el
// entrypoint. Un arnes que crece sin techo deja de ser una herramienta de
// medicion y pasa a ser un riesgo para la maquina que lo hospeda.
//
// 256M es el mismo numero que ya usa el motor en produccion: sobra para un PDF
// a la vez -- el pico real se imprime al final de cada corrida, asi que este
// numero se puede revisar CON DATOS en vez de a ojo -- y muere mucho antes que
// la maquina. Si algun dia falta, que falle este proceso con un mensaje claro.
//
// NOTA HISTORICA, para que nadie saque la conclusion equivocada: el dia que se
// escribio esto el servidor de desarrollo se colgo justo despues de lanzar el
// arnes, y NO FUE EL ARNES -- el log de arranque mostraba "Pool 'boot-pool' has
// encountered an uncorrectable I/O failure and has been suspended": fallo el
// disco de arranque y ZFS suspendio el pool. Fue coincidencia de tiempo. El
// techo se pone igual, porque la escopeta estaba cargada aunque no disparara.
ini_set('memory_limit', '256M');

$RAIZ = dirname(__DIR__);
require $RAIZ . '/vendor/autoload.php';

$fallos = 0;
$avisos = 0;

function titulo(string $t): void
{
    echo "\n", str_repeat('=', 78), "\n", $t, "\n", str_repeat('=', 78), "\n";
}
function ok(string $m): void
{
    echo "  [OK]      {$m}\n";
}
function mal(string $m): void
{
    global $fallos;
    $fallos++;
    echo "  [FALLA]   {$m}\n";
}
function aviso(string $m): void
{
    global $avisos;
    $avisos++;
    echo "  [AVISO]   {$m}\n";
}
function morir(string $m): never
{
    echo "\n*** ABORTADO: {$m}\n";
    exit(2);
}

// ===========================================================================
// PANTALLA 0 - REQUISITOS
// ===========================================================================
titulo('PANTALLA 0 - REQUISITOS');

if (! function_exists('imagepng')) {
    morir('la extension GD no tiene imagepng(): no se pueden generar los logos de '
        . 'prueba. ARNES SIN CORRER -- no es un fallo de la entrega.');
}
ok('GD con imagepng() disponible.');

$rutaHead = __DIR__ . '/HEAD_DtePdfDocumento.php';
if (! is_file($rutaHead)) {
    morir("falta {$rutaHead}. Lee la cabecera de este script.");
}
$txtHead = (string) file_get_contents($rutaHead);
$txtWork = (string) file_get_contents($RAIZ . '/src/Pdf/DtePdfDocumento.php');
printf("  HEAD   %7d bytes  md5 %s\n", strlen($txtHead), md5($txtHead));
printf("  TRABAJO %6d bytes  md5 %s\n", strlen($txtWork), md5($txtWork));
if ($txtHead === $txtWork) {
    morir('HEAD y el arbol son identicos: no hay nada que verificar.');
}
ok('HEAD y el arbol difieren.');

printf("  LogoEmpresa::ANCHO_DIBUJO_MM     = %s\n", \Plantiflex\FacturacionCl\Pdf\LogoEmpresa::ANCHO_DIBUJO_MM);
printf("  LogoEmpresa::MAX_PROPORCION_ALTO = %s\n", \Plantiflex\FacturacionCl\Pdf\LogoEmpresa::MAX_PROPORCION_ALTO);

// ===========================================================================
// PANTALLA 0b - EL SHIM DE HEAD: la misma clase, otro namespace, mismo proceso
// ===========================================================================
titulo('PANTALLA 0b - HEAD CARGADO EN OTRO NAMESPACE');

const NS_HEAD    = 'AbEmisorHead';
const CLASE_HEAD = NS_HEAD . '\\DtePdfDocumento';
const CLASE_WORK = \Plantiflex\FacturacionCl\Pdf\DtePdfDocumento::class;

// EL SHIM VA EN src/Pdf/, AL LADO DEL ORIGINAL Y A LA MISMA PROFUNDIDAD.
//
// No en /tmp y no en scripts/: cualquier ruta relativa o __DIR__ del codigo
// cargado resolveria a otro sitio y el shim dejaria de ser HEAD para pasar a ser
// "HEAD corriendo en otra parte". Ya nos mordio en el arnes del logo.
$rutaShim = $RAIZ . '/src/Pdf/HEAD_AB_DtePdfDocumento.php';

// Se borra al terminar PASE LO QUE PASE -- tambien si algo mas abajo aborta.
// Por ruta explicita, nunca por patron.
register_shutdown_function(static function () use ($rutaShim): void {
    if (is_file($rutaShim)) {
        unlink($rutaShim);
    }
});
if (is_file($rutaShim)) {
    morir("ya existe {$rutaShim}: sobro de una corrida anterior que no limpio. "
        . 'Borralo a mano y vuelve a correr.');
}

// EL AUTOLOADER DE LibreDTE VA ANTES DEL require DEL SHIM. La clase declara
// extends \sasco\LibreDTE\PDF y el padre se resuelve AL INCLUIR, no al
// instanciar: sin el autoloader registrado, el require del shim muere.
// Se usa la misma via que produccion (el constructor de DtePdfGenerator).
new \Plantiflex\FacturacionCl\Pdf\DtePdfGenerator();

// SE SUSTITUYE UNICAMENTE LA LINEA DEL NAMESPACE. Todo lo demas son los bytes
// literales de HEAD.
$patronNs = '/^namespace\s+Plantiflex\\\\FacturacionCl\\\\Pdf;\s*$/m';
$cuantas  = preg_match_all($patronNs, $txtHead);
if ($cuantas !== 1) {
    morir("el volcado de HEAD tiene {$cuantas} lineas de namespace y se esperaba 1. "
        . 'La sustitucion no seria inequivoca.');
}
$txtShim = (string) preg_replace($patronNs, 'namespace ' . NS_HEAD . ';', $txtHead, 1);

// Y SE DEMUESTRA QUE FUE LA UNICA SUSTITUCION: mismo md5 quitando esa linea de
// los dos. Si tocara algo mas, el shim dejaria de ser HEAD y la comparacion de
// inercia no probaria nada.
$sinLaLineaNs = static fn (string $t): string => (string) preg_replace('/^namespace .*;\s*$/m', '', $t);
$md5CuerpoHead = md5($sinLaLineaNs($txtHead));
$md5CuerpoShim = md5($sinLaLineaNs($txtShim));
printf("  cuerpo de HEAD sin la linea de namespace : md5 %s\n", $md5CuerpoHead);
printf("  cuerpo del SHIM sin la linea de namespace: md5 %s\n", $md5CuerpoShim);
if ($md5CuerpoHead !== $md5CuerpoShim) {
    morir('el shim difiere de HEAD en algo mas que la linea del namespace.');
}
ok('la unica sustitucion fue la linea del namespace.');

if (file_put_contents($rutaShim, $txtShim) === false) {
    morir("no se pudo escribir el shim en {$rutaShim}.");
}
require $rutaShim;

if (! class_exists(CLASE_HEAD, false)) {
    morir('el shim se cargo pero ' . CLASE_HEAD . ' no existe. Revisa si HEAD '
        . 'referencia alguna clase de su propio namespace sin cualificar.');
}
ok('HEAD vive como ' . CLASE_HEAD . ' y el arbol como ' . CLASE_WORK . ', las dos a la vez.');

// Que sean DOS clases distintas y no la misma vista dos veces.
$rHead = new \ReflectionClass(CLASE_HEAD);
$rWork = new \ReflectionClass(CLASE_WORK);
printf("  HEAD    -> %s\n", $rHead->getFileName());
printf("  TRABAJO -> %s\n", $rWork->getFileName());
if ($rHead->getFileName() === $rWork->getFileName()) {
    morir('las dos clases salen del mismo archivo: el shim no se cargo.');
}
if ($rHead->getParentClass() === false || $rWork->getParentClass() === false
    || $rHead->getParentClass()->getName() !== $rWork->getParentClass()->getName()) {
    morir('las dos clases no comparten la misma clase padre: la comparacion no seria valida.');
}
ok('dos archivos distintos, misma clase padre (' . $rHead->getParentClass()->getName() . ').');

// ===========================================================================
// UTILIDADES
// ===========================================================================

/** PNG opaco de $w x $h px, generado en memoria. Sin archivos temporales. */
function logoPng(int $w, int $h): string
{
    $im = imagecreatetruecolor($w, $h);
    $fondo = imagecolorallocate($im, 200, 40, 40);
    imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, $fondo);
    // Una franja para que el PNG no sea un color plano comprimible a nada.
    $franja = imagecolorallocate($im, 250, 250, 250);
    imagefilledrectangle($im, 0, (int) ($h / 2), $w - 1, (int) ($h / 2) + max(1, (int) ($h / 10)), $franja);
    ob_start();
    imagepng($im);
    $bytes = (string) ob_get_clean();
    imagedestroy($im);

    return $bytes;
}

/**
 * Todos los flujos de contenido del PDF, descomprimidos y concatenados.
 * ESTE es el criterio de inercia, no el md5 del archivo.
 */
function flujoDescomprimido(string $pdf): string
{
    $out = '';
    $n = preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m);
    if ($n === 0) {
        return '';
    }
    foreach ($m[1] as $bruto) {
        $plano = @gzuncompress($bruto);
        if ($plano === false) {
            $plano = @gzinflate($bruto);
        }
        // Un stream que no descomprime es una fuente incrustada o una imagen:
        // no aporta al dibujo y se salta. Si se contara en crudo, sus bytes
        // moverian el md5 sin que nada del dibujo hubiera cambiado.
        if ($plano !== false) {
            $out .= $plano . "\n";
        }
    }

    return $out;
}

/**
 * Operadores de texto del flujo, con su tamaño de fuente y su X/Y crudas.
 *
 * TCPDF escribe el texto EN HEXADECIMAL. Se soportan las tres formas que emite:
 * <hex> Tj, (literal) Tj y el array de TJ con sus kernings.
 *
 * @return list<array{texto:string, pt:float, x:float, yTd:float}>
 */
function operadoresDeTexto(string $flujo): array
{
    $ops = [];
    $ptActual = 0.0;
    $x = 0.0;
    $y = 0.0;

    // Se recorre linea a linea: TCPDF emite un operador por linea.
    foreach (preg_split('/\r?\n/', $flujo) ?: [] as $linea) {
        if (preg_match('/\/F\d+\s+([\d.]+)\s+Tf/', $linea, $m)) {
            $ptActual = (float) $m[1];
        }
        if (preg_match('/([\d.-]+)\s+([\d.-]+)\s+Td/', $linea, $m)) {
            $x = (float) $m[1];
            $y = (float) $m[2];
        }
        if (preg_match('/1\s+0\s+0\s+1\s+([\d.-]+)\s+([\d.-]+)\s+Tm/', $linea, $m)) {
            $x = (float) $m[1];
            $y = (float) $m[2];
        }

        // --- EL OPERADOR DE TEXTO NO EMPIEZA LA LINEA. ---
        //
        // ESTO ES LO QUE SE ROMPIO, y es una linea, no el enfoque. La version que
        // funcionaba buscaba el operador EN CUALQUIER PARTE del flujo; esta lo
        // anclaba con /^\s*.../, o sea exigia que la linea EMPEZARA por <hex> o
        // por [(...)]. TCPDF nunca escribe eso: emite el bloque entero de una vez
        //
        //     BT <x> <y> Td [(<texto>)] TJ ET
        //
        // en UNA sola linea (tcpdf.php:5584), con BT y las coordenadas DELANTE del
        // texto. Con el ancla, ninguna linea casaba y salian cero operandos.
        //
        // Se saca el ancla y se usa preg_match_all, porque una misma linea puede
        // traer mas de un BT...ET: tcpdf.php:5602 concatena un segundo bloque al
        // mismo buffer para los caracteres superpuestos.
        //
        // Las coordenadas se toman DEL PROPIO BLOQUE cuando vienen en el (que es
        // el caso normal); el seguimiento de Td/Tm de mas arriba queda como
        // respaldo para los operadores que no van envueltos en BT...ET.
        $n = preg_match_all(
            '/BT\s+([\d.-]+)\s+([\d.-]+)\s+Td\s*(\[.*?\]\s*TJ|<[0-9A-Fa-f]+>\s*Tj|\(.*?\)\s*Tj)\s*ET/s',
            $linea,
            $bloques,
            PREG_SET_ORDER
        );
        if ($n > 0) {
            foreach ($bloques as $b) {
                $texto = cargaUtilDe($b[3]);
                if (trim($texto) !== '') {
                    $ops[] = [
                        'texto' => $texto,
                        'pt'    => $ptActual,
                        'x'     => (float) $b[1],
                        'yTd'   => (float) $b[2],
                    ];
                }
            }
            continue;
        }

        // Respaldo: operador suelto, sin BT...ET en la misma linea.
        if (preg_match('/(\[.*?\]\s*TJ|<[0-9A-Fa-f]+>\s*Tj|\(.*?\)\s*Tj)/s', $linea, $m)) {
            $texto = cargaUtilDe($m[1]);
            if (trim($texto) !== '') {
                $ops[] = ['texto' => $texto, 'pt' => $ptActual, 'x' => $x, 'yTd' => $y];
            }
        }
    }

    return $ops;
}

/**
 * Texto de un operador, sea cual sea la de las tres formas en que viene:
 * [(...)] TJ, <hex> Tj o (...) Tj.
 *
 * CON FUENTE UNICODE, TCPDF NO ESCRIBE HEXADECIMAL AQUI: escribe la cadena entre
 * parentesis con los bytes ESCAPADOS a la manera de PDF (\( \) \\ y octales),
 * y esos bytes son UTF-16BE. Por eso hay que desescapar ANTES de convertir: si
 * se convirtiera en crudo, un '\050' quedaria como cuatro caracteres.
 */
function cargaUtilDe(string $operador): string
{
    $texto = '';

    // Forma hexadecimal.
    if (preg_match_all('/<([0-9A-Fa-f]+)>/', $operador, $mm)) {
        foreach ($mm[1] as $h) {
            $texto .= hexAUtf($h);
        }
    }

    // Forma literal entre parentesis. El [^\\\\)] evita cortar en un \) escapado.
    if (preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)/s', $operador, $mm)) {
        foreach ($mm[1] as $t) {
            $texto .= binAUtf(desescapar($t));
        }
    }

    return $texto;
}

/** Desescapa una cadena literal de PDF: \( \) \\ \n \r \t y octales \ddd. */
function desescapar(string $s): string
{
    return (string) preg_replace_callback(
        '/\\\\([nrtbf()\\\\]|[0-7]{1,3})/',
        static function (array $m): string {
            return match ($m[1]) {
                'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C",
                '(' => '(', ')' => ')', '\\' => '\\',
                default => chr(octdec($m[1])),
            };
        },
        $s
    );
}

/** Bytes crudos a UTF-8: UTF-16BE si trae nulos intercalados, si no tal cual. */
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

/** Hexadecimal de TCPDF a texto legible (UTF-16BE con fuentes unicode). */
function hexAUtf(string $hex): string
{
    $bin = (string) hex2bin(strlen($hex) % 2 === 0 ? $hex : '0' . $hex);
    // Con subsetting, TCPDF emite dos bytes por caracter.
    if (strlen($bin) >= 2 && $bin[0] === "\x00") {
        $conv = @iconv('UTF-16BE', 'UTF-8//IGNORE', $bin);
        if ($conv !== false && trim($conv) !== '') {
            return $conv;
        }
    }

    return $bin;
}

// ===========================================================================
// PANTALLA 1 - GENERAR LOS DOCUMENTOS
// ===========================================================================
titulo('PANTALLA 1 - DOCUMENTOS DE PRUEBA');

// LA ESCALA Y EL ALTO SE LEEN ANTES DE Output(). Ver la nota de la cabecera.
$escala   = null;
$altoHoja = null;

/**
 * Construye un DTE de prueba y devuelve [pdf, escala, altoHoja].
 *
 * $logo  = bytes PNG con el prefijo '@' que espera TCPDF, o null.
 * $clase = CLASE_WORK (el arbol) o CLASE_HEAD (el shim). Las dos conviven.
 */
function construir(array $emisor, ?string $logo, string $clase = CLASE_WORK): array
{
    global $escala, $altoHoja;

    $dte = [
        'Encabezado' => [
            'Emisor'   => $emisor,
            'Receptor' => [
                'RUTRecep'   => '76192083-9',
                'RznSocRecep'=> 'CLIENTE DE PRUEBA DEL ARNES SPA',
                'GiroRecep'  => 'PRUEBAS',
                'DirRecep'   => 'CALLE FALSA 123',
                'CmnaRecep'  => 'VALDIVIA',
            ],
            'IdDoc'    => ['TipoDTE' => 33, 'Folio' => 1, 'FchEmis' => '2026-08-10'],
            'Totales'  => ['MntNeto' => 1000, 'TasaIVA' => 19, 'IVA' => 190, 'MntTotal' => 1190],
        ],
        'Detalle' => [[
            'NmbItem'   => 'Item de prueba del arnes',
            'QtyItem'   => 1,
            'PrcItem'   => 1000,
            'MontoItem' => 1000,
        ]],
    ];

    // El autoloader de LibreDTE ya quedo registrado en la pantalla 0b.
    $pdf = new $clase();
    $pdf->setResolucion(['NroResol' => '0', 'FchResol' => '2026-01-01']);
    if ($logo !== null) {
        $pdf->setLogo($logo);
    }
    // $timbre = null: sin TED. No hace falta para medir la cabecera y evita
    // depender de un CAF.
    $pdf->agregar($dte, null);

    // ANTES de Output(): despues, _destroy(false) deja h y k en 0.
    if ($escala === null) {
        $escala   = $pdf->getScaleFactor();
        $altoHoja = $pdf->getPageHeight();
    }

    $salida = (string) $pdf->Output('', 'S');

    // SE LIBERA AQUI, NO AL SALIR DE SCOPE. Output() ya llamo a _destroy(false),
    // pero el objeto sigue vivo con su buffer y sus recursos GD hasta que el
    // recolector pase. Con doce documentos seguidos eso es la diferencia entre un
    // pico de un PDF y un pico de doce.
    unset($pdf);
    gc_collect_cycles();

    return [$salida, $escala, $altoHoja];
}

/**
 * Construye, extrae el md5 del flujo descomprimido y SUELTA EL PDF.
 *
 * DE A UNO Y SE LIBERA EN EL MEDIO: lo unico que sobrevive a esta funcion son 32
 * caracteres y un entero, no un documento de ~100 KB por variante.
 *
 * @return array{0:string, 1:int} [md5 del flujo, bytes del flujo]
 */
function md5DelFlujo(array $emisor, ?string $logo, string $clase): array
{
    [$pdf] = construir($emisor, $logo, $clase);
    $flujo = flujoDescomprimido($pdf);
    unset($pdf);
    $r = [md5($flujo), strlen($flujo)];
    unset($flujo);
    gc_collect_cycles();

    return $r;
}

/** Memoria en uso, legible. */
function memoria(): string
{
    return sprintf('%.1f MB (pico %.1f MB)',
        memory_get_usage(true) / 1048576,
        memory_get_peak_usage(true) / 1048576);
}

$emisorBase = [
    'RUTEmisor'    => '77724622-4',
    'RznSoc'       => 'SOCIEDAD DE PROFESIONALES ROSAS Y VILLAR LIMITADA',
    'GiroEmis'     => 'SERVICIOS PROFESIONALES DE INGENIERIA Y CONSTRUCCION',
    'DirOrigen'    => 'AVENIDA PICARTE 1234, OFICINA 501',
    'CmnaOrigen'   => 'VALDIVIA',
    'Telefono'     => '+56 63 2 123456',
    'CorreoEmisor' => 'contacto@ejemplo.invalid',
];

try {
    // Solo para leer escala y alto de hoja: el PDF se suelta enseguida.
    [$pdfSinLogo] = construir($emisorBase, null);
    unset($pdfSinLogo);
    gc_collect_cycles();
} catch (\Throwable $e) {
    morir('no se pudo construir el documento sin logo: ' . $e->getMessage()
        . ' -- ARNES SIN CORRER.');
}

if ($escala === null || $escala <= 0.0 || $altoHoja === null || $altoHoja <= 0.0) {
    morir(sprintf('escala=%s altoHoja=%s: se leyeron DESPUES de Output() o el objeto ya '
        . 'estaba destruido. Sin estos dos numeros ninguna Y es interpretable.',
        var_export($escala, true), var_export($altoHoja, true)));
}
printf("  getScaleFactor() = %.4f  (puntos por mm)\n", $escala);
printf("  getPageHeight()  = %.2f mm\n", $altoHoja);
if (abs($altoHoja - 297.0) < 0.5) {
    ok('la hoja es A4 (297 mm), como quedo anotado en LogoEmpresa: el formato '
        . "'Letter' que pide LibreDTE no existe con esa capitalizacion y cae en A4.");
} else {
    aviso(sprintf('la hoja mide %.2f mm y se esperaba A4 (297). Revisa la nota de '
        . 'MAX_PROPORCION_ALTO antes de creer el calculo del quinto.', $altoHoja));
}

/** Y de llamada (mm desde arriba) a partir de la Y cruda del operador. */
function yEnMm(float $yTd, float $escala, float $altoHoja): float
{
    return $altoHoja - ($yTd / $escala);
}

// ===========================================================================
// VERIFICACION 1 - INERCIA SIN LOGO (las seis variantes)
// ===========================================================================
titulo('VERIFICACION 1 - INERCIA: un documento SIN logo no puede moverse');

$variantes = [
    'nombre corto'                  => ['RznSoc' => 'SinergIA SpA'],
    'nombre largo (49 car.)'        => [],
    'sin telefono'                  => ['Telefono' => ''],
    'sin correo'                    => ['CorreoEmisor' => ''],
    'sin telefono ni correo'        => ['Telefono' => '', 'CorreoEmisor' => ''],
    'giro y direccion muy largos'   => [
        'GiroEmis'  => 'SERVICIOS PROFESIONALES DE INGENIERIA, CONSTRUCCION, MONTAJE INDUSTRIAL Y ASESORIA TECNICA ESPECIALIZADA',
        'DirOrigen' => 'AVENIDA RAMON PICARTE 1234, OFICINA 501, EDIFICIO TORRE DEL SUR, SECTOR ISLA TEJA',
    ],
];

if (preg_match('/getImageRBY/', $txtWork) !== 1) {
    mal('el codigo nuevo no llama a getImageRBY() en ninguna parte: la bajada '
        . 'quedo como constante.');
} else {
    ok('el codigo nuevo usa getImageRBY().');
}

// --- CONTROL: HEAD contra HEAD ---
//
// Antes de comparar nada, comprobar que dos corridas de la MISMA clase dan el
// mismo flujo. Si esto no sale identico, el arnes tiene ruido (fechas, ids) y
// ninguna diferencia de mas abajo significa nada.
// SE COMPARAN md5, NO PDF. En ningun momento hay mas de un documento vivo:
// md5DelFlujo() construye, extrae y suelta antes de devolver.
[$c1] = md5DelFlujo($emisorBase, null, CLASE_HEAD);
[$c2, $bytesC2] = md5DelFlujo($emisorBase, null, CLASE_HEAD);
if ($c1 === $c2 && $bytesC2 > 0) {
    ok('CONTROL limpio: dos corridas de HEAD dan el mismo flujo descomprimido.');
} else {
    morir('CONTROL sucio: el flujo no es determinista entre dos corridas iguales, o '
        . 'salio vacio. Revisa flujoDescomprimido() antes de creer cualquier comparacion.');
}

// --- LA DIFERENCIA ESPERADA SE DECLARA, VARIANTE POR VARIANTE ---
//
// El arreglo de la guarda del telefono (DtePdfDocumento.php:396) CAMBIA el
// dibujo de cualquier emisor que traiga <Telefono>: antes el telefono se perdia
// y ahora se imprime. Eso es la correccion, no una regresion.
//
// Lo que TIENE que seguir inerte es el documento REAL, y por una razon medida:
// DteXmlBuilder emite en <Emisor> exactamente RUTEmisor, RznSoc, GiroEmis,
// Acteco, DirOrigen y CmnaOrigen -- NI Telefono NI CorreoEmisor. O sea que
// ningun DTE de produccion entra siquiera al bloque de contacto.
//
// Asi que la expectativa se decide por variante, mirando el dato: sin telefono
// -> identico; con telefono -> distinto Y con el telefono a la vista.
echo "\n      variante                        esperado    HEAD                              TRABAJO                           memoria\n";
echo "      ------------------------------  ----------  --------------------------------  --------------------------------  --------------------\n";
$comoSeEsperaba = 0;
foreach ($variantes as $nombre => $sobre) {
    $emisor  = array_merge($emisorBase, $sobre);
    $conTel  = ! empty($emisor['Telefono']);
    $esperado = $conTel ? 'DISTINTO' : 'identico';
    try {
        [$mdHead, $bHead] = md5DelFlujo($emisor, null, CLASE_HEAD);
        [$mdWork, $bWork] = md5DelFlujo($emisor, null, CLASE_WORK);
    } catch (\Throwable $e) {
        mal("variante '{$nombre}': no se pudo construir - " . $e->getMessage());
        continue;
    }
    if ($bHead === 0 || $bWork === 0) {
        morir("variante '{$nombre}': el flujo salio VACIO. Fallo del arnes "
            . '(descompresion), no de la entrega.');
    }
    printf("      %-30s  %-10s  %s  %s  %s\n", $nombre, $esperado, $mdHead, $mdWork, memoria());

    if ($conTel) {
        if ($mdWork !== $mdHead) {
            $comoSeEsperaba++;
        } else {
            mal(sprintf("variante '%s': trae telefono y el flujo NO cambio. La guarda "
                . 'sigue comiendoselo.', $nombre));
        }
    } else {
        if ($mdHead === $mdWork) {
            $comoSeEsperaba++;
        } else {
            mal(sprintf("variante '%s': SIN telefono y el flujo CAMBIO (%d bytes en HEAD, "
                . '%d en el arbol). Un documento con forma de produccion no puede moverse.',
                $nombre, $bHead, $bWork));
        }
    }
}
if ($comoSeEsperaba === count($variantes)) {
    ok(sprintf('las %d variantes se comportaron como se declaro: inertes las que no '
        . 'llevan telefono, cambiadas las que si.', $comoSeEsperaba));
}

// --- Y LA INERCIA QUE DE VERDAD IMPORTA: EL EMISOR CON FORMA DE PRODUCCION ---
//
// Las mismas seis variantes, pero sobre un emisor con EXACTAMENTE las claves que
// DteXmlBuilder emite. Aqui no cabe ninguna diferencia esperada: si una sola de
// estas se mueve, la entrega toco un documento real.
echo "\n  EMISOR CON FORMA DE PRODUCCION (sin Telefono ni CorreoEmisor, como emite\n";
echo "  DteXmlBuilder.php:114-127). NINGUNA de estas puede moverse:\n\n";
$emisorProduccion = [
    'RUTEmisor'  => $emisorBase['RUTEmisor'],
    'RznSoc'     => $emisorBase['RznSoc'],
    'GiroEmis'   => $emisorBase['GiroEmis'],
    'Acteco'     => '620200',
    'DirOrigen'  => $emisorBase['DirOrigen'],
    'CmnaOrigen' => $emisorBase['CmnaOrigen'],
];
$inertes = 0;
foreach ($variantes as $nombre => $sobre) {
    // Solo se aplican los campos que existen en un emisor de produccion.
    $sobreReal = array_intersect_key($sobre, $emisorProduccion);
    $emisor    = array_merge($emisorProduccion, $sobreReal);
    [$mdHead] = md5DelFlujo($emisor, null, CLASE_HEAD);
    [$mdWork] = md5DelFlujo($emisor, null, CLASE_WORK);
    printf("      %-30s  %s  %s\n", $nombre, $mdHead, $mdWork);
    if ($mdHead === $mdWork) {
        $inertes++;
    } else {
        mal(sprintf("PRODUCCION, variante '%s': el flujo CAMBIO. Esto SI es una regresion.", $nombre));
    }
}
if ($inertes === count($variantes)) {
    ok(sprintf('INERCIA INTACTA en las %d variantes con forma de produccion: mismo flujo '
        . 'descomprimido, byte a byte.', $inertes));
}

// Ademas de los md5: que la rama sin logo conserve sus dos constantes.
$rama = [];
if (preg_match('/\$this->y = \$y-2;/', $txtWork)) {
    $rama[] = 'conserva el $this->y = $y-2 de la rama sin logo';
}
if (preg_match('/\$wCompleto\s*=\s*\$w\s*\+\s*40;/', $txtWork)) {
    $rama[] = 'conserva el +40 que daba los 115 mm';
}
if (count($rama) === 2) {
    ok('la rama sin logo mantiene sus dos constantes: ' . implode(' y ', $rama) . '.');
} else {
    mal('la rama sin logo cambio de forma: ' . implode(' / ', $rama));
}

// ===========================================================================
// VERIFICACION 2 - EL CASO REAL CON LOGO
// ===========================================================================
titulo('VERIFICACION 2 - CASO REAL: logo 40 mm, volcado con X y tamaño');

// Logo "normal": proporcion 0,4, que es la del caso real (12 mm dibujado a 30).
// A 40 mm de ancho tiene que dar 16 mm de alto.
$logoNormal = '@' . logoPng(400, 160);
try {
    [$pdfLogo] = construir($emisorBase, $logoNormal);
} catch (\Throwable $e) {
    morir('no se pudo construir el documento con logo: ' . $e->getMessage());
}
$ops = operadoresDeTexto(flujoDescomprimido($pdfLogo));
unset($pdfLogo);   // los operadores ya estan extraidos; el PDF no hace falta mas
gc_collect_cycles();
if ($ops === []) {
    // SE MUESTRA EL DATO CRUDO, no solo el veredicto: la primera vez que esto
    // fallo hubo que ir a buscar el formato a mano. Aqui queda a la vista.
    $flujoMuestra = flujoDescomprimido((string) construir($emisorBase, $logoNormal)[0]);
    echo "\n  MUESTRA DEL FLUJO (primeras lineas que contienen TJ o Tj):\n";
    $vistas = 0;
    foreach (preg_split('/\r?\n/', $flujoMuestra) ?: [] as $l) {
        if ((str_contains($l, 'TJ') || str_contains($l, 'Tj')) && $vistas < 5) {
            echo '      ' . mb_substr($l, 0, 150) . "\n";
            $vistas++;
        }
    }
    if ($vistas === 0) {
        echo "      (ninguna: el flujo no trae operadores de texto en absoluto)\n";
    }
    morir('CERO operandos de texto. Fallo del arnes (el decodificador), no de la '
        . 'entrega. Compara la muestra de arriba con los patrones de '
        . 'operadoresDeTexto(): TCPDF emite "BT x y Td [(texto)] TJ ET" en UNA '
        . 'linea (tcpdf.php:5584), con BT delante del texto.');
}

// --- LO PRIMERO ES VER QUE TRAE, NO BUSCAR NADA ---
//
// VA ANTES DE LA CALIBRACION A PROPOSITO. Este volcado es el que contesta de
// una sola mirada si un fallo posterior es de codificacion, de la tilde o de la
// busqueda. Sin el hubo que adivinarlo dos veces.
//
// La Y sale SIN CALIBRAR (todavia no hay desfase que aplicar): es la cruda del
// operador, en puntos, mas su conversion directa a mm. Y el texto va tal como
// queda tras decodificar, con su volcado hexadecimal al lado, que es lo que
// delata un byte roto -- una tilde mal convertida se ve aqui y en ningun otro
// sitio.
printf("\n  PRIMEROS %d OPERANDOS DECODIFICADOS (de %d en total), SIN CALIBRAR:\n", 15, count($ops));
echo "      #   pt      X       Y cruda   Y directa   texto | hex\n";
echo "      --  ----  ------  ---------  ----------   -------------------------------------\n";
foreach (array_slice($ops, 0, 15) as $i => $o) {
    printf("      %2d  %4.1f  %6.1f  %9.2f  %9.2f   %s | %s\n",
        $i,
        $o['pt'],
        $o['x'] / $escala,
        $o['yTd'],
        yEnMm($o['yTd'], $escala, $altoHoja),
        mb_substr(trim($o['texto']), 0, 28),
        mb_strimwidth(bin2hex(mb_substr(trim($o['texto']), 0, 10)), 0, 24, ''));
}

// --- CALIBRACION DE LA Y ---
//
// El Td no da la Y de la celda: da la LINEA BASE. Se mide el desfase contra un
// texto cuya Y de llamada conocemos con certeza: 'Emisión', que escribe
// agregarFechaEmision() (DtePdfDocumento.php:582) justo despues del
// setY(max(50, finEmisor)).
//
// SE BUSCA 'Emisi', SIN TILDE, Y NO ES UN ATAJO. La palabra que dibuja el codigo
// lleva tilde, el texto viaja en UTF-16BE dentro del operador y en los volcados
// anteriores esa tilde ya salio rota ("Emisi?n"). Buscar la palabra entera hace
// que la calibracion dependa de que un byte sobreviva a la conversion; 'Emisi'
// es el prefijo mas corto que identifica la etiqueta sin depender de eso. La
// unica otra etiqueta de esa columna es 'Vence', que no colisiona.
$PREFIJO_CALIBRACION = 'Emisi';
$Y_LLAMADA_CALIBRACION = 50.0;

$desfase = null;
$opCal   = null;
foreach ($ops as $o) {
    if (stripos($o['texto'], $PREFIJO_CALIBRACION) !== false) {
        $opCal   = $o;
        $desfase = yEnMm($o['yTd'], $escala, $altoHoja) - $Y_LLAMADA_CALIBRACION;
        break;
    }
}
if ($desfase === null) {
    // ANTES DE CULPAR AL DECODIFICADOR: comprobar que la etiqueta EXISTE en el
    // documento. Si el emisor sembrado no llegara a dibujar ese bloque, no habria
    // nada que encontrar y el fallo seria de la siembra, no de la busqueda.
    echo "\n  NO APARECIO '{$PREFIJO_CALIBRACION}'. Todos los textos decodificados, en orden:\n";
    foreach ($ops as $i => $o) {
        printf("      %2d  %s\n", $i, mb_substr(trim($o['texto']), 0, 60));
    }
    morir("no se encontro el texto de calibracion ('{$PREFIJO_CALIBRACION}'). "
        . 'agregarFechaEmision() lo dibuja SIEMPRE (DtePdfDocumento.php:582, llamado '
        . 'sin condicion desde agregar():282), asi que si no esta en la lista de '
        . 'arriba el problema es la decodificacion; si esta y no caso, es la '
        . 'busqueda. Sin calibrar, ninguna Y es interpretable. ARNES SIN CORRER.');
}
printf("\n  CALIBRACION: '%s' encontrado en '%s' (x=%.1f, Y cruda %.2f)\n",
    $PREFIJO_CALIBRACION, mb_substr(trim($opCal['texto']), 0, 20),
    $opCal['x'] / $escala, $opCal['yTd']);
printf("  DESFASE DE LINEA BASE contra Y de llamada = %.2f: %+.2f mm\n",
    $Y_LLAMADA_CALIBRACION, $desfase);

// LA PREMISA DE LA CALIBRACION ES QUE finEmisor NO PASE DE 50. Con el bloque
// nuevo el nombre puede ocupar una linea mas, asi que conviene comprobarlo en
// vez de suponerlo: si el emisor se estirara por debajo de 50, el setY tomaria
// finEmisor y esta Y de llamada dejaria de ser 50.
if (abs($desfase) > 8.0) {
    aviso(sprintf('el desfase (%+.2f mm) es mayor de lo razonable. Lo mas probable es '
        . 'que el bloque del emisor pase de 50 mm y que el setY(max(50, finEmisor)) '
        . 'este tomando finEmisor: en ese caso la Y de llamada NO es 50 y todas las '
        . 'Y de mas abajo estan corridas por igual. Mira la Y del contacto en el '
        . 'volcado de arriba antes de creer nada.', $desfase));
}

echo "\n  VOLCADO CRUDO (Y ya calibrada). Se imprime TODO lo de la cabecera:\n";
echo "      pt      X       Y      texto\n";
echo "      ----  -----  -----   ---------------------------------------------\n";
foreach ($ops as $o) {
    $ymm = yEnMm($o['yTd'], $escala, $altoHoja) - $desfase;
    if ($ymm > 60.0) {
        continue; // solo la cabecera
    }
    printf("      %4.1f  %5.1f  %5.1f   %s\n", $o['pt'], $o['x'] / $escala, $ymm,
        mb_substr(trim($o['texto']), 0, 45));
}

/** Primer operador cuyo texto empieza por $prefijo. */
function buscar(array $ops, string $prefijo): ?array
{
    foreach ($ops as $o) {
        if (stripos(trim($o['texto']), $prefijo) === 0) {
            return $o;
        }
    }

    return null;
}

// --- LAS TRES CONFIRMACIONES DEL VOLCADO ---
//
// No se deducen de que "se ve bien": se cuentan.
echo "\n  CONFIRMACIONES SOBRE EL VOLCADO:\n";

// (a) CUANTAS LINEAS OCUPA EL NOMBRE. Son los operadores de la columna del
//     nombre (x ~ 54) al tamaño que eligio la cascada. Si aparecieran cuatro
//     donde antes habia tres, se veria aqui.
$ptNombre = null;
foreach ($ops as $o) {
    if (stripos(trim($o['texto']), 'SOCIEDAD') === 0) {
        $ptNombre = $o['pt'];
        break;
    }
}
$lineasNombre = 0;
if ($ptNombre !== null) {
    foreach ($ops as $o) {
        if (abs($o['pt'] - $ptNombre) < 0.01 && abs($o['x'] / $escala - 54.0) < 2.0) {
            $lineasNombre++;
        }
    }
}
printf("      (a) el nombre ocupa %d linea(s) a %.0f pt en la columna x~54\n",
    $lineasNombre, $ptNombre ?? 0);
if ($lineasNombre > 0 && $lineasNombre <= 3) {
    ok("el nombre no gano una cuarta linea al estrecharse de 75 a 65 mm.");
} elseif ($lineasNombre >= 4) {
    aviso(sprintf('el nombre ocupa %d lineas. NO es una regresion -- con el bloque nuevo '
        . 'la linea de mas no empuja nada hacia abajo --, pero es el numero que no '
        . 'habiamos medido.', $lineasNombre));
} else {
    mal('no se pudo contar las lineas del nombre: revisa el volcado.');
}

// (b) DE DONDE ARRANCA EL BLOQUE DE ABAJO. Si el emisor no pasa de 50 mm, el
//     setY(max(50, finEmisor)) toma 50 y 'Emisi' cae exactamente ahi.
$yEmisi = yEnMm($opCal['yTd'], $escala, $altoHoja) - $desfase;
printf("      (b) el bloque de abajo arranca en Y=%.1f mm\n", $yEmisi);
if (abs($yEmisi - 50.0) < 0.5) {
    ok('sigue arrancando en 50: el bloque del emisor no se paso, asi que el max() '
        . 'esta tomando el piso y no finEmisor.');
} else {
    aviso(sprintf('arranca en %.1f y no en 50: el bloque del emisor se estiro por debajo '
        . 'del piso y manda finEmisor.', $yEmisi));
}

// (c) LA ULTIMA LINEA DEL EMISOR, para ver cuanto margen queda hasta ese piso.
$yUltimaEmisor = null;
foreach ($ops as $o) {
    $ymm = yEnMm($o['yTd'], $escala, $altoHoja) - $desfase;
    if ($ymm < 49.5 && $o['x'] / $escala < 60.0 && ($yUltimaEmisor === null || $ymm > $yUltimaEmisor)) {
        $yUltimaEmisor = $ymm;
    }
}
printf("      (c) la ultima linea del emisor cae en Y=%.1f mm (margen hasta el piso: %.1f mm)\n",
    $yUltimaEmisor ?? 0.0, 50.0 - ($yUltimaEmisor ?? 0.0));

// --- EL CONTACTO: TELEFONO **Y** CORREO ---
//
// Esta es la comprobacion que antes fallaba y que estaba atrapando el defecto
// real: el arnes buscaba el contacto por el prefijo '+56' y no lo encontraba
// porque la guarda rota se comia el telefono, dejando la linea con el correo
// solo. Ahora se exige que esten LOS DOS, y se imprime la linea de las dos
// versiones una debajo de otra para que se vea el antes y el despues.
echo "\n  LA LINEA DE CONTACTO, HEAD contra TRABAJO:\n";
$lineaContactoDe = static function (array $lista): ?string {
    foreach ($lista as $o) {
        if (str_contains($o['texto'], '@') || str_contains($o['texto'], '+56')) {
            return trim($o['texto']);
        }
    }

    return null;
};
[$pdfContactoHead] = construir($emisorBase, $logoNormal, CLASE_HEAD);
$opsContactoHead   = operadoresDeTexto(flujoDescomprimido($pdfContactoHead));
unset($pdfContactoHead);
gc_collect_cycles();

$contactoHead = $lineaContactoDe($opsContactoHead);
$contactoWork = $lineaContactoDe($ops);
printf("      HEAD    : %s\n", $contactoHead ?? '(no se dibujo ninguna linea de contacto)');
printf("      TRABAJO : %s\n", $contactoWork ?? '(no se dibujo ninguna linea de contacto)');

$telSembrado    = $emisorBase['Telefono'];
$correoSembrado = $emisorBase['CorreoEmisor'];
if ($contactoWork === null) {
    mal('el arbol no dibuja ninguna linea de contacto.');
} else {
    $tieneTel    = str_contains($contactoWork, $telSembrado);
    $tieneCorreo = str_contains($contactoWork, $correoSembrado);
    if ($tieneTel && $tieneCorreo) {
        ok('el contacto sale con TELEFONO Y CORREO. La guarda de la linea 396 quedo '
            . 'arreglada: is_array() en vez de isset($string[0]).');
    } else {
        mal(sprintf('el contacto sale incompleto: telefono=%s correo=%s.',
            $tieneTel ? 'si' : 'NO', $tieneCorreo ? 'si' : 'NO'));
    }
}
// Y el antes, para que el arreglo quede demostrado y no afirmado.
if ($contactoHead !== null && ! str_contains($contactoHead, $telSembrado)) {
    ok('y en HEAD el telefono NO estaba: el defecto era real y este arnes lo atrapo.');
} elseif ($contactoHead !== null) {
    aviso('en HEAD el telefono tambien salia: revisa el volcado de HEAD, porque '
        . 'entonces la guarda no era el problema.');
}

echo "\n  DONDE ARRANCA CADA COSA:\n";
$objetivos = [
    'nombre'    => ['SOCIEDAD', 54.0],   // x de llamada 53 + 1 de cMargin
    'giro'      => ['SERVICIOS', 11.0],  // x de llamada 10 + 1 de cMargin
    'direccion' => ['AVENIDA',   11.0],
    'contacto'  => ['+56',       11.0],
];
foreach ($objetivos as $etiqueta => [$prefijo, $xEsperada]) {
    $o = buscar($ops, $prefijo);
    if ($o === null) {
        mal("no se encontro el operador de {$etiqueta} (prefijo '{$prefijo}'). "
            . 'Puede ser un fallo del arnes: revisa el volcado de arriba.');
        continue;
    }
    $xmm = $o['x'] / $escala;
    printf("      %-10s x=%5.1f  (esperado %.1f)  pt=%.1f\n", $etiqueta, $xmm, $xEsperada, $o['pt']);
    if (abs($xmm - $xEsperada) < 1.5) {
        ok("{$etiqueta} arranca donde tiene que arrancar.");
    } else {
        mal(sprintf('%s arranca en x=%.1f y se esperaba %.1f.', $etiqueta, $xmm, $xEsperada));
    }
}

// ===========================================================================
// VERIFICACION 3 - EL NOMBRE SIGUE DESTACADO
// ===========================================================================
titulo('VERIFICACION 3 - jerarquia: el nombre destacado frente al giro');

$oNombre = buscar($ops, 'SOCIEDAD');
$oGiro   = buscar($ops, 'SERVICIOS');
if ($oNombre === null || $oGiro === null) {
    mal('faltan operadores para comparar la jerarquia.');
} else {
    printf("      nombre %.1f pt   giro %.1f pt   razon %.2fx\n",
        $oNombre['pt'], $oGiro['pt'], $oGiro['pt'] > 0 ? $oNombre['pt'] / $oGiro['pt'] : 0);
    if ($oNombre['pt'] >= 14.0 && $oGiro['pt'] > 0 && $oNombre['pt'] / $oGiro['pt'] >= 1.5) {
        ok('el nombre se lee como otro nivel de titulo (>= 1,5x el giro y >= 14 pt), '
            . 'que es el argumento con el que se defiende el "destacado" del SII.');
    } else {
        mal('la jerarquia bajo del minimo que sostiene el cumplimiento.');
    }
    if ($oNombre['pt'] === 14.0) {
        ok('la cascada aterrizo en el piso de 14 pt, como estaba previsto para este '
            . 'nombre de 49 caracteres en 65 mm.');
    } else {
        aviso(sprintf('la cascada eligio %.0f pt, no el piso de 14 que se esperaba. '
            . 'No es un fallo: es el numero que no habiamos medido.', $oNombre['pt']));
    }
}

// ===========================================================================
// VERIFICACION 4 - EL LOGO ALTO (el que prueba que la bajada no es constante)
// ===========================================================================
titulo('VERIFICACION 4 - LOGO ALTO: el giro tiene que quedar DEBAJO de la imagen');

$maxProp = \Plantiflex\FacturacionCl\Pdf\LogoEmpresa::MAX_PROPORCION_ALTO;
$anchoMm = \Plantiflex\FacturacionCl\Pdf\LogoEmpresa::ANCHO_DIBUJO_MM;
$altoEsperadoMm = $anchoMm * $maxProp;
printf("  Logo al MAXIMO que la validacion permite: proporcion %.2f -> %.0f x %.0f mm.\n",
    $maxProp, $anchoMm, $altoEsperadoMm);
echo "  ESTE ES EL CASO QUE PRUEBA EL max(). Con un logo asi, una bajada constante\n";
echo "  dibujaria el giro ENCIMA de la imagen.\n\n";

// 400 px de ancho por 400*1,25 = 500 de alto.
$logoAlto = '@' . logoPng(400, (int) round(400 * $maxProp));

// Y de paso: que la validacion acepte EXACTAMENTE este y rechace uno mas alto.
[$err] = \Plantiflex\FacturacionCl\Pdf\LogoEmpresa::validar(substr($logoAlto, 1));
if ($err === null) {
    ok('la validacion acepta el logo en el limite exacto.');
} else {
    mal('la validacion RECHAZA su propio limite: ' . $err);
}
[$err2] = \Plantiflex\FacturacionCl\Pdf\LogoEmpresa::validar(logoPng(400, (int) round(400 * $maxProp) + 40));
if ($err2 !== null) {
    ok('y rechaza uno mas alto: ' . mb_substr($err2, 0, 70) . '...');
} else {
    mal('la validacion ACEPTA un logo por encima del limite.');
}

try {
    [$pdfAlto] = construir($emisorBase, $logoAlto);
} catch (\Throwable $e) {
    morir('no se pudo construir el documento con logo alto: ' . $e->getMessage());
}
$opsAlto = operadoresDeTexto(flujoDescomprimido($pdfAlto));
unset($pdfAlto, $logoAlto);
gc_collect_cycles();
if ($opsAlto === []) {
    morir('CERO operandos con el logo alto. Fallo del arnes.');
}

$desfaseAlto = null;
foreach ($opsAlto as $o) {
    if (stripos($o['texto'], 'Emision') !== false || stripos($o['texto'], 'Emisión') !== false) {
        // Con un logo de 50 mm el bloque del emisor SI pasa de 50, asi que el
        // setY(max(50, finEmisor)) ya no vale 50 y este texto no sirve para
        // calibrar. Se reusa el desfase medido en el caso normal, que es una
        // propiedad de la FUENTE y del cuerpo, no del documento.
        $desfaseAlto = $desfase;
        break;
    }
}
if ($desfaseAlto === null) {
    $desfaseAlto = $desfase;
}

$oNombreA = buscar($opsAlto, 'SOCIEDAD');
$oGiroA   = buscar($opsAlto, 'SERVICIOS');
if ($oNombreA === null || $oGiroA === null) {
    mal('con el logo alto no se encontraron los operadores de nombre y giro.');
} else {
    $yNombre = yEnMm($oNombreA['yTd'], $escala, $altoHoja) - $desfaseAlto;
    $yGiro   = yEnMm($oGiroA['yTd'], $escala, $altoHoja) - $desfaseAlto;
    // El logo se dibuja desde y=10: su borde inferior cae en 10 + alto.
    $fondoLogo = 10.0 + $altoEsperadoMm;
    printf("      borde inferior del logo (10 + %.0f) = %.1f mm\n", $altoEsperadoMm, $fondoLogo);
    printf("      Y del nombre                        = %.1f mm  (x=%.1f)\n", $yNombre, $oNombreA['x'] / $escala);
    printf("      Y del giro                          = %.1f mm  (x=%.1f)\n", $yGiro, $oGiroA['x'] / $escala);

    if ($yGiro >= $fondoLogo) {
        ok(sprintf('EL GIRO QUEDA DEBAJO DEL LOGO (%.1f >= %.1f). El max() esta probado, '
            . 'no solo escrito.', $yGiro, $fondoLogo));
    } else {
        mal(sprintf('EL GIRO SE DIBUJA ENCIMA DEL LOGO: y=%.1f y el logo llega hasta %.1f. '
            . 'La bajada no esta tomando getImageRBY().', $yGiro, $fondoLogo));
    }
    if (abs($oGiroA['x'] / $escala - 11.0) < 1.5) {
        ok('y arranca igual en x=11: el ancho completo no depende del alto del logo.');
    } else {
        mal(sprintf('el giro arranca en x=%.1f con el logo alto.', $oGiroA['x'] / $escala));
    }
}

// ===========================================================================
// VERIFICACION 5 - EL MOBILIARIO FIJO NO SE MUEVE
// ===========================================================================
titulo('VERIFICACION 5 - el mobiliario fijo');

// El recuadro del folio se dibuja siempre en x=130, y=10, independiente del
// emisor. Si se moviera, el cambio habria tocado algo que no era suyo.
$fijos = ['R.U.T.' => 130.0, 'FACTURA' => 130.0];
foreach ($fijos as $prefijo => $xEsperada) {
    $a = buscar($ops, $prefijo);
    $b = buscar($opsAlto, $prefijo);
    if ($a === null || $b === null) {
        aviso("no se encontro '{$prefijo}' en uno de los dos documentos; no se puede "
            . 'comparar este elemento.');
        continue;
    }
    $xa = $a['x'] / $escala;
    $xb = $b['x'] / $escala;
    printf("      %-10s logo normal x=%.1f   logo alto x=%.1f\n", $prefijo, $xa, $xb);
    if (abs($xa - $xb) < 0.5 && $xa >= $xEsperada - 2.0) {
        ok("'{$prefijo}' no se movio entre los dos documentos.");
    } else {
        mal("'{$prefijo}' cambio de sitio segun el logo: no deberia depender de el.");
    }
}

// ===========================================================================
// RESUMEN
// ===========================================================================
titulo('RESUMEN');
printf("  fallos: %d\n  avisos: %d\n", $fallos, $avisos);

// EL PICO REAL, NO EL SUPUESTO. Con este numero se decide si el techo de 256M
// sobra o falta, en vez de discutirlo.
printf("\n  MEMORIA\n");
printf("    memory_limit en efecto : %s\n", ini_get('memory_limit'));
printf("    pico real del proceso  : %.1f MB (real) / %.1f MB (asignada a PHP)\n",
    memory_get_peak_usage(true) / 1048576,
    memory_get_peak_usage(false) / 1048576);
printf("    documentos construidos : %d\n", 6 * 2 + 2 + 3);
$pico = memory_get_peak_usage(true) / 1048576;
$tope = 256.0;
if ($pico > $tope * 0.75) {
    aviso(sprintf('el pico (%.1f MB) pasa del 75%% del techo (%.0f MB). Sube el techo '
        . 'CON ESTE DATO o reduce el numero de variantes.', $pico, $tope));
} else {
    ok(sprintf('el pico (%.1f MB) queda holgado bajo el techo de %.0f MB.', $pico, $tope));
}
echo "\n  LO QUE ESTE ARNES NO CUBRE:\n";
echo "    - El logo REAL del cliente: esta en dte_logo y aqui no se toca ninguna\n";
echo "      base. Los PNG de prueba se generan con GD.\n";
echo "    - PHPUnit: vendor/bin/phpunit --testdox\n";

exit($fallos > 0 ? 1 : 0);
