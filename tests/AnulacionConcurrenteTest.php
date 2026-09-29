<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Dos pedidos de anulacion sobre el mismo documento, AL MISMO TIEMPO, dejan
 * una sola nota de credito.
 *
 * ES UNA CARRERA DE VERDAD. Cada pedido es un proceso PHP aparte
 * (tests/concurrencia/anular_hijo.php) con su propia conexion a un MySQL real;
 * una barrera los larga en el mismo instante y la "emision" tarda como el SII,
 * asi que las ventanas se solapan siempre. Un test en serie dentro de un solo
 * proceso pasaria aunque el candado no existiera.
 *
 * Y SE DEMUESTRA QUE EL ARNES PROVOCA LA CARRERA: el control corre la logica
 * de antes (SELECT y emitir) con los mismos procesos y la misma barrera, y
 * tiene que salir con DOS notas de credito. Si un dia el arnes dejara de
 * solapar los pedidos, el control fallaria y lo diria, en vez de que el test
 * del candado pase en silencio por no haber probado nada.
 *
 * Necesita el MySQL desechable (TEST_MYSQL_DSN), que deploy.sh prepara.
 */
final class AnulacionConcurrenteTest extends MysqlDesechableTestCase
{
    private const HIJO       = __DIR__ . '/concurrencia/anular_hijo.php';
    private const DEMORA_MS  = 1500;

