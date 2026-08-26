<?php

declare(strict_types=1);

/**
 * COMO SE RECORTA LA BASE PARA RESPALDAR A UN CLIENTE SOLO.
 *
 * EL PROBLEMA QUE RESUELVE, Y CONVIENE LEERLO ANTES QUE EL CODIGO. Este SaaS no
 * tiene una base por cliente: es multi-tenant POR FILA. Las diez y pico de
 * empresas comparten las mismas ~40 tablas y lo unico que las separa es un
 * WHERE. Asi que "respaldar la base de cada cliente por separado" no es correr
 * un mysqldump por base -- no hay tal cosa --, es armar, para CADA TABLA, la
 * condicion que se queda solo con las filas de esa empresa.
 *
 * ESA CONDICION NO SE ESCRIBE A MANO. Una lista de 40 pares (tabla, WHERE)
 * mantenida a mano queda vieja en la proxima migracion, y lo haria EN SILENCIO:
 * la tabla nueva simplemente no saldria en ningun respaldo y nadie se enteraria
 * hasta el dia que hay que restaurar. Se deriva del esquema real -- columnas y
 * claves foraneas -- igual que hace [[AislamientoTenant]] para la pantalla de
 * /admin/base-datos, y por el mismo motivo.
 *
 * LAS CINCO FORMAS DE LLEGAR A LA CUENTA, que son las que decide construir():
 *
 *   raiz           Es la tabla cuenta. Se lleva su propia fila: `id` = N.
 *   directo        Tiene cuenta_id. La condicion es esa columna y ya.
 *   indirecto      Llega por claves foraneas (permiso -> rol -> cuenta). La
 *                  condicion es un IN anidado, un nivel por salto.
 *   discriminador  No llega por FK pero tiene rut_emisor, que identifica al
 *                  contribuyente igual (hoy: dte_logo, que no pudo recibir la
 *                  FK de la migracion 045 porque no tiene columna ambiente).
 *                  Se puentea por la tabla de emisores.
 *   global         No es de nadie (el changelog de auditoria, el backlog, la
 *                  bitacora del panel). NO entra en el respaldo de un cliente:
 *                  meterla le entregaria a cada uno los datos de la casa y los
 *                  de los demas.
 *
 * Y UNA SEXTA QUE ES UNA ALARMA: sin_mapa. Una tabla que parece tener datos de
 * un contribuyente y para la que no se pudo construir ninguna condicion. No se
 * respalda -- no habria como saber que filas son de quien -- y el proceso la
 * denuncia. Es el unico desenlace que no puede quedar callado: significa que
 * hay datos de alguien que su respaldo no esta llevando.
 *
 * EL WHERE SALE CON UN %d DONDE VA EL ID DE LA CUENTA, y lo rellena quien lo
 * usa con un (int). No se interpola aqui ningun valor que venga de afuera: lo
 * unico variable es un entero que sale de la clave primaria de cuenta.
 */
final class PlanRespaldo
{
    public const RAIZ          = 'raiz';
    public const DIRECTO       = 'directo';
    public const INDIRECTO     = 'indirecto';
    public const DISCRIMINADOR = 'discriminador';
    public const GLOBAL        = 'global';
    public const SIN_MAPA      = 'sin_mapa';

    /** Cuantos saltos de FK se siguen, como mucho, buscando la cuenta. */
    private const MAX_SALTOS = 6;

