<?php

declare(strict_types=1);

/**
 * Capa HTTP delgada del API interno de facturacion-cl.
 *
 * - Autenticacion por header X-Api-Key con formato "{prefijo}.{secreto}",
 *   resuelta contra la tabla api_key (ver resolverTenant()). 401 generico si
 *   falta, esta mal formada, el prefijo no existe, la key esta revocada o el
 *   secreto no coincide (hash_equals en tiempo constante).
 * - El AMBIENTE y el RUT EMISOR salen SIEMPRE de la api_key autenticada
 *   (cuenta_id/rut_emisor_scope/ambiente en la tabla api_key), NUNCA de una
 *   env var global ni del cliente. El RUT SENDER (firmante del certificado)
 *   sale de dte_certificado.rut_sender del propio tenant (ver
 *   resolverRutSender()); la constante FACT_RUT_SENDER ya no se usa para
 *   construir Credenciales, queda solo por si algo externo la referencia.
 * - Servicio SOLO interno (red Docker web_default), nunca expuesto a internet.
 */

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use Plantiflex\FacturacionCl\Dto\Credenciales;
use Plantiflex\FacturacionCl\Dto\Detalle;
use Plantiflex\FacturacionCl\Dto\DocumentoOriginal;
use Plantiflex\FacturacionCl\Dto\DocumentoTributario;
use Plantiflex\FacturacionCl\Dto\Libro;
use Plantiflex\FacturacionCl\Dto\LineaLibro;
use Plantiflex\FacturacionCl\Dto\Receptor;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Enums\TipoAnulacion;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Enums\TipoEnvioLibro;
use Plantiflex\FacturacionCl\Enums\TipoLibro;
use Plantiflex\FacturacionCl\Enums\TipoOperacionLibro;
use Plantiflex\FacturacionCl\Exceptions\DocumentoInvalidoException;
use Plantiflex\FacturacionCl\Exceptions\EnvioRechazadoException;
use Plantiflex\FacturacionCl\Exceptions\SiiAutenticacionException;
use Plantiflex\FacturacionCl\Pdf\BoletaPdfGenerator;
use Plantiflex\FacturacionCl\Pdf\DtePdfGenerator;
use Plantiflex\FacturacionCl\Providers\BoletaFacturador;
use Plantiflex\FacturacionCl\Providers\SiiDirectoFacturador;
use Plantiflex\Integration\Facturacion\CertificadoCrypto;
use Plantiflex\Integration\Facturacion\MySqlDteEmitidoRepository;
use Plantiflex\Integration\Facturacion\MySqlEmisorRepository;
use Plantiflex\Integration\Facturacion\MySqlFolioRepository;
use Plantiflex\Integration\Facturacion\MySqlIdempotenciaRepository;
use Plantiflex\Integration\Facturacion\MySqlLibroRepository;
use Plantiflex\FacturacionCl\Sii\BoletaAutenticador;
use Plantiflex\FacturacionCl\Sii\BoletaConsultor;
use Plantiflex\FacturacionCl\Sii\LibroService;
use Plantiflex\FacturacionCl\Sii\RcvConsultor;
use Plantiflex\FacturacionCl\Sii\SiiAutenticador;
use Plantiflex\FacturacionCl\Sii\SiiConsultor;
use Plantiflex\FacturacionCl\Sii\XmlSigner;
use Plantiflex\FacturacionCl\Dto\Certificado;
use Plantiflex\FacturacionCl\Exceptions\RcvConsultaException;
use Plantiflex\FacturacionCl\Exceptions\ConexionException;

// --- Timezone: el SII espera FchEmis/TmstFirma en hora de Chile. Sin esto PHP
// usa UTC y las emisiones nocturnas (despues de ~20:00 CL) salen con fecha del
// dia siguiente. Afecta FchEmis (DteXmlBuilder), TSTED/TmstFirma (TedBuilder) y
// TmstFirmaEnv (EnvioDteBuilder), que usan date()/new DateTimeImmutable() sin TZ.
date_default_timezone_set('America/Santiago');

// --- Config resuelta en el servidor (no la elige el cliente) ---
const FACT_RUT_SENDER = '13520634-2';        // firmante del certificado
const TIPOS_PERMITIDOS = [33, 34, 61, 56];   // factura, factura exenta, NC, ND
// Solo para GET .../pdf: boleta (39) se emite por CLI, no por este API, pero SI
// puede pedir su PDF una vez persistida. TIPOS_PERMITIDOS NO se toca: emitir/
// listar/estado/anular siguen exclusivos de factura/NC/ND.
//
// OJO: ESTA LISTA ESTA DUPLICADA EN src/Correo/PreparadorEnvio.php (constante
// PreparadorEnvio::TIPOS_CON_PDF), que es de donde la leen los dos CLI de envio
// de correo (enviar_correo.php y enviar_correos_pendientes.php). Esos scripts
// corren por CLI y NO incluyen este front controller -- incluirlo dispararia
// Auth, sesion y el router --, asi que no pueden leer esta const. Los
// generadores de PDF (DtePdfGenerator/BoletaPdfGenerator) NO validan el tipo por
// su cuenta: quien filtra es pdfDte() aqui, y PreparadorEnvio alla. Si se agrega
// o quita un tipo, hay que tocar LOS DOS SITIOS: no hay nada que lo detecte
// automaticamente.
const TIPOS_PERMITIDOS_PDF = [33, 34, 61, 56, 39];
// Solo para el filtro ?tipoDte= del listado (GET /api/v1/dte): permite pedir
// boleta en el historico. TIPOS_PERMITIDOS NO se toca.
const TIPOS_PERMITIDOS_LISTADO = [33, 34, 61, 56, 39];
// Solo para anularDte(): boleta (39) puede ser el ORIGEN referenciado por una NC
// (no se "anula a si misma"). TIPOS_PERMITIDOS NO se toca (emitirDte sigue
// exclusivo de 33/61/56).
//
// EL 34 NO ESTA AQUI A PROPOSITO, y no es un olvido de la entrega que abrio el
// tipo 34. La NC 61 SI es el instrumento correcto para anular una factura exenta
// -- no existe un tipo de NC exenta --, pero hoy la NC saldria mal formada:
// SiiDirectoFacturador::anular() fija SIEMPRE 'MntNeto' e 'IVA' en los totales
// explicitos (src/Providers/SiiDirectoFacturador.php:414-417), y para un 34
// ambos valen 0. Como resolverTotales() corta en seco cuando recibe totales
// explicitos (src/Sii/DteXmlBuilder.php:148), la proteccion por datos que evita
// ese problema al emitir NO aplica al anular: la NC saldria con MntNeto=0 e
// IVA=0 sobre un documento que no puede llevarlos. Abrir el 34 aqui exige antes
// arreglar esos totales, y eso es una entrega propia.
const TIPOS_PERMITIDOS_ANULAR = [33, 61, 56, 39];
const NS_SII = 'http://www.sii.cl/SiiDte';
// TTL de un claim de idempotencia SIN folio (emision en curso / servidor caido):
// pasado este tiempo se considera muerto y se permite reintentar.
const IDEMPOTENCIA_TTL_SEGUNDOS = 300;       // 5 min

header('Content-Type: application/json; charset=utf-8');

/**
 * @param array<string,mixed> $payload
 */
function responder(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Error de validacion (no consume folio): 422 con campo. */
function invalido(string $error, string $campo): never
{
    responder(422, ['error' => $error, 'campo' => $campo]);
}

/** Ambiente resuelto desde el tenant autenticado (api_key), nunca del cliente. */
function ambienteDesdeTenant(array $tenant): Ambiente
{
    return $tenant['ambiente'] === 'certificacion'
        ? Ambiente::Certificacion
        : Ambiente::Produccion;
}

/**
 * Resuelve el RUT del firmante (sender) del tenant desde dte_certificado.rut_sender.
 *
 * NUNCA cae a FACT_RUT_SENDER: eso reintroduciria el bug de multi-tenancy
 * (firmar/consultar/anular con la identidad de Plantiflex para cualquier
 * tenant). Si el emisor no tiene certificado cargado, o el certificado no
 * tiene rut_sender resuelto (NULL o vacio), responde con error claro en vez
 * de emitir con una identidad equivocada.
 */
function resolverRutSender(PDO $pdo, string $rutEmisor, Ambiente $ambiente): string
{
    $stmt = $pdo->prepare(
        'SELECT rut_sender FROM dte_certificado WHERE rut_emisor = :rut AND ambiente = :amb LIMIT 1'
    );
    $stmt->execute([':rut' => $rutEmisor, ':amb' => $ambiente->value]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        responder(409, ['error' => 'El emisor no tiene certificado cargado']);
    }

    $rutSender = $row['rut_sender'];
    if ($rutSender === null || trim((string) $rutSender) === '') {
        responder(409, [
            'error' => 'El certificado del emisor no tiene RUT de firmante (sender). Vuelve a cargar el certificado.',
        ]);
    }

    return (string) $rutSender;
}

/** Valida RUT chileno con DV (modulo 11). */
function rutDvValido(string $rut): bool
{
    $rut = strtoupper(str_replace(['.', ' '], '', trim($rut)));
    if (! preg_match('/^(\d{7,8})-([\dK])$/', $rut, $m)) {
        return false;
    }
    [$num, $dv] = [$m[1], $m[2]];
    $suma = 0;
    $mul  = 2;
    for ($i = strlen($num) - 1; $i >= 0; $i--) {
        $suma += ((int) $num[$i]) * $mul;
        $mul = $mul === 7 ? 2 : $mul + 1;
    }
    $resto = 11 - ($suma % 11);
    $calc  = $resto === 11 ? '0' : ($resto === 10 ? 'K' : (string) $resto);

    return $calc === $dv;
}

/** Valida una fecha YYYY-MM-DD real (calendario). */
function validaFecha(string $f): bool
{
    if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) {
        return false;
    }
    [$y, $m, $d] = explode('-', $f);
    return checkdate((int) $m, (int) $d, (int) $y);
}

/**
 * Extrae montos y fecha del EnvioDTE serializado para la respuesta.
 *
 * @return array{neto:int, iva:int, total:int, fchEmis:string}
 */
function montosDeXml(string $xml): array
{
    $dom  = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $dom->loadXML($xml);
    libxml_use_internal_errors($prev);

    $get = static function (string $t) use ($dom): string {
        $n = $dom->getElementsByTagNameNS(NS_SII, $t)->item(0);
        return $n !== null ? trim($n->textContent) : '';
    };

    return [
        'neto'    => (int) ($get('MntNeto') ?: '0'),
        'iva'     => (int) ($get('IVA') ?: '0'),
        'total'   => (int) ($get('MntTotal') ?: '0'),
        'fchEmis' => $get('FchEmis'),
    ];
}

function pdo(): PDO
{
    return new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            getenv('DB_HOST') ?: '127.0.0.1',
            getenv('DB_PORT') ?: '3306',
            getenv('DB_NAME') ?: 'facturacion_cl',
        ),
        getenv('DB_USER') ?: '',
        getenv('DB_PASS') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

