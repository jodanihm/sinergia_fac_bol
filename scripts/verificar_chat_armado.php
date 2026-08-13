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

// EL CONTADOR NO SE LLAMA $avisos, Y ES DELIBERADO.
//
// "aviso" es hoy una palabra del DOMINIO -- avisosPanel, chatAvisosDelPanel(),
// el aviso que el panel le manda al modelo --, asi que una variable con ese
// nombre en cualquier bloque de este arnes tiene muchas papeletas de existir.
// Y como correrArnes() declara `global $avisos` para el resumen, esa variable
// "local" NO seria local: pisaria el contador. Ya paso -- el arnes murio con
// "Cannot increment array" en la verificacion 8, a mil lineas de donde estaba
// la causa.
//
// Renombrarlo es mas barato que acordarse de no usar la palabra.
$avisosDelArnes = 0;

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
    global $avisosDelArnes;
    $avisosDelArnes++;
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

// =============================================================================
// AYUDANTES DE LAS VERIFICACIONES -- AL NIVEL DEL ARCHIVO, Y NO DENTRO DE
// correrArnes(). ESTO NO ES ESTILO: ES LO QUE HACE QUE EXISTAN.
//
// PHP declara las funciones de nivel superior AL COMPILAR, antes de ejecutar la
// primera linea, y por eso se pueden usar mas arriba de donde estan escritas.
// Las declaradas DENTRO de otra funcion no: existen recien cuando esa linea se
// ejecuta.
//
// Como todo el arnes vive dentro de correrArnes() -- hace falta para correr en el
// shutdown, ver el BOOTSTRAP --, estas tres estaban quedando "declaradas" en
// mitad del recorrido, y una verificacion que las usara ANTES moria con
// "Call to undefined function". Paso exactamente eso con traductorArmadoFalso()
// al agregar los tres turnos de Daniel a la verificacion 5: la funcion se
// declaraba en la 6.
//
// Sacandolas aqui, el orden de las verificaciones deja de importar.
// =============================================================================

/** Un traductor de armado con respuestas prefabricadas, y su historial de peticiones. */
function traductorArmadoFalso(array $respuestas, string $clave, array &$historial): DeepSeekTraductorArmadoFactura
{
    $stack = HandlerStack::create(new MockHandler($respuestas));
    $stack->push(Middleware::history($historial));

    return new DeepSeekTraductorArmadoFactura(new Client(['handler' => $stack, 'http_errors' => false]), $clave);
}

/** El sobre que devuelve DeepSeek, con el JSON del modelo dentro. */
function sobreArmado(string $contenido): Response
{
    return new Response(200, [], (string) json_encode([
        'choices' => [['message' => ['content' => $contenido]]],
    ]));
}

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

/**
 * TODO EL ARNES. Corre en el shutdown, despues del exit del router -- ver el
 * bloque BOOTSTRAP de arriba.
 */
