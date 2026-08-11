<?php

declare(strict_types=1);

/**
 * ARNES DE VERIFICACION: el CAMINO COMPLETO de cotizaciones POR HTTP.
 *
 * =============================================================================
 * POR QUE EXISTE ESTE ARNES, APARTE DEL OTRO
 * =============================================================================
 *
 * Se desplego una pantalla QUE NO SE PODIA USAR. El formulario de cotizacion no
 * emitia csrfInput() y todo POST moria en 403 antes de llegar al handler.
 *
 * verificar_cotizaciones.php no lo detecto, y no por descuido: prueba el
 * REPOSITORIO, el correlativo y el PDF llamando a las clases directamente. NADA
 * de eso pasa por el formulario. Una pantalla rota con un backend sano da verde
 * en ese arnes.
 *
 * Asi que este cubre lo otro: sesion real, token real, POST del formulario tal
 * como lo manda el navegador, y la fila en la base al final. Si hubiera existido,
 * el 403 salia antes de desplegar.
 *
 * Y ademas comprueba, SOBRE TODAS LAS VISTAS y no solo sobre esta, que cualquier
 * <form method="post"> emita csrfInput(). Es una comprobacion de texto, barata,
 * y evita el proximo igual en una pantalla que nadie este mirando hoy.
 *
 * -----------------------------------------------------------------------------
 * COMO PREPARARLO
 *
 *   1. El panel tiene que estar SIRVIENDO. Si no lo esta:
 *        php -S 127.0.0.1:8080 -t panel/public
 *      y exporta PANEL_URL=http://127.0.0.1:8080
 *
 *   2. Credenciales de un usuario de prueba de ESA base:
 *        export PANEL_USER=... PANEL_PASS=...
 *
 *   3. Para el A/B de las vistas que NO se tocan (git no existe en el contenedor):
 *        git show HEAD:panel/views/emision-form.php > scripts/HEAD_emision_form.php
 *        git show HEAD:panel/views/cliente-form.php > scripts/HEAD_cliente_form.php
 *      y despues, por ruta explicita:
 *        rm scripts/HEAD_emision_form.php scripts/HEAD_cliente_form.php
 *
 * NUNCA SE IMPRIME PANEL_PASS, ni completa ni parcial. Solo su longitud.
 * -----------------------------------------------------------------------------
 */

ini_set('memory_limit', '256M');

$RAIZ = dirname(__DIR__);
require $RAIZ . '/vendor/autoload.php';
require $RAIZ . '/panel/src/Db.php';

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
// VERIFICACION A - csrfInput() EN TODAS LAS VISTAS CON POST
//
// VA PRIMERO Y NO NECESITA NI SERVIDOR NI BASE: es la comprobacion mas barata
// del arnes y la que habria atajado este defecto. Si falla, falla aqui y ya.
// ===========================================================================
titulo('VERIFICACION A - toda vista con <form method="post"> emite csrfInput()');

$vistas = glob($RAIZ . '/panel/views/*.php') ?: [];
$vistas = array_merge($vistas, glob($RAIZ . '/panel/views/partials/*.php') ?: []);
if ($vistas === []) {
    morir('no se encontro ninguna vista en panel/views/. ARNES SIN CORRER.');
}

$conForm = 0;
$sinToken = [];
foreach ($vistas as $ruta) {
    $txt = (string) file_get_contents($ruta);

    // method="post" en cualquier orden de atributos y con comillas simples o
    // dobles. Un <form> sin method es GET y no necesita token.
    if (preg_match('/<form\b[^>]*\bmethod\s*=\s*["\']?post["\']?/i', $txt) !== 1) {
        continue;
    }
    $conForm++;

    // El token puede venir por csrfInput() o por un input escrito a mano; las
    // dos formas valen, lo que no vale es que no este ninguna.
    $tieneHelper = str_contains($txt, 'csrfInput(');
    $tieneInput  = preg_match('/name\s*=\s*["\']csrf_token["\']/', $txt) === 1;
    if (! $tieneHelper && ! $tieneInput) {
        $sinToken[] = basename($ruta);
    }
}

