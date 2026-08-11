<?php

declare(strict_types=1);

/**
 * ARNES DE VERIFICACION: la base de la orden de compra.
 *
 * Cubre lo que YA EXISTE y se puede ejercitar sin el runner ni las pantallas:
 * las migraciones 036/037/038, MySqlProveedorRepository,
 * MySqlOrdenCompraRepository y OrdenCompraPdfDocumento.
 *
 * NO TOCA NINGUN CORREO Y NO SALE A INTERNET. El runner no existe todavia.
 *
 * -----------------------------------------------------------------------------
 * COMO PREPARARLO
 *
 *   Variables: las del panel -- DB_HOST, DB_NAME, DB_USER, DB_PASS (+ DB_PORT).
 *   Las tres migraciones aplicadas.
 *
 * Siembra su propia cuenta (dos, para el caso del RUT repetido entre cuentas) y
 * las borra al terminar POR ID EXPLICITO, pase lo que pase.
 * -----------------------------------------------------------------------------
 */

// TECHO DE MEMORIA. Este arnes construye DOS PDF, uno de ellos de 60 lineas que
// ocupa varias paginas. 256M es el mismo numero del motor en produccion: sobra
// para un PDF a la vez y muere mucho antes que la maquina.
ini_set('memory_limit', '256M');

$RAIZ = dirname(__DIR__);
require $RAIZ . '/vendor/autoload.php';
require $RAIZ . '/panel/src/Db.php';

use Plantiflex\FacturacionCl\Pdf\CotizacionPdfGenerator;
use Plantiflex\FacturacionCl\Pdf\OrdenCompraPdfGenerator;
use Plantiflex\Integration\Facturacion\MySqlOrdenCompraRepository;
use Plantiflex\Integration\Facturacion\MySqlProveedorRepository;
use Plantiflex\Integration\Facturacion\ProveedorDuplicadoException;

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
// VERIFICACION 1 - LAS TRES MIGRACIONES
// ===========================================================================
titulo('VERIFICACION 1 - migraciones 036, 037 y 038');

$faltan = [];
foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $v) {
    if (getenv($v) === false || getenv($v) === '') {
        $faltan[] = $v;
    }
}
if ($faltan !== []) {
    morir('faltan variables: ' . implode(', ', $faltan)
        . '. Son las que exige Db::conexion() (panel/src/Db.php). ARNES SIN CORRER.');
}
$pdo = Db::conexion();
ok('conectado con Db::conexion(), la misma via que usa el panel.');

/**
 * Las MISMAS huellas que declara estado_migraciones.php para estas tres. Se
 * comprueban aqui ademas de alli porque este arnes tiene que poder abortar antes
 * de sembrar: correr con una tabla a medias daria errores de SQL que parecerian
 * defectos de los repositorios.
 */
$huellas = [
    ['036', 'tabla',   'proveedor',                null],
    ['036', 'indice',  'proveedor',                'uk_proveedor_rut'],
    ['036', 'columna', 'proveedor',                'condiciones_pago'],
    ['037', 'tabla',   'orden_compra',             null],
    ['037', 'tabla',   'orden_compra_linea',       null],
    ['037', 'tabla',   'orden_compra_correlativo', null],
    ['037', 'indice',  'orden_compra',             'uk_orden_compra_numero'],
    ['037', 'columna', 'orden_compra',             'total'],
    ['038', 'tabla',   'orden_compra_envio',       null],
    ['038', 'indice',  'orden_compra_envio',       'uk_oc_envio'],
    ['038', 'columna', 'orden_compra_envio',       'message_id'],
];
$sinAplicar = [];
foreach ($huellas as [$mig, $tipo, $tabla, $nombre]) {
    $existe = match ($tipo) {
        'tabla'   => $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($tabla))->fetchColumn() !== false,
        'indice'  => $pdo->query("SHOW INDEX FROM `{$tabla}` WHERE Key_name = " . $pdo->quote((string) $nombre))->fetch() !== false,
        'columna' => $pdo->query("SHOW COLUMNS FROM `{$tabla}` LIKE " . $pdo->quote((string) $nombre))->fetch() !== false,
    };
    printf("      %s  %-8s %-26s %-24s %s\n", $mig, $tipo, $tabla, (string) $nombre, $existe ? 'OK' : 'FALTA');
    if (! $existe) {
        $sinAplicar[] = "{$mig}:{$tipo}:{$tabla}" . ($nombre !== null ? ".{$nombre}" : '');
    }
}
if ($sinAplicar !== []) {
    morir('faltan huellas: ' . implode(', ', $sinAplicar)
        . '. Aplica las migraciones antes de correr esto. ARNES SIN CORRER.');
}
ok('las once huellas de las tres migraciones estan.');

