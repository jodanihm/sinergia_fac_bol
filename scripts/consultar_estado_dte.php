<?php

declare(strict_types=1);

/**
 * Consulta el ESTADO DE UN DTE INDIVIDUAL en el SII (QueryEstDte.jws, metodo
 * getEstDte). A diferencia de consultar_estado.php (que consulta el ENVIO por
 * TrackId y devuelve EPR), esto consulta el documento puntual y devuelve su
 * estado de aceptacion: DOK (recibido conforme), DNK (datos no coinciden), u
 * otro rechazo.
 *
 * Lee tipo, folio, fecha, rut receptor y monto DIRECTAMENTE del XML EnvioDTE
 * indicado, para que coincidan EXACTO con lo enviado (si no coinciden, el SII
 * responde DNK).
 *
 * USO:
 *   php scripts/consultar_estado_dte.php <ambiente> <rut_emisor> <ruta_xml>
 *
 * EJEMPLO:
 *   DB_HOST=127.0.0.1 DB_PORT=3308 DB_NAME=facturacion_cl \
 *   DB_USER=facturacion DB_PASS=facturacion2026 CRYPTO_MASTER_KEY=<64hex> \
 *   php scripts/consultar_estado_dte.php certificacion 77724622-4 oracle/dte_libredte.xml
 */

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Sii\SiiAutenticador;
use Plantiflex\FacturacionCl\Sii\SiiConsultor;
use Plantiflex\FacturacionCl\Sii\XmlSigner;
use Plantiflex\Integration\Facturacion\CertificadoCrypto;
use Plantiflex\Integration\Facturacion\MySqlEmisorRepository;

const RUT_CONSULTANTE = '13520634-2'; // firmante que autentica

function fail(string $msg): never
{
    fwrite(STDERR, "ERROR: {$msg}\n");
    exit(1);
}

function env(string $n, ?string $def = null): string
{
    $v = getenv($n);
    if ($v === false) {
        if ($def !== null) {
            return $def;
        }
        fail("Falta variable de entorno {$n}.");
    }
    return $v;
}

/** "13520634-2" -> ["13520634","2"] */
function rutDv(string $rut): array
{
    $l = str_replace('.', '', trim($rut));
    [$n, $d] = explode('-', $l, 2);
    return [$n, strtoupper($d)];
}

function tag(DOMDocument $doc, string $name): string
{
    $n = $doc->getElementsByTagName($name)->item(0);
    if ($n === null) {
        fail("No se encontro <{$name}> en el XML.");
    }
    return trim((string) $n->textContent);
}

// --- Args ---
$args = array_slice($argv, 1);
if (count($args) !== 3) {
    fail('Uso: php scripts/consultar_estado_dte.php <ambiente> <rut_emisor> <ruta_xml>');
}
[$ambienteArg, $rutEmisor, $rutaXml] = $args;

$ambiente = match ($ambienteArg) {
    'certificacion' => Ambiente::Certificacion,
    'produccion'    => Ambiente::Produccion,
    default         => fail("ambiente debe ser 'certificacion' o 'produccion'."),
};

if (! is_file($rutaXml) || ! is_readable($rutaXml)) {
    fail("No se encuentra el XML: {$rutaXml}");
}

// --- Parsear el DTE para sacar los datos EXACTOS ---
$xmlStr = (string) file_get_contents($rutaXml);
$dom = new DOMDocument();
$prev = libxml_use_internal_errors(true);
$ok = $dom->loadXML($xmlStr);
libxml_clear_errors();
libxml_use_internal_errors($prev);
if (! $ok) {
    fail("No se pudo parsear el XML: {$rutaXml}");
}

$tipoDte  = tag($dom, 'TipoDTE');
$folio    = tag($dom, 'Folio');
$fchEmis  = tag($dom, 'FchEmis');   // AAAA-MM-DD
$rutRecep = tag($dom, 'RUTRecep');  // ej 60803000-K
$mntTotal = tag($dom, 'MntTotal');

// FechaEmisionDte que espera getEstDte: dd-mm-aaaa (String Date, largo 10).
[$Y, $m, $d] = explode('-', $fchEmis);
$fechaEstDte = "{$d}-{$m}-{$Y}";

echo "Consultando estado DTE en {$ambienteArg}:\n";
echo "  Tipo {$tipoDte}  Folio {$folio}  Fecha {$fechaEstDte}  Monto {$mntTotal}\n";
echo '  Emisor ' . $rutEmisor . '  Receptor ' . $rutRecep . '  Consultante ' . RUT_CONSULTANTE . "\n";

// --- BD + crypto + certificado del emisor ---
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', env('DB_HOST'), env('DB_PORT', '3306'), env('DB_NAME')),
        env('DB_USER'),
        env('DB_PASS', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
} catch (PDOException $e) {
    fail('No se pudo conectar a la base: ' . $e->getMessage());
}

$bin = @hex2bin(env('CRYPTO_MASTER_KEY'));
if ($bin === false || strlen($bin) !== CertificadoCrypto::KEY_LENGTH) {
    fail('CRYPTO_MASTER_KEY debe ser ' . CertificadoCrypto::KEY_LENGTH . ' bytes en HEX.');
}
$crypto = new CertificadoCrypto($bin);
$emisor = new MySqlEmisorRepository($pdo, $crypto);
try {
    $cert = $emisor->obtenerCertificado($rutEmisor, $ambiente);
} catch (Throwable $e) {
    fail('No se pudo cargar el certificado: ' . $e->getMessage());
}

// --- TLS mutuo + token ---
$certTls = __DIR__ . '/../fullchain.pem';
$keyTls  = __DIR__ . '/../key.pem';
foreach (['fullchain.pem' => $certTls, 'key.pem' => $keyTls] as $nombre => $ruta) {
    if (! is_file($ruta) || ! is_readable($ruta)) {
        fail("No se encuentra {$nombre} para el TLS mutuo en: {$ruta}");
    }
}
$http = new Client(['timeout' => 60, 'cert' => $certTls, 'ssl_key' => $keyTls, 'verify' => true]);

try {
    $token = (new SiiAutenticador($http, new XmlSigner()))->obtenerToken($cert, $ambiente);
} catch (Throwable $e) {
    fail('Fallo la autenticacion: ' . $e->getMessage());
}

// --- getEstDte via SiiConsultor (logica reutilizable) ---
$consultor = new SiiConsultor($http);
try {
    $res = $consultor->consultarDte(
        RUT_CONSULTANTE,
        $rutEmisor,
        $rutRecep,
        (int) $tipoDte,
        (int) $folio,
        $fchEmis,
        (int) $mntTotal,
        $token,
        $ambiente,
    );
} catch (Throwable $e) {
    fail('Fallo getEstDte: ' . $e->getMessage());
}

echo "\n=== RESPUESTA getEstDte ===\n";
echo '  ESTADO:    ' . ($res['estado'] ?: '(vacio)') . "\n";
echo '  GLOSA:     ' . ($res['glosa'] ?: '(vacio)') . "\n";
echo '  ERR_CODE:  ' . ($res['errCode'] ?: '(vacio)') . "\n";
echo '  GLOSA_ERR: ' . ($res['glosaErr'] ?: '(vacio)') . "\n";
echo "\n--- XML interno completo ---\n" . $res['inner'] . "\n";
echo "\n--- RAW SOAP ---\n" . $res['raw'] . "\n";
