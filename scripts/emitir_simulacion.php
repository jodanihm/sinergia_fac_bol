<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use Plantiflex\FacturacionCl\Dto\Credenciales;
use Plantiflex\FacturacionCl\Dto\Detalle;
use Plantiflex\FacturacionCl\Dto\DocumentoTributario;
use Plantiflex\FacturacionCl\Dto\Receptor;
use Plantiflex\FacturacionCl\Enums\Ambiente;
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
if (count($args) !== 2) fail("Uso: php scripts/emitir_simulacion.php <ambiente> <rut_emisor>");
[$ambienteArg, $rut] = $args;
if ($ambienteArg === 'certificacion') $ambiente = Ambiente::Certificacion;
elseif ($ambienteArg === 'produccion') $ambiente = Ambiente::Produccion;
else fail("ambiente debe ser 'certificacion' o 'produccion'.");
if (trim($rut) === '') fail('rut_emisor vacio.');

$receptor = new Receptor('66666666-6', 'Cliente de Prueba', 'Servicios', 'Calle Falsa 123', 'Santiago');

$pdo = conectarDb(); $crypto = crearCrypto();
$folios = new MySqlFolioRepository($pdo, function (string $c) use ($crypto): string { return $crypto->descifrar($c); });
$emisor = new MySqlEmisorRepository($pdo, $crypto);
$certTls = __DIR__ . '/../fullchain.pem'; $keyTls = __DIR__ . '/../key.pem';
foreach (['fullchain.pem' => $certTls, 'key.pem' => $keyTls] as $n => $r) { if (!is_file($r) || !is_readable($r)) fail("No se encuentra {$n} en {$r}"); }
$facturador = new SiiDirectoFacturador(new Client(['timeout' => 120, 'cert' => $certTls, 'ssl_key' => $keyTls, 'verify' => true]), $folios, $emisor);
$cred = new Credenciales(rutEmisor: $rut, apiToken: 'no-usado-por-sii-directo', ambiente: $ambiente, rutSender: '13520634-2');

// Precios NETOS (el total con IVA es el que paga el cliente):
//   adulto 73529 -> 87500 | ninos 56723 -> 67500 | promo 2 pares 117647 -> 140000
$ADULTO = 73529;
$NINOS  = 56723;
$PROMO  = 117647;

// Fechas en junio 2026 (posteriores a la autorizacion del CAF, dentro del periodo).
$fechas = [
    1 => '2026-06-05', 2 => '2026-06-06', 3 => '2026-06-08', 4 => '2026-06-09',
    5 => '2026-06-11', 6 => '2026-06-12', 7 => '2026-06-13',
    8 => '2026-06-16', 9 => '2026-06-17', 10 => '2026-06-18',
];
$fe = fn(int $n): DateTimeImmutable => new DateTimeImmutable($fechas[$n]);

// 1) Asignar 10 folios frescos (7 facturas, 2 NC, 1 ND).
$tipoPorDoc = [
    1 => TipoDte::FacturaElectronica, 2 => TipoDte::FacturaElectronica,
    3 => TipoDte::FacturaElectronica, 4 => TipoDte::FacturaElectronica,
    5 => TipoDte::FacturaElectronica, 6 => TipoDte::FacturaElectronica,
    7 => TipoDte::FacturaElectronica,
    8 => TipoDte::NotaCreditoElectronica, 9 => TipoDte::NotaCreditoElectronica,
    10 => TipoDte::NotaDebitoElectronica,
];
$folioMap = [];
foreach ($tipoPorDoc as $n => $tipo) {
    $folioMap[$n] = $folios->asignarSiguienteFolio($rut, $tipo, $ambiente);
    echo "Doc {$n} (tipo {$tipo->value}) -> folio {$folioMap[$n]}\n";
}
$porTipo = [];
foreach ($tipoPorDoc as $n => $tipo) { $porTipo[$tipo->value][] = $folioMap[$n]; }
foreach ($porTipo as $t => $fs) { if (count($fs) !== count(array_unique($fs))) fail("Folios DUPLICADOS en tipo {$t}: " . implode(',', $fs)); }

// Referencia madre (NC/ND). codigo = CodRef: 1 = anula documento de referencia.
$ref = fn(string $tpo, int $folio, string $fecha, string $cod, string $razon): array =>
    ['tipoDocumento' => $tpo, 'folio' => $folio, 'fecha' => $fecha, 'codigo' => $cod, 'razon' => $razon];

// 2) Construir los 10 documentos (sin referencias al SET).
$docs = [];

$docs[] = new DocumentoTributario(tipoDte: TipoDte::FacturaElectronica, receptor: $receptor,
    detalles: [new Detalle('Par de plantillas ortopedicas adulto', 1, $ADULTO)],
    montosSonBrutos: false, folio: $folioMap[1], fechaEmision: $fe(1), referencias: []);

