<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use Plantiflex\FacturacionCl\Dto\Credenciales;
use Plantiflex\FacturacionCl\Dto\Detalle;
use Plantiflex\FacturacionCl\Dto\DocumentoTributario;
use Plantiflex\FacturacionCl\Dto\Receptor;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Enums\TipoAnulacion;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Providers\SiiDirectoFacturador;
use Plantiflex\Integration\Facturacion\CertificadoCrypto;
use Plantiflex\Integration\Facturacion\MySqlEmisorRepository;
use Plantiflex\Integration\Facturacion\MySqlFolioRepository;

function fail(string $msg, int $code = 1): never { fwrite(STDERR, "ERROR: {$msg}\n"); exit($code); }
function requerirEnv(string $n): string { $v = getenv($n); if ($v === false) fail("Falta variable de entorno {$n}."); return $v; }
function conectarDb(): PDO {
    $host = requerirEnv('DB_HOST'); $name = requerirEnv('DB_NAME'); $user = requerirEnv('DB_USER');
    $pass = getenv('DB_PASS'); $pass = ($pass === false) ? '' : $pass;
    $port = getenv('DB_PORT'); $port = ($port === false) ? '3306' : $port;
    try { return new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }
    catch (PDOException $e) { fail('No se pudo conectar a la base: ' . $e->getMessage()); }
}
function crearCrypto(): CertificadoCrypto {
    $hex = requerirEnv('CRYPTO_MASTER_KEY'); $bin = @hex2bin($hex);
    if ($bin === false || strlen($bin) !== CertificadoCrypto::KEY_LENGTH) fail('CRYPTO_MASTER_KEY debe ser ' . CertificadoCrypto::KEY_LENGTH . ' bytes en HEX (64 hex).');
    return new CertificadoCrypto($bin);
}

$args = array_slice($argv, 1);
if (count($args) !== 2) fail("Uso: php scripts/emitir_set_basico_lote.php <ambiente> <rut_emisor>");
[$ambienteArg, $rut] = $args;
if ($ambienteArg === 'certificacion') $ambiente = Ambiente::Certificacion;
elseif ($ambienteArg === 'produccion') $ambiente = Ambiente::Produccion;
else fail("ambiente debe ser 'certificacion' o 'produccion'.");
if (trim($rut) === '') fail('rut_emisor vacio.');

$casos = require __DIR__ . '/../set_basico_data.php';
$receptor = new Receptor('66666666-6', 'Cliente de Prueba', 'Servicios', 'Calle Falsa 123', 'Santiago');

$pdo = conectarDb(); $crypto = crearCrypto();
$folios = new MySqlFolioRepository($pdo, function (string $c) use ($crypto): string { return $crypto->descifrar($c); });
$emisor = new MySqlEmisorRepository($pdo, $crypto);
$certTls = __DIR__ . '/../fullchain.pem'; $keyTls = __DIR__ . '/../key.pem';
foreach (['fullchain.pem' => $certTls, 'key.pem' => $keyTls] as $n => $r) { if (!is_file($r) || !is_readable($r)) fail("No se encuentra {$n} en {$r}"); }
$facturador = new SiiDirectoFacturador(new Client(['timeout' => 120, 'cert' => $certTls, 'ssl_key' => $keyTls, 'verify' => true]), $folios, $emisor);
$cred = new Credenciales(rutEmisor: $rut, apiToken: 'no-usado-por-sii-directo', ambiente: $ambiente, rutSender: '13520634-2');

$fecha = new DateTimeImmutable(date('Y-m-d'));
$fechaStr = $fecha->format('Y-m-d');

// 1) Asignar 8 folios frescos en orden.
$tipoPorCaso = [
    1 => TipoDte::FacturaElectronica, 2 => TipoDte::FacturaElectronica,
    3 => TipoDte::FacturaElectronica, 4 => TipoDte::FacturaElectronica,
    5 => TipoDte::NotaCreditoElectronica, 6 => TipoDte::NotaCreditoElectronica,
    7 => TipoDte::NotaCreditoElectronica, 8 => TipoDte::NotaDebitoElectronica,
];
$folioMap = [];
foreach ($tipoPorCaso as $c => $tipo) {
    $folioMap[$c] = $folios->asignarSiguienteFolio($rut, $tipo, $ambiente);
    echo "Caso {$c} (tipo {$tipo->value}) -> folio {$folioMap[$c]}\n";
}
$porTipo = [];
foreach ($tipoPorCaso as $c => $tipo) { $porTipo[$tipo->value][] = $folioMap[$c]; }
foreach ($porTipo as $t => $fs) { if (count($fs) !== count(array_unique($fs))) fail("Folios DUPLICADOS en tipo {$t}: " . implode(',', $fs) . ". Aborta sin enviar."); }

// Referencia al SET (num. atencion 4860965): FolioRef = folio propio del doc;
// RazonRef = "CASO 4860965-<n>" (identificador completo que empareja el SII).
$setRef = fn(int $c): array => ['tipoDocumento' => 'SET', 'folio' => $folioMap[$c], 'fecha' => $fechaStr, 'razon' => "CASO 4860965-{$c}"];

