<?php

declare(strict_types=1);

/**
 * ARNES DE VERIFICACION: lista cerrada de claves en validarDocumentoDte()
 * + retiro del campo "observaciones" del formulario de emision.
 *
 * NO TOCA NINGUNA BASE, NO ABRE NINGUN SOCKET, NO LLAMA AL SII Y NO ESCRIBE
 * NINGUN ARCHIVO. Compara el codigo de HEAD contra el del arbol de trabajo
 * cargando LOS BYTES LITERALES de cada version en namespaces separados, y les
 * pasa los MISMOS payloads. No parafrasea la logica: la ejecuta.
 *
 * ------------------------------------------------------------------------
 * COMO PREPARARLO (git NO existe dentro del contenedor, asi que los archivos
 * de HEAD se sacan FUERA y se dejan junto a este script):
 *
 *   cd Y:/webserver/sinergia_fac_bol
 *   git show HEAD:public/index.php        > scripts/HEAD_public_index.php
 *   git show HEAD:panel/public/index.php  > scripts/HEAD_panel_index.php
 *   git show HEAD:panel/views/emision-form.php > scripts/HEAD_emision_form.php
 *
 * Y DESPUES SE BORRAN A MANO, POR RUTA EXPLICITA (nada de globs):
 *
 *   rm scripts/HEAD_public_index.php
 *   rm scripts/HEAD_panel_index.php
 *   rm scripts/HEAD_emision_form.php
 *
 * Si falta alguno, el script ABORTA EN LA PANTALLA 0. Un arnes que corre a
 * medias y pasa es peor que uno que no corre.
 * ------------------------------------------------------------------------
 */

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

$requeridos = [
    'HEAD_public'  => __DIR__ . '/HEAD_public_index.php',
    'HEAD_panel'   => __DIR__ . '/HEAD_panel_index.php',
    'HEAD_vista'   => __DIR__ . '/HEAD_emision_form.php',
    'WORK_public'  => $RAIZ . '/public/index.php',
    'WORK_panel'   => $RAIZ . '/panel/public/index.php',
    'WORK_vista'   => $RAIZ . '/panel/views/emision-form.php',
];
$fuente = [];
foreach ($requeridos as $etiqueta => $ruta) {
    if (! is_file($ruta)) {
        morir("falta {$etiqueta}: {$ruta}. Lee la cabecera de este script.");
    }
    $txt = file_get_contents($ruta);
    if ($txt === false || $txt === '') {
        morir("{$etiqueta} esta vacio: {$ruta}");
    }
    $fuente[$etiqueta] = $txt;
    printf("  %-12s %7d bytes  md5 %s\n", $etiqueta, strlen($txt), md5($txt));
}

if ($fuente['HEAD_public'] === $fuente['WORK_public']) {
    morir('HEAD_public_index.php y public/index.php son IDENTICOS: o no hay cambios '
        . 'que verificar, o el volcado de HEAD se saco despues de editar.');
}
ok('HEAD y arbol de trabajo difieren en public/index.php: hay algo que comparar.');

// ===========================================================================
// PANTALLA 1 - EXTRAER LAS FUNCIONES POR BYTES (tokenizer, no regex)
// ===========================================================================
titulo('PANTALLA 1 - EXTRACCION DE FUNCIONES');

/**
 * Devuelve el texto literal de una funcion de nivel superior, usando el
 * tokenizer de PHP. Nada de contar llaves a mano: las llaves dentro de
 * strings, heredocs y comentarios no son tokens '{'.
 */
function extraerFuncion(string $codigo, string $nombre): ?string
{
    $tokens = token_get_all($codigo);
    $n      = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
            continue;
        }
        // Siguiente token con contenido: el nombre.
        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $j++;
        }
        if ($j >= $n || ! is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING || $tokens[$j][1] !== $nombre) {
            continue;
        }
        // Recorrer hasta el '{' de apertura y luego balancear.
        $texto     = 'function ';
        $prof      = 0;
        $abierto   = false;
        for ($k = $j; $k < $n; $k++) {
            $t     = $tokens[$k];
            $texto .= is_array($t) ? $t[1] : $t;
            if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $prof++;
                $abierto = true;
            } elseif ($t === '}') {
                $prof--;
                if ($abierto && $prof === 0) {
                    return $texto;
                }
            }
        }

        return null;
    }

    return null;
}

