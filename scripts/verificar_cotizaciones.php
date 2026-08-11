<?php

declare(strict_types=1);

/**
 * ARNES DE VERIFICACION: cotizaciones, primera entrega.
 *
 * Cubre las verificaciones 1 a 5. La 6 (PHPUnit) se corre aparte.
 *
 * SIEMBRA SUS PROPIOS DATOS Y LIMPIA TODO AL TERMINAR, pase lo que pase. No
 * toca ninguna fila que no haya creado: trabaja sobre una CUENTA PROPIA, creada
 * al empezar y borrada al final por id explicito -- nunca por patron.
 *
 * ------------------------------------------------------------------------
 * COMO PREPARARLO (git NO existe dentro del contenedor):
 *
 *   cd Y:/webserver/sinergia_fac_bol
 *   git show HEAD:panel/public/index.php > scripts/HEAD_panel_index.php
 *   git show HEAD:public/index.php       > scripts/HEAD_public_index.php
 *
 * Y DESPUES SE BORRAN A MANO, POR RUTA EXPLICITA:
 *
 *   rm scripts/HEAD_panel_index.php scripts/HEAD_public_index.php
 *
 * VARIABLES DE ENTORNO: las mismas que el panel, ni una mas -- DB_HOST, DB_NAME,
 * DB_USER, DB_PASS y opcionalmente DB_PORT. Si falta alguna, el script dice
 * CUAL por su nombre y para.
 * ------------------------------------------------------------------------
 */

// TECHO DE MEMORIA, ANTES DE TODO. Este arnes construye DOS PDF (uno de 3 lineas
// y otro de 40, que ocupa varias paginas). Mismo numero que usa el motor en
// produccion (docker/Dockerfile.motor:48): sobra para un PDF a la vez y muere
// mucho antes que la maquina. El pico real se imprime al final.
ini_set('memory_limit', '256M');

$RAIZ = dirname(__DIR__);
require $RAIZ . '/vendor/autoload.php';

// LA CONEXION SE TOMA DEL CODIGO REAL, NO SE REINVENTA.
//
// La primera version de este arnes leia una variable DB_DSN que NO EXISTE en
// este proyecto, y abortaba siempre. Es el mismo tropiezo que el CAST del XML:
// el arnes inventando una forma de hablar con la base distinta de la que usa el
// codigo. Ahora se carga panel/src/Db.php y se usa Db::conexion() tal cual --
// las variables, el puerto por defecto, el charset y los atributos salen de ahi
// y no de lo que a este script le parezca.
require $RAIZ . '/panel/src/Db.php';

use Plantiflex\Integration\Facturacion\MySqlCotizacionRepository;
use Plantiflex\Integration\Facturacion\CotizacionFacturadaException;
use Plantiflex\FacturacionCl\Pdf\CotizacionPdfGenerator;

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
// PANTALLA 0 - CONEXION Y SIEMBRA
// ===========================================================================
titulo('PANTALLA 0 - CONEXION Y SIEMBRA');

// LAS VARIABLES SON LAS QUE EXIGE Db::conexion(), UNA POR UNA Y POR SU NOMBRE.
// DB_PORT no entra en la lista a proposito: Db le da default 3306 "por ser un
// dato de bajo riesgo", asi que faltar no es un problema.
$faltan = [];
foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $var) {
    $v = getenv($var);
    if ($v === false || $v === '') {
        $faltan[] = $var;
    }
}
if ($faltan !== []) {
    morir('faltan variables de entorno: ' . implode(', ', $faltan)
        . '. Son las que exige Db::conexion() (panel/src/Db.php). '
        . 'ARNES SIN CORRER -- no es un fallo de la entrega.');
}
printf("  DB_HOST=%s DB_PORT=%s DB_NAME=%s DB_USER=%s DB_PASS=(%d caracteres)\n",
    getenv('DB_HOST'), getenv('DB_PORT') ?: '3306 (default de Db)', getenv('DB_NAME'),
    getenv('DB_USER'), strlen((string) getenv('DB_PASS')));

try {
    $pdo = Db::conexion();
} catch (Throwable $e) {
    morir('no se pudo conectar: ' . $e->getMessage() . ' -- ARNES SIN CORRER.');
}
ok('conectado con Db::conexion(), la misma via que usa el panel.');

