<?php
declare(strict_types=1);
/**
 * Genera (y firma) las respuestas del intercambio a partir de un EnvioDTE recibido.
 * USO: php scripts/generar_respuesta_intercambio.php <ruta_envio_dte> [acuse|resultado|recibos|ambos|todas]
 *   acuse     -> respuesta_acuse.xml      (RecepcionEnvio)
 *   resultado -> respuesta_resultado.xml  (ResultadoDTE, aceptacion)
 *   recibos   -> respuesta_recibos.xml    (EnvioRecibos, Ley 19.983)
 *   ambos     -> acuse + resultado   |   todas -> los tres
 * Valida cada XML contra su XSD. No envia nada (solo escribe a disco).
 */
require __DIR__ . '/../vendor/autoload.php';

use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Sii\EnvioDteParser;
use Plantiflex\FacturacionCl\Sii\EnvioRecibosBuilder;
use Plantiflex\FacturacionCl\Sii\RespuestaDteBuilder;
use Plantiflex\FacturacionCl\Sii\XmlSigner;
use Plantiflex\Integration\Facturacion\CertificadoCrypto;
use Plantiflex\Integration\Facturacion\MySqlEmisorRepository;

function fail(string $m): never { fwrite(STDERR, "ERROR: $m\n"); exit(1); }
function env(string $n): string { $v = getenv($n); return $v === false ? fail("Falta env $n") : $v; }
function db(): PDO {
    $port = getenv('DB_PORT') ?: '3306';
    return new PDO('mysql:host=' . env('DB_HOST') . ";port={$port};dbname=" . env('DB_NAME') . ';charset=utf8mb4',
        env('DB_USER'), getenv('DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}
function crypto(): CertificadoCrypto {
    $bin = @hex2bin(env('CRYPTO_MASTER_KEY'));
    if ($bin === false || strlen($bin) !== CertificadoCrypto::KEY_LENGTH) fail('CRYPTO_MASTER_KEY invalida (64 hex)');
    return new CertificadoCrypto($bin);
}
function validar(string $xml, string $xsd, string $salida): void {
    $d = new DOMDocument(); $d->loadXML($xml);
    libxml_use_internal_errors(true);
    $ok = $d->schemaValidate($xsd);
    $errs = array_map(static fn ($e) => trim($e->message), libxml_get_errors());
    libxml_clear_errors();
    file_put_contents($salida, $xml);
    echo str_pad(strtoupper(pathinfo($salida, PATHINFO_FILENAME)), 22) . ': schema ' . ($ok ? 'VALIDO' : 'INVALIDO') . " -> $salida\n";
    foreach ($errs as $e) echo "   - $e\n";
}

$ruta = $argv[1] ?? fail('Uso: generar_respuesta_intercambio.php <ruta_envio_dte> [acuse|resultado|recibos|ambos|todas]');
$modo = $argv[2] ?? 'todas';
$rutResponde = '78157243-8';
$rutFirma    = '13520634-2';
$ambiente = Ambiente::Certificacion;

$emisor = new MySqlEmisorRepository(db(), crypto());
$cert  = $emisor->obtenerCertificado($rutResponde, $ambiente);
$datos = $emisor->obtenerDatosEmisor($rutResponde, $ambiente);

$envio = EnvioDteParser::fromFile($ruta);
if (isset($envio->caratula['RutReceptor']) && $envio->caratula['RutReceptor'] !== $rutResponde) {
    fwrite(STDERR, "AVISO: RutReceptor del envio ({$envio->caratula['RutReceptor']}) != {$rutResponde}\n");
}

$car = [
    'RutResponde'  => $rutResponde,
    'RutRecibe'    => $envio->caratula['RutEmisor'] ?? '',
    'IdRespuesta'  => 1,
    'MailContacto' => 'contacto@easyagenda.cl',
];

$resp = new RespuestaDteBuilder(new XmlSigner());
$rec  = new EnvioRecibosBuilder(new XmlSigner());
$xsdResp = __DIR__ . '/../oracle/LibreDTE-master/schemas/RespuestaEnvioDTE_v10.xsd';
$xsdRec  = __DIR__ . '/../oracle/LibreDTE-master/schemas/EnvioRecibos_v10.xsd';

echo "EnvioDTE: {$envio->nmbEnvio} | SetDTE={$envio->setDteId} | docs=" . count($envio->documentos)
   . " | emisor={$envio->caratula['RutEmisor']} -> respondemos como {$rutResponde}\n";

$hacer = match ($modo) {
    'acuse'     => ['acuse'],
    'resultado' => ['resultado'],
    'recibos'   => ['recibos'],
    'ambos'     => ['acuse', 'resultado'],
    'todas'     => ['acuse', 'resultado', 'recibos'],
    default     => fail("modo invalido: $modo"),
};

foreach ($hacer as $h) {
    if ($h === 'acuse') {
        validar($resp->acuseRecibo($envio, $car, $cert), $xsdResp, 'respuesta_acuse.xml');
    } elseif ($h === 'resultado') {
        validar($resp->aceptacionRechazo($envio, $car, $cert, 0), $xsdResp, 'respuesta_resultado.xml');
    } elseif ($h === 'recibos') {
        validar($rec->generar($envio, $car, $cert, $datos->dirOrigen, $rutFirma), $xsdRec, 'respuesta_recibos.xml');
    }
}
