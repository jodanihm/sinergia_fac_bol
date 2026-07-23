<?php

declare(strict_types=1);

/**
 * Consulta el estado de un envío al SII por trackid (canal clásico QueryEstUp.jws).
 * USO: php scripts/consultar_estado_envio.php <trackid> [rut_emisor]
 *   rut_emisor default: 77724622-4
 */

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Sii\SiiAutenticador;
use Plantiflex\FacturacionCl\Sii\SiiConsultor;
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
if (count($args) < 1 || trim($args[0]) === '') {
    fail('Uso: php scripts/consultar_estado_envio.php <trackid> [rut_emisor]');
}
$trackId   = trim($args[0]);
$rutEmisor = isset($args[1]) && trim($args[1]) !== '' ? trim($args[1]) : '77724622-4';
$ambiente  = Ambiente::Certificacion;

$pdo    = conectarDb();
$crypto = crearCrypto();

$emisorRepo = new MySqlEmisorRepository($pdo, $crypto);

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

// ---------------------------------------------------------------------------
// Token clásico (SiiAutenticador / DTEWS)
// ---------------------------------------------------------------------------

$cert  = $emisorRepo->obtenerCertificado($rutEmisor, $ambiente);
$token = (new SiiAutenticador($http, new XmlSigner()))->obtenerToken($cert, $ambiente);

// ---------------------------------------------------------------------------
// Consulta de estado
// ---------------------------------------------------------------------------

$resultado = (new SiiConsultor($http))->consultarEnvio($rutEmisor, $trackId, $token, $ambiente);

echo "*** ESTADO ENVIO trackid {$trackId} (certificacion) emisor {$rutEmisor} ***\n";
echo "  Estado: {$resultado['estado']}\n";
echo "  Glosa:  {$resultado['glosa']}\n";
echo "  Raw:\n{$resultado['raw']}\n";
