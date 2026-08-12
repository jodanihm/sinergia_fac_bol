<?php

declare(strict_types=1);

/**
 * ARNES DE VERIFICACION: armar facturas conversando (capa 3).
 *
 * =============================================================================
 * COMO SE EJERCITA CODIGO QUE VIVE EN panel/public/index.php
 * =============================================================================
 *
 * Ese archivo es un front controller: incluirlo despacha una ruta. PERO el
 * despacho son bloques `if ($metodo === ... && $ruta === ...)`, y despues de
 * ellos el archivo sigue definiendo funciones. Si se le da una RUTA QUE NO
 * EXISTE, ningun bloque entra y el archivo termina de cargarse dejando todas sus
 * funciones definidas.
 *
 * ESO ES MEJOR QUE EXTRAER EL CODIGO CON UNA EXPRESION REGULAR, que es lo que
 * hicieron otros arneses de este proyecto: aqui se ejercita EL ARCHIVO REAL, no
 * una copia que puede quedar vieja sin que nadie se entere.
 *
 * Lo que NO se puede probar asi son los handlers completos (handleChatPost y
 * companía): terminan en vista(), que hace exit. Esos se miran por HTTP.
 *
 * =============================================================================
 * NINGUNA LLAMADA REAL A DEEPSEEK. Ni una. El traductor se inyecta con
 * MockHandler, igual que en verificar_chat_http.php.
 * =============================================================================
 *
 * COMO PREPARARLO
 *   1. Las de la base: DB_HOST, DB_NAME, DB_USER, DB_PASS (+ DB_PORT).
 *   2. Para la mitad HTTP: PANEL_URL, PANEL_USER, PANEL_PASS.
 *   3. Migraciones 039 y 040 aplicadas.
 *
 * NUNCA SE IMPRIME PANEL_PASS ni DEEPSEEK_API_KEY, ni completas ni parciales.
 *
 * ESTE ARNES ESCRIBE EN LA BASE: crea una cuenta de prueba con su cliente y sus
 * cotizaciones, y las deja. Son filas de una cuenta que no existe fuera de aqui.
 */

// Los `use` van ARRIBA DEL TODO, antes del require de index.php. Son de tiempo de
// compilacion y valen para el archivo entero, pero ponerlos despues de codigo
// ejecutable es una forma de escribir que nadie espera al leer.
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReaderArnes;
use Plantiflex\FacturacionCl\Dto\ArmadoFacturaTraducido;
use Plantiflex\FacturacionCl\Providers\DeepSeekTraductorArmadoFactura;
use Plantiflex\Integration\Facturacion\MySqlChatUsoRepository;

ini_set('memory_limit', '256M');

$RAIZ = dirname(__DIR__);

$fallos = 0;
$avisos = 0;

/** La cuenta de siembra, para que la limpieza la encuentre desde donde sea. */
$cuentaSembrada = null;

// =============================================================================
// BOOTSTRAP: COMO SE EJERCITA EL index.php REAL SIN COPIARLO NI TOCARLO
// =============================================================================
//
// TODO CAMINO DEL ROUTER TERMINA EN exit. No es solo la ruta inexistente: el
// archivo cierra su router con un catch-all incondicional (index.php:8614)
//
//     http_response_code(404); echo '404 - ruta no encontrada'; exit;
//
// y las rutas que SI existen terminan en vista() o en un redirect, que tambien
// hacen exit. O sea que no hay ninguna peticion, real o inventada, que deje el
// archivo cargado y devuelva el control. La primera version de este arnes usaba
// una ruta falsa y moria justo ahi.
//
// LO QUE SI SE PUEDE: exit no se puede atrapar, pero PHP ejecuta las funciones
// registradas con register_shutdown_function() DESPUES de el. Y en ese momento
// ya esta todo lo que hace falta:
//
//   - Las funciones de index.php estan DEFINIDAS. PHP declara las funciones de
//     nivel superior al compilar el archivo, antes de ejecutar su primera linea,
//     asi que existen incluso las que estan escritas DESPUES del catch-all.
//   - Las constantes declaradas ANTES del catch-all tambien existen -- y las del
//     chat lo estan, con mas de 6000 lineas de margen. Las de despues no; si
//     alguna hiciera falta, la comprobacion de mas abajo lo diria.
//   - La sesion esta activa y la base es alcanzable.
//
// POR ESO EL ARNES ENTERO VIVE DENTRO DE correrArnes(), que se registra ANTES de
// cargar el archivo. El 404 ocurre, y el arnes corre a continuacion.
//
// LAS DOS ALTERNATIVAS SE DESCARTARON, y no por gusto:
//   - Partir el router en "resolver" y "despachar" para poder llamar solo lo
//     primero: no existe esa separacion -- son bloques `if` con exit adentro --,
//     asi que habria que refactorizar el front controller de produccion para
//     acomodar un script de verificacion. Al reves de como se hacen las cosas.
//   - Entrar por una ruta real y cortar con un buffer al ver HTML: el handler ya
//     habria corrido, con sus consultas y sus escrituras. Un arnes no puede
//     ejecutar media pantalla de produccion para mirarla.
//
// -----------------------------------------------------------------------------
// Y NO SE REQUIERE NADA QUE index.php YA REQUIERA. Ese archivo hace `require` --
// NO `require_once` -- de Db, Auth, Rut, Csrf, FechaExcel e InformePdf
// (lineas 95-109). Pedirlos aqui tambien daba "Cannot declare class Db, because
// the name is already in use". La solucion no es cambiar esos require: el archivo
// se carga una vez por peticion y ahi funciona -- es este arnes el que llega por
// un camino que nadie previo.
//
// Y todo lo que toca cabeceras o sesion va ANTES del primer echo, que fue el otro
// aviso de la primera version.
// =============================================================================

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/__arnes_armado__';
$_SERVER['HTTP_HOST']      = 'arnes.local';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