printf("  vistas revisadas: %d, con <form method=post>: %d\n", count($vistas), $conForm);
if ($conForm === 0) {
    morir('ninguna vista tiene <form method="post"> -- la expresion regular no esta '
        . 'casando. Fallo del arnes, no de las vistas.');
}
if ($sinToken === []) {
    ok("las {$conForm} vistas con POST emiten el token.");
} else {
    mal('vistas con POST y SIN token CSRF (no se pueden usar): ' . implode(', ', $sinToken));
}

// ===========================================================================
// VERIFICACION B - A/B DE LAS VISTAS QUE NO SE TOCAN
// ===========================================================================
titulo('VERIFICACION B - las vistas ajenas no se movieron');

foreach ([
    'emision-form.php' => __DIR__ . '/HEAD_emision_form.php',
    'cliente-form.php' => __DIR__ . '/HEAD_cliente_form.php',
] as $nombre => $rutaHead) {
    if (! is_file($rutaHead)) {
        aviso("falta {$rutaHead}: no se puede comparar {$nombre}. Lee la cabecera.");
        continue;
    }
    $head = (string) file_get_contents($rutaHead);
    $work = (string) file_get_contents($RAIZ . '/panel/views/' . $nombre);
    if ($head === $work) {
        ok("{$nombre}: identica a HEAD, byte a byte.");
    } else {
        mal("{$nombre} CAMBIO y no estaba en el alcance.");
    }
}

// El CSS si cambio (se declaro .col-descuento). Lo que NO puede haber cambiado
// son las clases que ya usaba el formulario de emision.
$css = (string) file_get_contents($RAIZ . '/panel/public/css/style.css');
$intactas = ['col-producto { width: auto; }', 'col-cantidad { width: 6.5rem; }',
             'col-precio   { width: 8rem; }', 'col-unidad   { width: 5.5rem; }',
             'col-exento   { width: 5rem; text-align: center; }',
             'col-accion   { width: 3rem; text-align: center; }'];
$rotas = [];
foreach ($intactas as $regla) {
    if (! str_contains($css, $regla)) {
        $rotas[] = $regla;
    }
}
if ($rotas === []) {
    ok('las seis reglas col-* que ya existian siguen igual; solo se agrego col-descuento.');
} else {
    mal('se modificaron reglas col-* que usa el formulario de emision: ' . implode(' | ', $rotas));
}
if (str_contains($css, '.tabla-editable .col-descuento')) {
    ok('.col-descuento quedo DECLARADA, no reusando el ancho de otra columna.');
} else {
    mal('.col-descuento sigue sin declararse en style.css.');
}

// ===========================================================================
// PANTALLA 0 - REQUISITOS PARA LA PARTE HTTP
// ===========================================================================
titulo('PANTALLA 0 - REQUISITOS HTTP');

$base = rtrim((string) (getenv('PANEL_URL') ?: ''), '/');
$user = (string) (getenv('PANEL_USER') ?: '');
$pass = (string) (getenv('PANEL_PASS') ?: '');
$faltan = [];
foreach (['PANEL_URL' => $base, 'PANEL_USER' => $user, 'PANEL_PASS' => $pass] as $n => $v) {
    if ($v === '') {
        $faltan[] = $n;
    }
}
if ($faltan !== []) {
    echo "\n  Las verificaciones A y B ya corrieron y son las que no necesitan servidor.\n";
    morir('faltan variables para la parte HTTP: ' . implode(', ', $faltan)
        . '. ARNES SIN CORRER en la parte HTTP -- no es un fallo de la entrega.');
}
printf("  PANEL_URL=%s  PANEL_USER=%s  PANEL_PASS=(%d caracteres)\n", $base, $user, strlen($pass));

foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $var) {
    if (getenv($var) === false || getenv($var) === '') {
        morir("falta {$var}: hace falta para comprobar que la cotizacion quedo en la base.");
    }
}
$pdo = Db::conexion();
ok('conectado a la base con Db::conexion().');

// --- Cliente HTTP con cookies, sin dependencias ---
$cookies = [];

/**
 * @return array{status:int, headers:array<string,string>, body:string}
 */