$aExtraer = [
    ['HEAD_public', 'validarDocumentoDte'],
    ['WORK_public', 'validarDocumentoDte'],
    ['HEAD_public', 'rutDvValido'],
    ['WORK_public', 'rutDvValido'],
    ['HEAD_public', 'validaFecha'],
    ['WORK_public', 'validaFecha'],
    ['HEAD_panel',  'armarDocumentoEmision'],
    ['WORK_panel',  'armarDocumentoEmision'],
    ['HEAD_panel',  'armarDocumentosSubLote'],
    ['WORK_panel',  'armarDocumentosSubLote'],
    ['HEAD_panel',  'tiposDocumentosPorNota'],
    ['WORK_panel',  'tiposDocumentosPorNota'],
];
$fn = [];
foreach ($aExtraer as [$archivo, $nombre]) {
    $cuerpo = extraerFuncion($fuente[$archivo], $nombre);
    if ($cuerpo === null) {
        morir("no se pudo extraer {$nombre}() de {$archivo}. El arnes no puede seguir.");
    }
    $fn["{$archivo}::{$nombre}"] = $cuerpo;
    printf("  %-38s %6d bytes  md5 %s\n", "{$archivo}::{$nombre}", strlen($cuerpo), md5($cuerpo));
}

if ($fn['HEAD_public::validarDocumentoDte'] === $fn['WORK_public::validarDocumentoDte']) {
    morir('validarDocumentoDte() es IDENTICA en HEAD y en el arbol: el cambio no esta ahi.');
}
ok('validarDocumentoDte() cambio entre HEAD y el arbol.');

if ($fn['HEAD_panel::armarDocumentoEmision'] === $fn['WORK_panel::armarDocumentoEmision']) {
    mal('armarDocumentoEmision() NO cambio: la captura de observaciones deberia haberse quitado.');
} else {
    ok('armarDocumentoEmision() cambio entre HEAD y el arbol.');
}

if ($fn['HEAD_panel::armarDocumentosSubLote'] !== $fn['WORK_panel::armarDocumentosSubLote']) {
    mal('armarDocumentosSubLote() cambio y NO estaba en el alcance de esta entrega.');
} else {
    ok('armarDocumentosSubLote() intacta (la carga masiva no se toco).');
}

// ===========================================================================
// PANTALLA 2 - MONTAR LOS DOS NAMESPACES
// ===========================================================================
titulo('PANTALLA 2 - CARGA EN NAMESPACES SEPARADOS');

define('TIPOS_PERMITIDOS', [33, 34, 61, 56]);

/**
 * Excepcion que sustituye a invalido(): en el motor real invalido() responde
 * 422 y hace exit. Aqui hay que poder seguir corriendo casos, asi que se
 * captura el par (error, campo) TAL CUAL lo produce el codigo bajo prueba.
 */
class Rechazo422 extends \RuntimeException
{
    public function __construct(public readonly string $err, public readonly string $campo)
    {
        parent::__construct($err);
    }
}

function montar(string $ns, string $validar, string $rut, string $fecha, string $armar, string $subLote, string $tipos): void
{
    $codigo = "namespace {$ns};\n"
        . "use Plantiflex\\FacturacionCl\\Sii\\ImpuestoAdicional;\n"
        . "function invalido(string \$e, string \$c): never { throw new \\Rechazo422(\$e, \$c); }\n"
        . $rut . "\n" . $fecha . "\n" . $validar . "\n"
        . $armar . "\n" . $subLote . "\n" . $tipos . "\n";
    if (eval($codigo) === false) {
        morir("eval() fallo al montar el namespace {$ns}.");
    }
}

montar(
    'Base',
    $fn['HEAD_public::validarDocumentoDte'],
    $fn['HEAD_public::rutDvValido'],
    $fn['HEAD_public::validaFecha'],
    $fn['HEAD_panel::armarDocumentoEmision'],
    $fn['HEAD_panel::armarDocumentosSubLote'],
    $fn['HEAD_panel::tiposDocumentosPorNota'],
);
montar(
    'Nuevo',
    $fn['WORK_public::validarDocumentoDte'],
    $fn['WORK_public::rutDvValido'],
    $fn['WORK_public::validaFecha'],
    $fn['WORK_panel::armarDocumentoEmision'],
    $fn['WORK_panel::armarDocumentosSubLote'],
    $fn['WORK_panel::tiposDocumentosPorNota'],
);
ok('Base (HEAD) y Nuevo (arbol) cargados.');

