<?php

declare(strict_types=1);

/**
 * Pregunta a la pasarela que paso con las ordenes de cobro sin resolver.
 *
 * POR QUE HACE FALTA UN PROCESO NUESTRO. Flow llama UNA VEZ a la url de
 * confirmacion, espera un 200 en menos de 15 segundos y, si no lo recibe, manda
 * un correo de "Alerta: Problema de integracion" -- pero NO vuelve a llamar. Su
 * documentacion aclara ademas que el estado de la transaccion no se ve afectado
 * por ese error: el pago sigue cobrado de su lado.
 *
 * Sin este barrido, un aviso perdido -- por una caida de red, por un despliegue
 * justo en ese segundo, porque la consulta de estado no respondio -- es dinero
 * cobrado que no aparece nunca en nuestro sistema.
 *
 *
 * QUE HACE Y QUE NO HACE
 *
 *   SI: consulta el estado de ordenes ya creadas y actualiza la nuestra.
 *   NO: crear ordenes, mandar correos, mover dinero, tocar el SII.
 *
 * Esa lista corta es deliberada: esto corre solo y sin nadie mirando, asi que
 * como maximo puede hacer una cosa inofensiva.
 *
 *
 * USO
 *   php scripts/conciliar_pagos.php [--tope=N] [--dry-run]
 *
 *   --dry-run  no consulta ni escribe: solo dice CUANTAS ordenes tocaria. Sirve
 *              para ver si hay trabajo pendiente sin gastar llamadas.
 *
 * TODAVIA NO HAY CRON. Se deja invocable a mano a proposito: hasta que no se
 * haya probado contra sandbox, ponerlo a correr solo seria estrenar en
 * produccion un proceso que habla con un tercero.
 *
 * VARIABLES DE ENTORNO: DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_PORT (opc),
 * CRYPTO_MASTER_KEY.
 *
 * CODIGOS DE SALIDA: 0 todo bien · 1 fallo de configuracion o de base ·
 * 2 hubo descuadres de monto (alguien tiene que mirarlos).
 */

require __DIR__ . '/../vendor/autoload.php';

use Plantiflex\FacturacionCl\Pago\ReconciliadorPagos;
use Plantiflex\Integration\Facturacion\CertificadoCrypto;

function fail(string $msg): never
{
    fwrite(STDERR, "ERROR: {$msg}\n");
    exit(1);
}

function linea(string $msg): void
{
    fwrite(STDOUT, $msg . "\n");
}

$argumentos = array_slice($argv, 1);
$dryRun     = in_array('--dry-run', $argumentos, true);
$tope       = 100;
foreach ($argumentos as $a) {
    if (str_starts_with($a, '--tope=')) {
        $tope = max(1, (int) substr($a, 7));
    }
}

$pass = getenv('DB_PASS');
$port = getenv('DB_PORT');
foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'CRYPTO_MASTER_KEY'] as $v) {
    if (getenv($v) === false || getenv($v) === '') {
        fail("falta la variable de entorno {$v}.");
    }
}

try {
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            getenv('DB_HOST'),
            $port === false || $port === '' ? '3306' : $port,
            getenv('DB_NAME')
        ),
        (string) getenv('DB_USER'),
        $pass === false ? '' : $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
} catch (PDOException $e) {
    fail('no se pudo conectar a la base: ' . $e->getMessage());
}

$llave = @hex2bin((string) getenv('CRYPTO_MASTER_KEY'));
if ($llave === false || strlen($llave) !== CertificadoCrypto::KEY_LENGTH) {
    fail('CRYPTO_MASTER_KEY ausente o mal formada.');
}
$crypto = new CertificadoCrypto($llave);

// --- DRY RUN: cuenta y se va, sin consultar a nadie -------------------------
if ($dryRun) {
    // La misma condicion base que usa el conciliador, sin el detalle del
    // backoff: aqui solo interesa el orden de magnitud del trabajo pendiente.
    $n = (int) $pdo->query(
        "SELECT COUNT(*) FROM dte_pago_link WHERE estado = 'creado' AND orden_externa IS NOT NULL"
    )->fetchColumn();
    $marcadas = (int) $pdo->query(
        'SELECT COUNT(*) FROM dte_pago_link WHERE confirmacion_pendiente_at IS NOT NULL'
    )->fetchColumn();

    linea(sprintf(
        'DRY RUN: %d orden(es) creadas sin resolver, de las cuales %d dejaron aviso sin procesar. '
        . 'No se consulto nada.',
        $n,
        $marcadas
    ));
    exit(0);
}

$r = ReconciliadorPagos::conciliar(
    $pdo,
    static fn (string $cifrado): string => $crypto->descifrar($cifrado),
    null,
    $tope
);

linea(sprintf(
    'RESUMEN miradas=%d pagadas=%d sin_pagar=%d descuadres=%d fallidas=%d',
    $r['miradas'],
    $r['pagadas'],
    $r['sin_pagar'],
    $r['descuadres'],
    $r['fallidas']
));

if ($r['descuadres'] > 0) {
    linea(sprintf(
        '*** %d orden(es) con MONTO DISTINTO del cobrado. No se marcaron pagadas. '
        . 'Revisa Ventas > Correos: eso lo tiene que mirar una persona. ***',
        $r['descuadres']
    ));
    exit(2);
}

exit(0);
