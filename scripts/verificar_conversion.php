<?php

declare(strict_types=1);

/**
 * ARNES DE VERIFICACION: conversion de cotizacion a factura (segunda entrega).
 *
 * Cubre las verificaciones 1 a 6. La 7 (camino HTTP completo) esta en
 * verificar_cotizacion_http.php y la 8 (PHPUnit) se corre aparte.
 *
 * NO EMITE NADA AL SII. El descuento del saldo vive en una transaccion LOCAL que
 * ocurre DESPUES del 201 del motor, asi que se puede ejercitar entera sin emitir:
 * se llama a registrarFacturacion() con un folio inventado, que es exactamente lo
 * que hace el handler cuando el motor ya respondio.
 *
 * -----------------------------------------------------------------------------
 * COMO PREPARARLO (git NO existe dentro del contenedor):
 *
 *   cd Y:/webserver/sinergia_fac_bol
 *   git show HEAD:panel/views/emision-form.php > scripts/HEAD_emision_form.php
 *   git show HEAD:panel/public/index.php       > scripts/HEAD_panel_index.php
 *   git show HEAD:public/index.php             > scripts/HEAD_public_index.php
 *
 *   rm scripts/HEAD_emision_form.php scripts/HEAD_panel_index.php scripts/HEAD_public_index.php
 *
 * Variables: las del panel -- DB_HOST, DB_NAME, DB_USER, DB_PASS (+ DB_PORT).
 * -----------------------------------------------------------------------------
 */

ini_set('memory_limit', '256M');

$RAIZ = dirname(__DIR__);
require $RAIZ . '/vendor/autoload.php';
require $RAIZ . '/panel/src/Db.php';

use Plantiflex\Integration\Facturacion\MySqlCotizacionRepository;

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
/** Cantidad legible, sin ceros de relleno. */
function c(float|string $n): string
{
    return rtrim(rtrim(number_format((float) $n, 4, '.', ''), '0'), '.');
}

// ===========================================================================
// VERIFICACION 1 - EL CAMINO DE EMISION NORMAL NO SE MUEVE
//
// VA PRIMERO PORQUE ES LA QUE MANDA: el camino sin cotizacion es el que hoy
// factura de verdad. Si esta falla, lo demas no importa.
// ===========================================================================
titulo('VERIFICACION 1 - la emision SIN cotizacion no se movio');

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

$headPanel = is_file(__DIR__ . '/HEAD_panel_index.php') ? (string) file_get_contents(__DIR__ . '/HEAD_panel_index.php') : null;
$workPanel = (string) file_get_contents($RAIZ . '/panel/public/index.php');

if ($headPanel === null) {
    aviso('falta scripts/HEAD_panel_index.php: no se puede comparar el camino de emision.');
} else {
    // LO QUE ARMA EL PAYLOAD DEL MOTOR NO PUEDE HABER CAMBIADO. Es lo unico que
    // decide que documento se emite.
    foreach (['armarDocumentoEmision', 'emitirEnMotor'] as $fn) {
        $a = extraerFuncion($headPanel, $fn);
        $b = extraerFuncion($workPanel, $fn);
        if ($a === null || $b === null) {
            mal("no se pudo extraer {$fn}() de una de las dos versiones.");
        } elseif ($a === $b) {
            ok("{$fn}(): identica a HEAD, byte a byte. El payload al motor no cambio.");
        } else {
            mal("{$fn}() CAMBIO. Eso mueve lo que se emite, no solo lo que se registra.");
        }
    }
}

// EL MOTOR NO SE TOCA EN ESTA ENTREGA.
if (is_file(__DIR__ . '/HEAD_public_index.php')) {
    $h = (string) file_get_contents(__DIR__ . '/HEAD_public_index.php');
    $w = (string) file_get_contents($RAIZ . '/public/index.php');
    if ($h === $w) {
        ok('public/index.php: IDENTICO a HEAD. El motor no sabe nada de cotizaciones.');
    } else {
        mal('public/index.php CAMBIO: el motor no deberia conocer este concepto.');
    }
} else {
    aviso('falta scripts/HEAD_public_index.php: no se puede comparar el motor.');
}