register_shutdown_function('correrArnes');

// El 404 del catch-all se captura para que no se mezcle con el informe.
ob_start();
require $RAIZ . '/panel/public/index.php';

// INALCANZABLE: el require de arriba siempre termina en exit. Si alguna vez se
// llegara hasta aqui, el router habria dejado de cortar y correrArnes() se
// ejecutaria igual al terminar el script -- pero conviene saberlo.
echo "  [AVISO]   el router NO corto la ejecucion: index.php cambio de forma.\n";

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
    // LA LIMPIEZA VA ANTES DEL exit, TAMBIEN AL ABORTAR. Un arnes que se cae a
    // mitad es justo el que mas basura deja.
    limpiarSiembra();
    echo "\n*** ABORTADO: {$m}\n";
    exit(2);
}

/**
 * Borra la cuenta de prueba y todo lo que cuelga de ella.
 *
 * IDEMPOTENTE con un static: se la llama desde tres sitios -- al terminar bien,
 * al abortar, y como funcion de apagado por si un fatal se lleva el script -- y
 * ninguno sabe si otro ya paso.
 *
 * EL ORDEN ES EL DE LAS CLAVES FORANEAS. Todas las tablas de este esquema apuntan
 * a cuenta con ON DELETE RESTRICT, asi que la fila de cuenta se borra al final o
 * no se borra. Y las lineas de cotizacion se borran con un JOIN porque cuelgan de
 * la cotizacion, no de la cuenta.
 *
 * SE HACE UN rollBack DEFENSIVO: la verificacion 2 provoca a proposito una
 * transaccion que falla, y si alguna quedara abierta estos DELETE se irian con
 * ella sin borrar nada.
 */
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
        $pdo->prepare('DELETE l FROM cotizacion_linea l '
            . 'INNER JOIN cotizacion c ON c.id = l.cotizacion_id WHERE c.cuenta_id = ?')->execute([$cuentaId]);
        foreach (['cotizacion', 'cotizacion_correlativo', 'chat_consulta', 'chat_consulta_uso', 'cliente'] as $tabla) {
            $pdo->prepare("DELETE FROM {$tabla} WHERE cuenta_id = ?")->execute([$cuentaId]);
        }
        $pdo->prepare('DELETE FROM cuenta WHERE id = ?')->execute([$cuentaId]);
        echo "\n  LIMPIEZA: cuenta {$cuentaId} borrada, con su cliente y sus cotizaciones.\n";
    } catch (Throwable $e) {
        echo "\n  *** LA LIMPIEZA FALLO para la cuenta {$cuentaId}: " . $e->getMessage() . "\n";
        echo "      Es la unica fila que este arnes deja escrita; borrala a mano.\n";
    }
}

/**
 * TODO EL ARNES. Corre en el shutdown, despues del exit del router -- ver el
 * bloque BOOTSTRAP de arriba.
 */
