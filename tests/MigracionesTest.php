<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use Migraciones;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../panel/src/Migraciones.php';

/**
 * Tests del registro de migraciones (/admin/migraciones).
 *
 * Migraciones vive en panel/src/, fuera del autoload PSR-4, y se carga con un
 * require_once explicito -- mismo patron que RutasDelRouterTest.
 *
 * LO QUE HAY QUE ATRAPAR ES EL DESAJUSTE, y por eso el ultimo test se corre
 * contra los archivos DE VERDAD: el modo de fallar de este sistema no es que
 * cruzar() se equivoque, es que alguien agregue un .sql y se olvide de
 * declararlo en scripts/catalogo_migraciones.php. Desde ese momento el chequeo
 * de despliegue dice "todo al dia" sobre una migracion que no vigila nadie. Un
 * test sobre listas inventadas no se entera nunca de eso.
 */
final class MigracionesTest extends TestCase
{
    private const DIRECTORIO = __DIR__ . '/../integration/plantiflex/migrations';

    public function testSoloCuentaLosArchivosConNombreDeMigracion(): void
    {
        $archivos = Migraciones::archivos(self::DIRECTORIO);

        self::assertNotSame([], $archivos);

        foreach ($archivos as $id => $nombre) {
            self::assertMatchesRegularExpression('/^\d{3}$/', (string) $id);
            self::assertStringStartsWith((string) $id . '_', $nombre);
            self::assertStringEndsWith('.sql', $nombre);
        }
    }

    /**
     * El orden importa: la pantalla las lista tal como vienen, y una lista de
     * migraciones fuera de orden se lee como si faltaran.
     */
    public function testVienenOrdenadasPorId(): void
    {
        $ids = array_keys(Migraciones::archivos(self::DIRECTORIO));
        $ordenados = $ids;
        sort($ordenados);

        self::assertSame($ordenados, $ids);
    }

    public function testUnDirectorioQueNoExisteNoRevienta(): void
    {
        self::assertSame([], Migraciones::archivos(self::DIRECTORIO . '/no-existe'));
    }

    public function testElTituloSaleDeLaCabecera(): void
    {
        $sql = "-- =====================\n"
            . "-- Migracion 015: tabla cliente (maestro de clientes por tenant).\n"
            . "--\n"
            . "-- POR QUE EXISTE\n"
            . "CREATE TABLE cliente (id INT);\n";

        self::assertSame('tabla cliente (maestro de clientes por tenant).', Migraciones::titulo($sql));
    }

    /**
     * La mitad de las cabeceras vienen partidas en dos porque los comentarios
     * se ajustan a 80 columnas. Cortar en la primera linea dejaria titulos que
     * terminan a media frase.
     */
    public function testElTituloJuntaLasLineasDeContinuacion(): void
    {
        $sql = "-- =====================\n"
            . "-- Migracion 040: el tope diario del chat deja de ser una constante de PHP\n"
            . "-- y pasa a ser un dato de la cuenta.\n"
            . "--\n"
            . "-- QUE CAMBIA\n";

        self::assertSame(
            'el tope diario del chat deja de ser una constante de PHP y pasa a ser un dato de la cuenta.',
            Migraciones::titulo($sql)
        );
    }

    public function testUnArchivoSinCabeceraNoInventaTitulo(): void
    {
        self::assertSame('', Migraciones::titulo("ALTER TABLE usuario ADD COLUMN x INT;\n"));
    }

    public function testElCruceDistingueLosDosSentidos(): void
    {
        $cruce = Migraciones::cruzar(['001', '002', '003'], ['002', '003', '004']);

        self::assertSame(['001'], $cruce['sinArchivo']);
        self::assertSame(['004'], $cruce['sinEntrada']);
    }

    /**
     * EL TEST QUE JUSTIFICA EL ARCHIVO. Se corre contra el catalogo y el
     * directorio de verdad: si se agrega un .sql sin su entrada -- o al reves --
     * falla aqui, y como el despliegue corre la suite, un catalogo incompleto
     * ya no puede llegar a produccion en silencio.
     */
    public function testElCatalogoYLosArchivosDicenLoMismo(): void
    {
        require_once __DIR__ . '/../scripts/catalogo_migraciones.php';

        $enDisco  = Migraciones::archivos(self::DIRECTORIO);
        $catalogo = array_map(static fn (array $m): string => (string) $m['id'], MIGRACIONES);
        $cruce    = Migraciones::cruzar($catalogo, array_keys($enDisco));

        self::assertSame([], $cruce['sinEntrada'], 'hay migraciones en disco que el catalogo no declara: nadie vigila si estan aplicadas');
        self::assertSame([], $cruce['sinArchivo'], 'el catalogo nombra migraciones cuyo .sql no esta en el repositorio');

        // El nombre tambien: el catalogo es lo unico que dice donde leer que
        // hizo cada una, y un archivo renombrado lo deja apuntando al vacio.
        foreach (MIGRACIONES as $m) {
            self::assertSame(
                $enDisco[(string) $m['id']],
                (string) $m['archivo'],
                "el catalogo nombra un archivo distinto del que hay en disco para la migracion {$m['id']}"
            );
        }
    }
}