// 2) Construir los 8 documentos.
$docs = [];
foreach ([1, 2, 3, 4] as $c) {
    $caso = $casos[$c];
    $docs[] = new DocumentoTributario(
        tipoDte: TipoDte::FacturaElectronica,
        receptor: $receptor,
        detalles: $caso['detalles'],
        montosSonBrutos: $caso['montosSonBrutos'] ?? false,
        folio: $folioMap[$c],
        fechaEmision: $fecha,
        referencias: [$setRef($c)],
        descuentoGlobalPct: $caso['descuentoGlobalPct'] ?? null,
    );
}
// Caso 5: NC corrige texto (ref factura caso 1)
$docs[] = new DocumentoTributario(
    tipoDte: TipoDte::NotaCreditoElectronica,
    receptor: $receptor,
    detalles: [new Detalle('Correccion de texto', 1, 0)],
    montosSonBrutos: false,
    folio: $folioMap[5],
    fechaEmision: $fecha,
    referencias: [
        $setRef(5),
        ['tipoDocumento' => '33', 'folio' => $folioMap[1], 'fecha' => $fechaStr, 'codigo' => (string) TipoAnulacion::CorrigeTexto->value, 'razon' => $casos[5]['motivo']],
    ],
    observaciones: $casos[5]['motivo'],
    totales: ['MntTotal' => 0],
);
// Caso 6: NC devolucion parcial (ref factura caso 2)
$detallesDev = array_map(fn($d) => new Detalle($d['nombre'], $d['cantidad'], $d['precioUnitario'], descuentoPorcentaje: $d['descuentoPorcentaje'] ?? 0.0), $casos[6]['items_devueltos']);
$docs[] = new DocumentoTributario(
    tipoDte: TipoDte::NotaCreditoElectronica,
    receptor: $receptor,
    detalles: $detallesDev,
    montosSonBrutos: false,
    folio: $folioMap[6],
    fechaEmision: $fecha,
    referencias: [
        $setRef(6),
        ['tipoDocumento' => '33', 'folio' => $folioMap[2], 'fecha' => $fechaStr, 'codigo' => '3', 'razon' => $casos[6]['razon']],
    ],
    observaciones: $casos[6]['razon'],
);
// Caso 7: NC anula total (ref factura caso 3) -> replica montos NETOS de caso 3
$docs[] = new DocumentoTributario(
    tipoDte: TipoDte::NotaCreditoElectronica,
    receptor: $receptor,
    detalles: $casos[3]['detalles'],
    montosSonBrutos: false,
    folio: $folioMap[7],
    fechaEmision: $fecha,
    referencias: [
        $setRef(7),
        ['tipoDocumento' => '33', 'folio' => $folioMap[3], 'fecha' => $fechaStr, 'codigo' => (string) TipoAnulacion::AnulaTotal->value, 'razon' => $casos[7]['motivo']],
    ],
    observaciones: $casos[7]['motivo'],
    totales: ['MntNeto' => 463896, 'TasaIVA' => 19, 'IVA' => 88140, 'MntExe' => 34674, 'MntTotal' => 586710],
);
// Caso 8: ND anula NC (ref NC caso 5)
$docs[] = new DocumentoTributario(
    tipoDte: TipoDte::NotaDebitoElectronica,
    receptor: $receptor,
    detalles: [new Detalle('Anula NC', 1, 0)],
    montosSonBrutos: false,
    folio: $folioMap[8],
    fechaEmision: $fecha,
    referencias: [
        $setRef(8),
        ['tipoDocumento' => '61', 'folio' => $folioMap[5], 'fecha' => $fechaStr, 'codigo' => '1', 'razon' => $casos[8]['motivo']],
    ],
    observaciones: $casos[8]['motivo'],
    totales: ['MntTotal' => 0],
);

// 3) Enviar en UN solo EnvioDTE.
echo "\nEnviando lote de " . count($docs) . " documentos en un solo EnvioDTE...\n";
try {
    $res = $facturador->emitirLote($docs, $cred);
} catch (Throwable $e) {
    fail("Fallo emitirLote: " . get_class($e) . ": " . $e->getMessage());
}

$trackId = $res['trackId'];
echo "\n=== LOTE ENVIADO ===\n";
echo "TrackID unico del Set Basico: {$trackId}\n";
echo "Fecha de envio: {$fechaStr}\n";

$estado = ['trackId' => $trackId, 'fechaEnvio' => $fechaStr, 'casos' => []];
foreach ($tipoPorCaso as $c => $tipo) { $estado['casos'][$c] = ['tipo' => (string) $tipo->value, 'folio' => $folioMap[$c]]; }
file_put_contents(__DIR__ . '/../set_basico_lote_estado.json', json_encode($estado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
file_put_contents(__DIR__ . '/../envio_set_basico_lote.xml', $res['xml']);
echo "Estado guardado en set_basico_lote_estado.json\n";
echo "XML del envio guardado en envio_set_basico_lote.xml\n";
echo "=== Fin ===\n";
