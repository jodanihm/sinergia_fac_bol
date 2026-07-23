<?php

declare(strict_types=1);

/**
 * Prueba end-to-end LOCAL del flujo de emision, SIN subir al SII.
 *
 * Valida TODO el codigo propio (asignacion de folio, obtencion de cert/datos
 * descifrados, construccion del DTE, firma del Documento, construccion del
 * EnvioDTE y firma del SetDTE) y deja el XML resultante en un archivo. NO hace
 * upload: cuando llegue el CAF real, la unica diferencia sera que el SII
 * aceptara ese upload.
 *
 * USO:
 *   php scripts/probar_emision_local.php <ambiente> <rut>
 *
 * REQUISITOS (deben estar cargados en la BD para ese rut/ambiente):
 *   - CAF de prueba (scripts/generar_caf_prueba.php + scripts/cargar_caf.php)
 *   - Certificado     (scripts/cargar_certificado.php)
 *   - Datos de emisor (scripts/cargar_emisor.php)
 *
 * VARIABLES DE ENTORNO:
 *   DB_HOST, DB_NAME, DB_USER, DB_PASS  -> conexion MySQL.
 *   CRYPTO_MASTER_KEY                   -> 32 bytes en HEX (la misma de la carga).
 *   RUT_SENDER (opcional)              -> RUT del firmante, ej "13520634-2".
 *                                         Si falta, se intenta extraer del cert.
 *
 * OJO: si el CAF es ficticio, el XML generado NO sirve para el SII real.
 */

require __DIR__ . '/../vendor/autoload.php';

use Plantiflex\FacturacionCl\Dto\Detalle;
use Plantiflex\FacturacionCl\Dto\DocumentoTributario;
use Plantiflex\FacturacionCl\Dto\Receptor;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Sii\DteXmlBuilder;
use Plantiflex\FacturacionCl\Sii\EnvioDteBuilder;
use Plantiflex\FacturacionCl\Sii\TedBuilder;
use Plantiflex\FacturacionCl\Sii\XmlSigner;
use Plantiflex\Integration\Facturacion\CertificadoCrypto;
use Plantiflex\Integration\Facturacion\MySqlEmisorRepository;
use Plantiflex\Integration\Facturacion\MySqlFolioRepository;

const NS_SII  = 'http://www.sii.cl/SiiDte';
const RUT_SII = '60803000-K';

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

function primerElemento(DOMDocument $dom, string $tag): DOMElement
{
    $n = $dom->getElementsByTagNameNS(NS_SII, $tag)->item(0);
    if (! $n instanceof DOMElement) {
        fail("No se encontro <{$tag}> en el XML generado.");
    }
    return $n;
}

// ---------------------------------------------------------------------------

$args = array_slice($argv, 1);
if (count($args) !== 2) {
    fail("Uso: php scripts/probar_emision_local.php <ambiente> <rut>");
}
[$ambienteArg, $rut] = $args;

if ($ambienteArg === 'certificacion') {
    $ambiente = Ambiente::Certificacion;
} elseif ($ambienteArg === 'produccion') {
    $ambiente = Ambiente::Produccion;
} else {
    fail("ambiente debe ser 'certificacion' o 'produccion'.");
}

$pdo    = conectarDb();
$crypto = crearCrypto();
$folios = new MySqlFolioRepository(
    $pdo,
    function (string $c) use ($crypto): string {
        return $crypto->descifrar($c);
    },
);
$emisor = new MySqlEmisorRepository($pdo, $crypto);

// 1. Documento de ejemplo: factura tipo 33 con 2 items.
$doc = new DocumentoTributario(
    tipoDte: TipoDte::FacturaElectronica,
    receptor: new Receptor(
        rut: '99999999-9',
        razonSocial: 'CLIENTE DE PRUEBA SPA',
        giro: 'Comercio',
        direccion: 'Calle Falsa 123',
        comuna: 'Santiago',
    ),
    detalles: [
        new Detalle('Plantilla ortopedica modelo A', 2, 15000),
        new Detalle('Plantilla ortopedica modelo B', 1, 25000),
    ],
    montosSonBrutos: false,
);