/** Corre la validacion y devuelve ['ok'=>bool, 'campo'=>?string, 'err'=>?string]. */
function correr(string $ns, array $body, string $prefijo = '', bool $enLote = false): array
{
    $f = "{$ns}\\validarDocumentoDte";
    try {
        $f($body, $prefijo, $enLote);

        return ['ok' => true, 'campo' => null, 'err' => null];
    } catch (Rechazo422 $e) {
        return ['ok' => false, 'campo' => $e->campo, 'err' => $e->err];
    }
}

function pintar(string $etiqueta, array $r): void
{
    if ($r['ok']) {
        echo "      {$etiqueta}: ACEPTA\n";

        return;
    }
    echo "      {$etiqueta}: 422 campo='{$r['campo']}'\n";
    echo "                err={$r['err']}\n";
}

// Receptor valido reutilizable. RUT con DV correcto (76192083-9).
const RECEPTOR_OK = [
    'rut'         => '76192083-9',
    'razonSocial' => 'CLIENTE DE PRUEBA DEL ARNES SPA',
    'giro'        => 'PRUEBAS',
    'direccion'   => 'CALLE FALSA 123',
    'comuna'      => 'VALDIVIA',
];
const DETALLE_OK = [['nombre' => 'Item de prueba', 'cantidad' => 1, 'precioUnitario' => 1000]];

// ===========================================================================
// PANTALLA 3 - CONTROL: HEAD contra HEAD
// ===========================================================================
titulo('PANTALLA 3 - CONTROL (HEAD vs HEAD, mismo payload)');
echo "  Si esto no sale identico, el arnes tiene ruido y NADA de lo que sigue vale.\n\n";

$payloadControl = ['tipoDte' => 33, 'receptor' => RECEPTOR_OK, 'detalles' => DETALLE_OK, 'montosSonBrutos' => false];
$c1 = correr('Base', $payloadControl);
$c2 = correr('Base', $payloadControl);
pintar('Base #1', $c1);
pintar('Base #2', $c2);
if ($c1 === $c2) {
    ok('CONTROL limpio: dos corridas de HEAD dan lo mismo.');
} else {
    morir('CONTROL sucio: HEAD no es determinista en este arnes.');
}

// ===========================================================================
// VERIFICACION 1 - UNA CLAVE DESCONOCIDA DA 422 NOMBRANDOLA
// ===========================================================================
titulo('VERIFICACION 1 - clave desconocida: antes 201 y la perdia, ahora 422');

$casos1 = [
    'observaciones (la real, la que mandaba el panel)' => ['observaciones' => 'Entregar en bodega 2'],
    'una errata de tipeo (detalless)'                  => ['detalless' => []],
    'una clave inventada por una integracion'          => ['centroCosto' => 'CC-100'],
    'dos claves de mas a la vez'                       => ['observaciones' => 'x', 'moneda' => 'USD'],
];
foreach ($casos1 as $nombre => $extra) {
    echo "\n  CASO: {$nombre}\n";
    $body = ['tipoDte' => 33, 'receptor' => RECEPTOR_OK, 'detalles' => DETALLE_OK, 'montosSonBrutos' => false] + $extra;
    $b = correr('Base', $body);
    $n = correr('Nuevo', $body);
    pintar('HEAD ', $b);
    pintar('NUEVO', $n);

    if (! $b['ok']) {
        mal('HEAD deberia ACEPTAR (y perder la clave en silencio) y no lo hizo.');
        continue;
    }
    if ($n['ok']) {
        mal('NUEVO deberia rechazar y ACEPTO: la lista no quedo cerrada.');
        continue;
    }
    // EL MENSAJE TIENE QUE NOMBRAR LA CLAVE. No basta con que rechace.
    $faltan = [];
    foreach (array_keys($extra) as $clave) {
        if (! str_contains($n['err'], $clave)) {
            $faltan[] = $clave;
        }
    }
    if ($faltan !== []) {
        mal('el mensaje NO nombra: ' . implode(', ', $faltan));
        continue;
    }
    ok('HEAD aceptaba y perdia la clave; NUEVO da 422 y la nombra.');
}

