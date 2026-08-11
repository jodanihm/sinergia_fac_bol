<?php

declare(strict_types=1);

/**
 * ARNES DE VERIFICACION: el camino HTTP del modulo de compras.
 *
 * POR QUE EXISTE, APARTE DE verificar_orden_compra.php: aquel prueba las
 * migraciones, los repositorios y el PDF llamando a las clases directamente.
 * NADA DE ESO PASA POR UN FORMULARIO. Con cotizacion se desplego una pantalla
 * que no se podia usar -- le faltaba csrfInput() y todo POST moria en 403 --
 * mientras el backend daba verde. Esto cubre lo otro: sesion real, token real,
 * POST del formulario, y la fila en la base al final.
 *
 * NO MANDA NINGUN CORREO. El boton de enviar ENCOLA; que la fila salga de la
 * cola es del runner, y ese se prueba aparte con --seco.
 *
 * -----------------------------------------------------------------------------
 * COMO PREPARARLO
 *
 *   1. El panel sirviendo, y PANEL_URL / PANEL_USER / PANEL_PASS en el entorno.
 *   2. Las del panel: DB_HOST, DB_NAME, DB_USER, DB_PASS (+ DB_PORT).
 *   3. Las migraciones 036/037/038 aplicadas.
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
// VERIFICACION 5 - csrfInput() EN TODAS LAS VISTAS CON POST
//
// VA PRIMERA Y NO NECESITA NI SERVIDOR NI BASE: es la comprobacion mas barata y
// la que habria atajado el defecto de cotizacion. Recorre el directorio entero,
// asi que las cinco vistas nuevas entran solas.
// ===========================================================================
titulo('VERIFICACION 5 - toda vista con <form method="post"> emite csrfInput()');

$vistas = array_merge(
    glob($RAIZ . '/panel/views/*.php') ?: [],
    glob($RAIZ . '/panel/views/partials/*.php') ?: []
);
if ($vistas === []) {
    morir('no se encontro ninguna vista en panel/views/. ARNES SIN CORRER.');
}

$conForm  = 0;
$sinToken = [];
$nuevasVistas = ['proveedores-listado.php', 'proveedor-form.php', 'ordenes-compra-listado.php',
                 'orden-compra-form.php', 'orden-compra-detalle.php'];
$nuevasConForm = [];

foreach ($vistas as $ruta) {
    $txt = (string) file_get_contents($ruta);
    // method="post" en cualquier orden de atributos y con comillas simples o
    // dobles. Un <form> sin method es GET y no necesita token.
    if (preg_match('/<form\b[^>]*\bmethod\s*=\s*["\']?post["\']?/i', $txt) !== 1) {
        continue;
    }
    $conForm++;
    if (in_array(basename($ruta), $nuevasVistas, true)) {
        $nuevasConForm[] = basename($ruta);
    }
    // El token puede venir por csrfInput() o por un input escrito a mano; lo que
    // no vale es que no este ninguno.
    if (! str_contains($txt, 'csrfInput(')
        && preg_match('/name\s*=\s*["\']csrf_token["\']/', $txt) !== 1) {
        $sinToken[] = basename($ruta);
    }
}

printf("  vistas revisadas: %d, con <form method=post>: %d\n", count($vistas), $conForm);
if ($conForm === 0) {
    morir('ninguna vista tiene <form method="post"> -- la expresion regular no esta casando. '
        . 'Fallo del arnes, no de las vistas.');
}
if ($sinToken === []) {
    ok("las {$conForm} vistas con POST emiten el token.");
} else {
    mal('vistas con POST y SIN token CSRF (no se pueden usar): ' . implode(', ', $sinToken));
}

// Y que las nuevas hayan entrado de verdad en la revision: si una no casara con
// el regex, pasaria por cubierta sin estarlo.
sort($nuevasConForm);
printf("  vistas nuevas con formulario POST: %s\n", implode(', ', $nuevasConForm));
foreach (['proveedor-form.php', 'orden-compra-form.php', 'orden-compra-detalle.php',
          'proveedores-listado.php'] as $esperada) {
    if (in_array($esperada, $nuevasConForm, true)) {
        ok("{$esperada} entro en la revision.");
    } else {
        mal("{$esperada} NO tiene <form method=\"post\">, o no se encontro el archivo.");
    }
}

// ===========================================================================
// PANTALLA 0 - REQUISITOS
// ===========================================================================
titulo('PANTALLA 0 - REQUISITOS');

foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $v) {
    if (getenv($v) === false || getenv($v) === '') {
        morir("falta {$v}. Es de las que exige Db::conexion(). ARNES SIN CORRER en el resto.");
    }
}
$pdo = Db::conexion();
foreach (['proveedor', 'orden_compra', 'orden_compra_linea', 'orden_compra_envio'] as $t) {
    if ($pdo->query('SHOW TABLES LIKE ' . $pdo->quote($t))->fetchColumn() === false) {
        morir("falta la tabla {$t}: aplica las migraciones 036/037/038. ARNES SIN CORRER.");
    }
}
ok('conectado y las tablas de las tres migraciones existen.');

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
    echo "\n  La verificacion 5 ya corrio y es la que no necesita servidor.\n";
    morir('faltan variables para la parte HTTP: ' . implode(', ', $faltan)
        . '. ARNES SIN CORRER en la parte HTTP -- no es un fallo de la entrega.');
}
printf("  PANEL_URL=%s  PANEL_USER=%s  PANEL_PASS=(%d caracteres)\n", $base, $user, strlen($pass));

// --- Cliente HTTP con cookies, sin dependencias ---
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

    $cabeceras = substr((string) $bruto, 0, $corte);
    $h = [];
    foreach (preg_split('/\r?\n/', $cabeceras) ?: [] as $l) {
        if (preg_match('/^([^:]+):\s*(.*)$/', $l, $m)) {
            $h[strtolower($m[1])] = $m[2];
            if (strtolower($m[1]) === 'set-cookie' && preg_match('/^([^=]+)=([^;]*)/', $m[2], $mc)) {
                $cookies[$mc[1]] = $mc[2];
            }
        }
    }

    return ['status' => $status, 'headers' => $h, 'body' => substr((string) $bruto, $corte)];
}
function tokenDe(string $html): ?string
{
    return preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) === 1 ? $m[1] : null;
}

// --- Limpieza: solo lo que crea este arnes, por id explicito ---
$proveedorId = null;
$ordenId     = null;
register_shutdown_function(static function () use (&$proveedorId, &$ordenId, $pdo): void {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    try {
        if ($ordenId !== null) {
            $pdo->prepare('DELETE FROM orden_compra_envio WHERE orden_compra_id = ?')->execute([$ordenId]);
            $pdo->prepare('DELETE FROM orden_compra_linea WHERE orden_compra_id = ?')->execute([$ordenId]);
            $pdo->prepare('DELETE FROM orden_compra WHERE id = ?')->execute([$ordenId]);
            echo "\n  LIMPIEZA: orden {$ordenId} y sus lineas/envios borrados.\n";
            echo "  NOTA: el correlativo NO se devuelve, igual que un folio.\n";
        }
        if ($proveedorId !== null) {
            $pdo->prepare('DELETE FROM proveedor WHERE id = ?')->execute([$proveedorId]);
            echo "  LIMPIEZA: proveedor {$proveedorId} borrado.\n";
        }
    } catch (Throwable $e) {
        echo "\n  *** LA LIMPIEZA FALLO: " . $e->getMessage() . "\n";
        echo "      A mano: DELETE FROM orden_compra WHERE id = " . var_export($ordenId, true) . ";\n";
    }
});

// ===========================================================================
// VERIFICACION 1 - LOGIN Y LOS DOS FORMULARIOS
// ===========================================================================
titulo('VERIFICACION 1 - sesion real y token en los dos formularios');

$r = pedir('GET', $base . '/login');
if ($r['status'] !== 200) {
    morir("GET /login devolvio {$r['status']}: el panel no esta sirviendo en {$base}. ARNES SIN CORRER.");
}
$tok = tokenDe($r['body']);
if ($tok === null) {
    morir('la pagina de login no trae csrf_token.');
}
$r = pedir('POST', $base . '/login', ['csrf_token' => $tok, 'email' => $user, 'password' => $pass]);
if (! in_array($r['status'], [302, 303], true)) {
    morir("el login devolvio {$r['status']}. Revisa PANEL_USER/PANEL_PASS. ARNES SIN CORRER.");
}
ok('sesion iniciada.');

$formularios = [
    '/compras/proveedores/nuevo' => 'alta de proveedor',
    '/compras/ordenes/nueva'     => 'emision de orden de compra',
];
$tokens = [];
foreach ($formularios as $ruta => $que) {
    $r = pedir('GET', $base . $ruta);
    printf("  GET %-30s -> %d\n", $ruta, $r['status']);
    if (in_array($r['status'], [302, 303], true)) {
        morir("{$ruta} redirige. Las rutas de compras NO deben exigir produccion completa. "
            . 'ARNES SIN CORRER en el resto.');
    }
    if ($r['status'] !== 200) {
        mal("el formulario de {$que} devolvio {$r['status']}.");
        continue;
    }
    $t = tokenDe($r['body']);
    if ($t !== null) {
        $tokens[$ruta] = $t;
        ok("el formulario de {$que} EMITE EL TOKEN.");
    } else {
        mal("el formulario de {$que} NO emite csrf_token: todo POST moriria en 403.");
    }
    // Y lo que hace usable la pantalla de la orden.
    if ($ruta === '/compras/ordenes/nueva') {
        foreach (['id="proveedores-list"' => 'el datalist de proveedores',
                  'id="productos-list"'   => 'el datalist de productos',
                  'name="fecha_entrega"'  => 'la fecha de entrega',
                  'name="lugar_entrega"'  => 'el lugar de entrega'] as $marca => $q) {
            if (str_contains($r['body'], $marca)) {
                ok("trae {$q}.");
            } else {
                mal("NO trae {$q} ({$marca}).");
            }
        }
    }
}

// ===========================================================================
// VERIFICACION 2 - ALTA DE PROVEEDOR POR POST
// ===========================================================================
titulo('VERIFICACION 2 - alta de proveedor por HTTP');

if (! isset($tokens['/compras/proveedores/nuevo'])) {
    morir('sin token no se puede seguir: se probaria el 403, no la entrega.');
}

$rutProv = '76192083-9';
$marca   = 'PROVEEDOR ARNES ' . bin2hex(random_bytes(4));
$r = pedir('POST', $base . '/compras/proveedores/nuevo', [
    'csrf_token'       => $tokens['/compras/proveedores/nuevo'],
    'rut_proveedor'    => $rutProv,
    'razon_social'     => $marca,
    'giro'             => 'INSUMOS',
    'direccion'        => 'CALLE FALSA 123',
    'comuna'           => 'VALDIVIA',
    'email'            => 'no-existe@ejemplo.invalid',
    'telefono'         => '+56 63 2 123456',
    'contacto'         => 'Juan Perez',
    'condiciones_pago' => '30 dias',
]);
printf("  POST -> %d %s\n", $r['status'], $r['headers']['location'] ?? '');

if ($r['status'] === 403) {
    mal('403: EL TOKEN NO VIAJO O NO VALIDO. Es exactamente el defecto de cotizacion.');
} elseif (in_array($r['status'], [302, 303], true)) {
    ok('guardado y redirigido (PRG), como el resto del panel.');
} else {
    mal("devolvio {$r['status']} y se esperaba 303. Cuerpo: " . substr(strip_tags($r['body']), 0, 200));
}

$stmt = $pdo->prepare('SELECT id, rut_proveedor, contacto, condiciones_pago FROM proveedor WHERE razon_social = ?');
$stmt->execute([$marca]);
$prov = $stmt->fetch(PDO::FETCH_ASSOC);
if ($prov === false) {
    mal('NO quedo ningun proveedor con esa razon social.');
} else {
    $proveedorId = (int) $prov['id'];
    printf("  proveedor id=%d rut=%s contacto=%s condiciones=%s\n",
        $proveedorId, $prov['rut_proveedor'], $prov['contacto'], $prov['condiciones_pago']);
    ok('el proveedor quedo guardado con contacto y condiciones de pago.');
}

// El endpoint del fetch tiene que encontrarlo.
$r = pedir('GET', $base . '/compras/proveedor-por-rut?rut=' . rawurlencode($rutProv));
$json = json_decode($r['body'], true);
if (is_array($json) && ($json['estado'] ?? '') === 'encontrado') {
    ok('/compras/proveedor-por-rut lo encuentra: el autocompletado del formulario funciona.');
} else {
    mal('el endpoint del fetch no encontro el proveedor: ' . substr($r['body'], 0, 150));
}

// ===========================================================================
// VERIFICACION 3 - EMISION DE LA ORDEN POR POST
// ===========================================================================
titulo('VERIFICACION 3 - orden de compra por HTTP, con lineas');

if (! isset($tokens['/compras/ordenes/nueva'])) {
    morir('sin token del formulario de orden no se puede seguir.');
}

// LAS LINEAS DE ESTA PRUEBA: tres afectas de 333, una exenta de 10.000 y una
// afecta con decimales de 2.500. Afecto = 3.499, exento = 10.000.
//
// EL ESPERADO SE DERIVA DE ESTA SIEMBRA MAS ABAJO, NO SE ESCRIBE A MANO. La
// version anterior tenia el IVA esperado en 190, que era el del OTRO arnes
// (donde el afecto son 999). Al agregar aqui la quinta linea -- la de decimales,
// que es AFECTA -- el afecto paso a 3.499 y el numero literal quedo viejo: el
// arnes acuso al codigo de un defecto que no tenia. Un valor esperado que no
// sale de la siembra se desincroniza en cuanto alguien toca la siembra.
$campos = [
    'csrf_token'             => $tokens['/compras/ordenes/nueva'],
    'proveedor_rut'          => $rutProv,
    'proveedor_razon_social' => $marca,
    'proveedor_giro'         => 'INSUMOS',
    'proveedor_direccion'    => 'CALLE FALSA 123',
    'proveedor_comuna'       => 'VALDIVIA',
    'proveedor_email'        => 'no-existe@ejemplo.invalid',
    'proveedor_contacto'     => 'Juan Perez',
    'condiciones_pago'       => '30 dias',
    'fecha'                  => date('Y-m-d'),
    'fecha_entrega'          => date('Y-m-d', strtotime('+15 days')),
    'lugar_entrega'          => 'Bodega central',
    'notas'                  => 'Orden creada por el arnes HTTP.',
    'lineas'                 => [
        ['nombre' => 'Afecto A', 'cantidad' => '1',   'precio_unitario' => '333',  'unidad' => 'UN'],
        ['nombre' => 'Afecto B', 'cantidad' => '1',   'precio_unitario' => '333',  'unidad' => 'UN'],
        ['nombre' => 'Afecto C', 'cantidad' => '1',   'precio_unitario' => '333',  'unidad' => 'UN'],
        ['nombre' => 'Exento',   'cantidad' => '2',   'precio_unitario' => '5000', 'unidad' => 'UN', 'exento' => '1'],
        // Con coma, como la teclea un usuario chileno.
        ['nombre' => 'Con decimales', 'cantidad' => '2,5', 'precio_unitario' => '1000', 'unidad' => 'KG'],
    ],
];
$r = pedir('POST', $base . '/compras/ordenes/nueva', $campos);
printf("  POST -> %d %s\n", $r['status'], $r['headers']['location'] ?? '');
if ($r['status'] === 403) {
    mal('403 en la orden: el token no viajo.');
} elseif (! in_array($r['status'], [302, 303], true)) {
    mal("devolvio {$r['status']}: " . substr(strip_tags($r['body']), 0, 200));
} else {
    ok('orden guardada y redirigida (PRG).');
}

$stmt = $pdo->prepare('SELECT id, numero, proveedor_id, neto, exento, iva, total FROM orden_compra WHERE proveedor_razon_social = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$marca]);
$oc = $stmt->fetch(PDO::FETCH_ASSOC);
if ($oc === false) {
    mal('NO quedo ninguna orden de compra en la base.');
} else {
    $ordenId = (int) $oc['id'];
    printf("  orden id=%d numero=%d proveedor_id=%s neto=%s exento=%s iva=%s total=%s\n",
        $ordenId, $oc['numero'], var_export($oc['proveedor_id'], true),
        $oc['neto'], $oc['exento'], $oc['iva'], $oc['total']);

    $lineas = $pdo->prepare('SELECT orden, nombre, cantidad, unidad, exento FROM orden_compra_linea WHERE orden_compra_id = ? ORDER BY orden');
    $lineas->execute([$ordenId]);
    $filas = $lineas->fetchAll(PDO::FETCH_ASSOC);
    echo "\n      orden  cantidad  unidad  exento  nombre\n";
    foreach ($filas as $f) {
        printf("      %5d  %8s  %-6s  %6s  %s\n", $f['orden'], $f['cantidad'], $f['unidad'], $f['exento'], $f['nombre']);
    }
    if (count($filas) === 5) {
        ok('quedaron las cinco lineas.');
    } else {
        mal('quedaron ' . count($filas) . ' lineas y se enviaron 5.');
    }
    if (isset($filas[4]) && abs((float) $filas[4]['cantidad'] - 2.5) < 0.00005) {
        ok('la cantidad "2,5" con coma llego como 2,5: los decimales sobreviven al formulario.');
    } else {
        mal('la cantidad con coma llego como ' . ($filas[4]['cantidad'] ?? '?') . '.');
    }

    // --- EL IVA, POR EL CAMINO COMPLETO ---
    //
    // El esperado SE CALCULA DESDE LAS LINEAS QUE SE ENVIARON, recorriendo
    // $campos['lineas']. Asi no puede volver a quedarse viejo si alguien agrega
    // o cambia una linea de la siembra.
    //
    // NO ES TAUTOLOGICO: la regla se reescribe aqui desde su fuente
    // -- DteXmlBuilder::TASA_IVA, 19, sobre el neto afecto -- en vez de llamar a
    // MySqlOrdenCompraRepository::totales(), que es justamente lo que se esta
    // probando. Si el repositorio cambiara de regla, esto se pondria rojo.
    $afectoEsperado = 0.0;
    $exentoEsperado = 0.0;
    $ivaPorLinea    = 0;
    foreach ($campos['lineas'] as $l) {
        $cant  = (float) str_replace(',', '.', (string) $l['cantidad']);
        $monto = $cant * (float) $l['precio_unitario'];
        if (! empty($l['exento'])) {
            $exentoEsperado += $monto;
        } else {
            $afectoEsperado += $monto;
            $ivaPorLinea    += (int) round($monto * 19 / 100);
        }
    }
    $netoEsperado = (int) round($afectoEsperado);
    $ivaEsperado  = (int) round($netoEsperado * 19 / 100);

    printf("      afecto sembrado %s -> IVA una vez %d | por linea %d\n",
        number_format($netoEsperado, 0, ',', '.'), $ivaEsperado, $ivaPorLinea);

    // Si las dos formas coincidieran, este caso no distinguiria nada y el verde
    // seria falso. Mismo criterio que el arnes de la base.
    if ($ivaEsperado === $ivaPorLinea) {
        morir('con estos importes las dos formas de redondear dan lo mismo: la prueba no '
            . 'distingue nada. Cambia la siembra.');
    }

    if ((int) $oc['neto'] === $netoEsperado && (int) $oc['iva'] === $ivaEsperado) {
        ok("neto {$netoEsperado} e IVA {$ivaEsperado}: redondeado UNA vez sobre el afecto, "
            . "no por linea (que habria dado {$ivaPorLinea}).");
    } elseif ((int) $oc['iva'] === $ivaPorLinea) {
        mal("el IVA salio {$oc['iva']}, que es la suma de los redondeos POR LINEA. "
            . "Deberia ser {$ivaEsperado}, redondeado una vez sobre el neto afecto.");
    } else {
        mal("neto={$oc['neto']} iva={$oc['iva']}: se esperaba neto {$netoEsperado} e IVA {$ivaEsperado}.");
    }
    if ((int) $oc['exento'] === (int) round($exentoEsperado)) {
        ok('el exento se sumo aparte y no entro en la base del IVA.');
    } else {
        mal("el exento salio {$oc['exento']} y se esperaba " . (int) round($exentoEsperado) . '.');
    }
    if ((int) $oc['proveedor_id'] === $proveedorId) {
        ok('proveedor_id se resolvio EN EL SERVIDOR desde el RUT, no vino del formulario.');
    } else {
        mal('proveedor_id no quedo enlazado al proveedor del maestro.');
    }

    // El PDF, por HTTP.
    $r = pedir('GET', $base . '/compras/ordenes/' . $ordenId . '/pdf');
    $esPdf = str_starts_with($r['body'], '%PDF');
    printf("  PDF: %d, %d bytes, empieza con %%PDF: %s\n", $r['status'], strlen($r['body']), $esPdf ? 'si' : 'NO');
    if ($r['status'] === 200 && $esPdf) {
        ok('el PDF se genera por HTTP.');
    } else {
        mal('el PDF no se genero: ' . substr(strip_tags($r['body']), 0, 150));
    }
}

// ===========================================================================
// VERIFICACION 4 - EL BOTON DE ENVIAR ENCOLA
// ===========================================================================
titulo('VERIFICACION 4 - enviar por correo ENCOLA (no manda)');

if ($ordenId === null) {
    morir('sin orden creada no se puede probar el envio.');
}

$r = pedir('GET', $base . '/compras/ordenes/' . $ordenId);
if ($r['status'] !== 200) {
    mal("el detalle devolvio {$r['status']}.");
} else {
    ok('el detalle se abre.');
    $tokDet = tokenDe($r['body']);
    if ($tokDet === null) {
        mal('el detalle NO emite csrf_token: el boton de enviar moriria en 403.');
        $tokDet = '';
    } else {
        ok('el formulario de envio emite el token.');
    }
    // La advertencia tiene que estar donde el usuario lee el estado.
    if (str_contains($r['body'], 'no es lo mismo que recibido')) {
        ok('la pantalla advierte que "aceptado" no es "recibido".');
    } else {
        aviso('la advertencia sobre aceptado/recibido no aparece todavia (solo sale con envios).');
    }

    if ($tokDet !== '') {
        $r = pedir('POST', $base . '/compras/ordenes/' . $ordenId . '/enviar', [
            'csrf_token'   => $tokDet,
            'destinatario' => 'no-existe@ejemplo.invalid',
        ]);
        printf("  POST enviar -> %d\n", $r['status']);
        if ($r['status'] === 403) {
            mal('403 al enviar: el token no viajo.');
        } elseif (! in_array($r['status'], [302, 303], true)) {
            mal("devolvio {$r['status']}.");
        } else {
            ok('el boton responde con PRG al instante.');
        }
    }
}

$stmt = $pdo->prepare('SELECT intento_de, destinatario, estado, intentos, message_id FROM orden_compra_envio WHERE orden_compra_id = ?');
$stmt->execute([$ordenId]);
$envios = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "\n      intento  estado      intentos  destinatario\n";
foreach ($envios as $e) {
    printf("      %7d  %-10s  %8d  %s\n", $e['intento_de'], $e['estado'], $e['intentos'], $e['destinatario']);
}

if (count($envios) === 1 && (string) $envios[0]['estado'] === 'pendiente') {
    ok("la fila quedo en orden_compra_envio con estado 'pendiente'.");
} else {
    mal('no quedo exactamente una fila pendiente: ' . count($envios) . ' fila(s).');
}
// NO SE ENVIO NADA: el runner es quien manda, y no ha corrido.
if ($envios !== [] && (int) $envios[0]['intentos'] === 0 && $envios[0]['message_id'] === null) {
    ok('intentos=0 y sin message_id: el boton ENCOLO y no mando. El runner no ha corrido.');
} else {
    mal('la fila trae intentos o message_id: alguien envio de forma sincronica.');
}

// Y un segundo envio es un intento NUEVO, no un choque contra el UNIQUE.
$stmt = $pdo->prepare('SELECT COUNT(*) FROM orden_compra_envio WHERE orden_compra_id = ?');
$stmt->execute([$ordenId]);
echo "\n  REENVIO: la cola admite intentos sucesivos por diseño (UNIQUE sobre\n";
echo "  (orden_compra_id, intento_de), no sobre la orden). Un proveedor que\n";
echo "  perdio el correo se resuelve reenviando, cosa que un DTE no necesita.\n";

// ===========================================================================
// RESUMEN
// ===========================================================================
titulo('RESUMEN');
printf("  fallos: %d\n  avisos: %d\n", $fallos, $avisos);
printf("\n  MEMORIA: pico %.1f MB (limite %s)\n",
    memory_get_peak_usage(true) / 1048576, ini_get('memory_limit'));

echo "\n  LO QUE ESTE ARNES NO CUBRE:\n";
echo "    - EL ENVIO REAL. El boton encola; que la fila salga de la cola es del\n";
echo "      runner. Pruebalo sin gastar un correo:\n";
echo "        php scripts/enviar_ordenes_compra_pendientes.php --seco\n";
echo "    - El aspecto de las pantallas y del PDF: eso lo mira Daniel.\n";
echo "    - El JavaScript. curl no ejecuta el fetch de /compras/proveedor-por-rut\n";
echo "      ni el autocompletado de productos; se comprueba que el datalist y el\n";
echo "      endpoint existan, no que el navegador los use.\n";
echo "    - PHPUnit: vendor/bin/phpunit --testdox\n";

exit($fallos > 0 ? 1 : 0);