function crearFacturador(PDO $pdo): SiiDirectoFacturador
{
    $bin = @hex2bin(getenv('CRYPTO_MASTER_KEY') ?: '');
    if ($bin === false || strlen($bin) !== CertificadoCrypto::KEY_LENGTH) {
        responder(500, ['error' => 'CRYPTO_MASTER_KEY mal configurada en el servidor']);
    }
    $crypto = new CertificadoCrypto($bin);

    $certTls = __DIR__ . '/../fullchain.pem';
    $keyTls  = __DIR__ . '/../key.pem';
    foreach ([$certTls, $keyTls] as $ruta) {
        if (! is_file($ruta) || ! is_readable($ruta)) {
            responder(500, ['error' => 'Certificado TLS mutuo no disponible en el servidor']);
        }
    }

    return new SiiDirectoFacturador(
        new Client(['timeout' => 60, 'cert' => $certTls, 'ssl_key' => $keyTls, 'verify' => true]),
        new MySqlFolioRepository($pdo, fn (string $c): string => $crypto->descifrar($c), cryptoKek: $crypto),
        new MySqlEmisorRepository($pdo, $crypto),
        dteEmitido: new MySqlDteEmitidoRepository($pdo),
    );
}

/**
 * Fabrica paralela a crearFacturador(): arma BoletaFacturador (canal REST
 * pangal/rahue), NO SiiDirectoFacturador. Mismo patron que ya usa
 * scripts/emitir_boleta_real.php.
 */
function crearBoletaFacturador(PDO $pdo): BoletaFacturador
{
    $bin = @hex2bin(getenv('CRYPTO_MASTER_KEY') ?: '');
    if ($bin === false || strlen($bin) !== CertificadoCrypto::KEY_LENGTH) {
        responder(500, ['error' => 'CRYPTO_MASTER_KEY mal configurada en el servidor']);
    }
    $crypto = new CertificadoCrypto($bin);

    $certTls = __DIR__ . '/../fullchain.pem';
    $keyTls  = __DIR__ . '/../key.pem';
    foreach ([$certTls, $keyTls] as $ruta) {
        if (! is_file($ruta) || ! is_readable($ruta)) {
            responder(500, ['error' => 'Certificado TLS mutuo no disponible en el servidor']);
        }
    }

    return new BoletaFacturador(
        new Client(['timeout' => 60, 'cert' => $certTls, 'ssl_key' => $keyTls, 'verify' => true]),
        new MySqlFolioRepository($pdo, fn (string $c): string => $crypto->descifrar($c), cryptoKek: $crypto),
        new MySqlEmisorRepository($pdo, $crypto),
        dteEmitido: new MySqlDteEmitidoRepository($pdo),
    );
}

/**
 * Fabrica paralela a crearFacturador(): arma LibroService (envio de libros
 * IECV). Los libros no usan CAF ni folios, asi que NO lleva FolioRepository ni
 * DteEmitidoRepository: solo el cliente HTTP con mTLS y el repo de emisor (con
 * envelope decryption via CertificadoCrypto). Signer/builder/autenticador/
 * uploader los arma LibroService por defecto.
 */
function crearLibroService(PDO $pdo): LibroService
{
    $bin = @hex2bin(getenv('CRYPTO_MASTER_KEY') ?: '');
    if ($bin === false || strlen($bin) !== CertificadoCrypto::KEY_LENGTH) {
        responder(500, ['error' => 'CRYPTO_MASTER_KEY mal configurada en el servidor']);
    }
    $crypto = new CertificadoCrypto($bin);

    $certTls = __DIR__ . '/../fullchain.pem';
    $keyTls  = __DIR__ . '/../key.pem';
    foreach ([$certTls, $keyTls] as $ruta) {
        if (! is_file($ruta) || ! is_readable($ruta)) {
            responder(500, ['error' => 'Certificado TLS mutuo no disponible en el servidor']);
        }
    }

    return new LibroService(
        new Client(['timeout' => 60, 'cert' => $certTls, 'ssl_key' => $keyTls, 'verify' => true]),
        new MySqlEmisorRepository($pdo, $crypto),
    );
}

// ===========================================================================
//  RCV (interno) — helpers
// ===========================================================================
/**
 * Secreto del API interno del RCV. Se lee de ARCHIVO (no env) para rotarlo sin
 * recrear el contenedor. Debe existir /app/.rcv_internal_key (una sola linea).
 * (Este repo no usa git; si lo usara, agregar .rcv_internal_key a .gitignore.)
 */
function rcvSecreto(): string
{
    $ruta = '/app/.rcv_internal_key';
    if (! is_file($ruta) || ! is_readable($ruta)) {
        responder(500, ['error' => 'RCV: falta /app/.rcv_internal_key en el servidor']);
    }
    $secreto = trim((string) file_get_contents($ruta));
    if ($secreto === '') {
        responder(500, ['error' => 'RCV: /app/.rcv_internal_key esta vacio']);
    }
    return $secreto;
}

/** Wiring del RcvConsultor: Guzzle propio SIN mTLS (el RCV no usa el par TLS de emision). */
function crearRcvConsultor(): RcvConsultor
{
    return new RcvConsultor(
        new Client(['timeout' => 60]),
        new SiiAutenticador(new Client(['timeout' => 30]), new XmlSigner()),
    );
}

/** Certificado de la empresa consultada (su RUT con DV) desde dte_certificado (cifrado). */
function certOperador(PDO $pdo, string $rutEmpresaConDv): Certificado
{
    $bin = @hex2bin(getenv('CRYPTO_MASTER_KEY') ?: '');
    if ($bin === false || strlen($bin) !== CertificadoCrypto::KEY_LENGTH) {
        responder(500, ['error' => 'CRYPTO_MASTER_KEY mal configurada en el servidor']);
    }
    return (new MySqlEmisorRepository($pdo, new CertificadoCrypto($bin)))
        ->obtenerCertificado($rutEmpresaConDv, Ambiente::Produccion);
}

// ===========================================================================
//  Resolucion de tenant: reemplaza el middleware global X-Api-Key/FACT_API_KEY.
// ===========================================================================
/**
 * Resuelve el tenant autenticado a partir del header X-Api-Key.
 *
 * Formato esperado: "{prefijo}.{secreto}". El prefijo hace lookup rapido
 * (ix_apikey_prefijo); el secreto se valida en tiempo constante con
 * hash_equals contra key_hash (sha256). Mismo mensaje 401 generico para
 * TODOS los casos de fallo (header ausente/mal formado, prefijo inexistente,
 * key revocada, secreto incorrecto): no revela cual condicion fallo.
 *
 * cuenta_id/rut_emisor/ambiente salen de la fila de api_key, NUNCA del
 * cliente ni de una env var global.
 *
 * @return array{cuenta_id:int, rut_emisor:string, ambiente:string}
 */
