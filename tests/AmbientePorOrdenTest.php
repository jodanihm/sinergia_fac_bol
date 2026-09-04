<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Correo\PreparadorEnvio;
use Plantiflex\FacturacionCl\Pago\ConfirmacionPago;
use Plantiflex\FacturacionCl\Pago\ReconciliadorPagos;
use Plantiflex\FacturacionCl\Pago\ResolutorLinkPago;

/**
 * Que el ambiente de una orden sea el suyo y no el que la empresa tenga hoy.
 *
 * EL FALLO QUE CIERRA. pago_pasarela_cuenta tenia UNIQUE(cuenta_id) con UNA
 * pareja de credenciales, y la pantalla guardaba con ON DUPLICATE KEY UPDATE:
 * pasar de sandbox a produccion SOBRESCRIBIA las llaves de sandbox. Como
 * dte_pago_link tampoco guardaba el ambiente, las tres rutas que consultan el
 * estado leian el ambiente y las llaves VIGENTES. En cuanto una empresa pasaba a
 * produccion, toda orden suya que siguiera viva se consultaba contra el Flow real
 * con un token de sandbox: fallo permanente, en bucle, y el pago sin registrar.
 *
 * Y habia un camino peor y mas probable: la apiKey se sobrescribia SIEMPRE y el
 * secreto solo si se escribia. El camino natural al pasar a produccion -- pegar
 * la apiKey nueva y dejar el secreto en blanco, que es lo que la pantalla
 * recomienda al editar -- producia apiKey de produccion con secretKey de
 * sandbox. Todas las firmas invalidas, sin un mensaje que lo explicara.
 *
 *
 * LAS TRES RESPONSABILIDADES QUE ESTOS TESTS SEPARAN
 * -----------------------------------------------------------------------------
 *   pago_pasarela_cuenta      LA ELECCION ACTIVA, una por empresa.
 *   pago_pasarela_credencial  EL LLAVERO, una fila por (proveedor, ambiente).
 *   dte_pago_link             LA HISTORIA de cada orden, inmutable.
 *
 * La regla que recorre todo el archivo: para CREAR se mira la eleccion activa;
 * para RESOLVER una orden que ya existe se mira su historia. Nunca al reves.
 */
