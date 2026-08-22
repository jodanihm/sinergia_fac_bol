<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use DiagramaEr;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../panel/src/DiagramaEr.php';

/**
 * Tests del generador del diagrama ER.
 *
 * POR QUE HACEN FALTA. El diagrama lo dibuja mermaid en el navegador, y ningun
 * test de PHP puede comprobar que se vea bien. Lo que SI se puede comprobar es
 * lo unico que produce este lado: que el texto generado respete la gramatica de
 * mermaid. Y ahi esta el riesgo real -- un tipo con un espacio ("bigint
 * unsigned"), un nombre con un guion o una comilla suelta no dan un diagrama
 * feo: dan un error de parseo y la pantalla queda EN BLANCO, sin decir cual de
 * las 37 tablas lo causo.
 *
 * Los nombres vienen de information_schema, o sea de lo que alguien escribio en
 * una migracion, asi que son texto ajeno y no identificadores confiables.
 */
final class DiagramaErTest extends TestCase
{
    public function testFormaBasicaDeUnaTablaConSuRelacion(): void
    {
        $texto = DiagramaEr::construir(
            ['cuenta', 'usuario'],
            [
                'cuenta'  => [['COLUMN_NAME' => 'id', 'DATA_TYPE' => 'bigint', 'COLUMN_KEY' => 'PRI']],
                'usuario' => [
                    ['COLUMN_NAME' => 'id',        'DATA_TYPE' => 'bigint',  'COLUMN_KEY' => 'PRI'],
                    ['COLUMN_NAME' => 'cuenta_id', 'DATA_TYPE' => 'bigint',  'COLUMN_KEY' => 'MUL'],
                    ['COLUMN_NAME' => 'email',     'DATA_TYPE' => 'varchar', 'COLUMN_KEY' => ''],
                ],
            ],
            [['tabla' => 'usuario', 'columna' => 'cuenta_id', 'refTabla' => 'cuenta']]
        );

        $esperado = <<<'TXT'
        erDiagram
          cuenta {
            bigint id PK
          }
          usuario {
            bigint id PK
            bigint cuenta_id FK
            varchar email
          }
          cuenta ||--o{ usuario : "cuenta_id"
        TXT;

        $this->assertSame($esperado, $texto);
    }

    /**
     * EL CASO QUE ROMPE MERMAID. La linea de atributo espera UN token de tipo y
     * UNO de nombre; un tipo con espacio mete un tercero y el parser corta.
     */
    public function testUnTipoConEspacioNoRompeLaLineaDeAtributo(): void
    {
        $texto = DiagramaEr::construir(
            ['t'],
            ['t' => [['COLUMN_NAME' => 'n', 'DATA_TYPE' => 'double precision', 'COLUMN_KEY' => '']]],
            []
        );

        $this->assertStringContainsString('    double_precision n', $texto);
        // Ni una linea de atributo puede tener mas de dos tokens (mas la marca).
        foreach (explode("\n", $texto) as $linea) {
            if (str_starts_with($linea, '    ')) {
                $partes = preg_split('/\s+/', trim($linea)) ?: [];
                $this->assertLessThanOrEqual(3, count($partes), "linea con demasiados tokens: {$linea}");
            }
        }
    }

    public function testSaneaNombresQueRomperianLaGramatica(): void
    {
        $texto = DiagramaEr::construir(
            ['tabla-con-guion'],
            ['tabla-con-guion' => [
                ['COLUMN_NAME' => 'col con espacio', 'DATA_TYPE' => 'text', 'COLUMN_KEY' => ''],
                ['COLUMN_NAME' => 'con"comilla',     'DATA_TYPE' => 'text', 'COLUMN_KEY' => ''],
                ['COLUMN_NAME' => 'acentuada_ñ',     'DATA_TYPE' => 'text', 'COLUMN_KEY' => ''],
            ]],
            []
        );

        $this->assertStringContainsString('tabla_con_guion {', $texto);
        $this->assertStringContainsString('col_con_espacio', $texto);
        $this->assertStringNotContainsString('"comilla', $texto);
        // Nada fuera del juego seguro sobrevive, salvo la sintaxis del propio
        // diagrama (llaves, comillas de la relacion, guiones de la flecha).
        $cuerpo = preg_replace('/^erDiagram$|[{}]|\|\|--o\{|"/m', '', $texto) ?? '';
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_\s:]*$/', $cuerpo);
    }

