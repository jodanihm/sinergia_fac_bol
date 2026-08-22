<?php

declare(strict_types=1);

/**
 * Genera el texto de un diagrama ER de Mermaid a partir del esquema real.
 *
 * EL DIAGRAMA SE ARMA EN PHP, EN EL SERVIDOR, y al navegador solo le llega un
 * texto ya terminado. Es lo que permite que la pagina siga sin enviarle al
 * front ni un dato de la base que no haya pasado por aca: mermaid dibuja lo
 * que recibe y no consulta nada.
 *
 * MISMO FORMATO QUE buildErDiagram() DEL PANEL HERMANO, para que los dos
 * proyectos produzcan diagramas que se leen igual:
 *
 *     erDiagram
 *       usuario {
 *         bigint id PK
 *         bigint cuenta_id FK
 *       }
 *       cuenta ||--o{ usuario : "cuenta_id"
 *
 * LO DELICADO ES EL SANEADO, y no es cosmetico. Mermaid tiene una gramatica
 * propia: un nombre de tabla con un guion, un tipo con un espacio ("bigint
 * unsigned") o una comilla suelta no producen un diagrama feo, producen un
 * error de parseo y la pantalla queda en blanco. Como los nombres vienen de
 * information_schema -- o sea, de lo que alguien escribio en una migracion --
 * hay que tratarlos como texto ajeno y no como identificadores confiables.
 */
final class DiagramaEr
{
    /**
     * Tipos de MySQL a nombres cortos, para que las cajas no se estiren.
     * Un tipo que no este aqui se usa tal cual (saneado).
     *
     * @var array<string,string>
     */
    private const TIPOS_CORTOS = [
        'character varying' => 'varchar',
        'timestamp'         => 'ts',
        'datetime'          => 'datetime',
        'longtext'          => 'text',
        'mediumtext'        => 'text',
        'longblob'          => 'blob',
        'tinyint'           => 'tinyint',
        'smallint'          => 'smallint',
        'mediumint'         => 'mediumint',
    ];

    /**
     * @param list<string>                                              $tablas
     * @param array<string, list<array{COLUMN_NAME:string, DATA_TYPE:string, COLUMN_KEY:string}>> $columnasPorTabla
     * @param list<array{tabla:string, columna:string, refTabla:string}> $fks
     */
    public static function construir(array $tablas, array $columnasPorTabla, array $fks): string
    {
        // Columnas que son FK, para marcarlas dentro de su caja.
        $esFk = [];
        foreach ($fks as $fk) {
            $esFk[$fk['tabla'] . '.' . $fk['columna']] = true;
        }

        $lineas = ['erDiagram'];

        foreach ($tablas as $tabla) {
            $nombreTabla = self::sanear($tabla);
            if ($nombreTabla === '') {
                continue;
            }

            $lineas[] = '  ' . $nombreTabla . ' {';
            foreach ($columnasPorTabla[$tabla] ?? [] as $columna) {
                $nombreColumna = self::sanear((string) $columna['COLUMN_NAME']);
                if ($nombreColumna === '') {
                    continue;
                }

                // PK gana sobre FK cuando la columna es las dos cosas: es el
                // dato mas fuerte sobre la fila, y mermaid solo admite una marca.
                $marca = '';
                if (($columna['COLUMN_KEY'] ?? '') === 'PRI') {
                    $marca = ' PK';
                } elseif (isset($esFk[$tabla . '.' . $columna['COLUMN_NAME']])) {
                    $marca = ' FK';
                }

                $lineas[] = '    ' . self::tipoCorto((string) $columna['DATA_TYPE'])
                    . ' ' . $nombreColumna . $marca;
            }
            $lineas[] = '  }';
        }

        // Relaciones. Una por FK, de la tabla referida hacia la que referencia:
        // "una cuenta tiene muchos usuarios" (||--o{), que es la cardinalidad
        // real de una clave foranea simple.
        //
        // Se descartan las que apuntan fuera de la lista de tablas: mermaid
        // crearia una caja vacia para un destino que la pagina no inspecciono, y
        // el diagrama afirmaria algo que no verifico.
        $conocidas = array_flip($tablas);
        foreach ($fks as $fk) {
            if (! isset($conocidas[$fk['refTabla']], $conocidas[$fk['tabla']])) {
                continue;
            }
            $lineas[] = '  ' . self::sanear($fk['refTabla'])
                . ' ||--o{ ' . self::sanear($fk['tabla'])
                . ' : "' . self::sanear($fk['columna']) . '"';
        }

        return implode("\n", $lineas);
    }

    /**
     * Nombre corto y seguro de un tipo. Se colapsa todo lo que no sea letra,
     * numero o guion bajo: "bigint unsigned" y "double precision" romperian la
     * linea de atributo, que espera UN token de tipo y UNO de nombre.
     */
    private static function tipoCorto(string $tipo): string
    {
        $tipo   = strtolower(trim($tipo));
        $corto  = self::TIPOS_CORTOS[$tipo] ?? $tipo;
        $limpio = self::sanear($corto);

        return $limpio === '' ? 'desconocido' : $limpio;
    }

    /**
     * Deja solo [A-Za-z0-9_]. Cualquier otra cosa -- espacios, guiones,
     * comillas, parentesis, acentos -- pasa a guion bajo.
     *
     * Es deliberadamente severo. Un identificador saneado de mas se lee un poco
     * peor; uno saneado de menos rompe el parser de mermaid y deja la pantalla
     * en blanco, sin decir cual de las 37 tablas lo causo.
     */
    private static function sanear(string $texto): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9_]/', '_', $texto), '_');
    }
}