// La migracion tiene que estar aplicada. Si falta, se declara y se para: correr
// sin ella daria errores de SQL que parecerian defectos de la entrega.
foreach (['cotizacion', 'cotizacion_linea', 'cotizacion_correlativo'] as $t) {
    if ($pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetchColumn() === false) {
        morir("falta la tabla {$t}: aplica la migracion 032 antes de correr esto. ARNES SIN CORRER.");
    }
}
ok('las tres tablas de la 032 existen.');

// --- CUENTA PROPIA, Y SU LIMPIEZA REGISTRADA ANTES DE CREAR NADA ---
//
// SE REGISTRA ANTES DE INSERTAR NADA, para que un fallo a mitad de la siembra
// tambien limpie. $cuentaId va por referencia porque todavia no existe.
//
// ESTE ARNES CORRE EN LA BASE DE DESARROLLO, donde tambien vive la cuenta de
// sembrar_demo.php. Por eso: nunca se reusa una cuenta existente, todo se borra
// POR ID EXPLICITO -- el que devolvio lastInsertId() -- y jamas por patron ni
// por LIKE sobre el nombre.
$cuentaId = null;
$conexiones = [&$pdo];
register_shutdown_function(static function () use (&$cuentaId, &$conexiones): void {
    // PRIMERO SOLTAR LAS TRANSACCIONES ABIERTAS. Si el script murio en medio de
    // la prueba de concurrencia, una de las dos sesiones puede tener locks
    // tomados sobre las mismas filas que la limpieza quiere borrar: sin esto, el
    // DELETE se quedaria esperando hasta el timeout.
    foreach ($conexiones as $c) {
        if ($c instanceof PDO && $c->inTransaction()) {
            $c->rollBack();
        }
    }
    if ($cuentaId === null) {
        return;
    }
    $pdo = $conexiones[0];
    try {
        $n = [];
        $s = $pdo->prepare('DELETE l FROM cotizacion_linea l INNER JOIN cotizacion c ON c.id = l.cotizacion_id WHERE c.cuenta_id = ?');
        $s->execute([$cuentaId]);
        $n['lineas'] = $s->rowCount();
        $s = $pdo->prepare('DELETE FROM cotizacion WHERE cuenta_id = ?');
        $s->execute([$cuentaId]);
        $n['cotizaciones'] = $s->rowCount();
        $s = $pdo->prepare('DELETE FROM cotizacion_correlativo WHERE cuenta_id = ?');
        $s->execute([$cuentaId]);
        $n['correlativo'] = $s->rowCount();
        $s = $pdo->prepare('DELETE FROM cuenta WHERE id = ?');
        $s->execute([$cuentaId]);
        $n['cuenta'] = $s->rowCount();

        echo "\n  LIMPIEZA (cuenta {$cuentaId}): ";
        foreach ($n as $que => $cuantas) {
            echo "{$que}={$cuantas} ";
        }
        echo "\n";
        if ($n['cuenta'] !== 1) {
            echo "  *** LA CUENTA NO SE BORRO. Borrala a mano: DELETE FROM cuenta WHERE id = {$cuentaId};\n";
        }
    } catch (Throwable $e) {
        echo "\n  *** LA LIMPIEZA FALLO: borra a mano la cuenta {$cuentaId}. " . $e->getMessage() . "\n";
    }
});

// --- LA CUENTA SE CREA COMO LA CREA EL CODIGO REAL ---
//
// NO hay repositorio para cuenta: los DOS sitios que la crean escriben el mismo
// INSERT a mano, y coinciden --
//   panel/public/index.php:6506  (alta de tenant, handleRegistroPost)
//   scripts/sembrar_demo.php:527 (siembra de la demo)
// los dos: INSERT INTO cuenta (email, nombre, estado) VALUES (..., 'activa').
//
// La version anterior de este arnes armaba el INSERT desde lo que le PARECIA que
// tenia la tabla -- con un SHOW COLUMNS para adivinar-- y reventaba con
// "Field 'email' doesn't have a default value". Mismo patron que el DB_DSN
// inventado. Aqui se copia la forma probada, y punto.
$emailArnes = 'arnes-cotizaciones-' . bin2hex(random_bytes(6)) . '@ejemplo.invalid';