    protected function prepararEsquema(): void
    {
        // dte_idempotencia tal cual esta en produccion (PK de la migracion
        // 001), sin la FK a dte_emisor, que aqui no aporta nada.
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE dte_idempotencia (
                ambiente       ENUM('certificacion','produccion') NOT NULL,
                rut_emisor     VARCHAR(20)  NOT NULL,
                clave          VARCHAR(255) NOT NULL,
                tipo_dte       INT          NULL,
                folio          INT UNSIGNED NULL,
                http_status    SMALLINT UNSIGNED NULL,
                respuesta_json TEXT         NULL,
                created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (rut_emisor, ambiente, clave)
            )
            SQL);
        // Las NC que llegaron a emitirse: lo que el test cuenta. El id hace de
        // folio de la NC.
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE nc_emitida (
                id           INT AUTO_INCREMENT PRIMARY KEY,
                tipo_dte_ref INT NOT NULL,
                folio_ref    INT NOT NULL,
                pid          INT NOT NULL,
                camino       VARCHAR(10) NOT NULL
            )
            SQL);
    }

    public function testCincoAnulacionesSimultaneasEmitenUnaSolaNotaDeCredito(): void
    {
        $r = $this->lanzar(array_fill(0, 5, 'con_candado'));

        self::assertSame(1, $this->ncEmitidas(), 'se emitio mas de una NC sobre el mismo documento');
        $resultados = array_count_values(array_column($r, 'resultado'));
        ksort($resultados);
        self::assertSame(['emitida' => 1, 'en_proceso' => 4], $resultados);
        foreach ($r as $x) {
            self::assertSame($x['resultado'] === 'emitida' ? 201 : 409, $x['httpStatus']);
        }
    }

    public function testControlSinCandadoLaMismaCarreraEmiteDosNotas(): void
    {
        $r = $this->lanzar(['sin_candado', 'sin_candado']);

        self::assertSame(
            2,
            $this->ncEmitidas(),
            'el arnes no provoco la carrera: sin candado deberian salir dos NC. '
            . 'Si esto falla, el test del candado no esta probando nada.'
        );
        self::assertSame(['emitida', 'emitida'], array_column($r, 'resultado'));
    }

    public function testUnReintentoDespuesDeTerminarDevuelveLaMismaNotaSinEmitirOtra(): void
    {
        $primera = $this->lanzar(['con_candado'])[0];
        $segunda = $this->lanzar(['con_candado'])[0];

        self::assertSame('emitida', $primera['resultado']);
        self::assertSame('repetida', $segunda['resultado']);
        self::assertSame(201, $segunda['httpStatus']);
        self::assertSame($primera['payload'], $segunda['payload'], 'la repeticion debe devolver la MISMA NC');
        self::assertSame(1, $this->ncEmitidas());
    }

    public function testSiLaEmisionFallaAutenticandoElCandadoSeSueltaYSePuedeReintentar(): void
    {
        $fallida = $this->lanzar(['falla_auth'])[0];
        $reintento = $this->lanzar(['con_candado'])[0];

        self::assertSame('excepcion_auth', $fallida['resultado']);
        self::assertSame('emitida', $reintento['resultado'], 'un fallo de autenticacion no emitio nada: el reintento tiene que poder emitir');
        self::assertSame(1, $this->ncEmitidas());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function caminosDelPanel(): iterable
    {
        yield 'NC por POST /api/v1/dte'      => ['panel_unitaria'];
        yield 'NC dentro de POST /api/v1/dte/lote' => ['panel_lote'];
    }

    /**
     * El caso cruzado: POST .../anular por API y la NC del panel sobre el
     * MISMO documento, al mismo tiempo. Cinco rondas, porque quien gana la
     * carrera no esta fijado y conviene ver los dos ordenes.
     */
    #[DataProvider('caminosDelPanel')]
    public function testAnularPorApiYLaNcDelPanelSimultaneasDejanUnaSolaNota(string $caminoPanel): void
    {
        $ganadores = [];
        for ($ronda = 1; $ronda <= 5; $ronda++) {
            $this->vaciar();
            $r = $this->lanzar(['con_candado', $caminoPanel]);

            self::assertSame(1, $this->ncEmitidas(), "ronda {$ronda}: salio mas de una NC sobre el mismo documento");
            $porModo = array_column($r, null, 'modo');
            $resultados = [$porModo['con_candado']['resultado'], $porModo[$caminoPanel]['resultado']];
            sort($resultados);
            self::assertSame(['emitida', 'en_proceso'], $resultados, "ronda {$ronda}: " . json_encode($r));
            // El que pierde contesta 409 y no gasta nada.
            foreach ($r as $x) {
                self::assertSame($x['resultado'] === 'emitida' ? 201 : 409, $x['httpStatus']);
            }
            $ganadores[] = $porModo['con_candado']['resultado'] === 'emitida' ? 'anular' : 'panel';
        }
        // Informativo, solo si se pide: quien gano cada ronda.
        if (getenv('ANULACION_VERBOSE')) fwrite(STDERR, "\n  [{$caminoPanel}] ganador por ronda: " . implode(', ', $ganadores) . "\n");
    }

    public function testControlCruzadoSinCandadoEnElPanelSalenDosNotas(): void
    {
        $this->lanzar(['con_candado', 'panel_sin_candado']);

        self::assertSame(
            2,
            $this->ncEmitidas(),
            'el arnes no provoco la carrera cruzada: sin el candado en el panel deberian salir dos NC'
        );
    }

    public function testDosNcDelPanelSimultaneasSobreElMismoDocumentoDejanUna(): void
    {
        $this->lanzar(['panel_unitaria', 'panel_lote']);

        self::assertSame(1, $this->ncEmitidas());
    }

    public function testDespuesDeQueElPanelTerminaElCandadoQuedaLibre(): void
    {
        // Opcion A: el panel sostiene el candado solo mientras emite. Un
        // /anular posterior lo toma y lo frena la segunda mirada (ya anulado),
        // no el candado.
        $panel  = $this->lanzar(['panel_unitaria'])[0];
        $anular = $this->lanzar(['con_candado'])[0];

        self::assertSame('emitida', $panel['resultado']);
        self::assertSame('ya_anulada', $anular['resultado']);
        self::assertSame(1, $this->ncEmitidas());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM dte_idempotencia')->fetchColumn(), 'no debe quedar ningun candado tomado');
    }

    /**
     * Lanza un proceso hijo por modo; arrancan todos en el mismo instante y se
     * espera a que terminen.
     *
     * @param list<string> $modos
     * @return list<array{resultado:string, httpStatus:int, payload:array<string,mixed>, modo:string}>
     */
    private function lanzar(array $modos): array
    {
        $env = getenv() + [
            'TEST_HIJO_DSN' => self::dsnSinBase((string) getenv('TEST_MYSQL_DSN')) . ';dbname=' . $this->base,
        ];
        // Medio segundo para que todos esten conectados y esperando en la barrera.
        $inicio = sprintf('%.6F', microtime(true) + 0.5);

        $procesos = [];
        foreach ($modos as $modo) {
            $cmd = [PHP_BINARY, self::HIJO, $modo, $inicio, (string) self::DEMORA_MS];
            $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
            self::assertIsResource($p, 'no se pudo lanzar el proceso hijo');
            $procesos[] = [$p, $pipes];
        }

        $salida = [];
        foreach ($procesos as [$p, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $rc = proc_close($p);
            self::assertSame(0, $rc, "el proceso hijo termino con error: {$err}{$out}");
            $json = json_decode(trim((string) $out), true);
            self::assertIsArray($json, "salida inesperada del hijo: {$out}{$err}");
            $salida[] = $json;
        }
        return $salida;
    }

    /** NC emitidas contra el documento 33 / 4242, por cualquier camino. */
    private function ncEmitidas(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM nc_emitida WHERE tipo_dte_ref = 33 AND folio_ref = 4242')->fetchColumn();
    }

    /** Estado limpio entre rondas: sin candados ni NC. */
    private function vaciar(): void
    {
        $this->pdo->exec('DELETE FROM dte_idempotencia');
        $this->pdo->exec('DELETE FROM nc_emitida');
    }
}
