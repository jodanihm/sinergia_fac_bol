<?php

declare(strict_types=1);

/**
 * PROBE (desechable): descubre el endpoint REST de consulta de estado de envio
 * de BOLETA. Usa el token de boleta y prueba varias formas candidatas contra
 * apicert, imprimiendo status + body de cada una. Solo consulta, no modifica.
 *
 * USO: php scripts/probe_estado_boleta.php <ambiente> <rut_emisor> <trackid>
 */

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Sii\BoletaAutenticador;
use Plantiflex\FacturacionCl\Sii\XmlSigner;
use Plantiflex\Integration\Facturacion\CertificadoCrypto;
use Plantiflex\Integration\Facturacion\MySqlEmisorRepository;

function fail(string $msg): never { fwrite(STDERR, "ERROR: {$msg}\n"); exit(1); }
function env_(string $n): string { $v = getenv($n); if ($v === false) { fail("Falta env {$n}"); } return $v; }

function db(): PDO {
    $port = getenv('DB_PORT'); $port = $port === false ? '3306' : $port;
    $pass = getenv('DB_PASS'); $pass = $pass === false ? '' : $pass;
    return new PDO(
        "mysql:host=".env_('DB_HOST').";port={$port};dbname=".env_('DB_NAME').";charset=utf8mb4",
        env_('DB_USER'), $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

function crypto(): CertificadoCrypto {
    $bin = @hex2bin(env_('CRYPTO_MASTER_KEY'));
    if ($bin === false || strlen($bin) !== CertificadoCrypto::KEY_LENGTH) { fail('CRYPTO_MASTER_KEY invalida'); }
    return new CertificadoCrypto($bin);
}

$args = array_slice($argv, 1);
if (count($args) !== 3) { fail('Uso: php scripts/probe_estado_boleta.php <ambiente> <rut_emisor> <trackid>'); }
[$ambienteArg, $rut, $trackId] = $args;
$ambiente = $ambienteArg === 'produccion' ? Ambiente::Produccion : Ambiente::Certificacion;
$apiHost  = $ambiente === Ambiente::Produccion ? 'https://api.sii.cl' : 'https://apicert.sii.cl';

[$num, $dv] = array_pad(explode('-', str_replace('.', '', trim($rut)), 2), 2, '');

$certTls = __DIR__ . '/../fullchain.pem';
$keyTls  = __DIR__ . '/../key.pem';
foreach ([$certTls, $keyTls] as $f) { if (! is_readable($f)) { fail("No se encuentra {$f}"); } }

$http   = new Client(['timeout' => 60, 'cert' => $certTls, 'ssl_key' => $keyTls, 'verify' => true]);
$emisor = new MySqlEmisorRepository(db(), crypto());
$cert   = $emisor->obtenerCertificado($rut, $ambiente);
$token  = (new BoletaAutenticador($http, new XmlSigner()))->obtenerToken($cert, $ambiente);

echo "Token OK (largo " . strlen($token) . "). Probando endpoints de estado...\n\n";

$base = $apiHost . '/recursos/v1';
$candidatos = [
    // Endpoint real (openapi SII): consulta estado de envio por trackid.
    "$base/boleta.electronica.envio/$num-$dv-$trackId",
];

foreach ($candidatos as $i => $url) {
    $n = $i + 1;
    echo "=== Candidato $n ===\nGET $url\n";
    try {
        $resp = $http->request('GET', $url, [
            'headers' => [
                'User-Agent' => 'Mozilla/4.0 (compatible; PROG 1.0; Windows NT)',
                'Cookie'     => 'TOKEN=' . $token,
                'Accept'     => 'application/json',
            ],
            'http_errors' => false,
        ]);
        $code = $resp->getStatusCode();
        $body = trim((string) $resp->getBody());
        echo "  HTTP $code\n";
        echo "  Body: " . (strlen($body) > 800 ? substr($body, 0, 800) . '...' : $body) . "\n\n";
    } catch (Throwable $e) {
        echo "  EXCEPCION: " . get_class($e) . " - " . $e->getMessage() . "\n\n";
    }
}
echo "Listo. El candidato que devuelva HTTP 200 con JSON de estado es el bueno.\n";