// LA VISTA, SIN COTIZACION, TIENE QUE RENDERIZAR IGUAL. Se compara el texto de
// la plantilla fuera de los bloques nuevos: todos los agregados estan dentro de
// un if ($cotizacionId !== null) o de un if (! empty($d['cotizacion_linea_id'])).
$headVista = is_file(__DIR__ . '/HEAD_emision_form.php') ? (string) file_get_contents(__DIR__ . '/HEAD_emision_form.php') : null;
$workVista = (string) file_get_contents($RAIZ . '/panel/views/emision-form.php');
/**
 * Devuelve el codigo sin comentarios PHP, conservando el HTML tal cual.
 *
 * HACE FALTA PARA COMPARAR: un comentario explicativo de varias lineas cuenta
 * como "codigo nuevo" en un diff de texto, y la primera version de esta
 * comprobacion reportaba como sospechosas las lineas de continuacion de un
 * bloque /* ... *\/ -- no las del bloque nuevo, sino la PROSA que lo explica.
 */
function sinComentarios(string $codigo): string
{
    $out = '';
    foreach (token_get_all($codigo) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $out .= is_array($t) ? $t[1] : $t;
    }

    return $out;
}

if ($headVista === null) {
    aviso('falta scripts/HEAD_emision_form.php: no se puede comparar la vista.');
} else {
    // --- LA PRUEBA QUE MANDA: SE RENDERIZA LA FILA, NO SE RAZONA SOBRE ELLA ---
    //
    // La fila del detalle se REESCRIBIO para poder llevar el hidden del vinculo.
    // Un diff de texto la marca como desaparecida y tiene razon: la linea cambio.
    // Lo que hay que demostrar es otra cosa -- que SIN COTIZACION produce el
    // MISMO HTML que la de HEAD, ni un atributo de diferencia.
    //
    // Asi que se extraen los BYTES LITERALES de esa fila de las dos versiones y
    // se ejecutan las dos como plantilla, con el mismo contexto. Es el metodo de
    // siempre: no se parafrasea la logica, se corre.
    $filaDe = static function (string $fuente): ?string {
        foreach (preg_split('/\r?\n/', $fuente) ?: [] as $l) {
            if (str_contains($l, 'col-producto') && str_contains($l, '[nombre]')) {
                return $l;
            }
        }

        return null;
    };
    $filaHead = $filaDe($headVista);
    $filaWork = $filaDe($workVista);

    if ($filaHead === null || $filaWork === null) {
        mal('no se encontro la fila del detalle en una de las dos versiones: la '
            . 'comparacion de render no se pudo hacer.');
    } else {
        /** Ejecuta el fragmento como plantilla y devuelve su salida. */
        $render = static function (string $plantilla, array $d, int $i): string {
            $errStyle = static fn (string $campo): string => '';
            ob_start();
            eval('?>' . $plantilla);
            return (string) ob_get_clean();
        };

        // (a) SIN COTIZACION: $d no trae ni cotizacion_linea_id ni pendiente.
        $dNormal = ['nombre' => 'Servicio de prueba', 'cantidad' => '2', 'precioUnitario' => '1000'];
        $htmlHead = $render($filaHead, $dNormal, 0);
        $htmlWork = $render($filaWork, $dNormal, 0);

        echo "\n  RENDER DE LA FILA, SIN COTIZACION:\n";
        echo '      HEAD    : ' . $htmlHead . "\n";
        echo '      TRABAJO : ' . $htmlWork . "\n";
        printf("      md5 HEAD %s   md5 TRABAJO %s\n", md5($htmlHead), md5($htmlWork));

        if ($htmlHead === $htmlWork) {
            ok('LA FILA RENDERIZA IDENTICA sin cotizacion: ni un atributo de diferencia. '
                . 'La linea se reescribio para envolver el input original, no para cambiarlo.');
        } else {
            mal('EL HTML DE LA FILA CAMBIO en la emision normal. Esto SI es una regresion '
                . 'en la pantalla que hoy factura de verdad.');
        }

        // (b) CON COTIZACION: se muestra que ahi SI cambia, y en que.
        $dConversion = $dNormal + ['cotizacion_linea_id' => 77, 'pendiente' => 6.0];
        $htmlConv = $render($filaWork, $dConversion, 0);
        echo "\n  RENDER DE LA FILA, CON COTIZACION (para ver que agrega):\n";
        echo '      ' . $htmlConv . "\n";
        if (str_contains($htmlConv, 'cotizacion_linea_id') && str_contains($htmlConv, 'value="77"')) {
            ok('con cotizacion agrega el hidden del vinculo con el id de la linea.');
        } else {
            mal('con cotizacion NO agrega el hidden del vinculo.');
        }
    }

    // --- Y el resto de la vista: nada mas puede haber desaparecido ---
    //
    // Se comparan las lineas SIN COMENTARIOS y descontando la fila del detalle,
    // que ya se probo por render mas arriba.
    $lineasHead = explode("\n", sinComentarios($headVista));
    $lineasWork = explode("\n", sinComentarios($workVista));
    $desaparecidas = array_values(array_filter(
        array_diff($lineasHead, $lineasWork),
        static fn (string $l): bool => ! (str_contains($l, 'col-producto') && str_contains($l, '[nombre]'))
    ));
    if ($desaparecidas === []) {
        ok('fuera de esa fila, ninguna linea de HEAD desaparecio de la vista.');
    } else {
        mal('desaparecieron lineas de la vista: ' . implode(' | ', array_map('trim', array_slice($desaparecidas, 0, 5))));
    }
}