function resolverTenant(PDO $pdo): array
{
    $apiKeyRecibida = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (! is_string($apiKeyRecibida) || $apiKeyRecibida === '') {
        responder(401, ['error' => 'no autorizado']);
    }

    $partes = explode('.', $apiKeyRecibida, 2);
    if (count($partes) !== 2 || $partes[0] === '' || $partes[1] === '') {
        responder(401, ['error' => 'no autorizado']);
    }
    [$prefijo, $secreto] = $partes;

    $stmt = $pdo->prepare(
        'SELECT id, cuenta_id, key_hash, rut_emisor_scope, ambiente, estado '
        . 'FROM api_key WHERE prefijo = :prefijo LIMIT 1'
    );
    $stmt->execute([':prefijo' => $prefijo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row === false || $row['estado'] !== 'activa') {
        responder(401, ['error' => 'no autorizado']);
    }

    if (! hash_equals((string) $row['key_hash'], hash('sha256', $secreto))) {
        responder(401, ['error' => 'no autorizado']);
    }

    // Best-effort: un fallo al marcar el uso no debe bloquear el request.
    try {
        $pdo->prepare('UPDATE api_key SET last_used_at = NOW() WHERE id = :id')
            ->execute([':id' => $row['id']]);
    } catch (Throwable $e) {
        // No abortar el request por esto.
    }

    return [
        'cuenta_id'  => (int) $row['cuenta_id'],
        'rut_emisor' => (string) $row['rut_emisor_scope'],
        'ambiente'   => (string) $row['ambiente'],
    ];
}

// ===========================================================================
//  RCV (interno): intercepta /api/v1/rcv/* ANTES del middleware X-Api-Key, con
//  su PROPIA clave (X-Rcv-Key vs /app/.rcv_internal_key). NO reusa FACT_API_KEY.
//  Solo afecta paths nuevos; cualquier otra ruta cae al flujo existente intacto.
// ===========================================================================
$rutaRcv = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
if (str_starts_with($rutaRcv, '/api/v1/rcv/')) {
    $rcvKey = $_SERVER['HTTP_X_RCV_KEY'] ?? '';
    if (! is_string($rcvKey) || ! hash_equals(rcvSecreto(), $rcvKey)) {
        responder(401, ['error' => 'no autorizado (rcv)']);
    }
    $metodoRcv = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($metodoRcv === 'GET' && $rutaRcv === '/api/v1/rcv/resumen') {
        rcvResumen();
    }
    if ($metodoRcv === 'GET' && $rutaRcv === '/api/v1/rcv/detalle') {
        rcvDetalleCsv();
    }
    responder(404, ['error' => 'ruta rcv no encontrada', 'ruta' => $rutaRcv]);
}

// ===========================================================================
//  Router: metodo y ruta
// ===========================================================================
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ruta   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$ruta   = rtrim($ruta, '/');
if ($ruta === '') {
    $ruta = '/';
}

// /health es publica: no requiere api_key (se resuelve ANTES de resolverTenant()).
if ($metodo === 'GET' && $ruta === '/health') {
    responder(200, ['status' => 'ok', 'tz' => date_default_timezone_get(), 'now' => date('c')]);
}

// ===========================================================================
//  Resolucion de tenant obligatoria (reemplaza el middleware global X-Api-Key
//  contra FACT_API_KEY). Todas las rutas de aqui en adelante requieren una
//  api_key valida; $tenant trae cuenta_id/rut_emisor/ambiente ya resueltos.
// ===========================================================================
$tenant = resolverTenant(pdo());

if ($metodo === 'POST' && $ruta === '/api/v1/dte') {
    emitirDte($tenant);
}

if ($metodo === 'POST' && $ruta === '/api/v1/dte/lote') {
    emitirDteLote($tenant);
}

if ($metodo === 'POST' && $ruta === '/api/v1/boleta') {
    emitirBoleta($tenant);
}

if ($metodo === 'POST' && $ruta === '/api/v1/libro') {
    enviarLibroIecv($tenant);
}

if ($metodo === 'GET' && preg_match('#^/api/v1/libro/([A-Za-z0-9]+)/estado-sii$#', $ruta, $mlt)) {
    consultarEstadoSiiLibro($tenant, $mlt[1]);
}

if ($metodo === 'GET' && preg_match('#^/api/v1/boleta/(\d+)/estado$#', $ruta, $mb)) {
    consultarEstadoBoleta($tenant, (int) $mb[1]);
}

if ($metodo === 'GET' && $ruta === '/api/v1/dte') {
    listarDte($tenant);
}

if ($metodo === 'GET' && preg_match('#^/api/v1/dte/(\d+)/(\d+)/estado$#', $ruta, $mr)) {
    consultarEstadoDte($tenant, (int) $mr[1], (int) $mr[2]);
}

if ($metodo === 'GET' && preg_match('#^/api/v1/dte/(\d+)/(\d+)/estado-sii$#', $ruta, $mes)) {
    consultarEstadoSiiDte($tenant, (int) $mes[1], (int) $mes[2]);
}

if ($metodo === 'GET' && preg_match('#^/api/v1/dte/(\d+)/(\d+)/pdf$#', $ruta, $mp)) {
    pdfDte($tenant, (int) $mp[1], (int) $mp[2]);
}

if ($metodo === 'GET' && preg_match('#^/api/v1/dte/(\d+)/(\d+)/xml$#', $ruta, $mx)) {
    xmlDte($tenant, (int) $mx[1], (int) $mx[2]);
}

if ($metodo === 'POST' && preg_match('#^/api/v1/dte/(\d+)/(\d+)/anular$#', $ruta, $ma)) {
    anularDte($tenant, (int) $ma[1], (int) $ma[2]);
}

responder(404, ['error' => 'ruta no encontrada', 'metodo' => $metodo, 'ruta' => $ruta]);

// ===========================================================================
//  Validacion compartida de UN documento DTE (forma del body de POST /api/v1/dte)
//
//  Extraida de emitirDte() como refactor puro (mismas reglas, mismos mensajes,
//  mismos nombres de campo cuando $prefijoCampo = ''). La usa tambien
//  emitirDteLote(), que antepone "documentos[i]." para senalar el indice del
//  documento invalido. NO consume folio ni toca el SII: solo valida.
//
//  $enLote habilita las referencias por indice del lote (refIndiceLote): solo
//  tienen sentido en /api/v1/dte/lote, y alli una refIndiceLote CUENTA como
//  referencia madre valida de NC/ND (apunta a otro DTE del mismo set). En el
//  endpoint unitario se rechazan con error claro en vez de ignorarse.
//
//  @return array{tipoDte:int, receptor:array, detalles:array, montosSonBrutos:bool, referencias:array, descuentoGlobalPct:?float}
// ===========================================================================
function validarDocumentoDte(array $body, string $prefijoCampo = '', bool $enLote = false): array
{
    $p = $prefijoCampo;

    $tipoDte = $body['tipoDte'] ?? null;
    if (! is_int($tipoDte) || ! in_array($tipoDte, TIPOS_PERMITIDOS, true)) {
        invalido("{$p}tipoDte debe ser uno de 33, 34, 61, 56", "{$p}tipoDte");
    }

    $r = $body['receptor'] ?? null;
    if (! is_array($r)) {
        invalido("{$p}receptor es obligatorio", "{$p}receptor");
    }
    foreach (['rut', 'razonSocial', 'giro', 'direccion', 'comuna'] as $campo) {
        if (! isset($r[$campo]) || ! is_string($r[$campo]) || trim($r[$campo]) === '') {
            invalido("{$p}receptor.{$campo} es obligatorio", "{$p}receptor.{$campo}");
        }
    }
    if (! rutDvValido($r['rut'])) {
        invalido("{$p}receptor.rut tiene DV invalido", "{$p}receptor.rut");
    }

    $detalles = $body['detalles'] ?? null;
    if (! is_array($detalles) || $detalles === []) {
        invalido("{$p}detalles debe tener al menos 1 item", "{$p}detalles");
    }
    foreach ($detalles as $i => $d) {
        if (! is_array($d) || ! isset($d['nombre']) || ! is_string($d['nombre']) || trim($d['nombre']) === '') {
            invalido("{$p}detalles[{$i}].nombre es obligatorio", "{$p}detalles[{$i}].nombre");
        }
        if (! isset($d['cantidad']) || ! is_numeric($d['cantidad']) || (float) $d['cantidad'] <= 0) {
            invalido("{$p}detalles[{$i}].cantidad debe ser > 0", "{$p}detalles[{$i}].cantidad");
        }
        if (! isset($d['precioUnitario']) || ! is_numeric($d['precioUnitario']) || (float) $d['precioUnitario'] < 0) {
            invalido("{$p}detalles[{$i}].precioUnitario debe ser >= 0", "{$p}detalles[{$i}].precioUnitario");
        }
        // Opcionales que el set basico del SII exige poder expresar: item
        // exento (casos con "SERVICIO EXENTO") y descuento por linea.
        if (isset($d['exento']) && ! is_bool($d['exento'])) {
            invalido("{$p}detalles[{$i}].exento debe ser booleano", "{$p}detalles[{$i}].exento");
        }
        if (
            isset($d['descuentoPorcentaje'])
            && (! is_numeric($d['descuentoPorcentaje']) || (float) $d['descuentoPorcentaje'] < 0 || (float) $d['descuentoPorcentaje'] > 100)
        ) {
            invalido("{$p}detalles[{$i}].descuentoPorcentaje debe ser numerico entre 0 y 100", "{$p}detalles[{$i}].descuentoPorcentaje");
        }
        // --- UN TIPO 34 NO PUEDE LLEVAR NI UNA LINEA AFECTA ---
        //
        // POR QUE ESTA VALIDACION EXISTE, Y POR QUE AQUI: resolverTotales() del
        // builder decide POR DATOS, no por tipo -- emite MntNeto, TasaIVA e IVA
        // en cuanto hay un solo peso afecto (src/Sii/DteXmlBuilder.php:171-205).
        // Eso es correcto para un 33 con items exentos, pero en un 34 produciria
        // un documento con IVA dentro de una factura que por definicion no lo
        // tiene: el SII lo rechaza Y EL FOLIO QUEDA QUEMADO IGUAL, porque se
        // asigna antes de enviar.
        //
        // Se valida ANTES de asignar folio y antes de tocar el SII, que es el
        // contrato de esta funcion. El formulario del panel ademas fuerza todas
        // las lineas como exentas, pero eso es comodidad para el usuario: la
        // regla vive AQUI porque al cliente no se le cree nunca.
        if ($tipoDte === 34 && empty($d['exento'])) {
            invalido(
                "{$p}detalles[{$i}]: una factura exenta (tipo 34) no puede tener lineas afectas; "
                . 'marca exento=true en todas',
                "{$p}detalles[{$i}].exento",
            );
        }
    }

    // Descuento global % sobre items afectos (DscRcgGlobal), opcional. El set
    // basico lo exige (ej. "DESCUENTO GLOBAL ITEMES AFECTOS 29%").
    $descuentoGlobalPct = $body['descuentoGlobalPct'] ?? null;
    if (
        $descuentoGlobalPct !== null
        && (! is_numeric($descuentoGlobalPct) || (float) $descuentoGlobalPct <= 0 || (float) $descuentoGlobalPct > 100)
    ) {
        invalido("{$p}descuentoGlobalPct debe ser numerico entre 0 (exclusivo) y 100", "{$p}descuentoGlobalPct");
    }

    $montosSonBrutos = (bool) ($body['montosSonBrutos'] ?? false);
    $referencias     = is_array($body['referencias'] ?? null) ? $body['referencias'] : [];

    // refIndiceLote solo existe en el endpoint de lote; en el unitario se
    // rechaza explicito (antes se habria ignorado y el XML saldria sin
    // TpoDocRef/FolioRef, basura silenciosa).
    if (! $enLote) {
        foreach ($referencias as $j => $ref) {
            if (is_array($ref) && array_key_exists('refIndiceLote', $ref)) {
                invalido(
                    "{$p}referencias[{$j}].refIndiceLote solo esta soportado en POST /api/v1/dte/lote",
                    "{$p}referencias[{$j}].refIndiceLote",
                );
            }
        }
    }

    // --- NC (61) y ND (56) EXIGEN al menos una referencia a un DTE valido ---
    // (SII REF-3-415). Se valida ANTES de armar el DTO/asignar folio/llamar al SII,
    // para NO quemar folio. TpoDocRef debe ser un tipo DTE numerico valido (no "SET"):
    // esta regla solo EXIGE la referencia madre; referencias ADICIONALES con
    // TpoDocRef no numerico (ej. "SET", requerida por el set de certificacion)
    // conviven sin problema y se pasan al builder en el orden recibido.
    if (in_array($tipoDte, [61, 56], true)) {
        $tiposDteRef = [29, 30, 32, 33, 34, 35, 38, 39, 40, 41, 43, 45, 46, 48, 50, 52, 55, 56, 60, 61, 103, 110, 111, 112];
        $tieneRefValida = false;
        foreach ($referencias as $ref) {
            if (! is_array($ref)) {
                continue;
            }
            // En lote, una referencia por indice apunta a otro DTE (33/61/56)
            // del MISMO envio: cuenta como referencia madre valida. Sus limites
            // (rango, no auto-referencia, indice anterior) los valida el
            // handler del lote, que conoce el contexto.
            if ($enLote && array_key_exists('refIndiceLote', $ref)) {
                $tieneRefValida = true;
                break;
            }
            $tipoRef  = $ref['tipoDocumento'] ?? null;
            $folioRef = $ref['folio'] ?? null;
            if (
                is_numeric($tipoRef) && in_array((int) $tipoRef, $tiposDteRef, true)
                && is_numeric($folioRef) && (int) $folioRef > 0
            ) {
                $tieneRefValida = true;
                break;
            }
        }
        if (! $tieneRefValida) {
            invalido(
                'NC/ND requiere al menos una referencia a un documento tributario valido (TpoDocRef numerico valido, FolioRef > 0, mas FchRef/CodRef/RazonRef)',
                "{$p}referencias",
            );
        }
    }

    return [
        'tipoDte'            => $tipoDte,
        'receptor'           => $r,
        'detalles'           => $detalles,
        'montosSonBrutos'    => $montosSonBrutos,
        'referencias'        => $referencias,
        'descuentoGlobalPct' => $descuentoGlobalPct !== null ? (float) $descuentoGlobalPct : null,
    ];
}

// ===========================================================================
//  Handler: POST /api/v1/dte
// ===========================================================================
function emitirDte(array $tenant): never
{
    $crudo = file_get_contents('php://input') ?: '';
    $body  = json_decode($crudo, true);
    if (! is_array($body)) {
        invalido('JSON invalido o vacio', 'body');
    }

    // --- Validacion ANTES de tocar el SII (no consume folio) ---
    $v       = validarDocumentoDte($body);
    $tipoDte = $v['tipoDte'];
    $r       = $v['receptor'];

    // --- Armar DTOs ---
    $doc = new DocumentoTributario(
        tipoDte:         TipoDte::from($tipoDte),
        receptor:        new Receptor(
            rut: $r['rut'],
            razonSocial: $r['razonSocial'],
            giro: $r['giro'],
            direccion: $r['direccion'],
            comuna: $r['comuna'],
        ),
        detalles:        array_map(
            static fn (array $d): Detalle => new Detalle(
                $d['nombre'],
                (float) $d['cantidad'],
                (float) $d['precioUnitario'],
                exento: (bool) ($d['exento'] ?? false),
                descuentoPorcentaje: (float) ($d['descuentoPorcentaje'] ?? 0),
            ),
            $v['detalles'],
        ),
        montosSonBrutos: $v['montosSonBrutos'],
        referencias:     $v['referencias'],
        descuentoGlobalPct: $v['descuentoGlobalPct'],
    );

    // --- Ambiente y emisor: SOLO del tenant autenticado ---
    $ambiente = ambienteDesdeTenant($tenant);
    $pdo      = pdo();

    // --- Idempotencia opcional por (rut_emisor, ambiente, Idempotency-Key) ---
    //
    // El rut_emisor sale del tenant autenticado, NUNCA del payload: es parte de
    // la PK de dte_idempotencia (migracion 001) y lo que impide que dos cuentas
    // que usen la misma Idempotency-Key se pisen entre si.
    $clave = trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
    $idem  = $clave !== '' ? new MySqlIdempotenciaRepository($pdo) : null;
    if ($idem !== null && ! $idem->reclamar($tenant['rut_emisor'], $ambiente, $clave)) {
        // La clave ya existe para este emisor en este ambiente.
        $previo = $idem->obtener($tenant['rut_emisor'], $ambiente, $clave);
        if ($previo !== null && $previo['folio'] !== null) {
            // Camino REPETIDO: devolver el resultado guardado SIN tocar el SII ni consumir folio.
            header('Idempotent-Replay: true');
            responder($previo['httpStatus'] ?? 201, json_decode((string) $previo['respuestaJson'], true));
        }
        // Sin folio: emision en curso, o claim muerto (servidor caido a mitad).
        if (! $idem->reactivarSiMuerto($tenant['rut_emisor'], $ambiente, $clave, IDEMPOTENCIA_TTL_SEGUNDOS)) {
            responder(409, ['error' => 'solicitud en proceso']);
        }
        // Claim reactivado por TTL: continuamos a emitir.
    }

    $cred = new Credenciales(
        rutEmisor: $tenant['rut_emisor'],
        apiToken:  'no-usado-por-sii-directo',
        ambiente:  $ambiente,
        rutSender: resolverRutSender($pdo, $tenant['rut_emisor'], $ambiente),
    );

    try {
        $res = crearFacturador($pdo)->emitir($doc, $cred);
    } catch (SiiAutenticacionException $e) {
        responder(502, ['error' => 'fallo de autenticacion con el SII', 'estadoSii' => $e->estadoSii, 'glosaSii' => $e->glosaSii]);
    } catch (EnvioRechazadoException $e) {
        responder(502, ['error' => 'el SII rechazo el envio', 'status' => $e->status, 'trackId' => $e->trackId]);
    } catch (Throwable $e) {
        responder(500, ['error' => 'fallo la emision', 'detalle' => $e->getMessage()]);
    }

    $m       = montosDeXml((string) $res->xml);
    $payload = [
        'folio'   => $res->folio,
        'tipoDte' => $res->tipoDte->value,
        'estado'  => $res->estado,
        'trackId' => $res->trackId,
        'fchEmis' => $m['fchEmis'],
        'neto'    => $m['neto'],
        'iva'     => $m['iva'],
        'total'   => $m['total'],
    ];

    // Guardar el resultado para reintentos con la misma clave (at-most-once).
    if ($idem !== null) {
        $idem->completar(
            $tenant['rut_emisor'],
            $ambiente,
            $clave,
            $res->tipoDte->value,
            (int) $res->folio,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            201,
        );
    }

    responder(201, $payload);
}

// ===========================================================================
//  Handler: POST /api/v1/dte/lote
//
//  Emite varios DTE (incluso de tipos distintos) en UN solo EnvioDTE, como
//  exige el SII para el SET BASICO de certificacion (8 documentos juntos).
//  Reusa SiiDirectoFacturador::emitirLote() y la MISMA validacion por
//  documento de emitirDte() (validarDocumentoDte()).
//
//  Folios: emitirLote() NO los asigna (exige doc->folio fijado, ver
//  SiiDirectoFacturador linea ~656), asi que se asignan AQUI con
//  asignarSiguienteFolio() -- mismo patron que scripts/emitir_set_basico_lote.php
//  -- y SOLO despues de validar el lote completo: si la validacion falla no se
//  quema ningun folio. El orden del array recibido se preserva (el SII procesa
//  el set en orden), igual que el orden de las referencias de cada documento
//  (NroLinRef = posicion en el payload; en NC/ND del set, la referencia "SET"
//  va primera y la madre segunda, tal como las mande el cliente). Para
//  TpoDocRef="SET" el builder pone FolioRef = folio propio automaticamente
//  (DteXmlBuilder::buildReferencia()).
//
//  Referencias intra-lote (refIndiceLote): el cliente NO puede conocer los
//  folios que este mismo request asigna, asi que una referencia madre a otro
//  documento DEL MISMO LOTE se declara por posicion ({"refIndiceLote": 0, ...})
//  y el servidor la resuelve a TpoDocRef/FolioRef/FchRef reales despues de
//  asignar folios. La referencia por tipoDocumento+folio explicitos sigue
//  funcionando igual (documentos de OTRO envio).
//
//  Persistencia: emitirLote() persiste cada documento en dte_emitido (envio
//  aceptado por el SII; best-effort), asi que aparecen en GET /api/v1/dte, en
//  la estacion 5 del panel y quedan disponibles para armar el Libro de Ventas.
// ===========================================================================
function emitirDteLote(array $tenant): never
{
    $crudo = file_get_contents('php://input') ?: '';
    $body  = json_decode($crudo, true);
    if (! is_array($body)) {
        invalido('JSON invalido o vacio', 'body');
    }

    $documentos = $body['documentos'] ?? null;
    if (! is_array($documentos) || $documentos === []) {
        invalido('documentos debe tener al menos 1 item', 'documentos');
    }

    // --- Validar TODO el lote ANTES de asignar folios o tocar el SII ---
    $totalDocs = count($documentos);
    $validados = [];
    foreach (array_values($documentos) as $i => $d) {
        if (! is_array($d)) {
            invalido("documentos[{$i}] debe ser un objeto", "documentos[{$i}]");
        }
        $v = validarDocumentoDte($d, "documentos[{$i}].", enLote: true);

        // Limites de las referencias intra-lote: entero, dentro del lote, sin
        // auto-referencia y SOLO hacia un documento ANTERIOR (no se puede
        // referenciar uno que aun no existe en el set).
        foreach ($v['referencias'] as $j => $ref) {
            if (! is_array($ref) || ! array_key_exists('refIndiceLote', $ref)) {
                continue;
            }
            $campo = "documentos[{$i}].referencias[{$j}].refIndiceLote";
            if (isset($ref['tipoDocumento']) || isset($ref['folio'])) {
                invalido("{$campo} no puede combinarse con tipoDocumento/folio en la misma referencia", $campo);
            }
            $k = $ref['refIndiceLote'];
            if (! is_int($k) || $k < 0) {
                invalido("{$campo} debe ser un entero >= 0", $campo);
            }
            if ($k >= $totalDocs) {
                invalido("{$campo} apunta fuera del lote (hay {$totalDocs} documentos)", $campo);
            }
            if ($k === $i) {
                invalido("{$campo} no puede referenciar al propio documento", $campo);
            }
            if ($k > $i) {
                invalido("{$campo} debe apuntar a un documento ANTERIOR del lote", $campo);
            }
        }

        $validados[] = $v;
    }

    // --- Emisor, ambiente y sender: SOLO del tenant autenticado ---
    $pdo      = pdo();
    $ambiente = ambienteDesdeTenant($tenant);

    $cred = new Credenciales(
        rutEmisor: $tenant['rut_emisor'],
        apiToken:  'no-usado-por-sii-directo',
        ambiente:  $ambiente,
        rutSender: resolverRutSender($pdo, $tenant['rut_emisor'], $ambiente),
    );

    // Repo de folios propio (crearFacturador() no lo expone): mismo cableado.
    $bin = @hex2bin(getenv('CRYPTO_MASTER_KEY') ?: '');
    if ($bin === false || strlen($bin) !== CertificadoCrypto::KEY_LENGTH) {
        responder(500, ['error' => 'CRYPTO_MASTER_KEY mal configurada en el servidor']);
    }
    $crypto = new CertificadoCrypto($bin);
    $folios = new MySqlFolioRepository($pdo, fn (string $c): string => $crypto->descifrar($c), cryptoKek: $crypto);

    try {
        // Asignar folios y armar los DTOs EN EL MISMO ORDEN del array recibido.
        // Fecha unica para todo el lote: la FchEmis de cada documento y la
        // FchRef de las referencias intra-lote deben coincidir.
        $fechaStr     = date('Y-m-d');
        $fechaEmision = new DateTimeImmutable($fechaStr);

        $docs     = [];
        $emitidos = [];
        foreach ($validados as $v) {
            $tipo  = TipoDte::from($v['tipoDte']);
            $folio = $folios->asignarSiguienteFolio($tenant['rut_emisor'], $tipo, $ambiente);
            $r     = $v['receptor'];

            // Resolver referencias intra-lote: refIndiceLote -> TpoDocRef/
            // FolioRef/FchRef reales del documento referenciado (siempre uno
            // ANTERIOR, ya validado, asi que su folio ya esta en $emitidos).
            // El orden del array se preserva tal cual (NroLinRef = posicion).
            $referencias = [];
            foreach ($v['referencias'] as $ref) {
                if (is_array($ref) && array_key_exists('refIndiceLote', $ref)) {
                    $k = $ref['refIndiceLote'];
                    unset($ref['refIndiceLote']);
                    $ref['tipoDocumento'] = (string) $emitidos[$k]['tipoDte'];
                    $ref['folio']         = $emitidos[$k]['folio'];
                    $ref['fecha']         = $fechaStr;
                }
                $referencias[] = $ref;
            }

            $docs[] = new DocumentoTributario(
                tipoDte:         $tipo,
                receptor:        new Receptor(
                    rut: $r['rut'],
                    razonSocial: $r['razonSocial'],
                    giro: $r['giro'],
                    direccion: $r['direccion'],
                    comuna: $r['comuna'],
                ),
                detalles:        array_map(
                    static fn (array $d): Detalle => new Detalle(
                        $d['nombre'],
                        (float) $d['cantidad'],
                        (float) $d['precioUnitario'],
                        exento: (bool) ($d['exento'] ?? false),
                        descuentoPorcentaje: (float) ($d['descuentoPorcentaje'] ?? 0),
                    ),
                    $v['detalles'],
                ),
                montosSonBrutos: $v['montosSonBrutos'],
                folio:           $folio,
                fechaEmision:    $fechaEmision,
                referencias:     $referencias,
                descuentoGlobalPct: $v['descuentoGlobalPct'],
            );
            $emitidos[] = ['tipoDte' => $v['tipoDte'], 'folio' => $folio];
        }

        $res = crearFacturador($pdo)->emitirLote($docs, $cred);
    } catch (SiiAutenticacionException $e) {
        responder(502, ['error' => 'fallo de autenticacion con el SII', 'estadoSii' => $e->estadoSii, 'glosaSii' => $e->glosaSii]);
    } catch (EnvioRechazadoException $e) {
        responder(502, ['error' => 'el SII rechazo el envio', 'status' => $e->status, 'trackId' => $e->trackId]);
    } catch (Throwable $e) {
        responder(502, ['error' => 'fallo la emision del lote', 'detalle' => $e->getMessage()]);
    }

    responder(201, [
        'trackId'    => $res['trackId'],
        'documentos' => $emitidos,
    ]);
}

// ===========================================================================
//  Handler: POST /api/v1/boleta
//
//  Paralelo a emitirDte(), NO comparte codigo con ella. Receptor de boleta
//  SOLO exige rut+razonSocial (sin giro/direccion/comuna); sin guard de
//  referencias (eso es exclusivo de NC/ND). Usa crearBoletaFacturador()
//  (BoletaFacturador / canal REST), nunca crearFacturador()/SiiDirectoFacturador.
// ===========================================================================
function emitirBoleta(array $tenant): never
{
    $crudo = file_get_contents('php://input') ?: '';
    $body  = json_decode($crudo, true);
    if (! is_array($body)) {
        invalido('JSON invalido o vacio', 'body');
    }

    $r = $body['receptor'] ?? null;
    if (! is_array($r)) {
        invalido('receptor es obligatorio', 'receptor');
    }
    foreach (['rut', 'razonSocial'] as $campo) {
        if (! isset($r[$campo]) || ! is_string($r[$campo]) || trim($r[$campo]) === '') {
            invalido("receptor.{$campo} es obligatorio", "receptor.{$campo}");
        }
    }
    if (! rutDvValido($r['rut'])) {
        invalido('receptor.rut tiene DV invalido', 'receptor.rut');
    }

    $detalles = $body['detalles'] ?? null;
    if (! is_array($detalles) || $detalles === []) {
        invalido('detalles debe tener al menos 1 item', 'detalles');
    }
    foreach ($detalles as $i => $d) {
        if (! is_array($d) || ! isset($d['nombre']) || ! is_string($d['nombre']) || trim($d['nombre']) === '') {
            invalido("detalles[{$i}].nombre es obligatorio", "detalles[{$i}].nombre");
        }
        if (! isset($d['cantidad']) || ! is_numeric($d['cantidad']) || (float) $d['cantidad'] <= 0) {
            invalido("detalles[{$i}].cantidad debe ser > 0", "detalles[{$i}].cantidad");
        }
        if (! isset($d['precioUnitario']) || ! is_numeric($d['precioUnitario']) || (float) $d['precioUnitario'] < 0) {
            invalido("detalles[{$i}].precioUnitario debe ser >= 0", "detalles[{$i}].precioUnitario");
        }
    }

    $montosSonBrutos = (bool) ($body['montosSonBrutos'] ?? false);

    // Receptor de boleta: SOLO rut+razonSocial. giro/direccion/comuna quedan
    // null (no aplican a boleta, ver fix de persistencia/PDF de hoy).
    $doc = new DocumentoTributario(
        tipoDte: TipoDte::BoletaElectronica,
        receptor: new Receptor(
            rut: $r['rut'],
            razonSocial: $r['razonSocial'],
        ),
        detalles: array_map(
            static fn (array $d): Detalle => new Detalle(
                $d['nombre'],
                (float) $d['cantidad'],
                (float) $d['precioUnitario'],
            ),
            $detalles,
        ),
        montosSonBrutos: $montosSonBrutos,
    );

    $ambiente = ambienteDesdeTenant($tenant);
    $pdo      = pdo();

    $cred = new Credenciales(
        rutEmisor: $tenant['rut_emisor'],
        apiToken:  'no-usado-por-sii-directo',
        ambiente:  $ambiente,
        rutSender: resolverRutSender($pdo, $tenant['rut_emisor'], $ambiente),
    );

    try {
        $res = crearBoletaFacturador($pdo)->emitir($doc, $cred);
    } catch (SiiAutenticacionException $e) {
        responder(502, ['error' => 'fallo de autenticacion con el SII', 'estadoSii' => $e->estadoSii, 'glosaSii' => $e->glosaSii]);
    } catch (EnvioRechazadoException $e) {
        responder(502, ['error' => 'el SII rechazo el envio', 'status' => $e->status, 'trackId' => $e->trackId]);
    } catch (Throwable $e) {
        responder(500, ['error' => 'fallo la emision', 'detalle' => $e->getMessage()]);
    }

    $m       = montosDeXml((string) $res->xml);
    $payload = [
        'folio'   => $res->folio,
        'tipoDte' => $res->tipoDte->value,
        'estado'  => $res->estado,
        'trackId' => $res->trackId,
        'fchEmis' => $m['fchEmis'],
        'neto'    => $m['neto'],
        'iva'     => $m['iva'],
        'total'   => $m['total'],
    ];

    responder(201, $payload);
}

// ===========================================================================
//  Handler: GET /api/v1/boleta/{folio}/estado
//
//  Boleta NO tiene getEstDte (SOAP) como factura: se sincroniza consultando el
//  ENVIO por trackId (BoletaConsultor, REST) y actualizando dte_emitido. Misma
//  logica que scripts/actualizar_estado_boleta.php. NO usa crearFacturador()/
//  SiiDirectoFacturador/consultarEstadoDte().
// ===========================================================================
function consultarEstadoBoleta(array $tenant, int $folio): never
{
    if ($folio <= 0) {
        invalido('folio debe ser > 0', 'folio');
    }

    $rutEmisor = $tenant['rut_emisor'];
    $ambiente  = ambienteDesdeTenant($tenant);
    $pdo       = pdo();

    $dteEmitido = new MySqlDteEmitidoRepository($pdo);

    $stmt = $pdo->prepare(
        'SELECT track_id, estado FROM dte_emitido '
        . 'WHERE rut_emisor = :rut AND ambiente = :amb AND tipo_dte = 39 AND folio = :folio LIMIT 1'
    );
    $stmt->execute([':rut' => $rutEmisor, ':amb' => $ambiente->value, ':folio' => $folio]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        responder(404, ['error' => 'folio no encontrado']);
    }
    $trackId = $row['track_id'];
    if ($trackId === null || $trackId === '') {
        responder(409, ['error' => 'boleta sin track_id registrado, no se puede consultar']);
    }

    $certTls = __DIR__ . '/../fullchain.pem';
    $keyTls  = __DIR__ . '/../key.pem';
    foreach ([$certTls, $keyTls] as $ruta) {
        if (! is_file($ruta) || ! is_readable($ruta)) {
            responder(500, ['error' => 'Certificado TLS mutuo no disponible en el servidor']);
        }
    }

    $bin = @hex2bin(getenv('CRYPTO_MASTER_KEY') ?: '');
    if ($bin === false || strlen($bin) !== CertificadoCrypto::KEY_LENGTH) {
        responder(500, ['error' => 'CRYPTO_MASTER_KEY mal configurada en el servidor']);
    }
    $crypto = new CertificadoCrypto($bin);
    $emisor = new MySqlEmisorRepository($pdo, $crypto);
    $http   = new Client(['timeout' => 60, 'cert' => $certTls, 'ssl_key' => $keyTls, 'verify' => true]);

    try {
        $cert  = $emisor->obtenerCertificado($rutEmisor, $ambiente);
        $token = (new BoletaAutenticador($http, new XmlSigner()))->obtenerToken($cert, $ambiente);
        $res   = (new BoletaConsultor($http))->consultarEnvio($rutEmisor, $trackId, $token, $ambiente);
    } catch (SiiAutenticacionException $e) {
        responder(502, ['error' => 'fallo de autenticacion con el SII', 'estadoSii' => $e->estadoSii, 'glosaSii' => $e->glosaSii]);
    } catch (Throwable $e) {
        responder(500, ['error' => 'fallo la consulta', 'detalle' => $e->getMessage()]);
    }

    $estadoNuevo = $res['estado'];
    $dteEmitido->actualizarEstado($rutEmisor, $ambiente, 39, $folio, $estadoNuevo);

    responder(200, [
        'tipoDte'        => 39,
        'folio'          => $folio,
        'trackId'        => $trackId,
        'estadoAnterior' => $row['estado'],
        'estado'         => $estadoNuevo,
        'informados'     => $res['informados'],
        'aceptados'      => $res['aceptados'],
        'rechazados'     => $res['rechazados'],
        'reparos'        => $res['reparos'],
        'errores'        => $res['errores'],
    ]);
}

// ===========================================================================
//  Handler: GET /api/v1/dte  (listado/historico por periodo)
// ===========================================================================
function listarDte(array $tenant): never
{
    $rutEmisor = $tenant['rut_emisor'];
    $ambiente  = ambienteDesdeTenant($tenant);

    $tipoDte = null;
    if (isset($_GET['tipoDte']) && $_GET['tipoDte'] !== '') {
        $tipoDte = (int) $_GET['tipoDte'];
        if (! in_array($tipoDte, TIPOS_PERMITIDOS_LISTADO, true)) {
            invalido('tipoDte debe ser 33, 34, 61, 56 o 39', 'tipoDte');
        }
    }

    // folio: filtro por numero de documento. Solo es unico DENTRO de un tipo,
    // asi que sin tipoDte puede devolver mas de una fila (uso normal del
    // listado del panel, M5); con tipoDte devuelve como maximo 1 (uso del
    // detalle del panel, que siempre manda ambos). NO exige rango de fechas:
    // un documento puede ser de cualquier periodo pasado, no solo del mes
    // actual.
    $folio = null;
    if (isset($_GET['folio']) && $_GET['folio'] !== '') {
        $folio = (int) $_GET['folio'];
        if ($folio <= 0) {
            invalido('folio debe ser > 0', 'folio');
        }
    }

    $periodo = trim((string) ($_GET['periodo'] ?? ''));
    $desde   = trim((string) ($_GET['desde'] ?? ''));
    $hasta   = trim((string) ($_GET['hasta'] ?? ''));

    if ($periodo !== '') {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo)) {
            invalido('periodo debe ser YYYY-MM', 'periodo');
        }
        $desde = $periodo . '-01';
        $hasta = date('Y-m-t', (int) strtotime($desde));
    } elseif ($desde !== '' || $hasta !== '') {
        if ($desde === '' || $hasta === '') {
            invalido('desde y hasta deben ir juntos', 'desde/hasta');
        }
        if (! validaFecha($desde)) {
            invalido('desde debe ser YYYY-MM-DD', 'desde');
        }
        if (! validaFecha($hasta)) {
            invalido('hasta debe ser YYYY-MM-DD', 'hasta');
        }
        if ($desde > $hasta) {
            invalido('desde no puede ser mayor que hasta', 'desde');
        }
        $periodo = null;
    } elseif ($folio !== null) {
        // Busqueda puntual sin rango: no default al mes actual.
        $periodo = null;
        $desde   = null;
        $hasta   = null;
    } else {
        $periodo = date('Y-m');
        $desde   = date('Y-m-01');
        $hasta   = date('Y-m-t');
    }

    // receptorRut: busqueda parcial (LIKE). estado: igualdad exacta (sin
    // catalogo cerrado, ver filtroPeriodo()). Ambos opcionales, para el
    // listado del panel (M5).
    $receptorRut = trim((string) ($_GET['receptorRut'] ?? ''));
    $receptorRut = $receptorRut !== '' ? $receptorRut : null;
    $estado      = trim((string) ($_GET['estado'] ?? ''));
    $estado      = $estado !== '' ? $estado : null;

    $limit  = (int) ($_GET['limit'] ?? 50);
    $offset = (int) ($_GET['offset'] ?? 0);
    if ($limit < 1 || $limit > 200) {
        invalido('limit debe estar entre 1 y 200', 'limit');
    }
    if ($offset < 0) {
        invalido('offset no puede ser negativo', 'offset');
    }

    try {
        $repo    = new MySqlDteEmitidoRepository(pdo());
        $items   = $repo->listarPorPeriodo($rutEmisor, $ambiente, $desde, $hasta, $tipoDte, $limit, $offset, $folio, $receptorRut, $estado);
        $total   = $repo->contarPorPeriodo($rutEmisor, $ambiente, $desde, $hasta, $tipoDte, $folio, $receptorRut, $estado);
        $resumen = $repo->resumenPorPeriodo($rutEmisor, $ambiente, $desde, $hasta, $tipoDte, $folio, $receptorRut, $estado);
    } catch (Throwable $e) {
        responder(500, ['error' => 'fallo el listado', 'detalle' => $e->getMessage()]);
    }

    responder(200, [
        'periodo' => $periodo,
        'desde'   => $desde,
        'hasta'   => $hasta,
        'total'   => $total,
        'limit'   => $limit,
        'offset'  => $offset,
        'resumen' => $resumen,
        'items'   => $items,
    ]);
}

