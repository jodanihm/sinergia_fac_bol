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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Pago\ConfirmacionPago;

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
 *   1. Que un pago cobrado NUNCA quede sin registrar en silencio. Ojo: la
 *      pasarela NO reintenta el aviso, asi que "responder 503 para que vuelva a
 *      llamar" no es una recuperacion. Lo que recupera es la marca local que
 *      recoge ReconciliadorPagos.
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

    /**
     * El POST TAL COMO LO MANDA FLOW: token y nada mas.
     *
     * Antes este helper anadia un parametro 's' con el HMAC del resto, porque el
     * codigo lo exigia. Flow no lo manda: su documentacion de "Confirmacion de
     * orden" dice POST con Content-Type application/x-www-form-urlencoded y el
     * cuerpo token=<token de la transaccion>. Mientras el helper firmaba, los
     * tests pasaban con un protocolo que no era el real y el 403 solo aparecio en
     * produccion.
     *
     * El parametro $secreto se conserva y se ignora para no reescribir las
     * treinta llamadas; da igual el valor que se le pase.
     */
    private function aviso(string $secreto = '', string $token = self::TOKEN): array
    {
        return ['token' => $token];
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

    public function testElAvisoOficialDeFlowSoloTraeTokenYSeProcesa(): void
    {
        // EL TEST DE REGRESION DEL BUG. El cuerpo es exactamente lo que Flow
        // manda -- token y nada mas, sin parametro 's' -- y tiene que llegar
        // hasta la consulta de estado. Mientras se exigio la firma, esto
        // respondia 403 y el pago del DTE 241 se quedo sin registrar.
        $this->pasarela(self::CUENTA_A);
        $id = $this->orden(self::CUENTA_A);

        $r = $this->procesar(self::CUENTA_A, ['token' => self::TOKEN], [self::estado(2)]);

        self::assertSame(200, $r['codigo']);
        self::assertNotSame(403, $r['codigo'], 'la ausencia de firma no puede dar 403');
        self::assertSame('pagado', $this->link($id)['estado']);
        self::assertNotNull($this->link($id)['pagado_at']);
    }

    public function testLaAusenciaDelParametroSNoSeCastiga(): void
    {
        // Dicho por separado del test de arriba porque es la afirmacion que hay
        // que proteger: si alguien vuelve a meter una comprobacion de firma
        // entrante, este es el test que se pone rojo.
        $this->pasarela(self::CUENTA_A);
        $this->orden(self::CUENTA_A);

        $r = $this->procesar(self::CUENTA_A, ['token' => self::TOKEN], [self::estado(2)]);

        self::assertNotSame(403, $r['codigo']);
        // 'firma' a secas no sirve de aguja: "confirmado" la contiene.
        self::assertStringNotContainsString('firma invalida', $r['motivo']);
    }

    public function testUnTokenQueNoEsDeNadieNoTocaNingunaFila(): void
    {
        // Sustituye al viejo test de "firma invalida". Sin firma que validar, lo
        // que frena a un tercero es que su token no encuentre ninguna orden.
        // Mock vacio: si se llegara a consultar a Flow, el test explota.
        $this->pasarela(self::CUENTA_A);
        $id = $this->orden(self::CUENTA_A);

        $r = $this->procesar(self::CUENTA_A, ['token' => 'token-inventado-por-un-tercero']);

        self::assertSame(200, $r['codigo'], 'se acusa recibo: la pasarela no reintenta');
        self::assertSame('creado', $this->link($id)['estado'], 'la orden real no se toca');
    }

    public function testUnaCuentaSinPasarelaResponde403SinConsultarNada(): void
    {
        // Aqui el 403 se queda: no es un aviso perdido que el conciliador pueda
        // recuperar, es una url que no corresponde a ninguna empresa con cobro
        // configurado. Mock vacio: no debe hablar con Flow.
        $r = $this->procesar(4242, ['token' => self::TOKEN]);

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

        // Aviso con el token de A, dirigido a la url de B. Mock vacio: si llegara
        // a consultar a Flow, el test explota.
        //
        // ESTE TEST PESA MAS QUE ANTES. Cuando el aviso tenia que venir firmado,
        // esto quedaba frenado dos veces: por la firma y por el cuenta_id del
        // WHERE. Con la firma fuera -- Flow no la manda --, el cuenta_id es la
        // UNICA defensa: sin el, un token de A posteado a la url de B encontraria
        // la fila de A. Por eso la cuenta va en el path de la url.
        $r = $this->procesar(self::CUENTA_B, ['token' => self::TOKEN], [], self::SECRETO_B);

        // 200 y no 503: la pasarela NO reintenta -- espera un 200 en 15 s y, si
        // no, manda un correo de alerta y se olvida --, asi que un no-200 solo
        // alarmaria al comerciante sin conseguir otra llamada. Lo que importa es
        // que la orden de A NO se toca.
        self::assertSame(200, $r['codigo']);
        self::assertStringContainsString('desconocida', $r['motivo']);
        self::assertSame('creado', $this->link($idA)['estado'], 'la orden de A no se toca');
    }

    public function testUnAvisoDeUnaOrdenDesconocidaSeAcusaYLoRecogeElConciliador(): void
    {
        // Puede ser la carrera de que el aviso llegue antes de terminar de
        // guardar la orden. Se acusa recibo con 200 porque pedir un reintento no
        // serviria: la pasarela no vuelve a llamar. Si era la carrera, el
        // conciliador barre esa orden mas tarde igualmente.
        $this->pasarela(self::CUENTA_A);

        $r = $this->procesar(self::CUENTA_A, $this->aviso(self::SECRETO_A));

        self::assertSame(200, $r['codigo']);
        self::assertStringContainsString('desconocida', $r['motivo']);
    }

    // -----------------------------------------------------------------------
    //  No todo aviso es un pago
    // -----------------------------------------------------------------------

    /** @return list<array{int,string}> */
    public static function estadosQueNoSonPago(): array
    {
        return [[1, 'pendiente'], [3, 'rechazada'], [4, 'anulada']];
    }

    #[DataProvider('estadosQueNoSonPago')]
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

    public function testUnFalloTransitorioDejaLaMarcaParaQueElConciliadorLoRetome(): void
    {
        // EL FALLO QUE MAS CARO SALE, y la correccion NO es pedir un reintento:
        // la pasarela no reintenta. Lo unico que recupera este pago es NUESTRO
        // conciliador, y para eso hace falta la marca.
        $this->pasarela(self::CUENTA_A);
        $id = $this->orden(self::CUENTA_A);

        $r = $this->procesar(self::CUENTA_A, $this->aviso(self::SECRETO_A), [new Response(503, [], 'caida')]);

        self::assertSame(200, $r['codigo'], 'se acusa recibo: un no-200 no consigue nada');
        self::assertNotNull(
            $this->link($id)['confirmacion_pendiente_at'],
            'la marca es lo que hace recuperable el pago'
        );
        self::assertSame('creado', $this->link($id)['estado'], 'no se inventa un estado');
    }

    public function testUnFalloDeConexionTambienQuedaRecuperable(): void
    {
        $this->pasarela(self::CUENTA_A);
        $id = $this->orden(self::CUENTA_A);

        $r = $this->procesar(
            self::CUENTA_A,
            $this->aviso(self::SECRETO_A),
            [new ConnectException('sin ruta', new Request('GET', 'x'))]
        );

        self::assertSame(200, $r['codigo']);
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
        // Mock vacio: sin token no hay nada que consultar, asi que no debe
        // hablar con Flow. 200 porque la pasarela no reintenta.
        $this->pasarela(self::CUENTA_A);
        $id = $this->orden(self::CUENTA_A);

        self::assertSame(200, $this->procesar(self::CUENTA_A, ['algo' => 'otra cosa'])['codigo']);
        self::assertSame('creado', $this->link($id)['estado']);
    }

    #[DataProvider('tokensVacios')]
    public function testUnTokenVacioNoMarcaNada(mixed $token): void
    {
        $this->pasarela(self::CUENTA_A);
        $id = $this->orden(self::CUENTA_A);

        $r = $this->procesar(self::CUENTA_A, ['token' => $token]);

        self::assertSame(200, $r['codigo']);
        self::assertSame('creado', $this->link($id)['estado']);
    }

    /** @return list<array{mixed}> */
    public static function tokensVacios(): array
    {
        return [
            'cadena vacia'   => [''],
            'solo espacios'  => ['   '],
            'salto de linea' => ["\n"],
        ];
    }

    public function testUnPostConArraysAnidadosNoRompeNada(): void
    {
        // strval() sobre un array emite warning y produce "Array". Se filtran con
        // is_scalar para que un campo asi no acabe buscandose como si fuera un
        // token llamado "Array", ni reviente con un aviso de PHP a mitad del
        // proceso. Sigue haciendo falta aunque ya no haya firma que calcular.
        $this->pasarela(self::CUENTA_A);
        $id = $this->orden(self::CUENTA_A);

        $post = ['token' => self::TOKEN, 'x' => ['anidado' => 1]];

        $r = $this->procesar(self::CUENTA_A, $post, [self::estado(2)]);

        self::assertSame(200, $r['codigo'], 'el campo raro se ignora, no rompe');
        self::assertSame('pagado', $this->link($id)['estado']);
    }

    public function testUnTokenQueLlegaComoArrayNoSeConvierteEnLaCadenaArray(): void
    {
        // $_POST['token'][]=x produce un array. Sin el filtro, (string) sobre el
        // se convertiria en "Array" y se buscaria una orden con ese token.
        $this->pasarela(self::CUENTA_A);
        $id = $this->orden(self::CUENTA_A);

        $r = $this->procesar(self::CUENTA_A, ['token' => ['a', 'b']]);

        self::assertSame(200, $r['codigo']);
        self::assertSame('creado', $this->link($id)['estado']);
    }

    /**
     * Que quitar la firma ENTRANTE no haya tocado la SALIENTE.
     *
     * Es la mitad que de verdad autentica: el aviso solo trae un puntero, y lo
     * que decide es esta consulta, hecha con la apiKey de la cuenta y firmada con
     * su secretKey. Si alguien "simplificara" tambien esta, cualquiera podria
     * preguntar por cualquier orden. FlowPasarelaPagoTest comprueba el HMAC al
     * detalle; aqui se comprueba que el flujo de la callback pasa por ahi.
     */
    public function testLaConsultaQueDispraElAvisoSigueYendoFirmada(): void
    {
        $this->pasarela(self::CUENTA_A);
        $this->orden(self::CUENTA_A);

        $peticiones = [];
        $handler    = HandlerStack::create(new MockHandler([self::estado(2)]));
        $handler->push(\GuzzleHttp\Middleware::history($peticiones));

        ConfirmacionPago::procesar(
            $this->pdo,
            self::CUENTA_A,
            ['token' => self::TOKEN],
            static fn (string $cifrado): string => self::SECRETO_A,
            new Client(['handler' => $handler])
        );

        self::assertCount(1, $peticiones, 'la callback tiene que consultar a Flow');

        parse_str((string) $peticiones[0]['request']->getUri()->getQuery(), $q);
        self::assertArrayHasKey('s', $q, 'la peticion saliente va firmada');
        self::assertSame(self::TOKEN, $q['token']);

        $firma = $q['s'];
        unset($q['s']);
        self::assertSame(
            hash_hmac('sha256', 'apiKey' . 'apikey' . 'token' . self::TOKEN, self::SECRETO_A),
            $firma,
            'firmada con el secreto de ESTA cuenta'
        );
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
