<?php
declare(strict_types=1);
/**
 * Construye, firma y envía un RVD (ConsumoFolios) de boleta al SII.
 * Datos del SET DE CERTIFICACION: tipo 39, folios 144-148, fecha 2026-07-03.
 * USO: php scripts/emitir_rvd_boleta.php
 */
require __DIR__ . '/../vendor/autoload.php';
use GuzzleHttp\Client;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Sii\RvdBuilder;
use Plantiflex\FacturacionCl\Sii\SiiAutenticador;
use Plantiflex\FacturacionCl\Sii\SiiUploader;
use Plantiflex\FacturacionCl\Sii\XmlSigner;
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
$rutEmisor = '77724622-4';
$rutEnvia  = '13520634-2';
$ambiente  = Ambiente::Certificacion;
$pdo    = conectarDb();
$crypto = crearCrypto();
$folios = new MySqlFolioRepository(
    $pdo,
    function (string $c) use ($crypto): string {
        return $crypto->descifrar($c);
    },
);
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
$cert  = $emisorRepo->obtenerCertificado($rutEmisor, $ambiente);
$datos = $emisorRepo->obtenerDatosEmisor($rutEmisor, $ambiente);
$signer = new XmlSigner();
$token  = (new SiiAutenticador($http, $signer))->obtenerToken($cert, $ambiente);
// ---------------------------------------------------------------------------
// RVD del SET: tipo 39, folios 144-148, totales reales de las 5 boletas.
// ---------------------------------------------------------------------------
$fechaRvd = '2026-07-03';
$resumen = [
    'tipoDocumento'    => 39,
    'mntNeto'          => 43831,
    'mntIva'           => 8329,
    'tasaIva'          => 19,
    'mntExento'        => 2000,
    'mntTotal'         => 54160,
    'foliosEmitidos'   => 5,
    'foliosAnulados'   => 0,
    'foliosUtilizados' => 5,
    'rangos'           => [[144, 148]],
];
$builder = new RvdBuilder();
$doc     = $builder->build($datos, $rutEnvia, $fechaRvd, 1, [$resumen], $ambiente);
$docCF = $doc->getElementsByTagNameNS('http://www.sii.cl/SiiDte', 'DocumentoConsumoFolios')->item(0);
if (! $docCF instanceof DOMElement) {
    fail('No se encontro DocumentoConsumoFolios en el documento generado');
}
$idCF = $docCF->getAttribute('ID');
$signer->insertarEsqueletoFirma($docCF, $idCF, $cert);
$builder->agregarSchemaLocation($doc);
$signer->congelar($doc);
$signer->calcularDigestYFirmar($doc, $idCF, $cert);
$utf8   = XmlSigner::limpiarPrefijosDsig((string) $doc->saveXML());
$iso    = (string) mb_convert_encoding($utf8, 'ISO-8859-1', 'UTF-8');
$xmlRvd = (string) preg_replace(
    '/(<\?xml[^>]*encoding=")UTF-8("[^>]*\?>)/i',
    '${1}ISO-8859-1${2}',
    $iso,
    1,
);
$rutaDump = dirname(__DIR__) . '/rvd_boleta_debug.xml';
file_put_contents($rutaDump, $xmlRvd);
echo "XML firmado guardado en {$rutaDump}\n";
echo "*** ENVIO RVD (ConsumoFolios) SET certificacion emisor {$rutEmisor} ***\n";
$resultado = (new SiiUploader($http))->subir($xmlRvd, $token, $rutEnvia, $rutEmisor, $ambiente);
echo "  TrackID: " . ($resultado['trackId'] ?? '(null)') . "\n";
echo "  Raw: " . json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