// ===========================================================================
//  Handler: GET /api/v1/dte/{tipoDte}/{folio}/estado
// ===========================================================================
function consultarEstadoDte(array $tenant, int $tipoDte, int $folio): never
{
    if (! in_array($tipoDte, TIPOS_PERMITIDOS, true)) {
        invalido('tipoDte debe ser uno de 33, 34, 61, 56', 'tipoDte');
    }
    if ($folio <= 0) {
        invalido('folio debe ser > 0', 'folio');
    }

    $rutEmisor = $tenant['rut_emisor'];
    $ambiente  = ambienteDesdeTenant($tenant);

    // 404 si el folio no esta persistido (sin tocar el SII).
    $pdo     = pdo();
    $emitido = new MySqlDteEmitidoRepository($pdo);
    if ($emitido->obtenerXml($rutEmisor, $ambiente, $tipoDte, $folio) === null) {
        responder(404, ['error' => 'folio no encontrado']);
    }

    $cred = new Credenciales(
        rutEmisor: $rutEmisor,
        apiToken:  'no-usado-por-sii-directo',
        ambiente:  $ambiente,
        rutSender: resolverRutSender($pdo, $rutEmisor, $ambiente),
    );

    try {
        $estado = crearFacturador($pdo)->consultarEstado($folio, $tipoDte, $cred);
    } catch (SiiAutenticacionException $e) {
        responder(502, ['error' => 'fallo de autenticacion con el SII', 'estadoSii' => $e->estadoSii, 'glosaSii' => $e->glosaSii]);
    } catch (Throwable $e) {
        responder(500, ['error' => 'fallo la consulta', 'detalle' => $e->getMessage()]);
    }

    responder(200, [
        'tipoDte' => $tipoDte,
        'folio'   => $folio,
        'estado'  => $estado->estado,
        'glosa'   => $estado->glosa,
    ]);
}