// GUARDA CONTRA LA COLUMNA OBLIGATORIA DE MAÑANA. Si alguien agrega a cuenta una
// columna NOT NULL sin default, este arnes tiene que decirlo por su nombre en vez
// de morir con un error de SQL que parece un defecto de la entrega. Se comprueba
// ANTES de insertar y contra las columnas que el INSERT real cubre.
$cubiertas = ['email', 'nombre', 'estado'];
$obligatoriasSinCubrir = [];
foreach ($pdo->query('SHOW COLUMNS FROM cuenta')->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $esAuto    = str_contains((string) $c['Extra'], 'auto_increment');
    $tieneDef  = $c['Default'] !== null;
    $aceptaNul = strtoupper((string) $c['Null']) === 'YES';
    if (! $esAuto && ! $tieneDef && ! $aceptaNul && ! in_array($c['Field'], $cubiertas, true)) {
        $obligatoriasSinCubrir[] = (string) $c['Field'];
    }
}
if ($obligatoriasSinCubrir !== []) {
    morir('la tabla cuenta gano columnas obligatorias que este arnes no manda: '
        . implode(', ', $obligatoriasSinCubrir)
        . '. Mira como las llena el alta real (panel/public/index.php:6506 y '
        . 'scripts/sembrar_demo.php:527) y copia esa forma. ARNES SIN CORRER.');
}

// SIEMPRE UNA CUENTA NUEVA, NUNCA SE REUSA UNA EXISTENTE: el email lleva 12
// caracteres aleatorios justamente para que no pueda chocar con nada de la base
// de desarrollo, donde tambien vive la cuenta de sembrar_demo.php.
$pdo->prepare("INSERT INTO cuenta (email, nombre, estado) VALUES (:e, :n, 'activa')")
    ->execute([':e' => $emailArnes, ':n' => 'ARNES COTIZACIONES']);
$cuentaId = (int) $pdo->lastInsertId();
if ($cuentaId <= 0) {
    morir('el INSERT de cuenta no devolvio id: no hay nada que limpiar despues.');
}
ok("cuenta de prueba creada: id {$cuentaId} ({$emailArnes}).");

$repo = new MySqlCotizacionRepository($pdo);

/** @return list<array<string,mixed>> */
function lineasDePrueba(int $n = 3): array
{
    $out = [];
    for ($i = 1; $i <= $n; $i++) {
        $out[] = [
            'nombre'          => "Servicio de prueba numero {$i}",
            'descripcion'     => $i % 3 === 0 ? 'Con una descripcion larga que ocupa su segunda linea en el impreso' : '',
            'unidad'          => $i % 2 === 0 ? 'HH' : 'UN',
            'cantidad'        => $i === 1 ? 2.5 : $i,          // decimales desde la primera
            'precio_unitario' => 1000 * $i,
            'descuento_pct'   => $i === 2 ? 10 : 0,
            'exento'          => $i === 3,
        ];
    }

    return $out;
}

// --- NO SE SIEMBRA NI cliente NI producto, Y ES A PROPOSITO ---
//
// Las dos tablas tienen columnas obligatorias y repositorio propio con crear(),
// asi que si hicieran falta habria que usar MySqlClienteRepository::crear() y
// MySqlProductoRepository::crear() en vez de escribir el INSERT a mano -- que es
// justo el error que se acaba de corregir en cuenta.
//
// Pero NO hacen falta: cotizacion.cliente_id y cotizacion_linea.producto_id son
// NULLABLE Y SIN FK (ver migracion 032), porque una cotizacion puede tener
// lineas de texto libre que no salen de ningun maestro. Este arnes las deja en
// null, que es el caso que interesa probar. El datalist de productos lo usa la
// VISTA, y la vista no se ejercita aqui.
$cabecera = [
    'receptor_rut'          => '76192083-9',
    'receptor_razon_social' => 'CLIENTE DE PRUEBA DEL ARNES SPA',
    'receptor_giro'         => 'PRUEBAS',
    'receptor_direccion'    => 'CALLE FALSA 123',
    'receptor_comuna'       => 'VALDIVIA',
    'receptor_email'        => 'no-existe@ejemplo.invalid',
    'fecha'                 => '2026-08-10',
    'valida_hasta'          => '2026-09-10',
    'notas'                 => 'Texto libre que en un DTE no cabria: el Formato DTE no ofrece glosa de documento.',
];

