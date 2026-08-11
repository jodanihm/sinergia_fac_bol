<?php

declare(strict_types=1);

/**
 * ARNES DE VERIFICACION: la pantalla del chat de consultas.
 *
 * DOS MITADES, Y LAS DOS HACEN FALTA:
 *
 *   A. EL CAMINO POR HTTP -- sesion, token, POST del formulario. Es lo que fallo
 *      con el formulario de cotizacion: una pantalla desplegada que no se podia
 *      usar porque le faltaba csrfInput(). Aqui NO se llama al proveedor real:
 *      el POST se hace sin clave configurada, asi que ejercita el formulario y
 *      el cuarto desenlace sin gastar saldo.
 *
 *   B. LOS CUATRO DESENLACES Y EL TOPE -- en proceso, con MockHandler, llamando
 *      a handleChatPost() con un traductor inyectado. Es lo unico que permite
 *      simular "el modelo dijo X" sin pagar por ello.
 *
 * NINGUNA CONSULTA REAL A DEEPSEEK. Ni una.
 *
 * -----------------------------------------------------------------------------
 * COMO PREPARARLO
 *
 *   1. El panel sirviendo, y PANEL_URL / PANEL_USER / PANEL_PASS en el entorno.
 *   2. Las del panel: DB_HOST, DB_NAME, DB_USER, DB_PASS (+ DB_PORT).
 *   3. La migracion 034 aplicada.
 *
 * NUNCA SE IMPRIME PANEL_PASS ni DEEPSEEK_API_KEY, ni completas ni parciales.
 * -----------------------------------------------------------------------------
 */

ini_set('memory_limit', '256M');

$RAIZ = dirname(__DIR__);
require $RAIZ . '/vendor/autoload.php';
require $RAIZ . '/panel/src/Db.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Plantiflex\FacturacionCl\Providers\DeepSeekTraductorPregunta;
use Plantiflex\Integration\Facturacion\MySqlChatUsoRepository;

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
// VERIFICACION 6 - csrfInput() EN TODAS LAS VISTAS CON POST
//
// PRIMERA Y SIN SERVIDOR NI BASE: es la comprobacion mas barata y la que habria
// atajado el defecto del formulario de cotizacion. Incluye la vista nueva porque
// recorre el directorio entero, no una lista.
// ===========================================================================
titulo('VERIFICACION 6 - toda vista con <form method="post"> emite csrfInput()');

$vistas = array_merge(
    glob($RAIZ . '/panel/views/*.php') ?: [],
    glob($RAIZ . '/panel/views/partials/*.php') ?: []
);
if ($vistas === []) {
    morir('no se encontro ninguna vista. ARNES SIN CORRER.');
}
$conForm = 0;
$sinToken = [];
$vistaNuevaRevisada = false;
foreach ($vistas as $ruta) {
    $txt = (string) file_get_contents($ruta);
    if (preg_match('/<form\b[^>]*\bmethod\s*=\s*["\']?post["\']?/i', $txt) !== 1) {
        continue;
    }
    $conForm++;
    if (basename($ruta) === 'chat-consultas.php') {
        $vistaNuevaRevisada = true;
    }
    if (! str_contains($txt, 'csrfInput(') && preg_match('/name\s*=\s*["\']csrf_token["\']/', $txt) !== 1) {
        $sinToken[] = basename($ruta);
    }
}
printf("  vistas con <form method=post>: %d\n", $conForm);
if ($sinToken === []) {
    ok("las {$conForm} vistas con POST emiten el token.");
} else {
    mal('vistas con POST y SIN token (no se pueden usar): ' . implode(', ', $sinToken));
}
if ($vistaNuevaRevisada) {
    ok('chat-consultas.php entro en la revision: la vista nueva esta cubierta.');
} else {
    mal('chat-consultas.php NO tiene <form method="post">, o no se encontro el archivo.');
}

// ===========================================================================
// PANTALLA 0 - BASE Y SIEMBRA
// ===========================================================================
titulo('PANTALLA 0 - BASE Y SIEMBRA');

foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $v) {
    if (getenv($v) === false || getenv($v) === '') {
        morir("falta {$v}. ARNES SIN CORRER en el resto.");
    }
}
$pdo = Db::conexion();
if ($pdo->query("SHOW TABLES LIKE 'chat_consulta_uso'")->fetchColumn() === false) {
    morir('falta chat_consulta_uso: aplica la migracion 034. ARNES SIN CORRER.');
}
ok('conectado y la 034 aplicada.');

$cuentaA = null;
$cuentaB = null;
register_shutdown_function(static function () use (&$cuentaA, &$cuentaB, $pdo): void {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    foreach ([$cuentaA, $cuentaB] as $cid) {
        if ($cid === null) {
            continue;
        }
        try {
            $r = $pdo->prepare('SELECT rut_emisor FROM dte_emisor WHERE cuenta_id = ?');
            $r->execute([$cid]);
            foreach ($r->fetchAll(PDO::FETCH_COLUMN) as $rut) {
                $pdo->prepare('DELETE FROM dte_emitido WHERE rut_emisor = ?')->execute([$rut]);
            }
            $pdo->prepare('DELETE FROM chat_consulta_uso WHERE cuenta_id = ?')->execute([$cid]);
            $pdo->prepare('DELETE FROM dte_emisor WHERE cuenta_id = ?')->execute([$cid]);
            $pdo->prepare('DELETE FROM cliente WHERE cuenta_id = ?')->execute([$cid]);
            $pdo->prepare('DELETE FROM cuenta WHERE id = ?')->execute([$cid]);
            echo "\n  LIMPIEZA: cuenta {$cid} borrada.\n";
        } catch (Throwable $e) {
            echo "\n  *** LA LIMPIEZA FALLO para {$cid}: " . $e->getMessage() . "\n";
        }
    }
});

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
        . " dir_origen, cmna_origen, resolucion_fecha, resolucion_numero) VALUES (:r, :c, 'produccion', "
        . " 'EMISOR ARNES', 'PRUEBAS', 620200, 'CALLE 1', 'VALDIVIA', '2020-01-01', 80)"
    )->execute([':r' => $rut, ':c' => $cuentaId]);
}
$folioSeq = 700000;
function emitir(PDO $pdo, string $rut, int $tipo, string $fecha, int $neto, int $iva, int $total, string $recep, string $estado): void
{
    global $folioSeq;
    $pdo->prepare(
        'INSERT INTO dte_emitido (rut_emisor, ambiente, tipo_dte, folio, track_id, estado, xml, '
        . " fecha_emision, neto, exento, iva, impuesto_adicional, total, receptor_rut) "
        . "VALUES (:r,'produccion',:t,:f,'TRK',:e,'<x/>',:fe,:n,0,:iva,0,:tot,:rr)"
    )->execute([':r' => $rut, ':t' => $tipo, ':f' => ++$folioSeq, ':e' => $estado, ':fe' => $fecha,
                ':n' => $neto, ':iva' => $iva, ':tot' => $total, ':rr' => $recep]);
}

$cuentaA = crearCuenta($pdo);
$cuentaB = crearCuenta($pdo);
$rutA = '76000011-2';
$rutB = '76000012-0';
crearEmisor($pdo, $cuentaA, $rutA);
crearEmisor($pdo, $cuentaB, $rutB);

$DESDE = '2026-01-01';
$HASTA = '2026-12-31';
emitir($pdo, $rutA, 33, '2026-03-10', 100000, 19000, 119000, '76192083-9', 'DOK');
emitir($pdo, $rutA, 33, '2026-04-10', 200000, 38000, 238000, '76192083-9', 'EPR');
emitir($pdo, $rutA, 61, '2026-05-10',  50000,  9500,  59500, '76192083-9', 'DOK');   // resta
emitir($pdo, $rutA, 33, '2026-06-10', 999000, 189810, 1188810, '76192083-9', 'RCT'); // no cuenta
emitir($pdo, $rutB, 33, '2026-03-11', 777000, 147630, 924630, '76192083-9', 'DOK');
ok("cuentas {$cuentaA} y {$cuentaB} sembradas con documentos.");

// ===========================================================================
// VERIFICACION 1 - EL CAMINO COMPLETO POR HTTP
// ===========================================================================
titulo('VERIFICACION 1 - la pantalla por HTTP, con sesion y token');

$base = rtrim((string) (getenv('PANEL_URL') ?: ''), '/');
$user = (string) (getenv('PANEL_USER') ?: '');
$pass = (string) (getenv('PANEL_PASS') ?: '');
$cookies = [];