    /**
     * Tablas de LA CASA, fuera del respaldo de cualquier cliente aunque el grafo
     * diga otra cosa.
     *
     * ES LA UNICA LISTA ESCRITA A MANO DE ESTA CLASE, y esta justificada: son
     * tablas que describen la OPERACION DE LA PLATAFORMA, no los datos de una
     * empresa. Su vinculo con una cuenta es un accidente -- guardan el
     * usuario_id de quien hizo cada cosa, y ese usuario resulta pertenecer a la
     * cuenta interna --, asi que el recorrido de claves foraneas las clasifica
     * como 'indirecto' y las meteria enteras en el respaldo de esa cuenta. El
     * changelog administrativo de TODAS las empresas viajando dentro del
     * respaldo de una es exactamente lo que no puede pasar.
     *
     * NO QUEDAN SIN RESPALDAR: entran en el volcado completo de la base que
     * corre todas las noches (/data/backups/backup_mysql.sh). Lo que dice esta
     * lista es que no son de nadie en particular.
     *
     * Que este escrita a mano tiene un costo y conviene tenerlo presente: una
     * tabla de la casa que se cree manana y no se agregue aqui se va a colar en
     * el respaldo de la cuenta interna. El log de cada corrida imprime la
     * clasificacion de todas las tablas justamente para que eso se pueda ver.
     *
     * @var list<string>
     */
    public const DE_LA_CASA = ['admin_auditoria', 'admin_actividad', 'pendiente'];

    /**
     * El plan de recorte de cada tabla.
     *
     * @param list<string>                $tablas
     * @param array<string, list<string>> $columnasPorTabla
     * @param list<array{tabla:string, columnas:list<string>, refTabla:string, refColumnas:list<string>}> $fks
     *        Claves foraneas YA AGRUPADAS POR CONSTRAINT: una entrada por clave,
     *        con todas sus columnas. Una FK compuesta partida en dos entradas
     *        produciria un JOIN por la mitad de la clave, que trae filas de mas.
     * @param array<string, string> $collations
     *        Collation de cada columna, indexada por "tabla.columna". Solo se
     *        usa para el puente del discriminador; ver whereDelDiscriminador().
     *        Puede venir vacia: el plan sale igual, sin el COLLATE explicito.
     *
     * @return array<string, array{modo:string, where:?string, saltos:int}>
     */
    public static function construir(array $tablas, array $columnasPorTabla, array $fks, array $collations = []): array
    {
        $salidas = [];
        foreach ($fks as $fk) {
            if (! in_array($fk['refTabla'], $tablas, true)) {
                continue;   // apunta a algo que no se puede inspeccionar
            }
            $salidas[$fk['tabla']][] = $fk;
        }

        $plan = [];
        foreach ($tablas as $tabla) {
            $plan[$tabla] = self::planDeUna($tabla, $columnasPorTabla, $salidas, $tablas, $collations);
        }

        return $plan;
    }

    /**
     * @param array<string, list<string>> $columnasPorTabla
     * @param array<string, list<array{tabla:string, columnas:list<string>, refTabla:string, refColumnas:list<string>}>> $salidas
     * @param list<string> $tablas
     *
     * @return array{modo:string, where:?string, saltos:int}
     */
    private static function planDeUna(
        string $tabla,
        array $columnasPorTabla,
        array $salidas,
        array $tablas,
        array $collations
    ): array {
        $columnas = $columnasPorTabla[$tabla] ?? [];

        // Antes que cualquier recorrido: lo que es de la casa no es de nadie,
        // por mas que tenga un camino hasta una cuenta.
        if (in_array($tabla, self::DE_LA_CASA, true)) {
            return ['modo' => self::GLOBAL, 'where' => null, 'saltos' => 0];
        }

        if ($tabla === AislamientoTenant::TABLA_TENANT) {
            return ['modo' => self::RAIZ, 'where' => '`id` = %d', 'saltos' => 0];
        }

        // Se pregunta por la COLUMNA y no por una FK declarada: hay tablas con
        // cuenta_id sin constraint, y para recortar filas la columna alcanza.
        if (in_array(AislamientoTenant::COLUMNA_TENANT, $columnas, true)) {
            return ['modo' => self::DIRECTO, 'where' => '`' . AislamientoTenant::COLUMNA_TENANT . '` = %d', 'saltos' => 0];
        }

        $camino = self::caminoACuenta($tabla, $salidas);
        if ($camino !== null) {
            return ['modo' => self::INDIRECTO, 'where' => self::whereDelCamino($camino), 'saltos' => count($camino)];
        }

        // Sin camino, pero con una columna que identifica al contribuyente. Se
        // puentea por una tabla que tenga esa misma columna Y cuenta_id: hoy
        // dte_emisor. Se busca en vez de escribirla fija para que el dia que el
        // puente se llame de otro modo, esto lo encuentre igual.
        foreach (AislamientoTenant::DISCRIMINADORES as $discriminador) {
            if (! in_array($discriminador, $columnas, true)) {
                continue;
            }

            $puente = self::puenteDe($discriminador, $columnasPorTabla, $tablas, $salidas);
            if ($puente !== null) {
                return [
                    'modo'   => self::DISCRIMINADOR,
                    'where'  => self::whereDelDiscriminador($tabla, $discriminador, $puente, $collations),
                    'saltos' => 1,
                ];
            }

            // Tiene datos de alguien y no hay como saber de quien. Alarma.
            return ['modo' => self::SIN_MAPA, 'where' => null, 'saltos' => 0];
        }

        return ['modo' => self::GLOBAL, 'where' => null, 'saltos' => 0];
    }