// ===========================================================================
// VERIFICACION 1 - EL SALDO POR LINEA, CON DECIMALES
// ===========================================================================
titulo('VERIFICACION 1 - saldo por linea, decimales, y la linea sin identificador');

[$idA, $numA] = $repo->crear($cuentaId, $cabecera, lineasDePrueba(3));
printf("  cotizacion creada: id %d, numero %d\n", $idA, $numA);

$cot = $repo->buscarPorId($cuentaId, $idA);
if ($cot === null) {
    morir('la cotizacion recien creada no se puede leer.');
}

echo "\n      linea  id      cantidad  facturada  pendiente\n";
echo "      -----  ------  --------  ---------  ---------\n";
foreach ($cot['lineas'] as $l) {
    printf("      %5d  %6d  %8s  %9s  %9s\n", $l['orden'], $l['id'],
        $l['cantidad'], $l['cantidad_facturada'],
        (string) ((float) $l['cantidad'] - (float) $l['cantidad_facturada']));
}

// (a) Toda linea nace con saldo intacto y la cotizacion en 'sin_facturar'.
if ((string) $cot['estado_cache'] === 'sin_facturar') {
    ok('nace en sin_facturar, y lo escribio recalcularEstado(), no un INSERT a mano.');
} else {
    mal("nace en '{$cot['estado_cache']}' y deberia ser sin_facturar.");
}
if (! $repo->tieneFacturacion($cuentaId, $idA)) {
    ok('tieneFacturacion() dice que no, mirando las CANTIDADES y no el cache.');
} else {
    mal('tieneFacturacion() dice que si en una cotizacion recien creada.');
}

// (b) LOS DECIMALES SOBREVIVEN AL VIAJE. 2,5 tiene que volver 2,5 y no 2 ni 3.
$primera = $cot['lineas'][0];
if (abs((float) $primera['cantidad'] - 2.5) < 0.00005) {
    ok('la cantidad 2,5 volvio intacta: la columna es DECIMAL, no entera.');
} else {
    mal("la cantidad volvio como {$primera['cantidad']} y se sembro 2,5.");
}

// (c) EL SALDO SE MUEVE POR LINEA Y ADMITE FRACCIONES. Se simula lo que hara la
//     segunda entrega: un UPDATE sobre UNA linea, identificada POR SU id.
$pdo->prepare('UPDATE cotizacion_linea SET cantidad_facturada = ? WHERE id = ?')
    ->execute([1.25, $primera['id']]);
$estado = $repo->recalcularEstado($cuentaId, $idA);
printf("\n  facturada 1,25 de 2,5 en la linea id %d -> estado '%s'\n", $primera['id'], $estado);
if ($estado === 'parcial') {
    ok('el estado derivado quedo en parcial.');
} else {
    mal("el estado quedo en '{$estado}' y deberia ser parcial.");
}
$cot = $repo->buscarPorId($cuentaId, $idA);
$pend = (float) $cot['lineas'][0]['cantidad'] - (float) $cot['lineas'][0]['cantidad_facturada'];
if (abs($pend - 1.25) < 0.00005) {
    ok('el pendiente de esa linea es 1,25: el saldo lleva decimales de verdad.');
} else {
    mal("el pendiente salio {$pend} y deberia ser 1,25.");
}
// Y las OTRAS lineas no se movieron: el saldo es POR LINEA.
$otrasIntactas = true;
foreach (array_slice($cot['lineas'], 1) as $l) {
    if ((float) $l['cantidad_facturada'] !== 0.0) {
        $otrasIntactas = false;
    }
}
if ($otrasIntactas) {
    ok('las demas lineas siguen en 0: facturar una no toca a las otras.');
} else {
    mal('facturar una linea movio el saldo de otras.');
}

