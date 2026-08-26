<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Que ninguna constante del router se declare DESPUES del despacho de rutas.
 *
 * DE DONDE SALE ESTE TEST: de un fatal en produccion. Se agrego una constante
 * junto al handler que la usaba -- a 3.000 lineas del final del archivo -- y la
 * pantalla reventaba con "Undefined constant" en cuanto alguien la abria.
 *
 * EL MOTIVO ES UNA ASIMETRIA DE PHP QUE NO SE VE LEYENDO EL CODIGO. Las
 * funciones se hoistean: `function f() {}` escrita al final del archivo ya
 * existe cuando la linea 10 la llama. Los `const` de nivel de archivo NO: no
 * existen hasta que la ejecucion pasa por su linea. Como panel/public/index.php
 * despacha la ruta en el medio del archivo y define los handlers mas abajo, un
 * handler puede llamarse perfectamente y encontrarse con que su constante
 * todavia no se declaro. Se lee bien, pasa el linter, y falla siempre.
 *
 * NO LO ATRAPA NI php -l NI UN TEST DE LA LOGICA. La unica forma de verlo es
 * entrar por el router o mirar el orden de las lineas, que es lo que hace esto.
 *
 * SOLO MIRA LOS `const` DE NIVEL DE ARCHIVO (los que empiezan en la columna 1).
 * Las constantes de clase viven dentro de su clase y no tienen este problema.
 */
final class ConstantesAntesDelDespachoTest extends TestCase
{
    private const ROUTER = __DIR__ . '/../panel/public/index.php';

    public function testNingunaConstanteSeDeclaraDespuesDelDespacho(): void
    {
        $lineas = (array) file(self::ROUTER, FILE_IGNORE_NEW_LINES);

        $despacho = null;
        $tardias  = [];

        foreach ($lineas as $i => $linea) {
            $numero = $i + 1;

            // El primer despacho: a partir de aqui se puede estar ejecutando un
            // handler, asi que todo lo que se declare mas abajo llega tarde.
            if ($despacho === null && preg_match('/^if \(\$metodo === /', (string) $linea) === 1) {
                $despacho = $numero;
            }

            if ($despacho !== null && preg_match('/^const ([A-Z_][A-Z0-9_]*)\s*=/', (string) $linea, $m) === 1) {
                $tardias[] = "{$m[1]} (linea {$numero})";
            }
        }

        self::assertNotNull($despacho, 'no se reconocio el despacho de rutas; si cambio de forma, hay que ajustar este test');

        self::assertSame(
            [],
            $tardias,
            "estas constantes se declaran despues del despacho de rutas (linea {$despacho}) y no existen cuando corre el "
            . 'handler que las use: ' . implode(', ', $tardias)
        );
    }

    /**
     * Si el router dejara de tener constantes reconocibles, el test de arriba
     * pasaria en vacio para siempre sin comprobar nada.
     */
    public function testElTestSigueReconociendoLasConstantesDelRouter(): void
    {
        $fuente = (string) file_get_contents(self::ROUTER);

        self::assertGreaterThan(
            10,
            preg_match_all('/^const [A-Z_][A-Z0-9_]*\s*=/m', $fuente),
            'no se reconocieron las constantes de nivel de archivo del router'
        );
    }
}