function correrArnes(): void
{
    global $RAIZ, $fallos, $avisosDelArnes;

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

    // --- LA FRASE REAL DE PRODUCCION, LITERAL ---------------------------------
    // La escribio Daniel el 12-08-2026 y el chat le contesto con el mensaje del
    // camino de consultas. Se guarda TAL CUAL, con su ortografia y todo: una
    // variante "parecida" no habria servido para reproducirlo, que fue
    // exactamente lo que paso con las 12 de arriba.
    ['quiero que me hagas una factura excenta para el cliente plantiflex por 1300 pesos', true],

    // Variantes con los errores de tipeo que aparecen de verdad. Ninguna toca la
    // raiz 'factur', que es lo unico que mira la regla -- por eso todas pasan, y
    // por eso este bloque demuestra que la ortografia NO era la causa.
    ['quiero que me hagas una factura exenta para plantiflex por 1300 pesos',  true],
    ['kiero una factura para plantiflex de 1300',                              true],
    ['necesito facturarle 1300 a plantiflex',                                  true],
    ['hazme una fatura para plantiflex',                                       false],
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
$totalRuteo = count($casosRuteo);
if ($erroresRuteo === 0) {
    ok("las {$totalRuteo} frases se rutean como corresponde, incluida la de produccion.");
} else {
    // NO todos los errores cuestan lo mismo, y por eso se dice cual es cual.
    mal("{$erroresRuteo} de {$totalRuteo} frases mal ruteadas. Una CONSULTA mandada a armado cuesta "
        . 'una llamada de mas y se recupera sola (cambio_de_tema); un ARMADO mandado a consulta NO '
        . 'se recupera y el usuario recibe una respuesta que no pidio.');
}

// --- HUECOS CONOCIDOS DE LA HEURISTICA -------------------------------------
//
// NO SON FALLOS: son el precio declarado de una regla de tres lineas, y salieron
// a la luz al trazar la frase de produccion. Se imprimen como AVISO para que
// esten a la vista y para que, si algun dia se decide taparlos, exista el sitio
// donde comprobarlo. Hoy NO se tapan: cambiar la regla sin decidirlo antes es
// como se rompen las cosas que funcionan.
echo "\n  HUECOS CONOCIDOS (no son fallos; se miden para tenerlos a la vista)\n";
$frontera = [
    ['que me hagas una factura para plantiflex por 1300',
     'abre con "que", que esta en la lista de interrogativas'],
    ['¿me puedes hacer una factura de 1300 a plantiflex?',
     'lleva signos de interrogacion, y un pedido en forma de pregunta es un pedido'],
    ['quiero ver mi ultima factura',
     'menciona "factur" sin pedir emitir: se va a armado y vuelve por cambio_de_tema'],
];
foreach ($frontera as [$frase, $porque]) {
    printf("      %-52s -> %s\n", mb_substr($frase, 0, 50), chatPareceArmado($frase) ? 'armado' : 'consulta');
    printf("        %s\n", $porque);
}
aviso('los tres casos de arriba estan MEDIDOS, no arreglados. Los dos primeros son falsos '
    . 'negativos (un pedido tratado como consulta, que NO se recupera solo); el tercero es un '
    . 'falso positivo, que cuesta una llamada y se recupera.');

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

// --- EL DETALLE FABRICADO (13-08-2026) -------------------------------------
//
// Se llego al formulario de emision con el cliente completo y SIN detalle, y la
// conversacion nunca pregunto por el. Tres capas fallaban abiertas: el traductor
// solo miraba que el borrador no fuera vacio, chatDocumentosDelBorrador() contaba
// elementos sin mirar adentro, y chatArmadoConfirmar() rellenaba lo que faltara
// con 'Servicio', 1 y 0 -- o sea que INVENTABA un item y lo escribia en la base.
echo "\n  EL DETALLE FABRICADO: un documento sin item no puede pasar\n";

$degenerados = [
    'documento vacio {}'            => [[]],
    'item vacio {"item":{}}'        => [['item' => []]],
    'solo nombre, sin cantidad ni precio' => [['item' => ['nombre' => 'Arriendo de software']]],
    'nombre y cantidad, sin precio' => [['item' => ['nombre' => 'Arriendo', 'cantidad' => 1]]],
    'cantidad cero'                 => [['item' => ['nombre' => 'Arriendo', 'cantidad' => 0, 'precioUnitario' => 1300]]],
];
foreach ($degenerados as $queEs => $docs) {
    $falta = chatFaltaDelDocumento(is_array($docs[0]) ? $docs[0] : [], 0, 1);
    printf("      %-38s -> %s\n", $queEs, $falta === null ? 'PASA (mal)' : mb_substr($falta, 0, 44));
    if ($falta !== null) {
        ok("'{$queEs}' se detecta como incompleto y el chat pregunta por lo que falta.");
    } else {
        mal("'{$queEs}' se dio por completo: puede llegar a la base como un item inventado.");
    }
}

// Y UNO COMPLETO TIENE QUE PASAR, o la validacion seria un muro y no un filtro.
$completo = ['item' => ['nombre' => 'Arriendo de software', 'cantidad' => 1, 'precioUnitario' => 1300]];
if (chatFaltaDelDocumento($completo, 0, 1) === null) {
    ok('un documento con nombre, cantidad y precio SI pasa.');
} else {
    mal('un documento completo se rechaza: la validacion bloquea el camino bueno.');
}

// EL PRECIO CERO SE ACEPTA A PROPOSITO: 'SIN COSTO' es forma de pago valida del
// SII. Lo que no puede es FALTAR y aparecer como cero sin que nadie lo dijera.
$gratis = ['item' => ['nombre' => 'Muestra sin costo', 'cantidad' => 1, 'precioUnitario' => 0]];
if (chatFaltaDelDocumento($gratis, 0, 1) === null) {
    ok('un precio 0 EXPLICITO se acepta: no se decide por el usuario que no puede regalar algo.');
} else {
    mal('se rechaza un precio 0 declarado: eso es decidir por el usuario.');
}

// LA ULTIMA RED: confirmar con un documento incompleto LANZA y no escribe nada.
$antesCliD = (int) $pdo->query("SELECT COUNT(*) FROM cliente WHERE cuenta_id = {$cuentaId}")->fetchColumn();
$antesCotD = (int) $pdo->query("SELECT COUNT(*) FROM cotizacion WHERE cuenta_id = {$cuentaId}")->fetchColumn();
try {
    chatArmadoConfirmar($pdo, $cuentaId, estadoDePrueba([['item' => ['nombre' => 'Arriendo']]]));
    mal('confirmar con un documento SIN cantidad ni precio NO lanzo: se escribio un item inventado.');
} catch (Throwable $e) {
    ok('confirmar un documento incompleto lanza: ' . mb_substr($e->getMessage(), 0, 80));
}
$despuesCliD = (int) $pdo->query("SELECT COUNT(*) FROM cliente WHERE cuenta_id = {$cuentaId}")->fetchColumn();
$despuesCotD = (int) $pdo->query("SELECT COUNT(*) FROM cotizacion WHERE cuenta_id = {$cuentaId}")->fetchColumn();
if ($despuesCliD === $antesCliD && $despuesCotD === $antesCotD) {
    ok('y no escribio NADA: la validacion corre antes de abrir la transaccion.');
} else {
    mal(sprintf('quedaron restos: clientes %d->%d, cotizaciones %d->%d.',
        $antesCliD, $despuesCliD, $antesCotD, $despuesCotD));
}

// --- EL PRECIO QUE SE PREGUNTABA TRES VECES (13-08-2026) -------------------
//
// Daniel dio el precio del segundo item de tres formas distintas en tres turnos y
// recibio SIEMPRE el mismo mensaje. La pregunta la hacia el panel y salia solo al
// hilo, que no viaja: el modelo nunca supo que su documento estaba incompleto.
// Es el bucle de "Plantiflex" otra vez, ahora en el detalle.
echo "\n  EL PRECIO QUE SE PREGUNTABA TRES VECES: el aviso del item viaja al modelo\n";

// TURNO 1: dos documentos, el segundo sin precio -- la forma exacta del caso.
$dosDocs = [
    ['item' => ['nombre' => 'Arriendo de software', 'cantidad' => 1, 'precioUnitario' => 80000]],
    ['item' => ['nombre' => 'Proceso de certificacion ante SII', 'cantidad' => 1]],
];
$faltas = [];
foreach ($dosDocs as $i => $doc) {
    $f = chatFaltaDelDocumento($doc, $i, count($dosDocs));
    if ($f !== null) {
        $faltas[$i] = $f;
    }
}
printf("      documentos con algo pendiente: %s\n", implode(', ', array_keys($faltas)) ?: 'ninguno');
if (array_keys($faltas) === [1] && str_contains($faltas[1], 'factura 2')) {
    ok('solo el segundo documento queda pendiente, y la pregunta lo NOMBRA: '
        . mb_substr($faltas[1], 0, 60));
} else {
    mal('la validacion no aisla el documento incompleto: ' . json_encode($faltas));
}

// EL CABLEADO: esa pregunta tiene que entrar al canal que va al modelo.
//
// LA VARIABLE **NO** SE LLAMA $avisos, y no es capricho: correrArnes() declara
// `global $fallos, $avisos` para el contador del resumen, asi que un $avisos
// local aqui NO es local -- pisa el contador. Ya paso: el arnes murio con
// "Cannot increment array" la siguiente vez que alguien llamo a aviso().
$avisosParaModelo = chatAvisosDelPanel(['avisoModelo' => null], $faltas);
if ($avisosParaModelo !== [] && str_contains($avisosParaModelo[0], 'factura 2')) {
    ok('el aviso del item ENTRA al canal avisosPanel (antes no entraba nunca).');
} else {
    mal('el aviso del item no llega al canal: el modelo va a repetir el documento sin precio.');
}

// LOS DOS AVISOS A LA VEZ NO SE PISAN. Era la trampa de la version anterior: la
// linea del cliente asignaba el arreglo entero y borraba lo que hubiera.
$ambos = chatAvisosDelPanel(['avisoModelo' => 'el nombre "Plantiflex" no se encontro en el maestro de clientes'], $faltas);
printf("      avisos con cliente sin resolver Y detalle incompleto: %d\n", count($ambos));
if (count($ambos) === 2
    && str_contains($ambos[0], 'Plantiflex')
    && str_contains($ambos[1], 'factura 2')) {
    ok('con las dos cosas pendientes viajan LOS DOS avisos: ninguno pisa al otro.');
} else {
    mal('un aviso borro al otro: ' . json_encode($ambos));
}

// TURNO 2: el aviso llega al prompt. Bytes reales, como en la verificacion 6.
$hPrecio = [];
$tPrecio = traductorArmadoFalso([sobreArmado('{"desenlace":"faltan_datos","pregunta":"¿cual?"}')],
    'clave-falsa', $hPrecio);
$tPrecio->traducir(
    ['facturale a plantiflex el arriendo de software', 'agregale el proceso de certificacion ante SII'],
    ['documentos' => $dosDocs],
    vocabularioArmado(),
    '2026-08-13',
    $avisosParaModelo
);
$planoPrecio = (string) json_encode(
    json_decode((string) $hPrecio[0]['request']->getBody(), true),
    JSON_UNESCAPED_UNICODE
);
if (str_contains($planoPrecio, 'me falta el precio')) {
    ok('turno 2: el aviso VIAJA en el prompt, asi que el modelo sabe que documento completar.');
} else {
    mal('el aviso no llego al prompt: el modelo seguira devolviendo el documento sin precio.');
}

// TURNO 3: el usuario dio el precio. Ni queda pendiente ni se repite el aviso.
$dosDocsCompletos = $dosDocs;
$dosDocsCompletos[1]['item']['precioUnitario'] = 80000;
$faltas3 = [];
foreach ($dosDocsCompletos as $i => $doc) {
    $f = chatFaltaDelDocumento($doc, $i, count($dosDocsCompletos));
    if ($f !== null) {
        $faltas3[$i] = $f;
    }
}
if ($faltas3 === [] && chatAvisosDelPanel(['avisoModelo' => null], $faltas3) === []) {
    ok('turno 3: con el precio dado no queda pendiente NADA y el aviso NO se repite. '
        . 'El bucle de las tres preguntas se cierra.');
} else {
    mal('turno 3: sigue habiendo pendientes con el precio ya dado: ' . json_encode($faltas3));
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

// --- EL ORDEN DENTRO DE chatTurnoDeArmado(), MIRADO EN EL FUENTE -----------
//
// POR QUE UNA COMPROBACION SOBRE EL TEXTO DEL ARCHIVO Y NO SOBRE EL COMPORTAMIENTO:
// chatTurnoDeArmado() no se puede llamar desde aqui -- todas sus salidas terminan
// en $pintar(), que redirige y hace exit. Lo que si se puede comprobar es la
// unica propiedad que causo el defecto, y es estructural.
//
// EL DEFECTO: la resolucion del cliente vivia DESPUES de la rama FALTAN_DATOS, y
// esa rama no vuelve. Con un RUT en el borrador, el panel nunca miraba el maestro
// y se limitaba a repetir la pregunta del modelo -- "¿el cliente es nuevo?" --
// que el modelo no puede responder porque no ve el maestro ni debe verlo.
echo "\n  EL ORDEN QUE CAUSO EL DEFECTO DEL RUT (12-08-2026)\n";

// LA VERSION ANTERIOR DE ESTE GUARDIAN NO COMPROBABA NADA, y conviene que quede
// escrito: buscaba la cadena 'ArmadoFacturaTraducido::FALTAN_DATOS)' -- que el
// propio arreglo habia BORRADO al reemplazar esa rama por $r->sigueAbierta().
// Como no la encontraba, salia por el aviso de "no se pudo comprobar" y el arnes
// daba fallos 0 sin haber mirado el orden. Un guardian que no encuentra su ancla
// tiene que FALLAR, no encogerse de hombros: por eso ahora los tres casos de
// ancla ausente son mal() y no aviso().
$fuente = (string) file_get_contents($RAIZ . '/panel/public/index.php');

// SE ACOTA AL CUERPO DE LA FUNCION. Fuera de ella hay otras apariciones de estos
// nombres -- la declaracion de chatResolverClienteDelBorrador(), por ejemplo --
// y compararlas entre si no significaria nada.
$ini = strpos($fuente, 'function chatTurnoDeArmado(');
$fin = $ini !== false ? strpos($fuente, "\n}\n", $ini) : false;

if ($ini === false || $fin === false) {
    mal('no se pudo aislar el cuerpo de chatTurnoDeArmado(): la comprobacion del orden NO CORRIO.');
} else {
    $cuerpoFn = substr($fuente, $ini, $fin - $ini);

    // LAS DOS ANCLAS, y por que estas:
    //
    //   A = la resolucion del cliente.
    //   B = el PRIMER chatArmadoGuardar($conversacionId, $estado) del tramo que
    //       maneja el borrador. Cada rama que corta guarda el estado justo antes
    //       de llamar a $pintar(), asi que la primera aparicion de B marca donde
    //       empieza a haber salidas.
    //
    // EN LA FORMA ROTA, B estaba en la rama FALTAN_DATOS y caia ANTES de A: por
    // eso el panel nunca miraba el maestro. En la forma correcta, A va primero.
    // No se ancla en 'FALTAN_DATOS' ni en '$r->pregunta': el primero ya no existe
    // y el segundo aparece tambien dentro de chatDiag(), antes de A, lo que daria
    // un fallo falso.
    $posA = strpos($cuerpoFn, 'chatResolverClienteDelBorrador($cuentaId');
    $posB = strpos($cuerpoFn, 'chatArmadoGuardar($conversacionId, $estado);');

    if ($posA === false) {
        mal('dentro de chatTurnoDeArmado() no aparece chatResolverClienteDelBorrador(): '
            . 'o cambio de nombre, o la resolucion del cliente se quito. La comprobacion NO CORRIO.');
    } elseif ($posB === false) {
        mal('dentro de chatTurnoDeArmado() no aparece ningun chatArmadoGuardar($conversacionId, '
            . '$estado): cambio la forma de guardar el estado y esta comprobacion NO CORRIO.');
    } elseif ($posA < $posB) {
        printf("      resolucion en el byte %d del cuerpo, primera salida en el %d\n", $posA, $posB);
        ok('la resolucion del cliente ocurre ANTES de la primera rama que corta: un RUT en el '
            . 'borrador se busca en el maestro pase lo que pase.');
    } else {
        mal('la resolucion del cliente volvio a quedar DESPUES de la rama que corta. Con un RUT en '
            . 'el borrador, el panel no va a mirar el maestro y le devolvera al usuario la pregunta '
            . 'del modelo. Es el defecto que reporto Daniel el 12-08-2026.');
    }
}

// --- LOS TRES TURNOS DE DANIEL (12-08-2026) --------------------------------
//
// Escribio "cliente plantiflex", no existia, y el panel se lo dijo. Corrigio con
// "el nombre de fantasia es plantiflex, es plantillas ortopedicas" y recibio EL
// MISMO MENSAJE. Lo intento una tercera vez y otra vez lo mismo.
//
// La causa no era el buscador: era que el aviso del panel iba al hilo -- que no
// viaja -- asi que el modelo nunca supo que su nombre habia fallado, y el prompt
// le pide conservar lo entendido. Se reproduce el ciclo entero.
echo "\n  LOS TRES TURNOS DE DANIEL: nombre que no existe, correccion, y busqueda nueva\n";

// El cliente que SI existe con el nombre corregido. Sin el, el turno 3 no podria
// distinguir "busco el nombre nuevo" de "sigue sin encontrar nada".
$pdo->prepare('INSERT INTO cliente (cuenta_id, rut_cliente, razon_social, giro, direccion, comuna) '
    . 'VALUES (?, ?, ?, ?, ?, ?)')
    ->execute([$cuentaId, '12345678-5', 'PLANTILLAS ORTOPEDICAS SPA', 'ORTOPEDIA', 'CALLE 9', 'VALDIVIA']);

// TURNO 1: el modelo trae el nombre de fantasia, que no existe en el maestro.
$t1 = chatResolverClienteDelBorrador($cuentaId, ['cliente' => ['nombre' => 'Plantiflex']]);
printf("      turno 1 -> %s | %s\n", $t1['estado'], mb_substr((string) $t1['texto'], 0, 50));
if ($t1['estado'] === 'preguntar' && str_contains((string) $t1['texto'], 'No encontre')) {
    ok('turno 1: "Plantiflex" no se encuentra y el panel lo dice.');
} else {
    mal('turno 1 no reprodujo el punto de partida: ' . json_encode($t1));
}

// LO QUE ANTES NO EXISTIA: un aviso para el modelo.
if (isset($t1['avisoModelo']) && str_contains((string) $t1['avisoModelo'], 'Plantiflex')) {
    ok('y deja un aviso para el modelo: "' . (string) $t1['avisoModelo'] . '"');
} else {
    mal('el panel NO dejo aviso para el modelo: el bucle del nombre sigue abierto.');
}

// EL AVISO LLEGA AL PROMPT. Se miran los bytes reales, como en la verificacion 6.
$hAviso = [];
$tAviso = traductorArmadoFalso([sobreArmado('{"desenlace":"faltan_datos","pregunta":"¿cual?"}')],
    'clave-falsa', $hAviso);
$tAviso->traducir(
    ['cliente plantiflex', 'el nombre de fantasia es plantiflex, es plantillas ortopedicas'],
    ['cliente' => ['nombre' => 'Plantiflex']],
    vocabularioArmado(),
    '2026-08-12',
    [(string) $t1['avisoModelo']]
);
$cuerpoAviso = (string) $hAviso[0]['request']->getBody();
$planoAviso  = (string) json_encode(json_decode($cuerpoAviso, true), JSON_UNESCAPED_UNICODE);
if (str_contains($planoAviso, 'no se encontro en el maestro de clientes')) {
    ok('turno 2: el aviso VIAJA en el prompt, asi que el modelo puede corregir el nombre.');
} else {
    mal('el aviso no llego al prompt: el modelo seguira repitiendo el nombre viejo.');
}
// Y NADA MAS DEL MAESTRO SE CUELA CON EL. El aviso nombra el termino buscado y ya.
foreach (['PLANTILLAS ORTOPEDICAS SPA', '12345678-5', 'CLIENTE DE ARNES SPA'] as $noDebe) {
    if (str_contains($planoAviso, $noDebe)) {
        mal('el aviso arrastro datos del maestro al proveedor: ' . $noDebe);
    }
}
ok('y el aviso NO arrastro nada del maestro: ni razon social, ni RUT, ni otros clientes.');

// TURNO 3: el modelo ya corrigio el nombre. Se busca el NUEVO, no el viejo.
$t3 = chatResolverClienteDelBorrador($cuentaId, ['cliente' => ['nombre' => 'plantillas ortopedicas']]);
printf("      turno 3 -> %s | %s\n", $t3['estado'], mb_substr((string) $t3['texto'], 0, 60));
if ($t3['estado'] === 'listo') {
    ok('turno 3: con el nombre corregido, el cliente SE ENCUENTRA. El bucle se cierra.');
} else {
    mal('turno 3: el nombre corregido tampoco resuelve: ' . json_encode($t3));
}

// EL AGRAVANTE: la correccion en 'razonSocial' con 'nombre' ocupado por el viejo.
// Antes esto no se miraba y la busqueda repetia el termino que ya habia fallado.
$tMixto = chatResolverClienteDelBorrador($cuentaId, [
    'cliente' => ['nombre' => 'Plantiflex', 'razonSocial' => 'plantillas ortopedicas'],
]);
printf("      nombre viejo + razonSocial corregida -> %s\n", $tMixto['estado']);
if ($tMixto['estado'] === 'listo') {
    ok('si el nombre no da nada, se reintenta con la razon social: la correccion no se pierde.');
} else {
    mal('con el nombre viejo ocupado, la correccion en razonSocial se ignora: ' . json_encode($tMixto));
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

// El CUARTO elemento es el borrador previo. cambio_de_tema necesita uno: desde el
// arreglo del defecto del 12-08-2026 ese desenlace no existe en el primer turno.
$casosDesenlace = [
    ['faltan_datos',   '{"desenlace":"faltan_datos","pregunta":"¿que precio?","borrador":{"cliente":{"rut":"1-9"}}}', ArmadoFacturaTraducido::FALTAN_DATOS, []],
    ['borrador_listo', '{"desenlace":"borrador_listo","borrador":{"documentos":[{"item":{"nombre":"x","cantidad":1,"precioUnitario":100}}]}}', ArmadoFacturaTraducido::BORRADOR_LISTO, []],
    ['cambio_de_tema', '{"desenlace":"cambio_de_tema"}', ArmadoFacturaTraducido::CAMBIO_DE_TEMA, ['cliente' => ['rut' => '76192083-9']]],
    ['no_entendida',   '{"desenlace":"no_entendida","motivo":"no se"}', ArmadoFacturaTraducido::NO_ENTENDIDA, []],
];
foreach ($casosDesenlace as [$nombre, $json, $esperado, $previo]) {
    $h = [];
    $t = traductorArmadoFalso([sobreArmado($json)], 'clave-falsa', $h);
    $res = $t->traducir(['algo'], $previo, vocabularioArmado(), '2026-08-12');
    printf("      %-16s -> %s\n", $nombre, $res->desenlace);
    if ($res->desenlace === $esperado) {
        ok("desenlace '{$nombre}' reconocido.");
    } else {
        mal("desenlace '{$nombre}' salio como '{$res->desenlace}'.");
    }
}

// --- EL DEFECTO DE PRODUCCION DEL 12-08-2026 -------------------------------
//
// Daniel escribio "quiero que me hagas una factura excenta para el cliente
// plantiflex por 1300 pesos" y recibio el mensaje del camino de consultas. La
// heuristica de ruteo habia acertado; lo que fallo fue que el modelo contesto
// "cambio_de_tema" EN EL PRIMER TURNO -- donde no habia nada que abandonar -- y
// el turno cayo al otro camino despues de pagar dos llamadas.
//
// ESTE CASO REPRODUCE EXACTAMENTE ESO: primer turno, borrador previo VACIO, y el
// modelo respondiendo cambio_de_tema. Tiene que fallar, no colarse.
echo "\n  EL DEFECTO DE PRODUCCION: cambio_de_tema en el primer turno\n";

$fraseReal = 'quiero que me hagas una factura excenta para el cliente plantiflex por 1300 pesos';

$h = [];
$t = traductorArmadoFalso([sobreArmado('{"desenlace":"cambio_de_tema"}')], 'clave-falsa', $h);
try {
    $res = $t->traducir([$fraseReal], [], vocabularioArmado(), '2026-08-12');
    mal('con borrador previo VACIO, un "cambio_de_tema" se acepto y salio como "'
        . $res->desenlace . '". Es el defecto de produccion: el pedido se va al camino de '
        . 'consultas y el usuario recibe una respuesta que no pidio.');
} catch (Throwable $e) {
    ok('sin borrador previo, "cambio_de_tema" se RECHAZA: ' . mb_substr($e->getMessage(), 0, 90));
}

// Y EL PROMPT NI SE LO OFRECE. Se mira el cuerpo real que se envio: si la palabra
// no esta en las instrucciones, el modelo no puede copiarla de ahi.
$cuerpoPrimerTurno = (string) ($h[0]['request'] ?? null)?->getBody();
if ($cuerpoPrimerTurno !== '' && ! str_contains($cuerpoPrimerTurno, 'cambio_de_tema')) {
    ok('y el prompt del primer turno NI MENCIONA cambio_de_tema: no hay de donde copiarlo.');
} else {
    mal('el prompt del primer turno sigue ofreciendo cambio_de_tema.');
}

// CON UNA CONVERSACION EN CURSO, EL MISMO DESENLACE ES VALIDO Y TIENE QUE PASAR.
// Sin esta mitad, el arreglo podria haber sido "quitar cambio_de_tema" a secas,
// que romperia el caso para el que existe.
$h2 = [];
$t2 = traductorArmadoFalso([sobreArmado('{"desenlace":"cambio_de_tema"}')], 'clave-falsa', $h2);
$res2 = $t2->traducir(
    [$fraseReal, 'cuanto facture en julio'],
    ['cliente' => ['rut' => '76192083-9']],   // <- borrador previo: hay algo en curso
    vocabularioArmado(),
    '2026-08-12'
);
if ($res2->desenlace === ArmadoFacturaTraducido::CAMBIO_DE_TEMA) {
    ok('con un borrador en curso, cambio_de_tema SIGUE funcionando: el arreglo no lo mato.');
} else {
    mal('con borrador en curso, cambio_de_tema salio como "' . $res2->desenlace
        . '": se rompio el caso para el que ese desenlace existe.');
}
$cuerpoSegundoTurno = (string) ($h2[0]['request'] ?? null)?->getBody();
if (str_contains($cuerpoSegundoTurno, 'cambio_de_tema')) {
    ok('y ahi el prompt SI lo ofrece: la opcion aparece solo cuando puede aplicar.');
} else {
    mal('con borrador en curso el prompt tampoco lo ofrece: el modelo no puede elegirlo.');
}

// --- LA RED DE SEGURIDAD DE LA HEURISTICA (13-08-2026) ---------------------
//
// Daniel escribio "puedes mostrarme los ultimos clientes facturados," -- una
// consulta -- y recibio "solo puedo ayudarte a preparar borradores de facturas".
//
// DOS CAUSAS ENCADENADAS: la heuristica la mando a armado (mira la PRIMERA
// palabra, y ahi dice "puedes"; la frase menciona "facturados"), y una vez alli
// ya no habia salida -- cambio_de_tema quedo cerrado para el primer turno al
// arreglar el defecto anterior, y no_entendida termina en pantalla.
//
// es_consulta devuelve la salida. Se prueba en el PRIMER turno, sin borrador
// previo, que es justo donde no existia.
echo "\n  LA RED DE SEGURIDAD: es_consulta funciona SIN borrador previo\n";

$frasesMalRuteadas = [
    'puedes mostrarme los ultimos clientes facturados,',   // la de Daniel, literal
    'me puedes mostrar lo facturado en julio',
    'necesito ver lo facturado este mes',
    'dime cuanto facture en julio',
];
foreach ($frasesMalRuteadas as $frase) {
    // Se comprueba primero que la heuristica SIGUE mandandolas a armado: si algun
    // dia se arregla el problema (a), esta prueba tiene que decirlo en vez de
    // pasar por un motivo distinto del que dice probar.
    $vaArmado = chatPareceArmado($frase);

    $h = [];
    $t = traductorArmadoFalso([sobreArmado('{"desenlace":"es_consulta"}')], 'clave-falsa', $h);
    $res = $t->traducir([$frase], [], vocabularioArmado(), '2026-08-13');

    printf("      %-52s heuristica=%s  desenlace=%s\n",
        mb_substr($frase, 0, 50), $vaArmado ? 'armado' : 'consulta', $res->desenlace);

    if ($res->desenlace === ArmadoFacturaTraducido::ES_CONSULTA && $res->vaAConsultas()) {
        ok('"' . mb_substr($frase, 0, 34) . '..." vuelve al camino de consultas.');
    } else {
        mal('"' . mb_substr($frase, 0, 34) . '..." quedo atrapada en armado como "'
            . $res->desenlace . '": el usuario recibe una respuesta que no pidio.');
    }
    if (! $vaArmado) {
        aviso('esa frase ya NO la rutea la heuristica a armado: el problema (a) se arreglo y '
            . 'esta prueba dejo de ejercitar la red por el camino que decia.');
    }
}

// LA DIFERENCIA CON cambio_de_tema, que es lo que impide reabrir el bug del
// 12-08: aquel SIGUE prohibido sin borrador previo.
$h = [];
$t = traductorArmadoFalso([sobreArmado('{"desenlace":"cambio_de_tema"}')], 'clave-falsa', $h);
try {
    $t->traducir(['puedes mostrarme los ultimos clientes facturados,'], [], vocabularioArmado(), '2026-08-13');
    mal('cambio_de_tema volvio a aceptarse sin borrador previo: se reabrio el defecto del 12-08.');
} catch (Throwable $e) {
    ok('y cambio_de_tema SIGUE cerrado en el primer turno: los dos desenlaces no se fundieron.');
}

// EL PROMPT DEL PRIMER TURNO OFRECE es_consulta Y NO cambio_de_tema.
$h = [];
$t = traductorArmadoFalso([sobreArmado('{"desenlace":"es_consulta"}')], 'clave-falsa', $h);
$t->traducir(['puedes mostrarme los ultimos clientes facturados,'], [], vocabularioArmado(), '2026-08-13');
$cuerpoPrimero = (string) json_encode(json_decode((string) $h[0]['request']->getBody(), true), JSON_UNESCAPED_UNICODE);
if (str_contains($cuerpoPrimero, 'es_consulta') && ! str_contains($cuerpoPrimero, 'cambio_de_tema')) {
    ok('el prompt del primer turno ofrece es_consulta y NO cambio_de_tema: cada uno donde aplica.');
} else {
    mal('el prompt del primer turno no distingue los dos desenlaces.');
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

// ===========================================================================
// LO QUE VIAJA AL MODELO -- LA COMPROBACION MAS IMPORTANTE DE ESTE ARNES
//
// SE MIRAN LOS BYTES REALES de la peticion, no lo que el codigo dice que manda.
//
// ---------------------------------------------------------------------------
// POR QUE ESTA COMPROBACION SE REESCRIBIO: DABA FALSAS ALARMAS
//
// La version anterior hacia, entre otras cosas:
//
//     str_contains($cuerpo, (string) $cuentaId)
//
// con $cuentaId un entero de la siembra. Buscar un numero suelto como subcadena
// dentro de un JSON de varios kilobytes es una loteria: el prompt lleva 255 tres
// veces (los largos de columna), 100, 10, 10000 y la fecha 2026-08-12. Una cuenta
// con id 10, 12, 20, 25, 26, 55, 100 o 255 hacia saltar la alarma sin que
// hubiera filtrado NADA. Y como el id lo asigna el AUTO_INCREMENT, el resultado
// dependia de cuantas veces se habia corrido el arnes antes.
//
// ES LA MISMA FAMILIA DE DEFECTO que ya aparecio en este proyecto al comparar un
// folio como entero con str_contains(). Un numero corto no identifica nada.
//
// UNA ALARMA FALSA EN UNA COMPROBACION DE PRIVACIDAD NO ES INOCUA: enseña a
// desconfiar de ella, y el dia que suene de verdad va a parecer otro falso
// positivo. Por eso se cambia por marcas que solo pueden estar si algo se filtro.
//
// TAMPOCO SE BUSCAN NOMBRES DE CAMPO ('giro', 'direccion', 'comuna',
// 'razonSocial'): el prompt los menciona a proposito, para pedirle esos datos al
// modelo. Buscarlos seria el mismo error al reves. Se buscan los VALORES.
// ===========================================================================
echo "\n  QUE VIAJA AL PROVEEDOR (bytes reales de la peticion)\n";

// EL CANARIO. Una cadena que no existe en ninguna otra parte del sistema: si
// aparece en el cuerpo, viajo desde el hilo y no hay otra explicacion posible.
//
// LLEVA UNA Ñ Y UNA TILDE A PROPOSITO. Ver la nota sobre el escapado de mas
// abajo: sin ellas, este canario no probaria que la busqueda funciona con los
// nombres reales de clientes chilenos, que es donde estan los acentos.
$CANARIO = 'CANARIO-QUE-NO-DEBE-VIAJAR-7f3a91-Ñuñoa-Pérez';

// UN ESTADO COMO EL QUE DE VERDAD TIENE UNA CONVERSACION A MEDIAS: con su hilo
// visible (que incluye lo que dijo el asistente, con datos del maestro dentro) y
// con el cliente ya resuelto. Es exactamente la forma que tiene el defecto que
// se quiere impedir.
$estadoSucio = [
    'turnos'   => ['facturale a Perez el diseño'],
    'borrador' => ['cliente' => ['rut' => '76192083-9']],
    'hilo'     => [
        ['rol' => 'usuario',   'texto' => 'facturale a Perez el diseño'],
        ['rol' => 'asistente', 'texto' => "Uso a CLIENTE DE ARNES SPA (76192083-9). {$CANARIO}"],
    ],
    'cliente'  => [
        'razon_social' => 'CLIENTE DE ARNES SPA',
        'giro'         => 'SERVICIOS DE PRUEBA',
        'direccion'    => 'CALLE FALSA 123',
        'comuna'       => 'VALDIVIA',
    ],
];

// SE COPIA LITERALMENTE LO QUE PASA chatTurnoDeArmado(): los turnos del usuario
// mas el mensaje de ahora, y el borrador del propio modelo. Si algun dia esa
// llamada cambiara para mandar el estado completo, esta prueba lo caza.
$h = [];
$t = traductorArmadoFalso([sobreArmado('{"desenlace":"cambio_de_tema"}')], 'clave-falsa', $h);
$t->traducir(
    array_values(array_merge($estadoSucio['turnos'], ['y agregale el hosting'])),
    is_array($estadoSucio['borrador'] ?? null) ? $estadoSucio['borrador'] : [],
    vocabularioArmado(),
    '2026-08-12'
);
$cuerpo = (string) $h[0]['request']->getBody();
printf("      bytes enviados: %d\n", strlen($cuerpo));

// ---------------------------------------------------------------------------
// EL CUERPO SE NORMALIZA ANTES DE BUSCAR NADA, Y ESTO NO ES UN DETALLE.
//
// Guzzle serializa la opcion 'json' con json_encode() Y FLAGS POR DEFECTO, o sea
// SIN JSON_UNESCAPED_UNICODE. Todo lo que no sea ASCII viaja escapado:
//
//     "facturale a Perez el diseño"  ->  facturale a Perez el diseño
//
// Buscar la cadena con tilde o ñ contra los bytes crudos NO LA ENCUENTRA aunque
// este ahi. Eso ya produjo un fallo -- "no viajaron las frases del usuario" --
// cuando la frase si habia viajado.
//
// Y EL LADO GRAVE ES EL OTRO: si un valor FILTRADO llevara acento (una razon
// social como "Comercial Pérez", una comuna como "Ñuñoa"), las marcas de
// privacidad no lo habrian visto. Un falso negativo ahi es mucho peor que el
// falso positivo del entero de ayer: la alarma se queda callada mientras el dato
// sale.
//
// Se decodifica y se vuelve a codificar SIN escapar: asi una misma busqueda
// encuentra el texto escrito como se lee, venga escapado o no.
// ---------------------------------------------------------------------------
$decodificado = json_decode($cuerpo, true);
if (! is_array($decodificado)) {
    mal('el cuerpo enviado al proveedor no es JSON valido: no se puede inspeccionar. '
        . 'ESTA COMPROBACION NO CORRIO.');
    $plano = $cuerpo;
} else {
    $plano = (string) json_encode($decodificado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($plano !== $cuerpo) {
        ok('el cuerpo venia con caracteres escapados (\\uXXXX) y se normalizo antes de buscar.');
    }
}

// Cada marca es un VALOR que solo pudo salir del maestro o del hilo. Ninguna es
// una palabra que el prompt use por su cuenta.
$filtrado = [];
foreach ([
    $CANARIO               => 'el canario del hilo',
    'CLIENTE DE ARNES SPA' => 'la razon social resuelta del maestro',
    'SERVICIOS DE PRUEBA'  => 'el giro del maestro',
    'CALLE FALSA 123'      => 'la direccion del maestro',
    'VALDIVIA'             => 'la comuna del maestro',
    '"hilo"'               => 'la clave del hilo visible',
    '"rol"'                => 'la estructura de turnos del hilo',
] as $marca => $queEs) {
    if (str_contains($plano, $marca)) {
        $filtrado[] = $queEs . ' (' . mb_substr($marca, 0, 40) . ')';
    }
}
if ($filtrado === []) {
    ok('NADA del tenant viaja: ni el hilo, ni lo que el panel resolvio del maestro. '
        . '7 marcas comprobadas contra los bytes reales, ya normalizados.');
} else {
    mal('VIAJO INFORMACION DEL TENANT AL PROVEEDOR. ESTO ROMPE LA REGLA DE PRIVACIDAD. '
        . 'Se encontro: ' . implode('; ', $filtrado));
}

// LA BUSQUEDA TIENE QUE SABER ENCONTRAR ACENTOS, y se demuestra en vez de
// suponerlo: se busca en el cuerpo una cadena acentuada que SI esta ahi. Si esto
// fallara, las siete marcas de arriba no valdrian nada -- estarian dando verde
// por no saber mirar, que es la peor forma de pasar una prueba de privacidad.
if (str_contains($plano, 'diseño')) {
    ok('la busqueda encuentra texto acentuado: las 7 marcas de arriba son fiables '
        . 'aunque el dato filtrado llevara ñ o tilde.');
} else {
    mal('la busqueda NO encuentra "diseño" pese a estar en el cuerpo: las marcas de privacidad '
        . 'estan ciegas a los acentos y su verde no significa nada.');
}

// Y LA OTRA MITAD: lo que SI tiene que viajar. Sin esto, un traductor que no
// mandara nada pasaria la prueba de arriba con nota perfecta.
if (str_contains($plano, 'facturale a Perez el diseño') && str_contains($plano, 'y agregale el hosting')) {
    ok('y si viajan las frases del usuario -- las dos --, que es lo unico que debe viajar.');
} else {
    mal('no viajaron las frases del usuario: el traductor no esta mandando los turnos.');
}
if (str_contains($plano, '76192083-9')) {
    ok('el RUT tecleado por el usuario tambien viaja, y esta bien: es su pedido, no un dato '
        . 'que el sistema fue a buscar. Es el limite que la pantalla ya declara.');
} else {
    aviso('el RUT del borrador previo no viajo; el modelo va a tener que volver a preguntarlo.');
}

// ===========================================================================
// VERIFICACION 7 - EL HILO VISIBLE
//
// Nace del hallazgo de UX de Daniel: la respuesta aparecia suelta debajo del
// cuadro, sin señal de que ahi seguia la conversacion. Ahora hay turnos, y hay
// tres cosas que pueden salir mal en silencio: que el hilo no se guarde, que
// crezca sin techo dentro de $_SESSION, y que las tablas viejas no se suelten.
// ===========================================================================
titulo('VERIFICACION 7 - el hilo de turnos y su tope');

$_SESSION[CHAT_ARMADO_SESION] = [];
$idHilo = chatConversacionRegistrar(chatConversacionNueva());

if (chatHiloDe($idHilo) === []) {
    ok('una conversacion recien abierta tiene el hilo vacio.');
} else {
    mal('el hilo nace con algo dentro.');
}

chatHiloAgregar($idHilo, 'usuario', 'cuanto vendi en julio');
chatHiloAgregar($idHilo, 'asistente', 'monto total, en total, entre el 01-07 y el 31-07',
    ['descripcion' => 'monto total', 'filas' => [], 'meta' => []]);

$hilo = chatHiloDe($idHilo);
printf("  turnos tras una vuelta: %d  (%s)\n", count($hilo),
    implode(' -> ', array_column($hilo, 'rol')));
if (count($hilo) === 2 && $hilo[0]['rol'] === 'usuario' && $hilo[1]['rol'] === 'asistente') {
    ok('una pregunta y su respuesta quedan como DOS turnos, en orden.');
} else {
    mal('el hilo no guardo los dos turnos en orden: ' . json_encode(array_column($hilo, 'rol')));
}
if (is_array($hilo[1]['resultado'] ?? null)) {
    ok('el turno del asistente conserva su tabla.');
} else {
    mal('la tabla de la consulta no se guardo con su turno.');
}

// EL TOPE. Se pasa de largo a proposito y se comprueba que lo que se descarta
// son LOS MAS VIEJOS -- descartar los nuevos dejaria el hilo congelado.
for ($i = 1; $i <= 25; $i++) {
    chatHiloAgregar($idHilo, $i % 2 === 0 ? 'asistente' : 'usuario', "turno de relleno {$i}");
}
$hilo = chatHiloDe($idHilo);
printf("  turnos tras agregar 25 mas: %d (tope %d)\n", count($hilo), CHAT_HILO_MAX_TURNOS);
if (count($hilo) === CHAT_HILO_MAX_TURNOS) {
    ok('el hilo se corta en ' . CHAT_HILO_MAX_TURNOS . ': $_SESSION no crece sin techo.');
} else {
    mal('el hilo quedo con ' . count($hilo) . ' turnos.');
}
if ((string) end($hilo)['texto'] === 'turno de relleno 25') {
    ok('y lo que sobrevive es lo RECIENTE: el ultimo turno es el ultimo escrito.');
} else {
    mal('el ultimo turno del hilo es "' . (string) end($hilo)['texto'] . '".');
}

// LAS TABLAS VIEJAS SE SUELTAN. Veinte resultados de 100 filas cada uno serian
// megabytes serializados en CADA peticion de esa sesion.
$conTabla = 0;
foreach ($hilo as $t) {
    if (is_array($t['resultado'] ?? null)) {
        $conTabla++;
    }
}
printf("  turnos que todavia cargan una tabla: %d\n", $conTabla);
if ($conTabla <= 1) {
    ok('solo el ultimo turno conserva tabla: los anteriores guardan su frase y sueltan el peso.');
} else {
    mal("{$conTabla} turnos siguen cargando su tabla en la sesion.");
}

// DE QUE CONVERSACION SE PINTA EL HILO. Es lo que hace que el redirect del POST
// vuelva a la pestaña correcta.
$_GET['c'] = $idHilo;
if (chatConversacionDelGet() === $idHilo) {
    ok('con ?c=<id> conocido, el GET pinta ESA conversacion: cada pestaña vuelve a la suya.');
} else {
    mal('el GET no respeta el ?c= que le manda el redirect.');
}
$_GET['c'] = str_repeat('f', 32);
$caida = chatConversacionDelGet();
if ($caida !== str_repeat('f', 32)) {
    ok('un ?c= desconocido NO se acepta: no se puede pintar el hilo de una conversacion ajena.');
} else {
    mal('el GET acepto un identificador que la sesion no conoce.');
}
unset($_GET['c']);

// --- LA HIPOTESIS DEL RELOAD EN BLANCO -------------------------------------
//
// SE MIDE, NO SE SUPONE. Hay un reporte de que un F5 deja la conversacion vacia
// con el ?c correcto y la sesion viva. La sospecha es el tope de conversaciones:
// descarta primero las que NO tienen 'turnos', y una conversacion de puras
// CONSULTAS es exactamente eso -- tiene hilo y no tiene turnos. Si se descarta,
// su ?c queda en la barra del navegador apuntando a algo que la sesion ya no
// conoce, y el GET estrena una conversacion vacia.
//
// SALIO EN ROJO Y ERA LA CAUSA. El criterio de descarte miraba solo 'turnos', asi
// que una conversacion de consultas -- hilo si, turnos no -- se consideraba
// prescindible. Ahora se descarta solo lo que no tiene NI hilo NI turnos, y este
// bloque queda como el guardian de que no vuelva a pasar.
echo "\n  ¿EL TOPE DE CONVERSACIONES SE COME LOS HILOS DE CONSULTA?\n";

// -----------------------------------------------------------------------------
// LA PRIMERA VERSION DE ESTA PRUEBA EXIGIA UN IMPOSIBLE, y conviene que quede
// escrito porque parecia una regresion y no lo era.
//
// Creaba CUATRO conversaciones CON HILO contra un tope de TRES, y despues exigia
// que la primera sobreviviera. Con el tope en 3 y cuatro conversaciones que
// TODAS tienen contenido, alguna tiene que caer: el bucle de descarte no termina
// hasta bajar del tope. El criterio nuevo elige BIEN a quien sacrificar -- las
// vacias primero -- pero no puede evitar que se sacrifique a alguien.
//
// EL CASO REAL ERA OTRO, y es el que se prueba ahora: UNA conversacion con hilo y
// varias vacias recien estrenadas. Eso es lo que producia el defecto -- cada GET
// a /chat estrenaba una conversacion, y con el criterio viejo esas vacias
// desplazaban al hilo de consulta por no tener 'turnos'.
// -----------------------------------------------------------------------------

// CASO REAL: un hilo de consulta rodeado de conversaciones recien abiertas.
$_SESSION[CHAT_ARMADO_SESION] = [];
$conHilo = chatConversacionRegistrar(chatConversacionNueva());
chatHiloAgregar($conHilo, 'usuario', 'cuanto vendi en julio');
chatHiloAgregar($conHilo, 'asistente', 'monto total, en total');

for ($i = 1; $i <= CHAT_ARMADO_MAX_CONVERSACIONES + 2; $i++) {
    chatConversacionRegistrar(chatConversacionNueva());   // vacias, como un GET a /chat
}
printf("      conversaciones en sesion: %d (tope %d)\n",
    count($_SESSION[CHAT_ARMADO_SESION]), CHAT_ARMADO_MAX_CONVERSACIONES);
printf("      ¿sobrevive el hilo de consulta?: %s\n",
    isset($_SESSION[CHAT_ARMADO_SESION][$conHilo]) ? 'si' : 'NO');

if (isset($_SESSION[CHAT_ARMADO_SESION][$conHilo])) {
    ok('un hilo de consulta sobrevive a CINCO conversaciones vacias: las vacias caen primero. '
        . 'Es el caso que producia la pantalla en blanco tras un F5.');
} else {
    mal('REGRESION del reload en blanco: el tope volvio a descartar un hilo de CONSULTA por '
        . 'delante de conversaciones vacias. Su ?c queda apuntando a algo que la sesion ya no '
        . 'conoce, el GET estrena una vacia, y el usuario ve la pantalla sin burbujas.');
}

// EL LIMITE DECLARADO: mas conversaciones CON CONTENIDO que el tope. Aqui si cae
// una, y es la mas antigua. No es un defecto -- es lo que significa tener tope --
// pero se prueba para que sea una decision visible y no una sorpresa.
$_SESSION[CHAT_ARMADO_SESION] = [];
$conContenido = [];
for ($i = 1; $i <= CHAT_ARMADO_MAX_CONVERSACIONES + 1; $i++) {
    $c = chatConversacionRegistrar(chatConversacionNueva());
    chatHiloAgregar($c, 'usuario', "consulta {$i}");
    $conContenido[] = $c;
}
$masVieja = $conContenido[0];
$ultima   = $conContenido[count($conContenido) - 1];
printf("      con %d conversaciones CON hilo y tope %d: quedan %d\n",
    count($conContenido), CHAT_ARMADO_MAX_CONVERSACIONES, count($_SESSION[CHAT_ARMADO_SESION]));

if (count($_SESSION[CHAT_ARMADO_SESION]) === CHAT_ARMADO_MAX_CONVERSACIONES
    && ! isset($_SESSION[CHAT_ARMADO_SESION][$masVieja])
    && isset($_SESSION[CHAT_ARMADO_SESION][$ultima])) {
    ok('cuando TODAS tienen contenido cae la mas antigua y sobrevive la ultima: el tope se '
        . 'respeta y lo que se pierde es lo mas viejo, no lo que el usuario esta usando.');
} else {
    mal('con todas llenas, el descarte no se comporto como se declara: quedaron '
        . count($_SESSION[CHAT_ARMADO_SESION]) . ' conversaciones.');
}

// LA OTRA MITAD: lo genuinamente vacio SI se tiene que poder descartar, o el tope
// no serviria de nada y $_SESSION creceria sin techo.
$_SESSION[CHAT_ARMADO_SESION] = [];
$vacias = [];
for ($i = 1; $i <= CHAT_ARMADO_MAX_CONVERSACIONES + 2; $i++) {
    $vacias[] = chatConversacionRegistrar(chatConversacionNueva());
}
printf("      tras registrar %d conversaciones vacias quedan %d (tope %d)\n",
    count($vacias), count($_SESSION[CHAT_ARMADO_SESION]), CHAT_ARMADO_MAX_CONVERSACIONES);
if (count($_SESSION[CHAT_ARMADO_SESION]) <= CHAT_ARMADO_MAX_CONVERSACIONES) {
    ok('las conversaciones sin hilo ni turnos SI se descartan: el tope sigue acotando la sesion.');
} else {
    mal('el tope dejo de descartar: $_SESSION puede crecer sin techo.');
}

// ===========================================================================
// VERIFICACION 8 - POR HTTP
// ===========================================================================
titulo('VERIFICACION 8 - el camino por HTTP');

$panel = rtrim((string) getenv('PANEL_URL'), '/');
$user  = (string) getenv('PANEL_USER');
$pass  = (string) getenv('PANEL_PASS');

if ($panel === '' || $user === '' || $pass === '') {
    aviso('faltan PANEL_URL / PANEL_USER / PANEL_PASS: la mitad HTTP NO SE CORRIO. '
        . 'No se declara verde lo que no se probo.');
} else {
    $cookies = tempnam(sys_get_temp_dir(), 'arnes_ck_');

    $pedir = static function (string $metodo, string $url, array $campos = [], bool $seguir = true) use ($cookies): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR      => $cookies,
            CURLOPT_COOKIEFILE     => $cookies,
            CURLOPT_FOLLOWLOCATION => $seguir,
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

        // EL POST DEL CHAT AHORA ES PRG. Se pide SIN seguir el redirect para ver
        // el codigo de verdad: 303 y no 200.
        //
        // NO ES COSMETICO. Mientras el POST renderizaba directo, un F5 reenviaba
        // la pregunta y GASTABA OTRA LLAMADA al proveedor -- un defecto que
        // estuvo en produccion sin que nadie lo reportara. Con 303, el refresco
        // recarga un GET y no cuesta nada.
        $consulta = $pedir('POST', $panel . '/chat', [
            'csrf_token' => $token($chat['cuerpo']),
            'pregunta'   => 'cuanto vendi en julio',
        ], false);
        printf("      POST /chat sin seguir redirect -> HTTP %d\n", $consulta['codigo']);
        if ($consulta['codigo'] === 303) {
            ok('el POST responde 303 (PRG): un F5 ya no reenvia la pregunta ni gasta otra llamada.');
        } else {
            mal('el POST respondio ' . $consulta['codigo'] . ' y se esperaba 303. Si renderiza '
                . 'directo, el F5 vuelve a cobrar.');
        }

        // Y siguiendolo, la pantalla responde y trae el hilo con el turno nuevo.
        $tras = $pedir('POST', $panel . '/chat', [
            'csrf_token' => $token($chat['cuerpo']),
            'pregunta'   => 'cuanto vendi en julio',
        ]);
        if ($tras['codigo'] === 200) {
            ok('siguiendo el redirect, la pantalla responde 200 (regresion del camino viejo).');
        } else {
            mal('tras el redirect, la pantalla respondio ' . $tras['codigo']);
        }
        if (str_contains($tras['cuerpo'], 'chat-turno--usuario')
            && str_contains($tras['cuerpo'], 'chat-turno--asistente')) {
            ok('el hilo se pinta con los dos roles: la respuesta ya no flota suelta abajo.');
        } else {
            mal('la pantalla no trae burbujas de usuario y asistente.');
        }
        if (str_contains($tras['cuerpo'], 'id="ultimo"')) {
            ok('y el ultimo turno lleva el ancla #ultimo, que es a donde apunta el redirect.');
        } else {
            mal('falta el ancla #ultimo: el navegador no tiene a donde saltar sin JavaScript.');
        }
    }
    @unlink($cookies);
}

// ===========================================================================
titulo('RESUMEN');
printf("  fallos: %d\n  avisos: %d\n", $fallos, $avisosDelArnes);
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
