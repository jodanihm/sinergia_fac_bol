<?php

declare(strict_types=1);

/**
 * Emite el set de 5 boletas de certificacion en UN SOLO EnvioBOLETA.
 * USO: php scripts/emitir_set_boletas.php <ambiente> <rut_emisor>
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

$args = array_slice($argv, 1);
if (count($args) !== 2) {
    fail("Uso: php scripts/emitir_set_boletas.php <ambiente> <rut_emisor>");
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

$receptor = new Receptor('66666666-6', 'Consumidor Final');

$nombresYDetalles = [
    'CASO-1' => [
        new Detalle('Cambio de aceite', 1, 19900),
        new Detalle('Alineacion y balanceo', 1, 9900),
    ],
    'CASO-2' => [
        new Detalle('Papel de regalo', 17, 120),
    ],
    'CASO-3' => [
        new Detalle('Sandwic', 2, 1500),
        new Detalle('Bebida', 2, 550),
    ],
    'CASO-4' => [
        new Detalle('item afecto 1', 8, 1590),
        new Detalle('item exento 2', 2, 1000, true),
    ],
    'CASO-5' => [
        new Detalle('Arroz', 5, 700, false, null, 'Kg'),
    ],
];

$docs    = [];
$nombres = [];

foreach ($nombresYDetalles as $nombreCaso => $detalles) {
    $docs[] = new DocumentoTributario(
        tipoDte: TipoDte::BoletaElectronica,
        receptor: $receptor,
        detalles: $detalles,
        montosSonBrutos: true,
        referencias: [[
            // Set de certificacion de boletas 4860969: conservar TpoDocRef/FolioRef
            // procesable por REST y agregar CodRef=SET pedido por el instructivo.
            'tipoDocumento' => 'SET',
            'codigo'        => 'SET',
            'razon'         => $nombreCaso,   // "CASO-1".."CASO-5"
        ]],
    );
    $nombres[] = $nombreCaso;
}

echo "*** EMISION SET BOLETAS LOTE REST ({$ambienteArg}) emisor {$rut} ***\n\n";

try {
    $resultado = $facturador->emitirLote($docs, $credenciales);
    file_put_contents(__DIR__ . '/../envio_set_debug.xml', $resultado['xml']);

    echo "TrackID : " . $resultado['trackId'] . "\n";
    echo "Estado  : " . $resultado['estado'] . "\n\n";
    echo "Raw     : " . ($resultado['raw']['raw'] ?? json_encode($resultado['raw'], JSON_UNESCAPED_UNICODE)) . "\n\n";

    echo "--- Folios asignados ---\n";
    foreach ($resultado['boletas'] as $i => $boleta) {
        $caso = $nombres[$i] ?? "DOC-{$i}";
        printf("  %-8s  folio %d\n", $caso, $boleta['folio']);
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