// El campo del 422 tiene que servirle al panel para resaltar algo.
echo "\n  CAMPO DEVUELTO (el panel lo usa para resaltar el input):\n";
$n = correr('Nuevo', ['tipoDte' => 33, 'receptor' => RECEPTOR_OK, 'detalles' => DETALLE_OK, 'observaciones' => 'x']);
echo "      unitario -> campo='{$n['campo']}'\n";
$n = correr('Nuevo', ['tipoDte' => 33, 'receptor' => RECEPTOR_OK, 'detalles' => DETALLE_OK, 'observaciones' => 'x'], 'documentos[7].', true);
echo "      lote     -> campo='{$n['campo']}'\n";
if ($n['campo'] === 'documentos[7]') {
    ok('el campo del lote conserva el indice del documento culpable.');
} else {
    mal("el campo del lote deberia ser 'documentos[7]' y es '{$n['campo']}'.");
}

// ===========================================================================
// VERIFICACION 2 - LOS LLAMADORES REALES SIGUEN EMITIENDO IGUAL
// ===========================================================================
titulo('VERIFICACION 2 - llamadores reales, uno por uno');
echo "  Los payloads NO estan escritos a mano: los produce la funcion REAL del\n";
echo "  panel, extraida de HEAD y del arbol, con el mismo \$_POST sembrado.\n";

$postFactura = [
    'receptor'        => ['rut' => '76192083-9', 'razonSocial' => 'CLIENTE DE PRUEBA DEL ARNES SPA',
                          'giro' => 'PRUEBAS', 'direccion' => 'CALLE FALSA 123', 'comuna' => 'VALDIVIA',
                          'email' => 'no-existe@ejemplo.invalid'],
    'detalles'        => [['nombre' => 'Servicio', 'cantidad' => '2', 'precioUnitario' => '15000', 'unidad' => 'UN']],
    'montosSonBrutos' => '',
    'formaPago'       => '2',
    'fechaVencimiento'=> '2026-12-31',
    'descuentoGlobalPct' => '10',
    'observaciones'   => 'ENTREGAR EN BODEGA 2',   // lo que el usuario escribia
];

echo "\n  (a) PANEL, FORMULARIO MANUAL -- factura 33 con observaciones tecleadas\n";
$docBase  = \Base\armarDocumentoEmision(33, $postFactura);
$docNuevo = \Nuevo\armarDocumentoEmision(33, $postFactura);
echo '      claves HEAD : ' . implode(', ', array_keys($docBase)) . "\n";
echo '      claves NUEVO: ' . implode(', ', array_keys($docNuevo)) . "\n";

// --- LA DIFERENCIA ESPERADA SE DECLARA, NO SE IGNORA ---
//
// QUE 'observaciones' DESAPAREZCA ES LA ENTREGA, no una regresion: si NO
// desapareciera, eso si seria un fallo. Este bloque comprueba que la diferencia
// sea EXACTAMENTE la declarada -- ni una clave menos, ni una de mas.
//
// Antes esto marcaba [FALLA] sobre la unica clave que veniamos a quitar, por dos
// motivos que conviene dejar escritos: (1) clasificaba cualquier diferencia como
// regresion sin saber cual era deliberada -- el mismo defecto que ya tuvo el
// arnes de certificacion, que contaba como problema los casos que TENIAN que
// lanzar; y (2) array_diff() CONSERVA LAS CLAVES del array original, asi que
// devolvia [8 => 'observaciones'] y la comparacion estricta contra
// ['observaciones'] (indice 0) no podia ser cierta NUNCA. De ahi el array_values.
$diferenciaEsperada = ['observaciones'];
$motivoEsperado     = 'el motor nunca la leyo: validarDocumentoDte() la descartaba en silencio';

$desaparecieron = array_values(array_diff(array_keys($docBase), array_keys($docNuevo)));
$aparecieron    = array_values(array_diff(array_keys($docNuevo), array_keys($docBase)));
sort($desaparecieron);
sort($aparecieron);

