<?php

declare(strict_types=1);

/**
 * ============================================================================
 *  ENVIA UN LIBRO IECV DE PRUEBA AL SII (Compra o Venta).
 * ============================================================================
 *  Autentica, construye el LibroCompraVenta, firma el EnvioLibro y lo SUBE.
 *  En 'certificacion' (maullin) es seguro para el set de certificacion.
 *
 *  USO:
 *    php scripts/enviar_libro.php <ambiente> <rut_emisor> <periodo AAAA-MM> [venta|compra]
 *  El 4to argumento es opcional; por defecto 'venta'.
 * ============================================================================
 */

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use Plantiflex\FacturacionCl\Dto\Credenciales;
use Plantiflex\FacturacionCl\Dto\Libro;
use Plantiflex\FacturacionCl\Dto\LineaLibro;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Enums\TipoEnvioLibro;
use Plantiflex\FacturacionCl\Enums\TipoLibro;
use Plantiflex\FacturacionCl\Enums\TipoOperacionLibro;
use Plantiflex\FacturacionCl\Exceptions\EnvioRechazadoException;
use Plantiflex\FacturacionCl\Sii\LibroService;
use Plantiflex\Integration\Facturacion\CertificadoCrypto;
use Plantiflex\Integration\Facturacion\MySqlEmisorRepository;

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
    fail("Uso: php scripts/enviar_libro.php <ambiente> <rut_emisor> <periodo AAAA-MM> [venta|compra]");
}
[$ambienteArg, $rut, $periodo] = $args;
$operacion = $args[3] ?? 'venta';

if ($ambienteArg === 'certificacion') {
    $ambiente = Ambiente::Certificacion;
} elseif ($ambienteArg === 'produccion') {
    $ambiente = Ambiente::Produccion;
} else {
    fail("ambiente debe ser 'certificacion' o 'produccion'.");
}
if (! preg_match('/^\d{4}-\d{2}$/', $periodo)) {
    fail("periodo debe ser AAAA-MM (recibido: '{$periodo}').");
}
if (! in_array($operacion, ['venta', 'compra'], true)) {
    fail("operacion debe ser 'venta' o 'compra' (recibido: '{$operacion}').");
}

$pdo    = conectarDb();
$crypto = crearCrypto();
$emisor = new MySqlEmisorRepository($pdo, $crypto);

$certTls = __DIR__ . '/../fullchain.pem';
$keyTls  = __DIR__ . '/../key.pem';
foreach (['fullchain.pem' => $certTls, 'key.pem' => $keyTls] as $nombre => $ruta) {
    if (! is_file($ruta) || ! is_readable($ruta)) {
        fail("No se encuentra {$nombre} para el TLS mutuo en: {$ruta}");
    }
}

$client = new Client([
    'timeout' => 60,
    'cert'    => $certTls,
    'ssl_key' => $keyTls,
    'verify'  => true,
]);

$service = new LibroService($client, $emisor);

