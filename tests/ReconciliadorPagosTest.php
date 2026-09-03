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
use Plantiflex\FacturacionCl\Pago\ReconciliadorPagos;

/**
 * Tests del barrido que recupera pagos cuyo aviso nunca llego o no se pudo
 * resolver.
 *
 * POR QUE EXISTE LO QUE SE PRUEBA AQUI. Flow llama UNA VEZ a la url de
 * confirmacion y no reintenta: si no recibe un 200 en 15 segundos manda un
 * correo de alerta y se olvida, dejando el pago cobrado de su lado. La entrega
 * anterior respondia 503 "para que reintente" -- una suposicion falsa -- y
 * dejaba la recuperacion apoyada en algo que no ocurre.
 *
 * Lo que estos tests fijan, en orden de lo que costaria equivocarse:
 *
 *   1. Que un pago cobrado acabe registrado aunque el aviso se haya perdido.
 *   2. Que NADA de lo que no sea un pago confirmado se marque como pagado.
 *   3. Que un descuadre de monto nunca pase por bueno.
 *   4. Que una cuenta jamas use el secreto de otra.
 *   5. Que el barrido no se convierta en un bucle eterno.
 */
final class ReconciliadorPagosTest extends TestCase
{
    private const CUENTA_A = 7;
    private const CUENTA_B = 9;
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
                conciliacion_ultimo_intento_at TEXT, conciliacion_intentos INT NOT NULL DEFAULT 0,
                estado_pasarela TEXT,
                creado_at TEXT, pagado_at TEXT
            );
        SQL);
    }

    // -----------------------------------------------------------------------
    //  Sembrado
    // -----------------------------------------------------------------------

    private function pasarela(int $cuentaId, string $secreto = 'secreto'): void
    {
        $this->pdo->prepare(
            'INSERT INTO pago_pasarela_cuenta '
            . '(cuenta_id, proveedor, ambiente, habilitado, credencial_publica, credencial_cifrada) '
            . "VALUES (:c, 'flow', 'sandbox', 1, 'apikey', :s)"
        )->execute([':c' => $cuentaId, ':s' => $secreto]);
    }

    private function orden(
        int $cuentaId = self::CUENTA_A,
        string $estado = 'creado',
        int $monto = self::MONTO,
        int $dteId = 100,
        ?string $conciliadoAt = null,
        int $intentos = 0,
        ?string $pendienteAt = null,
        ?string $estadoPasarela = null,
    ): int {
        $this->pdo->prepare(
            'INSERT INTO dte_pago_link '
            . '(dte_emitido_id, cuenta_id, proveedor, referencia, orden_externa, url, monto, estado, '
            . ' conciliacion_ultimo_intento_at, conciliacion_intentos, confirmacion_pendiente_at, estado_pasarela) '
            . "VALUES (:d, :c, 'flow', :r, :t, 'https://pay/x', :m, :e, :ca, :ci, :p, :ep)"
        )->execute([
            ':d' => $dteId, ':c' => $cuentaId,
            ':r' => sprintf('SIN-%d-33-%d', $cuentaId, $dteId),
            ':t' => sprintf('TOK-%d-%d', $cuentaId, $dteId),
            ':m' => $monto, ':e' => $estado,
            ':ca' => $conciliadoAt, ':ci' => $intentos, ':p' => $pendienteAt, ':ep' => $estadoPasarela,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function conciliar(array $respuestas = [], ?callable $descifrar = null): array
    {
        $http = $respuestas === []
            ? null
            : new Client(['handler' => HandlerStack::create(new MockHandler($respuestas))]);

        return ReconciliadorPagos::conciliar(
            $this->pdo,
            $descifrar !== null
                ? \Closure::fromCallable($descifrar)
                : static fn (string $c): string => $c,
            $http
        );
    }

    private static function estado(int $status, string $referencia = 'SIN-7-33-100', mixed $monto = self::MONTO): Response
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
    //  Lo que este proceso viene a resolver
    // -----------------------------------------------------------------------

    public function testUnPagoCuyoAvisoNuncaLlegoAcabaRegistrado(): void
    {
        // EL CASO QUE JUSTIFICA TODA LA CLASE. La orden nunca tuvo marca de aviso
        // pendiente, porque el aviso no llego nunca. Sin este barrido, ese pago
        // cobrado seria invisible para siempre.
        $this->pasarela(self::CUENTA_A);
        $id = $this->orden();

        $r = $this->conciliar([self::estado(2)]);

        self::assertSame(1, $r['pagadas']);
        self::assertSame('pagado', $this->link($id)['estado']);
        self::assertNotNull($this->link($id)['pagado_at']);
    }

    public function testUnAvisoQueNoSePudoResolverSeRecuperaDespues(): void
    {
        $this->pasarela(self::CUENTA_A);
        $id = $this->orden(pendienteAt: date('Y-m-d H:i:s'));

        $r = $this->conciliar([self::estado(2)]);

        self::assertSame(1, $r['pagadas']);
        self::assertSame('pagado', $this->link($id)['estado']);
        self::assertNull(
            $this->link($id)['confirmacion_pendiente_at'],
            'resuelto: ya no queda nada pendiente de mirar'
        );
    }

    // -----------------------------------------------------------------------
    //  Nada que no sea un pago confirmado se marca como pagado
    // -----------------------------------------------------------------------

    /** @return list<array{int,string}> */
    public static function estadosQueNoSonPago(): array
    {
        return [[1, 'pendiente'], [3, 'rechazada'], [4, 'anulada'], [0, 'desconocida']];
    }

    #[DataProvider('estadosQueNoSonPago')]
    public function testNoSeInventaUnPagoQueNoOcurrio(int $status, string $caso): void
    {
        $this->pasarela(self::CUENTA_A);
        $id = $this->orden();

        $r = $this->conciliar([self::estado($status)]);

        self::assertSame(0, $r['pagadas'], "una {$caso} no se paga sola");
        self::assertSame(1, $r['sin_pagar']);
        self::assertSame('creado', $this->link($id)['estado']);
        self::assertNull($this->link($id)['pagado_at']);
    }

    public function testUnMontoDistintoNuncaSeMarcaPagado(): void
    {
        $this->pasarela(self::CUENTA_A);
        $id = $this->orden(monto: 49990);

        $r = $this->conciliar([self::estado(2, monto: 10000)]);

        self::assertSame(0, $r['pagadas']);
        self::assertSame(1, $r['descuadres']);
        self::assertSame('error', $this->link($id)['estado']);
        self::assertNotNull($this->link($id)['confirmacion_pendiente_at']);
    }

    public function testUnFloatEnteroSiCuentaComoMontoBueno(): void
    {
        // Flow documenta amount como number<float>: 49990.0 es un pago valido y
        // no se puede rechazar por el tipo.
        $this->pasarela(self::CUENTA_A);
        $id = $this->orden(monto: 49990);

        $this->conciliar([self::estado(2, monto: 49990.0)]);

        self::assertSame('pagado', $this->link($id)['estado']);
    }

    // -----------------------------------------------------------------------
    //  Fallos
    // -----------------------------------------------------------------------

    public function testUnFalloTransitorioDejaLaOrdenRecuperable(): void
    {
        $this->pasarela(self::CUENTA_A);
        $id = $this->orden();

        $r = $this->conciliar([new Response(503, [], 'caida')]);

        self::assertSame(1, $r['fallidas']);
        self::assertSame('creado', $this->link($id)['estado'], 'no se inventa un estado');
        self::assertNotNull($this->link($id)['confirmacion_pendiente_at']);
        self::assertSame(1, (int) $this->link($id)['conciliacion_intentos'], 'y se volvera a mirar');
    }

    public function testUnFalloPermanenteTambienQuedaAnotadoYAcotado(): void
    {
        $this->pasarela(self::CUENTA_A);
        $id = $this->orden();

        $r = $this->conciliar([new Response(401, [], 'credenciales malas')]);

        self::assertSame(1, $r['fallidas']);
        self::assertStringContainsString('permanente', (string) $this->link($id)['ultimo_error']);
        // Acotado: el contador sube, asi que el tope acabara deteniendolo.
        self::assertSame(1, (int) $this->link($id)['conciliacion_intentos']);
    }

    public function testUnaFilaRotaNoSeLlevaPorDelanteLaCorrida(): void
    {
        $this->pasarela(self::CUENTA_A);
        $this->orden(dteId: 100);
        $this->orden(dteId: 200);

        // El descifrado revienta para todas: cada fila falla por su cuenta.
        $r = $this->conciliar(
            [],
            static function (string $c): string {
                throw new \RuntimeException('llave ilegible');
            }
        );

        self::assertSame(2, $r['miradas'], 'las dos se miraron');
        self::assertSame(2, $r['fallidas']);
    }

    // -----------------------------------------------------------------------
    //  Que no se convierta en un bucle eterno
    // -----------------------------------------------------------------------

    public function testUnaOrdenRecienMiradaNoSeVuelveAMirar(): void
    {
        $this->pasarela(self::CUENTA_A);
        $this->orden(conciliadoAt: date('Y-m-d H:i:s'), intentos: 1);

        // Mock vacio: si preguntara, el test revienta.
        self::assertSame(0, $this->conciliar()['miradas']);
    }

    public function testPasadoElBackoffSiSeVuelveAMirar(): void
    {
        $this->pasarela(self::CUENTA_A);
        $id = $this->orden(conciliadoAt: date('Y-m-d H:i:s', time() - 3600), intentos: 1);

        self::assertSame(1, $this->conciliar([self::estado(2)])['miradas']);
        self::assertSame('pagado', $this->link($id)['estado']);
    }

    // -----------------------------------------------------------------------
    //  N-1: una orden NUNCA queda ciega para siempre
    // -----------------------------------------------------------------------

    public function testPasadoElAntiguoTopeDe20LaOrdenSIGUE_siendoMirada(): void
    {
        // EL FALLO QUE ARREGLA ESTE CAMBIO. Antes, conciliacion_intentos >= 20
        // excluia la orden definitivamente, y ese presupuesto lo gastaba el
        // barrido NORMAL de facturas impagadas. Una factura impagada dos semanas
        // agotaba sus consultas; si el cliente pagaba despues, ese cobro quedaba
        // fuera del sistema para siempre.
        $this->pasarela(self::CUENTA_A);
        $id = $this->orden(conciliadoAt: date('Y-m-d H:i:s', time() - 999999), intentos: 25);

        $r = $this->conciliar([self::estado(2)]);

        self::assertSame(1, $r['miradas'], 'con 25 intentos sigue entrando');
        self::assertSame('pagado', $this->link($id)['estado']);
    }

    public function testPasadoElTramoRapidoBajaACadenciaDeMantenimiento(): void
    {
        // Tras el ultimo tramo (1440 min) la espera pasa a una semana. Con dos
        // dias transcurridos NO toca todavia.
        $this->pasarela(self::CUENTA_A);
        $this->orden(conciliadoAt: date('Y-m-d H:i:s', time() - (2 * 86400)), intentos: 25);

        self::assertSame(0, $this->conciliar()['miradas'], 'dos dias no alcanzan la cadencia semanal');
    }

    public function testPasadaLaSemanaLaOrdenDeMantenimientoSeVuelveAMirar(): void
    {
        $this->pasarela(self::CUENTA_A);
        $this->orden(conciliadoAt: date('Y-m-d H:i:s', time() - (8 * 86400)), intentos: 25);

        self::assertSame(1, $this->conciliar([self::estado(1)])['miradas']);
    }

    public function testUnAvisoSinResolverSeMiraAunqueTengaMuchisimosIntentos(): void
    {
        // De estas SI sabemos que la pasarela intento decirnos algo: nunca bajan
        // a la cadencia de mantenimiento. Con una hora transcurrida ya toca,
        // aunque lleve 300 intentos.
        $this->pasarela(self::CUENTA_A);
        $id = $this->orden(
            conciliadoAt: date('Y-m-d H:i:s', time() - 3700),
            intentos: 300,
            pendienteAt: date('Y-m-d H:i:s', time() - 3700)
        );

        $r = $this->conciliar([self::estado(2)]);

        self::assertSame(1, $r['miradas']);
        self::assertSame('pagado', $this->link($id)['estado']);
    }

    public function testElCasoSinCallbackNuncaRecibidoSigueSiendoRecuperable(): void
    {
        // Escenario B de la revision: el aviso NO llego nunca, asi que no hay
        // marca. El barrido por estado lo cubre igual, que es justo por lo que no
        // filtra por confirmacion_pendiente_at.
        $this->pasarela(self::CUENTA_A);
        $id = $this->orden(conciliadoAt: date('Y-m-d H:i:s', time() - (30 * 86400)), intentos: 60);

        self::assertNull($this->link($id)['confirmacion_pendiente_at']);
        $this->conciliar([self::estado(2)]);

        self::assertSame('pagado', $this->link($id)['estado']);
    }

    // -----------------------------------------------------------------------
    //  Estados de la pasarela
    // -----------------------------------------------------------------------

    public function testUnaOrdenTerminadaAllaBajaAMantenimientoPeroNoSeApaga(): void
    {
        // Rechazada/anulada no se apagan del todo: NO hemos verificado si una
        // orden rechazada puede pagarse mas tarde con el mismo link, asi que
        // apagarlas podria dejarnos ciegos otra vez.
        $this->pasarela(self::CUENTA_A);
        $this->orden(
            conciliadoAt: date('Y-m-d H:i:s', time() - 7200),
            intentos: 1,
            estadoPasarela: 'rechazada'
        );

        self::assertSame(0, $this->conciliar()['miradas'], 'dos horas no alcanzan la semanal');

        $this->pdo->exec("UPDATE dte_pago_link SET conciliacion_ultimo_intento_at = '" . date('Y-m-d H:i:s', time() - (8 * 86400)) . "'");
        self::assertSame(1, $this->conciliar([self::estado(3)])['miradas'], 'pasada la semana si');
    }

    public function testElEstadoDeLaPasarelaSeGuarda(): void
    {
        $this->pasarela(self::CUENTA_A);
        $id = $this->orden();

        $this->conciliar([self::estado(3)]);

        self::assertSame('rechazada', $this->link($id)['estado_pasarela']);
        self::assertSame('creado', $this->link($id)['estado'], 'nuestro estado es otra cosa');
    }

    /** @return list<array{int,string}> */
    public static function estadosDeFlow(): array
    {
        return [
            [1, 'pendiente'],
            [2, 'pagada'],
            [3, 'rechazada'],
            [4, 'anulada'],
            [7, 'desconocido:7'],
            [0, 'desconocido:0'],
        ];
    }

    #[DataProvider('estadosDeFlow')]
    public function testCadaStatusSeTraduceYSoloElDosPaga(int $status, string $nombre): void
    {
        $this->pasarela(self::CUENTA_A);
        $id = $this->orden();

        $this->conciliar([self::estado($status)]);

        self::assertSame($nombre, $this->link($id)['estado_pasarela']);
        self::assertSame(
            $status === 2 ? 'pagado' : 'creado',
            $this->link($id)['estado'],
            'solo el 2 puede pagar; un estado desconocido JAMAS'
        );
    }

    public function testUnStatusDesconocidoNoApagaLaOrden(): void
    {
        // No es terminal: se sigue preguntando por la cadencia normal.
        $this->pasarela(self::CUENTA_A);
        $this->orden(conciliadoAt: date('Y-m-d H:i:s', time() - 7200), intentos: 1, estadoPasarela: 'desconocido:7');

        self::assertSame(1, $this->conciliar([self::estado(7)])['miradas']);
    }

    public function testElIntentoSeAnotaAntesDePreguntar(): void
    {
        // Si se anotara despues, una pasarela que cuelga dejaria la corrida
        // empezando siempre por la misma fila.
        $this->pasarela(self::CUENTA_A);
        $id = $this->orden();

        $this->conciliar([new Response(500, [], 'x')]);

        self::assertSame(1, (int) $this->link($id)['conciliacion_intentos']);
        self::assertNotNull($this->link($id)['conciliacion_ultimo_intento_at']);
    }

    // -----------------------------------------------------------------------
    //  Que NO mira
    // -----------------------------------------------------------------------

    /** @return list<array{string}> */
    public static function estadosQueNoSeBarren(): array
    {
        return [['pagado'], ['omitido'], ['error'], ['pendiente']];
    }

    #[DataProvider('estadosQueNoSeBarren')]
    public function testSoloSeBarrenLasOrdenesCreadas(string $estado): void
    {
        // 'pagado' esta cerrada; 'omitido' nunca existio en la pasarela;
        // 'pendiente' aun no tiene orden alla; y 'error' -- descuadre de monto --
        // espera a una PERSONA: volver a preguntar daria lo mismo y taparia el
        // incidente bajo un intento mas.
        $this->pasarela(self::CUENTA_A);
        $this->orden(estado: $estado);

        self::assertSame(0, $this->conciliar()['miradas']);
    }

    public function testUnaOrdenSinIdentificadorExternoNoSePuedeConsultar(): void
    {
        $this->pasarela(self::CUENTA_A);
        $this->pdo->exec("UPDATE dte_pago_link SET orden_externa = NULL");
        $this->orden(dteId: 300);
        $this->pdo->exec("UPDATE dte_pago_link SET orden_externa = NULL WHERE dte_emitido_id = 300");

        self::assertSame(0, $this->conciliar()['miradas']);
    }

    // -----------------------------------------------------------------------
    //  Aislamiento entre tenants
    // -----------------------------------------------------------------------

    public function testCadaOrdenSeConsultaConElSecretoDeSuPropiaCuenta(): void
    {
        // Las credenciales viajan en la MISMA fila que la orden, unidas por
        // cuenta_id: por construccion no hay forma de cruzarlas.
        $this->pasarela(self::CUENTA_A, 'secreto-de-A');
        $this->pasarela(self::CUENTA_B, 'secreto-de-B');
        $this->orden(cuentaId: self::CUENTA_A, dteId: 100);
        $this->orden(cuentaId: self::CUENTA_B, dteId: 200);

        $usados = [];
        $this->conciliar(
            [self::estado(2, 'SIN-7-33-100'), self::estado(2, 'SIN-9-33-200')],
            static function (string $cifrado) use (&$usados): string {
                $usados[] = $cifrado;

                return $cifrado;
            }
        );

        self::assertSame(['secreto-de-A', 'secreto-de-B'], $usados);
    }

    public function testUnaCuentaSinPasarelaNoTieneOrdenesQueConciliar(): void
    {
        // El INNER JOIN las deja fuera: sin credenciales no hay a quien preguntar.
        $this->orden(cuentaId: self::CUENTA_A);

        self::assertSame(0, $this->conciliar()['miradas']);
    }

    // -----------------------------------------------------------------------
    //  Convivencia con el aviso
    // -----------------------------------------------------------------------

    public function testElAvisoYElConciliadorNoSePisan(): void
    {
        // Los dos llaman a la MISMA ConfirmacionPago::resolverOrden(), cuyo
        // UPDATE lleva "estado <> 'pagado'". Que coincidan sobre la misma fila no
        // produce doble efecto ni reescribe la hora del pago.
        $this->pasarela(self::CUENTA_A);
        $id = $this->orden();

        $this->conciliar([self::estado(2)]);
        $primero = $this->link($id)['pagado_at'];

        // Segunda pasada: la fila ya esta pagada y ni siquiera es candidata.
        $r = $this->conciliar();

        self::assertSame(0, $r['miradas'], 'una orden pagada no hace trabajo externo');
        self::assertSame($primero, $this->link($id)['pagado_at']);
    }

    public function testUnaOrdenYaPagadaNoConsultaANadie(): void
    {
        $this->pasarela(self::CUENTA_A);
        $this->orden(estado: 'pagado');

        // Mock vacio: cualquier consulta revienta.
        self::assertSame(0, $this->conciliar()['miradas']);
    }

    public function testLasQueDejaronAvisoSinResolverSeMiranPrimero(): void
    {
        $this->pasarela(self::CUENTA_A);
        $this->orden(dteId: 100);                                        // sin marca
        $this->orden(dteId: 200, pendienteAt: date('Y-m-d H:i:s'));      // con marca

        $r = ReconciliadorPagos::conciliar(
            $this->pdo,
            static fn (string $c): string => $c,
            new Client(['handler' => HandlerStack::create(new MockHandler([self::estado(1)]))]),
            1   // solo una por corrida
        );

        self::assertSame(1, $r['miradas']);
        // La que tenia marca es la que se miro: de esa SI sabemos que hubo
        // movimiento.
        self::assertSame(1, (int) $this->link(2)['conciliacion_intentos']);
        self::assertSame(0, (int) $this->link(1)['conciliacion_intentos']);
    }
}
