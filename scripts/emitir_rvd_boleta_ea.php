<?php

declare(strict_types=1);

/**
 * Construye, firma y (opcionalmente) envia el RVD (ConsumoFolios, ex-RCOF) de
 * boleta para EASY AGENDA SPA (78157243-8), del set folios 101-105 tipo 39,
 * fecha 2026-07-15.
 *
 * CANAL DE ENVIO: SiiAutenticador + SiiUploader (SOAP maullin/palena, el
 * mismo canal clasico de FACTURA) -- igual que scripts/emitir_rvd_boleta.php
 * (Plantiflex, que NO se toca). NO usar BoletaAutenticador/BoletaUploader
 * (REST pangal/rahue): un intento real con ese canal fue rechazado por el
 * SII con "SCH-00001: Invalid Schema Name". Confirmado en
 * docs/44_API_Boleta_Electronica_OpenAPI_Spec.yaml:150: "El sitio de
 * palena.sii.cl es la plataforma dedicada para la recepcion de DTE y RVD en
 * Produccion" -- el canal REST de boleta (rahue/api en produccion,
 * pangal/apicert en certificacion) es EXCLUSIVO para boletas, sin ningun
 * endpoint de ConsumoFolios/RVD (confirmado listando los 10 endpoints del
 * spec: ninguno es de consumo de folios).
 *
 * MODO DRY-RUN POR DEFECTO: sin --enviar, solo construye/firma/imprime el XML
 * y lo guarda en rvd_boleta_ea_debug.xml -- NO llama al SII. Correr con
 * --enviar SOLO cuando el payload impreso este revisado y aprobado.
 *
 * USO:
 *   php scripts/emitir_rvd_boleta_ea.php            (dry-run: solo genera/muestra el XML)
 *   php scripts/emitir_rvd_boleta_ea.php --enviar    (ademas lo sube al SII)
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

$enviar = in_array('--enviar', array_slice($argv, 1), true);

$rutEmisor = '78157243-8';
$rutEnvia  = '13520634-2';
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

$cert  = $emisorRepo->obtenerCertificado($rutEmisor, $ambiente);
$datos = $emisorRepo->obtenerDatosEmisor($rutEmisor, $ambiente);
$signer = new XmlSigner();

// ---------------------------------------------------------------------------
// RVD del SET DE PRUEBAS: tipo 39, folios 101-105, totales reales de las 5
// boletas ya emitidas y en EPR (scripts/emitir_set_boletas_ea.php).
//
// Desglose por boleta (mismos montos/formula que DteXmlBuilder::resolverTotales(),
// montosSonBrutos=true -> neto = round(bruto / 1.19), iva = round(neto * 0.19)):
//   CASO-1 (folio 101): bruto afecto 29800 (19900+9900)      -> neto 25042, iva 4758
//   CASO-2 (folio 102): bruto afecto  2040 (17x120)          -> neto  1714, iva  326
//   CASO-3 (folio 103): bruto afecto  4100 (2x1500+2x550)    -> neto  3445, iva  655
//   CASO-4 (folio 104): bruto afecto 12720 (8x1590) + exento 2000 (2x1000)
//                                                             -> neto 10689, iva 2031
//   CASO-5 (folio 105): bruto afecto  3500 (5x700)           -> neto  2941, iva  559
//   Sumas -> Neto=43831 IVA=8329 Exento=2000 Total=54160 (verificado con la
//   MISMA aritmetica que el envio real aceptado de Plantiflex, folios 144-148,
//   track 30392731: identicos montos por caso, mismo resultado).
// ---------------------------------------------------------------------------
$fechaRvd = '2026-07-15';
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
    'rangos'           => [[101, 105]],
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

$utf8 = XmlSigner::limpiarPrefijosDsig((string) $doc->saveXML());
$iso  = (string) mb_convert_encoding($utf8, 'ISO-8859-1', 'UTF-8');
$xmlRvd = (string) preg_replace(
    '/(<\?xml[^>]*encoding=")UTF-8("[^>]*\?>)/i',
    '${1}ISO-8859-1${2}',
    $iso,
    1,
);

$rutaDump = dirname(__DIR__) . '/rvd_boleta_ea_debug.xml';
file_put_contents($rutaDump, $xmlRvd);

// --- Validacion XSD OPCIONAL, NO bloqueante: ConsumoFolio_v10.xsd no existe
// hoy en oracle/LibreDTE-master/schemas/ (confirmado). Si en algun momento se
// agrega, se valida y se avisa; si no esta, se avisa y se sigue igual. ---
$xsd = __DIR__ . '/../oracle/LibreDTE-master/schemas/ConsumoFolio_v10.xsd';
if (is_file($xsd)) {
    $paraValidar = new DOMDocument();
    $paraValidar->loadXML($xmlRvd);
    libxml_use_internal_errors(true);
    $ok = $paraValidar->schemaValidate($xsd);
    $errs = array_map(static fn ($e) => trim($e->message), libxml_get_errors());
    libxml_clear_errors();
    echo 'Validacion XSD (' . basename($xsd) . '): ' . ($ok ? 'VALIDO' : 'INVALIDO') . "\n";
    foreach ($errs as $e) {
        echo "   - {$e}\n";
    }
} else {
    echo "AVISO: no se valido contra XSD -- {$xsd} no existe en este repo (no bloqueante).\n";
}

echo "\n=== XML DEL RVD (revisa esto ANTES de correr con --enviar) ===\n";
echo $xmlRvd . "\n";
echo "=== FIN XML ===\n\n";
echo "XML firmado guardado en {$rutaDump}\n";

if (! $enviar) {
    echo "\nDRY-RUN: no se envio nada al SII. Corre con --enviar cuando lo hayas revisado.\n";
    exit(0);
}

echo "\n*** ENVIANDO RVD (ConsumoFolios) al SII -- canal DTE clasico (maullin), emisor {$rutEmisor} ***\n";
$token = (new SiiAutenticador($http, $signer))->obtenerToken($cert, $ambiente);

$resultado = (new SiiUploader($http))->subir($xmlRvd, $token, $rutEnvia, $rutEmisor, $ambiente);
echo '  TrackID: ' . ($resultado['trackId'] ?? '(null)') . "\n";
echo '  Status : ' . ($resultado['status'] ?? '(null)') . "\n";
echo '  Raw    : ' . json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