// (d) UNA LINEA SIN IDENTIFICADOR NO DESCUENTA NADA.
//     Se simula el caso real: en la factura parcial el usuario agrega una linea
//     a mano CON EL MISMO NOMBRE que una cotizada. Si el vinculo fuera por
//     nombre, esa linea consumiria saldo en silencio.
echo "\n  LINEA AGREGADA A MANO (sin id de cotizacion), con el MISMO nombre:\n";
$lineaLibre = ['cotizacion_linea_id' => null, 'nombre' => $primera['nombre'], 'cantidad' => 99];
$antes = array_map(static fn ($l) => (string) $l['cantidad_facturada'], $repo->lineasDe($idA));

// El descuento SOLO ocurre cuando hay id. Esta es la regla, escrita como la
// aplicara la segunda entrega.
if ($lineaLibre['cotizacion_linea_id'] !== null) {
    $pdo->prepare('UPDATE cotizacion_linea SET cantidad_facturada = cantidad_facturada + ? WHERE id = ?')
        ->execute([$lineaLibre['cantidad'], $lineaLibre['cotizacion_linea_id']]);
}
$despues = array_map(static fn ($l) => (string) $l['cantidad_facturada'], $repo->lineasDe($idA));
printf("      antes:   %s\n      despues: %s\n", implode(' | ', $antes), implode(' | ', $despues));
if ($antes === $despues) {
    ok('no descontó nada, aunque el nombre coincide exactamente. Es venta nueva.');
} else {
    mal('una linea sin identificador movio el saldo: el vinculo se esta haciendo por contenido.');
}

// (e) EL CHECK DE LA BASE: no se puede facturar mas de lo pendiente.
echo "\n  FACTURAR MAS DE LO PENDIENTE (2,5 de una linea de 2,5 que ya tiene 1,25):\n";
try {
    $pdo->prepare('UPDATE cotizacion_linea SET cantidad_facturada = cantidad_facturada + ? WHERE id = ?')
        ->execute([2.5, $primera['id']]);
    mal('LA BASE LO ACEPTO. El CHECK ck_cotizacion_linea_saldo no esta activo: '
        . 'comprueba la version de MySQL/MariaDB (8.0.16+ / 10.2+ lo aplican).');
} catch (PDOException $e) {
    ok('la base lo rechazo: ' . substr($e->getMessage(), 0, 60) . '...');
}

// ===========================================================================
// VERIFICACION 2 - NO SE PUEDE EDITAR CON FACTURACION PARCIAL
// ===========================================================================
titulo('VERIFICACION 2 - editar una cotizacion con facturacion parcial');

if ($repo->tieneFacturacion($cuentaId, $idA)) {
    ok('tieneFacturacion() ahora dice que si.');
} else {
    mal('tieneFacturacion() sigue diciendo que no despues de facturar 1,25.');
}

try {
    $repo->actualizar($cuentaId, $idA, $cabecera, lineasDePrueba(2));
    mal('SE PUDO EDITAR una cotizacion con facturacion parcial: el vinculo por id '
        . 'de las facturas emitidas quedaria roto.');
} catch (CotizacionFacturadaException $e) {
    ok('rechazado con CotizacionFacturadaException: ' . substr($e->getMessage(), 0, 60) . '...');
}

// Y las lineas SIGUEN AHI con sus id originales: el rollback funciono.
$idsDespues = array_map(static fn ($l) => (int) $l['id'], $repo->lineasDe($idA));
$idsAntes   = array_map(static fn ($l) => (int) $l['id'], $cot['lineas']);
if ($idsDespues === $idsAntes) {
    ok('los id de linea no cambiaron: el rollback de la transaccion funciono.');
} else {
    mal('los id de linea CAMBIARON pese al rechazo: ' . implode(',', $idsAntes)
        . ' -> ' . implode(',', $idsDespues));
}

// La que NO tiene facturacion si se edita.
[$idB] = $repo->crear($cuentaId, $cabecera, lineasDePrueba(2));
try {
    $repo->actualizar($cuentaId, $idB, $cabecera, lineasDePrueba(4));
    $n = count($repo->lineasDe($idB));
    if ($n === 4) {
        ok("una cotizacion sin facturacion SI se edita (quedo con {$n} lineas).");
    } else {
        mal("la edicion dejo {$n} lineas y se enviaron 4.");
    }
} catch (Throwable $e) {
    mal('no se pudo editar una cotizacion SIN facturacion: ' . $e->getMessage());
}

