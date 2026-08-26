<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use ActividadAdmin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../panel/src/ActividadAdmin.php';

/**
 * Tests de la bitacora del panel de control (/admin/actividad).
 *
 * ActividadAdmin vive en panel/src/, fuera del autoload PSR-4, y se carga con
 * un require_once explicito -- mismo patron que RutasDelRouterTest.
 *
 * DOS COSAS QUE HAY QUE VIGILAR AQUI Y NO EN OTRO LADO:
 *
 *   1. QUE NO SE ESCAPE UN SECRETO. La tabla guarda el query string, y un
 *      parametro sensible en la URL terminaria copiado a una pantalla que se
 *      mira. El filtro es la unica barrera y tiene que valer aunque la clave
 *      venga con mayusculas o como parte de un nombre mas largo.
 *
 *   2. QUE NINGUNA PANTALLA DE /admin QUEDE FUERA. Eso no depende de esta
 *      clase sino de DONDE la llama el router, asi que el ultimo test se corre
 *      contra el fuente de verdad: el enganche tiene que estar antes del primer
 *      despacho, que es lo que hace que una pantalla nueva nazca registrada.
 */
final class ActividadAdminTest extends TestCase
{
    public function testReconoceLasRutasDelPanelDeControl(): void
    {
        self::assertTrue(ActividadAdmin::esDelPanel('/admin'));
        self::assertTrue(ActividadAdmin::esDelPanel('/admin/tenants'));
        self::assertTrue(ActividadAdmin::esDelPanel('/admin/login'));
    }

    /**
     * El prefijo lleva barra a proposito: sin ella, una ruta futura que empiece
     * igual quedaria registrada en la bitacora del panel sin que nadie lo
     * hubiera pedido.
     */
    public function testNoSeQuedaConRutasQueSoloEmpiezanIgual(): void
    {
        self::assertFalse(ActividadAdmin::esDelPanel('/administracion'));
        self::assertFalse(ActividadAdmin::esDelPanel('/panel'));
        self::assertFalse(ActividadAdmin::esDelPanel('/'));
    }

    public function testElMetodoDecideSiEsAccionOLectura(): void
    {
        self::assertSame('lectura', ActividadAdmin::efecto('GET'));
        self::assertSame('accion', ActividadAdmin::efecto('POST'));
        self::assertSame('accion', ActividadAdmin::efecto('delete'));
    }

    public function testLosParametrosSeGuardanLegibles(): void
    {
        self::assertSame('', ActividadAdmin::parametros(''));
        self::assertSame('sql=045', ActividadAdmin::parametros('sql=045'));
        self::assertSame('q=dte emitido&pagina=2', ActividadAdmin::parametros('q=dte%20emitido&pagina=2'));
    }

    /**
     * @param string $queryString
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('parametrosSensibles')]
    public function testElValorDeUnParametroSensibleNuncaSeGuarda(string $queryString, string $secreto): void
    {
        $guardado = ActividadAdmin::parametros($queryString);

        self::assertStringNotContainsString($secreto, $guardado);
        self::assertStringContainsString('(oculto)', $guardado);
    }

    /** @return array<string, array{0:string, 1:string}> */
    public static function parametrosSensibles(): array
    {
        return [
            'token'            => ['token=abc123', 'abc123'],
            'clave'            => ['clave=hunter2', 'hunter2'],
            'password'         => ['password=hunter2', 'hunter2'],
            'csrf'             => ['csrf_token=deadbeef', 'deadbeef'],
            'mayusculas'       => ['TOKEN=abc123', 'abc123'],
            'clave compuesta'  => ['api_key_secreta=xyz', 'xyz'],
        ];
    }

    /**
     * El corte se marca. Un valor recortado en silencio se lee despues como el
     * valor completo, y en una auditoria eso es peor que no tenerlo.
     */
    public function testUnQueryStringEnormeSeRecortaConMarca(): void
    {
        $guardado = ActividadAdmin::parametros('q=' . str_repeat('a', 900));

        self::assertSame(500, strlen($guardado));
        self::assertStringEndsWith('...', $guardado);
    }

    public function testLaIpPrefiereLaQuePoneCloudflare(): void
    {
        self::assertSame('203.0.113.7', ActividadAdmin::ip([
            'HTTP_CF_CONNECTING_IP' => '203.0.113.7',
            'HTTP_X_FORWARDED_FOR'  => '198.51.100.1',
            'REMOTE_ADDR'           => '172.18.0.4',
        ]));
    }

    public function testDeXForwardedForSeQuedaConElPrimero(): void
    {
        self::assertSame('198.51.100.1', ActividadAdmin::ip([
            'HTTP_X_FORWARDED_FOR' => '198.51.100.1, 172.18.0.4',
            'REMOTE_ADDR'          => '172.18.0.4',
        ]));
    }

    public function testCaeAremoteAddrYDescartaLoQueNoEsUnaIp(): void
    {
        self::assertSame('172.18.0.4', ActividadAdmin::ip(['REMOTE_ADDR' => '172.18.0.4']));

        // Las tres cabeceras son texto que llega de afuera: una falsificada
        // meteria cualquier cosa en una tabla de auditoria.
        self::assertSame('172.18.0.4', ActividadAdmin::ip([
            'HTTP_CF_CONNECTING_IP' => "no soy una ip'; DROP TABLE",
            'REMOTE_ADDR'           => '172.18.0.4',
        ]));
        self::assertNull(ActividadAdmin::ip([]));
    }

    public function testElSemaforoDistingueRechazoDeError(): void
    {
        self::assertSame('ok', ActividadAdmin::claseHttp(200));
        self::assertSame('ok', ActividadAdmin::claseHttp(302));
        self::assertSame('warn', ActividadAdmin::claseHttp(403));
        self::assertSame('err', ActividadAdmin::claseHttp(500));
    }

    /**
     * EL TEST QUE SOSTIENE LA COBERTURA. Se corre contra el router de verdad:
     * el enganche tiene que estar UNA vez y antes del primer despacho de una
     * ruta de /admin. Puesto ahi, una pantalla de superadmin nueva nace
     * registrada sin que su autor sepa que la bitacora existe; puesto despues,
     * o repartido por handler, la primera que alguien agregue va a faltar.
     */
    public function testElEnganchePrecedeAlPrimerDespachoDeAdmin(): void
    {
        $lineas = (array) file(__DIR__ . '/../panel/public/index.php', FILE_IGNORE_NEW_LINES);

        $enganche = null;
        $despacho = null;

        foreach ($lineas as $i => $linea) {
            if ($enganche === null && str_contains((string) $linea, 'ActividadAdmin::esDelPanel($ruta)')) {
                $enganche = $i + 1;
            }
            if ($despacho === null && preg_match("#^if \(\\\$metodo === '[A-Z]+' && .*'/admin#", (string) $linea) === 1) {
                $despacho = $i + 1;
            }
        }

        self::assertNotNull($enganche, 'el router ya no engancha la bitacora del panel');
        self::assertNotNull($despacho, 'no se reconocio ningun despacho de /admin en el router');
        self::assertLessThan(
            $despacho,
            $enganche,
            "el enganche de la bitacora (linea {$enganche}) quedo DESPUES del primer despacho de /admin "
            . "(linea {$despacho}): las pantallas despachadas antes no se registran"
        );
    }
}
