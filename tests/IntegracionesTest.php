<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use Integraciones;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../panel/src/Integraciones.php';

/**
 * Tests de la sonda de integraciones.
 *
 * Integraciones vive en panel/src/, fuera del autoload PSR-4, y se carga con un
 * require_once explicito -- mismo patron que RutasDelRouterTest.
 *
 * NINGUN TEST SALE A LA RED. La peticion se inyecta como callable, asi que la
 * suite corre igual sin internet y no depende de que Brevo o el SII esten
 * arriba hoy. Un test que golpea a un tercero no prueba nuestro codigo: prueba
 * el uptime de otro, y falla los dias que no corresponde.
 *
 * LO QUE SE PROTEGE. Primero, que un OK de alcance NO se confunda con un OK
 * autenticado: son dos afirmaciones muy distintas y la unica proteccion es que
 * el veredicto las nombre distinto. Segundo, que la credencial no salga por
 * ningun lado. Tercero, que una sonda autenticada sin credencial diga "falta la
 * clave" y no "el servicio la rechazo", que llevan a buscar en lugares
 * distintos.
 */
final class IntegracionesTest extends TestCase
{
    /** Una peticion falsa que devuelve el codigo que se le pida. */
    private static function responde(?int $http, string $error = ''): callable
    {
        return static fn (string $url, array $cabeceras): array => [$http, $error];
    }

    // ---- sondas de ALCANCE ------------------------------------------------

    /**
     * EL CASO QUE MOTIVA TODA LA DISTINCION. Los hosts del SII contestan 500 en
     * la raiz y 404 en pangal/rahue estando perfectamente sanos. Tratar eso
     * como falla seria una alarma permanente, y una pantalla que grita sin
     * motivo se deja de mirar.
     *
     * @param int $http
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('codigosQueIgualSignificanAlcanzado')]
    public function testParaAlcanceCualquierRespuestaHttpEsExito(int $http): void
    {
        $r = Integraciones::clasificar('alcance', $http, '', 12);

        self::assertSame('alcanzado', $r['estado'], "HTTP {$http} deberia contar como alcanzado");
        self::assertSame('Responde', $r['titulo']);
    }

    /**
     * @return array<string, array{0:int}>
     */
    public static function codigosQueIgualSignificanAlcanzado(): array
    {
        return [
            '200 maullin wsdl' => [200],
            '302 www4 rcv'     => [302],
            '404 pangal'       => [404],
            '500 apicert'      => [500],
        ];
    }

    /**
     * Un OK de alcance NO puede leerse como que las credenciales sirven. El
     * texto tiene que decirlo, porque es lo unico que separa a esta fila verde
     * de aquella.
     */
    public function testElOkDeAlcanceAclaraQueNoProboCredenciales(): void
    {
        $r = Integraciones::clasificar('alcance', 200, '', 12);

        self::assertStringContainsString('NO comprueba ninguna credencial', $r['detalle']);
    }

    // ---- sondas AUTENTICADAS ---------------------------------------------

    public function testUnDosCientosAutenticadoSiAfirmaQueLaCredencialSirve(): void
    {
        $r = Integraciones::clasificar('autenticada', 200, '', 40);

        self::assertSame('ok', $r['estado']);
        self::assertStringContainsString('la credencial sirve', $r['detalle']);
    }

    public function testCuatroCeroUnoEsCredencialRechazadaYNoServicioCaido(): void
    {
        foreach ([401, 403] as $http) {
            $r = Integraciones::clasificar('autenticada', $http, '', 40);

            self::assertSame('rechazada', $r['estado'], "HTTP {$http}");
            // Importa que diga que el servicio SI contesta: manda a revisar la
            // clave, no la red.
            self::assertStringContainsString('contesta', $r['detalle']);
        }
    }

    public function testUnCodigoRaroNoSeDisfrazaDeExitoNiDeRechazo(): void
    {
        $r = Integraciones::clasificar('autenticada', 503, '', 40);

        self::assertSame('raro', $r['estado']);
    }

    // ---- sin respuesta ---------------------------------------------------

