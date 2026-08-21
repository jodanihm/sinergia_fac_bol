<?php

declare(strict_types=1);

/**
 * COMO LLEGA CADA TABLA A SU DUENO. Clasificacion del aislamiento multi-tenant
 * a partir del esquema real: columnas y claves foraneas, nada escrito a mano.
 *
 * POR QUE EXISTE ESTA CLASE
 * -----------------------------------------------------------------------------
 * Este SaaS es multi-tenant POR FILA: todas las cuentas comparten las mismas
 * tablas y lo unico que separa a una empresa de otra es un WHERE cuenta_id = :c
 * que una persona tiene que acordarse de escribir en cada consulta. No hay
 * schema por tenant, no hay row-level security: el aislamiento no lo impone el
 * motor, lo impone la disciplina.
 *
 * Un olvido de ese WHERE no es un defecto de pantalla. Es un contribuyente
 * viendo los documentos tributarios de otro.
 *
 * Esta clase convierte ese riesgo en algo que se puede MIRAR: para cada tabla
 * dice si el vinculo con la cuenta esta a la vista, si hay que seguir un camino
 * de claves foraneas para encontrarlo, o si directamente no existe.
 *
 * SE CALCULA RECORRIENDO EL GRAFO, NO CON UNA LISTA
 * -----------------------------------------------------------------------------
 * Una lista de tablas escrita a mano queda desactualizada en la proxima
 * migracion, y lo peor es que quedaria desactualizada EN SILENCIO: la pantalla
 * seguiria mostrando su cuadro tranquilizador sin incluir la tabla nueva. Al
 * derivarlo del esquema, una tabla que se cree manana aparece clasificada sola.
 *
 * LAS CINCO CLASES
 * -----------------------------------------------------------------------------
 *   raiz       Es la tabla de cuentas: no cuelga de nadie, es el dueno.
 *   directo    Tiene columna cuenta_id. El WHERE es evidente al leer la tabla.
 *   indirecto  Llega a cuenta siguiendo claves foraneas (permiso -> rol ->
 *              cuenta). El aislamiento existe y esta impuesto por el motor, pero
 *              quien escribe la consulta tiene que conocer el camino.
 *   sin_ruta   NO llega a cuenta por ningun camino, pero tiene una columna que
 *              claramente identifica a un contribuyente (rut_emisor). O sea:
 *              guarda datos que SON de alguien, sin que la base pueda decir de
 *              quien. AQUI ES DONDE UN WHERE OLVIDADO NO LO ATAJA NADIE.
 *   global     Ni camino a cuenta ni discriminador de tenant: no pertenece a
 *              ninguna empresa (catalogos, tablas de infraestructura).
 *
 * LA DIFERENCIA ENTRE sin_ruta Y global ES EL PUNTO DE TODO ESTO. Las dos son,
 * para el grafo, exactamente lo mismo: no hay camino. Lo que las separa es si
 * la tabla contiene o no datos de un contribuyente, y eso se decide por la
 * presencia de un discriminador (ver DISCRIMINADORES). Sin esa distincion, las
 * tablas de DTE -- que son las que guardan los documentos tributarios y las que
 * mas caro cuestan si se filtran -- apareceran junto a las tablas de catalogo
 * bajo la misma etiqueta inofensiva.
 */
final class AislamientoTenant
{
    public const RAIZ      = 'raiz';
    public const DIRECTO   = 'directo';
    public const INDIRECTO = 'indirecto';
    public const SIN_RUTA  = 'sin_ruta';
    public const GLOBAL    = 'global';

    /** Tabla que representa al tenant: la meta de todo camino. */
    public const TABLA_TENANT = 'cuenta';

    /** Columna que ata una fila a su cuenta sin intermediarios. */
    public const COLUMNA_TENANT = 'cuenta_id';

    /**
     * Columnas que identifican a un contribuyente sin ser una FK a cuenta.
     *
     * rut_emisor es el caso real y el que motivo la clase: las tablas de DTE
     * (dte_emitido, dte_caf, dte_certificado, dte_folio...) cuelgan del RUT del
     * emisor y no tienen cuenta_id ni FK a cuenta. Sus filas son de una empresa
     * concreta y la base no lo sabe.
     *
     * @var list<string>
     */
    public const DISCRIMINADORES = ['rut_emisor'];