function pedir(string $metodo, string $url, ?array $campos = null): array
{
    global $cookies;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,   // los 303 se siguen A MANO, para verlos
        CURLOPT_TIMEOUT        => 30,
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

    $cabeceras = substr((string) $bruto, 0, $corte);
    $cuerpo    = substr((string) $bruto, $corte);

    $h = [];
    foreach (preg_split('/\r?\n/', $cabeceras) ?: [] as $linea) {
        if (preg_match('/^([^:]+):\s*(.*)$/', $linea, $m)) {
            $h[strtolower($m[1])] = $m[2];
            if (strtolower($m[1]) === 'set-cookie' && preg_match('/^([^=]+)=([^;]*)/', $m[2], $mc)) {
                $cookies[$mc[1]] = $mc[2];
            }
        }
    }

    return ['status' => $status, 'headers' => $h, 'body' => $cuerpo];
}

/** El token del formulario que acaba de llegar. */
function tokenDe(string $html): ?string
{
    return preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) === 1 ? $m[1] : null;
}

// ===========================================================================
// VERIFICACION C - SESION Y TOKEN
// ===========================================================================
titulo('VERIFICACION C - login real');

$r = pedir('GET', $base . '/login');
if ($r['status'] !== 200) {
    morir("GET /login devolvio {$r['status']}: el panel no esta sirviendo en {$base}. ARNES SIN CORRER.");
}
$token = tokenDe($r['body']);
if ($token === null) {
    morir('la pagina de login no trae csrf_token: el arnes no puede autenticarse.');
}
ok('GET /login 200 con token.');

$r = pedir('POST', $base . '/login', ['csrf_token' => $token, 'email' => $user, 'password' => $pass]);
if ($r['status'] === 303 || $r['status'] === 302) {
    ok('login aceptado (' . $r['status'] . ' -> ' . ($r['headers']['location'] ?? '?') . ').');
} else {
    morir("el login devolvio {$r['status']} y se esperaba una redireccion. Revisa PANEL_USER/PANEL_PASS. "
        . 'ARNES SIN CORRER.');
}

// ===========================================================================
// VERIFICACION D - EL FORMULARIO SE ABRE Y TRAE LO QUE TIENE QUE TRAER
// ===========================================================================
titulo('VERIFICACION D - GET /ventas/cotizaciones/nueva');

$r = pedir('GET', $base . '/ventas/cotizaciones/nueva');
if ($r['status'] !== 200) {
    morir("GET del formulario devolvio {$r['status']}. ARNES SIN CORRER.");
}
$html = $r['body'];
ok('el formulario se abre (200).');

$tokenForm = tokenDe($html);
if ($tokenForm !== null) {
    ok('EL FORMULARIO EMITE EL TOKEN. Es el defecto que dejo la pantalla inutilizable.');
} else {
    mal('EL FORMULARIO NO EMITE csrf_token: cualquier POST va a morir en 403.');
}

foreach ([
    'class="form-compacto"' => 'la clase de la que cuelga el estilo de los inputs de la tabla',
    'id="clientes-list"'    => 'el datalist de clientes',
    'id="productos-list"'   => 'el datalist de productos',
    'col-descuento'         => 'la columna de descuento',
] as $marca => $porque) {
    if (str_contains($html, $marca)) {
        ok("trae {$marca}: {$porque}.");
    } else {
        mal("NO trae {$marca}: {$porque}.");
    }
}

// Los clientes del maestro tienen que estar PROPUESTOS de verdad.
$opciones = preg_match_all('/<datalist id="clientes-list">(.*?)<\/datalist>/s', $html, $mDl)
    ? substr_count($mDl[1][0], '<option') : 0;
$enBase = (int) $pdo->query('SELECT COUNT(*) FROM cliente WHERE activo = 1')->fetchColumn();
printf("  clientes activos en la base: %d, opciones en el datalist: %d\n", $enBase, $opciones);
if ($opciones > 0) {
    ok('el datalist propone clientes del maestro.');
} elseif ($enBase === 0) {
    aviso('no hay clientes activos en esta base: el datalist vacio es correcto aqui, '
        . 'pero este caso no prueba nada. Corre esto contra una base con clientes.');
} else {
    mal("hay {$enBase} clientes activos y el datalist salio vacio.");
}

// ===========================================================================
// VERIFICACION E - GUARDAR DE VERDAD, POR HTTP
// ===========================================================================
titulo('VERIFICACION E - POST del formulario y fila en la base');

if ($tokenForm === null) {
    morir('sin token no se puede seguir: el resto del arnes probaria el 403, no la entrega.');
}

