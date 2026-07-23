<?php

declare(strict_types=1);

/**
 * Diagnostico de firma DTE — SIN enviar al SII.
 *
 * Replica EXACTAMENTE el flujo de emitir_real (construye DTE, EnvioDTE,
 * TED, TmstFirma, firma Documento y SetDTE), serializa a ISO-8859-1 con
 * serializarParaSii, guarda esos bytes en /tmp/dte_diagnostico.xml,
 * re-parsea ese archivo y verifica DigestValues + SignatureValues de ambas
 * firmas XML-DSig tal como lo haria el SII.
 *
 * NO consume folios ni toca el nucleo.
 *
 * USO:
 *   DB_HOST=localhost DB_NAME=plantiflex DB_USER=root DB_PASS=secreto \
 *   CRYPTO_MASTER_KEY=<64 hex> \
 *   php scripts/diagnosticar_firma.php <ambiente> <rut_emisor>
 */

require __DIR__ . '/../vendor/autoload.php';

use DOMDocument;
use DOMElement;
use DOMNode;
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

// ---------------------------------------------------------------------------
// Helpers (mismos que emitir_real)
// ---------------------------------------------------------------------------

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

// Misma logica que SiiDirectoFacturador::serializarParaSii.
function serializarParaSii(DOMDocument $doc): string
{
    $utf8 = XmlSigner::limpiarPrefijosDsig((string) $doc->saveXML());
    $iso  = (string) mb_convert_encoding($utf8, 'ISO-8859-1', 'UTF-8');
    $iso  = (string) preg_replace(
        '/(<\?xml[^>]*encoding=")UTF-8("[^>]*\?>)/i',
        '${1}ISO-8859-1${2}',
        $iso,
        1,
    );
    return $iso;
}

// Devuelve el primer elemento con ese tag en el NS SiiDte, o lanza.
function primerElementoSii(DOMDocument $doc, string $tag): DOMElement
{
    $ns   = 'http://www.sii.cl/SiiDte';
    $node = $doc->getElementsByTagNameNS($ns, $tag)->item(0);
    if (! $node instanceof DOMElement) {
        fail("No se encontro <{$tag}> en el documento generado.");
    }
    return $node;
}

// Busca la Signature cuya Reference/@URI coincide con $refUri.
function encontrarSignaturePorRef(DOMDocument $doc, string $refUri): ?DOMElement
{
    $nsDsig = 'http://www.w3.org/2000/09/xmldsig#';
    foreach ($doc->getElementsByTagNameNS($nsDsig, 'Signature') as $sig) {
        if (! $sig instanceof DOMElement) {
            continue;
        }
        foreach ($sig->getElementsByTagNameNS($nsDsig, 'Reference') as $ref) {
            if ($ref instanceof DOMElement && $ref->getAttribute('URI') === $refUri) {
                return $sig;
            }
        }
    }
    return null;
}

// Busca el primer elemento (cualquier NS) cuyo atributo ID == $id.
function encontrarElementoPorId(DOMDocument $doc, string $id): ?DOMElement
{
    foreach ($doc->getElementsByTagName('*') as $el) {
        if ($el instanceof DOMElement && $el->getAttribute('ID') === $id) {
            return $el;
        }
    }
    return null;
}

/**
 * Verifica la firma XML-DSig de un nodo del documento re-parseado.
 *
 * Replica lo que hace el SII:
 *   a) Localiza la Signature cuya Reference/@URI = $refUri.
 *   b) Recomputa DigestValue: C14N del nodo + SHA1 + base64.
 *      (enveloped-signature: la Signature es HERMANA del nodo, no descendiente,
 *       asi que el subtree del nodo no la contiene y no hay nada que remover.)
 *   c) Compara con el DigestValue almacenado en el archivo.
 *   d) Verifica SignatureValue: C14N del SignedInfo en contexto + openssl_verify RSA-SHA1.
 *
 * @param DOMDocument $doc      Documento re-parseado desde los bytes ISO-8859-1.
 * @param string      $refUri   Reference URI en la Signature (ej. "#F123T33", "#SetDoc").
 * @param mixed       $pubKey   Llave publica extraida del certificado real.
 * @param string      $label    Etiqueta para el reporte (ej. "Documento", "SetDTE").
 */