    /**
     * @param list<string>                $tablas           Nombres de tabla de la base.
     * @param array<string, list<string>> $columnasPorTabla Columnas de cada tabla.
     * @param list<array{tabla:string, columna:string, refTabla:string}> $fks Claves foraneas.
     *
     * @return array<string, array{clase:string, camino:list<string>}>
     *         Indexado por nombre de tabla. 'camino' trae los saltos de FK
     *         hasta cuenta, y solo viene lleno en las indirectas.
     */
    public static function clasificar(array $tablas, array $columnasPorTabla, array $fks): array
    {
        // Grafo de salida: de que tabla se puede saltar a cual, y por que
        // columna. Se descartan las FK que apuntan a una tabla que no esta en
        // la lista (una vista, o una tabla de otro schema): un camino que pasa
        // por algo que no se puede inspeccionar no es un camino verificado.
        $salidas = [];
        foreach ($fks as $fk) {
            if (! in_array($fk['refTabla'], $tablas, true)) {
                continue;
            }
            $salidas[$fk['tabla']][] = ['columna' => $fk['columna'], 'destino' => $fk['refTabla']];
        }

        $resultado = [];
        foreach ($tablas as $tabla) {
            $resultado[$tabla] = self::clasificarUna(
                $tabla,
                $columnasPorTabla[$tabla] ?? [],
                $salidas
            );
        }

        return $resultado;
    }

    /**
     * @param list<string> $columnas
     * @param array<string, list<array{columna:string, destino:string}>> $salidas
     *
     * @return array{clase:string, camino:list<string>}
     */
    private static function clasificarUna(string $tabla, array $columnas, array $salidas): array
    {
        if ($tabla === self::TABLA_TENANT) {
            return ['clase' => self::RAIZ, 'camino' => []];
        }

        // Se pregunta por la COLUMNA y no por una FK declarada a proposito: hay
        // tablas con cuenta_id sin constraint (el aislamiento igual esta a la
        // vista de quien escribe el WHERE, que es lo que esta clase mide).
        if (in_array(self::COLUMNA_TENANT, $columnas, true)) {
            return ['clase' => self::DIRECTO, 'camino' => []];
        }

        $camino = self::buscarCamino($tabla, $salidas);
        if ($camino !== null) {
            return ['clase' => self::INDIRECTO, 'camino' => $camino];
        }

        foreach (self::DISCRIMINADORES as $discriminador) {
            if (in_array($discriminador, $columnas, true)) {
                return ['clase' => self::SIN_RUTA, 'camino' => []];
            }
        }

        return ['clase' => self::GLOBAL, 'camino' => []];
    }

    /**
     * Camino MAS CORTO de $origen a cuenta siguiendo claves foraneas, o null si
     * no hay ninguno.
     *
     * BFS y no DFS porque interesa el camino mas corto: es el que va a usar
     * quien escriba el JOIN, y el que hace entendible la etiqueta.
     *
     * EL CONJUNTO $vistas NO ES UNA OPTIMIZACION, ES LO QUE EVITA UN CUELGUE.
     * Un esquema real tiene ciclos (dte_folio -> dte_caf y una FK de vuelta,
     * tablas con FK a si mismas): sin marcar lo ya visitado, el recorrido no
     * termina nunca y la pagina se cae por timeout en vez de mostrar el dato.
     *
     * @param array<string, list<array{columna:string, destino:string}>> $salidas
     *
     * @return list<string>|null Saltos legibles, ej. ['permiso.rol_id -> rol', 'rol.cuenta_id -> cuenta'].
     */
    private static function buscarCamino(string $origen, array $salidas): ?array
    {
        $cola   = [[$origen, []]];
        $vistas = [$origen => true];

        while ($cola !== []) {
            [$actual, $recorrido] = array_shift($cola);

            foreach ($salidas[$actual] ?? [] as $salto) {
                $destino = $salto['destino'];
                $paso    = $actual . '.' . $salto['columna'] . ' -> ' . $destino;

                if ($destino === self::TABLA_TENANT) {
                    return [...$recorrido, $paso];
                }
                if (isset($vistas[$destino])) {
                    continue;
                }

                $vistas[$destino] = true;
                $cola[]           = [$destino, [...$recorrido, $paso]];
            }
        }

        return null;
    }
}
