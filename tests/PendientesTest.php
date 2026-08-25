<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\TestCase;
use Pendientes;

require_once __DIR__ . '/../panel/src/Pendientes.php';

/**
 * Tests del backlog: catalogo de valores y armado de filtros.
 *
 * Pendientes vive en panel/src/, fuera del autoload PSR-4, y se carga con un
 * require_once explicito -- mismo patron que RutasDelRouterTest.
 *
 * DOS COSAS SE PROTEGEN AQUI Y SON DE NATURALEZA DISTINTA.
 *
 * La primera es que NINGUN VALOR DEL REQUEST LLEGUE AL SQL. Los filtros se
 * comparan contra una lista blanca y lo que no esta se descarta; el test lo
 * comprueba con lo que un atacante escribiria y tambien con lo aburrido: un
 * parametro vacio, un array donde se espera un texto, una URL vieja con un
 * valor que ya no existe. Esos casos aburridos son los que pasan de verdad.
 *
 * La segunda es que EL CATALOGO DE PHP Y EL ENUM DE LA MIGRACION NO SE
 * SEPAREN. Son dos copias de la misma lista -- se explica en la cabecera de
 * Pendientes por que se acepta la duplicacion -- y el dia que alguien agregue
 * un valor en la base y no aqui, el filtro dejaria de ofrecerlo sin que nada
 * falle. El ultimo test lee el .sql de verdad y compara.
 */
final class PendientesTest extends TestCase
{
    // ---- filtros ---------------------------------------------------------

    public function testSinParametrosSeMuestraLoQueFaltaPorHacer(): void
    {
        $f = Pendientes::filtros([]);

        self::assertSame('sin_cerrar', $f['estado']);
        self::assertNull($f['area']);
        self::assertNull($f['categoria']);
        self::assertNull($f['prioridad']);
        self::assertSame('', $f['q']);
    }

    public function testLosValoresValidosPasan(): void
    {
        $f = Pendientes::filtros([
            'area'      => 'motor',
            'categoria' => 'seguridad',
            'prioridad' => 'P1',
            'estado'    => 'bloqueado',
            'q'         => '  certificado  ',
        ]);

        self::assertSame('motor', $f['area']);
        self::assertSame('seguridad', $f['categoria']);
        self::assertSame('P1', $f['prioridad']);
        self::assertSame('bloqueado', $f['estado']);
        self::assertSame('certificado', $f['q'], 'el texto de busqueda se recorta');
    }

    public function testUnValorQueNoEstaEnLaListaSeDescartaEnSilencio(): void
    {
        // Una URL vieja con un area que ya no existe tiene que mostrar la lista
        // completa, no una pantalla de error.
        $f = Pendientes::filtros(['area' => 'frontend', 'prioridad' => 'P9']);

        self::assertNull($f['area']);
        self::assertNull($f['prioridad']);
    }

    public function testUnArrayDondeSeEsperaTextoNoRompe(): void
    {
        // ?area[]=x llega como array. Sin el is_string() esto seria un
        // TypeError en produccion.
        $f = Pendientes::filtros(['area' => ['motor'], 'q' => ['x']]);

        self::assertNull($f['area']);
        self::assertSame('', $f['q']);
    }

    public function testTodosQuitaElFiltroDeEstado(): void
    {
        self::assertNull(Pendientes::filtros(['estado' => 'todos'])['estado']);
    }

    // ---- where -----------------------------------------------------------

    public function testSinFiltrosEfectivosNoHayWhere(): void
    {
        [$where, $params] = Pendientes::where([
            'area' => null, 'categoria' => null, 'prioridad' => null, 'estado' => null, 'q' => '',
        ]);

        self::assertSame('', $where);
        self::assertSame([], $params);
    }

    public function testSinCerrarSeExpandeALosTresEstadosAbiertos(): void
    {
        [$where, $params] = Pendientes::where([
            'area' => null, 'categoria' => null, 'prioridad' => null, 'estado' => 'sin_cerrar', 'q' => '',
        ]);

        self::assertStringContainsString('estado IN (:estado0, :estado1, :estado2)', $where);
        self::assertSame(['abierto', 'en_curso', 'bloqueado'], array_values($params));
    }

    /**
     * LO QUE MAS IMPORTA DE ESTA CLASE. Ningun valor del request se interpola:
     * todos viajan como parametros.
     */
    public function testNingunValorDelRequestSeInterpolaEnElSql(): void
    {
        $f = Pendientes::filtros([
            'area'      => 'infra',
            'prioridad' => 'P0',
            'estado'    => 'abierto',
            'q'         => "'; DROP TABLE pendiente; --",
        ]);

        [$where, $params] = Pendientes::where($f);

        self::assertStringNotContainsString('DROP', $where);
        self::assertStringNotContainsString('infra', $where);
        self::assertStringNotContainsString('P0', $where);
        self::assertContains('infra', $params);
        self::assertContains('P0', $params);
        self::assertContains("%'; DROP TABLE pendiente; --%", $params);
    }