// ===========================================================================
//  Handler: GET /api/v1/dte/{tipoDte}/{folio}/estado-sii
//
//  Consulta el estado del ENVIO (no del documento individual) via track_id,
//  usando SiiConsultor::consultarEnvio() (SOAP QueryEstUp.jws) -- DISTINTO de
//  GET .../estado (que usa consultarEstado()/getEstDte, estado del DOCUMENTO
//  puntual: DOK/DNK). Aqui el estado de exito a nivel de ENVIO es EPR ("Envio
//  Procesado"), confirmado en src/Sii/SiiConsultor.php (docblock),
//  tests/SiiConsultorTest.php y tests/SiiDirectoFacturadorTest.php (fixtures
//  reales de EPR) y docs/REFERENCIA_CERTIFICACION_SII_DTE.md ("esperar EPR
//  (8/8 aceptados, 0 reparos)"). NO consume folio ni emite nada: solo consulta
//  y persiste el 'estado' devuelto en dte_emitido.
// ===========================================================================
function consultarEstadoSiiDte(array $tenant, int $tipoDte, int $folio): never
{
    if (! in_array($tipoDte, TIPOS_PERMITIDOS, true)) {
        invalido('tipoDte debe ser uno de 33, 34, 61, 56', 'tipoDte');
    }
    if ($folio <= 0) {
        invalido('folio debe ser > 0', 'folio');
    }

    $rutEmisor = $tenant['rut_emisor'];
    $ambiente  = ambienteDesdeTenant($tenant);
    $pdo       = pdo();

    $stmt = $pdo->prepare(
        'SELECT id, track_id FROM dte_emitido '
        . 'WHERE rut_emisor = :rut AND ambiente = :amb AND tipo_dte = :tipo AND folio = :folio LIMIT 1'
    );
    $stmt->execute([':rut' => $rutEmisor, ':amb' => $ambiente->value, ':tipo' => $tipoDte, ':folio' => $folio]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        responder(404, ['error' => 'folio no encontrado']);
    }

    $trackId = $row['track_id'];
    if ($trackId === null || $trackId === '') {
        // Sin track_id no hay nada que consultar en el SII; no se toca el
        // estado ya persistido (no hay verdad nueva que registrar).
        responder(200, [
            'tipoDte' => $tipoDte,
            'folio'   => $folio,
            'trackId' => null,
            'estado'  => 'sin_trackid',
            'glosa'   => null,
        ]);
    }

    $certTls = __DIR__ . '/../fullchain.pem';
    $keyTls  = __DIR__ . '/../key.pem';
    foreach ([$certTls, $keyTls] as $ruta) {
        if (! is_file($ruta) || ! is_readable($ruta)) {
            responder(500, ['error' => 'Certificado TLS mutuo no disponible en el servidor']);
        }
    }

    $bin = @hex2bin(getenv('CRYPTO_MASTER_KEY') ?: '');
    if ($bin === false || strlen($bin) !== CertificadoCrypto::KEY_LENGTH) {
        responder(500, ['error' => 'CRYPTO_MASTER_KEY mal configurada en el servidor']);
    }
    $crypto = new CertificadoCrypto($bin);
    $emisor = new MySqlEmisorRepository($pdo, $crypto);
    $http   = new Client(['timeout' => 60, 'cert' => $certTls, 'ssl_key' => $keyTls, 'verify' => true]);

    // La llamada al SII va aislada: si falla (timeout, 500, rechazo de auth),
    // se responde 502 SIN tocar dte_emitido -- el estado previo persistido
    // queda intacto, nunca se borra ni se sobreescribe con un fallo.
    try {
        $cert  = $emisor->obtenerCertificado($rutEmisor, $ambiente);
        $token = (new SiiAutenticador($http, new XmlSigner()))->obtenerToken($cert, $ambiente);
        $res   = (new SiiConsultor($http))->consultarEnvio($rutEmisor, (string) $trackId, $token, $ambiente);
    } catch (SiiAutenticacionException $e) {
        responder(502, ['error' => 'fallo de autenticacion con el SII', 'estadoSii' => $e->estadoSii, 'glosaSii' => $e->glosaSii]);
    } catch (Throwable $e) {
        responder(502, ['error' => 'fallo la consulta al SII', 'detalle' => $e->getMessage()]);
    }

    $estadoNuevo = $res['estado'];
    $glosaNueva  = $res['glosa'] !== '' ? $res['glosa'] : null;

    $pdo->prepare('UPDATE dte_emitido SET estado = :estado WHERE id = :id')
        ->execute([':estado' => $estadoNuevo, ':id' => $row['id']]);

    responder(200, [
        'tipoDte' => $tipoDte,
        'folio'   => $folio,
        'trackId' => (string) $trackId,
        'estado'  => $estadoNuevo,
        'glosa'   => $glosaNueva,
    ]);
}