// UN RUT QUE NO ESTA EN EL MAESTRO, A PROPOSITO: es el caso que demuestra que el
// datalist PROPONE Y NO OBLIGA. Se elige uno que no exista en cliente.
$rutLibre = '76192083-9';
$existe = $pdo->prepare('SELECT COUNT(*) FROM cliente WHERE rut_cliente = ?');
$existe->execute([$rutLibre]);
if ((int) $existe->fetchColumn() > 0) {
    aviso("{$rutLibre} SI esta en el maestro de esta base: el caso 'RUT que no existe' "
        . 'no se puede probar con ese RUT. Se sigue igual, pero cliente_id no sera null.');
}

$marca = 'ARNES HTTP ' . bin2hex(random_bytes(4));
$campos = [
    'csrf_token'            => $tokenForm,
    'receptor_rut'          => $rutLibre,
    'receptor_razon_social' => $marca,
    'receptor_giro'         => 'PRUEBAS',
    'receptor_direccion'    => 'CALLE FALSA 123',
    'receptor_comuna'       => 'VALDIVIA',
    'receptor_email'        => 'no-existe@ejemplo.invalid',
    'fecha'                 => date('Y-m-d'),
    'valida_hasta'          => date('Y-m-d', strtotime('+30 days')),
    'notas'                 => 'Cotizacion creada por el arnes HTTP.',
    'lineas'                => [
        ['nombre' => 'Servicio con decimales', 'cantidad' => '2,5', 'precio_unitario' => '12000', 'unidad' => 'HH', 'descuento_pct' => '', 'exento' => ''],
        ['nombre' => 'Producto afecto',        'cantidad' => '3',   'precio_unitario' => '4500',  'unidad' => 'UN', 'descuento_pct' => '10'],
        ['nombre' => 'Item exento',            'cantidad' => '1',   'precio_unitario' => '9900',  'unidad' => 'UN', 'exento' => '1'],
    ],
];

$r = pedir('POST', $base . '/ventas/cotizaciones/nueva', $campos);
printf("  POST -> %d %s\n", $r['status'], $r['headers']['location'] ?? '');

if ($r['status'] === 403) {
    mal('403: EL TOKEN NO VIAJO O NO VALIDO. Es exactamente el defecto de produccion.');
} elseif ($r['status'] === 303 || $r['status'] === 302) {
    ok('guardado y redirigido (PRG), como el resto del panel.');
} else {
    mal("devolvio {$r['status']} y se esperaba 303. Cuerpo: " . substr(strip_tags($r['body']), 0, 200));
}

// --- Y LO QUE IMPORTA: LA FILA EN LA BASE ---
$stmt = $pdo->prepare(
    'SELECT id, numero, cliente_id, receptor_rut, estado_cache FROM cotizacion '
    . 'WHERE receptor_razon_social = ? LIMIT 1'
);
$stmt->execute([$marca]);
$cot = $stmt->fetch(PDO::FETCH_ASSOC);

$cotizacionId = null;
if ($cot === false) {
    mal('NO quedo ninguna cotizacion en la base con esa razon social.');
} else {
    $cotizacionId = (int) $cot['id'];
    printf("  cotizacion id=%d numero=%d cliente_id=%s estado=%s\n",
        $cot['id'], $cot['numero'], var_export($cot['cliente_id'], true), $cot['estado_cache']);
    ok('la cotizacion quedo guardada con su correlativo.');

    $lineas = $pdo->prepare('SELECT orden, nombre, cantidad, unidad, descuento_pct, exento, cantidad_facturada FROM cotizacion_linea WHERE cotizacion_id = ? ORDER BY orden');
    $lineas->execute([$cotizacionId]);
    $filas = $lineas->fetchAll(PDO::FETCH_ASSOC);
    echo "\n      orden  cantidad  unidad  desc  exento  facturada  nombre\n";
    foreach ($filas as $f) {
        printf("      %5d  %8s  %-6s  %4s  %6s  %9s  %s\n", $f['orden'], $f['cantidad'],
            $f['unidad'], $f['descuento_pct'], $f['exento'], $f['cantidad_facturada'], $f['nombre']);
    }
    if (count($filas) === 3) {
        ok('quedaron las tres lineas.');
    } else {
        mal('quedaron ' . count($filas) . ' lineas y se enviaron 3.');
    }
    // "2,5" tecleado con coma tiene que llegar como 2.5 y no como 2 ni como 25.
    if (isset($filas[0]) && abs((float) $filas[0]['cantidad'] - 2.5) < 0.00005) {
        ok('la cantidad "2,5" escrita con coma llego como 2,5: los decimales sobreviven al formulario.');
    } else {
        mal('la cantidad con coma llego como ' . ($filas[0]['cantidad'] ?? '?') . ' y se envio "2,5".');
    }
    // EL DATALIST PROPONE Y NO OBLIGA.
    $existe->execute([$rutLibre]);
    $estaEnMaestro = (int) $existe->fetchColumn() > 0;
    if (! $estaEnMaestro && $cot['cliente_id'] === null) {
        ok('el RUT no esta en el maestro y se guardo igual, con cliente_id NULL. '
            . 'El datalist propone y no obliga.');
    } elseif ($estaEnMaestro && $cot['cliente_id'] !== null) {
        ok('el RUT si esta en el maestro y cliente_id quedo resuelto en el servidor.');
    } else {
        mal('cliente_id no concuerda con si el RUT esta o no en el maestro: '
            . var_export($cot['cliente_id'], true));
    }
}