try {
    // 2. Reunir dependencias + asignar folio.
    $cafXml = $folios->obtenerCafActivo($rut, $doc->tipoDte, $ambiente);
    $cert   = $emisor->obtenerCertificado($rut, $ambiente);
    $datos  = $emisor->obtenerDatosEmisor($rut, $ambiente);
    $folio  = $folios->asignarSiguienteFolio($rut, $doc->tipoDte, $ambiente);
} catch (Throwable $e) {
    fail('No se pudieron reunir dependencias: ' . $e->getMessage()
        . "\n  Verifica que CAF, certificado y datos del emisor esten cargados para {$rut}/{$ambienteArg}.");
}

$signer       = new XmlSigner();
$dteBuilder   = new DteXmlBuilder();
$tedBuilder   = new TedBuilder();
$envioBuilder = new EnvioDteBuilder();

// 3. Construir el DTE individual SIN firmar y SIN TED.
$dteDoc      = $dteBuilder->build($doc, $datos, $folio);
$idDocumento = sprintf('F%dT%d', $folio, $doc->tipoDte->value);

// Resolver rutSender (firmante).
$rutSender = getenv('RUT_SENDER') ?: null;
if ($rutSender === null) {
    $parsed = openssl_x509_parse($cert->certData);
    $sn = is_array($parsed) ? ($parsed['subject']['serialNumber'] ?? null) : null;
    $rutSender = is_string($sn) ? $sn : $rut;
}

// 4. Construir el EnvioDTE importando el DTE: todo queda en UN doc.
$envioDoc = $envioBuilder->build([$dteDoc], $datos, RUT_SII, $rutSender, $ambiente);

// 4b. Insertar el TED YA EN EL DOC FINAL (con xmlns=""; no antes del import,
//     que perderia esa declaracion). Antes de firmar el Documento.
$tedBuilder->build($envioDoc, $doc, $datos, $folio, $cafXml);

// 5. Firmar el <Documento> YA dentro del envioDoc.
//    El root aun NO tiene xmlns:xsi, por lo que el C14N del Documento no lo hereda
//    y el digest coincide con el que calcula el SII.
$signer->firmarNodo(primerElemento($envioDoc, 'Documento'), $idDocumento, $cert);

// 5b. Agregar xmlns:xsi / schemaLocation al root DESPUES de firmar el Documento
//     y ANTES de firmar el SetDTE. Necesario para SCH-00001; seguro para el SetDTE.
$envioBuilder->agregarSchemaLocation($envioDoc);

// 6. Firmar el <SetDTE>.
$signer->firmarNodo(primerElemento($envioDoc, 'SetDTE'), 'SetDoc', $cert);

// 7. Guardar (NO subir al SII).
$xml  = (string) $envioDoc->saveXML();
$ruta = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'envio_dte_prueba.xml';
if (file_put_contents($ruta, $xml) === false) {
    fail("No se pudo escribir el XML en {$ruta}");
}

// 8. Resumen.
echo "=== Prueba de emision LOCAL (sin SII) ===\n";
echo "  Emisor:        {$rut} ({$datos->razonSocial})\n";
echo "  Ambiente:      {$ambienteArg}\n";
echo "  Tipo DTE:      {$doc->tipoDte->value} (factura)\n";
echo "  Folio asignado:{$folio}\n";
echo "  ID Documento:  {$idDocumento}\n";
echo "  ID SetDTE:     SetDoc\n";
echo "  RutSender:     {$rutSender}\n";
echo "  Longitud XML:  " . strlen($xml) . " bytes\n";
echo "  Archivo:       {$ruta}\n";
echo "  CAF en uso:    " . strlen($cafXml) . " bytes (descifrado)\n";
echo "\nNOTA: si el CAF es ficticio, este XML NO sirve para el SII real.\n";