// ===========================================================================
//  Handler: GET /api/v1/dte/{tipoDte}/{folio}/pdf
// ===========================================================================
function pdfDte(array $tenant, int $tipoDte, int $folio): never
{
    if (! in_array($tipoDte, TIPOS_PERMITIDOS_PDF, true)) {
        invalido('tipoDte debe ser uno de 33, 34, 61, 56, 39', 'tipoDte');
    }
    if ($folio <= 0) {
        invalido('folio debe ser > 0', 'folio');
    }

    $rutEmisor = $tenant['rut_emisor'];
    $ambiente  = ambienteDesdeTenant($tenant);

    $xml = (new MySqlDteEmitidoRepository(pdo()))->obtenerXml($rutEmisor, $ambiente, $tipoDte, $folio);
    if ($xml === null) {
        responder(404, ['error' => 'folio no encontrado']);
    }

    $cedible = ($_GET['cedible'] ?? '') === '1';

    try {
        // Boleta (39): renderizador propio A5, no pasa por DtePdfGenerator/Sii\PDF\Dte
        // (esa clase asume layout A4, ver BoletaPdfGenerator.php). "cedible" no aplica
        // a boleta (concepto de factoring de factura), se ignora si viene.
        $pdf = $tipoDte === 39
            ? (new BoletaPdfGenerator())->generarDesdeEnvioXml($xml)
            : (new DtePdfGenerator())->generarDesdeEnvioXml($xml, $cedible, $tipoDte, $folio);
    } catch (Throwable $e) {
        responder(500, ['error' => 'fallo la generacion del PDF', 'detalle' => $e->getMessage()]);
    }

    // Respuesta binaria (no JSON): el navegador/intranet lo muestra o descarga directo.
    header('Content-Type: application/pdf');
    header(sprintf('Content-Disposition: inline; filename="dte_%d_%d.pdf"', $tipoDte, $folio));
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

// ===========================================================================
//  Handler: GET /api/v1/dte/{tipoDte}/{folio}/xml
//
//  Descarga el XML crudo tal como se persiste en dte_emitido (el EnvioDTE
//  completo, mismo bytes enviados al SII -- ver persistirEmitido() en
//  SiiDirectoFacturador). Mas simple que /pdf: no pasa por LibreDTE/TCPDF, solo
//  devuelve el blob ya guardado. Mismo patron de auth/resolucion de tenant y
//  mismos tipos permitidos que /pdf (TIPOS_PERMITIDOS_PDF incluye boleta).
// ===========================================================================
function xmlDte(array $tenant, int $tipoDte, int $folio): never
{
    if (! in_array($tipoDte, TIPOS_PERMITIDOS_PDF, true)) {
        invalido('tipoDte debe ser uno de 33, 34, 61, 56, 39', 'tipoDte');
    }
    if ($folio <= 0) {
        invalido('folio debe ser > 0', 'folio');
    }

    $rutEmisor = $tenant['rut_emisor'];
    $ambiente  = ambienteDesdeTenant($tenant);

    $xml = (new MySqlDteEmitidoRepository(pdo()))->obtenerXml($rutEmisor, $ambiente, $tipoDte, $folio);
    if ($xml === null) {
        responder(404, ['error' => 'folio no encontrado']);
    }

    // El XML se persiste en ISO-8859-1 (mismo criterio que dte_libro/dte_emitido,
    // ver migracion 008): se declara el charset para que quien lo abra/valide
    // no asuma UTF-8 por defecto.
    header('Content-Type: application/xml; charset=ISO-8859-1');
    header(sprintf('Content-Disposition: attachment; filename="dte_%d_%d.xml"', $tipoDte, $folio));
    header('Content-Length: ' . strlen($xml));
    echo $xml;
    exit;
}

// ===========================================================================
//  Handler: POST /api/v1/dte/{tipoDte}/{folio}/anular
// ===========================================================================
function anularDte(array $tenant, int $tipoDte, int $folio): never
{
    if (! in_array($tipoDte, TIPOS_PERMITIDOS_ANULAR, true)) {
        invalido('tipoDte debe ser uno de 33, 61, 56, 39', 'tipoDte');
    }
    if ($folio <= 0) {
        invalido('folio debe ser > 0', 'folio');
    }

    $body   = json_decode(file_get_contents('php://input') ?: '', true);
    $motivo = is_array($body) && isset($body['motivo']) && is_string($body['motivo']) ? trim($body['motivo']) : '';
    if ($motivo === '') {
        invalido('motivo es obligatorio', 'motivo');
    }

    $rutEmisor = $tenant['rut_emisor'];
    $ambiente  = ambienteDesdeTenant($tenant);

    $pdo     = pdo();
    $emitido = new MySqlDteEmitidoRepository($pdo);

    $xml = $emitido->obtenerXml($rutEmisor, $ambiente, $tipoDte, $folio);
    if ($xml === null) {
        responder(404, ['error' => 'folio no encontrado']);
    }

    // Salvaguarda 1 (deterministica): ya existe una NC contra este documento.
    if ($emitido->existeAnulacion($rutEmisor, $ambiente, $tipoDte, $folio)) {
        responder(409, ['error' => 'el documento ya esta anulado o no es anulable']);
    }

    $cred       = new Credenciales($rutEmisor, 'no-usado-por-sii-directo', $ambiente, null, resolverRutSender($pdo, $rutEmisor, $ambiente));
    $facturador = crearFacturador($pdo);

    // Salvaguarda 2: verificacion contra el SII del estado del original.
    // Boleta (39) NO tiene getEstDte (SOAP) ni un estado "ANC" expuesto por el
    // SII a nivel de documento individual: la proteccion contra doble-anulacion
    // para boleta depende SOLO de la Salvaguarda 1 (BD, ya generica y suficiente,
    // arriba). Aqui, para boleta, se hace en su lugar una verificacion defensiva
    // de que el ENVIO siga aceptado (no rechazado) via BoletaConsultor, usando el
    // track_id persistido. Factura/ND (!==39) siguen exactamente igual: SOAP
    // getEstDte, sin tocar.
    if ($tipoDte === 39) {
        $stmtTrack = $pdo->prepare(
            'SELECT track_id FROM dte_emitido '
            . 'WHERE rut_emisor = :rut AND ambiente = :amb AND tipo_dte = 39 AND folio = :folio LIMIT 1'
        );
        $stmtTrack->execute([':rut' => $rutEmisor, ':amb' => $ambiente->value, ':folio' => $folio]);
        $trackId = $stmtTrack->fetchColumn();
        if ($trackId === false || $trackId === null || $trackId === '') {
            responder(409, ['error' => 'boleta sin track_id registrado, no se puede verificar antes de anular']);
        }

        $bin = @hex2bin(getenv('CRYPTO_MASTER_KEY') ?: '');
        if ($bin === false || strlen($bin) !== CertificadoCrypto::KEY_LENGTH) {
            responder(500, ['error' => 'CRYPTO_MASTER_KEY mal configurada en el servidor']);
        }
        $crypto  = new CertificadoCrypto($bin);
        $emisor  = new MySqlEmisorRepository($pdo, $crypto);
        $certTls = __DIR__ . '/../fullchain.pem';
        $keyTls  = __DIR__ . '/../key.pem';
        foreach ([$certTls, $keyTls] as $ruta) {
            if (! is_file($ruta) || ! is_readable($ruta)) {
                responder(500, ['error' => 'Certificado TLS mutuo no disponible en el servidor']);
            }
        }
        $http = new Client(['timeout' => 60, 'cert' => $certTls, 'ssl_key' => $keyTls, 'verify' => true]);

        try {
            $cert       = $emisor->obtenerCertificado($rutEmisor, $ambiente);
            $tokenBol   = (new BoletaAutenticador($http, new XmlSigner()))->obtenerToken($cert, $ambiente);
            $resEnvio   = (new BoletaConsultor($http))->consultarEnvio($rutEmisor, $trackId, $tokenBol, $ambiente);
        } catch (SiiAutenticacionException $e) {
            responder(502, ['error' => 'fallo de autenticacion con el SII', 'estadoSii' => $e->estadoSii, 'glosaSii' => $e->glosaSii]);
        } catch (Throwable $e) {
            responder(500, ['error' => 'fallo la consulta de estado previa (boleta)', 'detalle' => $e->getMessage()]);
        }
        if ((int) $resEnvio['aceptados'] < 1 || (int) $resEnvio['rechazados'] > 0) {
            responder(409, ['error' => 'la boleta no esta aceptada por el SII, no se puede anular']);
        }
    } else {
        // Salvaguarda 2 (SII): estado del original; si ya esta anulado (ANC), no re-emitir.
        try {
            $estadoOriginal = $facturador->consultarEstado($folio, $tipoDte, $cred);
        } catch (SiiAutenticacionException $e) {
            responder(502, ['error' => 'fallo de autenticacion con el SII', 'estadoSii' => $e->estadoSii, 'glosaSii' => $e->glosaSii]);
        } catch (Throwable $e) {
            responder(500, ['error' => 'fallo la consulta de estado previa', 'detalle' => $e->getMessage()]);
        }
        if ($estadoOriginal->estado === 'ANC') {
            responder(409, ['error' => 'el documento ya esta anulado o no es anulable']);
        }
    }

    try {
        $original = reconstruirOriginal($xml, $tipoDte, $folio);
        $res      = $facturador->anular($original, $motivo, TipoAnulacion::AnulaTotal, $cred);
    } catch (SiiAutenticacionException $e) {
        responder(502, ['error' => 'fallo de autenticacion con el SII', 'estadoSii' => $e->estadoSii, 'glosaSii' => $e->glosaSii]);
    } catch (EnvioRechazadoException $e) {
        responder(502, ['error' => 'el SII rechazo la nota de credito', 'status' => $e->status, 'trackId' => $e->trackId]);
    } catch (Throwable $e) {
        responder(500, ['error' => 'fallo la anulacion', 'detalle' => $e->getMessage()]);
    }

    responder(201, [
        'ncFolio'    => $res->folio,
        'tipoDte'    => $res->tipoDte->value,
        'estado'     => $res->estado,
        'trackId'    => $res->trackId,
        'folioRef'   => $folio,
        'tipoDteRef' => $tipoDte,
    ]);
}

/**
 * Reconstruye el DocumentoOriginal (receptor, detalles, montos, fecha) desde el
 * EnvioDTE persistido. El cliente NO reenvia estos datos: son la fuente de verdad.
 */
function reconstruirOriginal(string $envioXml, int $tipoDte, int $folio): DocumentoOriginal
{
    $dom  = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $dom->loadXML($envioXml);
    libxml_use_internal_errors($prev);

    $txt = static function (string $tag) use ($dom): string {
        $n = $dom->getElementsByTagNameNS(NS_SII, $tag)->item(0);
        return $n !== null ? trim($n->textContent) : '';
    };

    $receptor = new Receptor(
        rut: $txt('RUTRecep'),
        razonSocial: $txt('RznSocRecep'),
        giro: ($g = $txt('GiroRecep')) !== '' ? $g : null,
        direccion: ($d = $txt('DirRecep')) !== '' ? $d : null,
        comuna: ($c = $txt('CmnaRecep')) !== '' ? $c : null,
    );

    $detalles = [];
    foreach ($dom->getElementsByTagNameNS(NS_SII, 'Detalle') as $det) {
        $hijo = static function (string $tag) use ($det): string {
            $n = $det->getElementsByTagNameNS(NS_SII, $tag)->item(0);
            return $n !== null ? trim($n->textContent) : '';
        };
        $detalles[] = new Detalle(
            $hijo('NmbItem'),
            (float) ($hijo('QtyItem') ?: '1'),
            (float) ($hijo('PrcItem') ?: '0'),
            exento: $hijo('IndExe') === '1',
        );
    }

    $fch = $txt('FchEmis');

    return new DocumentoOriginal(
        tipoDte:      TipoDte::from($tipoDte),
        folio:        $folio,
        fechaEmision: new DateTimeImmutable($fch !== '' ? $fch : 'today'),
        receptor:     $receptor,
        detalles:     $detalles,
        montoNeto:    (int) ($txt('MntNeto') ?: '0'),
        iva:          (int) ($txt('IVA') ?: '0'),
        montoTotal:   (int) ($txt('MntTotal') ?: '0'),
    );
}

// ===========================================================================
//  Handler: POST /api/v1/libro
//
//  Envio de Libro IECV (Ventas/Compras) al SII, componente obligatorio de la
//  certificacion de factura junto al Set Basico (ver
//  docs/REFERENCIA_CERTIFICACION_SII_DTE.md seccion 4). Reusa LibroService
//  (construccion + firma + envio); aqui solo se valida el payload y se arman
//  los DTOs. Los libros no usan CAF ni consumen folios.
//
//  Persistencia (migracion 006, tabla dte_libro): SOLO despues de que el SII
//  responde OK, igual que emitir()/emitirLote() para dte_emitido (ver
//  SiiDirectoFacturador::persistirEmitido()/persistirEmitidosLote()). Si el
//  envio falla (catch de abajo) no se persiste nada -- el catch responde y
//  termina la request antes de llegar a la persistencia. LibroService::
//  enviarLibro() YA devuelve el xml enviado (Sii/LibroService.php linea ~70),
//  no hizo falta tocar su firma.
// ===========================================================================
function enviarLibroIecv(array $tenant): never
{
    $crudo = file_get_contents('php://input') ?: '';
    $body  = json_decode($crudo, true);
    if (! is_array($body)) {
        invalido('JSON invalido o vacio', 'body');
    }

    // --- Validacion ANTES de tocar el SII ---
    $tipoOperacion = TipoOperacionLibro::tryFrom((string) ($body['tipoOperacion'] ?? ''));
    if ($tipoOperacion === null) {
        invalido('tipoOperacion debe ser VENTA o COMPRA', 'tipoOperacion');
    }

    $periodo = $body['periodoTributario'] ?? null;
    if (! is_string($periodo) || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo)) {
        invalido('periodoTributario debe tener formato AAAA-MM (mes 01-12)', 'periodoTributario');
    }

    $tipoLibro = TipoLibro::tryFrom((string) ($body['tipoLibro'] ?? ''));
    if ($tipoLibro === null) {
        invalido('tipoLibro debe ser MENSUAL, ESPECIAL o RECTIFICA', 'tipoLibro');
    }

    $tipoEnvio = TipoEnvioLibro::tryFrom((string) ($body['tipoEnvio'] ?? ''));
    if ($tipoEnvio === null) {
        invalido('tipoEnvio debe ser TOTAL, PARCIAL o AJUSTE', 'tipoEnvio');
    }

    $folioNotificacion = $body['folioNotificacion'] ?? null;
    if (! is_int($folioNotificacion) || $folioNotificacion <= 0) {
        invalido('folioNotificacion debe ser un entero > 0', 'folioNotificacion');
    }

    $factor = $body['factorProporcionalidad'] ?? null;
    if ($factor !== null && ! is_numeric($factor)) {
        invalido('factorProporcionalidad debe ser numerico', 'factorProporcionalidad');
    }

    $lineas = $body['lineas'] ?? null;
    if (! is_array($lineas) || $lineas === []) {
        invalido('lineas debe tener al menos 1 item', 'lineas');
    }

    $lineasDto = [];
    foreach (array_values($lineas) as $i => $l) {
        if (! is_array($l)) {
            invalido("lineas[{$i}] debe ser un objeto", "lineas[{$i}]");
        }
        foreach (['tpoDoc', 'nroDoc'] as $campo) {
            if (! isset($l[$campo]) || ! is_int($l[$campo]) || $l[$campo] <= 0) {
                invalido("lineas[{$i}].{$campo} debe ser un entero > 0", "lineas[{$i}].{$campo}");
            }
        }
        if (! isset($l['fecha']) || ! is_string($l['fecha']) || ! validaFecha($l['fecha'])) {
            invalido("lineas[{$i}].fecha debe ser una fecha valida AAAA-MM-DD", "lineas[{$i}].fecha");
        }
        foreach (['rutContraparte', 'razonSocial'] as $campo) {
            if (! isset($l[$campo]) || ! is_string($l[$campo]) || trim($l[$campo]) === '') {
                invalido("lineas[{$i}].{$campo} es obligatorio", "lineas[{$i}].{$campo}");
            }
        }
        if (! rutDvValido($l['rutContraparte'])) {
            invalido("lineas[{$i}].rutContraparte tiene DV invalido", "lineas[{$i}].rutContraparte");
        }
        foreach (['mntExe', 'mntNeto', 'mntIva', 'mntTotal'] as $campo) {
            if (! isset($l[$campo]) || ! is_int($l[$campo]) || $l[$campo] < 0) {
                invalido("lineas[{$i}].{$campo} debe ser un entero >= 0", "lineas[{$i}].{$campo}");
            }
        }
        // Opcionales enteros: tasaImp y el bloque COMPRA (IVA uso comun / no
        // recuperable / otros impuestos). Los pares obligatorios (codIvaNoRec+
        // mntIvaNoRec, codOtroImp+mntOtroImp) los valida el propio DTO.
        foreach (['tasaImp', 'ivaUsoComun', 'codIvaNoRec', 'mntIvaNoRec', 'codOtroImp', 'mntOtroImp', 'tasaOtroImp'] as $campo) {
            if (isset($l[$campo]) && ! is_int($l[$campo])) {
                invalido("lineas[{$i}].{$campo} debe ser entero", "lineas[{$i}].{$campo}");
            }
        }
    }

    try {
        foreach (array_values($lineas) as $l) {
            $lineasDto[] = new LineaLibro(
                tpoDoc:         $l['tpoDoc'],
                nroDoc:         $l['nroDoc'],
                fecha:          new DateTimeImmutable($l['fecha']),
                rutContraparte: $l['rutContraparte'],
                razonSocial:    $l['razonSocial'],
                mntExe:         $l['mntExe'],
                mntNeto:        $l['mntNeto'],
                mntIva:         $l['mntIva'],
                mntTotal:       $l['mntTotal'],
                tasaImp:        $l['tasaImp'] ?? 19,
                ivaUsoComun:    $l['ivaUsoComun'] ?? null,
                codIvaNoRec:    $l['codIvaNoRec'] ?? null,
                mntIvaNoRec:    $l['mntIvaNoRec'] ?? null,
                codOtroImp:     $l['codOtroImp'] ?? null,
                mntOtroImp:     $l['mntOtroImp'] ?? null,
                tasaOtroImp:    $l['tasaOtroImp'] ?? 19,
            );
        }

        $libro = new Libro(
            tipoOperacion:          $tipoOperacion,
            periodoTributario:      $periodo,
            tipoLibro:              $tipoLibro,
            tipoEnvio:              $tipoEnvio,
            folioNotificacion:      $folioNotificacion,
            lineas:                 $lineasDto,
            factorProporcionalidad: $factor !== null ? (float) $factor : null,
        );
    } catch (DocumentoInvalidoException $e) {
        invalido($e->getMessage(), 'lineas');
    }

    // --- Emisor, ambiente y sender: SOLO del tenant autenticado ---
    $pdo      = pdo();
    $ambiente = ambienteDesdeTenant($tenant);

    $cred = new Credenciales(
        rutEmisor: $tenant['rut_emisor'],
        apiToken:  'no-usado-por-sii-directo',
        ambiente:  $ambiente,
        rutSender: resolverRutSender($pdo, $tenant['rut_emisor'], $ambiente),
    );

    try {
        $res = crearLibroService($pdo)->enviarLibro($libro, $cred);
    } catch (SiiAutenticacionException $e) {
        responder(502, ['error' => 'fallo de autenticacion con el SII', 'estadoSii' => $e->estadoSii, 'glosaSii' => $e->glosaSii]);
    } catch (EnvioRechazadoException $e) {
        responder(502, ['error' => 'el SII rechazo el envio del libro', 'status' => $e->status, 'trackId' => $e->trackId]);
    } catch (Throwable $e) {
        responder(502, ['error' => 'fallo el envio del libro', 'detalle' => $e->getMessage()]);
    }

    // El SII ya respondio OK: persistir (best-effort, mismo criterio que
    // SiiDirectoFacturador::persistirEmitido() -- un fallo de persistencia no
    // debe convertir un libro ya aceptado por el SII en un error de la request).
    try {
        (new MySqlLibroRepository($pdo))->registrar(
            rutEmisor:         $tenant['rut_emisor'],
            ambiente:          $ambiente,
            tipoOperacion:     $tipoOperacion->value,
            periodoTributario: $periodo,
            tipoLibro:         $tipoLibro->value,
            tipoEnvio:         $tipoEnvio->value,
            folioNotificacion: $folioNotificacion,
            trackId:           $res['trackId'] ?? null,
            estado:            'enviado',
            xml:               $res['xml'],
        );
    } catch (Throwable $e) {
        error_log('dte_libro registrar fallo (trackId ' . ($res['trackId'] ?? 'null') . '): ' . $e->getMessage());
    }

    responder(201, [
        'tipoOperacion'     => $tipoOperacion->value,
        'periodoTributario' => $periodo,
        'tipoLibro'         => $tipoLibro->value,
        'tipoEnvio'         => $tipoEnvio->value,
        'trackId'           => $res['trackId'],
        'status'            => $res['status'],
    ]);
}