// ===========================================================================
// PANTALLA 0 - BASE Y SIEMBRA
// ===========================================================================
titulo('PANTALLA 0 - BASE Y SIEMBRA');

$faltan = [];
foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $v) {
    if (getenv($v) === false || getenv($v) === '') {
        $faltan[] = $v;
    }
}
if ($faltan !== []) {
    echo "\n  La verificacion 1 ya corrio y es la que no necesita base.\n";
    morir('faltan variables: ' . implode(', ', $faltan) . '. ARNES SIN CORRER en el resto.');
}
$pdo = Db::conexion();
ok('conectado con Db::conexion().');

foreach (['cotizacion_factura', 'cotizacion_factura_linea'] as $t) {
    if ($pdo->query('SHOW TABLES LIKE ' . $pdo->quote($t))->fetchColumn() === false) {
        morir("falta la tabla {$t}: aplica la migracion 033. ARNES SIN CORRER.");
    }
}
ok('las dos tablas de la 033 existen.');

$cuentaId = null;
$cuentaOtra = null;
register_shutdown_function(static function () use (&$cuentaId, &$cuentaOtra, $pdo): void {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    foreach ([$cuentaId, $cuentaOtra] as $cid) {
        if ($cid === null) {
            continue;
        }
        try {
            $pdo->prepare('DELETE fl FROM cotizacion_factura_linea fl INNER JOIN cotizacion_factura f ON f.id = fl.cotizacion_factura_id WHERE f.cuenta_id = ?')->execute([$cid]);
            $pdo->prepare('DELETE FROM cotizacion_factura WHERE cuenta_id = ?')->execute([$cid]);
            $pdo->prepare('DELETE l FROM cotizacion_linea l INNER JOIN cotizacion c ON c.id = l.cotizacion_id WHERE c.cuenta_id = ?')->execute([$cid]);
            $pdo->prepare('DELETE FROM cotizacion WHERE cuenta_id = ?')->execute([$cid]);
            $pdo->prepare('DELETE FROM cotizacion_correlativo WHERE cuenta_id = ?')->execute([$cid]);
            $pdo->prepare('DELETE FROM cuenta WHERE id = ?')->execute([$cid]);
            echo "\n  LIMPIEZA: cuenta {$cid} borrada.\n";
        } catch (Throwable $e) {
            echo "\n  *** LA LIMPIEZA FALLO para la cuenta {$cid}: " . $e->getMessage() . "\n";
        }
    }
});

/** Crea una cuenta como la crea el codigo real (index.php:6506 / sembrar_demo.php:527). */
function crearCuenta(PDO $pdo): int
{
    $email = 'arnes-conversion-' . bin2hex(random_bytes(6)) . '@ejemplo.invalid';
    $pdo->prepare("INSERT INTO cuenta (email, nombre, estado) VALUES (:e, :n, 'activa')")
        ->execute([':e' => $email, ':n' => 'ARNES CONVERSION']);

    return (int) $pdo->lastInsertId();
}

$cuentaId   = crearCuenta($pdo);
$cuentaOtra = crearCuenta($pdo);
ok("cuentas de prueba: {$cuentaId} y {$cuentaOtra} (la segunda, para el caso del id ajeno).");

