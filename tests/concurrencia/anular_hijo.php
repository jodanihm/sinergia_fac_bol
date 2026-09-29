<?php

declare(strict_types=1);

/**
 * Proceso hijo de AnulacionConcurrenteTest: UN pedido que anula el documento
 * 33 / 4242.
 *
 * Es un proceso aparte, con su propia conexion a MySQL, porque la carrera que
 * se prueba es entre pedidos HTTP distintos: dos php-fpm workers no comparten
 * nada salvo la base. Correrlo en el mismo proceso del test serializaria las
 * llamadas y el test pasaria aunque el candado no existiera.
 *
 * Uso: php anular_hijo.php <modo> <inicioEpochMicro> <demoraEmisionMs>
 *   con_candado       -> POST .../anular: lo que hace anularDte() (AnulacionUnica::ejecutar)
 *   sin_candado       -> POST .../anular como era antes: SELECT y, si no hay NC, emitir
 *   falla_auth        -> POST .../anular, y la emision falla autenticando
 *   panel_unitaria    -> NC CodRef=1 por POST /api/v1/dte: lo que hace emitirDte()
 *   panel_lote        -> la misma NC dentro de un lote por POST /api/v1/dte/lote:
 *                        lo que hace emitirDteLote()
 *   panel_sin_candado -> la NC por POST /api/v1/dte como era antes: emite sin mirar nada
 * Conexion: TEST_HIJO_DSN, TEST_MYSQL_USER, TEST_MYSQL_PASS.
 * Imprime una linea JSON con el resultado.
 */

use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Exceptions\SiiAutenticacionException;
use Plantiflex\Integration\Facturacion\AnulacionEnCursoException;
use Plantiflex\Integration\Facturacion\AnulacionUnica;
use Plantiflex\Integration\Facturacion\MySqlIdempotenciaRepository;

require __DIR__ . '/../../vendor/autoload.php';

const RUT      = '78454034-0';
const TIPO_REF = 33;
const FOLIO    = 4242;

[, $modo, $inicio, $demoraMs] = $argv;

$pdo = new PDO((string) getenv('TEST_HIJO_DSN'), (string) getenv('TEST_MYSQL_USER'), (string) getenv('TEST_MYSQL_PASS'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$anulacion = new AnulacionUnica(new MySqlIdempotenciaRepository($pdo));

$yaAnulado = static function () use ($pdo): bool {
    $st = $pdo->prepare('SELECT 1 FROM nc_emitida WHERE tipo_dte_ref = ? AND folio_ref = ?');
    $st->execute([TIPO_REF, FOLIO]);
    return $st->fetchColumn() !== false;
};
// La "emision": tarda lo que tardaria el SII y deja registrada cada NC que
// salio, con el documento al que apunta (como persistirEmitido()).
$emitirNc = static function (int $tipoRef, int $folioRef, string $camino) use ($pdo, $demoraMs, $modo): int {
    usleep((int) $demoraMs * 1000);
    if ($modo === 'falla_auth') {
        throw new SiiAutenticacionException('-11', 'token rechazado (simulado)');
    }
    $pdo->prepare('INSERT INTO nc_emitida (tipo_dte_ref, folio_ref, pid, camino) VALUES (?, ?, ?, ?)')
        ->execute([$tipoRef, $folioRef, getmypid(), $camino]);
    return (int) $pdo->lastInsertId();
};

// Las referencias de la NC tal como las arma el formulario del panel y llegan
// al motor: la que anula el documento, y una segunda que no anula nada
// (CodRef=2, corrige texto) para que se vea que solo cuenta la CodRef=1.
$refsDeLaNc = [
    ['tipoDocumento' => '33', 'folio' => FOLIO, 'fecha' => '2026-09-01', 'codigo' => 1, 'razon' => 'Anula factura'],
    ['tipoDocumento' => '33', 'folio' => 999, 'fecha' => '2026-09-01', 'codigo' => 2, 'razon' => 'Corrige texto'],
];

// Barrera: todos los hijos arrancan en el mismo instante, no en el orden en
// que el padre los lanzo.
while (microtime(true) < (float) $inicio) {
    usleep(1000);
}

try {
    switch ($modo) {
        case 'sin_candado':
            if ($yaAnulado()) {
                $r = ['resultado' => 'ya_anulada', 'httpStatus' => 409, 'payload' => []];
                break;
            }
            $f = $emitirNc(TIPO_REF, FOLIO, 'anular');
            $r = ['resultado' => 'emitida', 'httpStatus' => 201, 'payload' => ['ncFolio' => $f]];
            break;

        case 'con_candado':
        case 'falla_auth':
            $r = $anulacion->ejecutar(RUT, Ambiente::Certificacion, TIPO_REF, FOLIO, $yaAnulado, static function () use ($emitirNc): array {
                $f = $emitirNc(TIPO_REF, FOLIO, 'anular');
                return [$f, ['ncFolio' => $f, 'folioRef' => FOLIO, 'tipoDteRef' => TIPO_REF]];
            });
            break;

        case 'panel_unitaria':
            // emitirDte(): documentosQueAnula() sobre las referencias validadas
            // y la emision envuelta en emitirSinPisarse().
            $f = $anulacion->emitirSinPisarse(
                RUT,
                Ambiente::Certificacion,
                AnulacionUnica::documentosQueAnula(61, $refsDeLaNc),
                static fn (): int => $emitirNc(TIPO_REF, FOLIO, 'panel'),
            );
            $r = ['resultado' => 'emitida', 'httpStatus' => 201, 'payload' => ['ncFolio' => $f]];
            break;

        case 'panel_lote':
            // emitirDteLote(): una factura, la NC que anula 33/4242 y otra NC
            // que anula la factura DEL MISMO LOTE (refIndiceLote), que no toma
            // candado. Se juntan los documentos anulados de todo el sobre y se
            // toman antes de asignar folios, igual que el handler.
            $validados = [
                ['tipoDte' => 33, 'referencias' => []],
                ['tipoDte' => 61, 'referencias' => $refsDeLaNc],
                ['tipoDte' => 61, 'referencias' => [['refIndiceLote' => 0, 'codigo' => 1, 'razon' => 'Anula la del lote']]],
            ];
            $anulados = [];
            foreach ($validados as $v) {
                foreach (AnulacionUnica::documentosQueAnula($v['tipoDte'], $v['referencias']) as $d) {
                    $anulados[] = $d;
                }
            }
            $f = $anulacion->emitirSinPisarse(
                RUT,
                Ambiente::Certificacion,
                $anulados,
                static fn (): int => $emitirNc(TIPO_REF, FOLIO, 'lote'),
            );
            $r = ['resultado' => 'emitida', 'httpStatus' => 201, 'payload' => ['ncFolio' => $f]];
            break;

        case 'panel_sin_candado':
            $f = $emitirNc(TIPO_REF, FOLIO, 'panel');
            $r = ['resultado' => 'emitida', 'httpStatus' => 201, 'payload' => ['ncFolio' => $f]];
            break;

        default:
            throw new RuntimeException("modo desconocido: {$modo}");
    }
} catch (SiiAutenticacionException $e) {
    $r = ['resultado' => 'excepcion_auth', 'httpStatus' => 502, 'payload' => []];
} catch (AnulacionEnCursoException $e) {
    // Lo que emitirDte()/emitirDteLote() responden con 409 anulacion_en_curso.
    $r = ['resultado' => 'en_proceso', 'httpStatus' => 409, 'payload' => ['tipoDte' => $e->tipoDte, 'folio' => $e->folio]];
}

$r['modo'] = $modo;
echo json_encode($r), "\n";