// ===========================================================================
//  Handler: GET /api/v1/libro/{trackId}/estado-sii
//
//  Consulta el estado del ENVIO del libro via SiiConsultor::consultarEnvio()
//  (SOAP QueryEstUp.jws) -- MISMO servicio y misma clase que
//  consultarEstadoSiiDte(), los libros comparten el canal de consulta de
//  envios con los DTE (ambos se suben con SiiUploader/LibroUploader al mismo
//  QueryEstUp.jws). Scope estricto: el trackId debe existir en dte_libro para
//  el rut_emisor+ambiente de ESTE tenant (LibroRepositoryInterface::
//  existeTrackId()); si no, 404 sin filtrar si el trackId existe para OTRO
//  tenant. La llamada al SII va aislada: si falla, no se toca el estado
//  persistido.
// ===========================================================================
function consultarEstadoSiiLibro(array $tenant, string $trackId): never
{
    $rutEmisor = $tenant['rut_emisor'];
    $ambiente  = ambienteDesdeTenant($tenant);
    $pdo       = pdo();
    $libros    = new MySqlLibroRepository($pdo);

    if (! $libros->existeTrackId($rutEmisor, $ambiente, $trackId)) {
        responder(404, ['error' => 'trackId no encontrado']);
    }

    $certTls = __DIR__ . '/../fullchain.pem';
    $keyTls  = __DIR__ . '/../key.pem';
    foreach ([$certTls, $keyTls] as $ruta) {
        if (! is_file($ruta) || ! is_readable($ruta)) {
            responder(500, ['error' => 'Certificado TLS mutuo no disponible en el servidor']);
        }
    }

    $bin = @hex2bin(getenv('CRYPTO_MASTER_KEY') ?: '');
    if ($bin === false || strlen($bin) !== CertificadoCrypto::KEY_LENGTH) {
        responder(500, ['error' => 'CRYPTO_MASTER_KEY mal configurada en el servidor']);
    }
    $crypto = new CertificadoCrypto($bin);
    $emisor = new MySqlEmisorRepository($pdo, $crypto);
    $http   = new Client(['timeout' => 60, 'cert' => $certTls, 'ssl_key' => $keyTls, 'verify' => true]);

    // La llamada al SII va aislada: si falla, no se toca dte_libro -- el
    // estado previo persistido queda intacto.
    try {
        $cert  = $emisor->obtenerCertificado($rutEmisor, $ambiente);
        $token = (new SiiAutenticador($http, new XmlSigner()))->obtenerToken($cert, $ambiente);
        $res   = (new SiiConsultor($http))->consultarEnvio($rutEmisor, $trackId, $token, $ambiente);
    } catch (SiiAutenticacionException $e) {
        responder(502, ['error' => 'fallo de autenticacion con el SII', 'estadoSii' => $e->estadoSii, 'glosaSii' => $e->glosaSii]);
    } catch (Throwable $e) {
        responder(502, ['error' => 'fallo la consulta al SII', 'detalle' => $e->getMessage()]);
    }

    $estadoNuevo = $res['estado'];
    $glosaNueva  = $res['glosa'] !== '' ? $res['glosa'] : null;

    $libros->actualizarEstado($rutEmisor, $ambiente, $trackId, $estadoNuevo);

    responder(200, [
        'trackId' => $trackId,
        'estado'  => $estadoNuevo,
        'glosa'   => $glosaNueva,
    ]);
}