echo "\n  EL VERIFICADOR OFICIAL VA APARTE, y conviene correrlo tambien: este\n";
echo "  arnes comprueba lo que necesita para sembrar, no el estado completo del\n";
echo "  esquema ni las migraciones diferidas a proposito.\n";
echo "      php scripts/estado_migraciones.php\n";

// --- Siembra y limpieza ---
$cuentaA = null;
$cuentaB = null;
register_shutdown_function(static function () use (&$cuentaA, &$cuentaB, $pdo): void {
    // PRIMERO SOLTAR TRANSACCIONES ABIERTAS: si el script murio en medio de la
    // prueba de concurrencia, una sesion puede tener locks sobre las mismas
    // filas que la limpieza quiere borrar y el DELETE esperaria al timeout.
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    foreach ([$cuentaA, $cuentaB] as $cid) {
        if ($cid === null) {
            continue;
        }
        try {
            // EN ORDEN DE DEPENDENCIA Y POR ID EXPLICITO. Nada de patrones.
            $pdo->prepare('DELETE e FROM orden_compra_envio e INNER JOIN orden_compra o ON o.id = e.orden_compra_id WHERE o.cuenta_id = ?')->execute([$cid]);
            $pdo->prepare('DELETE l FROM orden_compra_linea l INNER JOIN orden_compra o ON o.id = l.orden_compra_id WHERE o.cuenta_id = ?')->execute([$cid]);
            $pdo->prepare('DELETE FROM orden_compra WHERE cuenta_id = ?')->execute([$cid]);
            $pdo->prepare('DELETE FROM orden_compra_correlativo WHERE cuenta_id = ?')->execute([$cid]);
            $pdo->prepare('DELETE FROM proveedor WHERE cuenta_id = ?')->execute([$cid]);
            $pdo->prepare('DELETE FROM cuenta WHERE id = ?')->execute([$cid]);
            echo "\n  LIMPIEZA: cuenta {$cid} borrada.\n";
        } catch (Throwable $e) {
            echo "\n  *** LA LIMPIEZA FALLO para {$cid}: " . $e->getMessage() . "\n";
            echo "      Borrala a mano: DELETE FROM cuenta WHERE id = {$cid};\n";
        }
    }
});

/** Cuenta como la crea el codigo real (index.php:6506 / sembrar_demo.php:527). */
function crearCuenta(PDO $pdo): int
{
    $pdo->prepare("INSERT INTO cuenta (email, nombre, estado) VALUES (:e, :n, 'activa')")
        ->execute([':e' => 'arnes-oc-' . bin2hex(random_bytes(6)) . '@ejemplo.invalid', ':n' => 'ARNES ORDEN COMPRA']);

    return (int) $pdo->lastInsertId();
}

$cuentaA = crearCuenta($pdo);
$cuentaB = crearCuenta($pdo);
ok("cuentas de prueba: A={$cuentaA}, B={$cuentaB}.");

$repoProv = new MySqlProveedorRepository($pdo);
$repoOc   = new MySqlOrdenCompraRepository($pdo);

// ===========================================================================
// VERIFICACION 5 - EL UNIQUE DE PROVEEDOR
//
// VA ANTES QUE LAS DEMAS porque necesita la base limpia y no depende de nada.
// ===========================================================================
titulo('VERIFICACION 5 - UNIQUE(cuenta_id, rut_proveedor)');

$RUT = '76192083-9';
$idProv = $repoProv->crear($cuentaA, [
    'rut_proveedor' => $RUT, 'razon_social' => 'PROVEEDOR DE PRUEBA SPA',
    'giro' => 'INSUMOS', 'direccion' => 'CALLE FALSA 123', 'comuna' => 'VALDIVIA',
    'email' => 'no-existe@ejemplo.invalid', 'contacto' => 'Juan Perez',
    'condiciones_pago' => '30 dias',
]);
ok("proveedor creado en A (id {$idProv}).");