if ($desaparecieron === $diferenciaEsperada && $aparecieron === []) {
    ok('DIFERENCIA ESPERADA CUMPLIDA: desaparece ' . implode(', ', $diferenciaEsperada)
        . ' y nada mas. Es lo que vinimos a hacer -- ' . $motivoEsperado . '.');
} else {
    // Cada desviacion se nombra por separado: "no coinciden" no le sirve a nadie.
    if ($desaparecieron === []) {
        mal('NUEVO sigue mandando ' . implode(', ', $diferenciaEsperada)
            . ': la captura del panel no se quito.');
    }
    $regresiones = array_values(array_diff($desaparecieron, $diferenciaEsperada));
    if ($regresiones !== []) {
        mal('REGRESION: desaparecieron ademas ' . implode(', ', $regresiones)
            . '. Esas claves SI las lee el motor.');
    }
    $noQuitadas = array_values(array_diff($diferenciaEsperada, $desaparecieron));
    if ($noQuitadas !== [] && $desaparecieron !== []) {
        mal('NUEVO sigue mandando ' . implode(', ', $noQuitadas)
            . ', que era justo lo que habia que quitar.');
    }
    if ($aparecieron !== []) {
        mal('FUERA DE ALCANCE: NUEVO agrego claves que HEAD no mandaba: '
            . implode(', ', $aparecieron));
    }
}

// Y la prueba de verdad: quitada la diferencia declarada, el documento tiene que
// ser IDENTICO. Esto es lo que descarta que se haya movido un valor de sitio.
$sinEsperadas = $docBase;
foreach ($diferenciaEsperada as $clave) {
    unset($sinEsperadas[$clave]);
}
if ($sinEsperadas === $docNuevo) {
    ok('quitada la diferencia esperada, el documento es identico valor por valor.');
} else {
    mal('el documento cambio en algo mas que ' . implode(', ', $diferenciaEsperada) . '.');
    echo '      HEAD  sin la esperada: ' . json_encode($sinEsperadas, JSON_UNESCAPED_UNICODE) . "\n";
    echo '      NUEVO               : ' . json_encode($docNuevo, JSON_UNESCAPED_UNICODE) . "\n";
}

// La otra mitad del contrato: HEAD TENIA que mandarla. Si no, el volcado de HEAD
// esta mal sacado y todo lo anterior estaria comparando contra nada.
if (! array_key_exists('observaciones', $docBase)) {
    mal('HEAD no manda observaciones: el volcado de HEAD esta mal sacado y este '
        . 'caso no prueba nada.');
} else {
    ok('HEAD si la mandaba ("' . $docBase['observaciones'] . '"), y se perdia entera.');
}
pintar('motor HEAD  <- doc del panel HEAD ', correr('Base', $docBase));
pintar('motor NUEVO <- doc del panel NUEVO', correr('Nuevo', $docNuevo));
if (correr('Nuevo', $docNuevo)['ok']) {
    ok('el panel nuevo emite contra el motor nuevo.');
} else {
    mal('EL PANEL NUEVO YA NO EMITE: esto rompe la emision manual.');
}
// La combinacion que rompe si alguien despliega a medias.
$r = correr('Nuevo', $docBase);
if (! $r['ok']) {
    aviso('DESPLIEGUE PARCIAL: panel VIEJO contra motor NUEVO da 422 ("' . $r['campo'] . '"). '
        . 'Los dos archivos van en el MISMO despliegue.');
} else {
    mal('panel viejo contra motor nuevo NO da 422: la lista no esta cerrada de verdad.');
}

echo "\n  (b) PANEL, FORMULARIO MANUAL -- los otros tres tipos\n";
foreach ([34 => 'factura exenta', 61 => 'nota de credito', 56 => 'nota de debito'] as $tipo => $glosa) {
    $post = $postFactura;
    if ($tipo === 34) {
        $post['detalles'][0]['exento'] = '1';
    }
    if (in_array($tipo, [61, 56], true)) {
        unset($post['formaPago'], $post['fechaVencimiento']);
        $post['referencias'] = [['tipoDocumento' => '33', 'folio' => '100', 'fecha' => '2026-01-15',
                                 'codigo' => '1', 'razon' => 'ANULA FACTURA']];
    }
    $dB = \Base\armarDocumentoEmision($tipo, $post);
    $dN = \Nuevo\armarDocumentoEmision($tipo, $post);
    $rB = correr('Base', $dB);
    $rN = correr('Nuevo', $dN);
    printf("      tipo %d (%-16s) HEAD=%s  NUEVO=%s\n", $tipo, $glosa,
        $rB['ok'] ? 'ACEPTA' : '422:' . $rB['campo'],
        $rN['ok'] ? 'ACEPTA' : '422:' . $rN['campo']);
    if ($rB['ok'] !== $rN['ok']) {
        mal("tipo {$tipo}: HEAD y NUEVO no coinciden.");
    }
}
ok('los cuatro tipos del formulario se comportan igual que en HEAD.');

