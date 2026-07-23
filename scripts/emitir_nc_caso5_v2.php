<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use Plantiflex\FacturacionCl\Dto\Credenciales;
use Plantiflex\FacturacionCl\Dto\Detalle;
use Plantiflex\FacturacionCl\Dto\DocumentoOriginal;
use Plantiflex\FacturacionCl\Dto\DocumentoTributario;
use Plantiflex\FacturacionCl\Dto\Receptor;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Enums\TipoAnulacion;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Exceptions\EnvioRechazadoException;
use Plantiflex\FacturacionCl\Exceptions\SiiAutenticacionException;
use Plantiflex\FacturacionCl\Providers\SiiDirectoFacturador;
use Plantiflex\Integration\Facturacion\CertificadoCrypto;
use Plantiflex\Integration\Facturacion\MySqlEmisorRepository;
use Plantiflex\Integration\Facturacion\MySqlFolioRepository;

function fail(string $msg, int $code = 1): never { fwrite(STDERR, "ERROR: {$msg}\n"); exit($code); }
function requerirEnv(string $nombre): string { $v = getenv($nombre); if ($v === false) fail("Falta la variable de entorno {$nombre}."); return $v; }

function conectarDb(): PDO {
    $host = requerirEnv('DB_HOST'); $name = requerirEnv('DB_NAME'); $user = requerirEnv('DB_USER');
    $pass = getenv('DB_PASS'); $pass = ($pass === false) ? '' : $pass;
    $port = getenv('DB_PORT'); $port = ($port === false) ? '3306' : $port;
    try {
        return new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException $e) { fail('No se pudo conectar a la base: ' . $e->getMessage()); }
}

function crearCrypto(): CertificadoCrypto {
    $hex = requerirEnv('CRYPTO_MASTER_KEY');
    $bin = @hex2bin($hex);
    if ($bin === false || strlen($bin) !== CertificadoCrypto::KEY_LENGTH) fail('CRYPTO_MASTER_KEY debe ser ' . CertificadoCrypto::KEY_LENGTH . ' bytes en HEX (64 hex).');
    return new CertificadoCrypto($bin);
}

// ---------------------------------------------------------------------------
$args = array_slice($argv, 1);
if (count($args) !== 2) fail("Uso: php scripts/emitir_nc_caso5_v2.php <ambiente> <rut_emisor>");
[$ambienteArg, $rut] = $args;

if ($ambienteArg === 'certificacion') $ambiente = Ambiente::Certificacion;
elseif ($ambienteArg === 'produccion') $ambiente = Ambiente::Produccion;
else fail("ambiente debe ser 'certificacion' o 'produccion'.");

$pdo    = conectarDb();
$crypto = crearCrypto();
$folios = new MySqlFolioRepository($pdo, function (string $c) use ($crypto): string { return $crypto->descifrar($c); });
$emisor = new MySqlEmisorRepository($pdo, $crypto);

$certTls = __DIR__ . '/../fullchain.pem';
$keyTls  = __DIR__ . '/../key.pem';
foreach (['fullchain.pem' => $certTls, 'key.pem' => $keyTls] as $nombre => $ruta) {
    if (! is_file($ruta) || ! is_readable($ruta)) fail("No se encuentra {$nombre} para el TLS mutuo en: {$ruta}");
}

$facturador = new SiiDirectoFacturador(new Client(['timeout' => 60, 'cert' => $certTls, 'ssl_key' => $keyTls, 'verify' => true]), $folios, $emisor);
$cred = new Credenciales(rutEmisor: $rut, apiToken: 'no-usado-por-sii-directo', ambiente: $ambiente, rutSender: '13520634-2');

$receptorCorregido = new Receptor('66666666-6', 'Cliente de Prueba', 'Giro Corregido', 'Calle Falsa 123', 'Santiago');
$detalles = [
    new Detalle('Cajón AFECTO', 121, 791),
    new Detalle('Relleno AFECTO', 52, 1250),
];

$nc = new DocumentoTributario(
    tipoDte: TipoDte::NotaCreditoElectronica,
    receptor: $receptorCorregido,
    detalles: $detalles,
    montosSonBrutos: true,
    observaciones: 'CORRIGE GIRO DEL RECEPTOR',
    referencias: [[
        'tipoDocumento' => '33',
        'folio' => 29,
        'fecha' => '2026-06-19',
        'codigo' => '2',
        'razon' => 'CORRIGE GIRO DEL RECEPTOR',
    ]],
);

// Montos reales de factura 29: afecto bruto 160711 -> neto 135051, IVA 25660, total 160711
$original = new DocumentoOriginal(
    tipoDte: TipoDte::FacturaElectronica,
    folio: 29,
    fechaEmision: new DateTimeImmutable('2026-06-19'),
    receptor: new Receptor('66666666-6', 'Cliente de Prueba', 'Servicios', 'Calle Falsa 123', 'Santiago'),
    detalles: $detalles,
    montoNeto: 135051,
    iva: 25660,
    montoTotal: 160711,
    montosSonBrutos: true,
);

echo "*** CASO 5 - NC Corrige Texto (ref. factura folio 29) ***\n";
try {
    $resultado = $facturador->emitirDocumentoReferenciado($nc, $original, TipoAnulacion::CorrigeTexto, $cred);
    echo "OK - NC emitida\n  Folio: {$resultado->folio}\n  Estado: {$resultado->estado}\n  TrackID: {$resultado->trackId}\n";
} catch (SiiAutenticacionException $e) {
    fail(sprintf("Fallo autenticacion: %s - %s", $e->estadoSii, $e->glosaSii));
} catch (EnvioRechazadoException $e) {
    fail(sprintf("Rechazo SII: status=%s track=%s msg=%s", $e->status, $e->trackId ?? '?', $e->getMessage()));
} catch (Throwable $e) {
    fail(sprintf("Excepcion: %s: %s", get_class($e), $e->getMessage()));
}