    /**
     * Una FK hacia una tabla que la pagina no inspecciono se descarta: mermaid
     * dibujaria una caja vacia y el diagrama afirmaria algo no verificado.
     */
    public function testDescartaRelacionesHaciaTablasDesconocidas(): void
    {
        $texto = DiagramaEr::construir(
            ['a'],
            ['a' => [['COLUMN_NAME' => 'x_id', 'DATA_TYPE' => 'bigint', 'COLUMN_KEY' => '']]],
            [['tabla' => 'a', 'columna' => 'x_id', 'refTabla' => 'tabla_de_otro_schema']]
        );

        $this->assertStringNotContainsString('tabla_de_otro_schema', $texto);
        $this->assertStringNotContainsString('||--o{', $texto);
        // La columna igual se dibuja: existe, aunque su destino no se pueda verificar.
        $this->assertStringContainsString('bigint x_id', $texto);
    }

    /** PK gana sobre FK: mermaid admite una sola marca por columna. */
    public function testUnaColumnaQueEsPkYFkSeMarcaComoPk(): void
    {
        $texto = DiagramaEr::construir(
            ['rol', 'permiso'],
            [
                'rol'     => [['COLUMN_NAME' => 'id', 'DATA_TYPE' => 'bigint', 'COLUMN_KEY' => 'PRI']],
                'permiso' => [['COLUMN_NAME' => 'rol_id', 'DATA_TYPE' => 'bigint', 'COLUMN_KEY' => 'PRI']],
            ],
            [['tabla' => 'permiso', 'columna' => 'rol_id', 'refTabla' => 'rol']]
        );

        $this->assertStringContainsString('bigint rol_id PK', $texto);
        $this->assertStringNotContainsString('rol_id FK', $texto);
    }

    /**
     * Contra el esquema REAL, sin base de datos: se recorre el mismo camino que
     * la pagina y se comprueba que ninguna linea salga malformada.
     */
    public function testElTextoGeneradoRespetaLaGramaticaLineaPorLinea(): void
    {
        $tablas = ['cuenta', 'usuario', 'rol', 'permiso', 'dte_emitido'];
        $cols   = [
            'cuenta'      => [['COLUMN_NAME' => 'id', 'DATA_TYPE' => 'bigint', 'COLUMN_KEY' => 'PRI']],
            'usuario'     => [['COLUMN_NAME' => 'cuenta_id', 'DATA_TYPE' => 'bigint', 'COLUMN_KEY' => 'MUL']],
            'rol'         => [['COLUMN_NAME' => 'cuenta_id', 'DATA_TYPE' => 'bigint', 'COLUMN_KEY' => 'MUL']],
            'permiso'     => [['COLUMN_NAME' => 'rol_id', 'DATA_TYPE' => 'bigint', 'COLUMN_KEY' => 'PRI']],
            'dte_emitido' => [['COLUMN_NAME' => 'rut_emisor', 'DATA_TYPE' => 'varchar', 'COLUMN_KEY' => 'MUL']],
        ];
        $fks = [
            ['tabla' => 'usuario', 'columna' => 'cuenta_id', 'refTabla' => 'cuenta'],
            ['tabla' => 'rol',     'columna' => 'cuenta_id', 'refTabla' => 'cuenta'],
            ['tabla' => 'permiso', 'columna' => 'rol_id',    'refTabla' => 'rol'],
        ];

        $lineas = explode("\n", DiagramaEr::construir($tablas, $cols, $fks));

        $this->assertSame('erDiagram', $lineas[0]);
        foreach (array_slice($lineas, 1) as $linea) {
            $this->assertMatchesRegularExpression(
                '/^(  [A-Za-z0-9_]+ \{|  \}|    [A-Za-z0-9_]+ [A-Za-z0-9_]+( PK| FK)?'
                . '|  [A-Za-z0-9_]+ \|\|--o\{ [A-Za-z0-9_]+ : "[A-Za-z0-9_]+")$/',
                $linea,
                "linea fuera de la gramatica: {$linea}"
            );
        }
    }
}