// (a) EL MISMO RUT EN LA MISMA CUENTA: rechazado.
try {
    $repoProv->crear($cuentaA, ['rut_proveedor' => $RUT, 'razon_social' => 'OTRO NOMBRE']);
    mal('SE ACEPTO un RUT duplicado en la misma cuenta: el UNIQUE no esta actuando.');
} catch (ProveedorDuplicadoException $e) {
    ok('duplicado en la misma cuenta: rechazado con ProveedorDuplicadoException.');
} catch (Throwable $e) {
    mal('rechazado, pero con otra excepcion (' . $e::class . '): el traductor de errno 1062 no actuo.');
}

// (b) EL MISMO RUT EN OTRA CUENTA: aceptado. Es el aislamiento por tenant.
try {
    $repoProv->crear($cuentaB, ['rut_proveedor' => $RUT, 'razon_social' => 'MISMO RUT, OTRA EMPRESA']);
    ok('el mismo RUT en OTRA cuenta si se acepta: cada tenant tiene su maestro.');
} catch (Throwable $e) {
    mal('se rechazo el mismo RUT en otra cuenta: ' . $e->getMessage());
}

// (c) Y LO QUE MOTIVO LA TABLA APARTE: que ese RUT pueda ser cliente Y proveedor
//     de la MISMA cuenta. Se comprueba que las dos tablas no compartan clave.
$hayCliente = $pdo->query("SHOW TABLES LIKE 'cliente'")->fetchColumn() !== false;
if ($hayCliente) {
    $pdo->prepare('INSERT INTO cliente (cuenta_id, rut_cliente, razon_social) VALUES (?, ?, ?)')
        ->execute([$cuentaA, $RUT, 'EL MISMO RUT, COMO CLIENTE']);
    $n = $pdo->prepare('SELECT COUNT(*) FROM cliente WHERE cuenta_id = ? AND rut_cliente = ?');
    $n->execute([$cuentaA, $RUT]);
    if ((int) $n->fetchColumn() === 1) {
        ok('el MISMO RUT es cliente Y proveedor de la MISMA cuenta: es el motivo '
            . 'entero de no generalizar cliente.');
    } else {
        mal('no se pudo crear el cliente con el mismo RUT.');
    }
    $pdo->prepare('DELETE FROM cliente WHERE cuenta_id = ? AND rut_cliente = ?')->execute([$cuentaA, $RUT]);
} else {
    aviso('no existe la tabla cliente en esta base: no se pudo probar el caso cruzado.');
}

// El listado NO tiene filtro de incompletos, y eso es deliberado.
$metodos = get_class_methods(MySqlProveedorRepository::class);
$deFacturacion = array_intersect($metodos, ['contarIncompletos']);
if ($deFacturacion === []) {
    ok('el repositorio NO trae contarIncompletos(): la regla de "puede facturarle" '
        . 'es del SII y a un proveedor no se le emite ningun DTE.');
} else {
    mal('el repositorio copio ' . implode(',', $deFacturacion) . ' de cliente, que no aplica.');
}

// ===========================================================================
// VERIFICACION 3 - EL IVA, CON LINEAS AFECTAS Y EXENTAS MEZCLADAS
// ===========================================================================
titulo('VERIFICACION 3 - la regla del IVA (DteXmlBuilder::TASA_IVA)');

// NUMEROS ELEGIDOS PARA QUE LA DIFERENCIA SE VEA. Tres lineas afectas de 333:
//   - redondeando UNA vez:      round(999 * 0.19) = round(189,81) = 190
//   - redondeando POR LINEA:    round(333 * 0.19) * 3 = 63 * 3   = 189
// Un peso de diferencia. Con cifras "redondas" las dos formas coinciden y la
// prueba no probaria nada.
$lineas = [
    ['nombre' => 'Afecto A', 'cantidad' => 1, 'precio_unitario' => 333, 'descuento_pct' => 0, 'exento' => false, 'unidad' => 'UN'],
    ['nombre' => 'Afecto B', 'cantidad' => 1, 'precio_unitario' => 333, 'descuento_pct' => 0, 'exento' => false, 'unidad' => 'UN'],
    ['nombre' => 'Afecto C', 'cantidad' => 1, 'precio_unitario' => 333, 'descuento_pct' => 0, 'exento' => false, 'unidad' => 'UN'],
    ['nombre' => 'Exento',   'cantidad' => 2, 'precio_unitario' => 5000, 'descuento_pct' => 0, 'exento' => true,  'unidad' => 'UN'],
];

