<?php

declare(strict_types=1);

/**
 * Consulta el estado real de una boleta (39) por su trackid (BoletaConsultor,
 * REST) y sincroniza dte_emitido.estado (de 'enviado' al estado real del SII).
 * Solo lectura hacia el SII; el unico escritura es el UPDATE de estado (no
 * toca folios, XML ni ningun otro campo).
 *
 * USO:
 *   php scripts/actualizar_estado_boleta.php <ambiente> <rut_emisor> <folio>
 *
 * El trackid se lee de dte_emitido (columna track_id), no se pide por argumento.
 *
 * NO toca SiiDirectoFacturador ni ningun flujo de factura/NC/ND.
 */

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Sii\BoletaAutenticador;
use Plantiflex\FacturacionCl\Sii\BoletaConsultor;
use Plantiflex\FacturacionCl\Sii\XmlSigner;
use Plantiflex\Integration\Facturacion\CertificadoCrypto;
use Plantiflex\Integration\Facturacion\MySqlDteEmitidoRepository;
use Plantiflex\Integration\Facturacion\MySqlEmisorRepository;

function fail(string $m): never
{
    fwrite(STDERR, "ERROR: {$m}\n");
    exit(1);
}

function env_(string $n): string
{
    $v = getenv($n);
    if ($v === false) {
        fail("Falta env {$n}");
    }
    return $v;
}

function db(): PDO
{
    $port = getenv('DB_PORT');
    $port = $port === false ? '3306' : $port;
    $pass = getenv('DB_PASS');
    $pass = $pass === false ? '' : $pass;
    return new PDO(
        'mysql:host=' . env_('DB_HOST') . ';port=' . $port . ';dbname=' . env_('DB_NAME') . ';charset=utf8mb4',
        env_('DB_USER'),
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

function crypto(): CertificadoCrypto
{
    $bin = @hex2bin(env_('CRYPTO_MASTER_KEY'));
    if ($bin === false || strlen($bin) !== CertificadoCrypto::KEY_LENGTH) {
        fail('CRYPTO_MASTER_KEY invalida');
    }
    return new CertificadoCrypto($bin);
}

$args = array_slice($argv, 1);
if (count($args) !== 3) {
    fail('Uso: php scripts/actualizar_estado_boleta.php <ambiente> <rut_emisor> <folio>');
}
[$ambienteArg, $rut, $folioArg] = $args;
if (! in_array($ambienteArg, ['certificacion', 'produccion'], true)) {
    fail("ambiente debe ser 'certificacion' o 'produccion'.");
}
$ambiente = $ambienteArg === 'produccion' ? Ambiente::Produccion : Ambiente::Certificacion;
$folio    = (int) $folioArg;
if ($folio <= 0) {
    fail('folio debe ser > 0.');
}

$pdo        = db();
$dteEmitido = new MySqlDteEmitidoRepository($pdo);

// Leer track_id y estado actual directo de dte_emitido (no se pide por argumento).
$stmt = $pdo->prepare(
    'SELECT track_id, estado FROM dte_emitido '
    . 'WHERE rut_emisor = :rut AND ambiente = :amb AND tipo_dte = 39 AND folio = :folio LIMIT 1'
);
$stmt->execute([':rut' => $rut, ':amb' => $ambiente->value, ':folio' => $folio]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row === false) {
    fail("No hay boleta persistida en dte_emitido para folio {$folio} ({$ambienteArg}). No se puede sincronizar.");
}
$trackId = $row['track_id'];
if ($trackId === null || $trackId === '') {
    fail("La boleta folio {$folio} no tiene track_id registrado.");
}
$estadoAnterior = $row['estado'];

$certTls = __DIR__ . '/../fullchain.pem';
$keyTls  = __DIR__ . '/../key.pem';
foreach ([$certTls, $keyTls] as $f) {
    if (! is_readable($f)) {
        fail("No se encuentra {$f}");
    }
}

$http   = new Client(['timeout' => 60, 'cert' => $certTls, 'ssl_key' => $keyTls, 'verify' => true]);
$emisor = new MySqlEmisorRepository($pdo, crypto());
$cert   = $emisor->obtenerCertificado($rut, $ambiente);
$token  = (new BoletaAutenticador($http, new XmlSigner()))->obtenerToken($cert, $ambiente);

$res = (new BoletaConsultor($http))->consultarEnvio($rut, $trackId, $token, $ambiente);

$estadoNuevo = $res['estado'];
$dteEmitido->actualizarEstado($rut, $ambiente, 39, $folio, $estadoNuevo);

echo "*** SINCRONIZACION ESTADO BOLETA folio {$folio} ({$ambienteArg}), trackid {$trackId} ***\n";
echo "  Estado anterior (dte_emitido): {$estadoAnterior}\n";
echo "  Estado SII (consultarEnvio):   {$estadoNuevo}\n";
echo "  Informados: {$res['informados']}\n";
echo "  Aceptados:  {$res['aceptados']}\n";
echo "  Rechazados: {$res['rechazados']}\n";
echo "  Reparos:    {$res['reparos']}\n";
if ($res['errores'] !== []) {
    echo "  Errores:\n";
    foreach ($res['errores'] as $e) {
        echo "    - {$e}\n";
    }
}
echo "  dte_emitido.estado actualizado a: {$estadoNuevo}\n";
