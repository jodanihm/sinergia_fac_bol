<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Pago\EstadoRetornoPago;

/**
 * La pagina a la que vuelve el pagador desde la pasarela.
 *
 * LO QUE DE VERDAD SE VIGILA AQUI: que el navegador no pueda fabricar un pago.
 *
 * Quien vuelve de pagar trae un token en el POST, y ese token es un dato que
 * cualquiera puede inventarse. La pagina lo usa como clave de LECTURA y nada
 * mas; el veredicto sale de una columna que solo escribe ConfirmacionPago
 * despues de preguntarle a la pasarela. Media docena de los tests de abajo
 * existen para que eso no se pueda romper sin que salte algo.
 *
 * El otro grupo mira la pantalla en si: que no muestre un error PHP a alguien
 * que acaba de darnos dinero, y que no diga "pagado" cuando no lo sabe.
 */
final class RetornoDelPagadorTest extends TestCase
{
    private const TOKEN = 'aB3dE5fG7hJ9kL1mN3pQ5rS7tU9vW1xY';

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE dte_pago_link (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                dte_emitido_id BIGINT NOT NULL,
                cuenta_id BIGINT NOT NULL, proveedor TEXT NOT NULL DEFAULT 'flow',
                referencia TEXT NOT NULL, orden_externa TEXT, url TEXT,
                monto INT NOT NULL DEFAULT 0, estado TEXT NOT NULL DEFAULT 'pendiente',
                estado_pasarela TEXT, pagado_at TEXT
            );
        SQL);
    }

    private function orden(
        string $estado = 'creado',
        ?string $estadoPasarela = null,
        string $token = self::TOKEN,
        int $cuentaId = 1,
        int $dteId = 100
    ): void {
        $this->pdo->prepare(
            'INSERT INTO dte_pago_link (dte_emitido_id, cuenta_id, referencia, orden_externa, monto, estado, estado_pasarela) '
            . 'VALUES (:d, :c, :r, :t, 49990, :e, :p)'
        )->execute([
            ':d' => $dteId,
            ':c' => $cuentaId,
            ':r' => 'SIN-' . $cuentaId . '-33-' . $dteId,
            ':t' => $token,
            ':e' => $estado,
            ':p' => $estadoPasarela,
        ]);
    }

    /** Foto de la tabla entera, para probar que un request no escribio nada. */
    private function foto(): string
    {
        return json_encode(
            $this->pdo->query('SELECT * FROM dte_pago_link ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            JSON_THROW_ON_ERROR
        );
    }

    // ------------------------------------------------------------------
    //  El navegador no decide nada
    // ------------------------------------------------------------------

    public function testUnTokenInventadoNoConfirmaNada(): void
    {
        $this->orden(estado: 'creado');

        self::assertSame(
            EstadoRetornoPago::VERIFICANDO,
            EstadoRetornoPago::resolver($this->pdo, 'token-que-nadie-emitio-jamas')
        );
    }

    public function testVolverDeLaPasarelaNoMarcaElPagoComoPagado(): void
    {
        $this->orden(estado: 'creado');
        $antes = $this->foto();

        EstadoRetornoPago::resolver($this->pdo, self::TOKEN);

        self::assertSame($antes, $this->foto(), 'la pagina de retorno NO escribe');
        self::assertSame(
            'creado',
            $this->pdo->query('SELECT estado FROM dte_pago_link')->fetchColumn()
        );
    }

    public function testNingunParametroDelNavegadorEscribeEnLaBase(): void
    {
        $this->orden(estado: 'creado');
        $antes = $this->foto();

        // Todo lo que a alguien se le podria ocurrir mandar.
        foreach ([self::TOKEN, 'x', '', str_repeat('a', 300), "' OR 1=1 --", '../../etc/passwd'] as $intento) {
            EstadoRetornoPago::resolver($this->pdo, $intento);
        }

        self::assertSame($antes, $this->foto());
    }

    public function testUnaComillaEnElTokenNoRompeLaConsulta(): void
    {
        $this->orden();

        // Va como parametro: no puede cerrar la cadena ni concatenar SQL. Y de
        // paso lo frena el patron antes de llegar a la base.
        self::assertSame(
            EstadoRetornoPago::VERIFICANDO,
            EstadoRetornoPago::resolver($this->pdo, "' OR '1'='1")
        );
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM dte_pago_link')->fetchColumn());
    }

    // ------------------------------------------------------------------
    //  Lo que si puede afirmar
    // ------------------------------------------------------------------

    public function testConfirmaSoloCuandoLaOrdenYaEstaPagada(): void
    {
        $this->orden(estado: 'pagado');

        self::assertSame(
            EstadoRetornoPago::CONFIRMADO,
            EstadoRetornoPago::resolver($this->pdo, self::TOKEN)
        );
    }

    #[DataProvider('estadosFallidosDeLaPasarela')]
    public function testDiceQueNoSeCobroCuandoLaPasarelaLoRechazo(string $estadoPasarela): void
    {
        $this->orden(estado: 'creado', estadoPasarela: $estadoPasarela);

        self::assertSame(
            EstadoRetornoPago::RECHAZADO,
            EstadoRetornoPago::resolver($this->pdo, self::TOKEN)
        );
    }

    /** @return list<array{string}> */
    public static function estadosFallidosDeLaPasarela(): array
    {
        return [['rechazada'], ['anulada'], ['RECHAZADA'], [' anulada ']];
    }

    #[DataProvider('estadosQueTodaviaNoDicenNada')]
    public function testTodoLoDemasEsVerificando(string $estado, ?string $estadoPasarela): void
    {
        $this->orden(estado: $estado, estadoPasarela: $estadoPasarela);

        self::assertSame(
            EstadoRetornoPago::VERIFICANDO,
            EstadoRetornoPago::resolver($this->pdo, self::TOKEN)
        );
    }

    /** @return list<array{string, string|null}> */
    public static function estadosQueTodaviaNoDicenNada(): array
    {
        return [
            ['creado', null],
            ['creado', 'pendiente'],
            ['pendiente', null],
            ['error', null],
            ['omitido', null],
        ];
    }

    // ------------------------------------------------------------------
    //  Sin oraculo y sin cruce de tenants
    // ------------------------------------------------------------------

    public function testUnTokenDesconocidoSeVeIgualQueUnoPendiente(): void
    {
        $this->orden(estado: 'creado');

        // Si estas dos respuestas se distinguieran, probando tokens al azar se
        // podria averiguar cuales son ordenes reales.
        self::assertSame(
            EstadoRetornoPago::resolver($this->pdo, 'zZ9yY8xX7wW6vV5uU4tT3sS2rR1qQ0p'),
            EstadoRetornoPago::resolver($this->pdo, self::TOKEN)
        );
    }

    public function testUnaColisionDeTokenEntreComerciosNoAfirmaNada(): void
    {
        // Flow no promete tokens unicos ENTRE comercios distintos. Si dos filas
        // comparten token, decir el estado de una seria decir el de otro tenant.
        $this->orden(estado: 'pagado', cuentaId: 1, dteId: 100);
        $this->orden(estado: 'creado', cuentaId: 2, dteId: 200);

        self::assertSame(
            EstadoRetornoPago::VERIFICANDO,
            EstadoRetornoPago::resolver($this->pdo, self::TOKEN)
        );
    }

    // ------------------------------------------------------------------
    //  De donde sale el token
    // ------------------------------------------------------------------

    public function testElTokenSeLeeDelPostPorqueEsLoQueHaceFlow(): void
    {
        self::assertSame('abcdefghijkl', EstadoRetornoPago::tokenDeLaPeticion(['token' => 'abcdefghijkl'], []));
    }

    public function testTambienSeAceptaPorGetParaQuienRecargaLaPagina(): void
    {
        self::assertSame('abcdefghijkl', EstadoRetornoPago::tokenDeLaPeticion([], ['token' => 'abcdefghijkl']));
    }

    public function testElPostGanaAlGet(): void
    {
        self::assertSame(
            'delpostdelpost',
            EstadoRetornoPago::tokenDeLaPeticion(['token' => 'delpostdelpost'], ['token' => 'delgetdelget'])
        );
    }

    #[DataProvider('tokensQueNoSonTokens')]
    public function testLoQueNoTieneFormaDeTokenSeIgnora(mixed $crudo): void
    {
        self::assertNull(EstadoRetornoPago::tokenDeLaPeticion(['token' => $crudo], []));
    }

    /** @return list<array{mixed}> */
    public static function tokensQueNoSonTokens(): array
    {
        return [
            [''],
            ['   '],
            ['corto'],
            [['un', 'array']],       // $_POST['token'][]=x
            [null],
            [12345],
            ['con espacio en medio'],
            ["salto\nde linea"],
            [str_repeat('a', 300)],
        ];
    }

    // ------------------------------------------------------------------
    //  Nunca un error PHP en la cara del pagador
    // ------------------------------------------------------------------

    public function testSiLaBaseSeCaeLaRespuestaEsNeutraYNoUnaExcepcion(): void
    {
        $rota = new PDO('sqlite::memory:');
        $rota->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Sin CREATE TABLE: cualquier consulta revienta.

        self::assertSame(
            EstadoRetornoPago::VERIFICANDO,
            EstadoRetornoPago::resolver($rota, self::TOKEN)
        );
    }

    public function testNoHablaConLaPasarelaNiConElMotorNiConElSii(): void
    {
        $codigo = self::codigoSinComentarios();

        // Si alguien mete aqui una llamada HTTP, esta pantalla pasa a depender de
        // que un tercero responda mientras el pagador espera mirandola.
        foreach (['Guzzle', 'ClientInterface', 'curl_', 'file_get_contents', 'fsockopen',
                  'FabricaPasarela', 'consultarEstado', 'MOTOR_URL'] as $prohibido) {
            self::assertStringNotContainsString($prohibido, $codigo, "no debe aparecer {$prohibido}");
        }
    }

    public function testNoEscribeEnLaBaseNiEnUnaSolaLinea(): void
    {
        $codigo = self::codigoSinComentarios();

        foreach (['UPDATE ', 'INSERT ', 'DELETE ', 'REPLACE '] as $escritura) {
            self::assertStringNotContainsString($escritura, $codigo);
        }
    }

    /**
     * El codigo del resolutor con los comentarios fuera.
     *
     * Los dos tests de arriba miran el ARCHIVO en busca de palabras prohibidas, y
     * sobre el texto crudo darian falso positivo: el docblock nombra a
     * consultarEstado() y a ConfirmacionPago justamente para explicar que ESOS
     * son los que confirman y este no. Quitar la explicacion para que pase un
     * test seria dejar el archivo peor; filtrar los comentarios cuesta cuatro
     * lineas y hace la comprobacion que de verdad se queria hacer.
     */
    private static function codigoSinComentarios(): string
    {
        $fuente = file_get_contents(__DIR__ . '/../src/Pago/EstadoRetornoPago.php');
        self::assertNotFalse($fuente);

        $codigo = '';
        foreach (token_get_all($fuente) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $codigo .= is_array($token) ? $token[1] : $token;
        }

        return $codigo;
    }

    // ------------------------------------------------------------------
    //  La pantalla
    // ------------------------------------------------------------------

    #[DataProvider('losTresEstados')]
    public function testLaPantallaRenderizaEnLosTresEstados(string $estado, string $tituloEsperado): void
    {
        $html = self::pintar($estado);

        self::assertStringContainsString('<html lang="es"', $html);
        self::assertStringContainsString('<title>' . $tituloEsperado . '</title>', $html);
        self::assertStringContainsString('</body>', $html);
        self::assertStringNotContainsString('Fatal error', $html);
        self::assertStringNotContainsString('Warning', $html);
        self::assertStringNotContainsString('Stack trace', $html);
    }

    /** @return list<array{string, string}> */
    public static function losTresEstados(): array
    {
        return [
            ['confirmado',  'Pago confirmado'],
            ['rechazado',   'El pago no se completo'],
            ['verificando', 'Estamos verificando tu pago'],
        ];
    }

    public function testMientrasVerificaNoAfirmaQueElPagoSalioBien(): void
    {
        $html = mb_strtolower(self::pintar('verificando'));

        foreach (['pago exitoso', 'pago recibido', 'pago confirmado', 'gracias por tu pago'] as $promesa) {
            self::assertStringNotContainsString($promesa, $html, "no puede prometer '{$promesa}'");
        }
        self::assertStringContainsString('verificando tu pago', $html);
    }

    public function testNoOfreceVolverAPagarDesdeAqui(): void
    {
        // Un boton de reintentar en esta pantalla es como se cobra dos veces:
        // quien ya pago lo ve y lo pulsa.
        foreach (self::losTresEstados() as [$estado, $_]) {
            $html = self::pintar($estado);
            self::assertStringNotContainsString('flow.cl', $html);
            self::assertStringNotContainsString('pay.php', $html);
        }
    }

    #[DataProvider('losTresEstados')]
    public function testLaPantallaNoMuestraNadaDeLaFacturaNiSecretos(string $estado, string $_): void
    {
        $html = mb_strtolower(self::pintar($estado));

        foreach (['apikey', 'secret', 'credencial', 'token', 'orden_externa', 'commerceorder',
                  'folio', 'rut', '49990', 'dte'] as $filtracion) {
            self::assertStringNotContainsString($filtracion, $html, "no debe filtrar '{$filtracion}'");
        }
    }

    /** Renderiza la vista tal cual, sin router y sin sesion. */
    private static function pintar(string $estadoRetorno): string
    {
        ob_start();
        require __DIR__ . '/../panel/views/pago-retorno.php';

        return (string) ob_get_clean();
    }
}