echo "\n  (c) PANEL, CARGA MASIVA -- sub-lote desde nota_venta\n";
$notas = [
    ['detalle' => json_encode([['nombre' => 'Producto A', 'cantidad' => 3, 'precioUnitario' => 2500]]),
     'receptor_rut' => '76192083-9', 'receptor_razon_social' => 'CLIENTE DE PRUEBA DEL ARNES SPA',
     'receptor_giro' => 'PRUEBAS', 'receptor_direccion' => 'CALLE FALSA 123', 'receptor_comuna' => 'VALDIVIA',
     'receptor_email' => 'no-existe@ejemplo.invalid', 'forma_pago' => 1, 'fecha_vencimiento' => null,
     'boleta_ref_folio' => null, 'boleta_ref_tipo' => null, 'boleta_ref_fecha' => null, 'tipo_dte' => 33],
    // La segunda arrastra la NC que anula una boleta: dos documentos de una nota.
    ['detalle' => json_encode([['nombre' => 'Producto B', 'cantidad' => 1, 'precioUnitario' => 9900]]),
     'receptor_rut' => '76192083-9', 'receptor_razon_social' => 'CLIENTE DE PRUEBA DEL ARNES SPA',
     'receptor_giro' => 'PRUEBAS', 'receptor_direccion' => 'CALLE FALSA 123', 'receptor_comuna' => 'VALDIVIA',
     'receptor_email' => null, 'forma_pago' => 2, 'fecha_vencimiento' => '2026-11-30',
     'boleta_ref_folio' => 55, 'boleta_ref_tipo' => 39, 'boleta_ref_fecha' => '2026-08-01',
     'tipo_dte' => 33],
];
try {
    $subBase  = \Base\armarDocumentosSubLote($notas);
    $subNuevo = \Nuevo\armarDocumentosSubLote($notas);
} catch (\Throwable $e) {
    morir('la siembra del sub-lote no calza con tiposDocumentosPorNota(): ' . $e->getMessage()
        . ' -- ARNES SIN CORRER en esta parte, no es un fallo de la entrega.');
}
if ($subBase !== $subNuevo) {
    mal('la carga masiva produce un payload distinto: no deberia haber cambiado nada.');
} else {
    ok('la carga masiva produce el MISMO payload que en HEAD (' . count($subNuevo) . ' documentos).');
}
foreach ($subNuevo as $i => $d) {
    $rB = correr('Base', $d, "documentos[{$i}].", true);
    $rN = correr('Nuevo', $d, "documentos[{$i}].", true);
    printf("      doc %d tipo %-3d claves(%s)  HEAD=%s  NUEVO=%s\n",
        $i, $d['tipoDte'], implode(',', array_keys($d)),
        $rB['ok'] ? 'ACEPTA' : '422', $rN['ok'] ? 'ACEPTA' : '422:' . $rN['campo']);
    if (! $rN['ok']) {
        mal("la carga masiva dejo de emitir el documento {$i}.");
    }
}

echo "\n  (d) BREWER -- integracion externa por API\n";
echo "      DE DONDE SALEN ESTAS CUATRO CLAVES: no se pueden producir desde este\n";
echo "      repo (grep -i brewer solo aparece en .gitignore y .graphifyignore).\n";
echo "      Estan escritas a mano porque Daniel leyo el repo de Brewer EN EL VPS\n";
echo "      el 08-08-2026 y confirmo que su interfaz PayloadDte, en TypeScript,\n";
echo "      declara EXACTAMENTE tipoDte, receptor, detalles y montosSonBrutos, y\n";
echo "      las declara cerradas: no puede colar una de mas.\n";
echo "      POR QUE QUEDA FIJADO COMO CASO: si alguien estrecha la lista de\n";
echo "      validarDocumentoDte() mas adelante, este test se pone rojo ANTES de\n";
echo "      que Brewer se quede sin emitir. Es el unico consumidor externo\n";
echo "      conocido y no tiene ninguna prueba dentro de este repositorio.\n";

