<?php

declare(strict_types=1);

/**
 * ============================================================================
 *  EMITE UNA BOLETA ELECTRONICA (39) DE VERDAD CONTRA EL SII.
 * ============================================================================
 *  Autentica (canal REST de boleta), construye, firma, agrega el TED y SUBE el
 *  EnvioBOLETA a pangal (cert) / rahue (prod) via BoletaFacturador::emitir().
 *  Consume un folio real del CAF de boleta (39) cargado.
 *
 *  REC + trackid = canal tecnico OK. La certificacion formal de boletas
 *  (set de pruebas SII + RVD) es un proceso aparte.
 * ============================================================================
 *
 * USO:
 *   php scripts/emitir_boleta_real.php <ambiente> <rut_emisor>
 *
 * REQUISITOS (en BD para ese rut/ambiente): CAF de boleta (39), certificado,
 * datos emisor. Y fullchain.pem + key.pem en la raiz (TLS mutuo).
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
use Plantiflex\FacturacionCl\Providers\BoletaFacturador;
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
if (count($args) !== 2) {
    fail("Uso: php scripts/emitir_boleta_real.php <ambiente> <rut_emisor>");
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
$dteEmitido = new MySqlDteEmitidoRepository($pdo);

// TLS mutuo: mismo certificado en el handshake (igual que facturas).
$certTls = __DIR__ . '/../fullchain.pem';
$keyTls  = __DIR__ . '/../key.pem';
foreach (['fullchain.pem' => $certTls, 'key.pem' => $keyTls] as $nombre => $ruta) {
    if (! is_file($ruta) || ! is_readable($ruta)) {
        fail("No se encuentra {$nombre} para el TLS mutuo en: {$ruta}");
    }
}

$facturador = new BoletaFacturador(
    new Client([
        'timeout' => 60,
        'cert'    => $certTls,
        'ssl_key' => $keyTls,
        'verify'  => true,
    ]),
    $folios,
    $emisor,
    dteEmitido: $dteEmitido,
);

// Boleta 39: 1 item afecto, monto BRUTO (precio con IVA incluido). Receptor
// real (RUT + razonSocial); giro/direccion/comuna en null: boleta no los
// exige y las boletas certificadas DOK (folios 111-148) tampoco los llevaban.
$doc = new DocumentoTributario(
    tipoDte: TipoDte::BoletaElectronica,
    receptor: new Receptor(
        rut: '15531064-2',
        razonSocial: 'Alejandra Urrutia',
    ),
    detalles: [
        new Detalle('Par de plantillas ortopedicas', 1, 50000),
    ],
    montosSonBrutos: true,
);

$cred = new Credenciales(
    rutEmisor: $rut,
    apiToken:  'no-usado-por-sii-directo',
    ambiente:  $ambiente,
    rutSender: '13520634-2',
);

echo "*** EMISION REAL de BOLETA 39 al SII ({$ambienteArg}) para emisor {$rut} ***\n";

try {
    $resultado = $facturador->emitir($doc, $cred);

    echo "OK - boleta enviada\n";
    echo "  Folio:    " . ($resultado->folio ?? '(null)') . "\n";
    echo "  Estado:   {$resultado->estado}\n";
    echo "  TrackID:  " . ($resultado->trackId ?? '(null)') . "\n";
    echo "  EstadoSII:" . ($resultado->raw['estado'] ?? '(?)') . "\n";
} catch (SiiAutenticacionException $e) {
    fail(sprintf(
        "Fallo de autenticacion (boleta)\n  clase: %s\n  estadoSii: %s\n  glosaSii: %s\n  mensaje: %s",
        get_class($e),
        $e->estadoSii,
        $e->glosaSii,
        $e->getMessage(),
    ));
} catch (EnvioRechazadoException $e) {
    fail(sprintf(
        "El SII rechazo el envio de boleta\n  clase: %s\n  status: %s\n  trackId: %s\n  mensaje: %s",
        get_class($e),
        $e->status,
        $e->trackId ?? '(null)',
        $e->getMessage(),
    ));
} catch (Throwable $e) {
    fail(sprintf(
        "Fallo la emision de boleta\n  clase: %s\n  mensaje: %s",
        get_class($e),
        $e->getMessage(),
    ));
}
