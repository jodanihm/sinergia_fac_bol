<?php

declare(strict_types=1);

/**
 * Carga los datos PUBLICOS de un emisor a la base de datos (tabla dte_emisor).
 *
 * USO:
 *   php scripts/cargar_emisor.php <ambiente> <rut> <razon_social> <giro> \
 *       <acteco> <dir> <comuna> <res_fecha> <res_numero>
 *
 * EJEMPLO:
 *   DB_HOST=localhost DB_NAME=plantiflex DB_USER=root DB_PASS=secreto \
 *   php scripts/cargar_emisor.php certificacion 77724622-4 "Plantiflex SpA" \
 *       "Venta de plantas" 477310 "Av Siempre Viva 123" "Santiago" 2024-01-01 0
 *
 * VARIABLES DE ENTORNO:
 *   DB_HOST, DB_NAME, DB_USER, DB_PASS  -> conexion MySQL.
 *
 * Nota: estos datos NO son secretos y se guardan en claro (no se cifran).
 */

require __DIR__ . '/../vendor/autoload.php';

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

// ---------------------------------------------------------------------------

$args = array_slice($argv, 1);
if (count($args) !== 9) {
    fail(
        "Uso: php scripts/cargar_emisor.php <ambiente> <rut> <razon_social> <giro> "
        . "<acteco> <dir> <comuna> <res_fecha> <res_numero>"
    );
}
[$ambiente, $rut, $razon, $giro, $acteco, $dir, $comuna, $resFecha, $resNumero] = $args;

if (! in_array($ambiente, ['certificacion', 'produccion'], true)) {
    fail("ambiente debe ser 'certificacion' o 'produccion' (recibido: '{$ambiente}').");
}
if (trim($rut) === '' || trim($razon) === '') {
    fail('rut y razon_social no pueden ser vacios.');
}
if (! ctype_digit($acteco)) {
    fail("acteco debe ser numerico (recibido: '{$acteco}').");
}
if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $resFecha)) {
    fail("res_fecha debe tener formato YYYY-MM-DD (recibido: '{$resFecha}').");
}
if (! ctype_digit($resNumero)) {
    fail("res_numero debe ser numerico (recibido: '{$resNumero}').");
}

$pdo = conectarDb();

try {
    $pdo->prepare(
        'INSERT INTO dte_emisor '
        . '(rut_emisor, ambiente, razon_social, giro, acteco, dir_origen, cmna_origen, resolucion_fecha, resolucion_numero) '
        . 'VALUES (:rut, :amb, :razon, :giro, :acteco, :dir, :cmna, :fecha, :numero)'
    )->execute([
        ':rut'    => $rut,
        ':amb'    => $ambiente,
        ':razon'  => $razon,
        ':giro'   => $giro,
        ':acteco' => (int) $acteco,
        ':dir'    => $dir,
        ':cmna'   => $comuna,
        ':fecha'  => $resFecha,
        ':numero' => (int) $resNumero,
    ]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        fail("Ya existe un emisor cargado para rut '{$rut}' ambiente '{$ambiente}'. Para cambiarlo, actualiza la fila existente.");
    }
    fail('Error al insertar en la base: ' . $e->getMessage());
}

echo "Emisor cargado: {$rut} ({$razon}) ambiente {$ambiente}\n";
