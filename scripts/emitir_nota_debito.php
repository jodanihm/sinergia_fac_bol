<?php

declare(strict_types=1);

/**
 * Emite una NOTA DE DEBITO (56) que deja sin efecto una NOTA DE CREDITO previa
 * (CodRef 1 = Anula), via SiiDirectoFacturador::emitirDocumentoReferenciado().
 *
 * USO:
 *   php scripts/emitir_nota_debito.php <ambiente> <rut_emisor> <folio_nc> [fecha_nc AAAA-MM-DD]
 *
 * <folio_nc> es la Nota de Credito (tipo 61) que se anula. Datos del documento
 * replican el caso de prueba (neto 20.000, IVA 3.800, total 23.800).
 */

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use Plantiflex\FacturacionCl\Dto\Credenciales;
use Plantiflex\FacturacionCl\Dto\Detalle;
use Plantiflex\FacturacionCl\Dto\DocumentoOriginal;
use Plantiflex\FacturacionCl\Dto\DocumentoTributario;
use Plantiflex\FacturacionCl\Dto\Receptor;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Enums\TipoAnulacion;
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
if (count($args) < 3 || count($args) > 4) {
    fail("Uso: php scripts/emitir_nota_debito.php <ambiente> <rut_emisor> <folio_nc> [fecha_nc AAAA-MM-DD]");
}
$ambienteArg = $args[0];
$rut         = $args[1];
$folioNc     = (int) $args[2];
$fechaNc     = $args[3] ?? date('Y-m-d');

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
if ($folioNc <= 0) {
    fail('folio_nc debe ser > 0.');
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

$receptor = new Receptor(
    rut: '66666666-6',
    razonSocial: 'Cliente de Prueba',
    giro: 'Servicios',
    direccion: 'Calle Falsa 123',
    comuna: 'Santiago',
);
$detalles = [
    new Detalle('Item de prueba 1', 1, 10000),
    new Detalle('Item de prueba 2', 2, 5000),
];

// La ND (56) con su propio detalle (revierte el monto de la NC).
$nd = new DocumentoTributario(
    tipoDte:         TipoDte::NotaDebitoElectronica,
    receptor:        $receptor,
    detalles:        $detalles,
    montosSonBrutos: false,
    observaciones:   'Deja sin efecto nota de credito',
);

// Documento referenciado: la NC (61) que se anula.
$refNc = new DocumentoOriginal(
    tipoDte:         TipoDte::NotaCreditoElectronica,
    folio:           $folioNc,
    fechaEmision:    new DateTimeImmutable($fechaNc),
    receptor:        $receptor,
    detalles:        $detalles,
    montoNeto:       20000,
    iva:             3800,
    montoTotal:      23800,
    montosSonBrutos: false,
);

$cred = new Credenciales(
    rutEmisor: $rut,
    apiToken:  'no-usado-por-sii-directo',
    ambiente:  $ambiente,
    rutSender: '13520634-2',
);

echo "*** EMISION NOTA DE DEBITO (anula NC folio {$folioNc}) al SII ({$ambienteArg}) ***\n";

try {
    $resultado = $facturador->emitirDocumentoReferenciado(
        $nd,
        $refNc,
        TipoAnulacion::AnulaTotal,
        $cred,
    );

    echo "OK - Nota de Debito emitida\n";
    echo "  Folio ND: " . ($resultado->folio ?? '(null)') . "\n";
    echo "  Estado:   {$resultado->estado}\n";
    echo "  TrackID:  " . ($resultado->trackId ?? '(null)') . "\n";
} catch (SiiAutenticacionException $e) {
    fail(sprintf("Fallo de autenticacion SII\n  estadoSii: %s\n  glosaSii: %s\n  mensaje: %s",
        $e->estadoSii, $e->glosaSii, $e->getMessage()));
} catch (EnvioRechazadoException $e) {
    fail(sprintf("El SII rechazo el envio\n  status: %s\n  trackId: %s\n  mensaje: %s",
        $e->status, $e->trackId ?? '(null)', $e->getMessage()));
} catch (Throwable $e) {
    fail(sprintf("Fallo la emision\n  clase: %s\n  mensaje: %s", get_class($e), $e->getMessage()));
}
