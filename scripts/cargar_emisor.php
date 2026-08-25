<?php

declare(strict_types=1);

/**
 * Carga los datos PUBLICOS de un emisor a la base de datos (tabla dte_emisor).
 *
 * USO:
 *   php scripts/cargar_emisor.php <cuenta_id> <ambiente> <rut> <razon_social> <giro> \
 *       <acteco> <dir> <comuna> <res_fecha> <res_numero>
 *
 * EJEMPLO:
 *   DB_HOST=localhost DB_NAME=plantiflex DB_USER=root DB_PASS=secreto \
 *   php scripts/cargar_emisor.php 2 certificacion 77724622-4 "Plantiflex SpA" \
 *       "Venta de plantas" 477310 "Av Siempre Viva 123" "Santiago" 2024-01-01 0
 *
 * EL PRIMER ARGUMENTO ES NUEVO (migracion 045) y rompe a proposito las llamadas
 * viejas de nueve argumentos: una llamada antigua fallaria igual contra la base
 * -- cuenta_id es NOT NULL --, y es mejor que falle con la linea de uso.
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
if (count($args) !== 10) {
    fail(
        "Uso: php scripts/cargar_emisor.php <cuenta_id> <ambiente> <rut> <razon_social> <giro> "
        . "<acteco> <dir> <comuna> <res_fecha> <res_numero>"
    );
}
[$cuentaId, $ambiente, $rut, $razon, $giro, $acteco, $dir, $comuna, $resFecha, $resNumero] = $args;

// LA CUENTA ES OBLIGATORIA DESDE LA MIGRACION 045. Este script nacio antes de
// que el sistema fuera multi-tenant y cargaba el emisor sin dueno: la fila
// quedaba con cuenta_id NULL y todos los documentos que colgaran de ella eran
// de una empresa que la base no podia nombrar. Era el unico camino que todavia
// podia crear ese agujero. Ahora cuenta_id es NOT NULL, asi que sin este
// argumento el INSERT fallaria igual -- se pide aqui para que el error sea una
// linea de uso y no una excepcion de PDO.
if (! ctype_digit($cuentaId) || (int) $cuentaId <= 0) {
    fail("cuenta_id debe ser el id numerico de una fila de la tabla cuenta (recibido: '{$cuentaId}').");
}
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
        . '(rut_emisor, cuenta_id, ambiente, razon_social, giro, acteco, dir_origen, cmna_origen, resolucion_fecha, resolucion_numero) '
        . 'VALUES (:rut, :cuenta, :amb, :razon, :giro, :acteco, :dir, :cmna, :fecha, :numero)'
    )->execute([
        ':rut'    => $rut,
        ':cuenta' => (int) $cuentaId,
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
    // 1452 = fk_emisor_cuenta: la cuenta_id que se paso no existe. Se separa del
    // 1062 porque el arreglo es otro (crear la cuenta, no editar el emisor).
    if ((int) ($e->errorInfo[1] ?? 0) === 1452) {
        fail("No existe la cuenta {$cuentaId}. Revisa: SELECT id, nombre FROM cuenta;");
    }
    if ($e->getCode() === '23000') {
        fail("Ya existe un emisor cargado para rut '{$rut}' ambiente '{$ambiente}'. Para cambiarlo, actualiza la fila existente.");
    }
    fail('Error al insertar en la base: ' . $e->getMessage());
}

echo "Emisor cargado: {$rut} ({$razon}) ambiente {$ambiente}\n";