$docs[] = new DocumentoTributario(tipoDte: TipoDte::FacturaElectronica, receptor: $receptor,
    detalles: [new Detalle('Par de plantillas ortopedicas ninos', 1, $NINOS)],
    montosSonBrutos: false, folio: $folioMap[2], fechaEmision: $fe(2), referencias: []);

$docs[] = new DocumentoTributario(tipoDte: TipoDte::FacturaElectronica, receptor: $receptor,
    detalles: [new Detalle('Promo 2 pares de plantillas ortopedicas', 1, $PROMO)],
    montosSonBrutos: false, folio: $folioMap[3], fechaEmision: $fe(3), referencias: []);

$docs[] = new DocumentoTributario(tipoDte: TipoDte::FacturaElectronica, receptor: $receptor,
    detalles: [
        new Detalle('Par de plantillas ortopedicas adulto', 1, $ADULTO),
        new Detalle('Par de plantillas ortopedicas ninos', 1, $NINOS),
    ],
    montosSonBrutos: false, folio: $folioMap[4], fechaEmision: $fe(4), referencias: []);

$docs[] = new DocumentoTributario(tipoDte: TipoDte::FacturaElectronica, receptor: $receptor,
    detalles: [new Detalle('Par de plantillas ortopedicas adulto', 1, $ADULTO)],
    montosSonBrutos: false, folio: $folioMap[5], fechaEmision: $fe(5), referencias: []);

$docs[] = new DocumentoTributario(tipoDte: TipoDte::FacturaElectronica, receptor: $receptor,
    detalles: [new Detalle('Promo 2 pares de plantillas ortopedicas', 1, $PROMO)],
    montosSonBrutos: false, folio: $folioMap[6], fechaEmision: $fe(6), referencias: []);

$docs[] = new DocumentoTributario(tipoDte: TipoDte::FacturaElectronica, receptor: $receptor,
    detalles: [new Detalle('Par de plantillas ortopedicas ninos', 1, $NINOS)],
    montosSonBrutos: false, folio: $folioMap[7], fechaEmision: $fe(7), referencias: []);

// NC (61): devolucion total de la factura #1 (par adulto)
$docs[] = new DocumentoTributario(tipoDte: TipoDte::NotaCreditoElectronica, receptor: $receptor,
    detalles: [new Detalle('Par de plantillas ortopedicas adulto', 1, $ADULTO)],
    montosSonBrutos: false, folio: $folioMap[8], fechaEmision: $fe(8),
    referencias: [$ref('33', $folioMap[1], $fechas[1], '1', 'ANULA FACTURA POR DEVOLUCION TOTAL')]);

// NC (61): devolucion total de la factura #3 (promo 2 pares)
$docs[] = new DocumentoTributario(tipoDte: TipoDte::NotaCreditoElectronica, receptor: $receptor,
    detalles: [new Detalle('Promo 2 pares de plantillas ortopedicas', 1, $PROMO)],
    montosSonBrutos: false, folio: $folioMap[9], fechaEmision: $fe(9),
    referencias: [$ref('33', $folioMap[3], $fechas[3], '1', 'ANULA FACTURA POR DEVOLUCION TOTAL')]);

// ND (56): anula la NC #9
$docs[] = new DocumentoTributario(tipoDte: TipoDte::NotaDebitoElectronica, receptor: $receptor,
    detalles: [new Detalle('Promo 2 pares de plantillas ortopedicas', 1, $PROMO)],
    montosSonBrutos: false, folio: $folioMap[10], fechaEmision: $fe(10),
    referencias: [$ref('61', $folioMap[9], $fechas[9], '1', 'ANULA NOTA DE CREDITO ELECTRONICA')]);

// 3) Enviar en UN solo EnvioDTE.
echo "\nEnviando lote de simulacion de " . count($docs) . " documentos...\n";
try {
    $res = $facturador->emitirLote($docs, $cred);
} catch (Throwable $e) {
    fail("Fallo emitirLote: " . get_class($e) . ": " . $e->getMessage());
}

$trackId = $res['trackId'];
echo "\n=== LOTE DE SIMULACION ENVIADO ===\n";
echo "TrackID: {$trackId}\n";

$estado = ['trackId' => $trackId, 'docs' => []];
foreach ($tipoPorDoc as $n => $tipo) { $estado['docs'][$n] = ['tipo' => (string) $tipo->value, 'folio' => $folioMap[$n], 'fecha' => $fechas[$n]]; }
file_put_contents(__DIR__ . '/../simulacion_estado.json', json_encode($estado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
file_put_contents(__DIR__ . '/../envio_simulacion.xml', $res['xml']);
echo "Estado guardado en simulacion_estado.json\n";
echo "XML guardado en envio_simulacion.xml\n";
echo "=== Fin ===\n";
