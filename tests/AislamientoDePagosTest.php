<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Pago\ResolutorLinkPago;

/**
 * Que el modulo de pagos no pueda detener el correo de quien no lo usa.
 *
 * DE DONDE SALE ESTE TEST, medido y no supuesto. El runner de la cola construia
 * el resolutor UNA vez, al arrancar, y exigia ahi CRYPTO_MASTER_KEY y
 * PANEL_URL_PUBLICA. Si faltaba cualquiera de las dos hacia:
 *
 *     if ($resolutor === null) { $aplazados++; continue; }
 *
 * y ese continue iba ANTES de mirar de que cuenta era el correo. Ejecutado
 * contra un preview con la pasarela en habilitado = 0, el resultado fue:
 *
 *     fila 2  APLAZADA no se pudo resolver el link de pago (falta PANEL_URL_PUBLICA)
 *     fila 1  APLAZADA no se pudo resolver el link de pago (falta PANEL_URL_PUBLICA)
 *     RESUMEN enviados=0 aplazados=2
 *
 * Una empresa que no cobra en linea dejaba de enviar facturas por una variable
 * de entorno de un modulo que no usa. En este sistema hay un inquilino que manda
 * ~139 facturas al mes; para el, eso es el correo caido.
 *
 *
 * LA REGLA QUE FIJAN ESTOS TESTS, EN DOS MITADES QUE NO SE PUEDEN SEPARAR
 * -----------------------------------------------------------------------------
 *   SIN IMPACTO para quien no usa pagos. Sin fila en pago_pasarela_cuenta, o con
 *   habilitado = 0, el correo sale como salia antes de que este modulo
 *   existiera, aunque no haya ni llave ni url configuradas.
 *
 *   FAIL CLOSED para quien si los usa. Con el cobro activo y una dependencia
 *   rota, el correo ESPERA. Nadie recibe una factura sin el link que su emisor
 *   pidio: aislar a quien no cobra no puede relajar a quien si.
 *
 * La segunda mitad importa tanto como la primera. Un arreglo que dejara pasar el
 * correo pelado "para no bloquear a nadie" seria peor que el fallo original.
 */
final class AislamientoDePagosTest extends TestCase
{
    private const CUENTA_CON_PAGOS = 1;
    private const CUENTA_SIN_PAGOS = 5;
    private const LLAVE_BUENA      = '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff';

    private PDO $pdo;

    /** @var array<string,string|false> */
    private array $entornoOriginal = [];

