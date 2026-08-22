<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\TestCase;
use RutasDelRouter;

require_once __DIR__ . '/../panel/src/RutasDelRouter.php';

/**
 * Tests del extractor de rutas del router.
 *
 * RutasDelRouter vive en panel/src/, fuera del autoload PSR-4, y se carga con
 * un require_once explicito -- mismo patron que los otros tests de panel/.
 *
 * Lo que mas importa aqui es lo que NO tiene que reconocer: el router tiene
 * lineas que empiezan igual que un despacho (el corte por demo, la validacion
 * de CSRF) y no son rutas. Contarlas inflaria el informe de cobertura con
 * entradas falsas, y un informe con ruido es un informe que nadie mira.
 *
 * Ademas se corre contra el router DE VERDAD, para que el test se entere si
 * alguien escribe un despacho de una forma que el extractor no reconoce.
 */
final class RutasDelRouterTest extends TestCase
{
    public function testReconoceLasDosFormasDeDespacho(): void
    {
        $fuente = <<<'PHP'
        <?php
        if ($metodo === 'GET' && $ruta === '/maestros/clientes') {
            handleClientesListar();
        }
        if ($metodo === 'POST' && preg_match('#^/configuracion/roles/(\d+)/editar$#', $ruta, $mRol)) {
            handleRolesEditarPost((int) $mRol[1]);
        }
        PHP;

        $rutas = RutasDelRouter::extraer($fuente);

        $this->assertCount(2, $rutas);
        $this->assertContains(
            ['metodo' => 'GET', 'ruta' => '/maestros/clientes', 'esPatron' => false],
            $rutas
        );
        $this->assertContains(
            ['metodo' => 'POST', 'ruta' => '#^/configuracion/roles/(\d+)/editar$#', 'esPatron' => true],
            $rutas
        );
    }

    /**
     * El router tiene tres lineas que arrancan con $metodo y NO son rutas: el
     * corte por cuenta demo, la verificacion de CSRF y el bucle de patrones de
     * exigirPermisoDeRuta(). Ninguna debe entrar al informe.
     */
    public function testNoConfundeConLineasQueNoSonDespachos(): void
    {
        $fuente = <<<'PHP'
        <?php
        if ($metodo === 'POST' && sesionEsDemo()) {
            cortarPorDemo();
        }
        if ($metodo === 'POST' && ! Csrf::validar((string) ($_POST['csrf_token'] ?? ''))) {
            exit;
        }
        if ($metodo === $m && preg_match($patron, $ruta) === 1) {
            exigirPermiso($pdo, $cuentaId, $modulo, $accion);
        }
        PHP;

        $this->assertSame([], RutasDelRouter::extraer($fuente));
    }

    public function testSacaElPrefijoFijoDeUnPatron(): void
    {
        $this->assertSame('/admin/tenants/', RutasDelRouter::prefijoLiteral('#^/admin/tenants/(\d+)$#'));
        $this->assertSame('/activar/', RutasDelRouter::prefijoLiteral('#^/activar/[0-9a-f]{64}$#'));
        $this->assertSame('/informes/', RutasDelRouter::prefijoLiteral('#^/informes/([a-z-]+)$#'));
    }

    /**
     * Sin ancla ^ el patron puede coincidir en cualquier parte, asi que no hay
     * prefijo garantizado. Devolver cadena vacia deja la ruta en la lista de no
     * declaradas: un falso positivo se revisa, un falso negativo esconde
     * exactamente lo que se busca.
     */
    public function testAnteLaDudaNoAfirmaUnPrefijo(): void
    {
        $this->assertSame('', RutasDelRouter::prefijoLiteral('#/admin/algo$#'));
        $this->assertSame('', RutasDelRouter::prefijoLiteral('no soy un patron'));
        $this->assertSame('', RutasDelRouter::prefijoLiteral(''));
    }

    /** Un patron sin ninguna parte fija tampoco puede afirmar un prefijo. */
    public function testPatronSinParteFija(): void
    {
        $this->assertSame('', RutasDelRouter::prefijoLiteral('#^(\d+)$#'));
    }

    /**
     * El generador de URLs de ejemplo es lo que hace confiable al bloque de
     * cobertura: sin el habria que comparar los textos de dos regex, y dos
     * regex escritos distinto pueden cubrir las mismas URLs.
     */
    public function testGeneraUnaUrlDeEjemploParaCadaConstruccionQueUsaElRouter(): void
    {
        $casos = [
            '#^/admin/tenants/(\d+)$#'                                   => '/admin/tenants/1',
            '#^/ventas/panel-emision/(\d+)/(\d+)$#'                      => '/ventas/panel-emision/1/1',
            '#^/informes/([a-z-]+)$#'                                    => '/informes/a',
            '#^/informes/([a-z-]+)/(pdf|excel)$#'                        => '/informes/a/pdf',
            '#^/certificacion/etapa/([^/]+)$#'                           => '/certificacion/etapa/a',
            '#^/certificacion/intercambio/(acuse|resultado|recibos)\.xml$#' => '/certificacion/intercambio/acuse.xml',
            '#^/compras/proveedores/(\d+)/(activar|desactivar)$#'        => '/compras/proveedores/1/activar',
            '#^/activar/([0-9a-f]{64})$#'                                => '/activar/' . str_repeat('a', 64),
        ];

        foreach ($casos as $patron => $esperada) {
            $this->assertSame($esperada, RutasDelRouter::muestraDePatron($patron), "patron: {$patron}");
        }
    }

