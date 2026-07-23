<?php

declare(strict_types=1);

/**
 * Carga un archivo CAF.xml entregado por el SII a la base de datos.
 *
 * USO:
 *   php scripts/cargar_caf.php <ruta_caf.xml> <ambiente> <rut_emisor>
 *
 * EJEMPLO:
 *   DB_HOST=localhost DB_NAME=plantiflex DB_USER=root DB_PASS=secreto \
 *   CRYPTO_MASTER_KEY=<64 hex> \
 *   php scripts/cargar_caf.php ./CAF_F33.xml certificacion 77724622-4
 *
 * VARIABLES DE ENTORNO:
 *   DB_HOST, DB_NAME, DB_USER, DB_PASS  -> conexion MySQL.
 *   CRYPTO_MASTER_KEY                   -> 32 bytes en HEX (64 caracteres).
 *
 * Que hace:
 *   1. Lee y parsea el CAF (TipoDTE en //DA/TD, RUT en //DA/RE, rango en //DA/RNG).
 *   2. Verifica que el RUT del CAF coincida con el argumento.
 *   3. Cifra el XML completo del CAF con CertificadoCrypto (AES-256-GCM).
 *   4. Inserta en dte_caf y crea el contador en dte_folio (proximo_folio = desde).
 */

require __DIR__ . '/../vendor/autoload.php';

use Plantiflex\Integration\Facturacion\CertificadoCrypto;

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
    $pass = $pass === false ? '' : $pass; // password vacio es valido.
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
        fail('CRYPTO_MASTER_KEY debe ser ' . CertificadoCrypto::KEY_LENGTH . ' bytes en HEX (64 caracteres hex).');
    }
    try {
        return new CertificadoCrypto($bin);
    } catch (Throwable $e) {
        fail('Llave maestra invalida: ' . $e->getMessage());
    }
}

function primerTexto(DOMDocument $dom, string $tag): string
{
    $n = $dom->getElementsByTagName($tag)->item(0);
    return $n === null ? '' : trim((string) $n->textContent);
}

// ---------------------------------------------------------------------------

$args = array_slice($argv, 1);
if (count($args) !== 3) {
    fail("Uso: php scripts/cargar_caf.php <ruta_caf.xml> <ambiente> <rut_emisor>");
}
[$ruta, $ambiente, $rutArg] = $args;

if (! in_array($ambiente, ['certificacion', 'produccion'], true)) {
    fail("ambiente debe ser 'certificacion' o 'produccion' (recibido: '{$ambiente}').");
}
if (! is_file($ruta) || ! is_readable($ruta)) {
    fail("No se puede leer el archivo CAF: {$ruta}");
}

// El CAF del SII viene en ISO-8859-1 pero su declaracion XML no trae atributo
// encoding. Estos bytes son los que se cifran y guardan: la firma FRMA del CAF
// es sobre ellos; convertirlos invalidaria el CAF al timbrar.
$original = file_get_contents($ruta);
if ($original === false || trim($original) === '') {
    fail("Archivo CAF vacio o ilegible: {$ruta}");
}

// Version SOLO para parsear: si no es UTF-8 valido, asumir ISO-8859-1 y
// convertir, asi DOMDocument (que asume UTF-8 sin atributo encoding) no rompe
// con caracteres latin1 (ej la "A" con tilde en una razon social).
$paraParsear = mb_check_encoding($original, 'UTF-8')
    ? $original
    : mb_convert_encoding($original, 'UTF-8', 'ISO-8859-1');

$dom = new DOMDocument();
libxml_use_internal_errors(true);
$ok = $dom->loadXML($paraParsear);
libxml_clear_errors();
libxml_use_internal_errors(false);
if (! $ok) {
    fail("El archivo no es XML valido: {$ruta}");
}

$td = primerTexto($dom, 'TD'); // tipo DTE
$re = primerTexto($dom, 'RE'); // rut emisor
$d  = primerTexto($dom, 'D');  // folio desde (dentro de RNG)
$h  = primerTexto($dom, 'H');  // folio hasta (dentro de RNG)

if ($td === '' || $re === '' || $d === '' || $h === '') {
    fail("CAF mal formado: faltan TD/RE/RNG(D,H). Verifica que sea un CAF valido del SII.");
}
if (strcasecmp(trim($re), trim($rutArg)) !== 0) {
    fail("El RUT del CAF ('{$re}') no coincide con el argumento ('{$rutArg}'). Verifica el RUT.");
}

$tipo  = (int) $td;
$desde = (int) $d;
$hasta = (int) $h;
if ($tipo <= 0 || $desde <= 0 || $hasta < $desde) {
    fail("Valores invalidos en el CAF: tipo={$td} desde={$d} hasta={$h}.");
}

$crypto  = crearCrypto();
// Cifrar y guardar el ORIGINAL (bytes intactos), NO la version convertida a
// UTF-8: preserva la firma FRMA del CAF.
$cifrado = $crypto->cifrar($original);
$pdo     = conectarDb();

try {
    $pdo->beginTransaction();

    $pdo->prepare(
        'INSERT INTO dte_caf (rut_emisor, tipo_dte, ambiente, folio_desde, folio_hasta, caf_xml_cifrado) '
        . 'VALUES (:rut, :tipo, :amb, :d, :h, :xml)'
    )->execute([
        ':rut'  => $re,
        ':tipo' => $tipo,
        ':amb'  => $ambiente,
        ':d'    => $desde,
        ':h'    => $hasta,
        ':xml'  => $cifrado,
    ]);
    $cafId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO dte_folio (caf_id, rut_emisor, tipo_dte, ambiente, proximo_folio, folio_hasta) '
        . 'VALUES (:caf, :rut, :tipo, :amb, :prox, :h)'
    )->execute([
        ':caf'  => $cafId,
        ':rut'  => $re,
        ':tipo' => $tipo,
        ':amb'  => $ambiente,
        ':prox' => $desde,
        ':h'    => $hasta,
    ]);

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($e->getCode() === '23000') {
        fail("Este CAF ya esta cargado (duplicado rut/tipo/ambiente/rango): tipo {$tipo} rango {$desde}-{$hasta} ambiente {$ambiente}.");
    }
    fail('Error al insertar en la base: ' . $e->getMessage());
}

echo "CAF cargado: tipo {$tipo} rango {$desde}-{$hasta} emisor {$re}\n";
