<?php

declare(strict_types=1);

/**
 * Emite una Nota de Crédito Electrónica (61) que anula la boleta 39 folio 3,
 * por el canal EnvioDTE (SiiDirectoFacturador / maullin).
 *
 * USO:
 *   php scripts/emitir_nc_boleta.php <ambiente> <rut_emisor>
 */

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use Plantiflex\FacturacionCl\Dto\Credenciales;
use Plantiflex\FacturacionCl\Dto\Detalle;
use Plantiflex\FacturacionCl\Dto\DocumentoTributario;
use Plantiflex\FacturacionCl\Dto\Receptor;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Exceptions\EnvioRechazadoException;
use Plantiflex\FacturacionCl\Exceptions\SiiAutenticacionException;
use Plantiflex\FacturacionCl\Providers\SiiDirectoFacturador;
use Plantiflex\Integration\Facturacion\CertificadoCrypto;
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
if (count($args) !== 2) {
    fail("Uso: php scripts/emitir_nc_boleta.php <ambiente> <rut_emisor>");
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
);

$credenciales = new Credenciales(
    rutEmisor: $rut,
    apiToken:  'no-usado-por-sii-directo',
    ambiente:  $ambiente,
    rutSender: '13520634-2',
);

// ---------------------------------------------------------------------------
// Nota de Crédito 61 que anula boleta 39 folio 3
// ---------------------------------------------------------------------------

$fchBoleta = '2026-06-05'; // FchEmis real de la boleta folio 3; ajustar si se emitio otro dia

$nc = new DocumentoTributario(
    tipoDte: TipoDte::NotaCreditoElectronica,
    receptor: new Receptor('66666666-6', 'Consumidor Final'),
    detalles: [new Detalle('Anulacion boleta electronica 39 folio 3', 1, 11900)],
    montosSonBrutos: true,
    referencias: [[
        'tipoDocumento' => 39,
        'folio'         => 3,
        'fecha'         => $fchBoleta,
        'codigo'        => 1,            // 1 = anula
        'razon'         => 'Anula boleta de prueba',
    ]],
    observaciones: 'Anulacion boleta 39 folio 3 - prueba certificacion',
);

echo "*** EMISION NC 61 (anula boleta 39 folio 3) al SII ({$ambienteArg}) para emisor {$rut} ***\n";

try {
    $resultado = $facturador->emitir($nc, $credenciales);

    echo "OK - NC emitida\n";
    echo "  TipoDte: " . $resultado->tipoDte->value . "\n";
    echo "  Folio:   " . ($resultado->folio ?? '(null)') . "\n";
    echo "  Estado:  {$resultado->estado}\n";
    echo "  TrackID: " . ($resultado->trackId ?? '(null)') . "\n";
    echo "  Raw: " . json_encode($resultado->raw, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} catch (SiiAutenticacionException $e) {
    fail(sprintf(
        "Fallo de autenticacion SII\n  clase: %s\n  estadoSii: %s\n  glosaSii: %s\n  mensaje: %s",
        get_class($e),
        $e->estadoSii,
        $e->glosaSii,
        $e->getMessage(),
    ));
} catch (EnvioRechazadoException $e) {
    fail(sprintf(
        "El SII rechazo el envio\n  clase: %s\n  status: %s\n  trackId: %s\n  mensaje: %s",
        get_class($e),
        $e->status,
        $e->trackId ?? '(null)',
        $e->getMessage(),
    ));
} catch (Throwable $e) {
    fail(sprintf(
        "Fallo la emision\n  clase: %s\n  mensaje: %s",
        get_class($e),
        $e->getMessage(),
    ));
}