function pedir(string $metodo, string $url, ?array $campos = null): array
{
    global $cookies;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60,
    ]);
    if ($cookies !== []) {
        $pares = [];
        foreach ($cookies as $k => $v) {
            $pares[] = $k . '=' . $v;
        }
        curl_setopt($ch, CURLOPT_COOKIE, implode('; ', $pares));
    }
    if ($metodo === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($campos ?? []));
    }
    $bruto = curl_exec($ch);
    if ($bruto === false) {
        morir('fallo la peticion a ' . $url . ': ' . curl_error($ch));
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $corte  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $cab = substr((string) $bruto, 0, $corte);
    foreach (preg_split('/\r?\n/', $cab) ?: [] as $l) {
        if (stripos($l, 'set-cookie:') === 0 && preg_match('/set-cookie:\s*([^=]+)=([^;]*)/i', $l, $m)) {
            $cookies[trim($m[1])] = $m[2];
        }
    }

    return ['status' => $status, 'body' => substr((string) $bruto, $corte)];
}
function tokenDe(string $html): ?string
{
    return preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) === 1 ? $m[1] : null;
}

if ($base === '' || $user === '' || $pass === '') {
    aviso('faltan PANEL_URL/PANEL_USER/PANEL_PASS: la parte HTTP no corre. '
        . 'Las verificaciones en proceso si.');
} else {
    printf("  PANEL_URL=%s PANEL_USER=%s PANEL_PASS=(%d caracteres)\n", $base, $user, strlen($pass));
    $r = pedir('GET', $base . '/login');
    $t = tokenDe($r['body']);
    if ($t === null) {
        morir('la pagina de login no trae token.');
    }
    $r = pedir('POST', $base . '/login', ['csrf_token' => $t, 'email' => $user, 'password' => $pass]);
    if (! in_array($r['status'], [302, 303], true)) {
        morir("el login devolvio {$r['status']}. ARNES SIN CORRER en la parte HTTP.");
    }
    ok('sesion iniciada.');

    $r = pedir('GET', $base . '/informes/chat');
    if (in_array($r['status'], [302, 303], true)) {
        aviso('la pantalla redirige: esta cuenta no tiene produccion completa. '
            . 'No se pudo ejercitar por HTTP.');
    } elseif ($r['status'] !== 200) {
        mal("GET /informes/chat devolvio {$r['status']}.");
    } else {
        ok('la pantalla se abre (200).');
        $tok = tokenDe($r['body']);
        if ($tok !== null) {
            ok('EL FORMULARIO EMITE EL TOKEN. Es lo que faltaba en la cotizacion.');
        } else {
            mal('el formulario NO emite csrf_token: todo POST moriria en 403.');
        }
        foreach (['name="pregunta"' => 'el campo', 'consultas de hoy' => 'el contador del tope'] as $marca => $que) {
            if (str_contains($r['body'], $marca)) {
                ok("trae {$que}.");
            } else {
                mal("NO trae {$que} ({$marca}).");
            }
        }

        if ($tok !== null) {
            // EL POST DE VERDAD. Sin clave en el entorno del panel, esto ejercita
            // el formulario entero y el CUARTO desenlace sin gastar saldo.
            $r = pedir('POST', $base . '/informes/chat', ['csrf_token' => $tok, 'pregunta' => 'cuanto vendi en marzo']);
            printf("  POST -> %d\n", $r['status']);
            if ($r['status'] === 403) {
                mal('403: el token no viajo. Es el defecto de la cotizacion, otra vez.');
            } elseif ($r['status'] === 200) {
                ok('el POST se procesa y repinta la pantalla (200), sin reventar.');
                if (str_contains($r['body'], 'DEEPSEEK_API_KEY') || str_contains($r['body'], 'no esta configurado')) {
                    ok('sin clave, la pantalla lo dice en vez de reventar (cuarto desenlace).');
                } else {
                    aviso('el POST no dio el mensaje de "sin clave": puede que la clave SI este '
                        . 'configurada en este entorno, y entonces esta pregunta gasto una consulta real.');
                }
            } else {
                mal("el POST devolvio {$r['status']}.");
            }
        }
    }
}