final class AmbientePorOrdenTest extends TestCase
{
    private const CUENTA   = 7;
    private const CUENTA_B = 9;
    private const MONTO    = 49990;
    private const URL      = 'https://facturacion.ejemplo.cl';

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE dte_emitido (
                id BIGINT PRIMARY KEY, rut_emisor TEXT, ambiente TEXT, tipo_dte INT,
                folio INT, total INT, receptor_rut TEXT, forma_pago INT,
                fecha_vencimiento TEXT, xml TEXT
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
                ambiente TEXT NOT NULL,
                referencia TEXT NOT NULL UNIQUE, orden_externa TEXT, url TEXT,
                monto INT NOT NULL DEFAULT 0, estado TEXT NOT NULL DEFAULT 'pendiente',
                intentos INT NOT NULL DEFAULT 0, reclamado_at TEXT, ultimo_error TEXT,
                reintentar_despues_at TEXT, confirmacion_pendiente_at TEXT,
                conciliacion_ultimo_intento_at TEXT, conciliacion_intentos INT NOT NULL DEFAULT 0,
                estado_pasarela TEXT, creado_at TEXT, pagado_at TEXT
            );
        SQL);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE pago_pasarela_cuenta (
                id INTEGER PRIMARY KEY AUTOINCREMENT, cuenta_id BIGINT NOT NULL UNIQUE,
                proveedor TEXT NOT NULL DEFAULT 'flow',
                ambiente_activo TEXT NOT NULL DEFAULT 'sandbox',
                habilitado INT NOT NULL DEFAULT 0, url_retorno TEXT
            );
        SQL);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE pago_pasarela_credencial (
                id INTEGER PRIMARY KEY AUTOINCREMENT, cuenta_id BIGINT NOT NULL,
                proveedor TEXT NOT NULL DEFAULT 'flow',
                ambiente TEXT NOT NULL DEFAULT 'sandbox',
                credencial_publica TEXT, credencial_cifrada TEXT,
                UNIQUE (cuenta_id, proveedor, ambiente)
            );
        SQL);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE cliente (
                id INTEGER PRIMARY KEY AUTOINCREMENT, cuenta_id BIGINT NOT NULL,
                rut_cliente TEXT NOT NULL, pago_link INT NOT NULL DEFAULT 1
            );
        SQL);
    }

    // ------------------------------------------------------------------
    //  Sembrado
    // ------------------------------------------------------------------

    /** La eleccion activa. NO trae llaves: son otra tabla. */
    private function eleccion(string $ambienteActivo, int $habilitado = 1, int $cuentaId = self::CUENTA): void
    {
        $this->pdo->prepare(
            'INSERT INTO pago_pasarela_cuenta (cuenta_id, proveedor, ambiente_activo, habilitado) '
            . "VALUES (:c, 'flow', :a, :h) "
            . 'ON CONFLICT(cuenta_id) DO UPDATE SET ambiente_activo = :a2, habilitado = :h2'
        )->execute([':c' => $cuentaId, ':a' => $ambienteActivo, ':h' => $habilitado,
                    ':a2' => $ambienteActivo, ':h2' => $habilitado]);
    }

    /** Una pareja de llaves para UN ambiente. */
    private function llaves(string $ambiente, int $cuentaId = self::CUENTA, string $secreto = null): void
    {
        $this->pdo->prepare(
            'INSERT INTO pago_pasarela_credencial (cuenta_id, proveedor, ambiente, credencial_publica, credencial_cifrada) '
            . "VALUES (:c, 'flow', :a, :k, :s)"
        )->execute([
            ':c' => $cuentaId,
            ':a' => $ambiente,
            ':k' => 'apikey-' . $ambiente,
            ':s' => $secreto ?? ('secreto-' . $ambiente),
        ]);
    }

    private function documento(int $dteId = 100, int $envioId = 1, int $cuentaId = self::CUENTA): int
    {
        $this->pdo->prepare(
            'INSERT INTO dte_emitido (id, rut_emisor, ambiente, tipo_dte, folio, total, receptor_rut) '
            . "VALUES (:id, '78225195-3', 'produccion', 33, :f, :t, '78159082-7')"
        )->execute([':id' => $dteId, ':f' => $dteId, ':t' => self::MONTO]);

        $this->pdo->prepare(
            'INSERT INTO dte_envio_correo (id, dte_emitido_id, cuenta_id, destinatario) '
            . "VALUES (:e, :d, :c, 'cliente@ejemplo.cl')"
        )->execute([':e' => $envioId, ':d' => $dteId, ':c' => $cuentaId]);

        return $envioId;
    }

    /** Una orden ya creada, con su ambiente congelado. */
    private function orden(string $ambiente, string $token = 'TOK', int $dteId = 100, int $cuentaId = self::CUENTA): int
    {
        $this->pdo->prepare(
            'INSERT INTO dte_pago_link (dte_emitido_id, cuenta_id, proveedor, ambiente, referencia, orden_externa, url, monto, estado) '
            . "VALUES (:d, :c, 'flow', :a, :r, :t, 'https://x/pay', :m, 'creado')"
        )->execute([
            ':d' => $dteId, ':c' => $cuentaId, ':a' => $ambiente,
            ':r' => 'SIN-' . $cuentaId . '-33-' . $dteId, ':t' => $token, ':m' => self::MONTO,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function resolutor(array $respuestas = []): ResolutorLinkPago
    {
        return new ResolutorLinkPago(
            $this->pdo,
            static fn (string $c): string => 'descifrado:' . $c,
            static fn (): string => self::URL,
            $respuestas === [] ? null : new Client(['handler' => HandlerStack::create(new MockHandler($respuestas))])
        );
    }

    private static function respuestaCrear(string $token = 'TOKNUEVO'): Response
    {
        return new Response(200, [], (string) json_encode([
            'token' => $token, 'url' => 'https://x.flow.cl/app/web/pay.php', 'flowOrder' => 1,
        ]));
    }

    private static function respuestaPagada(string $referencia): Response
    {
        return new Response(200, [], (string) json_encode([
            'status' => 2, 'commerceOrder' => $referencia, 'amount' => self::MONTO,
        ]));
    }

    private function link(int $id): array
    {
        $st = $this->pdo->prepare('SELECT * FROM dte_pago_link WHERE id = :id');
        $st->execute([':id' => $id]);

        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    // ==================================================================
    //  1-2. Los dos ambientes conviven; solo uno esta activo
    // ==================================================================

    public function testSandboxYProduccionPuedenTenerLlavesALaVez(): void
    {
        $this->eleccion('produccion');
        $this->llaves('sandbox');
        $this->llaves('produccion');

        self::assertSame(2, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM pago_pasarela_credencial WHERE cuenta_id = ' . self::CUENTA
        )->fetchColumn());
    }

    public function testSoloPuedeHaberUNAEleccionActivaPorCuenta(): void
    {
        // La garantia es del esquema, no de una regla que alguien tenga que
        // recordar: UNIQUE(cuenta_id) hace imposible escribir dos ambientes
        // activos. Ese es el motivo de separar la eleccion de las llaves.
        $this->eleccion('sandbox');

        $this->expectExceptionMessageMatches('/UNIQUE|constraint/i');
        $this->pdo->prepare(
            'INSERT INTO pago_pasarela_cuenta (cuenta_id, proveedor, ambiente_activo, habilitado) '
            . "VALUES (:c, 'flow', 'produccion', 1)"
        )->execute([':c' => self::CUENTA]);
    }

    // ==================================================================
    //  3-5. La orden congela su ambiente, y despues nadie se lo cambia
    // ==================================================================

    #[DataProvider('losDosAmbientes')]
    public function testUnaOrdenNuevaCongelaElAmbienteActivo(string $ambiente): void
    {
        $envio = $this->documento();
        $this->eleccion($ambiente);
        $this->llaves($ambiente);

        $r = $this->resolutor([self::respuestaCrear()])->resolver($envio);

        self::assertSame('listo', $r['verdicto'], $r['motivo']);
        self::assertSame(
            $ambiente,
            $this->pdo->query('SELECT ambiente FROM dte_pago_link')->fetchColumn()
        );
    }

    /** @return list<array{string}> */
    public static function losDosAmbientes(): array
    {
        return [['sandbox'], ['produccion']];
    }

    public function testCambiarElAmbienteActivoNoTocaUnaOrdenExistente(): void
    {
        $this->eleccion('sandbox');
        $this->llaves('sandbox');
        $id = $this->orden('sandbox');

        // La empresa pasa a cobrar de verdad.
        $this->eleccion('produccion');
        $this->llaves('produccion');

        self::assertSame('sandbox', $this->link($id)['ambiente'], 'la historia de la orden no se reescribe');
    }

    public function testCambiarElAmbienteActivoNoBorraLasLlavesDelOtro(): void
    {
        $this->eleccion('sandbox');
        $this->llaves('sandbox');
        $this->llaves('produccion');

        $this->eleccion('produccion');

        self::assertSame(2, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM pago_pasarela_credencial'
        )->fetchColumn(), 'las dos parejas siguen ahi');
    }

    // ==================================================================
    //  Una orden a medio crear NO cambia de ambiente con la cuenta
    // ==================================================================

    /**
     * Deja una orden reclamada y sin completar, como la deja registrarFallo().
     *
     * Es el estado en que queda una fila cuando payment/create falla: 'pendiente',
     * con su ambiente ya congelado, sin token ni url, y lista para reintentarse
     * en cuanto venza el backoff.
     */
    private function ordenAMedias(string $ambiente, int $dteId = 100, int $cuentaId = self::CUENTA): int
    {
        $this->pdo->prepare(
            'INSERT INTO dte_pago_link (dte_emitido_id, cuenta_id, proveedor, ambiente, referencia, monto, estado, intentos) '
            . "VALUES (:d, :c, 'flow', :a, :r, :m, 'pendiente', 1)"
        )->execute([
            ':d' => $dteId, ':c' => $cuentaId, ':a' => $ambiente,
            ':r' => 'SIN-' . $cuentaId . '-33-' . $dteId, ':m' => self::MONTO,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function testUnaOrdenPendienteDeSandboxNoSeCreaEnProduccionAlCambiarElActivo(): void
    {
        // EL AGUJERO QUE ESTE TEST CIERRA, con dinero real dentro:
        //   1. cuenta en sandbox, se reclama la orden -> fila ambiente sandbox
        //   2. payment/create falla -> queda 'pendiente' con backoff
        //   3. la empresa pasa a produccion y carga sus llaves reales
        //   4. vence el backoff -> el reintento leia el ambiente ACTIVO y creaba
        //      la orden en PRODUCCION, dejando la fila marcada sandbox
        // Resultado: correo con aviso PRUEBA sobre un cobro real, y callback
        // consultando sandbox con un token de produccion.
        $envio = $this->documento();
        $id    = $this->ordenAMedias('sandbox');

        $this->eleccion('produccion');
        $this->llaves('sandbox');
        $this->llaves('produccion');

        $capturadas = [];
        $handler    = HandlerStack::create(new MockHandler([self::respuestaCrear()]));
        $handler->push(\GuzzleHttp\Middleware::history($capturadas));

        $r = (new ResolutorLinkPago(
            $this->pdo,
            static fn (string $c): string => 'descifrado:' . $c,
            static fn (): string => self::URL,
            new Client(['handler' => $handler])
        ))->resolver($envio);

        self::assertSame('listo', $r['verdicto'], $r['motivo']);
        self::assertSame('sandbox', $this->link($id)['ambiente'], 'la historia no se reescribe');

        $uri = (string) $capturadas[0]['request']->getUri();
        self::assertStringContainsString('sandbox.flow.cl', $uri, 'la orden se creo en SANDBOX');
        self::assertStringNotContainsString('www.flow.cl', $uri);

        parse_str((string) $capturadas[0]['request']->getBody(), $cuerpo);
        self::assertSame('apikey-sandbox', $cuerpo['apiKey'], 'y con las llaves de SANDBOX');
    }

    public function testUnaOrdenPendienteDeProduccionNoSeCreaEnSandboxAlCambiarElActivo(): void
    {
        // El reverso, y no es simetrico en consecuencias pero si en principio:
        // una orden de produccion a medias que se completara en sandbox daria un
        // link que no cobra, sobre una factura que si hay que cobrar.
        $envio = $this->documento();
        $id    = $this->ordenAMedias('produccion');

        $this->eleccion('sandbox');
        $this->llaves('sandbox');
        $this->llaves('produccion');

        $capturadas = [];
        $handler    = HandlerStack::create(new MockHandler([self::respuestaCrear()]));
        $handler->push(\GuzzleHttp\Middleware::history($capturadas));

        $r = (new ResolutorLinkPago(
            $this->pdo,
            static fn (string $c): string => 'descifrado:' . $c,
            static fn (): string => self::URL,
            new Client(['handler' => $handler])
        ))->resolver($envio);

        self::assertSame('listo', $r['verdicto'], $r['motivo']);
        self::assertSame('produccion', $this->link($id)['ambiente']);

        $uri = (string) $capturadas[0]['request']->getUri();
        self::assertStringContainsString('www.flow.cl', $uri, 'la orden se creo en PRODUCCION');
        self::assertStringNotContainsString('sandbox', $uri);

        parse_str((string) $capturadas[0]['request']->getBody(), $cuerpo);
        self::assertSame('apikey-produccion', $cuerpo['apiKey']);
    }

    public function testSinLlavesDelAmbienteHistoricoNoSeCreaConLasDelActivo(): void
    {
        // La tentacion seria "hay llaves de produccion, uselas". Seria crear en
        // produccion una orden que la empresa pidio en pruebas. Mock vacio: si
        // llamara a Flow, el test revienta.
        $envio = $this->documento();
        $id    = $this->ordenAMedias('sandbox');

        $this->eleccion('produccion');
        $this->llaves('produccion');   // sandbox ya no tiene llaves

        $r = (new ResolutorLinkPago(
            $this->pdo,
            static fn (string $c): string => 'descifrado:' . $c,
            static fn (): string => self::URL,
            new Client(['handler' => HandlerStack::create(new MockHandler([]))])
        ))->resolver($envio);

        self::assertSame('esperar', $r['verdicto']);
        self::assertStringContainsString('faltan las llaves', $r['motivo']);
        self::assertStringContainsString('sandbox', $r['motivo']);
        self::assertStringContainsString('nacio esta orden', $r['motivo']);
        self::assertSame('pendiente', $this->link($id)['estado'], 'no se toco');
        self::assertSame('sandbox', $this->link($id)['ambiente']);
    }

    public function testUnaFilaNUEVA_SI_UsaElAmbienteActivo(): void
    {
        // La otra mitad de la regla: el activo decide para lo que todavia no
        // existe. Sin esto, cambiar de ambiente no serviria de nada.
        $envio = $this->documento();
        $this->eleccion('produccion');
        $this->llaves('produccion');

        $r = $this->resolutor([self::respuestaCrear()])->resolver($envio);

        self::assertSame('listo', $r['verdicto'], $r['motivo']);
        self::assertSame('produccion', $this->pdo->query('SELECT ambiente FROM dte_pago_link')->fetchColumn());
    }

    public function testElReintentoNoActualizaNiElAmbienteNiElProveedor(): void
    {
        // reclamar() hace UPDATE sobre una fila existente para tomar el reclamo.
        // Ese UPDATE toca intentos y reclamado_at, y nada mas: si algun dia
        // alguien le agregara ambiente o proveedor, este test se pone rojo.
        $envio = $this->documento();
        $id    = $this->ordenAMedias('sandbox');
        $antes = $this->link($id);

        $this->eleccion('produccion');
        $this->llaves('sandbox');
        $this->llaves('produccion');

        $this->resolutor([self::respuestaCrear()])->resolver($envio);

        $despues = $this->link($id);
        self::assertSame($antes['ambiente'], $despues['ambiente']);
        self::assertSame($antes['proveedor'], $despues['proveedor']);
    }

    public function testLaHistoriaDeUnaEmpresaNoAlcanzaALaDeOtra(): void
    {
        // Dos cuentas con ordenes a medias en ambientes distintos: cada una se
        // completa contra el suyo.
        $envioA = $this->documento(dteId: 100, envioId: 1, cuentaId: self::CUENTA);
        $idA    = $this->ordenAMedias('sandbox', dteId: 100, cuentaId: self::CUENTA);
        $this->eleccion('produccion', cuentaId: self::CUENTA);
        $this->llaves('sandbox', cuentaId: self::CUENTA);

        $this->eleccion('produccion', cuentaId: self::CUENTA_B);
        $this->llaves('produccion', cuentaId: self::CUENTA_B);

        $capturadas = [];
        $handler    = HandlerStack::create(new MockHandler([self::respuestaCrear()]));
        $handler->push(\GuzzleHttp\Middleware::history($capturadas));

        (new ResolutorLinkPago(
            $this->pdo,
            static fn (string $c): string => 'descifrado:' . $c,
            static fn (): string => self::URL,
            new Client(['handler' => $handler])
        ))->resolver($envioA);

        self::assertSame('sandbox', $this->link($idA)['ambiente']);
        parse_str((string) $capturadas[0]['request']->getBody(), $cuerpo);
        self::assertSame('apikey-sandbox', $cuerpo['apiKey'], 'las llaves de SU cuenta y SU ambiente');
    }

    // ==================================================================
    //  6-7. La callback resuelve por la historia de la orden
    // ==================================================================

    public function testUnaCallbackDeSandboxSiguePreguntandoASandboxDespuesDePasarAProduccion(): void
    {
        // EL CASO QUE MOTIVO TODO. Antes, esta orden se habria consultado contra
        // el Flow real con un token de sandbox: fallo permanente y pago perdido.
        $this->eleccion('sandbox');
        $this->llaves('sandbox');
        $id = $this->orden('sandbox', 'TOKSANDBOX');

        $this->eleccion('produccion');
        $this->llaves('produccion');

        $capturadas = [];
        $handler    = HandlerStack::create(new MockHandler([self::respuestaPagada('SIN-7-33-100')]));
        $handler->push(\GuzzleHttp\Middleware::history($capturadas));

        $r = ConfirmacionPago::procesar(
            $this->pdo,
            self::CUENTA,
            ['token' => 'TOKSANDBOX'],
            static fn (string $c): string => $c,
            new Client(['handler' => $handler])
        );

        self::assertSame(200, $r['codigo']);
        self::assertSame('pagado', $this->link($id)['estado']);

        $uri = (string) $capturadas[0]['request']->getUri();
        self::assertStringContainsString('sandbox.flow.cl', $uri, 'consulto el endpoint de SANDBOX');
        parse_str((string) $capturadas[0]['request']->getUri()->getQuery(), $q);
        self::assertSame('apikey-sandbox', $q['apiKey'], 'y con la apiKey de SANDBOX');
    }

    public function testUnaCallbackDeProduccionPreguntaAProduccion(): void
    {
        $this->eleccion('produccion');
        $this->llaves('sandbox');
        $this->llaves('produccion');
        $id = $this->orden('produccion', 'TOKPROD');

        $capturadas = [];
        $handler    = HandlerStack::create(new MockHandler([self::respuestaPagada('SIN-7-33-100')]));
        $handler->push(\GuzzleHttp\Middleware::history($capturadas));

        ConfirmacionPago::procesar(
            $this->pdo,
            self::CUENTA,
            ['token' => 'TOKPROD'],
            static fn (string $c): string => $c,
            new Client(['handler' => $handler])
        );

        self::assertSame('pagado', $this->link($id)['estado']);
        $uri = (string) $capturadas[0]['request']->getUri();
        self::assertStringContainsString('www.flow.cl', $uri);
        self::assertStringNotContainsString('sandbox', $uri);
        parse_str((string) $capturadas[0]['request']->getUri()->getQuery(), $q);
        self::assertSame('apikey-produccion', $q['apiKey']);
    }

    // ==================================================================
    //  8-9. El conciliador tambien, y sin fallback
    // ==================================================================

    public function testElConciliadorUsaLasLlavesDelAmbienteDeLaOrden(): void
    {
        $this->eleccion('produccion');
        $this->llaves('sandbox');
        $this->llaves('produccion');
        $id = $this->orden('sandbox', 'TOKS');

        $capturadas = [];
        $handler    = HandlerStack::create(new MockHandler([self::respuestaPagada('SIN-7-33-100')]));
        $handler->push(\GuzzleHttp\Middleware::history($capturadas));

        ReconciliadorPagos::conciliar(
            $this->pdo,
            static fn (string $c): string => $c,
            new Client(['handler' => $handler])
        );

        self::assertSame('pagado', $this->link($id)['estado']);
        parse_str((string) $capturadas[0]['request']->getUri()->getQuery(), $q);
        self::assertSame('apikey-sandbox', $q['apiKey'], 'las de la ORDEN, no las de la eleccion activa');
    }

    public function testSinLlavesDelAmbienteHistoricoNoSeUsaLasDelOtro(): void
    {
        // SIN FALLBACK. Caer al ambiente activo firmaria con el secreto
        // equivocado, o consultaria un endpoint donde ese token no existe: una
        // respuesta falsa disfrazada de verdadera.
        $this->eleccion('produccion');
        $this->llaves('produccion');          // solo produccion
        $id = $this->orden('sandbox', 'TOKS');  // orden de sandbox, huerfana

        // Mock vacio: si llamara a alguien, el test revienta.
        $r = ConfirmacionPago::procesar(
            $this->pdo,
            self::CUENTA,
            ['token' => 'TOKS'],
            static fn (string $c): string => $c,
            new Client(['handler' => HandlerStack::create(new MockHandler([]))])
        );

        self::assertSame(200, $r['codigo']);
        self::assertStringContainsString('no hay llaves', $r['motivo']);
        self::assertSame('creado', $this->link($id)['estado'], 'no se marco nada');
        self::assertNull(
            $this->link($id)['confirmacion_pendiente_at'],
            'no es un aviso irresuelto: es un ambiente retirado, no vuelve a la cola del conciliador'
        );
    }

    public function testElConciliadorNoTomaOrdenesDeUnAmbienteSinLlaves(): void
    {
        $this->eleccion('produccion');
        $this->llaves('produccion');
        $this->orden('sandbox', 'TOKS');

        // Mock vacio: el INNER JOIN tiene que dejarla fuera del barrido.
        $r = ReconciliadorPagos::conciliar(
            $this->pdo,
            static fn (string $c): string => $c,
            new Client(['handler' => HandlerStack::create(new MockHandler([]))])
        );

        self::assertSame(0, $r['miradas'], 'sale del barrido en vez de fallar en bucle');
    }

    public function testCrearSinLlavesDelAmbienteActivoEsperaYNoLlamaANadie(): void
    {
        $envio = $this->documento();
        $this->eleccion('produccion');
        $this->llaves('sandbox');   // solo sandbox: produccion sin cargar

        $r = $this->resolutor()->resolver($envio);   // mock vacio

        self::assertSame('esperar', $r['verdicto']);
        self::assertStringContainsString('faltan las llaves', $r['motivo']);
        self::assertStringContainsString('produccion', $r['motivo']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM dte_pago_link')->fetchColumn(),
            'ni siquiera se reclama la orden');
    }

    // ==================================================================
    //  13-14. El aviso PRUEBA sale de la orden, no de la eleccion
    // ==================================================================

    public function testElAvisoDePruebaSaleDelAmbienteDeLaOrden(): void
    {
        // Una orden creada en sandbox conserva su aviso aunque la empresa ya
        // este cobrando de verdad. El aviso habla del LINK que lleva el correo.
        $html = PreparadorEnvio::bloqueLinkPago(
            'https://sandbox.flow.cl/pay', 'creado', self::MONTO, 33, 3, 'Factura electronica', 'sandbox'
        );

        self::assertStringContainsString('PRUEBA', $html);
    }

    public function testUnaOrdenDeProduccionNuncaMuestraPrueba(): void
    {
        $html = PreparadorEnvio::bloqueLinkPago(
            'https://www.flow.cl/pay', 'creado', self::MONTO, 33, 3, 'Factura electronica', 'produccion'
        );

        self::assertStringNotContainsString('PRUEBA', $html);
        self::assertStringContainsString('Pagar factura', $html);
    }

    public function testElCorreoLeeElAmbienteDeLaOrdenYNoDeLaCuenta(): void
    {
        // Sobre el SELECT real de preparar(): la columna que alimenta el aviso
        // tiene que venir de dte_pago_link, no de pago_pasarela_cuenta.
        $fuente = file_get_contents(__DIR__ . '/../src/Correo/PreparadorEnvio.php');
        self::assertNotFalse($fuente);

        self::assertStringContainsString('p.ambiente AS pago_ambiente', $fuente);
        self::assertStringNotContainsString('pas.ambiente', $fuente);
        self::assertStringNotContainsString('LEFT JOIN pago_pasarela_cuenta', $fuente);
    }

    // ==================================================================
    //  15. Desactivar frena la creacion, no la resolucion
    // ==================================================================

    public function testDesactivarElCobroFrenaLasOrdenesNuevas(): void
    {
        $envio = $this->documento();
        $this->eleccion('produccion', habilitado: 0);
        $this->llaves('produccion');

        $r = $this->resolutor()->resolver($envio);   // mock vacio

        self::assertSame('no_aplica', $r['verdicto']);
    }

    public function testDesactivarElCobroNO_FrenaLasCallbacksDeLosLinksYaEnviados(): void
    {
        // Apagar el grifo no tira el agua que ya salio: los clientes siguen
        // pudiendo pagar los links que ya recibieron, y esos pagos tienen que
        // registrarse. La callback ni siquiera consulta pago_pasarela_cuenta.
        $this->eleccion('produccion', habilitado: 0);
        $this->llaves('produccion');
        $id = $this->orden('produccion', 'TOKP');

        $r = ConfirmacionPago::procesar(
            $this->pdo,
            self::CUENTA,
            ['token' => 'TOKP'],
            static fn (string $c): string => $c,
            new Client(['handler' => HandlerStack::create(new MockHandler([self::respuestaPagada('SIN-7-33-100')]))])
        );

        self::assertSame(200, $r['codigo']);
        self::assertSame('pagado', $this->link($id)['estado']);
    }

    public function testDesactivarElCobroNO_FrenaAlConciliador(): void
    {
        $this->eleccion('produccion', habilitado: 0);
        $this->llaves('produccion');
        $id = $this->orden('produccion', 'TOKP');

        ReconciliadorPagos::conciliar(
            $this->pdo,
            static fn (string $c): string => $c,
            new Client(['handler' => HandlerStack::create(new MockHandler([self::respuestaPagada('SIN-7-33-100')]))])
        );

        self::assertSame('pagado', $this->link($id)['estado']);
    }

    // ==================================================================
    //  16. Multi-tenant
    // ==================================================================

    public function testUnaEmpresaNoAlcanzaLasLlavesDeOtra(): void
    {
        $this->eleccion('produccion', cuentaId: self::CUENTA);
        $this->llaves('produccion', cuentaId: self::CUENTA);
        $this->eleccion('sandbox', cuentaId: self::CUENTA_B);
        $this->llaves('sandbox', cuentaId: self::CUENTA_B);

        $idA = $this->orden('produccion', 'TOKA', dteId: 100, cuentaId: self::CUENTA);

        // La cuenta B recibe un aviso con el token de A. Mock vacio.
        $r = ConfirmacionPago::procesar(
            $this->pdo,
            self::CUENTA_B,
            ['token' => 'TOKA'],
            static fn (string $c): string => $c,
            new Client(['handler' => HandlerStack::create(new MockHandler([]))])
        );

        self::assertSame(200, $r['codigo']);
        self::assertStringContainsString('desconocida', $r['motivo']);
        self::assertSame('creado', $this->link($idA)['estado'], 'la orden de A no se toca');
    }

    // ==================================================================
    //  El codigo nuevo ya no lee las columnas viejas
    // ==================================================================

    #[DataProvider('archivosDelModulo')]
    public function testNingunArchivoLeeYaLasCredencialesDePagoPasarelaCuenta(string $archivo): void
    {
        $fuente = file_get_contents(__DIR__ . '/../' . $archivo);
        self::assertNotFalse($fuente);

        // Se filtran los comentarios: los docblocks explican de donde se movieron
        // las columnas, y esa explicacion es justo lo que no hay que borrar para
        // que un test pase.
        $codigo = '';
        foreach (token_get_all($fuente) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $codigo .= is_array($t) ? $t[1] : $t;
        }

        // LA COMPROBACION VA SOBRE LA CONSULTA, NO SOBRE EL ARCHIVO. Seguir
        // leyendo pago_pasarela_cuenta es correcto -- ahi vive la eleccion
        // activa --; lo que no puede es traer credenciales de ahi. Asi que se
        // mira que hay entre cada SELECT y su FROM pago_pasarela_cuenta.
        preg_match_all('/SELECT(.*?)FROM pago_pasarela_cuenta/s', $codigo, $m);

        foreach ($m[1] as $columnas) {
            self::assertStringNotContainsString(
                'credencial',
                $columnas,
                "{$archivo}: una consulta a pago_pasarela_cuenta sigue pidiendo credenciales. "
                . 'Las llaves viven en pago_pasarela_credencial desde la migracion 054.'
            );
        }

        // Y al reves: si el archivo maneja credenciales, que sea desde el llavero.
        if (str_contains($codigo, 'credencial_cifrada')) {
            self::assertStringContainsString(
                'pago_pasarela_credencial',
                $codigo,
                "{$archivo}: las credenciales tienen que venir del llavero"
            );
        }
    }

    /** @return list<array{string}> */
    public static function archivosDelModulo(): array
    {
        return [
            ['src/Pago/ResolutorLinkPago.php'],
            ['src/Pago/ConfirmacionPago.php'],
            ['src/Pago/ReconciliadorPagos.php'],
            ['src/Correo/PreparadorEnvio.php'],
        ];
    }
}
