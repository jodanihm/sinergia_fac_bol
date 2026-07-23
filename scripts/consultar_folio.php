<?php

declare(strict_types=1);

/**
 * Consulta el ESTADO REAL de un DTE por folio (getEstDte -> DOK/DNK) usando
 * SiiDirectoFacturador::consultarEstado(). Recupera el XML desde dte_emitido
 * (fuente de verdad) y sincroniza el estado en la BD. NO consume folios.
 *
 * USO:
 *   php scripts/consultar_folio.php <ambiente> <rut_emisor> <tipo_dte> <folio>
 *
 * EJEMPLO:
 *   php scripts/consultar_folio.php certificacion 77724622-4 33 67
 *
 * VARIABLES DE ENTORNO: DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_PORT(opc),
 * CRYPTO_MASTER_KEY (32 bytes en HEX).
 */

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use Plantiflex\FacturacionCl\Dto\Credenciales;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Providers\SiiDirectoFacturador;
use Plantiflex\Integration\Facturacion\CertificadoCrypto;
use Plantiflex\Integration\Facturacion\MySqlDteEmitidoRepository;
use Plantiflex\Integration\Facturacion\MySqlEmisorRepository;
use Plantiflex\Integration\Facturacion\MySqlFolioRepository;

function fail(string $msg): never
{
    fwrite(STDERR, "ERROR: {$msg}\n");
    exit(1);
}

function requerirEnv(string $nombre): string
{
    $v = getenv($nombre);
    if ($v === false) {
        fail("Falta la variable de entorno {$nombre}.");
    }
    return $v;
}

$args = array_slice($argv, 1);
if (count($args) !== 4) {
    fail('Uso: php scripts/consultar_folio.php <ambiente> <rut_emisor> <tipo_dte> <folio>');
}
[$ambienteArg, $rut, $tipoArg, $folioArg] = $args;

$ambiente = match ($ambienteArg) {
    'certificacion' => Ambiente::Certificacion,
    'produccion'    => Ambiente::Produccion,
    default         => fail("ambiente debe ser 'certificacion' o 'produccion'."),
};
$tipoDte = (int) $tipoArg;
$folio   = (int) $folioArg;
if ($tipoDte <= 0 || $folio <= 0) {
    fail('tipo_dte y folio deben ser enteros > 0.');
}

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', requerirEnv('DB_HOST'), getenv('DB_PORT') ?: '3306', requerirEnv('DB_NAME')),
        requerirEnv('DB_USER'),
        getenv('DB_PASS') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
} catch (PDOException $e) {
    fail('No se pudo conectar a la base: ' . $e->getMessage());
}

$bin = @hex2bin(requerirEnv('CRYPTO_MASTER_KEY'));
if ($bin === false || strlen($bin) !== CertificadoCrypto::KEY_LENGTH) {
    fail('CRYPTO_MASTER_KEY debe ser ' . CertificadoCrypto::KEY_LENGTH . ' bytes en HEX.');
}
$crypto = new CertificadoCrypto($bin);

$folios  = new MySqlFolioRepository($pdo, fn (string $c): string => $crypto->descifrar($c));
$emisor  = new MySqlEmisorRepository($pdo, $crypto);
$emitido = new MySqlDteEmitidoRepository($pdo);

$certTls = __DIR__ . '/../fullchain.pem';
$keyTls  = __DIR__ . '/../key.pem';
foreach (['fullchain.pem' => $certTls, 'key.pem' => $keyTls] as $nombre => $ruta) {
    if (! is_file($ruta) || ! is_readable($ruta)) {
        fail("No se encuentra {$nombre} para el TLS mutuo en: {$ruta}");
    }
}

$facturador = new SiiDirectoFacturador(
    new Client(['timeout' => 60, 'cert' => $certTls, 'ssl_key' => $keyTls, 'verify' => true]),
    $folios,
    $emisor,
    dteEmitido: $emitido,
);

$cred = new Credenciales(
    rutEmisor: $rut,
    apiToken:  'no-usado-por-sii-directo',
    ambiente:  $ambiente,
    rutSender: '13520634-2',
);

echo "Consultando estado real de tipo {$tipoDte} folio {$folio} ({$ambienteArg}) emisor {$rut}\n";

try {
    $estado = $facturador->consultarEstado($folio, $tipoDte, $cred);
} catch (Throwable $e) {
    fail(sprintf("Fallo la consulta\n  clase: %s\n  mensaje: %s", get_class($e), $e->getMessage()));
}

echo "  Estado: {$estado->estado}\n";
echo '  Glosa:  ' . ($estado->glosa ?? '(sin glosa)') . "\n";
