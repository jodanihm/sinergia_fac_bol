<?php
declare(strict_types=1);

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

function fail(string $msg, int $code = 1): never { fwrite(STDERR, "ERROR: {$msg}\n"); exit($code); }
function requerirEnv(string $nombre): string { $v = getenv($nombre); if ($v === false) fail("Falta la variable de entorno {$nombre}."); return $v; }

function conectarDb(): PDO {
    $host = requerirEnv('DB_HOST'); $name = requerirEnv('DB_NAME'); $user = requerirEnv('DB_USER');
    $pass = getenv('DB_PASS'); $pass = ($pass === false) ? '' : $pass;
    $port = getenv('DB_PORT'); $port = ($port === false) ? '3306' : $port;
    try {
        return new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException $e) { fail('No se pudo conectar a la base: ' . $e->getMessage()); }
}

function crearCrypto(): CertificadoCrypto {
    $hex = requerirEnv('CRYPTO_MASTER_KEY');
    $bin = @hex2bin($hex);
    if ($bin === false || strlen($bin) !== CertificadoCrypto::KEY_LENGTH) fail('CRYPTO_MASTER_KEY debe ser ' . CertificadoCrypto::KEY_LENGTH . ' bytes en HEX (64 hex).');
    return new CertificadoCrypto($bin);
}

// ---------------------------------------------------------------------------
$args = array_slice($argv, 1);
$casoEspecifico = null;
$estadoArchivo = null;
$posicionales = [];
foreach ($args as $arg) {
    if (str_starts_with($arg, '--caso=')) {
        $casoEspecifico = (int) substr($arg, 7);
    } elseif (str_starts_with($arg, '--estado=')) {
        $estadoArchivo = substr($arg, 10);
    } else {
        $posicionales[] = $arg;
    }
}
if (count($posicionales) !== 2) fail("Uso: php scripts/emitir_set_basico.php <ambiente> <rut_emisor> [--caso=N] [--estado=archivo]");
[$ambienteArg, $rut] = $posicionales;

if ($ambienteArg === 'certificacion') $ambiente = Ambiente::Certificacion;
elseif ($ambienteArg === 'produccion') $ambiente = Ambiente::Produccion;
else fail("ambiente debe ser 'certificacion' o 'produccion'.");

if (trim($rut) === '') fail('rut_emisor no puede ser vacio.');

// Cargar datos de los casos
$casos = require __DIR__ . '/../set_basico_data.php';

// Cargar o inicializar estado de folios
$estadoArchivo = $estadoArchivo ?? __DIR__ . '/../set_basico_estado.json';
if (file_exists($estadoArchivo)) {
    $foliosAsignados = json_decode(file_get_contents($estadoArchivo), true);
    if (!is_array($foliosAsignados)) $foliosAsignados = [];
} else {
    $foliosAsignados = [];
}

// Inicializar servicios
$pdo    = conectarDb();
$crypto = crearCrypto();
$folios = new MySqlFolioRepository($pdo, function (string $c) use ($crypto): string { return $crypto->descifrar($c); });
$emisor = new MySqlEmisorRepository($pdo, $crypto);

$certTls = __DIR__ . '/../fullchain.pem';
$keyTls  = __DIR__ . '/../key.pem';
foreach (['fullchain.pem' => $certTls, 'key.pem' => $keyTls] as $nombre => $ruta) {
    if (! is_file($ruta) || ! is_readable($ruta)) fail("No se encuentra {$nombre} para el TLS mutuo en: {$ruta}");
}

$facturador = new SiiDirectoFacturador(new Client(['timeout' => 60, 'cert' => $certTls, 'ssl_key' => $keyTls, 'verify' => true]), $folios, $emisor);
$cred = new Credenciales(rutEmisor: $rut, apiToken: 'no-usado-por-sii-directo', ambiente: $ambiente, rutSender: '13520634-2');