$t = MySqlOrdenCompraRepository::totales($lineas);
printf("      neto=%d exento=%d iva=%d total=%d\n", $t['neto'], $t['exento'], $t['iva'], $t['total']);

$tasa = MySqlOrdenCompraRepository::TASA_IVA;
printf("      TASA_IVA del repositorio: %d\n", $tasa);
if ($tasa === 19) {
    ok('la tasa es 19, la misma de DteXmlBuilder.');
} else {
    mal("la tasa es {$tasa} y DteXmlBuilder usa 19.");
}

$ivaUnaVez   = (int) round(999 * $tasa / 100);
$ivaPorLinea = 3 * (int) round(333 * $tasa / 100);
printf("      IVA redondeando una vez: %d   por linea: %d\n", $ivaUnaVez, $ivaPorLinea);
if ($ivaUnaVez === $ivaPorLinea) {
    morir('las dos formas dan lo mismo con estos numeros: la prueba no distingue nada. '
        . 'Cambia los importes de la siembra.');
}

if ($t['iva'] === $ivaUnaVez) {
    ok("el IVA se redondea UNA vez sobre el neto afecto ({$ivaUnaVez}), no por linea ({$ivaPorLinea}).");
} else {
    mal("el IVA salio {$t['iva']}: se esperaba {$ivaUnaVez} (una vez sobre el afecto).");
}

// EL EXENTO NO PAGA IVA. Si el exento entrara al calculo, el IVA seria mayor.
if ($t['exento'] === 10000) {
    ok('el exento se suma aparte y NO entra en la base del IVA.');
} else {
    mal("el exento salio {$t['exento']} y deberia ser 10000.");
}
if ($t['total'] === $t['neto'] + $t['exento'] + $t['iva']) {
    ok('el total cuadra con sus componentes.');
} else {
    mal('el total no es la suma de neto + exento + IVA.');
}

// Y AHORA POR EL CAMINO REAL: se guarda la orden y se relee de la base, porque
// lo que importa es lo que quedo GUARDADO -- los totales son columnas, no un
// calculo al mostrar.
$cabecera = [
    'proveedor_id'           => $idProv,
    'proveedor_rut'          => $RUT,
    'proveedor_razon_social' => 'PROVEEDOR DE PRUEBA SPA',
    'proveedor_giro'         => 'INSUMOS',
    'proveedor_direccion'    => 'CALLE FALSA 123',
    'proveedor_comuna'       => 'VALDIVIA',
    'proveedor_email'        => 'no-existe@ejemplo.invalid',
    'proveedor_contacto'     => 'Juan Perez',
    'condiciones_pago'       => '30 dias',
    'fecha'                  => date('Y-m-d'),
    'fecha_entrega'          => date('Y-m-d', strtotime('+15 days')),
    'lugar_entrega'          => 'Bodega central',
    'notas'                  => 'Orden creada por el arnes.',
];
[$idOc, $numOc] = $repoOc->crear($cuentaA, $cabecera, $lineas);
$oc = $repoOc->buscarPorId($cuentaA, $idOc);
printf("      orden %d (numero %d) releida: neto=%s iva=%s total=%s, %d lineas\n",
    $idOc, $numOc, $oc['neto'], $oc['iva'], $oc['total'], count($oc['lineas']));

if ((int) $oc['iva'] === $ivaUnaVez && (int) $oc['total'] === $t['total']) {
    ok('los totales quedaron GUARDADOS en la cabecera, no se recalculan al leer.');
} else {
    mal('los totales guardados no coinciden con los calculados.');
}
if (count($oc['lineas']) === 4) {
    ok('las cuatro lineas quedaron, afectas y exentas mezcladas.');
} else {
    mal('quedaron ' . count($oc['lineas']) . ' lineas y se enviaron 4.');
}