// LAS CUATRO CLAVES DE BREWER, TAL CUAL. No agregar nada aqui "para probar mas":
// el valor de este caso es que sea el payload real, no uno enriquecido.
$brewer = [
    'tipoDte'         => 33,
    'receptor'        => RECEPTOR_OK,
    'detalles'        => [[
        'nombre'                  => 'Cerveza artesanal 500cc',
        'cantidad'                => 24,
        'precioUnitario'          => 1800,
        // El ILA viaja DENTRO de la linea, un nivel que esta entrega no toca.
        'codigoImpuestoAdicional' => '25',
        'tasaImpuestoAdicional'   => 20.5,
    ]],
    'montosSonBrutos' => false,
];

$sobran = array_diff(array_keys($brewer), ['tipoDte', 'receptor', 'detalles', 'montosSonBrutos']);
if ($sobran !== []) {
    mal('este caso dejo de ser el payload de Brewer: sobra ' . implode(', ', $sobran));
} else {
    ok('el caso sigue siendo las cuatro claves de PayloadDte, sin enriquecer.');
}

$rB = correr('Base', $brewer);
$rN = correr('Nuevo', $brewer);
pintar('HEAD ', $rB);
pintar('NUEVO', $rN);
if (! $rN['ok']) {
    mal('BREWER DEJARIA DE EMITIR: la lista se estrecho de mas. Campo: ' . $rN['campo']);
} elseif ($rB !== $rN) {
    mal('el payload de Brewer cambio de veredicto entre HEAD y NUEVO.');
} else {
    ok('Brewer emite igual que en HEAD; el ILA de la linea no se toco.');
}

// ===========================================================================
// VERIFICACION 3 - EL LOTE SIGUE FUNCIONANDO
// ===========================================================================
titulo('VERIFICACION 3 - el lote');

$loteSano = [
    ['tipoDte' => 33, 'receptor' => RECEPTOR_OK, 'detalles' => DETALLE_OK, 'montosSonBrutos' => false],
    ['tipoDte' => 34, 'receptor' => RECEPTOR_OK,
     'detalles' => [['nombre' => 'Servicio exento', 'cantidad' => 1, 'precioUnitario' => 5000, 'exento' => true]],
     'montosSonBrutos' => false],
    ['tipoDte' => 61, 'receptor' => RECEPTOR_OK, 'detalles' => DETALLE_OK, 'montosSonBrutos' => false,
     'referencias' => [['refIndiceLote' => 0, 'codigo' => 1, 'razon' => 'ANULA']]],
];
$todosOk = true;
foreach ($loteSano as $i => $d) {
    $rB = correr('Base', $d, "documentos[{$i}].", true);
    $rN = correr('Nuevo', $d, "documentos[{$i}].", true);
    printf("      doc %d tipo %d  HEAD=%s  NUEVO=%s\n", $i, $d['tipoDte'],
        $rB['ok'] ? 'ACEPTA' : '422:' . $rB['campo'], $rN['ok'] ? 'ACEPTA' : '422:' . $rN['campo']);
    if ($rB !== $rN) {
        $todosOk = false;
    }
}
if ($todosOk) {
    ok('lote sano: HEAD y NUEVO coinciden documento por documento.');
} else {
    mal('el lote sano cambio de veredicto.');
}

echo "\n  LOTE CON UN DOCUMENTO SUCIO EN MEDIO (el 1 de 3):\n";
$loteSucio = $loteSano;
$loteSucio[1]['observaciones'] = 'una glosa que nadie leia';
$primerFallo = null;
foreach ($loteSucio as $i => $d) {
    $r = correr('Nuevo', $d, "documentos[{$i}].", true);
    printf("      doc %d -> %s\n", $i, $r['ok'] ? 'ACEPTA' : "422 campo='{$r['campo']}'");
    if (! $r['ok'] && $primerFallo === null) {
        $primerFallo = $i;
    }
}
if ($primerFallo === 1) {
    ok('el 422 senala el documento 1, que es el culpable, y no otro.');
} else {
    mal("el 422 deberia salir en el documento 1 y salio en " . var_export($primerFallo, true));
}
echo "      NOTA: en el motor real invalido() es 'never' y corta el request entero.\n";
echo "      Eso ocurre en el bucle de validacion, ANTES de asignarSiguienteFolio():\n";
echo "      el sub-lote se cae SIN QUEMAR NINGUN FOLIO. Ese orden no lo verifica\n";
echo "      este arnes -- se lee en public/index.php (validacion ~1221, folios ~1288).\n";

