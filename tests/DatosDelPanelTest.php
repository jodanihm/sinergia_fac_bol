<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\TestCase;
use RutasDelRouter;

require_once __DIR__ . '/../panel/src/RutasDelRouter.php';

/**
 * Tests de los archivos de panel/datos/.
 *
 * POR QUE UN TEST PARA UN ARCHIVO DE TEXTO. Los cuatro archivos de datos se
 * mantienen a mano, y dos de ellos -- flujos.php y documentos.php -- PROMETEN
 * RUTAS: "esto se hace en /caf-produccion", "este PDF sale de
 * /compras/ordenes/{id}/pdf". Esa promesa es lo unico valioso de la pagina,
 * porque es lo que convierte una explicacion en una guia utilizable.
 *
 * Y es una promesa que se rompe sola. El dia que alguien renombre una ruta, el
 * archivo de datos sigue diciendo la vieja, la pagina la muestra con toda
 * confianza, y nadie se entera hasta que alguien la sigue y encuentra un 404.
 * Una documentacion que miente es peor que no tenerla: se le cree.
 *
 * Estos tests atan los datos al router de verdad.
 */
final class DatosDelPanelTest extends TestCase
{
    /** @var list<array{metodo:string, ruta:string, esPatron:bool}>|null */
    private static ?array $rutasDelRouter = null;

    /** @return list<array{metodo:string, ruta:string, esPatron:bool}> */
    private static function rutas(): array
    {
        if (self::$rutasDelRouter === null) {
            $fuente = (string) file_get_contents(__DIR__ . '/../panel/public/index.php');
            self::$rutasDelRouter = RutasDelRouter::extraer($fuente);
        }

        return self::$rutasDelRouter;
    }

    /**
     * True si el router despacha una ruta con esta forma.
     *
     * LOS PLACEHOLDERS NO SE RELLENAN CON UN VALOR CUALQUIERA. El primer
     * intento cambiaba {tipo} por '1' y comparaba, y fallaba con
     * /certificacion/intercambio/{tipo}.xml: el router solo acepta ahi
     * 'acuse', 'resultado' o 'recibos', asi que la URL inventada no casaba con
     * nada aunque la ruta documentada fuera correcta. El test habria obligado a
     * escribir en la documentacion un valor de ejemplo en vez del nombre del
     * parametro, que es peor de leer.
     *
     * SE COMPARA EN LAS DOS DIRECCIONES, y hacen falta las dos:
     *
     *   a) muestra de la ruta DOCUMENTADA contra el patron del router. Resuelve
     *      /informes/{informe}/excel, que existe porque el router acepta
     *      (pdf|excel) -- pero la URL de ejemplo del router elige siempre la
     *      primera alternativa, 'pdf', asi que por el otro lado no se ve.
     *
     *   b) muestra del patron del ROUTER contra la ruta documentada. Resuelve
     *      /certificacion/intercambio/{tipo}.xml, donde el router solo acepta
     *      tres valores concretos y una muestra inventada del lado documentado
     *      no caeria en ninguno.
     *
     * La pregunta que contesta el test es "existe una ruta real de esta forma",
     * y para eso la compatibilidad en cualquiera de los dos sentidos alcanza.
     */
    private static function elRouterLaDespacha(string $ruta): bool
    {
        $partes = array_map(
            static fn (string $p): string => preg_quote($p, '#'),
            preg_split('/\{[a-z_]+\}/i', $ruta) ?: [$ruta]
        );
        $patronDocumentado = '#^' . implode('[^/]+', $partes) . '$#';
        $muestraDocumentada = preg_replace('/\{[a-z_]+\}/i', 'x', $ruta) ?? $ruta;

        foreach (self::rutas() as $r) {
            if (! $r['esPatron']) {
                if (preg_match($patronDocumentado, $r['ruta']) === 1) {
                    return true;
                }
                continue;
            }

            // (a) la muestra documentada casa con el patron del router
            if (@preg_match($r['ruta'], $muestraDocumentada) === 1) {
                return true;
            }

            // (b) la muestra del router casa con la forma documentada
            $muestraDelRouter = RutasDelRouter::muestraDePatron($r['ruta']);
            if ($muestraDelRouter !== null && preg_match($patronDocumentado, $muestraDelRouter) === 1) {
                return true;
            }
        }

        return false;
    }

    public function testCadaPasoDeUnFlujoApuntaAUnaRutaQueExiste(): void
    {
        $flujos = require __DIR__ . '/../panel/datos/flujos.php';
        $this->assertNotEmpty($flujos);

        foreach ($flujos as $flujo) {
            $this->assertNotEmpty($flujo['pasos'], "el flujo {$flujo['id']} no tiene pasos");

            foreach ($flujo['pasos'] as $paso) {
                $this->assertTrue(
                    self::elRouterLaDespacha($paso['donde']),
                    "El flujo '{$flujo['id']}' manda a {$paso['donde']} y el router no despacha esa ruta. "
                    . 'O se renombro la ruta y hay que corregir panel/datos/flujos.php, o el paso esta mal escrito.'
                );
            }
        }
    }