// ===========================================================================
// VERIFICACION 2 - EL CORRELATIVO BAJO CONCURRENCIA
// ===========================================================================
titulo('VERIFICACION 2 - correlativo con dos conexiones reales');

// LA SEGUNDA CONEXION NO PUEDE SALIR DE Db::conexion(): esa clase cachea la
// conexion en una propiedad estatica y devolveria LA MISMA instancia, con lo que
// las dos "transacciones" serian una sola y la prueba pasaria sin probar nada.
$dsn2 = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST'), getenv('DB_PORT') ?: '3306', getenv('DB_NAME'));
$pdo2 = new PDO($dsn2, (string) getenv('DB_USER'), (string) getenv('DB_PASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$repoOc2 = new MySqlOrdenCompraRepository($pdo2);

$id1 = $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
$id2 = $pdo2->query('SELECT CONNECTION_ID()')->fetchColumn();
printf("  sesiones MySQL: %s y %s\n", $id1, $id2);
if ($id1 === $id2) {
    morir('las dos conexiones son la MISMA sesion: la prueba no probaria nada.');
}
ok('son dos sesiones distintas.');

// EL TIMEOUT DE LOCK ES LA COMPROBACION, NO UN ESTORBO. Si la conexion 1 NO se
// bloqueara, seria porque el FOR UPDATE no toma el lock y dos altas simultaneas
// se llevarian el mismo numero. Se baja SOLO EN ESTAS SESIONES: el default son
// 50 segundos y dejaria el terminal pegado casi un minuto.
const ESPERA_LOCK = 3;
foreach ([$pdo, $pdo2] as $c) {
    $c->exec('SET SESSION innodb_lock_wait_timeout = ' . ESPERA_LOCK);
}
printf("  innodb_lock_wait_timeout = %ds SOLO en estas dos sesiones.\n", ESPERA_LOCK);

$pdo2->beginTransaction();
$n2 = $repoOc2->asignarNumero($cuentaA);
printf("  conexion 2 reservo el numero %d y NO ha hecho commit.\n", $n2);

$t0 = microtime(true);
try {
    $pdo->beginTransaction();
    $nColado = $repoOc->asignarNumero($cuentaA);
    $ms = (microtime(true) - $t0) * 1000;
    $pdo->rollBack();
    mal(sprintf('LA CONEXION 1 NO SE BLOQUEO: reservo el %d en %.0f ms con el lock tomado%s.',
        $nColado, $ms, $nColado === $n2 ? ' -- de hecho se llevo EL MISMO' : ''));
} catch (PDOException $e) {
    $ms = (microtime(true) - $t0) * 1000;
    if ($pdo->inTransaction()) {
        // El timeout aborta la SENTENCIA, no siempre la transaccion entera.
        $pdo->rollBack();
    }
    printf("  conexion 1 espero %.0f ms y no pudo entrar.\n", $ms);
    if (stripos($e->getMessage(), 'lock wait timeout') !== false) {
        ok('SE BLOQUEO, que es lo correcto: la segunda sesion espera en vez de colarse.');
    } else {
        mal('fallo por otra cosa, no por el lock: ' . substr($e->getMessage(), 0, 100));
    }
    if ($ms >= (ESPERA_LOCK * 1000) * 0.8) {
        ok(sprintf('y espero %.1f s, coherente con el timeout de la sesion.', $ms / 1000));
    } else {
        aviso(sprintf('espero solo %.0f ms: revisa que el bloqueo sea el del correlativo.', $ms));
    }
}

$pdo2->commit();
$pdo->beginTransaction();
$n1 = $repoOc->asignarNumero($cuentaA);
$pdo->commit();
printf("  tras el commit de la 2, la conexion 1 obtuvo el numero %d.\n", $n1);
if ($n1 === $n2 + 1) {
    ok("es el SIGUIENTE ({$n2} -> {$n1}): ni repetido ni con hueco.");
} elseif ($n1 === $n2) {
    mal("obtuvo EL MISMO numero ({$n1}). El correlativo no esta protegido.");
} else {
    mal("obtuvo {$n1} y se esperaba " . ($n2 + 1) . ': la numeracion quedo con un hueco.');
}

// Sin transaccion abierta tiene que negarse a reservar.
try {
    $repoOc->asignarNumero($cuentaA);
    mal('asignarNumero() reservo SIN transaccion abierta: el lock no valdria nada.');
} catch (RuntimeException $e) {
    ok('sin transaccion abierta se niega a reservar, con el motivo explicito.');
}

// Y el correlativo es POR CUENTA: el de B no se movio.
$pdo->beginTransaction();
$nB = $repoOc->asignarNumero($cuentaB);
$pdo->commit();
printf("  primer numero de la cuenta B: %d\n", $nB);
if ($nB === 1) {
    ok('el correlativo es por cuenta: B arranca en 1 pese a lo consumido por A.');
} else {
    mal("B arranco en {$nB} y deberia arrancar en 1.");
}

// ===========================================================================
// VERIFICACION 4 - EL PDF
// ===========================================================================
titulo('VERIFICACION 4 - el PDF: pagina solo, y no mueve el de cotizacion');

/** Flujos de contenido descomprimidos: el unico criterio valido, nunca el archivo. */
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
        // Un stream que no descomprime es una fuente incrustada o una imagen: no
        // aporta al dibujo. Contarlo en crudo moveria el md5 sin que nada del
        // dibujo hubiera cambiado.
        if ($plano !== false) {
            $out .= $plano . "\n";
        }
    }

    return $out;
}
function memoria(): string
{
    return sprintf('%.1f MB (pico %.1f MB)',
        memory_get_usage(true) / 1048576, memory_get_peak_usage(true) / 1048576);
}