// ===========================================================================
//  Handlers RCV
// ===========================================================================
/** @return array{0:string,1:string,2:string,3:string} [rut, dv, periodo, operacion] */
function rcvParams(): array
{
    $rut     = trim((string) ($_GET['rut'] ?? ''));
    $dv      = strtoupper(trim((string) ($_GET['dv'] ?? '')));
    $periodo = trim((string) ($_GET['periodo'] ?? ''));
    $op      = strtoupper(trim((string) ($_GET['operacion'] ?? '')));
    if (! preg_match('/^\d{7,8}$/', $rut)) {
        responder(400, ['error' => 'rut invalido (solo numero, sin DV ni puntos)', 'campo' => 'rut']);
    }
    if (! preg_match('/^[0-9K]$/', $dv)) {
        responder(400, ['error' => 'dv invalido', 'campo' => 'dv']);
    }
    if (! preg_match('/^\d{6}$/', $periodo)) {
        responder(400, ['error' => 'periodo debe ser AAAAMM', 'campo' => 'periodo']);
    }
    if ($op !== 'COMPRA' && $op !== 'VENTA') {
        responder(400, ['error' => 'operacion debe ser COMPRA|VENTA', 'campo' => 'operacion']);
    }
    return [$rut, $dv, $periodo, $op];
}

function rcvResumen(): never
{
    [$rut, $dv, $periodo, $op] = rcvParams();
    try {
        $cert = certOperador(pdo(), $rut . '-' . $dv);
        $json = crearRcvConsultor()->getResumen($cert, $rut, $dv, $periodo, $op, Ambiente::Produccion);
    } catch (RcvConsultaException $e) {
        responder(502, ['error' => 'el SII rechazo la consulta RCV', 'codRespuesta' => $e->codRespuesta, 'codError' => $e->codError, 'msge' => $e->msgeRespuesta]);
    } catch (ConexionException $e) {
        responder(503, ['error' => 'RCV no disponible', 'detalle' => $e->getMessage()]);
    } catch (Throwable $e) {
        responder(500, ['error' => 'fallo la consulta RCV', 'detalle' => $e->getMessage()]);
    }
    responder(200, $json);
}

function rcvDetalleCsv(): never
{
    [$rut, $dv, $periodo, $op] = rcvParams();
    try {
        $cert = certOperador(pdo(), $rut . '-' . $dv);
        $csv  = crearRcvConsultor()->getDetalleCsv($cert, $rut, $dv, $periodo, $op, Ambiente::Produccion);
    } catch (RcvConsultaException $e) {
        responder(502, ['error' => 'el SII rechazo el detalle RCV', 'codRespuesta' => $e->codRespuesta, 'codError' => $e->codError, 'msge' => $e->msgeRespuesta]);
    } catch (ConexionException $e) {
        responder(503, ['error' => 'RCV no disponible', 'detalle' => $e->getMessage()]);
    } catch (Throwable $e) {
        responder(500, ['error' => 'fallo el detalle RCV', 'detalle' => $e->getMessage()]);
    }
    // CSV crudo: sobreescribe el Content-Type JSON global (headers aun no enviados).
    header('Content-Type: text/csv; charset=utf-8');
    header(sprintf('Content-Disposition: inline; filename="rcv_%s_%s_%s.csv"', $rut, $periodo, strtolower($op)));
    header('Content-Length: ' . strlen($csv));
    echo $csv;
    exit;
}
