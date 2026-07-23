<?php

declare(strict_types=1);

/**
 * ============================================================================
 *  ESTE SCRIPT EMITE UN DTE DE VERDAD CONTRA EL SII.
 * ============================================================================
 *  Autentica, construye, firma, agrega el TED y SUBE el EnvioDTE al SII via
 *  SiiDirectoFacturador::emitir(). Consume un folio real del CAF cargado y, si
 *  el SII lo acepta, queda emitido. NO es una prueba local.
 *
 *  En 'certificacion' (maullin) es seguro para pruebas. En 'produccion'
 *  (palena) emite documentos tributarios reales.
 * ============================================================================
 *
 * USO:
 *   php scripts/emitir_real.php <ambiente> <rut_emisor>
 *
 * EJEMPLO:
 *   DB_HOST=localhost DB_NAME=plantiflex DB_USER=root DB_PASS=secreto \
 *   CRYPTO_MASTER_KEY=<64 hex> \
 *   php scripts/emitir_real.php certificacion 77724622-4
 *
 * REQUISITOS (cargados en la BD para ese rut/ambiente):
 *   - CAF        (scripts/cargar_caf.php)
 *   - Certificado(scripts/cargar_certificado.php)
 *   - Datos emisor (scripts/cargar_emisor.php)
 *
 * VARIABLES DE ENTORNO:
 *   DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_PORT(opcional)  -> conexion MySQL.
 *   CRYPTO_MASTER_KEY                                      -> 32 bytes en HEX.
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
    fail("Uso: php scripts/emitir_real.php <ambiente> <rut_emisor>");
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

// El SII exige TLS mutuo (mismo certificado) tanto para autenticar como para
// el upload. El Client debe presentar el certificado cliente en el handshake;
// sin el, el upload falla con cURL error 56 "unexpected eof". Este mismo Client
// se pasa a SiiAutenticador y SiiUploader (ver SiiDirectoFacturador), asi que
// ambos heredan el TLS mutuo.
$certTls = __DIR__ . '/../fullchain.pem'; // certificado + cadena
$keyTls  = __DIR__ . '/../key.pem';       // llave privada
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

// Factura de validacion produccion: tipo 33, 1 item afecto, monto neto.
$doc = new DocumentoTributario(
    tipoDte: TipoDte::FacturaElectronica,
    receptor: new Receptor(
        rut: '13520634-2',
        razonSocial: 'Jose Daniel Hernandez Montecino',
        giro: 'Informatica',
        direccion: 'Caupolican 321',
        comuna: 'Valdivia',
    ),
    detalles: [
        new Detalle('Par de plantillas ortopedicas', 1, 5000),
    ],
    montosSonBrutos: false,
);

$cred = new Credenciales(
    rutEmisor: $rut,
    apiToken:  'no-usado-por-sii-directo',
    ambiente:  $ambiente,
    rutSender: '13520634-2',
);

echo "*** EMISION REAL al SII ({$ambienteArg}) para emisor {$rut} ***\n";

try {
    $resultado = $facturador->emitir($doc, $cred);

    echo "OK - DTE emitido\n";
    echo "  Folio:   " . ($resultado->folio ?? '(null)') . "\n";
    echo "  Estado:  {$resultado->estado}\n";
    echo "  TrackID: " . ($resultado->trackId ?? '(null)') . "\n";

    if ($resultado->xml !== null) {
        $rutaXml = __DIR__ . '/../folios_reales/envio_' . $ambienteArg . '_F' . ($resultado->folio ?? '0') . '.xml';
        file_put_contents($rutaXml, $resultado->xml);
        echo "  XML:     {$rutaXml}\n";
    }
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