// ===========================================================================
// VERIFICACION 3 - DOS ALTAS SIMULTANEAS NO REPITEN CORRELATIVO
// ===========================================================================
titulo('VERIFICACION 3 - correlativo bajo concurrencia');

// DOS CONEXIONES DE VERDAD, no dos llamadas seguidas: el defecto que se busca
// (dos transacciones leyendo el mismo maximo) SOLO aparece con dos sesiones.
//
// LA SEGUNDA NO PUEDE SALIR DE Db::conexion(): esa clase cachea la conexion en
// una propiedad estatica y devolveria LA MISMA instancia, con lo que las dos
// "transacciones" serian una sola y la prueba pasaria sin probar nada. Asi que
// aqui se construye una segunda con el MISMO DSN que arma Db -- mismas
// variables, mismo default de puerto, mismo charset, mismos atributos.
$dsn2 = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST'),
    getenv('DB_PORT') ?: '3306',
    getenv('DB_NAME'),
);
$pdo2 = new PDO($dsn2, (string) getenv('DB_USER'), (string) getenv('DB_PASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
// Se suma a la lista de la limpieza: si el script muere aqui, esta sesion tiene
// una transaccion abierta con locks y el DELETE final se quedaria esperando.
$conexiones[] = $pdo2;

// Y se comprueba que las dos sean de verdad sesiones distintas antes de concluir
// nada: si el id de conexion coincidiera, el resultado no significaria nada.
$id1 = $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
$id2 = $pdo2->query('SELECT CONNECTION_ID()')->fetchColumn();
printf("  sesiones MySQL: %s y %s\n", $id1, $id2);
if ($id1 === $id2) {
    morir('las dos conexiones son la MISMA sesion: la prueba de concurrencia no probaria nada.');
}
ok('son dos sesiones distintas.');

$repo2 = new MySqlCotizacionRepository($pdo2);

// --- EL TIMEOUT DE LOCK ES LA COMPROBACION, NO UN ESTORBO ---
//
// EL BLOQUEO ES EL COMPORTAMIENTO CORRECTO: si la conexion 1 NO se quedara
// esperando, seria porque el FOR UPDATE no esta tomando el lock, y entonces dos
// altas simultaneas se llevarian el mismo numero. Asi que aqui no se evita el
// bloqueo: se PROVOCA y se mide.
//
// innodb_lock_wait_timeout SOLO EN ESTA SESION (SET SESSION, no GLOBAL): no
// toca el servidor ni a ningun otro cliente, y muere con el script. El default
// son 50 segundos, que fue lo que dejo el terminal pegado casi un minuto en la
// primera corrida. Con 3 basta para demostrar que espera.
const ESPERA_LOCK = 3;
foreach ([$pdo, $pdo2] as $c) {
    $c->exec('SET SESSION innodb_lock_wait_timeout = ' . ESPERA_LOCK);
}
printf("  innodb_lock_wait_timeout = %ds SOLO en estas dos sesiones.\n", ESPERA_LOCK);

// (a) La conexion 2 reserva y NO cierra: se queda con el lock tomado.
$pdo2->beginTransaction();
$n2 = $repo2->asignarNumero($cuentaId);
printf("  conexion 2 reservo el numero %d y NO ha hecho commit.\n", $n2);

// (b) La conexion 1 intenta reservar. TIENE QUE BLOQUEARSE.
$t0 = microtime(true);
$bloqueo = null;
try {
    $pdo->beginTransaction();
    $nColado = $repo->asignarNumero($cuentaId);
    $ms = (microtime(true) - $t0) * 1000;
    $pdo->rollBack();
    // NO se bloqueo: eso es el fallo.
    mal(sprintf('LA CONEXION 1 NO SE BLOQUEO: reservo el numero %d en %.0f ms mientras la '
        . 'conexion 2 tenia el lock. El FOR UPDATE no esta protegiendo el correlativo '
        . 'y dos altas simultaneas se llevarian el mismo numero%s.',
        $nColado, $ms, $nColado === $n2 ? ' -- de hecho se llevo EL MISMO' : ''));
} catch (PDOException $e) {
    $ms = (microtime(true) - $t0) * 1000;
    $bloqueo = $e->getMessage();
    if ($pdo->inTransaction()) {
        // El timeout aborta la SENTENCIA, no siempre la transaccion entera.
        $pdo->rollBack();
    }
    printf("  conexion 1 espero %.0f ms y no pudo entrar.\n", $ms);
    if (stripos($bloqueo, 'lock wait timeout') !== false) {
        ok('SE BLOQUEO, que es lo correcto: la segunda sesion espera en vez de colarse.');
    } else {
        mal('fallo por otra cosa, no por el lock: ' . substr($bloqueo, 0, 120));
    }
    // Y espero DE VERDAD, no fallo al instante por otro motivo.
    if ($ms >= (ESPERA_LOCK * 1000) * 0.8) {
        ok(sprintf('y espero %.1f s, coherente con el timeout de %ds de la sesion.', $ms / 1000, ESPERA_LOCK));
    } else {
        aviso(sprintf('espero solo %.0f ms para un timeout de %ds: revisa que el bloqueo '
            . 'sea el del correlativo y no otro.', $ms, ESPERA_LOCK));
    }
}

// (c) La conexion 2 cierra. Ahora la 1 tiene que obtener el numero SIGUIENTE.
$pdo2->commit();
$pdo->beginTransaction();
$n1 = $repo->asignarNumero($cuentaId);
$pdo->commit();

printf("  tras el commit de la 2, la conexion 1 obtuvo el numero %d.\n", $n1);
if ($n1 === $n2 + 1) {
    ok("es el SIGUIENTE ({$n2} -> {$n1}): ni repetido ni con hueco.");
} elseif ($n1 === $n2) {
    mal("obtuvo EL MISMO numero ({$n1}). El correlativo no esta protegido.");
} else {
    mal("obtuvo {$n1} y se esperaba " . ($n2 + 1) . ": la numeracion quedo con un hueco.");
}

// Y el UNIQUE es la ultima linea de defensa: se comprueba que exista de verdad.
$idx = $pdo->query("SHOW INDEX FROM cotizacion WHERE Key_name = 'uk_cotizacion_numero'")->fetchAll();
if ($idx !== []) {
    ok('uk_cotizacion_numero existe: aunque fallara el lock, la base rechazaria el duplicado.');
} else {
    mal('falta uk_cotizacion_numero en cotizacion.');
}

// Sin transaccion abierta tiene que negarse a reservar.
try {
    $repo->asignarNumero($cuentaId);
    mal('asignarNumero() reservo SIN transaccion abierta: el lock no valdria nada.');
} catch (RuntimeException $e) {
    ok('sin transaccion abierta se niega a reservar, con el motivo explicito.');
}

// ===========================================================================
// VERIFICACION 4 - EL PDF CON 3 LINEAS Y CON 40
// ===========================================================================
titulo('VERIFICACION 4 - el PDF: 3 lineas y 40 lineas');

$emisor = [
    'RUTEmisor'  => '77724622-4',
    'RznSoc'     => 'SOCIEDAD DE PROFESIONALES ROSAS Y VILLAR LIMITADA',
    'GiroEmis'   => 'SERVICIOS PROFESIONALES DE INGENIERIA Y CONSTRUCCION',
    'DirOrigen'  => 'AVENIDA PICARTE 1234, OFICINA 501',
    'CmnaOrigen' => 'VALDIVIA',
];

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

foreach ([3 => 1, 40 => 2] as $nLineas => $paginasMinimas) {
    echo "\n  --- {$nLineas} lineas ---\n";
    $cotPdf = $cabecera + ['numero' => 999, 'lineas' => []];
    try {
        $pdfBin = (new CotizacionPdfGenerator())->generar($emisor, $cotPdf, lineasDePrueba($nLineas), null);
    } catch (Throwable $e) {
        mal("no se pudo generar el PDF de {$nLineas} lineas: " . $e->getMessage());
        continue;
    }

    $paginas = preg_match_all('#/Type\s*/Page[^s]#', $pdfBin);
    printf("      bytes %d, paginas %d, memoria %s\n", strlen($pdfBin), $paginas, memoria());

    if ($paginas >= $paginasMinimas) {
        ok("ocupa {$paginas} pagina(s), como corresponde a {$nLineas} lineas.");
    } else {
        mal("ocupa {$paginas} pagina(s) y con {$nLineas} lineas se esperaban al menos {$paginasMinimas}: "
            . 'la tabla NO esta paginando y se estaria dibujando fuera de la hoja.');
    }

    $flujo = flujoDescomprimido($pdfBin);
    if ($flujo === '') {
        morir('el flujo salio VACIO. Fallo del arnes (descompresion), no de la entrega.');
    }

    // LO QUE NO PUEDE LLEVAR una cotizacion. Si apareciera cualquiera de estos,
    // se estaria imitando un documento tributario -- y confundir los dos ante un
    // cliente o ante el SII es un problema, no un detalle de estilo.
    // "R.U.T." NO esta en la lista: el RUT del emisor si va, en su cabecera.
    $prohibidos = ['ACUSE DE RECIBO', 'Timbre Electronico', 'CEDIBLE', 'Resolucion'];
    $encontrados = [];
    foreach ($prohibidos as $p) {
        if (stripos($flujo, $p) !== false) {
            $encontrados[] = $p;
        }
    }
    if ($encontrados === []) {
        ok('sin acuse de recibo, sin timbre y sin resolucion: no imita a un DTE.');
    } else {
        mal('el impreso trae elementos de DTE: ' . implode(', ', $encontrados));
    }

    unset($pdfBin, $flujo);
    gc_collect_cycles();
}

// ===========================================================================
// VERIFICACION 5 - NADA DEL CAMINO DE EMISION SE MOVIO
// ===========================================================================
titulo('VERIFICACION 5 - el camino de emision de DTE no se toco');

$pares = [
    'panel/public/index.php' => [__DIR__ . '/HEAD_panel_index.php', $RAIZ . '/panel/public/index.php'],
    'public/index.php'       => [__DIR__ . '/HEAD_public_index.php', $RAIZ . '/public/index.php'],
];
foreach ($pares as $etiqueta => [$rutaHead, $rutaWork]) {
    if (! is_file($rutaHead)) {
        aviso("falta {$rutaHead}: no se puede comparar {$etiqueta}. Lee la cabecera de este script.");
        continue;
    }
    $head = (string) file_get_contents($rutaHead);
    $work = (string) file_get_contents($rutaWork);

    if ($etiqueta === 'public/index.php') {
        // EL MOTOR NO SE TOCA EN ESTA ENTREGA. Una cotizacion no pasa por el.
        if ($head === $work) {
            ok('public/index.php: IDENTICO a HEAD. El motor no se toco.');
        } else {
            mal('public/index.php CAMBIO y una cotizacion no deberia tocar el motor.');
        }
        continue;
    }

    // En el panel SI hay cambios (los handlers nuevos). Lo que tiene que estar
    // intacto es la funcion que arma el documento que viaja al motor.
    foreach (['armarDocumentoEmision', 'handleEmisionPost', 'emitirEnMotor'] as $fn) {
        $a = extraerFuncion($head, $fn);
        $b = extraerFuncion($work, $fn);
        if ($a === null || $b === null) {
            mal("no se pudo extraer {$fn}() de una de las dos versiones.");
            continue;
        }
        if ($a === $b) {
            ok("{$fn}(): identica a HEAD, byte a byte.");
        } else {
            mal("{$fn}() CAMBIO. El camino de emision de DTE no estaba en el alcance.");
        }
    }
}

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

// ===========================================================================
// RESUMEN
// ===========================================================================
titulo('RESUMEN');
printf("  fallos: %d\n  avisos: %d\n", $fallos, $avisos);
printf("\n  MEMORIA\n    memory_limit : %s\n    pico real    : %.1f MB\n",
    ini_get('memory_limit'), memory_get_peak_usage(true) / 1048576);

echo "\n  LO QUE ESTE ARNES NO CUBRE:\n";
echo "    - El A/B por HTTP de la pantalla de emision: necesita el panel sirviendo\n";
echo "      con sesion. Aqui se compara el CODIGO de sus tres funciones, no el HTML.\n";
echo "    - PHPUnit: vendor/bin/phpunit --testdox\n";
echo "    - El aspecto del PDF. Se comprueba que pagine y que no imite a un DTE,\n";
echo "      no que se vea bien: eso lo mira Daniel con sus ojos.\n";

exit($fallos > 0 ? 1 : 0);