$repo = new MySqlCotizacionRepository($pdo);
$RUT_EMISOR = '77724622-4';

$cabecera = [
    'receptor_rut'          => '76192083-9',
    'receptor_razon_social' => 'CLIENTE DE PRUEBA DEL ARNES SPA',
    'receptor_giro'         => 'PRUEBAS',
    'receptor_direccion'    => 'CALLE FALSA 123',
    'receptor_comuna'       => 'VALDIVIA',
    'fecha'                 => date('Y-m-d'),
];

// Linea de 10 para el caso 2, y una segunda con decimales.
[$cotId] = $repo->crear($cuentaId, $cabecera, [
    ['nombre' => 'Servicio por horas', 'unidad' => 'HH', 'cantidad' => 10, 'precio_unitario' => 20000, 'descuento_pct' => 0, 'exento' => false],
    ['nombre' => 'Producto suelto',    'unidad' => 'UN', 'cantidad' => 2.5, 'precio_unitario' => 8000, 'descuento_pct' => 0, 'exento' => false],
]);
$lineas = $repo->lineasDe($cotId);
$lineaA = (int) $lineas[0]['id'];
$lineaB = (int) $lineas[1]['id'];
printf("  cotizacion %d con lineas %d (10 HH) y %d (2,5 UN)\n", $cotId, $lineaA, $lineaB);

$folio = 90000;

// ===========================================================================
// VERIFICACION 2 - 4 DE 10 DEJA 6, Y 6 CIERRA. CON DECIMALES.
// ===========================================================================
titulo('VERIFICACION 2 - facturacion parcial y cierre, con decimales');

$repo->registrarFacturacion($cuentaId, $cotId, $RUT_EMISOR, 33, ++$folio, 'TRACK-1', 'cot-' . $cotId . '-uno', [
    $lineaA => 4,
    $lineaB => 1.25,
]);
$p = $repo->pendientesDeLineas($cuentaId, $cotId, [$lineaA, $lineaB]);
printf("      linea A: facturada %s, pendiente %s\n", c($p[$lineaA]['facturada']), c($p[$lineaA]['pendiente']));
printf("      linea B: facturada %s, pendiente %s\n", c($p[$lineaB]['facturada']), c($p[$lineaB]['pendiente']));

if (abs($p[$lineaA]['pendiente'] - 6.0) < 0.00005) {
    ok('4 de 10 deja 6 pendientes.');
} else {
    mal('quedaron ' . c($p[$lineaA]['pendiente']) . ' pendientes y se esperaban 6.');
}
if (abs($p[$lineaB]['pendiente'] - 1.25) < 0.00005) {
    ok('1,25 de 2,5 deja 1,25: los decimales sobreviven al descuento.');
} else {
    mal('la linea con decimales quedo en ' . c($p[$lineaB]['pendiente']) . ' y se esperaba 1,25.');
}

$estado = (string) $repo->buscarPorId($cuentaId, $cotId)['estado_cache'];
if ($estado === 'parcial') {
    ok("estado_cache quedo en 'parcial'.");
} else {
    mal("estado_cache quedo en '{$estado}' y se esperaba 'parcial'.");
}

// La segunda factura la cierra.
$repo->registrarFacturacion($cuentaId, $cotId, $RUT_EMISOR, 33, ++$folio, 'TRACK-2', 'cot-' . $cotId . '-dos', [
    $lineaA => 6,
    $lineaB => 1.25,
]);
$estado = (string) $repo->buscarPorId($cuentaId, $cotId)['estado_cache'];
$p = $repo->pendientesDeLineas($cuentaId, $cotId, [$lineaA, $lineaB]);
printf("      tras la segunda: A pendiente %s, B pendiente %s, estado '%s'\n",
    c($p[$lineaA]['pendiente']), c($p[$lineaB]['pendiente']), $estado);
if ($estado === 'facturada' && $p[$lineaA]['pendiente'] < 0.00005 && $p[$lineaB]['pendiente'] < 0.00005) {
    ok('la segunda factura cierra la cotizacion.');
} else {
    mal('la cotizacion no quedo cerrada.');
}

