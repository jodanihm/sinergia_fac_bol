<?php

declare(strict_types=1);

/**
 * Consulta el estado de un ENVIO al SII por TrackID. NO consume folios.
 *
 * USO:
 *   php scripts/consultar_estado.php <ambiente> <rut_emisor> <trackId>
 *
 * EJEMPLO:
 *   DB_HOST=localhost DB_NAME=plantiflex DB_USER=root DB_PASS=secreto \
 *   CRYPTO_MASTER_KEY=<64 hex> \
 *   php scripts/consultar_estado.php certificacion 77724622-4 0249995332
 *
 * REQUISITOS: certificado del emisor cargado en la BD (para autenticar).
 *
 * VARIABLES DE ENTORNO:
 *   DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_PORT(opcional)  -> conexion MySQL.
 *   CRYPTO_MASTER_KEY                                      -> 32 bytes en HEX.
 */

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use Plantiflex\FacturacionCl\Dto\Credenciales;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Providers\SiiDirectoFacturador;
use Plantiflex\Integration\Facturacion\CertificadoCrypto;
use Plantiflex\Integration\Facturacion\MySqlEmisorRepository;
use Plantiflex\Integration\Facturacion\MySqlFolioRepository;

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
if (count($args) !== 3) {
    fail("Uso: php scripts/consultar_estado.php <ambiente> <rut_emisor> <trackId>");
}
[$ambienteArg, $rut, $trackId] = $args;

if ($ambienteArg === 'certificacion') {
    $ambiente = Ambiente::Certificacion;
} elseif ($ambienteArg === 'produccion') {
    $ambiente = Ambiente::Produccion;
} else {
    fail("ambiente debe ser 'certificacion' o 'produccion'.");
}
if (trim($rut) === '' || trim($trackId) === '') {
    fail('rut_emisor y trackId no pueden ser vacios.');
}

$pdo    = conectarDb();
$crypto = crearCrypto();

// TLS mutuo: mismo certificado que en emitir_real.php.
$certTls = __DIR__ . '/../fullchain.pem';
$keyTls  = __DIR__ . '/../key.pem';
foreach (['fullchain.pem' => $certTls, 'key.pem' => $keyTls] as $nombre => $ruta) {
    if (! is_file($ruta) || ! is_readable($ruta)) {
        fail("No se encuentra {$nombre} para el TLS mutuo en: {$ruta}");
    }
}

$folios = new MySqlFolioRepository(
    $pdo,
    function (string $c) use ($crypto): string {
        return $crypto->descifrar($c);
    },
);
$emisor = new MySqlEmisorRepository($pdo, $crypto);

$facturador = new SiiDirectoFacturador(
    new Client([
        'timeout' => 60,
        'cert'    => $certTls,
        'ssl_key' => $keyTls,
        'verify'  => true,
    ]),
    $folios,
    $emisor,
);

$cred = new Credenciales(
    rutEmisor: $rut,
    apiToken:  'no-usado-por-sii-directo',
    ambiente:  $ambiente,
    rutSender: '13520634-2',
);

echo "Consultando estado del envio TrackID={$trackId} ({$ambienteArg}) emisor {$rut}\n";

try {
    $estado = $facturador->consultarEnvioPorTrackId($trackId, $cred);
    echo "  Estado: {$estado->estado}\n";
    echo "  Glosa:  " . ($estado->glosa ?? '(sin glosa)') . "\n";
    echo "  TrackID: " . ($estado->trackId ?? '(null)') . "\n";
} catch (Throwable $e) {
    fail(sprintf("Fallo la consulta\n  clase: %s\n  mensaje: %s", get_class($e), $e->getMessage()));
}