// ===========================================================================
// VERIFICACION 4 - LA PANTALLA DE EMISION
// ===========================================================================
titulo('VERIFICACION 4 - la vista emision-form.php (A/B textual contra HEAD)');
echo "  ESTO NO ES EL RENDER. El A/B de la pantalla servida por HTTP necesita\n";
echo "  el panel corriendo con sesion, y en este entorno no hay shell. Lo que si\n";
echo "  se puede probar sin ambiente: que el UNICO cambio del archivo es el campo.\n\n";

$lineasBase  = explode("\n", $fuente['HEAD_vista']);
$lineasNuevo = explode("\n", $fuente['WORK_vista']);

// Diferencia por lineas, sin librerias: se buscan las lineas de HEAD que ya no
// estan y las nuevas que aparecieron, comparando el conjunto de lineas con
// contenido (el orden del resto no cambia si solo se sustituyo un bloque).
$soloEnHead  = array_values(array_diff($lineasBase, $lineasNuevo));
$soloEnNuevo = array_values(array_diff($lineasNuevo, $lineasBase));

echo "  LINEAS QUE DESAPARECIERON (" . count($soloEnHead) . "):\n";
foreach ($soloEnHead as $l) {
    echo '      - ' . rtrim($l) . "\n";
}
$esperadas = ['<div class="form-campo">', '<label for="observaciones">', 'name="observaciones"', '</div>'];
$inesperadas = [];
foreach ($soloEnHead as $l) {
    $reconocida = false;
    foreach ($esperadas as $e) {
        if (str_contains($l, $e)) {
            $reconocida = true;
            break;
        }
    }
    if (! $reconocida && trim($l) !== '') {
        $inesperadas[] = trim($l);
    }
}
if ($inesperadas === []) {
    ok('todo lo que se fue pertenece al bloque del campo observaciones.');
} else {
    mal('se fueron lineas ajenas al campo: ' . implode(' | ', $inesperadas));
}

if (str_contains($fuente['WORK_vista'], 'name="observaciones"')) {
    mal('la vista TODAVIA declara name="observaciones".');
} else {
    ok('la vista ya no declara ningun control observaciones.');
}
if (str_contains($fuente['HEAD_vista'], 'name="observaciones"')) {
    ok('HEAD si lo declaraba: el volcado de HEAD es el correcto.');
} else {
    morir('HEAD_emision_form.php no trae el campo: el volcado esta mal sacado.');
}

// La nota explicativa tiene que quedar, y tiene que decir QUE hace falta.
foreach (['Formato DTE' => 'nombra el documento normativo',
          'glosa'       => 'dice que es lo que falta ubicar',
          'CodRef'      => 'deja anotado el candidato'] as $marca => $porque) {
    if (str_contains($fuente['WORK_vista'], $marca)) {
        ok("la nota {$porque} ('{$marca}').");
    } else {
        mal("la nota no menciona '{$marca}': {$porque}.");
    }
}
// Y no puede haberse colado en el HTML servido.
if (preg_match('/<!--.*observaciones/is', $fuente['WORK_vista'])) {
    mal('la explicacion quedo en un comentario HTML: se serviria al navegador.');
} else {
    ok('la explicacion esta en un comentario PHP: no viaja al navegador.');
}

// ===========================================================================
// RESUMEN
// ===========================================================================
titulo('RESUMEN');
printf("  fallos: %d\n  avisos: %d\n", $fallos, $avisos);
echo "\n  LO QUE ESTE ARNES NO CUBRE, Y HAY QUE CORRER APARTE:\n";
echo "    - El render real de /ventas/factura, /ventas/nota-credito y\n";
echo "      /ventas/nota-debito (la vista es una sola para los tres).\n";
echo "    - Una emision de punta a punta contra certificacion.\n";
echo "    - PHPUnit: vendor/bin/phpunit --testdox\n";
echo "    - Que ninguna OTRA api_key 'externa' activa mande claves de mas. Brewer\n";
echo "      esta cubierto (caso 2d, cuatro claves confirmadas en su repo el\n";
echo "      08-08-2026), pero api_key.tipo tiene DEFAULT 'externa' y este repo no\n";
echo "      sabe quien mas tiene credenciales. Si aparece otro consumidor, su\n";
echo "      payload se fija aqui como un caso mas ANTES de tocar la lista.\n";

exit($fallos > 0 ? 1 : 0);