$emisor = [
    'RUTEmisor'  => '77724622-4',
    'RznSoc'     => 'SOCIEDAD DE PROFESIONALES ROSAS Y VILLAR LIMITADA',
    'GiroEmis'   => 'SERVICIOS PROFESIONALES DE INGENIERIA Y CONSTRUCCION',
    'DirOrigen'  => 'AVENIDA PICARTE 1234, OFICINA 501',
    'CmnaOrigen' => 'VALDIVIA',
];

/** @return list<array<string,mixed>> */
function lineasDe(int $n): array
{
    $out = [];
    for ($i = 1; $i <= $n; $i++) {
        $out[] = [
            'nombre'          => "Insumo numero {$i}",
            'descripcion'     => $i % 5 === 0 ? 'Con una descripcion larga que ocupa su segunda linea en el impreso' : '',
            'unidad'          => $i % 2 === 0 ? 'KG' : 'UN',
            'cantidad'        => $i === 1 ? 2.5 : $i,
            'precio_unitario' => 1000 + $i,
            'descuento_pct'   => $i === 3 ? 10 : 0,
            'exento'          => $i % 7 === 0,
        ];
    }

    return $out;
}

/** Construye, extrae el flujo y SUELTA el PDF. De a uno y liberando. */
function flujoDeOrden(array $emisor, array $cabecera, int $nLineas): array
{
    $lineas = lineasDe($nLineas);
    $orden  = $cabecera + ['numero' => 999] + MySqlOrdenCompraRepository::totales($lineas);
    $pdf    = (new OrdenCompraPdfGenerator())->generar($emisor, $orden, $lineas, null);
    $paginas = preg_match_all('#/Type\s*/Page[^s]#', $pdf);
    $flujo  = flujoDescomprimido($pdf);
    $bytes  = strlen($pdf);
    unset($pdf);
    gc_collect_cycles();

    return ['md5' => md5($flujo), 'vacio' => $flujo === '', 'paginas' => $paginas, 'bytes' => $bytes];
}

foreach ([3 => 1, 60 => 2] as $n => $paginasMinimas) {
    $r = flujoDeOrden($emisor, $cabecera, $n);
    printf("\n      %2d lineas: %d bytes, %d pagina(s), memoria %s\n", $n, $r['bytes'], $r['paginas'], memoria());
    if ($r['vacio']) {
        morir("con {$n} lineas el flujo salio VACIO. Fallo del arnes (descompresion), no de la entrega.");
    }
    if ($r['paginas'] >= $paginasMinimas) {
        ok("ocupa {$r['paginas']} pagina(s): la tabla pagina sola con {$n} lineas.");
    } else {
        mal("ocupa {$r['paginas']} pagina(s) y con {$n} lineas se esperaban al menos {$paginasMinimas}: "
            . 'la tabla NO esta paginando y se dibujaria fuera de la hoja.');
    }
}