    protected function setUp(): void
    {
        foreach (['CRYPTO_MASTER_KEY', 'PANEL_URL_PUBLICA'] as $v) {
            $this->entornoOriginal[$v] = getenv($v);
        }

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
                ambiente TEXT NOT NULL DEFAULT 'sandbox',
                referencia TEXT NOT NULL UNIQUE, orden_externa TEXT, url TEXT,
                monto INT NOT NULL DEFAULT 0, estado TEXT NOT NULL DEFAULT 'pendiente',
                intentos INT NOT NULL DEFAULT 0, reclamado_at TEXT, ultimo_error TEXT,
                reintentar_despues_at TEXT, confirmacion_pendiente_at TEXT,
                creado_at TEXT, pagado_at TEXT
            );
        SQL);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE pago_pasarela_cuenta (
                id INTEGER PRIMARY KEY AUTOINCREMENT, cuenta_id BIGINT NOT NULL UNIQUE,
                proveedor TEXT NOT NULL DEFAULT 'flow',
                ambiente_activo TEXT NOT NULL DEFAULT 'sandbox',
                habilitado INT NOT NULL DEFAULT 0,
                url_retorno TEXT
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

    protected function tearDown(): void
    {
        // putenv es global al proceso: sin esto, un test dejaria el entorno roto
        // para los ~760 que corren despues en la misma corrida.
        foreach ($this->entornoOriginal as $nombre => $valor) {
            $valor === false ? putenv($nombre) : putenv("{$nombre}={$valor}");
        }
    }

    // ------------------------------------------------------------------
    //  Sembrado
    // ------------------------------------------------------------------

    private function documento(int $envioId, int $dteId, int $cuentaId): int
    {
        $this->pdo->prepare(
            'INSERT INTO dte_emitido (id, rut_emisor, ambiente, tipo_dte, folio, total, receptor_rut) '
            . "VALUES (:id, '78225195-3', 'produccion', 33, :f, 49990, '78159082-7')"
        )->execute([':id' => $dteId, ':f' => $dteId]);

        $this->pdo->prepare(
            'INSERT INTO dte_envio_correo (id, dte_emitido_id, cuenta_id, destinatario) '
            . "VALUES (:e, :d, :c, 'cliente@ejemplo.cl')"
        )->execute([':e' => $envioId, ':d' => $dteId, ':c' => $cuentaId]);

        return $envioId;
    }

    /**
     * Siembra la eleccion activa Y las llaves de ese ambiente.
     *
     * SON DOS TABLAS DESDE LA 054: pago_pasarela_cuenta dice que hace la empresa
     * hoy (una fila), pago_pasarela_credencial guarda las llaves de cada ambiente
     * (varias). Sembrar solo la primera deja una empresa "activa" sin llaves, que
     * es un estado legitimo y que el resolutor rechaza -- util para probarlo, no
     * para el caso normal.
     */
    private function pasarela(int $cuentaId, int $habilitado): void
    {
        $this->pdo->prepare(
            'INSERT INTO pago_pasarela_cuenta (cuenta_id, proveedor, ambiente_activo, habilitado) '
            . "VALUES (:c, 'flow', 'sandbox', :h)"
        )->execute([':c' => $cuentaId, ':h' => $habilitado]);

        $this->pdo->prepare(
            'INSERT INTO pago_pasarela_credencial (cuenta_id, proveedor, ambiente, credencial_publica, credencial_cifrada) '
            . "VALUES (:c, 'flow', 'sandbox', 'apikey-publica', 'cifrado')"
        )->execute([':c' => $cuentaId]);
    }

    private function entorno(?string $llave, ?string $url): void
    {
        $llave === null ? putenv('CRYPTO_MASTER_KEY')  : putenv("CRYPTO_MASTER_KEY={$llave}");
        $url   === null ? putenv('PANEL_URL_PUBLICA') : putenv("PANEL_URL_PUBLICA={$url}");
    }

    /**
     * Un cliente HTTP que EXPLOTA si alguien lo usa.
     *
     * MockHandler sin respuestas encoladas lanza en cuanto recibe una peticion,
     * asi que sirve de alarma: cualquier test que lo lleve y termine en verde ha
     * demostrado, y no supuesto, que no se hablo con Flow.
     */
    private function httpQueNadieDebeUsar(): Client
    {
        return new Client(['handler' => HandlerStack::create(new MockHandler([]))]);
    }

    // ==================================================================
    //  SIN IMPACTO para quien no usa pagos
    // ==================================================================

    public function testSinPasarelaYSinLlaveMaestraElCorreoSaleIgual(): void
    {
        $envio = $this->documento(1, 100, self::CUENTA_SIN_PAGOS);   // sin fila en pago_pasarela_cuenta
        $this->entorno(llave: null, url: 'https://facturacion.ejemplo.cl');

        $r = ResolutorLinkPago::desdeEntorno($this->pdo, $this->httpQueNadieDebeUsar())->resolver($envio);

        self::assertSame('no_aplica', $r['verdicto']);
    }

    public function testSinPasarelaYSinUrlPublicaElCorreoSaleIgual(): void
    {
        $envio = $this->documento(1, 100, self::CUENTA_SIN_PAGOS);
        $this->entorno(llave: self::LLAVE_BUENA, url: null);

        $r = ResolutorLinkPago::desdeEntorno($this->pdo, $this->httpQueNadieDebeUsar())->resolver($envio);

        self::assertSame('no_aplica', $r['verdicto']);
    }

    public function testSinPasarelaYSinNadaConfiguradoElCorreoSaleIgual(): void
    {
        $envio = $this->documento(1, 100, self::CUENTA_SIN_PAGOS);
        $this->entorno(llave: null, url: null);

        $r = ResolutorLinkPago::desdeEntorno($this->pdo, $this->httpQueNadieDebeUsar())->resolver($envio);

        self::assertSame('no_aplica', $r['verdicto']);
    }

    public function testConLaPasarelaApagadaYElEntornoRotoElCorreoSaleIgual(): void
    {
        $envio = $this->documento(1, 100, self::CUENTA_CON_PAGOS);
        $this->pasarela(self::CUENTA_CON_PAGOS, habilitado: 0);       // la tiene, pero apagada
        $this->entorno(llave: null, url: null);

        $r = ResolutorLinkPago::desdeEntorno($this->pdo, $this->httpQueNadieDebeUsar())->resolver($envio);

        self::assertSame('no_aplica', $r['verdicto']);
        self::assertStringContainsString('no tiene el cobro en linea activo', $r['motivo']);
    }

    public function testNingunNoAplicaLlegaAHablarConLaPasarela(): void
    {
        // El httpQueNadieDebeUsar() de cada caso ya lo garantiza; esto lo deja
        // dicho como afirmacion propia y comprueba ademas que no se toca la fila.
        $envio = $this->documento(1, 100, self::CUENTA_SIN_PAGOS);
        $this->entorno(llave: null, url: null);

        $r = ResolutorLinkPago::desdeEntorno($this->pdo, $this->httpQueNadieDebeUsar())->resolver($envio);

        self::assertSame('no_aplica', $r['verdicto']);
        self::assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM dte_pago_link')->fetchColumn(),
            'un no_aplica no reserva ni crea nada'
        );
    }

    // ==================================================================
    //  FAIL CLOSED para quien si los usa
    // ==================================================================

    #[DataProvider('dependenciasQueFaltan')]
    public function testConElCobroActivoYUnaDependenciaRotaElCorreoEspera(
        ?string $llave,
        ?string $url,
        string $enElMotivo
    ): void {
        $envio = $this->documento(1, 100, self::CUENTA_CON_PAGOS);
        $this->pasarela(self::CUENTA_CON_PAGOS, habilitado: 1);
        $this->entorno($llave, $url);

        $r = ResolutorLinkPago::desdeEntorno($this->pdo, $this->httpQueNadieDebeUsar())->resolver($envio);

        self::assertSame('esperar', $r['verdicto'], 'nadie debe recibir la factura sin su link');
        self::assertStringContainsString($enElMotivo, $r['motivo']);
    }

    /** @return list<array{?string, ?string, string}> */
    public static function dependenciasQueFaltan(): array
    {
        return [
            'falta la url publica'   => [self::LLAVE_BUENA, null, 'PANEL_URL_PUBLICA'],
            'url publica en blanco'  => [self::LLAVE_BUENA, '',   'PANEL_URL_PUBLICA'],
            'falta la llave maestra' => [null, 'https://facturacion.ejemplo.cl', 'CRYPTO_MASTER_KEY'],
            'llave maestra corta'    => ['00ff', 'https://facturacion.ejemplo.cl', 'CRYPTO_MASTER_KEY'],
        ];
    }

    public function testEsperarNoConsumeIntentosDelCorreo(): void
    {
        $envio = $this->documento(1, 100, self::CUENTA_CON_PAGOS);
        $this->pasarela(self::CUENTA_CON_PAGOS, habilitado: 1);
        $this->entorno(llave: self::LLAVE_BUENA, url: null);

        ResolutorLinkPago::desdeEntorno($this->pdo, $this->httpQueNadieDebeUsar())->resolver($envio);

        self::assertSame(
            0,
            (int) $this->pdo->query('SELECT intentos FROM dte_envio_correo WHERE id = 1')->fetchColumn(),
            'un fallo de pagos no puede gastar los intentos del correo'
        );
    }

    // ==================================================================
    //  El caso que de verdad importa: una tanda con los dos a la vez
    // ==================================================================

    public function testEnUnaTandaMezcladaSoloEsperaElQueCobra(): void
    {
        // Retrato del sistema real: la cuenta 1 estrena el cobro en linea con la
        // configuracion a medias, la cuenta 5 factura todos los dias y no cobra
        // por aqui. Antes, la primera apagaba el correo de la segunda.
        $deLaQueCobra   = $this->documento(1, 100, self::CUENTA_CON_PAGOS);
        $deLaQueNoCobra = $this->documento(2, 200, self::CUENTA_SIN_PAGOS);
        $this->pasarela(self::CUENTA_CON_PAGOS, habilitado: 1);
        $this->entorno(llave: self::LLAVE_BUENA, url: null);         // rota para todos

        $resolutor = ResolutorLinkPago::desdeEntorno($this->pdo, $this->httpQueNadieDebeUsar());

        self::assertSame('esperar',   $resolutor->resolver($deLaQueCobra)['verdicto']);
        self::assertSame('no_aplica', $resolutor->resolver($deLaQueNoCobra)['verdicto']);
    }

    // ==================================================================
    //  Que el arreglo no haya cambiado el camino bueno
    // ==================================================================

    public function testConTodoEnSuSitioElLinkSeSigueCreando(): void
    {
        $envio = $this->documento(1, 100, self::CUENTA_CON_PAGOS);
        $this->pasarela(self::CUENTA_CON_PAGOS, habilitado: 1);
        $this->entorno(llave: self::LLAVE_BUENA, url: 'https://facturacion.ejemplo.cl');

        // Con la llave de verdad, descifrar('cifrado') fallaria: lo que importa
        // aqui es que se LLEGA a intentarlo, o sea que la pereza no rompio el
        // camino bueno. El resolutor traduce ese fallo a 'esperar', nunca a
        // 'no_aplica' -- que es justo la garantia de fail-closed.
        $r = ResolutorLinkPago::desdeEntorno($this->pdo, $this->httpQueNadieDebeUsar())->resolver($envio);

        self::assertSame('esperar', $r['verdicto']);
        self::assertStringNotContainsString('PANEL_URL_PUBLICA', $r['motivo']);
        self::assertStringNotContainsString('CRYPTO_MASTER_KEY', $r['motivo']);
    }

    // ==================================================================
    //  Que el cron y el CLI no puedan divergir
    // ==================================================================

    public function testElRunnerYElCliUsanLaMismaPuerta(): void
    {
        foreach (['enviar_correos_pendientes.php', 'enviar_correo.php'] as $script) {
            $fuente = file_get_contents(__DIR__ . '/../scripts/' . $script);
            self::assertNotFalse($fuente);

            self::assertStringContainsString(
                'ResolutorLinkPago::desdeEntorno(',
                $fuente,
                "{$script} tiene que armar el resolutor por la puerta comun"
            );
            self::assertStringNotContainsString(
                'new ResolutorLinkPago(',
                $fuente,
                "{$script} no puede armarlo a mano: ahi es donde se cuela una politica distinta"
            );
        }
    }

    public function testNingunScriptVuelveAPonerUnaGuardaGlobalDePagos(): void
    {
        // La forma exacta del fallo original. Si alguien la reintroduce, un
        // fallo de configuracion de pagos vuelve a alcanzar a todos los tenants.
        foreach (['enviar_correos_pendientes.php', 'enviar_correo.php'] as $script) {
            $fuente = file_get_contents(__DIR__ . '/../scripts/' . $script);
            self::assertNotFalse($fuente);

            self::assertDoesNotMatchRegularExpression(
                '/\$resolutor\s*===\s*null/',
                $fuente,
                "{$script}: el resolutor ya no puede ser null, y comprobarlo esconderia una guarda global"
            );
        }
    }

    public function testElDryRunDelRunnerSigueSinResolverNada(): void
    {
        $fuente = file_get_contents(__DIR__ . '/../scripts/enviar_correos_pendientes.php');
        self::assertNotFalse($fuente);

        // El bloque de --dry-run: desde su if hasta el exit que lo cierra. Ahi
        // dentro no puede aparecer resolver(), porque resolver crea cobros reales
        // y este modo promete no tocar nada.
        $ini = strpos($fuente, 'if ($dryRun) {');
        self::assertNotFalse($ini);
        $bloque = substr($fuente, $ini, strpos($fuente, 'exit(0);', $ini) - $ini);

        self::assertStringNotContainsString('->resolver(', $bloque);
        self::assertStringNotContainsString('desdeEntorno(', $bloque);
    }
}