function correrArnes(): void
{
    global $RAIZ, $fallos, $avisos;

// ===========================================================================
// CARGA DEL FRONT CONTROLLER
// ===========================================================================
// EL BUFFER SE CIERRA ANTES DE IMPRIMIR NADA, Y ESE ORDEN ES EL ARREGLO.
//
// La version anterior llamaba a titulo() primero, y como el buffer seguia
// abierto, el titulo se escribia DENTRO de el. Despues ob_get_clean() devolvia el
// 404 mas el titulo del propio arnes, la comparacion no calzaba, y el aviso decia
// "index.php imprimio algo distinto del 404" mostrando justo los primeros 200
// caracteres, que eran el 404 exacto. El mensaje se contradecia con lo que
// mostraba porque la diferencia estaba DESPUES del corte.
//
// Se comprueba que el contenido sea EXACTAMENTE el del catch-all y nada mas: si
// el archivo hubiera pintado una pantalla entera, significaria que despacho un
// handler de verdad y este arnes estaria corriendo sobre un estado que no
// controla.
$ruido = trim(ob_get_level() > 0 ? (string) ob_get_clean() : '');

titulo('CARGA: index.php quedo definido pese al exit del router');

if ($ruido === '404 - ruta no encontrada' || $ruido === '') {
    ok('el router corto en su catch-all, sin despachar ningun handler.');
} else {
    aviso('index.php imprimio algo distinto del 404 (primeros 200 caracteres): '
        . mb_substr($ruido, 0, 200));
}
printf("  sesion: %s\n", session_status() === PHP_SESSION_ACTIVE ? 'activa' : 'NO ACTIVA');

foreach (['chatPareceArmado', 'chatArmadoConfirmar', 'chatArmadoExcel', 'chatBorradorPendiente',
          'chatResolverClienteDelBorrador', 'chatDeclararFolios', 'vocabularioArmado'] as $f) {
    if (! function_exists($f)) {
        morir("no se cargo {$f}(): index.php cambio de forma. ARNES SIN CORRER.");
    }
}
ok('las funciones del chat estan definidas: PHP declara las de nivel superior al '
    . 'compilar, antes de ejecutar la primera linea.');

// LAS CONSTANTES SON EL RIESGO REAL DE ESTA TECNICA: a diferencia de las
// funciones, se declaran EJECUTANDO la linea, asi que solo existen las escritas
// antes del catch-all de la 8614. Las del chat lo estan, pero se comprueba en vez
// de suponerlo -- si alguna faltara, el arnes fallaria mas abajo con un error que
// no diria por que.
foreach (['CHAT_ARMADO_HABILITADO', 'CHAT_ARMADO_SESION', 'CHAT_ARMADO_PREFIJO_EXTERNO',
          'CHAT_ARMADO_FORMA_PAGO_DEFECTO', 'NOTA_VENTA_ENCABEZADOS', 'NOTA_VENTA_FORMAS_PAGO'] as $c) {
    if (! defined($c)) {
        morir("la constante {$c} no esta definida: quedo DESPUES del catch-all del router "
            . '(index.php:8614) y esta tecnica no llega hasta ella. ARNES SIN CORRER.');
    }
}
ok('las seis constantes que usa este arnes estan definidas: todas viven antes del catch-all.');
printf("  CHAT_ARMADO_HABILITADO = %s\n", CHAT_ARMADO_HABILITADO ? 'true' : 'false');
if (! CHAT_ARMADO_HABILITADO) {
    aviso('la puerta del armado esta CERRADA: el camino no es alcanzable en produccion todavia. '
        . 'Las verificaciones de abajo prueban las piezas igual, porque llaman a las funciones '
        . 'directamente.');
}

// ===========================================================================
// VERIFICACION 1 - EL RUTEO DEL PRIMER MENSAJE
//
// Es una funcion pura: sin base, sin red, sin sesion. Y es la que decide si se
// gasta una llamada de mas, asi que la tabla de frases va con casos reales.
// ===========================================================================
titulo('VERIFICACION 1 - chatPareceArmado() no manda las consultas al camino caro');

$casosRuteo = [
    // [frase, esperado armado?]
    ['¿cuantas facturas emiti en julio?',            false],
    ['cuanto facture en julio',                      false],
    ['cual fue mi mejor cliente',                    false],
    ['muestrame las facturas de agosto',             false],
    ['dame el detalle de facturacion de agosto',     false],
    ['lista las facturas del mes',                   false],
    ['que mes vendi mas',                            false],
    ['facturale a Perez el diseño y el hosting',     true],
    ['facturale 50000 a 76192083-9',                 true],
    ['emite una factura para Comercial Perez',       true],
    ['hazme una factura de 30000 al cliente nuevo',  true],
    ['cobrale el arriendo a Juan',                   true],
];

$erroresRuteo = 0;
foreach ($casosRuteo as [$frase, $esperado]) {
    $r = chatPareceArmado($frase);
    $marca = $r === $esperado ? ' ' : '!';
    printf("    %s %-48s -> %s (esperado %s)\n", $marca, mb_substr($frase, 0, 46),
        $r ? 'armado' : 'consulta', $esperado ? 'armado' : 'consulta');
    if ($r !== $esperado) {
        $erroresRuteo++;
    }
}
if ($erroresRuteo === 0) {
    ok('las 12 frases se rutean como corresponde.');
} else {
    // NO todos los errores cuestan lo mismo, y por eso se dice cual es cual.
    mal("{$erroresRuteo} de 12 frases mal ruteadas. Una CONSULTA mandada a armado cuesta una "
        . 'llamada de mas y se recupera sola (cambio_de_tema); un ARMADO mandado a consulta NO '
        . 'se recupera y el usuario recibe una respuesta que no pidio.');
}

// ===========================================================================
// SIEMBRA
// ===========================================================================
titulo('SIEMBRA: una cuenta propia de este arnes');

$pdo = Db::conexion();

// La MISMA forma de INSERT que usa el panel: cuenta.email es NOT NULL y sin
// default. Copiarla evita el "Field email doesn't have a default value" que ya
// aborto otro arnes de este proyecto.
$sufijo = bin2hex(random_bytes(4));
$pdo->prepare('INSERT INTO cuenta (email, nombre) VALUES (?, ?)')
    ->execute(["arnes-armado-{$sufijo}@ejemplo.cl", "Arnes armado {$sufijo}"]);
$cuentaId = (int) $pdo->lastInsertId();

// SE PUBLICA EL ID **INMEDIATAMENTE** DESPUES DE CREARLA, antes de cualquier otra
// cosa: a partir de esta linea, cualquier salida -- morir(), un fatal, el final
// normal -- encuentra la cuenta y la borra. Si se publicara mas abajo, un fallo
// entremedio dejaria la fila huerfana.
$GLOBALS['cuentaSembrada'] = $cuentaId;
register_shutdown_function('limpiarSiembra');

printf("  cuenta de prueba: %d\n", $cuentaId);

$limite = (new MySqlChatUsoRepository($pdo))->limiteDiario($cuentaId);
printf("  tope diario que hereda del DEFAULT de la migracion 040: %d\n", $limite);
if ($limite >= 60) {
    ok("una cuenta nueva nace con {$limite} consultas diarias: nadie queda peor que con las 30 de antes.");
} else {
    mal("una cuenta nueva nace con {$limite}, y el acuerdo era 60 como minimo.");
}

// ===========================================================================
// VERIFICACION 2 - LA TRANSACCION CREA CLIENTE + N COTIZACIONES + LINEAS
// ===========================================================================
titulo('VERIFICACION 2 - confirmar crea todo junto, y una cotizacion POR DOCUMENTO');

/** El estado tal como lo deja chatTurnoDeArmado() antes de confirmar. */
function estadoDePrueba(array $documentos, ?array $clienteExistente = null): array
{
    return [
        'turnos'   => ['facturale a mi cliente'],
        'borrador' => [
            'cliente' => [
                'rut'         => '76192083-9',
                'razonSocial' => 'CLIENTE DE ARNES SPA',
                'giro'        => 'SERVICIOS DE PRUEBA',
                'direccion'   => 'CALLE FALSA 123',
                'comuna'      => 'VALDIVIA',
            ],
            'formaPago'   => 'CONTADO',
            'documentos'  => $documentos,
        ],
        'cliente' => $clienteExistente,
        'listo'   => true,
    ];
}

$tresDocs = [
    ['item' => ['nombre' => 'Diseño web',  'cantidad' => 1, 'precioUnitario' => 250000, 'exento' => false]],
    ['item' => ['nombre' => 'Hosting',     'cantidad' => 1, 'precioUnitario' =>  60000, 'exento' => false]],
    ['item' => ['nombre' => 'Capacitacion','cantidad' => 2, 'precioUnitario' =>  40000, 'exento' => true]],
];

$antesCli = (int) $pdo->query("SELECT COUNT(*) FROM cliente WHERE cuenta_id = {$cuentaId}")->fetchColumn();
$antesCot = (int) $pdo->query("SELECT COUNT(*) FROM cotizacion WHERE cuenta_id = {$cuentaId}")->fetchColumn();

try {
    $r = chatArmadoConfirmar($pdo, $cuentaId, estadoDePrueba($tresDocs));
} catch (Throwable $e) {
    morir('confirmar lanzo: ' . $e->getMessage());
}

printf("  cotizaciones creadas: %s (N° %s)\n", implode(', ', $r['ids']), implode(', ', $r['numeros']));

$cli = (int) $pdo->query("SELECT COUNT(*) FROM cliente WHERE cuenta_id = {$cuentaId}")->fetchColumn();
$cot = (int) $pdo->query("SELECT COUNT(*) FROM cotizacion WHERE cuenta_id = {$cuentaId}")->fetchColumn();

if ($cli === $antesCli + 1 && $r['clienteCreado']) {
    ok('el cliente nuevo se dio de alta UNA vez.');
} else {
    mal("clientes: antes {$antesCli}, ahora {$cli}, clienteCreado=" . var_export($r['clienteCreado'], true));
}

// LO QUE IMPORTA DEL DISEÑO: tres documentos son TRES cotizaciones de una linea,
// no una de tres lineas. Con una sola, su boton "Facturar" produciria UNA factura
// de tres lineas, que es lo contrario de lo que el usuario pidio.
if ($cot === $antesCot + 3 && count($r['ids']) === 3) {
    ok('tres documentos produjeron TRES cotizaciones, no una con tres lineas.');
} else {
    mal("cotizaciones: antes {$antesCot}, ahora {$cot}, ids=" . count($r['ids']));
}

$lineasPorCot = [];
foreach ($r['ids'] as $id) {
    $lineasPorCot[$id] = (int) $pdo->query(
        "SELECT COUNT(*) FROM cotizacion_linea WHERE cotizacion_id = {$id}"
    )->fetchColumn();
}
printf("  lineas por cotizacion: %s\n", json_encode($lineasPorCot));
if (array_sum($lineasPorCot) === 3 && max($lineasPorCot) === 1) {
    ok('cada cotizacion tiene exactamente UNA linea.');
} else {
    mal('el reparto de lineas no es de una por cotizacion.');
}

// Los numeros son CORRELATIVOS REALES de la cuenta, no inventados.
$numerosOrdenados = $r['numeros'];
sort($numerosOrdenados);
if ($numerosOrdenados === range($numerosOrdenados[0], $numerosOrdenados[0] + 2)) {
    ok('los tres correlativos son consecutivos: salieron de cotizacion_correlativo.');
} else {
    mal('los correlativos no son consecutivos: ' . implode(', ', $r['numeros']));
}

// --- LA TRANSACCION: si la cotizacion falla, el cliente NO queda huerfano ---
//
// Se fuerza el fallo con un documento cuyo precio no es un numero valido para la
// columna... no: se fuerza con un RUT que YA existe, que hace chocar el UNIQUE
// del maestro DESPUES de haber empezado la transaccion.
$antesCli2 = (int) $pdo->query("SELECT COUNT(*) FROM cliente WHERE cuenta_id = {$cuentaId}")->fetchColumn();
$antesCot2 = (int) $pdo->query("SELECT COUNT(*) FROM cotizacion WHERE cuenta_id = {$cuentaId}")->fetchColumn();
try {
    // Mismo RUT que el ya creado y sin cliente resuelto: intenta darlo de alta y
    // choca contra uk_cliente_rut.
    chatArmadoConfirmar($pdo, $cuentaId, estadoDePrueba($tresDocs));
    mal('crear el mismo cliente dos veces NO lanzo: el UNIQUE del maestro no esta actuando.');
} catch (Throwable $e) {
    ok('el alta duplicada lanza: ' . mb_substr($e->getMessage(), 0, 70));
}
$despuesCli2 = (int) $pdo->query("SELECT COUNT(*) FROM cliente WHERE cuenta_id = {$cuentaId}")->fetchColumn();
$despuesCot2 = (int) $pdo->query("SELECT COUNT(*) FROM cotizacion WHERE cuenta_id = {$cuentaId}")->fetchColumn();
if ($despuesCli2 === $antesCli2 && $despuesCot2 === $antesCot2) {
    ok('y NO quedo nada a medias: ni cliente ni cotizacion. La transaccion abarca las dos cosas.');
} else {
    mal(sprintf('quedaron restos: clientes %d->%d, cotizaciones %d->%d.',
        $antesCli2, $despuesCli2, $antesCot2, $despuesCot2));
}
if (! $pdo->inTransaction()) {
    ok('la conexion quedo SIN transaccion abierta despues del fallo.');
} else {
    mal('quedo una transaccion abierta: el siguiente que use esta conexion hereda el problema.');
}

// ===========================================================================
// VERIFICACION 3 - EL EXCEL
// ===========================================================================
titulo('VERIFICACION 3 - el Excel que se genera lo acepta la carga masiva');

$bytes = chatArmadoExcel($cuentaId, $r['ids']);
printf("  bytes generados: %d\n", strlen($bytes));
if (strlen($bytes) < 1000) {
    morir('el archivo salio demasiado chico para ser un .xlsx.');
}

$tmp = tempnam(sys_get_temp_dir(), 'arnes_xlsx_');
file_put_contents($tmp, $bytes);
$hoja = (new XlsxReaderArnes())->load($tmp)->getActiveSheet();
$filas = $hoja->toArray(null, true, false, false);
unlink($tmp);

// LA COMPARACION ES CONTRA LA CONSTANTE, no contra una lista escrita aqui: es
// exactamente lo que hace leerFilasExcelCargaMasiva() con === estricto.
$encabezados = array_map(static fn ($v): string => trim((string) $v), array_slice($filas[0] ?? [], 0, count(NOTA_VENTA_ENCABEZADOS)));
if ($encabezados === NOTA_VENTA_ENCABEZADOS) {
    ok('los ' . count(NOTA_VENTA_ENCABEZADOS) . ' encabezados son IDENTICOS a NOTA_VENTA_ENCABEZADOS.');
} else {
    mal('los encabezados no coinciden. Generado: ' . implode('|', $encabezados));
}

$datos = array_slice($filas, 1);
printf("  filas de datos: %d\n", count($datos));
if (count($datos) === 3) {
    ok('una fila por documento: tres facturas, tres filas.');
} else {
    mal('se esperaban 3 filas de datos.');
}

$externos = array_column($datos, 0);
printf("  identificador_externo: %s\n", implode(', ', $externos));
$patron = '/^' . preg_quote(CHAT_ARMADO_PREFIJO_EXTERNO, '/') . '-\d+-\d+$/';
$malos  = array_filter($externos, static fn ($v): bool => preg_match($patron, (string) $v) !== 1);
if ($malos === []) {
    ok('todos siguen el patron ' . CHAT_ARMADO_PREFIJO_EXTERNO . '-<cotizacionId>-<orden>.');
} else {
    mal('identificadores fuera de patron: ' . implode(', ', $malos));
}
if (count(array_unique($externos)) === count($externos)) {
    ok('y son distintos entre si: el UNIQUE de nota_venta no los va a rechazar.');
} else {
    mal('hay identificadores repetidos: la carga rechazaria las filas.');
}

// LA COLUMNA 13 (indice 12) ES forma_pago. Vacia NO aparta la fila: RECHAZA EL
// ARCHIVO ENTERO. Es la comprobacion que mas barato sale y mas caro costaria.
$formas = array_column($datos, 12);
if (array_filter($formas, static fn ($v): bool => trim((string) $v) === '') === []) {
    ok('forma_pago viene en TODAS las filas (' . implode('/', array_unique($formas)) . '): '
        . 'el archivo no se rechaza entero por eso.');
} else {
    mal('hay filas con forma_pago vacia: la carga masiva rechazaria el archivo completo.');
}

// Y la columna 12 (indice 11) es exento: SI/NO, nunca 1/0 ni true/false.
$exentos = array_unique(array_column($datos, 11));
printf("  valores de la columna exento: %s\n", implode(', ', $exentos));
if (array_diff($exentos, ['SI', 'NO']) === []) {
    ok("exento usa SI/NO, que es lo unico que valida validarFilaCargaMasiva().");
} else {
    mal('exento trae valores que la carga masiva no acepta.');
}

// ===========================================================================
// VERIFICACION 4 - EL AVISO PERSISTENTE
// ===========================================================================
titulo('VERIFICACION 4 - el aviso de borrador a medias aparece y desaparece');

$_SESSION[CHAT_ARMADO_SESION] = [];
if (chatBorradorPendiente() === null) {
    ok('sin conversaciones, no hay aviso.');
} else {
    mal('sale aviso con la sesion vacia.');
}

$idConv = chatConversacionRegistrar(chatConversacionNueva());
if (chatBorradorPendiente() === null) {
    ok('una pestaña abierta que no armo nada TAMPOCO produce aviso.');
} else {
    mal('una conversacion vacia produce aviso: molestaria a quien solo consulta.');
}

chatArmadoGuardar($idConv, estadoDePrueba($tresDocs));
$p = chatBorradorPendiente();
if ($p !== null && $p['id'] === $idConv && $p['listo'] === true && $p['documentos'] === 3) {
    ok('con un borrador listo de 3 documentos, el aviso lo dice: ' . json_encode($p));
} else {
    mal('el aviso no refleja el borrador: ' . json_encode($p));
}

chatArmadoOlvidar($idConv);
if (chatBorradorPendiente() === null) {
    ok('al descartar, el aviso desaparece.');
} else {
    mal('el aviso sobrevive al descarte.');
}

// ===========================================================================
// VERIFICACION 5 - LA RESOLUCION DEL CLIENTE
// ===========================================================================
titulo('VERIFICACION 5 - resolucion y desambiguacion de cliente');

$r1 = chatResolverClienteDelBorrador($cuentaId, ['cliente' => ['rut' => '76192083-9']]);
printf("  por RUT existente -> %s\n", $r1['estado']);
if ($r1['estado'] === 'listo') {
    ok('un RUT que existe y esta completo resuelve directo, sin preguntar nada.');
} else {
    mal('un cliente completo deberia resolver: ' . (string) $r1['texto']);
}

// OJO CON EL DATO DE PRUEBA. La version anterior usaba 11111111-1 y reportaba un
// fallo que no existia: ESE RUT ES VALIDO. Modulo 11 sobre 11111111 da suma 32,
// resto 11-(32%11)=1, o sea DV 1. Rut::valido() lo aceptaba con razon y
// resolverClientePorRut() devolvia 'no_encontrado', que es la respuesta correcta.
//
// Los dos casos que SI son invalidos, y que son los que hay que distinguir de
// "no existe": un DV que no calza, y algo que ni siquiera tiene forma de RUT.
foreach ([
    ['76192083-1', 'DV que no calza (el de 76192083 es 9)'],
    ['no-es-un-rut', 'ni siquiera tiene forma de RUT'],
] as [$rutMalo, $porque]) {
    $r2 = chatResolverClienteDelBorrador($cuentaId, ['cliente' => ['rut' => $rutMalo]]);
    printf("      %-14s -> %s | %s\n", $rutMalo, $r2['estado'], mb_substr((string) $r2['texto'], 0, 46));
    if ($r2['estado'] === 'preguntar' && str_contains((string) $r2['texto'], 'no es valido')) {
        ok("un RUT con {$porque} se rechaza NOMBRANDOLO, y no se ofrece darlo de alta.");
    } else {
        mal("'{$rutMalo}' ({$porque}) no se detecto como invalido: " . json_encode($r2));
    }
}

// LA DISTINCION QUE IMPORTA, comprobada de frente: 'rut_invalido' y
// 'no_encontrado' NO pueden terminar en el mismo mensaje. Ofrecerle dar de alta
// un RUT mal escrito lo metería en el maestro tal como esta escrito.
$rBien = chatResolverClienteDelBorrador($cuentaId, ['cliente' => ['rut' => '99999999-9']]);
$rMal  = chatResolverClienteDelBorrador($cuentaId, ['cliente' => ['rut' => '76192083-1']]);
if ($rBien['estado'] === 'preguntar' && str_contains((string) $rBien['texto'], 'no esta en tus clientes')
    && ! str_contains((string) $rMal['texto'], 'no esta en tus clientes')) {
    ok('un RUT bien formado que no existe ofrece el alta; uno mal escrito NO. Son dos caminos.');
} else {
    mal('los dos casos dan el mismo mensaje: valido="' . mb_substr((string) $rBien['texto'], 0, 40)
        . '" invalido="' . mb_substr((string) $rMal['texto'], 0, 40) . '"');
}

$r3 = chatResolverClienteDelBorrador($cuentaId, ['cliente' => ['nombre' => 'NO EXISTE ESTE NOMBRE XYZ']]);
if ($r3['estado'] === 'preguntar' && str_contains((string) $r3['texto'], 'No encontre')) {
    ok('un nombre sin coincidencias pide el RUT para dar de alta.');
} else {
    mal('la busqueda sin resultados no responde lo esperado: ' . json_encode($r3));
}

$r4 = chatResolverClienteDelBorrador($cuentaId, ['cliente' => ['nombre' => 'ARNES']]);
printf("  por nombre con un solo candidato -> %s\n", $r4['estado']);
if ($r4['estado'] === 'listo' && str_contains((string) $r4['texto'], '76192083-9')) {
    ok('un solo candidato resuelve, PERO diciendo a quien: ' . mb_substr((string) $r4['texto'], 0, 60));
} else {
    mal('el candidato unico no se confirmo en voz alta: ' . json_encode($r4));
}

// Un cliente INCOMPLETO no puede armar: la carga masiva ya lo trata como error de
// fila porque el lote del motor es todo-o-nada.
$pdo->prepare('INSERT INTO cliente (cuenta_id, rut_cliente, razon_social, giro) VALUES (?, ?, ?, ?)')
    ->execute([$cuentaId, '77724622-4', 'INCOMPLETO SPA', '']);
$r5 = chatResolverClienteDelBorrador($cuentaId, ['cliente' => ['rut' => '77724622-4']]);
if ($r5['estado'] === 'preguntar' && str_contains((string) $r5['texto'], 'falta')) {
    ok('un cliente sin giro/direccion/comuna FRENA el armado y dice que falta.');
} else {
    mal('el cliente incompleto paso: el borrador moriria al emitir. ' . json_encode($r5));
}

// ===========================================================================
// VERIFICACION 6 - EL TRADUCTOR DE ARMADO, SIN GASTAR NADA
// ===========================================================================
titulo('VERIFICACION 6 - los cuatro desenlaces del traductor de armado');

function traductorArmadoFalso(array $respuestas, string $clave, array &$historial): DeepSeekTraductorArmadoFactura
{
    $stack = HandlerStack::create(new MockHandler($respuestas));
    $stack->push(Middleware::history($historial));

    return new DeepSeekTraductorArmadoFactura(new Client(['handler' => $stack, 'http_errors' => false]), $clave);
}
function sobreArmado(string $contenido): Response
{
    return new Response(200, [], (string) json_encode([
        'choices' => [['message' => ['content' => $contenido]]],
    ]));
}

$casosDesenlace = [
    ['faltan_datos',   '{"desenlace":"faltan_datos","pregunta":"¿que precio?","borrador":{"cliente":{"rut":"1-9"}}}', ArmadoFacturaTraducido::FALTAN_DATOS],
    ['borrador_listo', '{"desenlace":"borrador_listo","borrador":{"documentos":[{"item":{"nombre":"x","cantidad":1,"precioUnitario":100}}]}}', ArmadoFacturaTraducido::BORRADOR_LISTO],
    ['cambio_de_tema', '{"desenlace":"cambio_de_tema"}', ArmadoFacturaTraducido::CAMBIO_DE_TEMA],
    ['no_entendida',   '{"desenlace":"no_entendida","motivo":"no se"}', ArmadoFacturaTraducido::NO_ENTENDIDA],
];
foreach ($casosDesenlace as [$nombre, $json, $esperado]) {
    $h = [];
    $t = traductorArmadoFalso([sobreArmado($json)], 'clave-falsa', $h);
    $res = $t->traducir(['algo'], [], vocabularioArmado(), '2026-08-12');
    printf("      %-16s -> %s\n", $nombre, $res->desenlace);
    if ($res->desenlace === $esperado) {
        ok("desenlace '{$nombre}' reconocido.");
    } else {
        mal("desenlace '{$nombre}' salio como '{$res->desenlace}'.");
    }
}

// Un desenlace inventado NO puede caer en "no entendida": tiene que verse.
$h = [];
$t = traductorArmadoFalso([sobreArmado('{"desenlace":"inventado"}')], 'clave-falsa', $h);
try {
    $t->traducir(['algo'], [], vocabularioArmado(), '2026-08-12');
    mal('un desenlace desconocido NO lanzo: se estaria tapando una alucinacion del modelo.');
} catch (Throwable $e) {
    ok('un desenlace desconocido lanza: ' . mb_substr($e->getMessage(), 0, 60));
}

// SIN CLAVE NO SE HACE NI UNA PETICION.
$h = [];
$t = traductorArmadoFalso([], '', $h);
try {
    $t->traducir(['algo'], [], vocabularioArmado(), '2026-08-12');
    mal('sin clave no lanzo.');
} catch (Throwable $e) {
    ok('sin clave lanza con un mensaje propio del armado: ' . mb_substr($e->getMessage(), 0, 60));
}
if ($h === []) {
    ok('y NO hizo ninguna peticion: sin clave no se gasta nada.');
} else {
    mal('sin clave igual llamo al proveedor.');
}

// LO QUE VIAJA AL MODELO: solo las frases del usuario. Se mira el cuerpo real.
$h = [];
$t = traductorArmadoFalso([sobreArmado('{"desenlace":"cambio_de_tema"}')], 'clave-falsa', $h);
$t->traducir(['facturale a Perez'], ['cliente' => ['rut' => '76192083-9']], vocabularioArmado(), '2026-08-12');
$cuerpo = (string) $h[0]['request']->getBody();
if (! str_contains($cuerpo, 'CLIENTE DE ARNES SPA') && ! str_contains($cuerpo, (string) $cuentaId)) {
    ok('en el cuerpo enviado NO aparece ni la razon social del maestro ni el cuenta_id.');
} else {
    mal('viajo informacion del tenant al proveedor. ESTO ROMPE LA REGLA DE PRIVACIDAD.');
}
if (str_contains($cuerpo, 'facturale a Perez')) {
    ok('y si viaja la frase del usuario, que es lo unico que debe viajar.');
} else {
    mal('no viajo ni la frase del usuario: el traductor no esta mandando el turno.');
}

// ===========================================================================
// VERIFICACION 7 - POR HTTP
// ===========================================================================
titulo('VERIFICACION 7 - el camino por HTTP');

$panel = rtrim((string) getenv('PANEL_URL'), '/');
$user  = (string) getenv('PANEL_USER');
$pass  = (string) getenv('PANEL_PASS');

if ($panel === '' || $user === '' || $pass === '') {
    aviso('faltan PANEL_URL / PANEL_USER / PANEL_PASS: la mitad HTTP NO SE CORRIO. '
        . 'No se declara verde lo que no se probo.');
} else {
    $cookies = tempnam(sys_get_temp_dir(), 'arnes_ck_');

    $pedir = static function (string $metodo, string $url, array $campos = []) use ($cookies): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR      => $cookies,
            CURLOPT_COOKIEFILE     => $cookies,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        if ($metodo === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($campos));
        }
        $cuerpo = (string) curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['codigo' => $codigo, 'cuerpo' => $cuerpo];
    };
    $token = static function (string $html): string {
        return preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
    };

    $login = $pedir('GET', $panel . '/login');
    $res   = $pedir('POST', $panel . '/login', [
        'csrf_token' => $token($login['cuerpo']), 'email' => $user, 'password' => $pass,
    ]);
    if ($res['codigo'] !== 200) {
        aviso('no se pudo entrar al panel (HTTP ' . $res['codigo'] . '). La mitad HTTP NO SE CORRIO.');
    } else {
        $chat = $pedir('GET', $panel . '/chat');
        if ($chat['codigo'] === 200) {
            ok('GET /chat responde 200.');
        } else {
            mal('GET /chat respondio ' . $chat['codigo']);
        }

        // EL HIDDEN DE LA CONVERSACION: sin el, dos turnos no se pueden atar y el
        // armado no existe. Se comprueba ademas su FORMA, que es la que
        // chatConversacionResolver() exige para no tratarlo como basura.
        if (preg_match('/name="conversacion_id"\s+value="([0-9a-f]{32})"/', $chat['cuerpo'], $mC) === 1) {
            ok('el formulario trae conversacion_id con los 32 hex que espera el resolvedor.');
        } else {
            mal('falta conversacion_id en el formulario, o no tiene la forma esperada.');
        }

        // DOS GET SEGUIDOS DAN DOS IDENTIFICADORES DISTINTOS: es lo que separa
        // dos pestañas.
        $chat2 = $pedir('GET', $panel . '/chat');
        preg_match('/name="conversacion_id"\s+value="([0-9a-f]{32})"/', $chat2['cuerpo'], $mC2);
        if (($mC[1] ?? 'a') !== ($mC2[1] ?? 'b')) {
            ok('dos cargas de la pantalla dan identificadores distintos: dos pestañas no se mezclan.');
        } else {
            mal('dos cargas dieron el MISMO identificador: dos pestañas compartirian conversacion.');
        }

        // CONFIRMAR ALGO QUE NO EXISTE no puede reventar ni crear nada.
        $conf = $pedir('POST', $panel . '/chat/confirmar', [
            'csrf_token'      => $token($chat['cuerpo']),
            'conversacion_id' => str_repeat('a', 32),
        ]);
        if ($conf['codigo'] === 200 && str_contains($conf['cuerpo'], 'ya no esta disponible')) {
            ok('confirmar un borrador inexistente responde con un mensaje, no con un error 500.');
        } else {
            mal('POST /chat/confirmar con id inventado respondio ' . $conf['codigo']);
        }

        // Y EL CHAT DE CONSULTAS SIGUE FUNCIONANDO: una pregunta normal no puede
        // haberse ido al camino de armado.
        $consulta = $pedir('POST', $panel . '/chat', [
            'csrf_token' => $token($chat['cuerpo']),
            'pregunta'   => 'cuanto vendi en julio',
        ]);
        if ($consulta['codigo'] === 200) {
            ok('una consulta normal sigue respondiendo 200 (regresion del camino viejo).');
        } else {
            mal('la consulta de siempre respondio ' . $consulta['codigo']);
        }
    }
    @unlink($cookies);
}

