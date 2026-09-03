<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PDO;
use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Pago\ConfirmacionPago;
use Plantiflex\FacturacionCl\Providers\FlowPasarelaPago;

/**
 * Tests del aviso de pago de la pasarela.
 *
 * ESTA CLASE NO TENIA NI UN TEST hasta esta entrega, y es la superficie de mayor
 * consecuencia del modulo: decide si una factura se da por pagada. Vivia dentro
 * de panel/public/index.php, un front controller de 19.000 lineas que arranca
 * sesion y router al incluirlo, asi que no habia forma de ejercerla. Se saco a
 * src/Pago/ConfirmacionPago para poder escribir esto.
 *
 * Lo que se fija aqui, en orden de lo que costaria equivocarse:
 *
 *   1. Que un pago cobrado NUNCA quede sin registrar en silencio.
 *   2. Que un aviso NO se confunda con un pago -- la pasarela avisa igual cuando
 *      el pago se rechaza.
 *   3. Que el monto cuadre exacto antes de marcar nada.
 *   4. Que un tenant no pueda tocar la orden de otro.
 */
final class ConfirmacionPagoTest extends TestCase
{
    private const CUENTA_A = 7;
    private const CUENTA_B = 9;
    private const SECRETO_A = 'secreto-de-la-cuenta-a';
    private const SECRETO_B = 'secreto-de-la-cuenta-b';
    private const TOKEN = 'TOK-ABC-123';
    private const MONTO = 49990;

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE pago_pasarela_cuenta (
                id INTEGER PRIMARY KEY AUTOINCREMENT, cuenta_id BIGINT NOT NULL UNIQUE,
                proveedor TEXT NOT NULL DEFAULT 'flow',
                ambiente TEXT NOT NULL DEFAULT 'sandbox',
                habilitado INT NOT NULL DEFAULT 0,
                credencial_publica TEXT, credencial_cifrada TEXT, url_retorno TEXT
            );
        SQL);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE dte_pago_link (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                dte_emitido_id BIGINT NOT NULL UNIQUE,
                cuenta_id BIGINT NOT NULL, proveedor TEXT NOT NULL,
                referencia TEXT NOT NULL UNIQUE, orden_externa TEXT, url TEXT,
                monto INT NOT NULL DEFAULT 0, estado TEXT NOT NULL DEFAULT 'pendiente',
                intentos INT NOT NULL DEFAULT 0, reclamado_at TEXT, ultimo_error TEXT,
                reintentar_despues_at TEXT, confirmacion_pendiente_at TEXT,
                creado_at TEXT, pagado_at TEXT
            );
        SQL);
    }

    // -----------------------------------------------------------------------
    //  Sembrado
    // -----------------------------------------------------------------------

    private function pasarela(int $cuentaId, int $habilitado = 1): void
    {
        $this->pdo->prepare(
            'INSERT INTO pago_pasarela_cuenta '
            . '(cuenta_id, proveedor, ambiente, habilitado, credencial_publica, credencial_cifrada) '
            . "VALUES (:c, 'flow', 'sandbox', :h, 'apikey', :s)"
        )->execute([
            ':c' => $cuentaId,
            ':h' => $habilitado,
            ':s' => $cuentaId === self::CUENTA_A ? self::SECRETO_A : self::SECRETO_B,
        ]);
    }

    private function orden(int $cuentaId, string $token = self::TOKEN, int $monto = self::MONTO, int $dteId = 100): int
    {
        $this->pdo->prepare(
            'INSERT INTO dte_pago_link '
            . '(dte_emitido_id, cuenta_id, proveedor, referencia, orden_externa, url, monto, estado) '
            . "VALUES (:d, :c, 'flow', :r, :t, 'https://pay/x', :m, 'creado')"
        )->execute([
            ':d' => $dteId, ':c' => $cuentaId,
            ':r' => sprintf('SIN-%d-33-745', $cuentaId), ':t' => $token, ':m' => $monto,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** El POST tal como lo manda Flow: token + firma sobre el resto. */
    private function aviso(string $secreto, string $token = self::TOKEN): array
    {
        $params      = ['token' => $token];
        $params['s'] = FlowPasarelaPago::firmar($params, $secreto);

        return $params;
    }

    private function procesar(int $cuentaId, array $post, array $respuestas = [], ?string $secreto = null): array
    {
        $http = $respuestas === []
            ? null
            : new Client(['handler' => HandlerStack::create(new MockHandler($respuestas))]);

        return ConfirmacionPago::procesar(
            $this->pdo,
            $cuentaId,
            $post,
            // El descifrador real usa CertificadoCrypto; aqui se sustituye por el
            // valor en claro, que es lo unico que esta clase necesita saber.
            static fn (string $cifrado): string => $secreto ?? $cifrado,
            $http
        );
    }

    private static function estado(int $status, string $referencia = 'SIN-7-33-745', mixed $monto = self::MONTO): Response
    {
        return new Response(200, [], (string) json_encode([
            'status'        => $status,
            'commerceOrder' => $referencia,
            'amount'        => $monto,
        ]));
    }

    private function link(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM dte_pago_link WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // -----------------------------------------------------------------------
    //  Autenticidad
    // -----------------------------------------------------------------------

    public function testUnaFirmaValidaConPagoConfirmadoMarcaPagada(): void
    {
        $this->pasarela(self::CUENTA_A);
        $id = $this->orden(self::CUENTA_A);

        $r = $this->procesar(self::CUENTA_A, $this->aviso(self::SECRETO_A), [self::estado(2)]);

        self::assertSame(200, $r['codigo']);
        self::assertSame('pagado', $this->link($id)['estado']);
        self::assertNotNull($this->link($id)['pagado_at']);
    }

    public function testUnaFirmaInvalidaNoTocaNada(): void
    {
        $this->pasarela(self::CUENTA_A);
        $id = $this->orden(self::CUENTA_A);

        // Firmado con el secreto equivocado. Mock vacio: no debe consultar nada.
        $r = $this->procesar(self::CUENTA_A, $this->aviso('secreto-que-no-es'));

        self::assertSame(403, $r['codigo']);
        self::assertSame('creado', $this->link($id)['estado']);
    }

    public function testSinFirmaNoPasa(): void
    {
        $this->pasarela(self::CUENTA_A);
        $this->orden(self::CUENTA_A);

        self::assertSame(403, $this->procesar(self::CUENTA_A, ['token' => self::TOKEN])['codigo']);
    }

    public function testUnaCuentaInexistenteResponde403IgualQueUnaFirmaMala(): void
    {
        // La misma respuesta a proposito: distinguirlas diria si esa cuenta existe.
        $r = $this->procesar(4242, $this->aviso(self::SECRETO_A));

        self::assertSame(403, $r['codigo']);
    }

    // -----------------------------------------------------------------------
    //  El interruptor NO gobierna la confirmacion
    // -----------------------------------------------------------------------

    public function testUnaCuentaDeshabilitadaSIGUE_confirmandoSusLinksYaCreados(): void
    {
        // LA CORRECCION QUE MAS DINERO SALVA. Antes el SELECT llevaba
        // "AND habilitado = 1": una empresa que desactivara el cobro -- algo que
        // la propia pantalla recomienda para desatascar la cola -- empezaba a
        // responder 403, y los pagos de los links YA EMITIDOS no se registraban
        // jamas. Apagar el grifo no puede tirar el agua que ya salio.
        $this->pasarela(self::CUENTA_A, habilitado: 0);
        $id = $this->orden(self::CUENTA_A);

        $r = $this->procesar(self::CUENTA_A, $this->aviso(self::SECRETO_A), [self::estado(2)]);

        self::assertSame(200, $r['codigo']);
        self::assertSame('pagado', $this->link($id)['estado']);
    }

    // -----------------------------------------------------------------------
    //  Aislamiento entre tenants
    // -----------------------------------------------------------------------

    public function testLaCuentaBNoPuedeConfirmarLaOrdenDeLaCuentaA(): void
    {
        $this->pasarela(self::CUENTA_A);
        $this->pasarela(self::CUENTA_B);
        $idA = $this->orden(self::CUENTA_A, dteId: 100);

        // Aviso firmado correctamente por B, con el token de A, dirigido a B.
        $r = $this->procesar(self::CUENTA_B, $this->aviso(self::SECRETO_B), [], self::SECRETO_B);

        self::assertSame(503, $r['codigo'], 'para B esa orden no existe');
        self::assertSame('creado', $this->link($idA)['estado'], 'la orden de A no se toca');
    }

    public function testUnAvisoDeUnaOrdenDesconocidaPideReintento(): void
    {
        // Puede ser la carrera de que el aviso llegue antes de terminar de
        // guardar la orden. 503 para que se resuelva sola.
        $this->pasarela(self::CUENTA_A);

        $r = $this->procesar(self::CUENTA_A, $this->aviso(self::SECRETO_A));

        self::assertSame(503, $r['codigo']);
    }

    // -----------------------------------------------------------------------
    //  No todo aviso es un pago
    // -----------------------------------------------------------------------

    /** @return list<array{int,string}> */
    public static function estadosQueNoSonPago(): array
    {
        return [[1, 'pendiente'], [3, 'rechazada'], [4, 'anulada']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('estadosQueNoSonPago')]
    public function testUnPagoNoConfirmadoNoMarcaNada(int $status, string $caso): void
    {
        $this->pasarela(self::CUENTA_A);
        $id = $this->orden(self::CUENTA_A);

        $r = $this->procesar(self::CUENTA_A, $this->aviso(self::SECRETO_A), [self::estado($status)]);

        self::assertSame(200, $r['codigo'], "un aviso de {$caso} se acusa recibo");
        self::assertSame('creado', $this->link($id)['estado'], "una {$caso} no puede quedar como pagada");
        self::assertNull($this->link($id)['pagado_at']);
    }

    // -----------------------------------------------------------------------
    //  El monto tiene que cuadrar exacto
    // -----------------------------------------------------------------------

    public function testUnMontoDistintoNoSeMarcaPagadoYQuedaParaRevisar(): void
    {
        $this->pasarela(self::CUENTA_A);
        $id = $this->orden(self::CUENTA_A, monto: 49990);

        $r = $this->procesar(
            self::CUENTA_A,
            $this->aviso(self::SECRETO_A),
            [self::estado(2, monto: 10000)]
        );

        self::assertSame(200, $r['codigo']);
        $link = $this->link($id);
        self::assertSame('error', $link['estado'], 'ni pagada (seria mentir) ni creada (ocultaria el incidente)');
        self::assertNotNull($link['confirmacion_pendiente_at'], 'aparece en la consulta de pendientes');
        self::assertStringContainsString('10000', (string) $link['ultimo_error']);
        self::assertStringContainsString('49990', (string) $link['ultimo_error']);
    }

    public function testUnMontoAusenteTampocoSeDaPorBueno(): void
    {
        $this->pasarela(self::CUENTA_A);
        $id = $this->orden(self::CUENTA_A);

        $r = $this->procesar(self::CUENTA_A, $this->aviso(self::SECRETO_A), [self::estado(2, monto: null)]);

        self::assertSame(200, $r['codigo']);
        self::assertSame('error', $this->link($id)['estado']);
    }

    public function testUnMontoQueNoEsNumeroTampocoSeDaPorBueno(): void
    {
        $this->pasarela(self::CUENTA_A);
        $id = $this->orden(self::CUENTA_A);

        $this->procesar(self::CUENTA_A, $this->aviso(self::SECRETO_A), [self::estado(2, monto: 'mucho')]);

        self::assertSame('error', $this->link($id)['estado']);
    }

    // -----------------------------------------------------------------------
    //  Cuando no se puede consultar el estado
    // -----------------------------------------------------------------------

    public function testUnFalloTransitorioPideReintentoYDejaLaMarcaLocal(): void
    {
        // EL FALLO QUE MAS CARO SALIA. Antes se respondia 200: la pasarela daba
        // el aviso por entregado, no lo repetia, y el pago quedaba cobrado de
        // verdad y sin registrar, sin nada que volviera a mirarlo.
        $this->pasarela(self::CUENTA_A);
        $id = $this->orden(self::CUENTA_A);

        $r = $this->procesar(self::CUENTA_A, $this->aviso(self::SECRETO_A), [new Response(503, [], 'caida')]);

        self::assertSame(503, $r['codigo'], 'la pasarela tiene que reintentar');
        self::assertNotNull(
            $this->link($id)['confirmacion_pendiente_at'],
            'y ademas queda la marca local por si acaba rindiendose'
        );
        self::assertSame('creado', $this->link($id)['estado'], 'no se inventa un estado');
    }

    public function testUnFalloDeConexionTambienPideReintento(): void
    {
        $this->pasarela(self::CUENTA_A);
        $id = $this->orden(self::CUENTA_A);

        $r = $this->procesar(
            self::CUENTA_A,
            $this->aviso(self::SECRETO_A),
            [new ConnectException('sin ruta', new Request('GET', 'x'))]
        );

        self::assertSame(503, $r['codigo']);
        self::assertNotNull($this->link($id)['confirmacion_pendiente_at']);
    }

    public function testUnFalloPermanenteAcusaReciboYDejaLaMarca(): void
    {
        // Reintentar no lo va a arreglar: se acusa recibo para que la pasarela no
        // insista, y queda anotado para mirarlo a mano.
        $this->pasarela(self::CUENTA_A);
        $id = $this->orden(self::CUENTA_A);

        $r = $this->procesar(self::CUENTA_A, $this->aviso(self::SECRETO_A), [new Response(401, [], 'nope')]);

        self::assertSame(200, $r['codigo']);
        self::assertNotNull($this->link($id)['confirmacion_pendiente_at']);
        self::assertSame('creado', $this->link($id)['estado']);
    }

    public function testLoQueQuedaSinResolverSeEncuentraConUnSelect(): void
    {
        // La forma local y recuperable: no es un sistema de conciliacion, es que
        // la pregunta "que avisos quedaron sin mirar" se conteste con SQL.
        $this->pasarela(self::CUENTA_A);
        $this->orden(self::CUENTA_A);
        $this->procesar(self::CUENTA_A, $this->aviso(self::SECRETO_A), [new Response(500, [], 'x')]);

        $pendientes = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM dte_pago_link WHERE confirmacion_pendiente_at IS NOT NULL AND estado <> 'pagado'"
        )->fetchColumn();

        self::assertSame(1, $pendientes);
    }

    // -----------------------------------------------------------------------
    //  Repeticion
    // -----------------------------------------------------------------------

    public function testUnAvisoRepetidoNoCambiaNadaLaSegundaVez(): void
    {
        $this->pasarela(self::CUENTA_A);
        $id = $this->orden(self::CUENTA_A);

        $this->procesar(self::CUENTA_A, $this->aviso(self::SECRETO_A), [self::estado(2)]);
        $primero = $this->link($id)['pagado_at'];

        $r = $this->procesar(self::CUENTA_A, $this->aviso(self::SECRETO_A), [self::estado(2)]);

        self::assertSame(200, $r['codigo']);
        self::assertSame('pagado', $this->link($id)['estado']);
        self::assertSame($primero, $this->link($id)['pagado_at'], 'la hora del pago no se reescribe');
    }

    public function testUnAvisoSinTokenSeAcusaYNoHaceNada(): void
    {
        $this->pasarela(self::CUENTA_A);
        $id = $this->orden(self::CUENTA_A);

        $params      = ['algo' => 'otra cosa'];
        $params['s'] = FlowPasarelaPago::firmar($params, self::SECRETO_A);

        self::assertSame(200, $this->procesar(self::CUENTA_A, $params)['codigo']);
        self::assertSame('creado', $this->link($id)['estado']);
    }

    public function testUnPostConArraysAnidadosNoRompeLaVerificacion(): void
    {
        // strval() sobre un array emite warning y produce "Array". Se filtran con
        // is_scalar para que la firma se calcule solo sobre lo que se puede
        // firmar, en vez de reventar con un aviso de PHP a mitad de la
        // verificacion.
        //
        // QUE UN CAMPO ASI SE IGNORE NO ABRE NADA: quien lo cuele sigue
        // necesitando una firma valida sobre los escalares, y para eso hace falta
        // el secreto. El caso de abajo la trae buena, asi que pasa; el de mas
        // arriba (firma mala) demuestra que sin el secreto no se entra.
        $this->pasarela(self::CUENTA_A);
        $id = $this->orden(self::CUENTA_A);

        $post      = ['token' => self::TOKEN];
        $post['s'] = FlowPasarelaPago::firmar($post, self::SECRETO_A);
        $post['x'] = ['anidado' => 1];

        $r = $this->procesar(self::CUENTA_A, $post, [self::estado(2)]);

        self::assertSame(200, $r['codigo'], 'el campo raro se ignora, no rompe');
        self::assertSame('pagado', $this->link($id)['estado']);
    }

    public function testColarUnCampoAnidadoNoPermiteSaltarseLaFirma(): void
    {
        // El contrapunto del anterior: mismo campo raro, pero sin secreto valido.
        $this->pasarela(self::CUENTA_A);
        $id = $this->orden(self::CUENTA_A);

        $post = [
            'token' => self::TOKEN,
            's'     => FlowPasarelaPago::firmar(['token' => self::TOKEN], 'secreto-inventado'),
            'x'     => ['anidado' => 1],
        ];

        self::assertSame(403, $this->procesar(self::CUENTA_A, $post)['codigo']);
        self::assertSame('creado', $this->link($id)['estado']);
    }

    // -----------------------------------------------------------------------
    //  Nada de esto filtra secretos
    // -----------------------------------------------------------------------

    public function testLaRespuestaNuncaLlevaElSecretoNiElMotivo(): void
    {
        $this->pasarela(self::CUENTA_A);
        $this->orden(self::CUENTA_A);

        foreach ([
            $this->procesar(self::CUENTA_A, $this->aviso('malo')),
            $this->procesar(self::CUENTA_A, $this->aviso(self::SECRETO_A), [self::estado(2)]),
        ] as $r) {
            self::assertStringNotContainsString(self::SECRETO_A, $r['cuerpo']);
            self::assertStringNotContainsString(self::SECRETO_A, $r['motivo']);
            // El cuerpo que ve la pasarela es una palabra sin informacion.
            self::assertContains($r['cuerpo'], ['ok', 'no', 'reintenta', 'error']);
        }
    }
}
