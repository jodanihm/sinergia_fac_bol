<?php

declare(strict_types=1);

/**
 * Emite una NOTA DE CREDITO (61) que ANULA una factura previa, contra el SII.
 * Reusa el flujo de SiiDirectoFacturador::anular() (TipoAnulacion::AnulaTotal).
 *
 * USO:
 *   php scripts/emitir_nota.php <ambiente> <rut_emisor> <folio_original> [fecha_original AAAA-MM-DD]
 *
 * El <folio_original> es la factura tipo 33 que se anula. Si no se pasa la
 * fecha, se asume hoy. Los datos del documento original (receptor, detalles,
 * montos) replican la factura de prueba emitida por emitir_real.php.
 *
 * VARIABLES DE ENTORNO: DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_PORT(opc),
 * CRYPTO_MASTER_KEY (32 bytes en HEX).
 */

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use Plantiflex\FacturacionCl\Dto\Credenciales;
use Plantiflex\FacturacionCl\Dto\Detalle;
use Plantiflex\FacturacionCl\Dto\DocumentoOriginal;
use Plantiflex\FacturacionCl\Dto\Receptor;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Enums\TipoAnulacion;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Exceptions\EnvioRechazadoException;
use Plantiflex\FacturacionCl\Exceptions\SiiAutenticacionException;
use Plantiflex\FacturacionCl\Providers\SiiDirectoFacturador;
use Plantiflex\Integration\Facturacion\CertificadoCrypto;
use Plantiflex\Integration\Facturacion\MySqlDteEmitidoRepository;
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

// ---------------------------------------------------------------------------

$args = array_slice($argv, 1);
if (count($args) < 3 || count($args) > 4) {
    fail("Uso: php scripts/emitir_nota.php <ambiente> <rut_emisor> <folio_original> [fecha_original AAAA-MM-DD]");
}
$ambienteArg   = $args[0];
$rut           = $args[1];
$folioOriginal = (int) $args[2];
$fechaOriginal = $args[3] ?? date('Y-m-d');

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
if ($folioOriginal <= 0) {
    fail('folio_original debe ser > 0.');
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

$certTls = __DIR__ . '/../fullchain.pem';
$keyTls  = __DIR__ . '/../key.pem';
foreach (['fullchain.pem' => $certTls, 'key.pem' => $keyTls] as $nombre => $ruta) {
    if (! is_file($ruta) || ! is_readable($ruta)) {
        fail("No se encuentra {$nombre} para el TLS mutuo en: {$ruta}");
    }
}

$facturador = new SiiDirectoFacturador(
    new Client([
        'timeout' => 60,
        'cert'    => $certTls,
        'ssl_key' => $keyTls,
        'verify'  => true,
    ]),
    $folios,
    $emisor,
    dteEmitido: new MySqlDteEmitidoRepository($pdo),
);

// Documento ORIGINAL a anular: replica la factura de validacion (emitir_real.php).
$original = new DocumentoOriginal(
    tipoDte:         TipoDte::FacturaElectronica,
    folio:           $folioOriginal,
    fechaEmision:    new DateTimeImmutable($fechaOriginal),
    receptor:        new Receptor(
        rut: '13520634-2',
        razonSocial: 'Jose Daniel Hernandez Montecino',
        giro: 'Informatica',
        direccion: 'Caupolican 321',
        comuna: 'Valdivia',
    ),
    detalles:        [
        new Detalle('Par de plantillas ortopedicas', 1, 5000),
    ],
    montoNeto:       5000,
    iva:             950,
    montoTotal:      5950,
    montosSonBrutos: false,
);

$cred = new Credenciales(
    rutEmisor: $rut,
    apiToken:  'no-usado-por-sii-directo',
    ambiente:  $ambiente,
    rutSender: '13520634-2',
);

echo "*** EMISION NOTA DE CREDITO (anula folio {$folioOriginal}) al SII ({$ambienteArg}) ***\n";

try {
    $resultado = $facturador->anular(
        $original,
        'Anula factura de validacion',
        TipoAnulacion::AnulaTotal,
        $cred,
    );

    echo "OK - Nota de Credito emitida\n";
    echo "  Folio NC: " . ($resultado->folio ?? '(null)') . "\n";
    echo "  Estado:   {$resultado->estado}\n";
    echo "  TrackID:  " . ($resultado->trackId ?? '(null)') . "\n";

    if ($resultado->xml !== null) {
        $rutaXml = __DIR__ . '/../folios_reales/envio_' . $ambienteArg . '_NC_F' . ($resultado->folio ?? '0') . '.xml';
        file_put_contents($rutaXml, $resultado->xml);
        echo "  XML:      {$rutaXml}\n";
    }
} catch (SiiAutenticacionException $e) {
    fail(sprintf("Fallo de autenticacion SII\n  estadoSii: %s\n  glosaSii: %s\n  mensaje: %s",
        $e->estadoSii, $e->glosaSii, $e->getMessage()));
} catch (EnvioRechazadoException $e) {
    fail(sprintf("El SII rechazo el envio\n  status: %s\n  trackId: %s\n  mensaje: %s",
        $e->status, $e->trackId ?? '(null)', $e->getMessage()));
} catch (Throwable $e) {
    fail(sprintf("Fallo la emision\n  clase: %s\n  mensaje: %s", get_class($e), $e->getMessage()));
}
