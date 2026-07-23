<?php

declare(strict_types=1);

/**
 * Emite UNA boleta (39) de reemplazo para EASY AGENDA SPA (78157243-8):
 * "Diseno pagina de reservas personalizada", 1 x $84.990 bruto.
 *
 * Reemplaza la muestra del lote anterior cuyo total no cuadraba exacto por
 * redondeo ($85.000 bruto -> neto 71.429 + IVA 13.572 = 85.001). Con $84.990:
 * neto = round(84990/1.19) = 71.420, IVA = round(71420*0.19) = 13.570,
 * total = 84.990 EXACTO (misma formula de DteXmlBuilder::resolverTotales()).
 *
 * Basado en scripts/emitir_muestras_boletas_ea.php (que NO se toca). Sin
 * referencia al SET (operacion simulada normal). El folio lo asigna
 * MySqlFolioRepository::asignarSiguienteFolio() (siguiente del CAF: 126).
 *
 * USO:
 *   php scripts/emitir_muestra_reemplazo_ea.php            (dry-run: solo muestra el plan)
 *   php scripts/emitir_muestra_reemplazo_ea.php --enviar   (emision real: consume folio y sube al SII)
 */

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use Plantiflex\FacturacionCl\Dto\Credenciales;
use Plantiflex\FacturacionCl\Dto\Detalle;
use Plantiflex\FacturacionCl\Dto\DocumentoTributario;
use Plantiflex\FacturacionCl\Dto\Receptor;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Providers\BoletaFacturador;
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

$enviar = in_array('--enviar', array_slice($argv, 1), true);

$rut      = '78157243-8';
$ambiente = Ambiente::Certificacion;

$receptor = new Receptor('66666666-6', 'Consumidor Final');

$detalle = new Detalle('Diseno pagina de reservas personalizada', 1, 84990);

$doc = new DocumentoTributario(
    tipoDte: TipoDte::BoletaElectronica,
    receptor: $receptor,
    detalles: [$detalle],
    montosSonBrutos: true,
    // Sin referencias: operacion simulada normal, no es caso del set.
);

// ---------------------------------------------------------------------------
// Plan (se imprime siempre): misma formula de DteXmlBuilder::resolverTotales()
// con montosSonBrutos=true. Debe cuadrar EXACTO (sin +1).
// ---------------------------------------------------------------------------
$bruto = (int) round($detalle->cantidad * $detalle->precioUnitario);
$neto  = (int) round($bruto / 1.19);
$iva   = (int) round($neto * 0.19);
$total = $neto + $iva;

echo "*** MUESTRA BOLETA REEMPLAZO (certificacion) emisor {$rut} (EASY AGENDA SPA) ***\n\n";
printf("Glosa    : %s\n", $detalle->nombre);
printf("Cantidad : %d\n", (int) $detalle->cantidad);
printf("PrecioBr : %d\n", (int) $detalle->precioUnitario);
printf("Neto     : %d\n", $neto);
printf("IVA      : %d\n", $iva);
printf("Total    : %d %s\n\n", $total, $total === $bruto ? '(cuadra EXACTO con el bruto)' : "(NO CUADRA: bruto={$bruto} -- ABORTAR)");

if ($total !== $bruto) {
    fail('El total calculado no cuadra exacto con el precio bruto; revisar el monto antes de emitir.');
}

if (! $enviar) {
    echo "DRY-RUN: no se conecto a la BD ni al SII, no se consumio ningun folio.\n";
    echo "Corre con --enviar para la emision real (folio siguiente del CAF: 126).\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Emision real.
// ---------------------------------------------------------------------------
$pdo    = conectarDb();
$crypto = crearCrypto();

// crypto como 4to argumento (cryptoKek): sin el, el repo no puede desenvolver
// la DEK del CAF cifrado y el descifrado falla (leccion del bug ya vivido).
$folios = new MySqlFolioRepository(
    $pdo,
    function (string $c) use ($crypto): string {
        return $crypto->descifrar($c);
    },
    null,
    $crypto,
);
$emisor = new MySqlEmisorRepository($pdo, $crypto);

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
);

$credenciales = new Credenciales(
    rutEmisor: $rut,
    apiToken:  'no-usado-por-sii-directo',
    ambiente:  $ambiente,
    rutSender: '13520634-2',
);

echo "*** ENVIANDO boleta de reemplazo al SII (canal REST boleta) ***\n\n";

try {
    $resultado = $facturador->emitirLote([$doc], $credenciales);
    file_put_contents(__DIR__ . '/../envio_muestra_reemplazo_ea_debug.xml', $resultado['xml']);

    $folio = $resultado['boletas'][0]['folio'] ?? null;
    echo "TrackID : " . $resultado['trackId'] . "\n";
    echo "Estado  : " . $resultado['estado'] . "\n";
    echo "Folio   : " . ($folio ?? '(desconocido)') . "\n";
    echo "XML     : envio_muestra_reemplazo_ea_debug.xml\n";
} catch (Throwable $e) {
    echo 'ERROR: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