if ($operacion === 'compra') {
    // Libro de COMPRA del SET de certificacion 4860967 (7 documentos).
    // Tipos: 30 (factura), 33 (factura electronica), 60 (nota de credito),
    // 46 (factura de compra). Casos especiales: uso comun (FctProp 0.60),
    // entrega gratuita (IVANoRec cod 4), retencion total (OtrosImp CodImp 15).
    $libro = new Libro(
        tipoOperacion: TipoOperacionLibro::Compra,
        periodoTributario: $periodo,
        tipoLibro: TipoLibro::Especial,
        tipoEnvio: TipoEnvioLibro::Total,
        folioNotificacion: 1,
        factorProporcionalidad: 0.60,
        lineas: [
            // 4860967-1: FACTURA (30) del giro con derecho a credito.
            new LineaLibro(
                tpoDoc: 30,
                nroDoc: 234,
                fecha: new DateTimeImmutable($periodo . '-03'),
                rutContraparte: '76086428-5',
                razonSocial: 'Proveedor de Prueba SpA',
                mntExe: 0,
                mntNeto: 22671,
                mntIva: 4307,
                mntTotal: 26978,
            ),
            // 4860967-2: FACTURA ELECTRONICA (33) del giro, mixta exenta+afecta.
            new LineaLibro(
                tpoDoc: 33,
                nroDoc: 32,
                fecha: new DateTimeImmutable($periodo . '-05'),
                rutContraparte: '76086428-5',
                razonSocial: 'Proveedor de Prueba SpA',
                mntExe: 8921,
                mntNeto: 6740,
                mntIva: 1281,
                mntTotal: 16942,
            ),
            // 4860967-3: FACTURA (30) con IVA USO COMUN (FctProp 0.60).
            new LineaLibro(
                tpoDoc: 30,
                nroDoc: 781,
                fecha: new DateTimeImmutable($periodo . '-08'),
                rutContraparte: '76086428-5',
                razonSocial: 'Proveedor de Prueba SpA',
                mntExe: 0,
                mntNeto: 29802,
                mntIva: 0,
                mntTotal: 35464,
                ivaUsoComun: 5662,
            ),
            // 4860967-4: NOTA DE CREDITO (60) por descuento a factura 234.
            new LineaLibro(
                tpoDoc: 60,
                nroDoc: 451,
                fecha: new DateTimeImmutable($periodo . '-10'),
                rutContraparte: '76086428-5',
                razonSocial: 'Proveedor de Prueba SpA',
                mntExe: 0,
                mntNeto: 2728,
                mntIva: 518,
                mntTotal: 3246,
            ),
            // 4860967-5: FACTURA ELECTRONICA (33) ENTREGA GRATUITA (IVANoRec cod 4).
            new LineaLibro(
                tpoDoc: 33,
                nroDoc: 67,
                fecha: new DateTimeImmutable($periodo . '-12'),
                rutContraparte: '76086428-5',
                razonSocial: 'Proveedor de Prueba SpA',
                mntExe: 0,
                mntNeto: 10116,
                mntIva: 0,
                mntTotal: 12038,
                codIvaNoRec: 4,
                mntIvaNoRec: 1922,
            ),
            // 4860967-6: FACTURA DE COMPRA (46) con RETENCION TOTAL (OtrosImp CodImp 15).
            new LineaLibro(
                tpoDoc: 46,
                nroDoc: 9,
                fecha: new DateTimeImmutable($periodo . '-15'),
                rutContraparte: '76086428-5',
                razonSocial: 'Proveedor de Prueba SpA',
                mntExe: 0,
                mntNeto: 9620,
                mntIva: 1828,
                mntTotal: 9620,
                codOtroImp: 15,
                mntOtroImp: 1828,
            ),
            // 4860967-7: NOTA DE CREDITO (60) por descuento a factura electronica 32.
            new LineaLibro(
                tpoDoc: 60,
                nroDoc: 211,
                fecha: new DateTimeImmutable($periodo . '-18'),
                rutContraparte: '76086428-5',
                razonSocial: 'Proveedor de Prueba SpA',
                mntExe: 0,
                mntNeto: 4662,
                mntIva: 886,
                mntTotal: 5548,
            ),
        ],
    );
} else {
    // Libro de VENTA del SET 4860966: 8 documentos del Set Basico (folios 55-58, 25-27, 9).
    $libro = new Libro(
        tipoOperacion: TipoOperacionLibro::Venta,
        periodoTributario: $periodo,
        tipoLibro: TipoLibro::Especial,
        tipoEnvio: TipoEnvioLibro::Total,
        folioNotificacion: 1,
        lineas: [
            new LineaLibro(
                tpoDoc: 33,
                nroDoc: 55,
                fecha: new DateTimeImmutable($periodo . '-20'),
                rutContraparte: '66666666-6',
                razonSocial: 'Cliente de Prueba',
                mntExe: 0,
                mntNeto: 160711,
                mntIva: 30535,
                mntTotal: 191246,
            ),
            new LineaLibro(
                tpoDoc: 33,
                nroDoc: 56,
                fecha: new DateTimeImmutable($periodo . '-20'),
                rutContraparte: '66666666-6',
                razonSocial: 'Cliente de Prueba',
                mntExe: 0,
                mntNeto: 448116,
                mntIva: 85142,
                mntTotal: 533258,
            ),
            new LineaLibro(
                tpoDoc: 33,
                nroDoc: 57,
                fecha: new DateTimeImmutable($periodo . '-20'),
                rutContraparte: '66666666-6',
                razonSocial: 'Cliente de Prueba',
                mntExe: 34674,
                mntNeto: 463896,
                mntIva: 88140,
                mntTotal: 586710,
            ),
            new LineaLibro(
                tpoDoc: 33,
                nroDoc: 58,
                fecha: new DateTimeImmutable($periodo . '-20'),
                rutContraparte: '66666666-6',
                razonSocial: 'Cliente de Prueba',
                mntExe: 13528,
                mntNeto: 116522,
                mntIva: 22139,
                mntTotal: 152189,
            ),
            new LineaLibro(
                tpoDoc: 61,
                nroDoc: 25,
                fecha: new DateTimeImmutable($periodo . '-20'),
                rutContraparte: '66666666-6',
                razonSocial: 'Cliente de Prueba',
                mntExe: 0,
                mntNeto: 0,
                mntIva: 0,
                mntTotal: 0,
            ),
            new LineaLibro(
                tpoDoc: 61,
                nroDoc: 26,
                fecha: new DateTimeImmutable($periodo . '-20'),
                rutContraparte: '66666666-6',
                razonSocial: 'Cliente de Prueba',
                mntExe: 0,
                mntNeto: 195017,
                mntIva: 37053,
                mntTotal: 232070,
            ),
            new LineaLibro(
                tpoDoc: 61,
                nroDoc: 27,
                fecha: new DateTimeImmutable($periodo . '-20'),
                rutContraparte: '66666666-6',
                razonSocial: 'Cliente de Prueba',
                mntExe: 34674,
                mntNeto: 463896,
                mntIva: 88140,
                mntTotal: 586710,
            ),
            new LineaLibro(
                tpoDoc: 56,
                nroDoc: 9,
                fecha: new DateTimeImmutable($periodo . '-20'),
                rutContraparte: '66666666-6',
                razonSocial: 'Cliente de Prueba',
                mntExe: 0,
                mntNeto: 0,
                mntIva: 0,
                mntTotal: 0,
            ),
        ],
    );
}

$cred = new Credenciales(
    rutEmisor: $rut,
    apiToken:  'no-usado-por-sii-directo',
    ambiente:  $ambiente,
    rutSender: '13520634-2',
);

echo "*** ENVIO de LIBRO IECV {$operacion} ({$ambienteArg}) emisor {$rut} periodo {$periodo} ***\n";

try {
    $res = $service->enviarLibro($libro, $cred);
    echo "OK - Libro enviado\n";
    echo "  Status:  {$res['status']}\n";
    echo "  TrackID: " . ($res['trackId'] !== '' ? $res['trackId'] : '(vacio)') . "\n";
} catch (EnvioRechazadoException $e) {
    fail(sprintf(
        "El SII rechazo el libro\n  status: %s\n  trackId: %s\n  mensaje: %s",
        $e->status,
        $e->trackId ?? '(null)',
        $e->getMessage(),
    ));
} catch (Throwable $e) {
    fail(sprintf("Fallo el envio del libro\n  clase: %s\n  mensaje: %s", get_class($e), $e->getMessage()));
}
