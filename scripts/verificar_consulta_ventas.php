<?php

declare(strict_types=1);

/**
 * ARNES DE VERIFICACION: la funcion de consulta del chat (sin IA, sin pantalla).
 *
 * LA VERIFICACION 1 ES LA QUE MANDA: el total de la consulta tiene que coincidir
 * con el del dashboard para la misma cuenta y el mismo periodo. Si difieren,
 * esto no se despliega.
 *
 * Y NO SE COMPARA CONTRA UN NUMERO ESCRITO A MANO: se extraen los BYTES
 * LITERALES de dashMetricasPorTipo() y dashResumen() del panel, se cargan en un
 * namespace propio y se ejecutan contra la MISMA base sembrada. Es el metodo de
 * siempre -- no se parafrasea la logica del dashboard, se corre.
 *
 * -----------------------------------------------------------------------------
 * COMO PREPARARLO (git NO existe dentro del contenedor):
 *
 *   cd Y:/webserver/sinergia_fac_bol
 *   git show HEAD:panel/public/index.php > scripts/HEAD_panel_index.php
 *   rm scripts/HEAD_panel_index.php
 *
 * Se usa el volcado de HEAD y no el archivo del arbol a proposito: el dashboard
 * es la referencia, y la referencia es lo que hay desplegado.
 *
 * Variables: las del panel -- DB_HOST, DB_NAME, DB_USER, DB_PASS (+ DB_PORT).
 * -----------------------------------------------------------------------------
 */

// TECHO DE MEMORIA. Este arnes no genera PDF, pero siembra cientos de filas y
// las agrega: 256M es el mismo numero del motor en produccion y muere mucho
// antes que la maquina.
ini_set('memory_limit', '256M');

$RAIZ = dirname(__DIR__);
require $RAIZ . '/vendor/autoload.php';
require $RAIZ . '/panel/src/Db.php';

use Plantiflex\Integration\Facturacion\MySqlClienteRepository;
use Plantiflex\Integration\Facturacion\MySqlConsultaVentasRepository;
use Plantiflex\Integration\Facturacion\ConsultaVentasInvalidaException;
use Plantiflex\FacturacionCl\Sii\EstadoContable;

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
function plata(float|int $n): string
{
    return number_format((float) $n, 0, ',', '.');
}

// ===========================================================================
// PANTALLA 0 - BASE, SIEMBRA Y EL DASHBOARD DE HEAD
// ===========================================================================
titulo('PANTALLA 0 - BASE Y DASHBOARD DE REFERENCIA');

$faltan = [];
foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $v) {
    if (getenv($v) === false || getenv($v) === '') {
        $faltan[] = $v;
    }
}
if ($faltan !== []) {
    morir('faltan variables: ' . implode(', ', $faltan) . '. ARNES SIN CORRER.');
}
$pdo = Db::conexion();
ok('conectado con Db::conexion().');

$rutaHead = __DIR__ . '/HEAD_panel_index.php';
if (! is_file($rutaHead)) {
    morir("falta {$rutaHead}. Lee la cabecera de este script. ARNES SIN CORRER.");
}
$head = (string) file_get_contents($rutaHead);

