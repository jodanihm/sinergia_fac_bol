<?php

declare(strict_types=1);

/**
 * Consulta el estado de varios envios de boleta por trackId (solo lectura).
 * Obtiene el token REST UNA vez y lo reusa para todas las consultas.
 *
 * USO:
 *   php scripts/consultar_set_boletas.php <ambiente> <rut_emisor> <trackId1> [trackId2 ...]
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

// ---------------------------------------------------------------------------
// Args
// ---------------------------------------------------------------------------

$args = array_slice($argv, 1);
if (count($args) < 3) {
    fail('Uso: php scripts/consultar_set_boletas.php <ambiente> <rut_emisor> <trackId1> [trackId2 ...]');
}

$ambienteArg = array_shift($args);
$rut         = array_shift($args);
$trackIds    = $args; // uno o mas

if ($ambienteArg === 'produccion') {
    $ambiente = Ambiente::Produccion;
} elseif ($ambienteArg === 'certificacion') {
    $ambiente = Ambiente::Certificacion;
} else {
    fail("ambiente debe ser 'certificacion' o 'produccion'.");
}

// ---------------------------------------------------------------------------
// Bootstrap: HTTP, certificado, token (una sola vez)
// ---------------------------------------------------------------------------

$certTls = __DIR__ . '/../fullchain.pem';
$keyTls  = __DIR__ . '/../key.pem';
foreach (['fullchain.pem' => $certTls, 'key.pem' => $keyTls] as $nombre => $ruta) {
    if (! is_readable($ruta)) { fail("No se encuentra {$nombre}: {$ruta}"); }
}

$http   = new Client(['timeout' => 60, 'cert' => $certTls, 'ssl_key' => $keyTls, 'verify' => true]);
$emisor = new MySqlEmisorRepository(db(), crypto());
$cert   = $emisor->obtenerCertificado($rut, $ambiente);
$token  = (new BoletaAutenticador($http, new XmlSigner()))->obtenerToken($cert, $ambiente);

$consultor = new BoletaConsultor($http);

echo "*** CONSULTA SET BOLETAS ({$ambienteArg}) emisor {$rut} — " . count($trackIds) . " trackId(s) ***\n\n";

// ---------------------------------------------------------------------------
// Consultar cada trackId
// ---------------------------------------------------------------------------

/** @var array<string, array{estado:string, informados:int, aceptados:int, rechazados:int, reparos:int, errores:list<string>}> $resultados */
$resultados = [];

foreach ($trackIds as $trackId) {
    echo "--- trackId {$trackId} ---\n";
    try {
        $res = $consultor->consultarEnvio($rut, $trackId, $token, $ambiente);

        echo "  Estado:     {$res['estado']}\n";
        echo "  Informados: {$res['informados']}\n";
        echo "  Aceptados:  {$res['aceptados']}\n";
        echo "  Rechazados: {$res['rechazados']}\n";
        echo "  Reparos:    {$res['reparos']}\n";

        if ($res['errores'] !== []) {
            echo "  Errores/reparos:\n";
            foreach ($res['errores'] as $e) {
                echo "    - {$e}\n";
            }
        }

        $resultados[$trackId] = $res;
    } catch (Throwable $e) {
        $msg = get_class($e) . ': ' . $e->getMessage();
        echo "  ERROR: {$msg}\n";
        $resultados[$trackId] = [
            'estado'     => 'ERROR',
            'informados' => 0,
            'aceptados'  => 0,
            'rechazados' => 0,
            'reparos'    => 0,
            'errores'    => [$msg],
        ];
    }
    echo "\n";
}

// ---------------------------------------------------------------------------
// Resumen final
// ---------------------------------------------------------------------------

echo str_repeat('=', 72) . "\n";
echo "RESUMEN\n";
echo str_repeat('=', 72) . "\n";
printf("%-20s %-8s %10s %10s %10s\n", 'trackId', 'Estado', 'Aceptados', 'Rechazados', 'Reparos');
echo str_repeat('-', 72) . "\n";
foreach ($resultados as $tid => $r) {
    printf(
        "%-20s %-8s %10d %10d %10d\n",
        $tid,
        $r['estado'],
        $r['aceptados'],
        $r['rechazados'],
        $r['reparos'],
    );
}
echo str_repeat('=', 72) . "\n";