    /**
     * El WHERE que puentea por la tabla maestra del discriminador.
     *
     * LLEVA UN COLLATE EXPLICITO CUANDO LAS DOS COLUMNAS NO COINCIDEN, y esto
     * no es prolijidad: sin el, MySQL corta la consulta con "Illegal mix of
     * collations (1267)" y el respaldo de ESE cliente no se escribe. No es
     * hipotetico -- se descubrio en la primera corrida contra la base real.
     *
     * El esquema de esta base quedo partido en dos collations: las tablas del
     * dump original y las que agrego cada migracion usan utf8mb4_unicode_ci, y
     * lo que nacio por el lado del motor usa utf8mb4_0900_ai_ci, el default de
     * MySQL 8. La migracion 045 emparejo las columnas que necesitaban una clave
     * foranea, y por eso los caminos indirectos no tienen este problema: una FK
     * EXIGE la misma collation en las dos puntas, asi que ahi la comparacion
     * siempre cierra. El puente del discriminador es la unica comparacion que
     * se hace SIN una FK que la garantice.
     *
     * Manda la collation de la tabla MAESTRA: es la que representa el dato
     * bueno, y la otra columna es la que quedo desalineada.
     *
     * @param array<string, string> $collations
     */
    private static function whereDelDiscriminador(
        string $tabla,
        string $discriminador,
        string $puente,
        array $collations
    ): string {
        $mia   = $collations[$tabla . '.' . $discriminador]  ?? null;
        $suya  = $collations[$puente . '.' . $discriminador] ?? null;

        // Solo cuando difieren: un COLLATE en todas las comparaciones ensuciaria
        // el plan y escondería justamente el caso raro entre el ruido.
        $collate = ($mia !== null && $suya !== null && $mia !== $suya) ? ' COLLATE ' . $suya : '';

        return sprintf(
            '`%s`%s IN (SELECT `%s` FROM `%s` WHERE `%s` = %%d)',
            $discriminador,
            $collate,
            $discriminador,
            $puente,
            AislamientoTenant::COLUMNA_TENANT
        );
    }

