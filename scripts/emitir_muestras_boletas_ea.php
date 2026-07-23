<?php

declare(strict_types=1);

/**
 * Emite 10 boletas (39) de SIMULACION en UN SOLO EnvioBOLETA, para
 * EASY AGENDA SPA (78157243-8) -- operaciones simuladas normales del giro
 * real (servicios de agendamiento / desarrollo web), NO casos del set de
 * pruebas: por eso NINGUNA boleta lleva referencia al SET.
 *
 * Copia parametrizada de scripts/emitir_set_boletas_ea.php (que NO se toca).
 * Los folios NO se eligen aqui: MySqlFolioRepository::asignarSiguienteFolio()
 * entrega el siguiente disponible del CAF vigente (proximo_folio en dte_caf),
 * que tras el ultimo set (111-115) continua desde 116.
 *
 * MODO DRY-RUN POR DEFECTO: sin --enviar, imprime el plan exacto de las 10
 * boletas (glosas, montos brutos y el calculo neto/IVA/total con la MISMA
 * formula de DteXmlBuilder::resolverTotales() para montosSonBrutos=true) SIN
 * tocar BD ni SII. A diferencia del script de RVD, aqui el dry-run NO genera
 * el XML final: construirlo exige asignar folios (mutaria dte_caf) y
 * BoletaFacturador::emitirLote() asigna+firma+envia en una sola operacion.
 *
 * USO:
 *   php scripts/emitir_muestras_boletas_ea.php            (dry-run: solo muestra el plan)
 *   php scripts/emitir_muestras_boletas_ea.php --enviar   (emision real: consume folios y sube al SII)
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

// ---------------------------------------------------------------------------
// 10 operaciones simuladas del giro real de EASY AGENDA (agendamiento /
// desarrollo web). Precios BRUTOS con IVA incluido (montosSonBrutos=true),
// entre $15.000 y $250.000. SIN referencia al SET (no son casos del set).
// ---------------------------------------------------------------------------
$boletas = [
    [new Detalle('Suscripcion mensual plan agenda pro', 1, 24990)],
    [new Detalle('Habilitacion agenda online', 1, 45000)],
    [new Detalle('Desarrollo modulo de reservas', 1, 250000)],
    [new Detalle('Suscripcion mensual plan agenda basico', 1, 15990)],
    [new Detalle('Configuracion recordatorios WhatsApp', 1, 35000)],
    [new Detalle('Integracion pasarela de pagos', 1, 120000)],
    [new Detalle('Soporte tecnico mensual', 1, 29990)],
    [new Detalle('Diseno pagina de reservas personalizada', 1, 85000)],
    [new Detalle('Capacitacion uso de agenda', 2, 25000)],
    [new Detalle('Migracion de datos de clientes', 1, 60000)],
];

$docs = [];
foreach ($boletas as $detalles) {
    $docs[] = new DocumentoTributario(
        tipoDte: TipoDte::BoletaElectronica,
        receptor: $receptor,
        detalles: $detalles,
        montosSonBrutos: true,
        // Sin referencias: operaciones simuladas normales, no casos del set.
    );
}

// ---------------------------------------------------------------------------
// Plan (se imprime siempre, dry-run o real): mismos calculos que
// DteXmlBuilder::resolverTotales() con montosSonBrutos=true.
// ---------------------------------------------------------------------------
echo "*** MUESTRAS BOLETAS SIMULACION (certificacion) emisor {$rut} (EASY AGENDA SPA) ***\n\n";
printf("%-3s %-45s %8s %10s %8s %8s %8s\n", '#', 'Glosa', 'Cant', 'PrecioBr', 'Neto', 'IVA', 'Total');
$sumaTotal = 0;
foreach ($boletas as $i => $detalles) {
    $bruto = 0;
    $glosas = [];
    foreach ($detalles as $d) {
        $bruto += (int) round($d->cantidad * $d->precioUnitario);
        $glosas[] = $d->nombre;
    }
    $neto  = (int) round($bruto / 1.19);
    $iva   = (int) round($neto * 0.19);
    $total = $neto + $iva;
    $sumaTotal += $total;
    printf(
        "%-3d %-45s %8s %10d %8d %8d %8d\n",
        $i + 1,
        implode(' + ', $glosas),
        implode('+', array_map(static fn (Detalle $d): string => (string) (int) $d->cantidad, $detalles)),
        $bruto,
        $neto,
        $iva,
        $total,
    );
}
echo str_repeat('-', 95) . "\n";
printf("%-68s %25d\n\n", 'Suma MntTotal del sobre:', $sumaTotal);

if (! $enviar) {
    echo "DRY-RUN: no se conecto a la BD ni al SII, no se consumio ningun folio.\n";
    echo "Corre con --enviar para la emision real (folios desde el proximo disponible del CAF, 116+).\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Emision real: BoletaFacturador::emitirLote() asigna folios (siguiente
// disponible del CAF, 116+), construye/firma el sobre y lo sube por el canal
// REST de boleta (pangal).
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

echo "*** ENVIANDO lote de " . count($docs) . " boletas al SII (canal REST boleta) ***\n\n";

try {
    $resultado = $facturador->emitirLote($docs, $credenciales);
    file_put_contents(__DIR__ . '/../envio_muestras_boletas_ea_debug.xml', $resultado['xml']);

    echo "TrackID : " . $resultado['trackId'] . "\n";
    echo "Estado  : " . $resultado['estado'] . "\n";
    echo "XML     : envio_muestras_boletas_ea_debug.xml\n\n";

    // Folios usados: necesarios despues para generar los PDF de muestras
    // (GET /api/v1/dte/39/{folio}/pdf o BoletaPdfGenerator con tipo+folio).
    echo "--- Folios usados (para generar los PDF despues) ---\n";
    $foliosUsados = [];
    foreach ($resultado['boletas'] as $i => $boleta) {
        $foliosUsados[] = $boleta['folio'];
        printf("  Boleta %2d  folio %d\n", $i + 1, $boleta['folio']);
    }
    echo "\nLista: " . implode(', ', $foliosUsados) . "\n";
} catch (Throwable $e) {
    echo 'ERROR: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
