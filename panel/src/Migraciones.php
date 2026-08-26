<?php

declare(strict_types=1);

/**
 * El REGISTRO de migraciones: lo que hay escrito en disco, no lo que hay en la
 * base.
 *
 * DOS FUENTES QUE NO SON LA MISMA, y esta clase se ocupa de una sola:
 *
 *   EL CATALOGO (scripts/catalogo_migraciones.php) declara que migraciones
 *   existen y como reconocer su efecto en la base. Es lo que lee el chequeo de
 *   despliegue para decir "falta la 043".
 *
 *   LOS ARCHIVOS (integration/plantiflex/migrations/*.sql) son las migraciones
 *   de verdad: el SQL que alguien escribio y que otro alguien ejecuto.
 *
 * POR QUE HAY QUE CRUZARLAS. Las dos se mantienen a mano y por separado, y el
 * modo de fallar es siempre el mismo: se agrega el .sql, se corre, y nadie
 * agrega la entrada al catalogo. Desde ese momento el deploy dice "todo al dia"
 * mientras hay una migracion que no vigila nadie, y la unica forma de
 * enterarse es que alguien compare dos listas de cuarenta y cinco lineas. El
 * cruce lo hace cruzar(), y su resultado se pinta arriba de todo en la pantalla
 * porque un desajuste ahi invalida lo que dice el resto.
 *
 * EL TITULO SALE DEL ARCHIVO, NO DE UNA LISTA APARTE. Cada .sql abre con
 * "-- Migracion NNN: que hace"; leer esa linea es preferible a copiarla al
 * catalogo, porque una copia envejece sin avisar y esta no puede: si alguien
 * reescribe la cabecera, la pantalla muestra la cabecera nueva.
 *
 * NO CONECTA A LA BASE Y NO IMPRIME. Solo lee archivos y compara listas; el
 * veredicto contra la base lo da el catalogo, que es quien sabe de huellas.
 */
final class Migraciones
{
    /**
     * Los .sql del directorio, indexados por id y ordenados por id.
     *
     * SOLO LOS QUE SE LLAMAN NNN_algo.sql. Un archivo suelto en ese directorio
     * (un respaldo, un .sql a medio escribir) no es una migracion, y contarlo
     * como tal haria aparecer un "sin entrada en el catalogo" que no se puede
     * arreglar agregando la entrada.
     *
     * @return array<string, string> id => nombre de archivo
     */
    public static function archivos(string $directorio): array
    {
        $encontrados = glob(rtrim($directorio, '/') . '/*.sql');

        if ($encontrados === false) {
            return [];
        }

        $archivos = [];
        foreach ($encontrados as $ruta) {
            $nombre = basename($ruta);

            if (preg_match('/^(\d{3})_[^\/]+\.sql$/', $nombre, $m) === 1) {
                $archivos[$m[1]] = $nombre;
            }
        }

        ksort($archivos);

        return $archivos;
    }

    /**
     * El titulo que declara la cabecera del .sql, o '' si no la tiene.
     *
     * La cabecera es "-- Migracion NNN: texto" y el texto puede seguir en las
     * lineas de abajo -- son comentarios ajustados a 80 columnas, asi que la
     * mitad de los titulos vienen partidos en dos. Se juntan hasta la primera
     * linea que ya no es continuacion: un "--" solo, una linea de guiones o
     * cualquier cosa que no sea comentario.
     */
    public static function titulo(string $sql): string
    {
        $lineas = preg_split('/\R/', $sql) ?: [];
        $titulo = null;

        foreach ($lineas as $linea) {
            $linea = rtrim($linea);

            if ($titulo === null) {
                if (preg_match('/^--\s*Migracion\s+\d+\s*:\s*(.+)$/i', $linea, $m) === 1) {
                    $titulo = trim($m[1]);
                }

                continue;
            }

            // Fin del titulo: se acabo el comentario, quedo una linea vacia de
            // comentario, o empezo la regla de guiones que separa los bloques.
            if (preg_match('/^--\s*[-=]{3,}\s*$/', $linea) === 1) {
                break;
            }

            if (preg_match('/^--\s+(\S.*)$/', $linea, $m) !== 1) {
                break;
            }

            $titulo .= ' ' . trim($m[1]);
        }

        return $titulo === null ? '' : (string) preg_replace('/\s+/', ' ', $titulo);
    }

    /**
     * Que hay en una lista y no en la otra.
     *
     * LOS DOS SENTIDOS IMPORTAN Y NO SIGNIFICAN LO MISMO:
     *
     *   sinEntrada  hay un .sql que el catalogo no menciona. Es el peligroso:
     *               ninguna huella lo vigila, asi que ni el deploy ni esta
     *               pantalla pueden decir si esta aplicado. Se arregla
     *               agregando su entrada al catalogo.
     *
     *   sinArchivo  el catalogo nombra un .sql que no esta. La pantalla puede
     *               seguir diciendo si esta aplicado -- eso lo dicen las
     *               huellas, no el archivo --, pero ya no hay donde leer que
     *               hizo ni como volver a correrlo en una base nueva.
     *
     * @param list<string> $idsCatalogo
     * @param list<string> $idsEnDisco
     * @return array{sinArchivo: list<string>, sinEntrada: list<string>}
     */
    public static function cruzar(array $idsCatalogo, array $idsEnDisco): array
    {
        return [
            'sinArchivo' => array_values(array_diff($idsCatalogo, $idsEnDisco)),
            'sinEntrada' => array_values(array_diff($idsEnDisco, $idsCatalogo)),
        ];
    }
}
