<?php

declare(strict_types=1);

/**
 * Consulta el estado de un envio de boleta por trackid (solo lectura).
 * USO: php scripts/consultar_boleta_envio.php <ambiente> <rut_emisor> <trackid>
 */

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Sii\BoletaAutenticador;
use Plantiflex\FacturacionCl\Sii\BoletaConsultor;
use Plantiflex\FacturacionCl\Sii\XmlSigner;
use Plantiflex\Integration\Facturacion\CertificadoCrypto;
use Plantiflex\Integration\Facturacion\MySqlEmisorRepository;

function fail(string $m): never { fwrite(STDERR, "ERROR: {$m}\n"); exit(1); }
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
if (count($args) !== 3) { fail('Uso: php scripts/consultar_boleta_envio.php <ambiente> <rut_emisor> <trackid>'); }
[$ambienteArg, $rut, $trackId] = $args;
$ambiente = $ambienteArg === 'produccion' ? Ambiente::Produccion : Ambiente::Certificacion;

$certTls = __DIR__ . '/../fullchain.pem';
$keyTls  = __DIR__ . '/../key.pem';
foreach ([$certTls, $keyTls] as $f) { if (! is_readable($f)) { fail("No se encuentra {$f}"); } }

$http   = new Client(['timeout' => 60, 'cert' => $certTls, 'ssl_key' => $keyTls, 'verify' => true]);
$emisor = new MySqlEmisorRepository(db(), crypto());
$cert   = $emisor->obtenerCertificado($rut, $ambiente);
$token  = (new BoletaAutenticador($http, new XmlSigner()))->obtenerToken($cert, $ambiente);

$res = (new BoletaConsultor($http))->consultarEnvio($rut, $trackId, $token, $ambiente);

echo "*** ESTADO ENVIO BOLETA trackid {$trackId} ({$ambienteArg}) ***\n";
echo "  Estado:     {$res['estado']}\n";
echo "  Informados: {$res['informados']}\n";
echo "  Aceptados:  {$res['aceptados']}\n";
echo "  Rechazados: {$res['rechazados']}\n";
echo "  Reparos:    {$res['reparos']}\n";
if ($res['errores'] !== []) {
    echo "  Errores:\n";
    foreach ($res['errores'] as $e) { echo "    - {$e}\n"; }
}
