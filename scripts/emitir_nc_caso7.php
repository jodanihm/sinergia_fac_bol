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
if (count($args) !== 2) fail("Uso: php scripts/emitir_nc_caso7.php <ambiente> <rut_emisor>");
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

$receptor = new Receptor('66666666-6', 'Cliente de Prueba', 'Servicios', 'Calle Falsa 123', 'Santiago');

$detalles = [
    new Detalle('Pintura B&W AFECTO', 24, 1653),
    new Detalle('ITEM 2 AFECTO', 144, 2946),
    new Detalle('ITEM 3 SERVICIO EXENTO', 1, 34674, exento: true),
];

$nc = new DocumentoTributario(
    tipoDte: TipoDte::NotaCreditoElectronica,
    receptor: $receptor,
    detalles: $detalles,
    montosSonBrutos: true,
    observaciones: 'ANULA FACTURA',
    referencias: [[
        'tipoDocumento' => '33',
        'folio' => 34,
        'fecha' => '2026-06-19',
        'codigo' => '1', // Anula total
        'razon' => 'ANULA FACTURA',
    ]],
);

// Montos reales de factura 34: afecto bruto 463896, exento 34674, neto 389829, IVA 74067, total 498570
$original = new DocumentoOriginal(
    tipoDte: TipoDte::FacturaElectronica,
    folio: 34,
    fechaEmision: new DateTimeImmutable('2026-06-19'),
    receptor: $receptor,
    detalles: $detalles,
    montoNeto: 389829,
    iva: 74067,
    montoTotal: 498570,
    montosSonBrutos: true,
);

echo "*** CASO 7 - NC Anula Total (ref. factura folio 34) ***\n";
try {
    $resultado = $facturador->emitirDocumentoReferenciado($nc, $original, TipoAnulacion::AnulaTotal, $cred);
    echo "OK - NC emitida\n  Folio: {$resultado->folio}\n  Estado: {$resultado->estado}\n  TrackID: {$resultado->trackId}\n";
} catch (SiiAutenticacionException $e) {
    fail(sprintf("Fallo autenticacion: %s - %s", $e->estadoSii, $e->glosaSii));
} catch (EnvioRechazadoException $e) {
    fail(sprintf("Rechazo SII: status=%s track=%s msg=%s", $e->status, $e->trackId ?? '?', $e->getMessage()));
} catch (Throwable $e) {
    fail(sprintf("Excepcion: %s: %s", get_class($e), $e->getMessage()));
}
