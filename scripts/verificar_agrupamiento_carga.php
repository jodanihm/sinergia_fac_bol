<?php

declare(strict_types=1);

/**
 * ARNES: agrupar las filas del mismo cliente en UNA factura (carga masiva).
 *
 * QUE CAMBIO Y POR QUE IMPORTA
 * -----------------------------------------------------------------------------
 * Hasta ahora la carga masiva creaba UNA nota de venta por FILA del Excel, sin
 * excepcion. Desde esta entrega, las filas del mismo cliente que comparten las
 * condiciones del documento se juntan en UNA factura con varias lineas.
 *
 * ESTO TOCA FACTURACION REAL. Una fusion de mas emite una factura que no
 * corresponde; una de menos gasta folios de mas. Por eso el arnes prueba las dos
 * direcciones: lo que TIENE que juntarse y lo que NO PUEDE juntarse.
 *
 * COMO SE CARGA index.php: con REQUEST_URI a una ruta inexistente y todo el arnes
 * dentro de una funcion de apagado. El router corta con exit en su catch-all
 * (index.php:8614) y las funciones quedan definidas igual. Ver el bloque
 * BOOTSTRAP de verificar_chat_armado.php, que explica la tecnica entera.
 *
 * COMO PREPARARLO
 *   1. DB_HOST, DB_NAME, DB_USER, DB_PASS (+ DB_PORT).
 *   2. Migracion 041 aplicada.
 *
 * ESTE ARNES ESCRIBE EN LA BASE y borra su cuenta de prueba al terminar.
 */

ini_set('memory_limit', '256M');

$RAIZ = dirname(__DIR__);

$fallos          = 0;
$avisosDelArnes  = 0;
$cuentaSembrada  = null;

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/__arnes_agrupamiento__';
$_SERVER['HTTP_HOST']      = 'arnes.local';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

register_shutdown_function('correrArnes');

ob_start();
require $RAIZ . '/panel/public/index.php';

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
    global $avisosDelArnes;
    $avisosDelArnes++;
    echo "  [AVISO]   {$m}\n";
}
function morir(string $m): never
{
    limpiarSiembra();
    echo "\n*** ABORTADO: {$m}\n";
    exit(2);
}

/** Borra la cuenta de prueba y todo lo que cuelga de ella. Idempotente. */
function limpiarSiembra(): void
{
    static $hecho = false;

    $cuentaId = $GLOBALS['cuentaSembrada'] ?? null;
    if ($hecho || $cuentaId === null) {
        return;
    }
    $hecho = true;

    try {
        $pdo = Db::conexion();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // nota_venta_origen cae sola por el ON DELETE CASCADE de la 041, pero se
        // borra explicito: depender de una cascada para limpiar es depender de que
        // nadie la cambie.
        $pdo->prepare('DELETE FROM nota_venta_origen WHERE cuenta_id = ?')->execute([$cuentaId]);

        // EL ORDEN ES EL DE LAS CLAVES FORANEAS, y ahora incluye usuario:
        // lote_carga apunta a usuario (fk_lote_carga_usuario), asi que el lote se
        // va antes que su autor, y la cuenta al final porque todo apunta a ella.
        foreach (['nota_venta', 'lote_carga', 'usuario', 'cliente'] as $tabla) {
            $pdo->prepare("DELETE FROM {$tabla} WHERE cuenta_id = ?")->execute([$cuentaId]);
        }
        $pdo->prepare('DELETE FROM cuenta WHERE id = ?')->execute([$cuentaId]);
        echo "\n  LIMPIEZA: cuenta {$cuentaId} borrada con su usuario, lotes, notas y origenes.\n";
    } catch (Throwable $e) {
        echo "\n  *** LA LIMPIEZA FALLO para la cuenta {$cuentaId}: " . $e->getMessage() . "\n";
        echo "      Es la unica fila que este arnes deja escrita; borrala a mano.\n";
    }
}