    /**
     * Los comodines de LIKE se escapan: buscar "100%" tiene que buscar ese
     * texto, no "empieza con 100". Sin esto, un "_" escrito por alguien que
     * busca un nombre de columna hace de comodin y trae de mas.
     */
    public function testLosComodinesDeLikeSeEscapan(): void
    {
        [, $params] = Pendientes::where([
            'area' => null, 'categoria' => null, 'prioridad' => null, 'estado' => null, 'q' => '100%_a',
        ]);

        self::assertSame('%100\%\_a%', $params[':q']);
    }

    // ---- semaforo --------------------------------------------------------

    public function testLaSeveridadAltaSePintaComoError(): void
    {
        self::assertSame('err', Pendientes::claseSeveridad('alta'));
        self::assertSame('warn', Pendientes::claseSeveridad('media'));
        self::assertSame('', Pendientes::claseSeveridad('baja'));
        self::assertSame('', Pendientes::claseSeveridad('info'));
    }

    public function testTodoEstadoTieneUnaClaseYNingunaEsInvalida(): void
    {
        foreach (array_keys(Pendientes::ESTADOS) as $estado) {
            self::assertContains(
                Pendientes::claseEstado($estado),
                ['', 'ok', 'warn', 'err'],
                "el estado '{$estado}' pinta con una clase que admin.css no define"
            );
        }
    }

    // ---- el catalogo contra la migracion ---------------------------------

    /**
     * @return array<string, array{0:string, 1:list<string>}>
     */
    public static function enumsDeLaMigracion(): array
    {
        return [
            'area'      => ['area', ['panel', 'motor', 'integracion', 'infra', 'datos', 'transversal']],
            'categoria' => ['categoria', ['seguridad', 'producto', 'refactor', 'deuda', 'infra', 'datos']],
            'prioridad' => ['prioridad', ['P0', 'P1', 'P2', 'P3']],
            'severidad' => ['severidad', ['alta', 'media', 'baja', 'info']],
            'estado'    => ['estado', ['abierto', 'en_curso', 'bloqueado', 'hecho', 'descartado']],
        ];
    }

    /**
     * El catalogo de PHP contra el ENUM del .sql DE VERDAD. Si alguien agrega
     * un valor en la migracion y no aqui, el filtro dejaria de ofrecerlo sin
     * que nada falle -- justo el tipo de desincronizacion que no avisa.
     *
     * @param list<string> $esperados
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('enumsDeLaMigracion')]
    public function testElCatalogoDePhpCoincideConElEnumDeLaMigracion(string $columna, array $esperados): void
    {
        $sql = file_get_contents(__DIR__ . '/../integration/plantiflex/migrations/044_pendiente.sql');
        self::assertIsString($sql);

        // El ENUM puede venir partido en varias lineas; se normalizan espacios.
        $plano = (string) preg_replace('/\s+/', ' ', $sql);

        self::assertMatchesRegularExpression(
            '/\b' . preg_quote($columna, '/') . '\s+ENUM\(/i',
            $plano,
            "la migracion 044 no declara la columna '{$columna}' como ENUM"
        );

        preg_match('/\b' . preg_quote($columna, '/') . '\s+ENUM\(([^)]*)\)/i', $plano, $m);
        preg_match_all("/'([^']*)'/", $m[1] ?? '', $valores);

        self::assertSame(
            $esperados,
            $valores[1],
            "el ENUM de '{$columna}' en la migracion 044 no es el que espera este test"
        );

        $enPhp = match ($columna) {
            'area'      => array_keys(Pendientes::AREAS),
            'categoria' => array_keys(Pendientes::CATEGORIAS),
            'prioridad' => array_keys(Pendientes::PRIORIDADES),
            'severidad' => array_keys(Pendientes::SEVERIDADES),
            'estado'    => array_keys(Pendientes::ESTADOS),
        };

        self::assertSame(
            $esperados,
            $enPhp,
            "Pendientes y la migracion 044 discrepan en los valores de '{$columna}'"
        );
    }

    /**
     * ABIERTOS y CERRADOS tienen que cubrir los cinco estados sin solaparse: un
     * estado que no este en ninguno desapareceria de los contadores, y uno que
     * este en los dos se contaria dos veces.
     */
    public function testAbiertosYCerradosParticionanLosEstados(): void
    {
        $union = array_merge(Pendientes::ABIERTOS, Pendientes::CERRADOS);

        self::assertSame([], array_intersect(Pendientes::ABIERTOS, Pendientes::CERRADOS));
        sort($union);
        $todos = array_keys(Pendientes::ESTADOS);
        sort($todos);
        self::assertSame($todos, $union);
    }
}