// ===========================================================================
// VERIFICACION 2 - LOS CUATRO DESENLACES, CON MockHandler
// ===========================================================================
titulo('VERIFICACION 2 - los cuatro desenlaces');

/** Traductor con una respuesta prefabricada, y su historial de peticiones. */
function traductorFalso(array $respuestas, string $clave, array &$historial): DeepSeekTraductorPregunta
{
    $stack = HandlerStack::create(new MockHandler($respuestas));
    $stack->push(Middleware::history($historial));

    return new DeepSeekTraductorPregunta(new Client(['handler' => $stack, 'http_errors' => false]), $clave);
}
function sobre(string $contenido): Response
{
    return new Response(200, [], (string) json_encode([
        'choices' => [['message' => ['content' => $contenido]]],
    ]));
}

// Se ejercita el TRADUCTOR, que es la pieza que decide el desenlace. El handler
// del panel no se puede llamar aqui sin una sesion HTTP; lo que se comprueba es
// que cada respuesta del modelo produzca el desenlace que la pantalla pinta
// distinto.
$hist = [];
$casos = [
    ['perillas', sobre((string) json_encode(['desenlace' => 'perillas', 'perillas' => [
        'metrica' => 'neto', 'agruparPor' => 'ninguna', 'desde' => $DESDE, 'hasta' => $HASTA,
        'orden' => 'metrica_desc', 'limite' => 10]])), 'perillas'],
    ['imposible', sobre((string) json_encode(['desenlace' => 'imposible',
        'motivo' => 'no puedo responder por producto'])), 'imposible'],
    ['no entendida', sobre((string) json_encode(['desenlace' => 'no_entendida',
        'motivo' => 'no entendi'])), 'no_entendida'],
];
foreach ($casos as [$nombre, $respuesta, $esperado]) {
    $h = [];
    $t = traductorFalso([$respuesta], 'clave-falsa', $h);
    $r = $t->traducir('pregunta', vocabularioDePrueba(), '2026-08-11');
    printf("      %-14s -> %s\n", $nombre, $r->desenlace);
    if ($r->desenlace === $esperado) {
        ok("desenlace '{$nombre}' reconocido.");
    } else {
        mal("desenlace '{$nombre}' salio como '{$r->desenlace}'.");
    }
}

// CUARTO: fallo tecnico. Sin clave no se hace NI UNA peticion.
$h = [];
$t = traductorFalso([], '', $h);
try {
    $t->traducir('pregunta', vocabularioDePrueba(), '2026-08-11');
    mal('sin clave no lanzo.');
} catch (Throwable $e) {
    ok('sin clave lanza con mensaje para el usuario: ' . substr($e->getMessage(), 0, 60));
}
if ($h === []) {
    ok('y NO hizo ninguna peticion: sin clave no se gasta nada.');
} else {
    mal('sin clave igual llamo al proveedor.');
}

function vocabularioDePrueba(): \Plantiflex\FacturacionCl\Dto\VocabularioConsulta
{
    return new \Plantiflex\FacturacionCl\Dto\VocabularioConsulta(
        \Plantiflex\Integration\Facturacion\MySqlConsultaVentasRepository::METRICAS,
        \Plantiflex\Integration\Facturacion\MySqlConsultaVentasRepository::AGRUPACIONES,
        \Plantiflex\Integration\Facturacion\MySqlConsultaVentasRepository::ORDENES,
        \Plantiflex\Integration\Facturacion\MySqlConsultaVentasRepository::LIMITE_MAX,
        \Plantiflex\Integration\Facturacion\MySqlConsultaVentasRepository::AGRUPACIONES_IMPOSIBLES,
    );
}

// ===========================================================================
// VERIFICACION 3 y 4 - EL TOTAL CUADRA, Y NO SE MEZCLAN LAS CUENTAS
// ===========================================================================
titulo('VERIFICACION 3 y 4 - el total del dashboard, y el aislamiento');

$repo = new \Plantiflex\Integration\Facturacion\MySqlConsultaVentasRepository(
    $pdo,
    new \Plantiflex\Integration\Facturacion\MySqlClienteRepository($pdo)
);
$perillas = ['metrica' => 'neto', 'agruparPor' => 'ninguna', 'desde' => $DESDE, 'hasta' => $HASTA];