    /**
     * La unica falla inequivoca, y vale igual para los dos tipos de sonda.
     */
    public function testSinRespuestaEsCaidaEnLosDosTiposDeSonda(): void
    {
        foreach (['alcance', 'autenticada'] as $tipo) {
            $r = Integraciones::clasificar($tipo, null, 'Could not resolve host', 8000);

            self::assertSame('caida', $r['estado'], $tipo);
            self::assertStringContainsString('Could not resolve host', $r['detalle']);
        }
    }

    public function testElCeroDeCurlSeTrataComoSinRespuesta(): void
    {
        // curl devuelve 0 cuando no hubo respuesta HTTP. Sin este caso, un 0 se
        // colaria por la rama de "codigo inesperado".
        self::assertSame('caida', Integraciones::clasificar('autenticada', 0, 'timeout', 8000)['estado']);
    }

    // ---- probar() de punta a punta ---------------------------------------

    public function testUnaSondaAutenticadaSinCredencialNoMandaLaPeticion(): void
    {
        $seLlamo = false;
        $espia   = static function () use (&$seLlamo): array {
            $seLlamo = true;
            return [200, ''];
        };

        $r = Integraciones::probar([
            'id'         => 'x',
            'nombre'     => 'X',
            'credencial' => 'VARIABLE_QUE_NO_EXISTE_EN_NINGUN_LADO',
            'sonda'      => ['tipo' => 'autenticada', 'url' => 'https://ejemplo.invalido/', 'auth' => 'bearer'],
        ], $espia);

        self::assertSame('sin_credencial', $r['estado']);
        self::assertFalse($seLlamo, 'no se debe mandar una peticion cuando no hay credencial que probar');
    }

    /**
     * Sin sonda declarada no hay boton ni peticion: probar una integracion que
     * solo se puede ejercitar emitiendo un documento no es un diagnostico.
     */
    public function testSinSondaDeclaradaNoSeProbaNada(): void
    {
        $r = Integraciones::probar(['id' => 'x', 'nombre' => 'X', 'credencial' => null, 'sonda' => null]);

        self::assertSame('sin_sonda', $r['estado']);
    }

    public function testLaCredencialViajaEnLaCabeceraYNoEnLaUrl(): void
    {
        putenv('SONDA_TEST_CLAVE=abc123secreta');

        $urlVista       = null;
        $cabecerasVistas = [];

        $espia = static function (string $url, array $cabeceras) use (&$urlVista, &$cabecerasVistas): array {
            $urlVista        = $url;
            $cabecerasVistas = $cabeceras;
            return [200, ''];
        };

        Integraciones::probar([
            'id'         => 'x',
            'nombre'     => 'X',
            'credencial' => 'SONDA_TEST_CLAVE',
            'sonda'      => ['tipo' => 'autenticada', 'url' => 'https://ejemplo.invalido/v3/account', 'auth' => 'header:api-key'],
        ], $espia);

        self::assertSame('https://ejemplo.invalido/v3/account', $urlVista);
        self::assertStringNotContainsString('abc123secreta', (string) $urlVista, 'la clave NO puede ir en la URL');
        self::assertSame(['api-key: abc123secreta'], $cabecerasVistas);

        putenv('SONDA_TEST_CLAVE');
    }

    public function testElVeredictoNuncaIncluyeLaCredencial(): void
    {
        putenv('SONDA_TEST_CLAVE=abc123secreta');

        $r = Integraciones::probar([
            'id'         => 'x',
            'nombre'     => 'X',
            'credencial' => 'SONDA_TEST_CLAVE',
            'sonda'      => ['tipo' => 'autenticada', 'url' => 'https://ejemplo.invalido/', 'auth' => 'bearer'],
        ], self::responde(401));

        self::assertStringNotContainsString('abc123secreta', implode(' ', array_map('strval', $r)));

        putenv('SONDA_TEST_CLAVE');
    }

