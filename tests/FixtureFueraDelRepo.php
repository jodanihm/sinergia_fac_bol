<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

/**
 * FIXTURES QUE NO VIAJAN EN EL REPOSITORIO.
 *
 * Cuatro tests comparan lo que este codigo genera contra ficheros REALES: el
 * set de pruebas que mando el SII (easyagenda/SIISetDePruebas781572438.txt) y
 * los payloads que el SII efectivamente ACEPTO en su dia (payload_*.json). Son
 * los tests mas valiosos de la suite, porque son los unicos que comparan contra
 * la realidad y no contra lo que alguien supuso.
 *
 * Y NO ESTAN EN GIT, a proposito: el .gitignore excluye /easyagenda/ y /*.json
 * porque ahi viven datos de un contribuyente concreto. Nunca estuvieron
 * versionados -- se comprobo con git log --all sobre esas rutas.
 *
 * DE AHI EL PROBLEMA QUE ESTE TRAIT RESUELVE. En una maquina sin esos ficheros
 * -- o sea, en cualquier clon limpio -- los cuatro tests fallaban con
 * "Failed asserting that false is not false", que es lo que devuelve
 * file_get_contents() cuando el archivo no existe. Once fallos permanentes que
 * no denunciaban ningun defecto del codigo, y que contribuian a que la suite
 * entera fuera ilegible como semaforo: si esta siempre en rojo, nadie puede
 * mirarla y sacar una conclusion.
 *
 * LO QUE HACE, Y LO QUE NO. Si el fichero esta, el test corre igual que
 * siempre y protege exactamente el mismo invariante. Si no esta, se marca como
 * OMITIDO diciendo cual falta y por que -- no se borra el test ni se afloja la
 * comparacion. Un test omitido con motivo es informacion; un test en rojo
 * permanente es ruido.
 */
trait FixtureFueraDelRepo
{
    /**
     * Devuelve el contenido del fixture, u OMITE el test si no esta disponible.
     *
     * @param string $rutaRelativa Relativa a la raiz del repositorio.
     */
    private function fixtureFueraDelRepo(string $rutaRelativa): string
    {
        $ruta = __DIR__ . '/../' . $rutaRelativa;

        if (! is_readable($ruta)) {
            self::markTestSkipped(sprintf(
                'Falta el fixture real "%s". No viaja en el repositorio (el .gitignore lo '
                . 'excluye por tener datos de un contribuyente), asi que este test solo corre '
                . 'en una maquina que lo tenga. No es un defecto del codigo.',
                $rutaRelativa
            ));
        }

        $bytes = file_get_contents($ruta);
        self::assertNotFalse($bytes, "El fixture {$rutaRelativa} existe pero no se pudo leer.");

        return $bytes;
    }
}
