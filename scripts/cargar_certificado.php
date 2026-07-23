<?php

declare(strict_types=1);

/**
 * Carga el certificado digital de un emisor (cert + llave privada) a la base,
 * CIFRADO con AES-256-GCM (tabla dte_certificado).
 *
 * USO:
 *   php scripts/cargar_certificado.php <ambiente> <rut> <cert.pem> <key.pem>
 *
 * EJEMPLO:
 *   DB_HOST=localhost DB_NAME=plantiflex DB_USER=root DB_PASS=secreto \
 *   CRYPTO_MASTER_KEY=<64 hex> \
 *   php scripts/cargar_certificado.php certificacion 77724622-4 ./cert.pem ./key.pem
 *
 * VARIABLES DE ENTORNO:
 *   DB_HOST, DB_NAME, DB_USER, DB_PASS  -> conexion MySQL.
 *   CRYPTO_MASTER_KEY                   -> 32 bytes en HEX (64 caracteres).
 *
 * El cert.pem y key.pem deben venir en formato PEM (texto base64 con headers
 * -----BEGIN ...-----). Si tienes un .p12/.pfx, extrae antes con openssl.
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
        fail('CRYPTO_MASTER_KEY debe ser ' . CertificadoCrypto::KEY_LENGTH . ' bytes en HEX (64 caracteres hex).');
    }
    try {
        return new CertificadoCrypto($bin);
    } catch (Throwable $e) {
        fail('Llave maestra invalida: ' . $e->getMessage());
    }
}

function leerPem(string $ruta, string $marcadorEsperado): string
{
    if (! is_file($ruta) || ! is_readable($ruta)) {
        fail("No se puede leer el archivo: {$ruta}");
    }
    $contenido = file_get_contents($ruta);
    if ($contenido === false || trim($contenido) === '') {
        fail("Archivo vacio o ilegible: {$ruta}");
    }
    if (! str_contains($contenido, $marcadorEsperado)) {
        fail("El archivo {$ruta} no parece PEM valido (falta '{$marcadorEsperado}').");
    }
    return $contenido;
}

// ---------------------------------------------------------------------------

$args = array_slice($argv, 1);
if (count($args) !== 4) {
    fail("Uso: php scripts/cargar_certificado.php <ambiente> <rut> <cert.pem> <key.pem>");
}
[$ambiente, $rut, $rutaCert, $rutaKey] = $args;

if (! in_array($ambiente, ['certificacion', 'produccion'], true)) {
    fail("ambiente debe ser 'certificacion' o 'produccion' (recibido: '{$ambiente}').");
}
if (trim($rut) === '') {
    fail('rut no puede ser vacio.');
}

$certPem = leerPem($rutaCert, 'BEGIN CERTIFICATE');
$keyPem  = leerPem($rutaKey, 'PRIVATE KEY'); // cubre "BEGIN PRIVATE KEY" y "BEGIN RSA PRIVATE KEY".

$crypto = crearCrypto();
$pdo    = conectarDb();

try {
    $pdo->prepare(
        'INSERT INTO dte_certificado (rut_emisor, ambiente, cert_data_cifrado, pkey_data_cifrado) '
        . 'VALUES (:rut, :amb, :cert, :pkey)'
    )->execute([
        ':rut'  => $rut,
        ':amb'  => $ambiente,
        ':cert' => $crypto->cifrar($certPem),
        ':pkey' => $crypto->cifrar($keyPem),
    ]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        fail("Ya existe un certificado cargado para rut '{$rut}' ambiente '{$ambiente}'. Para reemplazarlo, actualiza/elimina la fila existente.");
    }
    fail('Error al insertar en la base: ' . $e->getMessage());
}

echo "Certificado cargado (cifrado): {$rut} ambiente {$ambiente}\n";
