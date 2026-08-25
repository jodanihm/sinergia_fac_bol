<?php

declare(strict_types=1);

/**
 * El backlog: catalogo de valores, filtros y contadores.
 *
 * POR QUE UNA CLASE Y NO CONSULTAS SUELTAS EN EL HANDLER. Las mismas cinco
 * listas de valores las necesitan cuatro sitios distintos -- los <select> del
 * filtro, la validacion de lo que llega por GET, las etiquetas de la tabla y el
 * formulario de cambio de estado --, y son justo el tipo de lista que se copia
 * y despues se desincroniza: alguien agrega 'documentacion' a la categoria en
 * un lado y el filtro deja de ofrecerla sin que nada falle. Aqui hay una sola
 * copia y los cuatro la leen.
 *
 * LOS VALORES REPLICAN EL ENUM DE LA MIGRACION 044, y esa duplicacion es real:
 * si se agrega un valor en la base hay que agregarlo aca. Se acepta porque la
 * alternativa -- leer el ENUM de information_schema en cada carga -- pone una
 * consulta al esquema en el camino de una pantalla, y el dia que alguien
 * renombre un valor igual habria que tocar las etiquetas en castellano. El
 * test compara las dos listas contra el .sql para que la desincronizacion se
 * note al correr la suite y no en pantalla.
 *
 * LOS FILTROS SE ARMAN CON PLACEHOLDERS, SIEMPRE. Ningun valor que venga del
 * request se interpola en el SQL: lo que llega se compara contra la lista
 * blanca de arriba y, si no esta, se descarta. Es la unica forma de que un
 * filtro con seis ejes se pueda auditar de un vistazo.
 */
final class Pendientes
{
    /** Que parte del sistema. Mismo orden que se quiere ver en el <select>. */
    public const AREAS = [
        'panel'       => 'Panel',
        'motor'       => 'Motor',
        'integracion' => 'Integracion',
        'infra'       => 'Infra',
        'datos'       => 'Datos',
        'transversal' => 'Transversal',
    ];

    /** Que clase de trabajo es. */
    public const CATEGORIAS = [
        'seguridad' => 'Seguridad',
        'producto'  => 'Producto',
        'refactor'  => 'Refactor',
        'deuda'     => 'Deuda',
        'infra'     => 'Infra',
        'datos'     => 'Datos',
    ];

    /** CUANDO hay que hacerlo. */
    public const PRIORIDADES = ['P0' => 'P0', 'P1' => 'P1', 'P2' => 'P2', 'P3' => 'P3'];

    /** CUANTO duele si no se hace. No es lo mismo que la prioridad. */
    public const SEVERIDADES = [
        'alta'  => 'alta',
        'media' => 'media',
        'baja'  => 'baja',
        'info'  => 'info',
    ];

    public const ESTADOS = [
        'abierto'    => 'abierto',
        'en_curso'   => 'en curso',
        'bloqueado'  => 'bloqueado',
        'hecho'      => 'hecho',
        'descartado' => 'descartado',
    ];

    /**
     * Los estados que cuentan como "todavia hay trabajo aqui".
     *
     * Es el filtro por defecto del listado, y define lo que significa "sin
     * cerrar" en los contadores. Se declara una vez porque la pantalla lo usa
     * en tres lugares y una lista repetida se desincroniza.
     *
     * @var list<string>
     */
    public const ABIERTOS = ['abierto', 'en_curso', 'bloqueado'];

    /**
     * Los dos estados que cierran un item. Al llegar a cualquiera de ellos se
     * sella cerrado_at/cerrado_por; al salir, se limpian.
     *
     * @var list<string>
     */
    public const CERRADOS = ['hecho', 'descartado'];

    /**
     * Los filtros validos, ya limpios, sacados de lo que llego por GET.
     *
     * LO QUE NO ESTA EN LA LISTA BLANCA SE DESCARTA EN SILENCIO, no da error:
     * un filtro que llega mal (una URL vieja, un copy/paste a medias) tiene que
     * mostrar la lista completa, no una pantalla de error. Lo que NO puede
     * hacer nunca es llegar al SQL.
     *
     * @param array<string, mixed> $get
     * @return array{area:?string, categoria:?string, prioridad:?string, estado:?string, q:string}
     */
    public static function filtros(array $get): array
    {
        return [
            'area'      => self::deLaLista($get['area'] ?? null, self::AREAS),
            'categoria' => self::deLaLista($get['categoria'] ?? null, self::CATEGORIAS),
            'prioridad' => self::deLaLista($get['prioridad'] ?? null, self::PRIORIDADES),
            // 'estado' acepta ademas el pseudo-valor 'sin_cerrar', que no es un
            // estado de la base sino los tres ABIERTOS juntos. Es el default y
            // el que casi siempre se quiere ver.
            'estado'    => self::estadoValido($get['estado'] ?? null),
            'q'         => self::texto($get['q'] ?? null),
        ];
    }