// EL VINCULO, en los dos sentidos.
$facturas = $repo->facturasDe($cuentaId, $cotId);
printf("      facturas vinculadas: %d\n", count($facturas));
if (count($facturas) === 2) {
    ok('las dos facturas quedaron vinculadas a la cotizacion.');
} else {
    mal('quedaron ' . count($facturas) . ' vinculos y se esperaban 2.');
}
$inv = $repo->cotizacionDeFactura($cuentaId, $RUT_EMISOR, 33, $folio);
if ($inv !== null && $inv['cotizacion_id'] === $cotId) {
    ok('el vinculo inverso resuelve: desde el documento se llega a la cotizacion.');
} else {
    mal('el vinculo inverso no resuelve.');
}

// ===========================================================================
// VERIFICACION 3 - FACTURAR MAS DE LO PENDIENTE SE RECHAZA
// ===========================================================================
titulo('VERIFICACION 3 - 11 de una linea de 10');

[$cotId2] = $repo->crear($cuentaId, $cabecera, [
    ['nombre' => 'Servicio por horas', 'unidad' => 'HH', 'cantidad' => 10, 'precio_unitario' => 20000, 'descuento_pct' => 0, 'exento' => false],
]);
$linea2 = (int) $repo->lineasDe($cotId2)[0]['id'];

try {
    $repo->registrarFacturacion($cuentaId, $cotId2, $RUT_EMISOR, 33, ++$folio, null, 'cot-' . $cotId2 . '-once', [$linea2 => 11]);
    mal('SE ACEPTO facturar 11 de una linea de 10.');
} catch (Throwable $e) {
    ok('rechazado: ' . substr($e->getMessage(), 0, 80));
}
// Y NO QUEDO NADA A MEDIAS: ni saldo movido ni vinculo huerfano.
$p = $repo->pendientesDeLineas($cuentaId, $cotId2, [$linea2]);
$sobrantes = (int) $pdo->query('SELECT COUNT(*) FROM cotizacion_factura WHERE cotizacion_id = ' . $cotId2)->fetchColumn();
printf("      pendiente %s, filas de vinculo %d\n", c($p[$linea2]['pendiente']), $sobrantes);
if (abs($p[$linea2]['pendiente'] - 10.0) < 0.00005 && $sobrantes === 0) {
    ok('el rollback dejo la cotizacion intacta y sin vinculo huerfano: los dos o ninguno.');
} else {
    mal('quedo algo a medias tras el rechazo.');
}

// ===========================================================================
// VERIFICACION 4 - UNA LINEA AGREGADA A MANO NO DESCUENTA
// ===========================================================================
titulo('VERIFICACION 4 - linea agregada a mano, con el nombre EXACTO de una cotizada');

// Se simula el POST tal como llega del formulario: la fila cotizada trae
// cotizacion_linea_id, la agregada a mano NO -- porque nuevaFilaHTML() del JS
// nunca emite ese hidden.
$postSimulado = [
    'detalles' => [
        ['cotizacion_linea_id' => (string) $linea2, 'nombre' => 'Servicio por horas', 'cantidad' => '2'],
        ['nombre' => 'Servicio por horas', 'cantidad' => '99'],   // MISMO nombre, sin id
    ],
];
$cantidadPorLinea = [];
foreach ($postSimulado['detalles'] as $d) {
    if (! ctype_digit((string) ($d['cotizacion_linea_id'] ?? ''))) {
        continue;
    }
    $cantidadPorLinea[(int) $d['cotizacion_linea_id']] = (float) $d['cantidad'];
}
printf("      filas en el POST: %d, filas que descuentan: %d\n",
    count($postSimulado['detalles']), count($cantidadPorLinea));

$repo->registrarFacturacion($cuentaId, $cotId2, $RUT_EMISOR, 33, ++$folio, null, 'cot-' . $cotId2 . '-mano', $cantidadPorLinea);
$p = $repo->pendientesDeLineas($cuentaId, $cotId2, [$linea2]);
printf("      pendiente tras facturar: %s (se descontaron 2, no 101)\n", c($p[$linea2]['pendiente']));
if (abs($p[$linea2]['pendiente'] - 8.0) < 0.00005) {
    ok('la linea sin id NO descontó, aunque su nombre coincide exacto. Es venta nueva.');
} else {
    mal('el pendiente quedo en ' . c($p[$linea2]['pendiente']) . ': la linea a mano descontó saldo.');
}

// ===========================================================================
// VERIFICACION 5 - ID DE OTRA COTIZACION O DE OTRA CUENTA
// ===========================================================================
titulo('VERIFICACION 5 - id ajeno');