function verificarFirma(DOMDocument $doc, string $refUri, mixed $pubKey, string $label): void
{
    $nsDsig = 'http://www.w3.org/2000/09/xmldsig#';
    $idBuscado = ltrim($refUri, '#');

    // --- 1. Encontrar la Signature correspondiente ---
    $sig = encontrarSignaturePorRef($doc, $refUri);
    if ($sig === null) {
        echo "  [{$label}] ERROR: Signature con Reference URI=\"{$refUri}\" no encontrada.\n";
        return;
    }

    // --- 2. Encontrar el nodo referenciado ---
    $nodo = encontrarElementoPorId($doc, $idBuscado);
    if ($nodo === null) {
        echo "  [{$label}] ERROR: Elemento con ID=\"{$idBuscado}\" no encontrado.\n";
        return;
    }

    // --- 3. Recomputar DigestValue ---
    // La Signature es HERMANA del nodo (mismo padre, justo despues); no es
    // descendiente, por lo que el C14N del nodo no la incluye. La transformacion
    // enveloped-signature no tiene nada que remover en este caso.
    $c14nNodo = $nodo->C14N();
    if ($c14nNodo === false) {
        echo "  [{$label}] ERROR: C14N del nodo fallo.\n";
        return;
    }
    $digestRecomputado = base64_encode(sha1($c14nNodo, true));

    // --- 4. Extraer DigestValue del archivo ---
    $digestArchivo = '';
    foreach ($sig->getElementsByTagNameNS($nsDsig, 'Reference') as $ref) {
        if (! $ref instanceof DOMElement || $ref->getAttribute('URI') !== $refUri) {
            continue;
        }
        $dvNode = $ref->getElementsByTagNameNS($nsDsig, 'DigestValue')->item(0);
        if ($dvNode !== null) {
            // DigestValue no tiene saltos de linea (es base64 sin chunk), pero
            // trim() protege contra espacios blancos del parser.
            $digestArchivo = trim($dvNode->textContent);
        }
        break;
    }

    $digestCoincide = ($digestArchivo === $digestRecomputado);

    echo "\n  [{$label}] DigestValue\n";
    echo "    C14N longitud: " . strlen($c14nNodo) . " bytes\n";
    echo "    archivo      : {$digestArchivo}\n";
    echo "    recomputado  : {$digestRecomputado}\n";
    echo "    resultado    : " . ($digestCoincide ? 'COINCIDEN' : '*** NO COINCIDEN ***') . "\n";

    // --- 5. Verificar SignatureValue ---
    $signedInfoNode = $sig->getElementsByTagNameNS($nsDsig, 'SignedInfo')->item(0);
    $sigValueNode   = $sig->getElementsByTagNameNS($nsDsig, 'SignatureValue')->item(0);

    if (! $signedInfoNode instanceof DOMElement || ! $sigValueNode instanceof DOMElement) {
        echo "  [{$label}] ERROR: Estructura de Signature invalida (falta SignedInfo o SignatureValue).\n";
        return;
    }

    // C14N del SignedInfo EN SU CONTEXTO FINAL (hereda namespaces de ancestros,
    // ej. xmlns:xsi del EnvioDTE). Esto es exactamente lo que hace el SII al validar.
    $c14nSignedInfo = $signedInfoNode->C14N();
    if ($c14nSignedInfo === false) {
        echo "  [{$label}] ERROR: C14N del SignedInfo fallo.\n";
        return;
    }

    // SignatureValue puede venir en lineas de 64 (chunk_split); base64_decode
    // ignora los saltos de linea al decodificar, pero preg_replace los elimina
    // explicitamente para evitar cualquier sorpresa.
    $sigBase64 = (string) preg_replace('/\s+/', '', $sigValueNode->textContent);
    $sigBin = base64_decode($sigBase64, true);

    if ($sigBin === false || $sigBin === '') {
        echo "  [{$label}] SignatureValue: ERROR decodificando base64.\n";
        return;
    }

    $res = openssl_verify($c14nSignedInfo, $sigBin, $pubKey, OPENSSL_ALGO_SHA1);
    if ($res === 1) {
        $sigMsg = 'VALIDO';
    } elseif ($res === 0) {
        $sigMsg = '*** INVALIDO ***';
    } else {
        $err = openssl_error_string();
        $sigMsg = '*** ERROR openssl: ' . ($err !== false ? $err : 'desconocido') . ' ***';
    }
    echo "  [{$label}] SignatureValue: {$sigMsg}\n";
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

$args = array_slice($argv, 1);
if (count($args) !== 2) {
    fail("Uso: php scripts/diagnosticar_firma.php <ambiente> <rut_emisor>");
}
[$ambienteArg, $rut] = $args;

if ($ambienteArg === 'certificacion') {
    $ambiente = Ambiente::Certificacion;
} elseif ($ambienteArg === 'produccion') {
    $ambiente = Ambiente::Produccion;
} else {
    fail("ambiente debe ser 'certificacion' o 'produccion'.");
}
if (trim($rut) === '') {
    fail('rut_emisor no puede ser vacio.');
}

$pdo    = conectarDb();
$crypto = crearCrypto();

$folios = new MySqlFolioRepository(
    $pdo,
    function (string $c) use ($crypto): string {
        return $crypto->descifrar($c);
    },
);
$emisorRepo = new MySqlEmisorRepository($pdo, $crypto);

echo "=== DIAGNOSTICO DE FIRMA DTE ({$ambienteArg}) emisor={$rut} ===\n\n";

// --- Cargar dependencias desde BD (mismo orden que emitir_real) ---
echo "[1] Cargando CAF, certificado y datos del emisor desde BD...\n";
$cafXml = $folios->obtenerCafActivo($rut, TipoDte::FacturaElectronica, $ambiente);
$cert   = $emisorRepo->obtenerCertificado($rut, $ambiente);
$datos  = $emisorRepo->obtenerDatosEmisor($rut, $ambiente);
echo "    CAF cargado (" . strlen($cafXml) . " bytes), cert OK, datos OK.\n";

// --- Obtener folio SIN consumirlo (lectura directa, sin transaccion) ---
// No quemamos el folio: el diagnostico es idempotente.
echo "[2] Obteniendo folio siguiente sin consumirlo...\n";
$stmtFolio = $pdo->prepare(
    'SELECT f.proximo_folio '
    . 'FROM dte_folio f '
    . 'INNER JOIN dte_caf c ON c.id = f.caf_id '
    . 'WHERE f.rut_emisor = :rut '
    . '  AND f.tipo_dte   = :tipo '
    . '  AND f.ambiente   = :amb '
    . "  AND c.estado     = 'activo' "
    . '  AND f.proximo_folio <= f.folio_hasta '
    . 'ORDER BY c.folio_desde ASC '
    . 'LIMIT 1'
);
$stmtFolio->execute([
    ':rut'  => $rut,
    ':tipo' => TipoDte::FacturaElectronica->value,
    ':amb'  => $ambiente->value,
]);
$rowFolio = $stmtFolio->fetch(PDO::FETCH_ASSOC);
if ($rowFolio === false) {
    fail('No hay folios disponibles para el tipo 33 en este ambiente.');
}
$folio = (int) $rowFolio['proximo_folio'];
echo "    Folio: {$folio} (NO consumido).\n";

// --- Construir documento: MISMO flujo que emitir_real ---
echo "[3] Construyendo DTE individual...\n";
$doc = new DocumentoTributario(
    tipoDte: TipoDte::FacturaElectronica,
    receptor: new Receptor(
        rut: '66666666-6',
        razonSocial: 'Cliente de Prueba',
        giro: 'Servicios',
        direccion: 'Calle Falsa 123',
        comuna: 'Santiago',
    ),
    detalles: [
        new Detalle('Item de prueba 1', 1, 10000),
        new Detalle('Item de prueba 2', 2, 5000),
    ],
    montosSonBrutos: false,
);

$dteBuilder   = new DteXmlBuilder();
$envioBuilder = new EnvioDteBuilder();
$tedBuilder   = new TedBuilder();
$signer       = new XmlSigner();

$rutSender        = '13520634-2';
$rutReceptorSii   = '60803000-K';

$dteDoc   = $dteBuilder->build($doc, $datos, $folio);
$documentoId = sprintf('F%dT%d', $folio, TipoDte::FacturaElectronica->value);
echo "    DTE construido. Documento ID=\"{$documentoId}\".\n";

echo "[4] Construyendo EnvioDTE...\n";
$envioDoc = $envioBuilder->build([$dteDoc], $datos, $rutReceptorSii, $rutSender, $ambiente);

echo "[5] Insertando TED (timbre)...\n";
$tedBuilder->build($envioDoc, $doc, $datos, $folio, $cafXml);

echo "[6] Insertando esqueletos de firma (Documento(s) y SetDTE)...\n";
$idsDocumentos = [];
foreach ($envioDoc->getElementsByTagNameNS('http://www.sii.cl/SiiDte', 'Documento') as $nodoDoc) {
    if ($nodoDoc instanceof DOMElement) {
        $idDoc = $nodoDoc->getAttribute('ID');
        $signer->insertarEsqueletoFirma($nodoDoc, $idDoc, $cert);
        $idsDocumentos[] = $idDoc;
    }
}
$signer->insertarEsqueletoFirma(primerElementoSii($envioDoc, 'SetDTE'), 'SetDoc', $cert);

echo "[6b] Agregando xmlns:xsi / schemaLocation al root (ANTES de congelar)...\n";
$envioBuilder->agregarSchemaLocation($envioDoc);

echo "[6c] Congelando (normaliza 'default:' y re-parsea)...\n";
$signer->congelar($envioDoc);

echo "[7] Calculando digests y firmando (Documento(s), luego SetDTE)...\n";
foreach ($idsDocumentos as $idDoc) {
    $signer->calcularDigestYFirmar($envioDoc, $idDoc, $cert);
}
$signer->calcularDigestYFirmar($envioDoc, 'SetDoc', $cert);

// --- Serializar a ISO-8859-1 (bytes exactos que se enviarian al SII) ---
echo "[8] Serializando a ISO-8859-1...\n";
$xmlIso = serializarParaSii($envioDoc);
echo "    Tamano: " . strlen($xmlIso) . " bytes.\n";

// --- Guardar en /tmp/dte_diagnostico.xml ---
$rutaDiagnostico = '/tmp/dte_diagnostico.xml';
echo "[9] Guardando en {$rutaDiagnostico}...\n";
if (file_put_contents($rutaDiagnostico, $xmlIso) === false) {
    fail("No se pudo escribir {$rutaDiagnostico}.");
}
echo "    OK.\n";

// --- Re-parsear el archivo como haria el SII ---
echo "[10] Re-parseando {$rutaDiagnostico} (como haria el SII)...\n";
$reparsed = new DOMDocument();
$reparsed->preserveWhiteSpace = true;
$reparsed->formatOutput       = false;
libxml_use_internal_errors(true);
$ok = $reparsed->load($rutaDiagnostico);
$libxmlErrors = libxml_get_errors();
libxml_clear_errors();
libxml_use_internal_errors(false);

if (! $ok) {
    $errMsg = '';
    foreach ($libxmlErrors as $e) {
        $errMsg .= trim($e->message) . ' ';
    }
    fail("DOMDocument::load() fallo: " . trim($errMsg));
}
if ($libxmlErrors !== []) {
    echo "    ADVERTENCIAS al re-parsear:\n";
    foreach ($libxmlErrors as $e) {
        echo "      linea {$e->line}: " . trim($e->message) . "\n";
    }
}
echo "    Re-parseado OK.\n";

// --- Extraer llave publica del certificado real ---
echo "[11] Extrayendo llave publica del certificado...\n";
$pubKey = openssl_get_publickey($cert->certData);
if ($pubKey === false) {
    fail('No se pudo extraer la llave publica del certificado.');
}
echo "    OK.\n";

// ---------------------------------------------------------------------------
// VERIFICACION DE FIRMAS
// ---------------------------------------------------------------------------

echo "\n=== VERIFICACION DE FIRMAS ===\n";
echo "(Recomputa DigestValue y verifica SignatureValue tal como lo hace el SII)\n";

// Firma del Documento
verificarFirma($reparsed, '#' . $documentoId, $pubKey, 'Documento');

// Firma del SetDTE
verificarFirma($reparsed, '#SetDoc', $pubKey, 'SetDTE');

// ---------------------------------------------------------------------------
// RESUMEN FINAL
// ---------------------------------------------------------------------------

echo "\n=== RESUMEN ===\n";

$sumarioItems = [];
foreach (['#' . $documentoId => 'Documento', '#SetDoc' => 'SetDTE'] as $uri => $lbl) {
    $s   = encontrarSignaturePorRef($reparsed, $uri);
    $nId = ltrim($uri, '#');
    $n   = encontrarElementoPorId($reparsed, $nId);

    if ($s === null || $n === null) {
        $sumarioItems[] = "  {$lbl}: ERROR (Signature o nodo no encontrado)";
        continue;
    }

    $nsDsig = 'http://www.w3.org/2000/09/xmldsig#';
    $dvArch = '';
    foreach ($s->getElementsByTagNameNS($nsDsig, 'Reference') as $ref) {
        if ($ref instanceof DOMElement && $ref->getAttribute('URI') === $uri) {
            $dv = $ref->getElementsByTagNameNS($nsDsig, 'DigestValue')->item(0);
            if ($dv !== null) {
                $dvArch = trim($dv->textContent);
            }
            break;
        }
    }

    $c14n     = $n->C14N();
    $dvRecalc = $c14n !== false ? base64_encode(sha1($c14n, true)) : '';
    $dOk      = ($dvArch !== '' && $dvArch === $dvRecalc) ? 'COINCIDEN' : '*** NO COINCIDEN ***';

    $siNode = $s->getElementsByTagNameNS($nsDsig, 'SignedInfo')->item(0);
    $svNode = $s->getElementsByTagNameNS($nsDsig, 'SignatureValue')->item(0);
    $sigMsg = 'ERROR';
    if ($siNode instanceof DOMElement && $svNode instanceof DOMElement) {
        $c14nSi = $siNode->C14N();
        $svBin  = $c14nSi !== false
            ? base64_decode((string) preg_replace('/\s+/', '', $svNode->textContent), true)
            : false;
        if ($svBin !== false && $svBin !== '') {
            $r      = openssl_verify($c14nSi, $svBin, $pubKey, OPENSSL_ALGO_SHA1);
            $sigMsg = $r === 1 ? 'VALIDO' : ($r === 0 ? '*** INVALIDO ***' : '*** ERROR openssl ***');
        }
    }

    $sumarioItems[] = "  {$lbl}: DigestValue archivo={$dvArch} recomputado={$dvRecalc} -> {$dOk}";
    $sumarioItems[] = "  {$lbl}: SignatureValue -> {$sigMsg}";
}

foreach ($sumarioItems as $linea) {
    echo $linea . "\n";
}

echo "\nArchivo diagnostico guardado en: {$rutaDiagnostico}\n";
echo "Folio usado para la prueba: {$folio} (NO consumido del CAF).\n";