/** Texto literal de una funcion de nivel superior, via tokenizer. */
function extraerFuncion(string $codigo, string $nombre): ?string
{
    $tokens = token_get_all($codigo);
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
            continue;
        }
        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $j++;
        }
        if ($j >= $n || ! is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING || $tokens[$j][1] !== $nombre) {
            continue;
        }
        $texto = 'function ';
        $prof = 0;
        $abierto = false;
        for ($k = $j; $k < $n; $k++) {
            $t = $tokens[$k];
            $texto .= is_array($t) ? $t[1] : $t;
            if ($t === '{') {
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

// EL DASHBOARD DE HEAD, EN SU PROPIO NAMESPACE. Se sustituye NADA de su cuerpo:
// solo se envuelve. Depende de EstadoContable, TipoDte y DASH_TIPO_NOTA_CREDITO,
// que se importan / definen sin tocar sus bytes.
$fnMetricas = extraerFuncion($head, 'dashMetricasPorTipo');
$fnResumen  = extraerFuncion($head, 'dashResumen');
if ($fnMetricas === null || $fnResumen === null) {
    morir('no se pudieron extraer dashMetricasPorTipo()/dashResumen() de HEAD. ARNES SIN CORRER.');
}
printf("  dashMetricasPorTipo: %d bytes  md5 %s\n", strlen($fnMetricas), md5($fnMetricas));
printf("  dashResumen        : %d bytes  md5 %s\n", strlen($fnResumen), md5($fnResumen));

if (! defined('DASH_TIPO_NOTA_CREDITO')) {
    define('DASH_TIPO_NOTA_CREDITO', 61);
}

/**
 * Clases del namespace GLOBAL que un fragmento referencia sin barra inicial.
 *
 * ESTA ES LA TRAMPA QUE HAY QUE ESQUIVAR. Dentro de "namespace DashHead;", un
 * "PDO $pdo" se resuelve como DashHead\PDO -- que no existe -- y el error sale
 * al LLAMAR, no al cargar: "Argument #1 ($pdo) must be of type DashHead\PDO".
 *
 * En los A/B del PDF esto no aparecio por casualidad: la clase de HEAD escribe
 * \sasco\LibreDTE\PDF CON BARRA. El codigo del panel no la lleva porque en su
 * namespace original no le hace falta.
 *
 * SE DETECTAN, NO SE LISTAN A MANO. Arreglar solo PDO habria muerto tres lineas
 * mas abajo con DateTimeImmutable o Throwable. Esto recorre los tokens y se
 * queda con los identificadores que SON una clase, interfaz o enum del
 * namespace global; para lo namespaceado (EstadoContable, TipoDte) class_exists
 * sin barra da false y no se toca, que es lo correcto: esos ya van en su propio
 * use.
 *
 * @return list<string>
 */
function clasesGlobalesReferenciadas(string $codigo): array
{
    $encontradas = [];
    foreach (token_get_all('<?php ' . $codigo) as $t) {
        if (! is_array($t) || $t[0] !== T_STRING) {
            continue;
        }
        $n = $t[1];
        if (str_contains($n, '\\')) {
            continue;
        }
        if (class_exists($n) || interface_exists($n) || enum_exists($n)) {
            $encontradas[$n] = true;
        }
    }
    ksort($encontradas);

    return array_keys($encontradas);
}

// EL CUERPO SON LOS BYTES DE HEAD, SIN TOCAR. El preambulo es CONTEXTO: un
// namespace, unos use y una constante. Nada de eso entra en el cuerpo.
$cuerpo = $fnMetricas . "\n" . $fnResumen . "\n";

$globales = clasesGlobalesReferenciadas($cuerpo);
printf("  clases globales detectadas en el codigo de HEAD: %s\n",
    $globales === [] ? '(ninguna)' : implode(', ', $globales));

$preambulo = "namespace DashHead;\n"
    . "use Plantiflex\\FacturacionCl\\Sii\\EstadoContable;\n"
    . "use Plantiflex\\FacturacionCl\\Enums\\TipoDte;\n"
    . "const DASH_TIPO_NOTA_CREDITO = \\DASH_TIPO_NOTA_CREDITO;\n";
foreach ($globales as $clase) {
    $preambulo .= "use {$clase};\n";
}

$codigo = $preambulo . $cuerpo;

// Y SE DEMUESTRA QUE EL CUERPO NO SE TOCO, igual que el md5 del shim del PDF sin
// su linea de namespace. Si para que compilara hubiera que editar el cuerpo, la
// comparacion dejaria de comparar HEAD y todo lo de abajo no probaria nada.
$cuerpoEvaluado = substr($codigo, strlen($preambulo));
printf("  md5 del cuerpo extraido de HEAD : %s\n", md5($cuerpo));
printf("  md5 del cuerpo que se evalua    : %s\n", md5($cuerpoEvaluado));
if ($cuerpoEvaluado !== $cuerpo) {
    morir('el cuerpo que se evalua NO son los bytes de HEAD: la comparacion no seria valida.');
}
if (str_contains($preambulo, 'function ')) {
    morir('el preambulo contiene codigo, no solo contexto.');
}
ok('el preambulo es solo contexto (namespace + use + const); el cuerpo son los bytes de HEAD.');

if (eval($codigo) === false) {
    morir('eval() fallo al montar el dashboard de HEAD.');
}
if (! function_exists('DashHead\\dashMetricasPorTipo') || ! function_exists('DashHead\\dashResumen')) {
    morir('las funciones de HEAD no quedaron declaradas en DashHead.');
}
ok('dashboard de HEAD cargado como DashHead\\dashMetricasPorTipo()/dashResumen().');

// ---------------------------------------------------------------------------
//  HUMO: SE CORREN LAS DOS, LA DE HEAD **Y LA NUEVA**, ANTES DE SEMBRAR NADA
// ---------------------------------------------------------------------------
//
// LA VERSION ANTERIOR SOLO PROBABA LAS DE HEAD, y por eso un fatal de la funcion
// NUEVA -- "BIGINT UNSIGNED value is out of range" al multiplicar una columna
// unsigned por -1 -- salio a mitad de la verificacion 1, despues de sembrar dos
// cuentas y once documentos. Con esto sale en la pantalla 0, sin haber tocado la
// base y con la causa a la vista.
//
// Se usa un RUT que no existe y un periodo de 1900: la consulta es VALIDA y no
// devuelve filas, asi que lo unico que se ejercita es que el SQL COMPILE Y CORRA.
// Un SUM sobre cero filas basta para que MySQL evalue la expresion.
try {
    \DashHead\dashResumen(\DashHead\dashMetricasPorTipo($pdo, '00000000-0', '1900-01-01', '1900-01-02'));
    ok('las funciones de HEAD se pueden invocar: los type hints resuelven.');
} catch (Throwable $e) {
    morir('no se pueden invocar las funciones de HEAD: ' . $e->getMessage()
        . ' -- revisa el preambulo, no el cuerpo.');
}

// LA FUNCION NUEVA, CON TODAS SUS METRICAS Y TODAS SUS AGRUPACIONES. Una sola
// metrica no habria bastado: el defecto afectaba a las cuatro de dinero, y
// probar solo 'neto' habria dejado 'monto', 'exento', 'impuesto' y 'promedio'
// sin ejercitar.
$humo = new MySqlConsultaVentasRepository($pdo, new MySqlClienteRepository($pdo));
$combinaciones = 0;
foreach (MySqlConsultaVentasRepository::METRICAS as $metrica) {
    foreach (MySqlConsultaVentasRepository::AGRUPACIONES as $agrupacion) {
        try {
            $humo->consultar(0, [
                'metrica'    => $metrica,
                'agruparPor' => $agrupacion,
                'desde'      => '1900-01-01',
                'hasta'      => '1900-01-02',
            ]);
            $combinaciones++;
        } catch (Throwable $e) {
            morir(sprintf(
                "la consulta NUEVA revienta con metrica='%s' agruparPor='%s': %s\n"
                . '    Es un defecto de la ENTREGA, no del arnes, y sale aqui antes de sembrar.',
                $metrica, $agrupacion, $e->getMessage()
            ));
        }
    }
}
printf("  humo: %d combinaciones de metrica x agrupacion corrieron sin reventar.\n", $combinaciones);
ok('la funcion NUEVA compila y corre en las ' . $combinaciones . ' combinaciones.');

// Y el detalle que causo el fatal, comprobado sobre el SQL que se genera: ninguna
// metrica puede multiplicar una columna por el signo.
$reglaSigno = EstadoContable::sqlSumaConSigno('neto');
echo '  forma del signo: ' . $reglaSigno . "\n";
if (str_contains($reglaSigno, 'THEN -neto ELSE neto') && ! str_contains($reglaSigno, '*')) {
    ok('el signo va PEGADO a la columna dentro del CASE, como en dashTopClientes(), '
        . 'no como factor que multiplica una columna unsigned.');
} else {
    mal('sqlSumaConSigno() volvio a la forma que revienta con columnas unsigned.');
}

// --- Siembra: cuenta propia, emisor de produccion, y documentos ---
$cuentaA = null;
$cuentaB = null;
register_shutdown_function(static function () use (&$cuentaA, &$cuentaB, $pdo): void {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    foreach ([[$cuentaA, 'A'], [$cuentaB, 'B']] as [$cid, $etq]) {
        if ($cid === null) {
            continue;
        }
        try {
            $rut = $pdo->prepare('SELECT rut_emisor FROM dte_emisor WHERE cuenta_id = ?');
            $rut->execute([$cid]);
            foreach ($rut->fetchAll(PDO::FETCH_COLUMN) as $r) {
                $pdo->prepare('DELETE FROM dte_emitido WHERE rut_emisor = ?')->execute([$r]);
            }
            $pdo->prepare('DELETE FROM dte_emisor WHERE cuenta_id = ?')->execute([$cid]);
            $pdo->prepare('DELETE FROM cliente WHERE cuenta_id = ?')->execute([$cid]);
            $pdo->prepare('DELETE FROM cuenta WHERE id = ?')->execute([$cid]);
            echo "\n  LIMPIEZA: cuenta {$etq} ({$cid}) y sus documentos borrados.\n";
        } catch (Throwable $e) {
            echo "\n  *** LA LIMPIEZA FALLO para la cuenta {$cid}: " . $e->getMessage() . "\n";
        }
    }
});

/** Cuenta como la crea el codigo real (index.php:6506 / sembrar_demo.php:527). */
function crearCuenta(PDO $pdo): int
{
    $pdo->prepare("INSERT INTO cuenta (email, nombre, estado) VALUES (:e, :n, 'activa')")
        ->execute([':e' => 'arnes-chat-' . bin2hex(random_bytes(6)) . '@ejemplo.invalid', ':n' => 'ARNES CHAT']);

    return (int) $pdo->lastInsertId();
}

function crearEmisor(PDO $pdo, int $cuentaId, string $rut): void
{
    $pdo->prepare(
        'INSERT INTO dte_emisor (rut_emisor, cuenta_id, ambiente, razon_social, giro, acteco, '
        . ' dir_origen, cmna_origen, resolucion_fecha, resolucion_numero) '
        . "VALUES (:r, :c, 'produccion', 'EMISOR DEL ARNES', 'PRUEBAS', 620200, "
        . " 'CALLE 1', 'VALDIVIA', '2020-01-01', 80)"
    )->execute([':r' => $rut, ':c' => $cuentaId]);
}

$folioSeq = 800000;
function emitir(PDO $pdo, string $rut, int $tipo, string $fecha, int $neto, int $iva, int $total,
                string $receptorRut, string $estado, int $exento = 0, int $impAdic = 0): void
{
    global $folioSeq;
    $pdo->prepare(
        'INSERT INTO dte_emitido (rut_emisor, ambiente, tipo_dte, folio, track_id, estado, xml, '
        . ' fecha_emision, neto, exento, iva, impuesto_adicional, total, receptor_rut) '
        . "VALUES (:r, 'produccion', :t, :f, 'TRK', :e, '<x/>', :fe, :n, :ex, :iva, :ia, :tot, :rr)"
    )->execute([
        ':r' => $rut, ':t' => $tipo, ':f' => ++$folioSeq, ':e' => $estado, ':fe' => $fecha,
        ':n' => $neto, ':ex' => $exento, ':iva' => $iva, ':ia' => $impAdic, ':tot' => $total,
        ':rr' => $receptorRut,
    ]);
}

$cuentaA = crearCuenta($pdo);
$cuentaB = crearCuenta($pdo);
$rutA = '76000001-K';
$rutB = '76000002-6';
crearEmisor($pdo, $cuentaA, $rutA);
crearEmisor($pdo, $cuentaB, $rutB);
ok("cuentas sembradas: A={$cuentaA} ({$rutA}), B={$cuentaB} ({$rutB}).");

$CLI1 = '76192083-9';
$CLI2 = '77724622-4';

// Documentos de A, dentro del periodo. Mezcla deliberada:
//   - facturas normales                      -> suman
//   - una nota de credito                    -> RESTA
//   - un rechazado RCT                       -> NO cuenta
//   - un EPR con rechazados adentro          -> SI cuenta (el sobre se proceso)
//   - una factura exenta con exento e ILA
emitir($pdo, $rutA, 33, '2026-03-10', 100000, 19000, 119000, $CLI1, 'enviado');
emitir($pdo, $rutA, 33, '2026-03-20', 200000, 38000, 238000, $CLI1, 'DOK');
emitir($pdo, $rutA, 33, '2026-04-05', 300000, 57000, 357000, $CLI2, 'EPR');
emitir($pdo, $rutA, 61, '2026-04-15',  50000,  9500,  59500, $CLI1, 'EPR');          // NC: resta
emitir($pdo, $rutA, 33, '2026-04-18', 999000, 189810, 1188810, $CLI2, 'RCT');        // rechazado
emitir($pdo, $rutA, 34, '2026-05-02',      0,     0,  80000, $CLI2, 'RPR', 80000);   // exenta, cuenta
emitir($pdo, $rutA, 33, '2026-05-09', 100000, 19000, 124000, $CLI1, 'DOK', 0, 5000); // con ILA
// Fuera del periodo, para que el filtro de fechas tenga algo que dejar afuera.
emitir($pdo, $rutA, 33, '2025-12-01', 777000, 147630, 924630, $CLI1, 'DOK');

// Documentos de B: NUNCA pueden aparecer en las consultas de A.
emitir($pdo, $rutB, 33, '2026-03-11', 555000, 105450, 660450, $CLI1, 'DOK');
emitir($pdo, $rutB, 33, '2026-04-11', 444000,  84360, 528360, $CLI2, 'DOK');
ok('documentos sembrados en A y en B.');

// El maestro de clientes de A, para la etiqueta por cliente.
$repoClientes = new MySqlClienteRepository($pdo);
$repoClientes->crear($cuentaA, ['rut_cliente' => $CLI1, 'razon_social' => 'CLIENTE UNO SPA']);
$repoClientes->crear($cuentaA, ['rut_cliente' => $CLI2, 'razon_social' => 'CLIENTE DOS LTDA']);
// El MISMO RUT en la cuenta B, con OTRO nombre: si el nombre se filtrara entre
// cuentas, se veria aqui.
$repoClientes->crear($cuentaB, ['rut_cliente' => $CLI1, 'razon_social' => 'NOMBRE DE LA CUENTA B']);
ok('maestro de clientes sembrado en A y en B (mismo RUT, nombre distinto).');

$consulta = new MySqlConsultaVentasRepository($pdo, $repoClientes);
$DESDE = '2026-03-01';
$HASTA = '2026-05-31';

// ===========================================================================
// VERIFICACION 1 - EL TOTAL COINCIDE CON EL DEL DASHBOARD
// ===========================================================================
titulo('VERIFICACION 1 - la consulta contra el dashboard (la que manda)');

$porTipo = \DashHead\dashMetricasPorTipo($pdo, $rutA, $DESDE, $HASTA);
$dash    = \DashHead\dashResumen($porTipo);

$rNeto = $consulta->consultar($cuentaA, [
    'metrica' => 'neto', 'agruparPor' => 'ninguna', 'desde' => $DESDE, 'hasta' => $HASTA,
]);
$rDocs = $consulta->consultar($cuentaA, [
    'metrica' => 'documentos', 'agruparPor' => 'ninguna', 'desde' => $DESDE, 'hasta' => $HASTA,
]);

$netoChat = (int) ($rNeto['filas'][0]['valor'] ?? 0);
$docsChat = (int) ($rDocs['filas'][0]['valor'] ?? 0);

echo "\n      cifra          dashboard        chat\n";
echo "      -------------  ---------------  ---------------\n";
printf("      netoPeriodo    %15s  %15s\n", plata($dash['netoPeriodo']), plata($netoChat));
printf("      documentos     %15s  %15s\n", plata($dash['documentos']), plata($docsChat));

if ($netoChat === (int) $dash['netoPeriodo']) {
    ok('el NETO del chat es exactamente el netoPeriodo del dashboard.');
} else {
    mal(sprintf('EL NETO NO COINCIDE: dashboard %s, chat %s. Diferencia %s. ESTO NO SE DESPLIEGA.',
        plata($dash['netoPeriodo']), plata($netoChat), plata($netoChat - (int) $dash['netoPeriodo'])));
}
if ($docsChat === (int) $dash['documentos']) {
    ok('el CONTEO de documentos coincide.');
} else {
    mal(sprintf('EL CONTEO NO COINCIDE: dashboard %d, chat %d.', $dash['documentos'], $docsChat));
}

// Y que el numero no sea cero por casualidad: si los dos fueran 0, coincidirian
// sin probar nada.
if ($netoChat === 0) {
    mal('el neto es 0: la siembra no llego a la consulta y la coincidencia no prueba nada.');
} else {
    ok('el neto no es cero (' . plata($netoChat) . '): la comparacion es significativa.');
}

// ===========================================================================
// VERIFICACION 2 - AISLAMIENTO
// ===========================================================================
titulo('VERIFICACION 2 - una cuenta no ve datos de otra');

$rB = $consulta->consultar($cuentaB, [
    'metrica' => 'neto', 'agruparPor' => 'ninguna', 'desde' => $DESDE, 'hasta' => $HASTA,
]);
$netoB = (int) ($rB['filas'][0]['valor'] ?? 0);
printf("      neto de A: %s   neto de B: %s\n", plata($netoChat), plata($netoB));

$esperadoB = 555000 + 444000;
if ($netoB === $esperadoB) {
    ok('B ve SOLO lo suyo (' . plata($esperadoB) . ').');
} else {
    mal('B ve ' . plata($netoB) . ' y lo suyo son ' . plata($esperadoB) . '.');
}
if ($netoB !== $netoChat) {
    ok('A y B ven cifras distintas: no hay mezcla.');
} else {
    mal('A y B ven lo MISMO: hay fuga entre cuentas.');
}

// Y el NOMBRE tampoco se filtra: el mismo RUT tiene otro nombre en cada cuenta.
$porClienteA = $consulta->consultar($cuentaA, [
    'metrica' => 'neto', 'agruparPor' => 'cliente', 'desde' => $DESDE, 'hasta' => $HASTA,
]);
$nombresA = array_column($porClienteA['filas'], 'etiqueta');
printf("      etiquetas de A: %s\n", implode(' | ', $nombresA));
if (! in_array('NOMBRE DE LA CUENTA B', $nombresA, true)) {
    ok('el nombre que ese RUT tiene en la cuenta B NO aparece en la consulta de A.');
} else {
    mal('se filtro el nombre de un cliente de otra cuenta.');
}

// ===========================================================================
// VERIFICACION 3 - RECHAZADOS FUERA, EPR DENTRO
// ===========================================================================
titulo('VERIFICACION 3 - rechazados y EPR');

// El RCT sembrado vale 999.000 de neto. Si estuviera dentro, el total lo diria.
$conRechazado = $netoChat + 999000;
printf("      neto del chat: %s   neto SI contara el RCT: %s\n", plata($netoChat), plata($conRechazado));
if ($netoChat !== $conRechazado) {
    ok('el documento en RCT no suma.');
}
// Comprobacion directa: se pregunta por el mismo periodo agrupando por tipo y se
// verifica que ningun grupo incluya al rechazado.
$porTipoChat = $consulta->consultar($cuentaA, [
    'metrica' => 'neto', 'agruparPor' => 'tipo', 'desde' => $DESDE, 'hasta' => $HASTA, 'orden' => 'grupo_asc',
]);
echo "\n      tipo                       neto        docs\n";
foreach ($porTipoChat['filas'] as $f) {
    printf("      %-22s %11s  %6d\n", $f['etiqueta'], plata($f['valor']), $f['documentos']);
}
// Facturas 33 que cuentan: 100000 + 200000 + 300000 + 100000 = 700000
$neto33 = 0;
foreach ($porTipoChat['filas'] as $f) {
    if ($f['grupo'] === '33') {
        $neto33 = (int) $f['valor'];
    }
}
if ($neto33 === 700000) {
    ok('las facturas 33 suman 700.000: el RCT de 999.000 quedo fuera.');
} else {
    mal('las facturas 33 suman ' . plata($neto33) . ' y deberian sumar 700.000.');
}

// EL EPR CUENTA. Es el caso que la lista de EstadoContable deja dentro a
// proposito: el sobre se proceso, pero adentro puede haber rechazados que el
// SII no identifica uno por uno.
if (! EstadoContable::esRechazado('EPR')) {
    ok('EPR no esta en la lista de excluidos: un sobre procesado CUENTA, aunque pueda '
        . 'traer rechazos adentro que el SII no identifica.');
} else {
    mal('EPR esta siendo excluido: los totales se irian a cero.');
}
if (EstadoContable::esRechazado('RCT') && ! EstadoContable::esRechazado('RPR')) {
    ok('la lista sigue siendo la de EstadoContable, reusada y no copiada '
        . '(RCT excluido, RPR contando).');
} else {
    mal('el criterio de rechazados no es el de EstadoContable.');
}

// ===========================================================================
// VERIFICACION 4 - PERILLA DESCONOCIDA
// ===========================================================================
titulo('VERIFICACION 4 - lo que no se reconoce revienta');

$casos = [
    ['perilla inventada', ['metrica' => 'neto', 'desde' => $DESDE, 'hasta' => $HASTA, 'moneda' => 'USD'], 'moneda'],
    ['metrica invalida',  ['metrica' => 'margen', 'desde' => $DESDE, 'hasta' => $HASTA], 'margen'],
    ['orden invalido',    ['metrica' => 'neto', 'desde' => $DESDE, 'hasta' => $HASTA, 'orden' => 'alfabetico'], 'alfabetico'],
    ['limite fuera de rango', ['metrica' => 'neto', 'desde' => $DESDE, 'hasta' => $HASTA, 'limite' => 99999], '99999'],
    ['fecha basura',      ['metrica' => 'neto', 'desde' => '2026-02-31', 'hasta' => $HASTA], '2026-02-31'],
];
foreach ($casos as [$nombre, $perillas, $debeNombrar]) {
    try {
        $consulta->consultar($cuentaA, $perillas);
        mal("{$nombre}: SE ACEPTO. Lo que no se reconoce tiene que reventar.");
    } catch (ConsultaVentasInvalidaException $e) {
        if (str_contains($e->getMessage(), $debeNombrar)) {
            ok("{$nombre}: rechazado nombrandolo -> " . substr($e->getMessage(), 0, 70));
        } else {
            mal("{$nombre}: rechazado pero SIN nombrar '{$debeNombrar}': " . $e->getMessage());
        }
    }
}

// EL CASO QUE ES PARTE DE LA ENTREGA: preguntar por producto.
echo "\n  LO QUE NO SE PUEDE RESPONDER:\n";
foreach (array_keys(MySqlConsultaVentasRepository::AGRUPACIONES_IMPOSIBLES) as $imposible) {
    try {
        $consulta->consultar($cuentaA, [
            'metrica' => 'neto', 'agruparPor' => $imposible, 'desde' => $DESDE, 'hasta' => $HASTA,
        ]);
        mal("agrupar por {$imposible}: SE ACEPTO y no hay datos para eso.");
    } catch (ConsultaVentasInvalidaException $e) {
        echo '      ' . $e->getMessage() . "\n";
        // NO basta con que rechace: tiene que EXPLICAR, para que el chat sepa
        // decir que no puede en vez de devolver otra cosa parecida.
        if (str_contains($e->getMessage(), 'no se puede responder')) {
            ok("agrupar por {$imposible}: rechazado con el motivo, no con un error generico.");
        } else {
            mal("agrupar por {$imposible}: rechazado sin explicar por que.");
        }
    }
}

// ===========================================================================
// VERIFICACION 5 - AGRUPAR POR CLIENTE
// ===========================================================================
titulo('VERIFICACION 5 - agrupar por cliente usa el RUT y muestra el nombre');

echo "\n      grupo (RUT)      etiqueta                 neto        docs\n";
foreach ($porClienteA['filas'] as $f) {
    printf("      %-15s  %-22s %11s  %6d\n", $f['grupo'], $f['etiqueta'], plata($f['valor']), $f['documentos']);
}

$grupos = array_column($porClienteA['filas'], 'grupo');
if (in_array($CLI1, $grupos, true) && in_array($CLI2, $grupos, true)) {
    ok('agrupa por el RUT del receptor, normalizado.');
} else {
    mal('los grupos no son los RUT esperados: ' . implode(', ', $grupos));
}

$etiquetaCli1 = null;
foreach ($porClienteA['filas'] as $f) {
    if ($f['grupo'] === $CLI1) {
        $etiquetaCli1 = $f['etiqueta'];
    }
}
if ($etiquetaCli1 === 'CLIENTE UNO SPA') {
    ok('la etiqueta es el nombre del maestro de ESTA cuenta, resuelto con buscarPorRuts() '
        . 'igual que dashTopClientes().');
} else {
    mal("la etiqueta del cliente es '" . var_export($etiquetaCli1, true) . "'.");
}

// Un RUT que NO esta en el maestro se muestra por su RUT, sin fallar.
emitir($pdo, $rutA, 33, '2026-05-20', 12345, 2346, 14691, '60000000-0', 'DOK');
$conDesconocido = $consulta->consultar($cuentaA, [
    'metrica' => 'neto', 'agruparPor' => 'cliente', 'desde' => $DESDE, 'hasta' => $HASTA,
]);
$etiquetas = array_column($conDesconocido['filas'], 'etiqueta');
if (in_array('60000000-0', $etiquetas, true)) {
    ok('un receptor que no esta en el maestro se muestra por su RUT, sin fallar.');
} else {
    mal('el receptor sin ficha no aparece: ' . implode(' | ', $etiquetas));
}

// ===========================================================================
// RESUMEN
// ===========================================================================
titulo('RESUMEN');
printf("  fallos: %d\n  avisos: %d\n", $fallos, $avisos);
printf("\n  MEMORIA: pico %.1f MB (limite %s)\n",
    memory_get_peak_usage(true) / 1048576, ini_get('memory_limit'));

echo "\n  LO QUE ESTE ARNES NO CUBRE:\n";
echo "    - LA IA Y LA PANTALLA: no existen todavia. Esta entrega es solo la\n";
echo "      funcion de consulta, a proposito: la pieza correcta antes de\n";
echo "      conectarle nada.\n";
echo "    - Datos reales. La siembra es propia y se borra al terminar; el volumen\n";
echo "      de produccion (cuantas filas, cuantos meses) sigue sin medirse, y es\n";
echo "      lo que dira si hacen falta indices nuevos.\n";
echo "    - PHPUnit: vendor/bin/phpunit --testdox\n";

exit($fallos > 0 ? 1 : 0);
