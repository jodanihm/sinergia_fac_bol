<?php

declare(strict_types=1);

/**
 * Diagnostico aislado de referencias para SET BOLETAS 4860969.
 *
 * Uso:
 *   php scripts/probar_boleta_set_referencias.php certificacion 77724622-4 codref_only
 *   php scripts/probar_boleta_set_referencias.php certificacion 77724622-4 combined
 *
 * Restricciones:
 * - Solo certificacion.
 * - Solo RUT emisor 77724622-4.
 * - Solo UNA boleta 39 por ejecucion.
 * - Solo REST/pangal via BoletaFacturador.
 * - No RVD, no Maullin/DTEUpload, no retry.
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

$args = array_slice($argv, 1);
if (count($args) !== 3) {
    fail('Uso: php scripts/probar_boleta_set_referencias.php certificacion 77724622-4 <codref_only|combined>');
}

[$ambienteArg, $rut, $variante] = $args;

if ($ambienteArg !== 'certificacion') {
    fail('Este diagnostico aislado solo permite ambiente certificacion.');
}
if ($rut !== '77724622-4') {
    fail('Este diagnostico aislado solo permite RUT emisor 77724622-4.');
}
if (! in_array($variante, ['codref_only', 'combined'], true)) {
    fail('Variante invalida. Use codref_only o combined.');
}

function conectarDb(): PDO
{
    return new PDO(
        'mysql:host=' . requerirEnv('DB_HOST') . ';port=' . (getenv('DB_PORT') ?: '3306') . ';dbname=' . requerirEnv('DB_NAME') . ';charset=utf8mb4',
        requerirEnv('DB_USER'),
        getenv('DB_PASS') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

function crearCrypto(): CertificadoCrypto
{
    $bin = @hex2bin(requerirEnv('CRYPTO_MASTER_KEY'));
    if ($bin === false || strlen($bin) !== CertificadoCrypto::KEY_LENGTH) {
        fail('CRYPTO_MASTER_KEY invalida.');
    }
    return new CertificadoCrypto($bin);
}

/**
 * @return array{folio_id:int,caf_id:int,folio_desde:int,folio_hasta:int,proximo_folio:int}
 */
function cafActivo(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT f.id AS folio_id, f.caf_id, c.folio_desde, c.folio_hasta, f.proximo_folio
         FROM dte_folio f
         JOIN dte_caf c ON c.id = f.caf_id
         WHERE f.ambiente = 'certificacion'
           AND f.tipo_dte = 39
           AND f.proximo_folio <= f.folio_hasta
         ORDER BY c.folio_desde ASC
         LIMIT 1"
    );
    $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    if (! is_array($row)) {
        fail('No hay CAF activo disponible para boleta 39 certificacion.');
    }
    return array_map('intval', $row);
}

$pdo      = conectarDb();
$crypto   = crearCrypto();
$ambiente = Ambiente::Certificacion;
$caf      = cafActivo($pdo);

$folios = new MySqlFolioRepository(
    $pdo,
    fn (string $c): string => $crypto->descifrar($c),
);
$emisor = new MySqlEmisorRepository($pdo, $crypto);

$referencia = match ($variante) {
    'codref_only' => [
        'codigo' => 'SET',
        'razon'  => 'CASO-1',
    ],
    'combined' => [
        'tipoDocumento' => 'SET',
        'codigo'        => 'SET',
        'razon'         => 'CASO-1',
    ],
};

$doc = new DocumentoTributario(
    tipoDte: TipoDte::BoletaElectronica,
    receptor: new Receptor('66666666-6', 'Consumidor Final'),
    detalles: [
        new Detalle('Cambio de aceite', 1, 19900),
        new Detalle('Alineacion y balanceo', 1, 9900),
    ],
    montosSonBrutos: true,
    referencias: [$referencia],
);

$facturador = new BoletaFacturador(
    new Client([
        'timeout' => 60,
        'cert'    => __DIR__ . '/../fullchain.pem',
        'ssl_key' => __DIR__ . '/../key.pem',
        'verify'  => true,
    ]),
    $folios,
    $emisor,
);

$cred = new Credenciales(
    rutEmisor: $rut,
    apiToken:  'no-usado-por-sii-directo',
    ambiente:  $ambiente,
    rutSender: '13520634-2',
);

echo "*** PRUEBA BOLETA SET REFERENCIAS ({$variante}) ***\n";
echo "CAF    : id {$caf['caf_id']} rango {$caf['folio_desde']}-{$caf['folio_hasta']} proximo {$caf['proximo_folio']}\n";

$resultado = $facturador->emitir($doc, $cred);

$track = $resultado->trackId ?? 'sin-track';
$xmlPath = sprintf(
    '%s/../envio_boleta_%s_folio_%s_track_%s.xml',
    __DIR__,
    $variante,
    (string) $resultado->folio,
    preg_replace('/[^0-9A-Za-z_-]/', '_', (string) $track),
);
file_put_contents($xmlPath, $resultado->xml);

echo "Folio  : " . ($resultado->folio ?? '(null)') . "\n";
echo "TrackID: " . $track . "\n";
echo "Estado : " . $resultado->estado . "\n";
echo "Raw    : " . json_encode($resultado->raw, JSON_UNESCAPED_UNICODE) . "\n";
echo "XML    : " . $xmlPath . "\n";