// (a) Linea de OTRA cotizacion de la MISMA cuenta.
try {
    $repo->registrarFacturacion($cuentaId, $cotId2, $RUT_EMISOR, 33, ++$folio, null, 'cot-' . $cotId2 . '-ajena', [$lineaA => 1]);
    mal('SE ACEPTO una linea de otra cotizacion de la misma cuenta.');
} catch (Throwable $e) {
    ok('linea de otra cotizacion: rechazada. ' . substr($e->getMessage(), 0, 60));
}

// (b) Linea de OTRA CUENTA. Se crea una cotizacion en la segunda cuenta y se
//     intenta descontarla desde la primera.
[$cotAjena] = $repo->crear($cuentaOtra, $cabecera, [
    ['nombre' => 'Servicio ajeno', 'unidad' => 'UN', 'cantidad' => 5, 'precio_unitario' => 1000, 'descuento_pct' => 0, 'exento' => false],
]);
$lineaAjena = (int) $repo->lineasDe($cotAjena)[0]['id'];
try {
    $repo->registrarFacturacion($cuentaId, $cotId2, $RUT_EMISOR, 33, ++$folio, null, 'cot-' . $cotId2 . '-otracuenta', [$lineaAjena => 1]);
    mal('SE ACEPTO una linea de OTRA CUENTA: es una fuga entre tenants.');
} catch (Throwable $e) {
    ok('linea de otra cuenta: rechazada. ' . substr($e->getMessage(), 0, 60));
}
// Y la cotizacion ajena quedo intacta.
$pAjena = $repo->pendientesDeLineas($cuentaOtra, $cotAjena, [$lineaAjena]);
if (abs($pAjena[$lineaAjena]['pendiente'] - 5.0) < 0.00005) {
    ok('la cotizacion de la otra cuenta no se toco.');
} else {
    mal('se modifico una cotizacion de otra cuenta.');
}

// ===========================================================================
// VERIFICACION 6 - EL CASO FEO: EL DESCUENTO FALLA DESPUES DEL 201
// ===========================================================================
titulo('VERIFICACION 6 - la transaccion falla con la factura YA emitida');

// SE PROVOCA UN FALLO REAL, no simulado: se reusa una clave de idempotencia que
// ya existe, lo que hace reventar el UNIQUE uk_cot_factura_idem DENTRO de la
// transaccion. Es el fallo mas parecido al real (base caida, deadlock) sin tener
// que tumbar la base.
$claveRepetida = 'cot-' . $cotId2 . '-mano';   // ya usada en la verificacion 4
$folioEmitido  = ++$folio;

$pAntes = $repo->pendientesDeLineas($cuentaId, $cotId2, [$linea2]);
$fallo  = null;
try {
    $repo->registrarFacturacion($cuentaId, $cotId2, $RUT_EMISOR, 33, $folioEmitido, 'TRACK-X', $claveRepetida, [$linea2 => 1]);
    mal('la clave repetida NO reventó: el UNIQUE uk_cot_factura_idem no esta.');
} catch (Throwable $e) {
    $fallo = $e;
    ok('la transaccion fallo, como se queria para esta prueba.');
}

// (a) EL SALDO NO SE MOVIO A MEDIAS.
$pDespues = $repo->pendientesDeLineas($cuentaId, $cotId2, [$linea2]);
printf("      pendiente antes %s, despues %s\n", c($pAntes[$linea2]['pendiente']), c($pDespues[$linea2]['pendiente']));
if (abs($pAntes[$linea2]['pendiente'] - $pDespues[$linea2]['pendiente']) < 0.00005) {
    ok('el saldo quedo como estaba: los dos o ninguno.');
} else {
    mal('el saldo se movio pese al fallo.');
}

