<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tests de los iconos del sidebar del panel de control.
 *
 * SE CORREN CONTRA LOS ARCHIVOS DE VERDAD, no contra un ejemplo: las claves del
 * menu se extraen del propio _nav.php y los iconos se cargan de _iconos.php.
 * Mismo criterio que RutasDelRouterTest, y por el mismo motivo: lo que hay que
 * atrapar es que alguien agregue un item al menu y no le ponga icono. Un test
 * sobre datos inventados no se entera nunca de eso.
 *
 * NO SE COMPRUEBA QUE EL DIBUJO SE VEA BIEN -- eso no lo puede decir un test --
 * sino las tres propiedades que si son verificables y que, de romperse, dejan
 * el menu peor que sin iconos:
 *
 *   1. Que no falte ninguno. Un item sin icono queda con el texto corrido
 *      respecto a los demas y se lee como un error de carga.
 *   2. Que el SVG sea XML valido. Una etiqueta sin cerrar no se ve como un
 *      icono roto: el navegador se come el resto del marcado y desaparece
 *      medio menu.
 *   3. Que ninguno traiga un color fijo. Es lo que sostiene que el icono siga
 *      al texto en hover y en activo; un '#8b97a3' copiado de algun lado
 *      funciona en reposo y se queda gris cuando el item se ilumina.
 */
final class IconosDelSidebarTest extends TestCase
{
    /** @return array<string, string> */
    private static function iconos(): array
    {
        return require __DIR__ . '/../panel/views/partials/admin/_iconos.php';
    }

    /**
     * Las claves de los items del menu, sacadas del propio _nav.php.
     *
     * @return list<string>
     */
    private static function clavesDelMenu(): array
    {
        $fuente = (string) file_get_contents(__DIR__ . '/../panel/views/partials/admin/_nav.php');

        // Las entradas tienen la forma  'clave' => ['/ruta', 'Etiqueta'],
        preg_match_all("/'([a-z0-9-]+)'\s*=>\s*\['\/[^']*',/", $fuente, $m);

        return $m[1];
    }

    public function testElMenuSeSigueLeyendoDesdeElArchivo(): void
    {
        // Si _nav.php cambia de forma, el resto de los tests pasarian en vacio
        // sin comprobar nada. Este los sostiene.
        self::assertGreaterThanOrEqual(10, count(self::clavesDelMenu()), 'no se reconocieron los items de _nav.php');
    }

    public function testCadaItemDelMenuTieneSuIcono(): void
    {
        $iconos = self::iconos();

        foreach (self::clavesDelMenu() as $clave) {
            self::assertArrayHasKey($clave, $iconos, "el item '{$clave}' del sidebar no tiene icono");
            self::assertNotSame('', trim($iconos[$clave]), "el icono de '{$clave}' esta vacio");
        }
    }

    /**
     * Al reves que el anterior: un icono que no corresponde a ningun item es
     * una pantalla que se borro y dejo basura, o una clave mal escrita que hace
     * que el item de verdad se quede sin dibujo.
     */
    public function testNoSobraNingunIcono(): void
    {
        $claves = self::clavesDelMenu();

        foreach (array_keys(self::iconos()) as $clave) {
            self::assertContains($clave, $claves, "el icono '{$clave}' no corresponde a ningun item del sidebar");
        }
    }

    public function testCadaIconoEsXmlValido(): void
    {
        libxml_use_internal_errors(true);

        foreach (self::iconos() as $clave => $svg) {
            $doc = simplexml_load_string('<svg xmlns="http://www.w3.org/2000/svg">' . $svg . '</svg>');
            libxml_clear_errors();

            self::assertNotFalse($doc, "el icono '{$clave}' no es XML valido; una etiqueta sin cerrar se come el resto del menu");
        }

        libxml_use_internal_errors(false);
    }

    /**
     * @param string $svg
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('cadaIcono')]
    public function testNingunIconoTraeUnColorFijo(string $clave, string $svg): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/#[0-9a-fA-F]{3,6}/',
            $svg,
            "el icono '{$clave}' trae un color en hexadecimal: dejaria de seguir al texto en hover y en activo"
        );

        // fill="none" es lo esperado y lo pone el envoltorio; cualquier otro
        // fill pinta el interior de un color que no sigue al tema.
        self::assertDoesNotMatchRegularExpression(
            '/fill="(?!none)/',
            $svg,
            "el icono '{$clave}' declara un fill propio"
        );

        self::assertStringNotContainsString('stroke="', $svg, "el icono '{$clave}' declara su propio stroke");
    }

    /**
     * @return array<string, array{0:string, 1:string}>
     */
    public static function cadaIcono(): array
    {
        $casos = [];

        foreach (self::iconos() as $clave => $svg) {
            $casos[$clave] = [$clave, $svg];
        }

        return $casos;
    }

    /**
     * El envoltorio se escribe UNA vez en _nav.php. Si alguien lo mueve a cada
     * icono, los tamanos empiezan a divergir sin que nada falle.
     */
    public function testElEnvoltorioViveEnElNavYNoEnCadaIcono(): void
    {
        $nav = (string) file_get_contents(__DIR__ . '/../panel/views/partials/admin/_nav.php');

        self::assertStringContainsString('viewBox="0 0 24 24"', $nav);
        self::assertStringContainsString('stroke="currentColor"', $nav);
        self::assertStringContainsString('aria-hidden="true"', $nav);

        foreach (self::iconos() as $clave => $svg) {
            self::assertStringNotContainsString('<svg', $svg, "el icono '{$clave}' trae su propio <svg>");
            self::assertStringNotContainsString('viewBox', $svg, "el icono '{$clave}' trae su propio viewBox");
        }
    }
}
