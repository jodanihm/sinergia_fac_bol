<?php

declare(strict_types=1);

/**
 * Obtiene un TOKEN REAL de BOLETA desde el SII (canal REST de boletas).
 *
 * Prueba de la fase 2a: ejercita BoletaAutenticador end-to-end:
 *   GET semilla (apicert) -> firmar (XmlSigner) -> POST token (apicert).
 * NO consume folios ni emite nada. Solo autentica.
 *
 * USO:
 *   php scripts/obtener_token_boleta.php <ambiente> <rut_emisor>
 *
 * REQUISITOS: certificado cargado en BD para ese rut/ambiente y
 * fullchain.pem + key.pem en la raiz del proyecto (TLS mutuo).
 */

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Exceptions\SiiAutenticacionException;
use Plantiflex\FacturacionCl\Sii\BoletaAutenticador;
use Plantiflex\FacturacionCl\Sii\XmlSigner;
use Plantiflex\Integration\Facturacion\CertificadoCrypto;
use Plantiflex\Integration\Facturacion\MySqlEmisorRepository;

function fail(string $msg, int $code = 1): never
{
    fwrite(STDERR, "ERROR: {$msg}\n");
    exit($code);
}

function requerirEnv(string $nombre): string
{
    $v = getenv($nombre);
    if ($v === false) {
        fail("Falta la variable de entorno {$nombre}.");
    }
    return $v;
}

function conectarDb(): PDO
{
    $host = requerirEnv('DB_HOST');
    $name = requerirEnv('DB_NAME');
    $user = requerirEnv('DB_USER');
    $pass = getenv('DB_PASS');
    $pass = $pass === false ? '' : $pass;
    $port = getenv('DB_PORT');
    $port = $port === false ? '3306' : $port;

    try {
        return new PDO(
            "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    } catch (PDOException $e) {
        fail('No se pudo conectar a la base: ' . $e->getMessage());
    }
}

function crearCrypto(): CertificadoCrypto
{
    $hex = requerirEnv('CRYPTO_MASTER_KEY');
    $bin = @hex2bin($hex);
    if ($bin === false || strlen($bin) !== CertificadoCrypto::KEY_LENGTH) {
        fail('CRYPTO_MASTER_KEY debe ser ' . CertificadoCrypto::KEY_LENGTH . ' bytes en HEX (64 hex).');
    }
    return new CertificadoCrypto($bin);
}

// ---------------------------------------------------------------------------

$args = array_slice($argv, 1);
if (count($args) !== 2) {
    fail("Uso: php scripts/obtener_token_boleta.php <ambiente> <rut_emisor>");
}
[$ambienteArg, $rut] = $args;

if ($ambienteArg === 'certificacion') {
    $ambiente = Ambiente::Certificacion;
} elseif ($ambienteArg === 'produccion') {
    $ambiente = Ambiente::Produccion;
} else {
    fail("ambiente debe ser 'certificacion' o 'produccion'.");
}
if (trim($rut) === '') {
    fail('rut_emisor no puede ser vacio.');
}

$pdo    = conectarDb();
$crypto = crearCrypto();
$emisor = new MySqlEmisorRepository($pdo, $crypto);

// TLS mutuo: mismo certificado en el handshake (igual que el flujo de facturas).
$certTls = __DIR__ . '/../fullchain.pem';
$keyTls  = __DIR__ . '/../key.pem';
foreach (['fullchain.pem' => $certTls, 'key.pem' => $keyTls] as $nombre => $ruta) {
    if (! is_file($ruta) || ! is_readable($ruta)) {
        fail("No se encuentra {$nombre} para el TLS mutuo en: {$ruta}");
    }
}

$http = new Client([
    'timeout' => 60,
    'cert'    => $certTls,
    'ssl_key' => $keyTls,
    'verify'  => true,
]);

$auth = new BoletaAutenticador($http, new XmlSigner());

echo "*** TOKEN BOLETA ({$ambienteArg}) para emisor {$rut} ***\n";

try {
    $cert  = $emisor->obtenerCertificado($rut, $ambiente);
    $token = $auth->obtenerToken($cert, $ambiente);

    echo "OK - token de boleta obtenido\n";
    echo "  Token: {$token}\n";
    echo "  Largo: " . strlen($token) . "\n";
} catch (SiiAutenticacionException $e) {
    fail(sprintf(
        "Fallo de autenticacion de boleta\n  clase: %s\n  estadoSii: %s\n  glosaSii: %s\n  mensaje: %s",
        get_class($e),
        $e->estadoSii,
        $e->glosaSii,
        $e->getMessage(),
    ));
} catch (Throwable $e) {
    fail(sprintf(
        "Fallo al obtener token\n  clase: %s\n  mensaje: %s",
        get_class($e),
        $e->getMessage(),
    ));
}