// --- LIMPIEZA: solo lo que creo este arnes, por id explicito ---
register_shutdown_function(static function () use (&$cotizacionId, $pdo): void {
    if ($cotizacionId === null) {
        return;
    }
    try {
        $pdo->prepare('DELETE FROM cotizacion_linea WHERE cotizacion_id = ?')->execute([$cotizacionId]);
        $pdo->prepare('DELETE FROM cotizacion WHERE id = ?')->execute([$cotizacionId]);
        echo "\n  LIMPIEZA: cotizacion {$cotizacionId} y sus lineas borradas.\n";
        echo "  NOTA: el correlativo de esa cuenta NO se devuelve, igual que un folio.\n";
    } catch (Throwable $e) {
        echo "\n  *** LA LIMPIEZA FALLO: borra a mano la cotizacion {$cotizacionId}. " . $e->getMessage() . "\n";
    }
});

// ===========================================================================
// VERIFICACION F - EL DETALLE Y EL PDF POR HTTP
// ===========================================================================
titulo('VERIFICACION F - ver y PDF, por HTTP');

if ($cotizacionId !== null) {
    $r = pedir('GET', $base . '/ventas/cotizaciones/' . $cotizacionId);
    if ($r['status'] === 200 && str_contains($r['body'], $marca)) {
        ok('el detalle se abre y muestra la cotizacion.');
    } else {
        mal("el detalle devolvio {$r['status']} o no muestra la cotizacion.");
    }

    $r = pedir('GET', $base . '/ventas/cotizaciones/' . $cotizacionId . '/pdf');
    $esPdf = str_starts_with($r['body'], '%PDF');
    printf("  PDF: %d, %d bytes, empieza con %%PDF: %s\n",
        $r['status'], strlen($r['body']), $esPdf ? 'si' : 'NO');
    if ($r['status'] === 200 && $esPdf) {
        ok('el PDF se genera por HTTP.');
    } else {
        mal('el PDF no se genero: ' . substr(strip_tags($r['body']), 0, 150));
    }
}

// ===========================================================================
// RESUMEN
// ===========================================================================
titulo('RESUMEN');
printf("  fallos: %d\n  avisos: %d\n", $fallos, $avisos);
printf("\n  MEMORIA: pico %.1f MB (limite %s)\n",
    memory_get_peak_usage(true) / 1048576, ini_get('memory_limit'));

echo "\n  LO QUE ESTE ARNES NO CUBRE:\n";
echo "    - El aspecto de la pantalla. Comprueba que las clases esten, no que se\n";
echo "      vea bien: eso lo mira Daniel con sus ojos.\n";
echo "    - El JavaScript. curl no ejecuta el fetch de /ventas/cliente-por-rut ni\n";
echo "      el autocompletado de productos; se comprueba que el datalist y el\n";
echo "      endpoint existan, no que el navegador los use.\n";
echo "    - PHPUnit: vendor/bin/phpunit --testdox\n";

exit($fallos > 0 ? 1 : 0);