    public function testCadaDocumentoApuntaAUnaRutaQueExiste(): void
    {
        $documentos = require __DIR__ . '/../panel/datos/documentos.php';
        $this->assertNotEmpty($documentos);

        foreach ($documentos as $doc) {
            // Una entrada puede nombrar dos rutas (el PDF y el Excel de los
            // informes); se separan por dos espacios en el texto.
            foreach (preg_split('/\s{2,}(?:y\s+)?/', trim((string) $doc['desde'])) ?: [] as $ruta) {
                $ruta = trim($ruta);
                if ($ruta === '') {
                    continue;
                }
                $this->assertTrue(
                    self::elRouterLaDespacha($ruta),
                    "El documento '{$doc['nombre']}' dice salir de {$ruta} y el router no despacha esa ruta."
                );
            }
        }
    }

    /**
     * El estado 'listo' de un DTE afirma que el motor sabe imprimirlo. Esa
     * lista vive en TIPOS_PERMITIDOS_PDF, en el otro front controller, y si el
     * catalogo dijera que un tipo esta listo sin estar ahi, la pagina estaria
     * prometiendo un PDF que no existe.
     */
    public function testLosTiposDteMarcadosComoListosEstanEnElMotor(): void
    {
        $motor = (string) file_get_contents(__DIR__ . '/../public/index.php');
        $this->assertMatchesRegularExpression(
            '/const TIPOS_PERMITIDOS_PDF = \[([\d, ]+)\];/',
            $motor,
            'no se encontro TIPOS_PERMITIDOS_PDF en el motor'
        );
        preg_match('/const TIPOS_PERMITIDOS_PDF = \[([\d, ]+)\];/', $motor, $m);
        $permitidos = array_map('intval', explode(',', $m[1]));

        $documentos = require __DIR__ . '/../panel/datos/documentos.php';

        foreach ($documentos as $doc) {
            // Solo los DTE llevan el codigo entre parentesis en el nombre.
            if (preg_match('/\((\d{2})\)$/', (string) $doc['nombre'], $codigo) !== 1) {
                continue;
            }
            if ($doc['estado'] !== 'listo') {
                continue;
            }
            $this->assertContains(
                (int) $codigo[1],
                $permitidos,
                "'{$doc['nombre']}' figura como listo pero su tipo no esta en TIPOS_PERMITIDOS_PDF"
            );
        }
    }

    /** Los cuatro archivos son datos puros: un array y nada mas. */
    public function testLosArchivosDeDatosSoloDevuelvenUnArray(): void
    {
        foreach (['changelog', 'pendientes', 'flujos', 'documentos'] as $archivo) {
            $ruta = __DIR__ . '/../panel/datos/' . $archivo . '.php';
            $this->assertFileExists($ruta);

            $contenido = (string) file_get_contents($ruta);
            $this->assertStringNotContainsString('Db::', $contenido, "{$archivo}.php no debe consultar la base");

            // "no imprime nada" se COMPRUEBA incluyendo el archivo y midiendo
            // su salida, no buscando la palabra echo en el texto: 'derecho' y
            // 'hecho' la contienen, y ese primer intento fallaba contra prosa
            // perfectamente valida.
            ob_start();
            $datos  = require $ruta;
            $salida = (string) ob_get_clean();

            $this->assertSame('', $salida, "{$archivo}.php no debe imprimir nada al cargarse");
            $this->assertIsArray($datos, "{$archivo}.php debe devolver un array");
        }
    }

    /** El changelog se lee de arriba hacia abajo: lo mas nuevo primero. */
    public function testElChangelogEstaOrdenadoDeLoMasNuevoALoMasViejo(): void
    {
        $entradas = require __DIR__ . '/../panel/datos/changelog.php';
        $this->assertNotEmpty($entradas);

        $versiones = array_map(static fn (array $e): string => (string) $e['version'], $entradas);
        $ordenadas = $versiones;
        usort($ordenadas, static fn (string $a, string $b): int => version_compare($b, $a));

        $this->assertSame($ordenadas, $versiones, 'el changelog debe ir de la version mas alta a la mas baja');

        foreach ($entradas as $e) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $e['fecha']);
            $this->assertNotEmpty($e['items'], "la entrada v{$e['version']} no tiene items");
        }
    }

    /** Estados y tipos de pendientes.php, contra los valores que la vista sabe pintar. */
    public function testLosPendientesUsanTiposYEstadosConocidos(): void
    {
        foreach (require __DIR__ . '/../panel/datos/pendientes.php' as $item) {
            $this->assertContains($item['tipo'], ['idea', 'pendiente'], "tipo raro en '{$item['titulo']}'");
            $this->assertContains($item['estado'], ['nuevo', 'en_pausa', 'en_curso'], "estado raro en '{$item['titulo']}'");
            $this->assertNotEmpty($item['detalle'], "'{$item['titulo']}' no explica por que quedo pendiente");
        }
    }
}