/**
 * Una fila del Excel ya validada, con la forma que devuelve
 * validarFilaCargaMasiva(). Se construye a mano para poder variar UN campo por
 * vez: pasar por el lector de .xlsx metería PhpSpreadsheet en medio de una
 * prueba que no es sobre eso.
 */
function filaValida(string $externo, string $rut, array $cambios = []): array
{
    $datos = array_merge([
        'identificador_externo' => $externo,
        'receptor_razon_social' => 'CLIENTE DEL ARNES SPA',
        'receptor_giro'         => 'SERVICIOS',
        'receptor_direccion'    => 'CALLE 1',
        'receptor_comuna'       => 'VALDIVIA',
        'receptor_email'        => null,
        'fecha_nota'            => '2026-08-15',
        'detalle'               => [[
            'nombre'         => 'Servicio ' . $externo,
            'cantidad'       => 1.0,
            'precioUnitario' => 10000.0,
            'exento'         => false,
        ]],
        'forma_pago'            => 1,
        'forma_pago_raw'        => 'CONTADO',
        'fecha_vencimiento'     => null,
        'fecha_vencimiento_raw' => '',
        'monto_estimado'        => 11900,
        'boleta_ref_tipo'       => null,
        'boleta_ref_folio'      => null,
        'boleta_ref_fecha'      => null,
        'cliente_resolucion'    => ['estado' => 'encontrado', 'rut' => $rut, 'cliente' => null],
    ], $cambios);

    return ['status' => 'ok', 'errores' => [], 'fila_original' => [], 'datos' => $datos];
}

