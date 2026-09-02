<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PDO;
use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Pago\ResolutorLinkPago;

/**
 * Tests de la escalera de decisiones del link de pago, contra SQLite en memoria.
 *
 * LO QUE SE PROTEGE AQUI es que ninguna factura se quede sin enviar por un
 * problema de cobro, y que ninguna orden se cree dos veces. Las dos cosas se
 * deciden en esta clase, y las dos son caras de equivocar: la primera deja a un
 * cliente sin su documento tributario, la segunda le cobra dos veces.
 *
 * MISMO ARNES QUE MySqlIdempotenciaRepositoryTest: SQLite en memoria con el
 * esquema recortado a lo que la consulta toca. Se puede porque el resolutor no
 * usa una sola funcion de MySQL -- las fechas se calculan en PHP y el choque de
 * clave unica se captura por SQLSTATE 23000, que SQLite tambien devuelve.
 */
final class ResolutorLinkPagoTest extends TestCase
{
    private const CUENTA = 7;
    private const URL_CONFIRMACION = 'https://facturacion.sinergiaia.cl/pagos/flow/confirmacion';

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE dte_emitido (
                id BIGINT PRIMARY KEY, rut_emisor TEXT, ambiente TEXT, tipo_dte INT,
                folio INT, total INT, receptor_rut TEXT
            );
        SQL);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE dte_envio_correo (
                id BIGINT PRIMARY KEY, dte_emitido_id BIGINT, cuenta_id BIGINT,
                destinatario TEXT, estado TEXT DEFAULT 'pendiente', intentos INT DEFAULT 0
            );
        SQL);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE dte_pago_link (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                dte_emitido_id BIGINT NOT NULL UNIQUE,
                cuenta_id BIGINT NOT NULL, proveedor TEXT NOT NULL,
                referencia TEXT NOT NULL UNIQUE, orden_externa TEXT, url TEXT,
                monto INT NOT NULL DEFAULT 0, estado TEXT NOT NULL DEFAULT 'pendiente',
                intentos INT NOT NULL DEFAULT 0, ultimo_error TEXT,
                reintentar_despues_at TEXT, creado_at TEXT, pagado_at TEXT
            );
        SQL);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE pago_pasarela_cuenta (
                id INTEGER PRIMARY KEY AUTOINCREMENT, cuenta_id BIGINT NOT NULL UNIQUE,
                proveedor TEXT NOT NULL DEFAULT 'flow', habilitado INT NOT NULL DEFAULT 0,
                credencial_publica TEXT, credencial_cifrada TEXT, url_retorno TEXT
            );
        SQL);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE cliente (
                id INTEGER PRIMARY KEY AUTOINCREMENT, cuenta_id BIGINT NOT NULL,
                rut_cliente TEXT NOT NULL, pago_link INT NOT NULL DEFAULT 1
            );
        SQL);
    }

    // -----------------------------------------------------------------------
    //  Sembrado
    // -----------------------------------------------------------------------

    private function documento(int $tipoDte = 33, int $total = 49990, string $ambiente = 'produccion', string $rut = '78159082-7'): int
    {
        $this->pdo->prepare(
            'INSERT INTO dte_emitido (id, rut_emisor, ambiente, tipo_dte, folio, total, receptor_rut) '
            . 'VALUES (100, :re, :amb, :t, 745, :tot, :rr)'
        )->execute([':re' => '78225195-3', ':amb' => $ambiente, ':t' => $tipoDte, ':tot' => $total, ':rr' => $rut]);

        $this->pdo->prepare(
            'INSERT INTO dte_envio_correo (id, dte_emitido_id, cuenta_id, destinatario) VALUES (1, 100, :c, :d)'
        )->execute([':c' => self::CUENTA, ':d' => 'cliente@ejemplo.cl']);

        return 1;
    }

    private function pasarelaActiva(int $habilitado = 1): void
    {
        $this->pdo->prepare(
            'INSERT INTO pago_pasarela_cuenta (cuenta_id, proveedor, habilitado, credencial_publica, credencial_cifrada) '
            . "VALUES (:c, 'flow', :h, 'apikey-publica', 'cifrado')"
        )->execute([':c' => self::CUENTA, ':h' => $habilitado]);
    }

    private function resolutor(array $respuestas = []): ResolutorLinkPago
    {
        $http = $respuestas === []
            ? null
            : new Client(['handler' => HandlerStack::create(new MockHandler($respuestas))]);

        return new ResolutorLinkPago(
            $this->pdo,
            static fn (string $c): string => 'secreto-descifrado',
            self::URL_CONFIRMACION,
            $http
        );
    }

    private static function respuestaFlowOk(): Response
    {
        return new Response(200, [], (string) json_encode([
            'url'       => 'https://www.flow.cl/app/web/pay.php',
            'token'     => 'TOK123',
            'flowOrder' => 999,
        ]));
    }

    /** @return array<string,mixed>|null */
    private function link(): ?array
    {
        $f = $this->pdo->query('SELECT * FROM dte_pago_link WHERE dte_emitido_id = 100')->fetch(PDO::FETCH_ASSOC);

        return $f === false ? null : $f;
    }

    // -----------------------------------------------------------------------
    //  Camino feliz
    // -----------------------------------------------------------------------

    public function testCreaLaOrdenYGuardaElLink(): void
    {
        $envio = $this->documento();
        $this->pasarelaActiva();

        $r = $this->resolutor([self::respuestaFlowOk()])->resolver($envio);

        self::assertSame('listo', $r['verdicto']);
        $link = $this->link();
        self::assertSame('creado', $link['estado']);
        self::assertSame('https://www.flow.cl/app/web/pay.php?token=TOK123', $link['url']);
        self::assertSame('999', (string) $link['orden_externa']);
        self::assertSame('49990', (string) $link['monto'], 'el monto queda como foto');
        self::assertSame('SIN-7-33-745', $link['referencia']);
    }

    // -----------------------------------------------------------------------
    //  Las guardas de "no aplica"
    // -----------------------------------------------------------------------

    public function testSinPasarelaConfiguradaNoAplicaYNoSeLlamaANadie(): void
    {
        $envio = $this->documento();

        // Sin respuestas en el mock: si intentara llamar, el test reventaria.
        $r = $this->resolutor()->resolver($envio);

        self::assertSame('no_aplica', $r['verdicto']);
        self::assertNull($this->link(), 'no se reserva fila si no corresponde');
    }

    public function testConLaPasarelaApagadaNoAplica(): void
    {
        $envio = $this->documento();
        $this->pasarelaActiva(habilitado: 0);

        self::assertSame('no_aplica', $this->resolutor()->resolver($envio)['verdicto']);
    }

    public function testUnaNotaDeCreditoNuncaLlevaLink(): void
    {
        // La 61 DEVUELVE dinero.
        $envio = $this->documento(tipoDte: 61);
        $this->pasarelaActiva();

        self::assertSame('no_aplica', $this->resolutor()->resolver($envio)['verdicto']);
        self::assertNull($this->link());
    }

    public function testEnCertificacionNoSeCobraANadie(): void
    {
        $envio = $this->documento(ambiente: 'certificacion');
        $this->pasarelaActiva();

        self::assertSame('no_aplica', $this->resolutor()->resolver($envio)['verdicto']);
    }

    public function testUnDocumentoSinMontoNoSeCobra(): void
    {
        $envio = $this->documento(total: 0);
        $this->pasarelaActiva();

        self::assertSame('no_aplica', $this->resolutor()->resolver($envio)['verdicto']);
    }

    public function testUnClienteExcluidoNoRecibeLink(): void
    {
        $envio = $this->documento();
        $this->pasarelaActiva();
        $this->pdo->prepare('INSERT INTO cliente (cuenta_id, rut_cliente, pago_link) VALUES (:c, :r, 0)')
            ->execute([':c' => self::CUENTA, ':r' => '78159082-7']);

        self::assertSame('no_aplica', $this->resolutor()->resolver($envio)['verdicto']);
        self::assertNull($this->link());
    }

    public function testLaExclusionFuncionaAunqueElDocumentoGuardeElRutConPuntos(): void
    {
        // Los documentos anteriores al arreglo de RUT canonico tienen el receptor
        // con puntos. Sin normalizar, a este cliente se le mandaria link pese a
        // estar excluido, y en silencio.
        $envio = $this->documento(rut: '78.159.082-7');
        $this->pasarelaActiva();
        $this->pdo->prepare('INSERT INTO cliente (cuenta_id, rut_cliente, pago_link) VALUES (:c, :r, 0)')
            ->execute([':c' => self::CUENTA, ':r' => '78159082-7']);

        self::assertSame('no_aplica', $this->resolutor()->resolver($envio)['verdicto']);
    }

    public function testUnClienteSinFichaEnElMaestroSiRecibeLink(): void
    {
        // El maestro es opcional: exigir ficha dejaria fuera a todo receptor
        // tecleado a mano en el formulario de emision.
        $envio = $this->documento();
        $this->pasarelaActiva();

        self::assertSame('listo', $this->resolutor([self::respuestaFlowOk()])->resolver($envio)['verdicto']);
    }

    public function testUnClienteConLaFichaIncluidaRecibeLink(): void
    {
        $envio = $this->documento();
        $this->pasarelaActiva();
        $this->pdo->prepare('INSERT INTO cliente (cuenta_id, rut_cliente, pago_link) VALUES (:c, :r, 1)')
            ->execute([':c' => self::CUENTA, ':r' => '78159082-7']);

        self::assertSame('listo', $this->resolutor([self::respuestaFlowOk()])->resolver($envio)['verdicto']);
    }

    // -----------------------------------------------------------------------
    //  Idempotencia: nunca dos ordenes para el mismo documento
    // -----------------------------------------------------------------------

    public function testUnaSegundaPasadaNoVuelveALlamarALaPasarela(): void
    {
        $envio = $this->documento();
        $this->pasarelaActiva();
        $this->resolutor([self::respuestaFlowOk()])->resolver($envio);

        // Mock sin respuestas: cualquier llamada reventaria el test.
        $r = $this->resolutor()->resolver($envio);

        self::assertSame('listo', $r['verdicto']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM dte_pago_link')->fetchColumn());
    }

    public function testUnReintentoTrasUnFalloReusaLaMismaReferencia(): void
    {
        $envio = $this->documento();
        $this->pasarelaActiva();

        $this->resolutor([new Response(500, [], 'caida')])->resolver($envio);
        $referenciaTrasFallo = $this->link()['referencia'];

        // Se borra el backoff para que el segundo intento entre.
        $this->pdo->exec('UPDATE dte_pago_link SET reintentar_despues_at = NULL');
        $this->resolutor([self::respuestaFlowOk()])->resolver($envio);

        self::assertSame($referenciaTrasFallo, $this->link()['referencia']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM dte_pago_link')->fetchColumn());
        self::assertSame(2, (int) $this->link()['intentos']);
    }

    // -----------------------------------------------------------------------
    //  Fallos: esperar, y sobre todo NO tocar la cola de correos
    // -----------------------------------------------------------------------

    public function testUnFalloTransitorioDiceEsperarYNoTocaLaFilaDelCorreo(): void
    {
        $envio = $this->documento();
        $this->pasarelaActiva();

        $r = $this->resolutor([new Response(503, [], 'mantenimiento')])->resolver($envio);

        self::assertSame('esperar', $r['verdicto']);

        // ESTO ES LO QUE IMPIDE PERDER LA FACTURA. Si el fallo gastara intentos
        // del correo, tres caidas lo dejarian en 'error' con intentos=3 y el
        // runner no lo miraria nunca mas.
        $envioFila = $this->pdo->query('SELECT estado, intentos FROM dte_envio_correo WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('pendiente', $envioFila['estado']);
        self::assertSame(0, (int) $envioFila['intentos']);
    }

    public function testUnFalloTransitorioDejaBackoffCorto(): void
    {
        $envio = $this->documento();
        $this->pasarelaActiva();
        $this->resolutor([new Response(503, [], 'x')])->resolver($envio);

        $espera = strtotime((string) $this->link()['reintentar_despues_at']) - time();

        // Primer intento: el tramo 0 del backoff, o sea sin espera apreciable.
        self::assertLessThanOrEqual(60, $espera);
        self::assertSame('pendiente', $this->link()['estado'], 'sigue siendo candidata');
    }

    public function testUnFalloPermanenteSeAparcaUnDiaEntero(): void
    {
        $envio = $this->documento();
        $this->pasarelaActiva();

        // 401: credenciales rechazadas. Reintentar cada 5 min no las arregla.
        $r = $this->resolutor([new Response(401, [], 'Invalid api key')])->resolver($envio);

        self::assertSame('esperar', $r['verdicto']);
        $espera = strtotime((string) $this->link()['reintentar_despues_at']) - time();
        self::assertGreaterThan(23 * 3600, $espera);
        self::assertStringContainsString('Invalid api key', (string) $this->link()['ultimo_error']);
    }

    public function testMientrasDuraElBackoffNiSiquieraSeLlamaALaPasarela(): void
    {
        $envio = $this->documento();
        $this->pasarelaActiva();
        $this->resolutor([new Response(500, [], 'x')])->resolver($envio);
        $this->pdo->exec("UPDATE dte_pago_link SET reintentar_despues_at = '2999-01-01 00:00:00'");

        // Mock vacio: si llamara, reventaria.
        self::assertSame('esperar', $this->resolutor()->resolver($envio)['verdicto']);
    }

    public function testUnaConfiguracionRotaEsperaYNoDegradaAMandarSinLink(): void
    {
        // La empresa SI quiere cobrar, pero su proveedor no existe. Devolver
        // 'no_aplica' aqui mandaria la factura sin cobro y en silencio.
        $envio = $this->documento();
        $this->pdo->prepare(
            'INSERT INTO pago_pasarela_cuenta (cuenta_id, proveedor, habilitado, credencial_publica, credencial_cifrada) '
            . "VALUES (:c, 'pasarela-que-no-existe', 1, 'k', 'c')"
        )->execute([':c' => self::CUENTA]);

        self::assertSame('esperar', $this->resolutor()->resolver($envio)['verdicto']);
    }

    public function testCredencialesAMediasEsperaYNoDegrada(): void
    {
        $envio = $this->documento();
        $this->pdo->prepare(
            'INSERT INTO pago_pasarela_cuenta (cuenta_id, proveedor, habilitado, credencial_publica, credencial_cifrada) '
            . "VALUES (:c, 'flow', 1, '', '')"
        )->execute([':c' => self::CUENTA]);

        $r = $this->resolutor()->resolver($envio);

        self::assertSame('esperar', $r['verdicto']);
    }

    // -----------------------------------------------------------------------
    //  La valvula de escape
    // -----------------------------------------------------------------------

    public function testUnCorreoSoltadoAManoDejaDeEsperar(): void
    {
        $envio = $this->documento();
        $this->pasarelaActiva();
        $this->resolutor([new Response(500, [], 'x')])->resolver($envio);

        // Es lo que hace el boton "enviar sin link" del panel.
        $this->pdo->exec("UPDATE dte_pago_link SET estado = 'omitido'");

        self::assertSame('no_aplica', $this->resolutor()->resolver($envio)['verdicto']);
    }

    public function testAQuienYaPagoNoSeLeVuelveAOfrecer(): void
    {
        $envio = $this->documento();
        $this->pasarelaActiva();
        $this->resolutor([self::respuestaFlowOk()])->resolver($envio);
        $this->pdo->exec("UPDATE dte_pago_link SET estado = 'pagado', url = NULL");

        self::assertSame('no_aplica', $this->resolutor()->resolver($envio)['verdicto']);
    }

    public function testLaReferenciaEsDeterministaYNoDependeDelReloj(): void
    {
        self::assertSame('SIN-7-33-745', ResolutorLinkPago::referencia(7, 33, 745));
        self::assertSame(
            ResolutorLinkPago::referencia(7, 33, 745),
            ResolutorLinkPago::referencia(7, 33, 745)
        );
    }
}