    public function testEstadoCredencialDiceSiEstaYDeQueLargoPeroNoElValor(): void
    {
        putenv('SONDA_TEST_CLAVE=abc123secreta');

        $e = Integraciones::estadoCredencial(['credencial' => 'SONDA_TEST_CLAVE']);

        self::assertTrue($e['puesta']);
        self::assertSame(13, $e['largo']);
        self::assertNotContains('abc123secreta', $e);

        putenv('SONDA_TEST_CLAVE');

        $vacia = Integraciones::estadoCredencial(['credencial' => 'SONDA_TEST_CLAVE']);
        self::assertFalse($vacia['puesta']);
        self::assertSame(0, $vacia['largo']);
    }

    public function testUnaUrlConVariableSinDefinirNoSeGolpea(): void
    {
        putenv('SONDA_TEST_URL');

        $r = Integraciones::probar([
            'id' => 'x', 'nombre' => 'X', 'credencial' => null,
            'sonda' => ['tipo' => 'autenticada', 'url' => '{SONDA_TEST_URL}/health', 'auth' => null],
        ], self::responde(200));

        self::assertSame('sin_config', $r['estado']);
    }

    public function testUnaUrlConVariableSeResuelveSinDuplicarLaBarra(): void
    {
        putenv('SONDA_TEST_URL=http://sinergia_motor/');

        $urlVista = null;
        $espia    = static function (string $url) use (&$urlVista): array {
            $urlVista = $url;
            return [200, ''];
        };

        Integraciones::probar([
            'id' => 'x', 'nombre' => 'X', 'credencial' => null,
            'sonda' => ['tipo' => 'autenticada', 'url' => '{SONDA_TEST_URL}/health', 'auth' => null],
        ], $espia);

        self::assertSame('http://sinergia_motor/health', $urlVista);

        putenv('SONDA_TEST_URL');
    }

    // ---- el catalogo real -------------------------------------------------

    /**
     * El catalogo DE VERDAD, no uno inventado: si alguien agrega una
     * integracion con una sonda mal declarada, se entera aca y no en pantalla.
     */
    public function testElCatalogoRealEstaBienFormado(): void
    {
        /** @var list<array<string, mixed>> $integraciones */
        $integraciones = require __DIR__ . '/../panel/datos/integraciones.php';

        self::assertNotSame([], $integraciones);

        $ids = [];

        foreach ($integraciones as $i) {
            foreach (['id', 'nombre', 'para_que', 'donde', 'host', 'nota'] as $campo) {
                self::assertArrayHasKey($campo, $i, "falta '{$campo}'");
                self::assertNotSame('', trim((string) $i[$campo]), "'{$campo}' vacio en '{$i['id']}'");
            }

            self::assertArrayHasKey('credencial', $i, "'{$i['id']}' no declara 'credencial' (usa null si no lleva)");
            self::assertArrayHasKey('sonda', $i);

            // El id va en un value de formulario y se compara contra el
            // catalogo: se mantiene simple a proposito.
            self::assertMatchesRegularExpression('/^[a-z0-9-]+$/', (string) $i['id']);
            self::assertNotContains($i['id'], $ids, "id repetido: '{$i['id']}'");
            $ids[] = $i['id'];

            if ($i['sonda'] === null) {
                continue;
            }

            self::assertContains($i['sonda']['tipo'], ['autenticada', 'alcance'], "tipo de sonda raro en '{$i['id']}'");
            self::assertMatchesRegularExpression(
                '#^(https://|\{[A-Z_]+\})#',
                (string) $i['sonda']['url'],
                "la sonda de '{$i['id']}' tiene que ser https o una variable de entorno"
            );

            // Una sonda que manda credencial TIENE que declarar cual.
            if (($i['sonda']['auth'] ?? null) !== null) {
                self::assertNotNull($i['credencial'], "'{$i['id']}' manda auth sin declarar credencial");
                self::assertMatchesRegularExpression('/^(bearer|header:.+)$/', (string) $i['sonda']['auth']);
            }
        }
    }

    public function testTodoEstadoTieneUnaClaseQueElCssDefine(): void
    {
        $estados = ['ok', 'alcanzado', 'caida', 'rechazada', 'raro', 'sin_credencial', 'sin_sonda', 'sin_config'];

        foreach ($estados as $e) {
            self::assertContains(Integraciones::claseEstado($e), ['', 'ok', 'warn', 'err'], $e);
        }
    }
}