    /**
     * La tabla por la que se puentea un discriminador.
     *
     * NO ALCANZA CON "la primera que tenga rut_emisor y cuenta_id", y el error
     * que provoca es silencioso: en esta base, la primera por orden alfabetico
     * es cotizacion_factura, que solo guarda los RUT de las facturas que
     * salieron de una cotizacion. Puentear por ahi daria un respaldo con MENOS
     * filas de las que corresponden -- y sin ningun error, porque la consulta
     * es perfectamente valida.
     *
     * La tabla correcta es la que hace de MAESTRO de ese discriminador, y eso el
     * esquema si lo dice: es la que las demas referencian por esa columna
     * (dte_emisor, destino de las once FK que dejo la migracion 045). Se elige
     * la mas referenciada; solo si ninguna lo es se cae al orden alfabetico,
     * que al menos es estable.
     *
     * @param array<string, list<string>> $columnasPorTabla
     * @param list<string> $tablas
     * @param array<string, list<array{tabla:string, columnas:list<string>, refTabla:string, refColumnas:list<string>}>> $salidas
     */
    private static function puenteDe(string $discriminador, array $columnasPorTabla, array $tablas, array $salidas): ?string
    {
        $candidatas = [];
        foreach ($tablas as $candidata) {
            $columnas = $columnasPorTabla[$candidata] ?? [];

            if (in_array($discriminador, $columnas, true)
                && in_array(AislamientoTenant::COLUMNA_TENANT, $columnas, true)) {
                $candidatas[$candidata] = 0;
            }
        }

        if ($candidatas === []) {
            return null;
        }

        foreach ($salidas as $fksDeUna) {
            foreach ($fksDeUna as $fk) {
                if (isset($candidatas[$fk['refTabla']]) && in_array($discriminador, $fk['refColumnas'], true)) {
                    $candidatas[$fk['refTabla']]++;
                }
            }
        }

        arsort($candidatas);

        return (string) array_key_first($candidatas);
    }

    /**
     * Camino MAS CORTO de $origen a cuenta, en saltos de clave foranea.
     *
     * BFS y no DFS: el camino mas corto es el que produce el IN menos anidado, y
     * cada nivel de anidamiento es una subconsulta mas que MySQL tiene que
     * resolver por cada tabla y por cada cliente, todas las madrugadas.
     *
     * @param array<string, list<array{tabla:string, columnas:list<string>, refTabla:string, refColumnas:list<string>}>> $salidas
     * @return list<array{tabla:string, columnas:list<string>, refTabla:string, refColumnas:list<string>}>|null
     */
    private static function caminoACuenta(string $origen, array $salidas): ?array
    {
        // El conjunto de vistas no es una optimizacion: sin el, un ciclo de FK
        // -- que existe, dte_emisor <-> sus tablas -- deja el BFS girando.
        $vistas = [$origen => true];
        $cola   = [[$origen, []]];

        while ($cola !== []) {
            [$actual, $camino] = array_shift($cola);

            if (count($camino) >= self::MAX_SALTOS) {
                continue;
            }

            foreach ($salidas[$actual] ?? [] as $fk) {
                $siguiente = $fk['refTabla'];
                $nuevo     = [...$camino, $fk];

                if ($siguiente === AislamientoTenant::TABLA_TENANT) {
                    return $nuevo;
                }

                if (! isset($vistas[$siguiente])) {
                    $vistas[$siguiente] = true;
                    $cola[] = [$siguiente, $nuevo];
                }
            }
        }

        return null;
    }

    /**
     * El WHERE de un camino, armado DE ADENTRO HACIA AFUERA.
     *
     * Se empieza por la condicion sobre cuenta (`id` = %d) y se la va envolviendo
     * en un IN por cada salto, en orden inverso. Sale, por ejemplo:
     *
     *   (`rol_id`) IN (SELECT `id` FROM `rol` WHERE (`cuenta_id`) IN (SELECT `id` FROM `cuenta` WHERE `id` = %d))
     *
     * La tupla se escribe entre parentesis SIEMPRE, incluso con una sola
     * columna: MySQL lo acepta igual y hace que una FK compuesta -- las once que
     * dejo la migracion 045, (rut_emisor, ambiente) -- se escriba con la misma
     * regla que las simples, sin un caso especial que alguien tenga que
     * mantener.
     *
     * @param list<array{tabla:string, columnas:list<string>, refTabla:string, refColumnas:list<string>}> $camino
     */
    private static function whereDelCamino(array $camino): string
    {
        $where = '`id` = %d';

        foreach (array_reverse($camino) as $salto) {
            $where = sprintf(
                '(%s) IN (SELECT %s FROM `%s` WHERE %s)',
                self::listaColumnas($salto['columnas']),
                self::listaColumnas($salto['refColumnas']),
                $salto['refTabla'],
                $where
            );
        }

        return $where;
    }

    /** @param list<string> $columnas */
    private static function listaColumnas(array $columnas): string
    {
        return implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $columnas));
    }
}