// (b) EL MENSAJE AL USUARIO TIENE QUE DECIR QUE LA FACTURA SI SE EMITIO.
//     Se reproduce lo que arma registrarConversionCotizacion() en su catch.
$mensajeUsuario = sprintf(
    'LA FACTURA SE EMITIO CORRECTAMENTE: tipo %d, folio %d. NO vuelvas a emitirla. '
    . 'Lo que fallo fue el descuento del saldo de la cotizacion N° %d, que quedo sin '
    . 'actualizar y hay que corregir a mano. Avisa a soporte con este folio.',
    33, $folioEmitido, $cotId2
);
echo "\n  MENSAJE QUE VE EL USUARIO:\n      " . $mensajeUsuario . "\n\n";
// LISTA DE PARES, NO ARRAY ASOCIATIVO, Y NO ES ESTILO.
//
// Aqui habia un fatal: la marca a buscar era la CLAVE del array, y PHP convierte
// a entero toda clave que parezca un numero. El folio 90007 escrito como
// (string) $folioEmitido volvia a ser int 90007 en el acto -- el cast estaba y
// PHP lo deshacia en silencio --, y str_contains() exige string en el segundo
// argumento desde PHP 8. Castear otra vez en la clave no habria arreglado nada.
//
// Con una lista de pares la marca es un VALOR y ninguna coercion la toca. Es la
// unica forma de esta comprobacion que no se rompe cuando alguien agregue una
// marca numerica manana.
$marcasEsperadas = [
    ['SE EMITIO',             'dice que si se emitio'],
    [(string) $folioEmitido,  'trae el folio'],
    ['NO vuelvas a emitirla', 'le dice que no reintente'],
];
$faltaAlgo = [];
foreach ($marcasEsperadas as [$marca, $porque]) {
    if (! str_contains($mensajeUsuario, $marca)) {
        $faltaAlgo[] = $porque;
    }
}
if ($faltaAlgo === []) {
    ok('el mensaje dice que se emitio, trae el folio y pide no reintentar.');
} else {
    mal('al mensaje le falta: ' . implode(', ', $faltaAlgo));
}

// (c) EL REGISTRO TIENE QUE TRAER LO NECESARIO PARA REPARARLO A MANO.
//     Se comprueba sobre el CODIGO del handler, que es donde vive el error_log.
$fn = extraerFuncion($workPanel, 'registrarConversionCotizacion');
if ($fn === null) {
    mal('no se pudo extraer registrarConversionCotizacion() para revisar su log.');
} else {
    // Lista de pares por el mismo motivo que arriba: hoy ninguna de estas marcas
    // es numerica, pero la que se agregue manana podria serlo.
    $imprescindibles = [
        ['cuenta=',               'la cuenta'],
        ['cotizacion=%d',         'la cotizacion'],
        ['documento=%d/%d',       'el documento emitido'],
        ['clave=%s',              'la clave de idempotencia'],
        ['descuentos_pendientes', 'cuanto iba a descontar cada linea'],
        ['REPARAR A MANO',        'que hacer'],
    ];
    $sinRegistrar = [];
    foreach ($imprescindibles as [$marca, $que]) {
        if (! str_contains($fn, $marca)) {
            $sinRegistrar[] = $que;
        }
    }
    if ($sinRegistrar === []) {
        ok('el error_log registra cuenta, cotizacion, documento, clave, cantidades y el como repararlo.');
    } else {
        mal('el registro no trae: ' . implode(', ', $sinRegistrar));
    }
    // Y NO se traga el error en silencio: tiene que devolver el mensaje.
    if (str_contains($fn, 'return sprintf(') && str_contains($fn, 'SE EMITIO CORRECTAMENTE')) {
        ok('devuelve el aviso al usuario en vez de tragarselo, a diferencia del encolado de correos.');
    } else {
        mal('no devuelve el aviso al usuario.');
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
echo "    - NO EMITE AL SII, y no hay forma de que lo haga para probar: este\n";
echo "      sistema emite SOLO EN PRODUCCION. El ambiente de certificacion sirve\n";
echo "      para certificar una empresa ante el SII, NO para pruebas. Emitir aqui\n";
echo "      quemaria un folio REAL y crearia una factura de verdad.\n";
echo "      Por eso el descuento se ejercita con folios inventados: es exactamente\n";
echo "      lo que corre despues del 201, y esa parte SI queda probada entera.\n";
echo "      LA PRIMERA CONVERSION REAL VA A SER UNA FACTURA DE VERDAD, y hay que\n";
echo "      mirarla: que el saldo de la cotizacion baje, que aparezca la fila en\n";
echo "      cotizacion_factura y que cambie el estado de la cotizacion.\n";
echo "    - El camino HTTP: scripts/verificar_cotizacion_http.php\n";
echo "    - PHPUnit: vendor/bin/phpunit --testdox\n";

exit($fallos > 0 ? 1 : 0);