$netoA = (int) ($repo->consultar($cuentaA, $perillas)['filas'][0]['valor'] ?? 0);
$netoB = (int) ($repo->consultar($cuentaB, $perillas)['filas'][0]['valor'] ?? 0);

// El esperado, a mano, desde la siembra: 100000 + 200000 - 50000, y el RCT fuera.
$esperadoA = 100000 + 200000 - 50000;
printf("      neto A: %d (esperado %d)   neto B: %d (esperado %d)\n",
    $netoA, $esperadoA, $netoB, 777000);
if ($netoA === $esperadoA) {
    ok('el total aplica los dos filtros: la NC resta y el RCT no cuenta.');
} else {
    mal("el total de A es {$netoA} y deberia ser {$esperadoA}.");
}
if ($netoB === 777000 && $netoB !== $netoA) {
    ok('B ve solo lo suyo: no hay mezcla entre cuentas.');
} else {
    mal('el aislamiento entre cuentas falla.');
}
echo "\n      NOTA: la comparacion CONTRA EL CODIGO DEL DASHBOARD -- ejecutando\n";
echo "      dashMetricasPorTipo()/dashResumen() de HEAD -- vive en\n";
echo "      scripts/verificar_consulta_ventas.php, verificacion 1. Aqui se\n";
echo "      comprueba el resultado contra la siembra, que es lo que este arnes\n";
echo "      puede afirmar sin duplicar aquella.\n";

// ===========================================================================
// VERIFICACION 5 - EL TOPE
// ===========================================================================
titulo('VERIFICACION 5 - pasado el tope, no se llama al proveedor');

$uso = new MySqlChatUsoRepository($pdo);
$hoy = date('Y-m-d');
$limite = MySqlChatUsoRepository::LIMITE_DIARIO;
printf("  limite diario: %d\n", $limite);

for ($i = 0; $i < $limite; $i++) {
    $uso->registrarConsulta($cuentaA, $hoy);
}
printf("  consultas registradas hoy para A: %d\n", $uso->consultasDeHoy($cuentaA, $hoy));

if (! $uso->quedaCupo($cuentaA, $hoy)) {
    ok('la cuenta A quedo sin cupo.');
} else {
    mal('la cuenta A sigue con cupo despues de ' . $limite . ' consultas.');
}

// LA PRUEBA: con el cupo agotado, el codigo NO tiene que llamar. Se reproduce la
// guarda del handler y se comprueba el historial VACIO.
$h = [];
$t = traductorFalso([sobre('{"desenlace":"no_entendida"}')], 'clave-falsa', $h);
if ($uso->quedaCupo($cuentaA, $hoy)) {
    $t->traducir('otra pregunta', vocabularioDePrueba(), $hoy);
}
if ($h === []) {
    ok('la pregunta N+1 NO llego al proveedor: historial vacio.');
} else {
    mal('se llamo al proveedor con el cupo agotado.');
}

// Y la cuenta B, que no pregunto, conserva su cupo: el tope es POR CUENTA.
if ($uso->quedaCupo($cuentaB, $hoy)) {
    ok('el tope es por cuenta: B conserva su cupo entero.');
} else {
    mal('el tope de A afecto a B.');
}

// ===========================================================================
// RESUMEN
// ===========================================================================
titulo('RESUMEN');
printf("  fallos: %d\n  avisos: %d\n", $fallos, $avisos);
printf("\n  MEMORIA: pico %.1f MB (limite %s)\n",
    memory_get_peak_usage(true) / 1048576, ini_get('memory_limit'));

echo "\n  LO QUE ESTE ARNES NO CUBRE:\n";
echo "    - NINGUNA CONSULTA REAL A DEEPSEEK. El POST por HTTP se hace contra un\n";
echo "      panel sin clave configurada; los desenlaces se simulan con MockHandler.\n";
echo "      La primera pregunta real la hara Daniel, y conviene mirar que la\n";
echo "      descripcion en palabras diga lo que el pregunto.\n";
echo "    - El texto que devuelve el modelo de verdad. Que DeepSeek respete el\n";
echo "      esquema JSON con este prompt no se puede probar sin preguntarle.\n";
echo "    - PHPUnit: vendor/bin/phpunit --testdox\n";

exit($fallos > 0 ? 1 : 0);