    /**
     * El WHERE y sus parametros, a partir de los filtros ya limpios.
     *
     * @param array{area:?string, categoria:?string, prioridad:?string, estado:?string, q:string} $f
     * @return array{0:string, 1:array<string, string>}
     */
    public static function where(array $f): array
    {
        $condiciones = [];
        $params      = [];

        foreach (['area', 'categoria', 'prioridad'] as $campo) {
            if ($f[$campo] !== null) {
                $condiciones[]        = "{$campo} = :{$campo}";
                $params[":{$campo}"] = $f[$campo];
            }
        }

        if ($f['estado'] === 'sin_cerrar') {
            $marcas = [];

            foreach (self::ABIERTOS as $i => $estado) {
                $marcas[]                = ":estado{$i}";
                $params[":estado{$i}"] = $estado;
            }

            $condiciones[] = 'estado IN (' . implode(', ', $marcas) . ')';
        } elseif ($f['estado'] !== null) {
            $condiciones[]     = 'estado = :estado';
            $params[':estado'] = $f['estado'];
        }

        if ($f['q'] !== '') {
            // LIKE sobre titulo y detalle. Sin indice de texto completo a
            // proposito: son decenas de filas, no millones, y un FULLTEXT aqui
            // seria mas cosa que mantener que tiempo que ahorra.
            $condiciones[] = '(titulo LIKE :q OR detalle LIKE :q)';
            $params[':q']  = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $f['q']) . '%';
        }

        $where = $condiciones === [] ? '' : ' WHERE ' . implode(' AND ', $condiciones);

        return [$where, $params];
    }

    /**
     * El ORDER BY del listado: prioridad primero, y dentro de cada prioridad lo
     * mas grave arriba.
     *
     * SE ORDENA POR EL ENUM, NO ALFABETICAMENTE. En MySQL un ENUM ordena por el
     * orden en que se declararon sus valores, que aqui es exactamente el que se
     * quiere: P0 antes que P1, 'alta' antes que 'info'. Alfabeticamente
     * 'alta' < 'baja' < 'info' < 'media' pondria 'media' ultimo, que es
     * justo al reves de lo que significa.
     */
    public const ORDEN = ' ORDER BY prioridad ASC, severidad ASC, id ASC';

    /**
     * Los seis numeros de la fila de contadores.
     *
     * SE PIDEN EN UNA SOLA CONSULTA y no en seis: son seis COUNT sobre la misma
     * tabla y hacerlos por separado es abrir seis veces el mismo recorrido.
     *
     * NO LLEVAN LOS FILTROS DE LA PANTALLA, a proposito. Los contadores dicen
     * como esta el backlog COMPLETO; si siguieran al filtro, elegir "solo
     * infra" pondria "0 P0 abiertos" y quien lo lea de reojo entiende que no
     * hay ningun P0. Un numero que cambia de significado segun un <select> que
     * esta mas abajo es peor que no tenerlo.
     *
     * @return array{sin_cerrar:int, p0:int, p1:int, en_curso:int, bloqueados:int, hechos:int}
     */
    public static function contadores(PDO $pdo): array
    {
        $abiertos = "estado IN ('abierto','en_curso','bloqueado')";

        $sql = "SELECT
                  SUM({$abiertos})                                    AS sin_cerrar,
                  SUM({$abiertos} AND prioridad = 'P0')               AS p0,
                  SUM({$abiertos} AND prioridad = 'P1')               AS p1,
                  SUM(estado = 'en_curso')                            AS en_curso,
                  SUM(estado = 'bloqueado')                           AS bloqueados,
                  SUM(estado = 'hecho')                               AS hechos
                FROM pendiente";

        $fila = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'sin_cerrar' => (int) ($fila['sin_cerrar'] ?? 0),
            'p0'         => (int) ($fila['p0'] ?? 0),
            'p1'         => (int) ($fila['p1'] ?? 0),
            'en_curso'   => (int) ($fila['en_curso'] ?? 0),
            'bloqueados' => (int) ($fila['bloqueados'] ?? 0),
            'hechos'     => (int) ($fila['hechos'] ?? 0),
        ];
    }

    /**
     * La clase CSS del semaforo para una severidad.
     *
     * Vive aca y no en la vista para que la tabla y la ficha no puedan pintar
     * el mismo dato de dos colores distintos.
     */
    public static function claseSeveridad(string $severidad): string
    {
        return match ($severidad) {
            'alta'  => 'err',
            'media' => 'warn',
            default => '',
        };
    }

    public static function claseEstado(string $estado): string
    {
        return match ($estado) {
            'en_curso'   => 'ok',
            'bloqueado'  => 'err',
            'hecho'      => 'ok',
            'descartado' => '',
            default      => 'warn',
        };
    }

    /**
     * El texto de busqueda, o cadena vacia.
     *
     * NO SE CASTEA CON (string). Un ?q[]=x llega como array y (string) lo
     * convierte en la palabra "Array" -- mas un warning en el log --, asi que
     * la pantalla se pone a buscar "Array" y devuelve cero resultados sin que
     * nadie entienda por que. Lo que no es texto no es una busqueda.
     */
    private static function texto(mixed $valor): string
    {
        return is_string($valor) ? trim($valor) : '';
    }

    /**
     * @param array<string, string> $lista
     */
    private static function deLaLista(mixed $valor, array $lista): ?string
    {
        $valor = is_string($valor) ? $valor : '';

        return array_key_exists($valor, $lista) ? $valor : null;
    }

    private static function estadoValido(mixed $valor): ?string
    {
        $valor = is_string($valor) ? $valor : '';

        if ($valor === 'sin_cerrar') {
            return 'sin_cerrar';
        }

        if ($valor === 'todos') {
            return null;
        }

        if (array_key_exists($valor, self::ESTADOS)) {
            return $valor;
        }

        // Sin parametro -- o con uno que no se entiende -- se muestra lo que
        // falta por hacer, que es a lo que se entra en el 99% de los casos.
        return 'sin_cerrar';
    }
}