function correrArnes(): void
{
    global $fallos, $avisosDelArnes;

$ruido = trim(ob_get_level() > 0 ? (string) ob_get_clean() : '');

titulo('CARGA: index.php quedo definido pese al exit del router');
if ($ruido === '404 - ruta no encontrada' || $ruido === '') {
    ok('el router corto en su catch-all, sin despachar ningun handler.');
} else {
    aviso('index.php imprimio algo distinto del 404: ' . mb_substr($ruido, 0, 200));
}

foreach (['agruparFilasPorCliente', 'crearNotaVentaValida', 'crearLoteCarga',
          'listarNotasVentaDeLote', 'validarFilaCargaMasiva'] as $f) {
    if (! function_exists($f)) {
        morir("no se cargo {$f}(): index.php cambio de forma. ARNES SIN CORRER.");
    }
}
ok('las funciones de la carga masiva estan definidas.');

$pdo = Db::conexion();

// La 041 tiene que estar: sin ella, todo lo de abajo falla con un error de SQL
// que no dice nada util.
try {
    $pdo->query('SELECT 1 FROM nota_venta_origen LIMIT 1');
    ok('la tabla nota_venta_origen existe (migracion 041 aplicada).');
} catch (Throwable $e) {
    morir('falta nota_venta_origen: aplica la migracion 041 antes de correr esto.');
}

// ===========================================================================
// VERIFICACION 1 - QUE SE JUNTA Y QUE NO (sin tocar la base)
// ===========================================================================
titulo('VERIFICACION 1 - la clave de agrupamiento');

$RUT_A = '76192083-9';
$RUT_B = '77724622-4';

// --- MISMO RUT Y TODO IGUAL: UNA sola factura con DOS lineas ---------------
$grupos = agruparFilasPorCliente([
    filaValida('A-1', $RUT_A),
    filaValida('A-2', $RUT_A),
]);
printf("      2 filas del mismo RUT, todo igual -> %d documento(s)\n", count($grupos));
if (count($grupos) === 1) {
    ok('dos filas del mismo cliente producen UNA factura.');
} else {
    mal('siguen produciendo ' . count($grupos) . ' facturas: el agrupamiento no actua.');
}
if (count($grupos) === 1 && count($grupos[0]['datos']['detalle']) === 2) {
    ok('y esa factura lleva DOS lineas: el detalle se acumulo.');
} else {
    mal('la factura no quedo con dos lineas: ' . json_encode($grupos[0]['datos']['detalle'] ?? null));
}
// EL MONTO SE SUMA. Con el de la primera fila, la cifra del listado seria falsa.
if (count($grupos) === 1 && (int) $grupos[0]['datos']['monto_estimado'] === 23800) {
    ok('y el monto estimado es la SUMA (11900 + 11900 = 23800), no el de la primera fila.');
} else {
    mal('el monto no se sumo: ' . (string) ($grupos[0]['datos']['monto_estimado'] ?? '?'));
}
if (count($grupos) === 1 && $grupos[0]['externos'] === ['A-1', 'A-2']) {
    ok('y el grupo conserva LOS DOS identificadores de origen.');
} else {
    mal('se perdieron identificadores: ' . json_encode($grupos[0]['externos'] ?? null));
}

// --- RUT DISTINTO: no se juntan --------------------------------------------
$grupos = agruparFilasPorCliente([filaValida('B-1', $RUT_A), filaValida('B-2', $RUT_B)]);
if (count($grupos) === 2) {
    ok('dos clientes distintos siguen siendo dos facturas.');
} else {
    mal('se fusionaron clientes distintos. ESTO EMITIRIA UNA FACTURA A QUIEN NO CORRESPONDE.');
}

// --- LAS CUATRO CONDICIONES DEL DOCUMENTO ----------------------------------
//
// Cada una por separado, y cada una con su motivo: un DTE solo puede llevar una
// de cada. Si alguna dejara de estar en la clave, dos filas incompatibles
// terminarian en el mismo documento.
$condiciones = [
    'forma de pago'         => ['forma_pago' => 2, 'fecha_vencimiento' => '2026-09-15'],
    'fecha de vencimiento'  => ['fecha_vencimiento' => '2026-09-30'],
    'fecha de la nota'      => ['fecha_nota' => '2026-08-16'],
    'boleta a anular'       => ['boleta_ref_tipo' => 39, 'boleta_ref_folio' => 501, 'boleta_ref_fecha' => '2026-08-01'],
];
foreach ($condiciones as $queCambia => $cambio) {
    $g = agruparFilasPorCliente([
        filaValida('C-1', $RUT_A),
        filaValida('C-2', $RUT_A, $cambio),
    ]);
    printf("      mismo RUT, distinta %-22s -> %d documento(s)\n", $queCambia, count($g));
    if (count($g) === 2) {
        ok("difieren en {$queCambia}: quedan SEPARADAS, como corresponde.");
    } else {
        mal("se juntaron pese a diferir en {$queCambia}: un DTE no puede llevar dos.");
    }
}

// --- EL CASO FUNDACIONAL DE M4 ---------------------------------------------
//
// El cliente de reservas anula UNA BOLETA POR RESERVA. Sus filas comparten RUT y
// todo lo demas, y aun asi NO pueden juntarse: cada una arrastra su propia nota
// de credito. Es el caso que motivo toda la carga masiva, y el que mas caro
// costaria romper.
$m4 = agruparFilasPorCliente([
    filaValida('RES-1001', $RUT_A, ['boleta_ref_tipo' => 39, 'boleta_ref_folio' => 1001, 'boleta_ref_fecha' => '2026-08-01']),
    filaValida('RES-1002', $RUT_A, ['boleta_ref_tipo' => 39, 'boleta_ref_folio' => 1002, 'boleta_ref_fecha' => '2026-08-02']),
    filaValida('RES-1003', $RUT_A, ['boleta_ref_tipo' => 39, 'boleta_ref_folio' => 1003, 'boleta_ref_fecha' => '2026-08-03']),
]);
printf("      3 reservas del mismo cliente, 3 boletas distintas -> %d documento(s)\n", count($m4));
if (count($m4) === 3) {
    ok('el caso de M4 queda intacto: tres reservas, tres facturas, tres notas de credito.');
} else {
    mal('SE FUSIONARON RESERVAS CON BOLETAS DISTINTAS. Cada una anula su propia boleta: '
        . 'juntarlas emite notas de credito que no corresponden.');
}

// --- LAS FILAS CON ERROR NO ENTRAN -----------------------------------------
$conError = agruparFilasPorCliente([
    filaValida('D-1', $RUT_A),
    ['status' => 'error', 'errores' => ['algo'], 'fila_original' => [], 'datos' => null],
    filaValida('D-2', $RUT_A),
]);
if (count($conError) === 1 && count($conError[0]['externos']) === 2) {
    ok('las filas con error no entran al agrupamiento y no arrastran a las validas.');
} else {
    mal('una fila con error afecto el agrupamiento: ' . json_encode($conError));
}

// ===========================================================================
// SIEMBRA
// ===========================================================================
titulo('SIEMBRA: una cuenta propia de este arnes');

$sufijo = bin2hex(random_bytes(4));
$pdo->prepare('INSERT INTO cuenta (email, nombre) VALUES (?, ?)')
    ->execute(["arnes-agrup-{$sufijo}@ejemplo.cl", "Arnes agrupamiento {$sufijo}"]);
$cuentaId = (int) $pdo->lastInsertId();
$GLOBALS['cuentaSembrada'] = $cuentaId;
register_shutdown_function('limpiarSiembra');
printf("  cuenta de prueba: %d\n", $cuentaId);

// UN USUARIO DE VERDAD, Y NO UN 0.
//
// lote_carga.usuario_id tiene FK a usuario (fk_lote_carga_usuario). La primera
// version de este arnes pasaba 0 -- "no importa quien" -- y la verificacion 2
// moria con un error de clave foranea a mil lineas de distancia del descuido.
// Un id inventado no existe: si la tabla lo exige, hay que sembrarlo, igual que
// se siembra la cuenta.
//
// password_hash NO es un hash: es una cadena cualquiera. Es NOT NULL y hay que
// poner algo, pero un hash real crearia un usuario con el que se puede entrar al
// panel. password_verify() contra esto devuelve false siempre, asi que la cuenta
// de prueba no habilita ningun login.
$pdo->prepare('INSERT INTO usuario (cuenta_id, email, password_hash) VALUES (?, ?, ?)')
    ->execute([$cuentaId, "arnes-agrup-{$sufijo}@ejemplo.cl", 'sin-login-arnes']);
$usuarioId = (int) $pdo->lastInsertId();
printf("  usuario de prueba: %d (sin login posible)\n", $usuarioId);

// ===========================================================================
// VERIFICACION 2 - LOS ORIGENES QUEDAN TODOS EN LA BASE
// ===========================================================================
titulo('VERIFICACION 2 - nota_venta_origen guarda TODAS las filas fusionadas');

$loteId = crearLoteCarga($pdo, $cuentaId, $usuarioId, 'arnes.xlsx', 2, 2, 0, 33, 1);

$grupo = agruparFilasPorCliente([filaValida('X-1', $RUT_A), filaValida('X-2', $RUT_A)])[0];
$d = $grupo['datos'];
crearNotaVentaValida($pdo, $cuentaId, $loteId, [
    'identificador_externo' => $d['identificador_externo'],
    'receptor_rut'          => $RUT_A,
    'receptor_razon_social' => $d['receptor_razon_social'],
    'receptor_giro'         => $d['receptor_giro'],
    'receptor_direccion'    => $d['receptor_direccion'],
    'receptor_comuna'       => $d['receptor_comuna'],
    'receptor_email'        => $d['receptor_email'],
    'fecha_nota'            => $d['fecha_nota'],
    'detalle'               => $d['detalle'],
    'monto_estimado'        => $d['monto_estimado'],
    'tipo_dte'              => 33,
    'forma_pago'            => $d['forma_pago'],
    'fecha_vencimiento'     => $d['fecha_vencimiento'],
    'boleta_ref_tipo'       => $d['boleta_ref_tipo'],
    'boleta_ref_folio'      => $d['boleta_ref_folio'],
    'boleta_ref_fecha'      => $d['boleta_ref_fecha'],
], $grupo['externos']);

$notas = listarNotasVentaDeLote($pdo, $cuentaId, $loteId);
printf("  notas del lote: %d   origenes de la primera: %s\n",
    count($notas), implode(', ', $notas[0]['origenes'] ?? []));

if (count($notas) === 1) {
    ok('las dos filas produjeron UNA nota de venta.');
} else {
    mal('se crearon ' . count($notas) . ' notas.');
}
if (($notas[0]['origenes'] ?? []) === ['X-1', 'X-2']) {
    ok('y sus DOS identificadores quedaron en nota_venta_origen.');
} else {
    mal('faltan origenes en la base: ' . json_encode($notas[0]['origenes'] ?? null));
}
$lineas = json_decode((string) $notas[0]['detalle'], true);
if (is_array($lineas) && count($lineas) === 2) {
    ok('el detalle guardado tiene DOS lineas: el JSON ya era una lista, no hubo que estirarlo.');
} else {
    mal('el detalle guardado no tiene dos lineas: ' . (string) $notas[0]['detalle']);
}

// ===========================================================================
// VERIFICACION 3 - LA IDEMPOTENCIA SIGUE INTACTA
//
// Es la razon por la que existe nota_venta_origen. Recargar el mismo Excel tiene
// que fallar limpio y TEMPRANO -- en la validacion, antes de abrir ninguna
// transaccion --, tambien para las filas cuyo identificador se fusiono y ya no
// esta en nota_venta.
// ===========================================================================
titulo('VERIFICACION 3 - recargar el mismo Excel sigue fallando limpio');

$vistos = [];
foreach (['X-1', 'X-2'] as $externo) {
    $fila = [
        'identificador_externo' => $externo,
        'rut_receptor' => $RUT_A, 'razon_social_receptor' => 'CLIENTE DEL ARNES SPA',
        'giro_receptor' => 'SERVICIOS', 'direccion_receptor' => 'CALLE 1',
        'comuna_receptor' => 'VALDIVIA', 'email_receptor' => '',
        'fecha_nota' => '2026-08-15', 'producto_servicio' => 'Servicio',
        'cantidad' => '1', 'precio_unitario' => '10000', 'exento' => 'NO',
        'forma_pago' => 'CONTADO', 'fecha_vencimiento' => '',
        'folio_boleta_a_anular' => '', 'fecha_boleta_a_anular' => '',
    ];
    $r = validarFilaCargaMasiva($fila, $pdo, $cuentaId, $vistos);
    $yaExiste = false;
    foreach ($r['errores'] as $e) {
        if (str_contains($e, 'ya existe')) {
            $yaExiste = true;
        }
    }
    printf("      %s -> %s\n", $externo, $yaExiste ? 'rechazada (ya existe)' : 'ACEPTADA');

    if ($yaExiste) {
        ok("'{$externo}' se rechaza al recargar: la proteccion contra duplicar facturas sigue en pie.");
    } else {
        mal("'{$externo}' pasaria una segunda carga. " . ($externo === 'X-2'
            ? 'Es el identificador FUSIONADO: no esta en nota_venta y la validacion no miro '
              . 'nota_venta_origen. La idempotencia quedo degradada.'
            : 'Ni siquiera el primero se detecta.'));
    }
}

// ===========================================================================
titulo('RESUMEN');
printf("  fallos: %d\n  avisos: %d\n", $fallos, $avisosDelArnes);
printf("\n  MEMORIA: pico %.1f MB (limite %s)\n",
    memory_get_peak_usage(true) / 1048576, ini_get('memory_limit'));

echo "\n  LO QUE ESTE ARNES NO CUBRE:\n";
echo "    - El .xlsx real: las filas se construyen con la forma que devuelve\n";
echo "      validarFilaCargaMasiva(), no leyendo un archivo.\n";
echo "    - La emision contra el motor. Se prueba que la nota quede con N lineas,\n";
echo "      no que el SII las acepte.\n";
echo "    - La pantalla del lote: se comprueba el dato, no como se pinta.\n";

limpiarSiembra();
exit($fallos > 0 ? 1 : 0);
}