function guardarEstado(): void {
    global $foliosAsignados, $estadoArchivo;
    file_put_contents($estadoArchivo, json_encode($foliosAsignados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function emitirYCotejar(string $etiqueta, callable $fn): array {
    echo "*** {$etiqueta} ***\n";
    try {
        $res = $fn();
        echo "OK - {$etiqueta}\n  Folio: {$res->folio}\n  Estado: {$res->estado}\n  TrackID: {$res->trackId}\n\n";
        return ['folio' => $res->folio, 'trackId' => $res->trackId];
    } catch (SiiAutenticacionException $e) {
        fail(sprintf("Fallo autenticacion: %s - %s", $e->estadoSii, $e->glosaSii));
    } catch (EnvioRechazadoException $e) {
        fail(sprintf("Rechazo SII: status=%s track=%s msg=%s", $e->status, $e->trackId ?? '?', $e->getMessage()));
    } catch (Throwable $e) {
        fail(sprintf("Excepcion: %s: %s", get_class($e), $e->getMessage()));
    }
}

// ---------------------------------------------------------------------------
$inicio = $casoEspecifico ?? 1;
$fin    = $casoEspecifico ?? 8;
for ($c = $inicio; $c <= $fin; $c++) {
    if (!isset($casos[$c])) { echo "Caso {$c} no definido, saltando.\n"; continue; }
    $caso = $casos[$c];
    $receptor = new Receptor('66666666-6', 'Cliente de Prueba', 'Servicios', 'Calle Falsa 123', 'Santiago');

    switch ($caso['tipo']) {
        case 'factura':
            $doc = new DocumentoTributario(
                tipoDte: TipoDte::FacturaElectronica,
                receptor: $receptor,
                detalles: $caso['detalles'],
                montosSonBrutos: $caso['montosSonBrutos'] ?? false,
                descuentoGlobalPct: $caso['descuentoGlobalPct'] ?? null,
            );
            $res = emitirYCotejar("CASO {$c} - Factura", fn() => $facturador->emitir($doc, $cred));
            $foliosAsignados[$c] = $res + ['tipo' => '33'];
            guardarEstado();
            break;

        case 'nc_corrige_texto':
            $refFolio = $foliosAsignados[$caso['referencia_caso']]['folio'] ?? null;
            if (!$refFolio) fail("No se encontró folio del caso {$caso['referencia_caso']} para referencia.");
            $original = new DocumentoOriginal(
                tipoDte: TipoDte::FacturaElectronica,
                folio: $refFolio,
                fechaEmision: new DateTimeImmutable('2026-06-19'),
                receptor: $receptor,
                detalles: [new Detalle('temp', 1, 0)],
                montoNeto: 1000, iva: 190, montoTotal: 1190,
            );
            $res = emitirYCotejar("CASO {$c} - NC Corrige Texto (ref. factura folio {$refFolio})",
                fn() => $facturador->anular($original, $caso['motivo'], TipoAnulacion::CorrigeTexto, $cred));
            $foliosAsignados[$c] = $res + ['tipo' => '61'];
            guardarEstado();
            break;

        case 'nc_devolucion_parcial':
            $refFolio = $foliosAsignados[$caso['referencia_caso']]['folio'] ?? null;
            if (!$refFolio) fail("No se encontró folio del caso {$caso['referencia_caso']} para referencia.");
            $detallesDev = array_map(fn($d) => new Detalle($d['nombre'], $d['cantidad'], $d['precioUnitario'], descuentoPorcentaje: $d['descuentoPorcentaje'] ?? 0.0), $caso['items_devueltos']);
            $nc = new DocumentoTributario(
                tipoDte: TipoDte::NotaCreditoElectronica,
                receptor: $receptor,
                detalles: $detallesDev,
                montosSonBrutos: true,
                referencias: [[
                    'tipoDocumento' => '33',
                    'folio'         => $refFolio,
                    'fecha'         => '2026-06-19',
                    'codigo'        => '3',
                    'razon'         => $caso['razon'],
                ]],
                observaciones: $caso['razon'],
            );
            $res = emitirYCotejar("CASO {$c} - NC Devolución Parcial (ref. factura folio {$refFolio})",
                fn() => $facturador->emitir($nc, $cred));
            $foliosAsignados[$c] = $res + ['tipo' => '61'];
            guardarEstado();
            break;

        case 'nc_anula_total':
            $refFolio = $foliosAsignados[$caso['referencia_caso']]['folio'] ?? null;
            if (!$refFolio) fail("No se encontró folio del caso {$caso['referencia_caso']} para referencia.");
            $original = new DocumentoOriginal(
                tipoDte: TipoDte::FacturaElectronica,
                folio: $refFolio,
                fechaEmision: new DateTimeImmutable('2026-06-19'),
                receptor: $receptor,
                detalles: $casos[$caso['referencia_caso']]['detalles'],
                montoNeto: 389829, iva: 74067, montoTotal: 498570,
                montosSonBrutos: true,
            );
            $res = emitirYCotejar("CASO {$c} - NC Anula Total (ref. factura folio {$refFolio})",
                fn() => $facturador->anular($original, $caso['motivo'], TipoAnulacion::AnulaTotal, $cred));
            $foliosAsignados[$c] = $res + ['tipo' => '61'];
            guardarEstado();
            break;

        case 'nd_anula_nc':
            $refFolio = $foliosAsignados[$caso['referencia_caso']]['folio'] ?? null;
            if (!$refFolio) fail("No se encontró folio del caso {$caso['referencia_caso']} para referencia.");
            $nd = new DocumentoTributario(
                tipoDte: TipoDte::NotaDebitoElectronica,
                receptor: $receptor,
                detalles: [new Detalle('Anula NC', 1, 1, descuentoPorcentaje: 100.0)],
                montosSonBrutos: false,
                referencias: [[
                    'tipoDocumento' => '61',
                    'folio'         => $refFolio,
                    'fecha'         => '2026-06-19',
                    'codigo'        => '1',
                    'razon'         => $caso['motivo'],
                ]],
                observaciones: $caso['motivo'],
                totales: ['MntTotal' => 0],
            );
            $originalNC = new DocumentoOriginal(
                tipoDte: TipoDte::NotaCreditoElectronica,
                folio: $refFolio,
                fechaEmision: new DateTimeImmutable('2026-06-19'),
                receptor: $receptor,
                detalles: [new Detalle('temp', 1, 0)],
                montoNeto: 1, iva: 0, montoTotal: 1,
            );
            $res = emitirYCotejar("CASO {$c} - ND Anula NC (ref. NC folio {$refFolio})",
                fn() => $facturador->emitir($nd, $cred));
            $foliosAsignados[$c] = $res + ['tipo' => '56'];
            guardarEstado();
            break;

        default:
            echo "Caso {$c} tipo desconocido '{$caso['tipo']}', omitiendo.\n";
    }
}
echo "=== Fin del Set Básico ===\n";