// DETERMINISMO. "Inerte contra HEAD" no se puede pedir a una clase que nace en
// esta entrega: no hay version anterior con la que comparar. Lo que SI se puede
// exigir es que dos corridas iguales den el mismo flujo -- sin eso, ninguna
// comparacion futura contra HEAD valdria nada.
$a = flujoDeOrden($emisor, $cabecera, 3);
$b = flujoDeOrden($emisor, $cabecera, 3);
printf("\n      dos corridas iguales: %s / %s\n", $a['md5'], $b['md5']);
if ($a['md5'] === $b['md5']) {
    ok('el flujo es DETERMINISTA: mismo insumo, mismo dibujo. Es la linea base '
        . 'contra la que se podra comparar en la proxima entrega.');
} else {
    mal('dos corridas iguales dan flujos distintos: hay algo variable en el dibujo '
        . '(fecha, id) y la inercia no se podra medir nunca.');
}

// LA INERCIA QUE SI SE PUEDE MEDIR HOY: que el PDF de COTIZACION no se haya
// movido. La orden de compra se hizo como CLASE HERMANA justamente para no
// tocarlo; esto lo comprueba en vez de confiarlo.
$cotLineas = [
    ['nombre' => 'Servicio', 'descripcion' => '', 'unidad' => 'HH', 'cantidad' => 2.5,
     'precio_unitario' => 12000, 'descuento_pct' => 0, 'exento' => 0],
    ['nombre' => 'Producto', 'descripcion' => '', 'unidad' => 'UN', 'cantidad' => 3,
     'precio_unitario' => 4500, 'descuento_pct' => 10, 'exento' => 0],
];
$cot = ['numero' => 1, 'fecha' => '2026-08-10', 'valida_hasta' => '2026-09-10',
        'receptor_rut' => $RUT, 'receptor_razon_social' => 'CLIENTE', 'receptor_giro' => 'PRUEBAS',
        'receptor_direccion' => 'CALLE 1', 'receptor_comuna' => 'VALDIVIA', 'notas' => ''];
$c1 = md5(flujoDescomprimido((new CotizacionPdfGenerator())->generar($emisor, $cot, $cotLineas, null)));
$c2 = md5(flujoDescomprimido((new CotizacionPdfGenerator())->generar($emisor, $cot, $cotLineas, null)));
printf("      cotizacion, dos corridas: %s / %s\n", $c1, $c2);
if ($c1 === $c2) {
    ok('el PDF de cotizacion sigue siendo determinista tras agregar la orden de compra.');
} else {
    mal('el PDF de cotizacion dejo de ser determinista.');
}
if ($c1 !== $a['md5']) {
    ok('y produce un dibujo DISTINTO del de la orden: son dos documentos, no uno con otro titulo.');
} else {
    mal('la cotizacion y la orden producen el MISMO flujo: algo esta mal cableado.');
}

// ===========================================================================
// RESUMEN
// ===========================================================================
titulo('RESUMEN');
printf("  fallos: %d\n  avisos: %d\n", $fallos, $avisos);
printf("\n  MEMORIA: pico %.1f MB (limite %s)\n",
    memory_get_peak_usage(true) / 1048576, ini_get('memory_limit'));

echo "\n  LO QUE ESTE ARNES NO CUBRE, porque todavia no existe:\n";
echo "    - EL RUNNER DE CORREO. No hay envio, ni cola vaciada, ni BrevoMailer\n";
echo "      ejercitado. orden_compra_envio se crea pero nada la consume.\n";
echo "    - LAS DOS PANTALLAS. No hay camino HTTP, ni csrfInput() que comprobar:\n";
echo "      la leccion mas cara de cotizacion sigue sin poder aplicarse aqui.\n";
echo "    - El aspecto del PDF. Se comprueba que pagine y que sea determinista,\n";
echo "      no que se vea bien: eso lo mira Daniel.\n";
echo "    - PHPUnit: vendor/bin/phpunit --testdox\n";

exit($fallos > 0 ? 1 : 0);