// ===========================================================================
titulo('RESUMEN');
printf("  fallos: %d\n  avisos: %d\n", $fallos, $avisos);
printf("\n  MEMORIA: pico %.1f MB (limite %s)\n",
    memory_get_peak_usage(true) / 1048576, ini_get('memory_limit'));

echo "\n  LO QUE ESTE ARNES NO CUBRE:\n";
echo "    - handleChatPost() de punta a punta: termina en vista(), que hace exit.\n";
echo "      Lo que se prueba son sus piezas y el camino por HTTP.\n";
echo "    - El redirect a /ventas/cotizaciones/{id}/facturar: se comprueba que la\n";
echo "      cotizacion existe, no que la pantalla la pinte.\n";
echo "    - Una conversacion real con el modelo. Ni una llamada a DeepSeek.\n";
echo "    - Las constantes y el codigo que index.php declara DESPUES de su\n";
echo "      catch-all (linea 8614): esta tecnica no los alcanza.\n";

// LA LIMPIEZA VA ANTES DEL exit Y NO DESPUES. exit() dentro de una funcion de
// apagado termina el proceso en el acto: las funciones de apagado que quedaran
// registradas NO se ejecutan, y la que borra la cuenta es una de ellas.
limpiarSiembra();

// El codigo de salida se fija desde el shutdown. Si el interprete lo ignorara en
// esta fase, el conteo de fallos de arriba sigue siendo la respuesta -- por eso
// se imprime SIEMPRE y no solo cuando hay fallos.
exit($fallos > 0 ? 1 : 0);
}