    /**
     * EL CASO QUE MOTIVO TODO ESTO. El router captura el token
     * ('([0-9a-f]{64})') y PATRONES_PUBLICOS solo decide ('[0-9a-f]{64}'). Son
     * la misma ruta escrita distinto: comparando los textos daba no declarada,
     * y con una URL de ejemplo el gate la reconoce igual que en produccion.
     */
    public function testDosRegexDistintosQueCubrenLaMismaUrl(): void
    {
        $delRouter  = '#^/activar/([0-9a-f]{64})$#';
        $declarado  = '#^/activar/[0-9a-f]{64}$#';

        $this->assertNotSame($delRouter, $declarado, 'el punto del test es que los textos difieren');

        $muestra = RutasDelRouter::muestraDePatron($delRouter);
        $this->assertNotNull($muestra);
        $this->assertSame(1, preg_match($declarado, $muestra), 'la URL de ejemplo debe casar con lo declarado');
    }

    /**
     * Ante una construccion que no sabe generar, devuelve null en vez de
     * inventar: el informe dice "no se pudo determinar", que se investiga, en
     * lugar de un veredicto falso, que se cree.
     */
    public function testAnteLoQueNoSabeGenerarDevuelveNull(): void
    {
        $this->assertNull(RutasDelRouter::muestraDePatron('#/sin-ancla/(\d+)$#'));
        $this->assertNull(RutasDelRouter::muestraDePatron('#^/con-lookahead/(?=x)$#'));
        $this->assertNull(RutasDelRouter::muestraDePatron('no soy un patron'));
    }

    /** La muestra se valida contra su propio patron antes de devolverse. */
    public function testLaMuestraSiempreCasaConSuPatron(): void
    {
        $fuente = file_get_contents(__DIR__ . '/../panel/public/index.php');
        $this->assertNotFalse($fuente);

        $conPatron = array_filter(RutasDelRouter::extraer($fuente), static fn (array $r): bool => $r['esPatron']);
        $this->assertNotEmpty($conPatron);

        foreach ($conPatron as $ruta) {
            $muestra = RutasDelRouter::muestraDePatron($ruta['ruta']);
            $this->assertNotNull($muestra, "sin muestra para {$ruta['ruta']}");
            $this->assertSame(1, preg_match($ruta['ruta'], $muestra), "la muestra no casa: {$ruta['ruta']}");
        }
    }

    /**
     * Contra el router real: si alguien agrega un despacho con una forma que el
     * extractor no reconoce, este test lo delata en vez de dejar que el informe
     * de cobertura mienta por omision.
     */
    public function testContraElRouterReal(): void
    {
        $fuente = file_get_contents(__DIR__ . '/../panel/public/index.php');
        $this->assertNotFalse($fuente);

        $rutas = RutasDelRouter::extraer($fuente);

        // Toda linea que ARRANCA como un despacho tiene que estar reconocida o
        // ser una de las excepciones conocidas. Se enumeran en vez de contarlas:
        // un numero fijo hay que actualizarlo a mano cada vez que se agrega una
        // ruta, y eso lo convierte en un test que se "arregla" subiendo el
        // numero sin mirar que aparecio.
        preg_match_all('/^\s*if \(\$metodo.*$/m', $fuente, $lineas);

        // Las que arrancan igual y NO son despachos de ruta: el corte por
        // cuenta demo, la verificacion central de CSRF, y los dos recorridos de
        // PERMISOS_RUTA_PATRON (el del gate y el del informe de cobertura).
        $noSonDespachos = static fn (string $l): bool => str_contains($l, 'sesionEsDemo()')
            || str_contains($l, 'Csrf::validar')
            || str_contains($l, 'Auth::viendoCuentaId()')
            || str_contains($l, '$metodo === $m');

        $despachos = array_filter($lineas[0], static fn (string $l): bool => ! $noSonDespachos($l));

        $this->assertSame(
            count($despachos),
            count($rutas),
            'Hay lineas que arrancan como despacho y el extractor no reconocio. '
            . 'O aparecio una forma nueva de declarar rutas, o una linea que no es ruta: '
            . 'revisar RutasDelRouter antes de confiar en /admin/roles-permisos.'
        );

        // Y algunas rutas conocidas tienen que estar.
        $claves = array_map(static fn (array $r): string => $r['metodo'] . ' ' . $r['ruta'], $rutas);
        $this->assertContains('GET /panel', $claves);
        $this->assertContains('GET /admin/tenants', $claves);
        $this->assertContains('POST /admin/tenants/suspender', $claves);
    }
}
