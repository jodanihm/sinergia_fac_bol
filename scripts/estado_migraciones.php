<?php

declare(strict_types=1);

/**
 * estado_migraciones.php -- QUE MIGRACIONES ESTAN APLICADAS EN ESTA BASE.
 *
 * SOLO LECTURA. Este script no ejecuta ni una sentencia que escriba: ni CREATE,
 * ni ALTER, ni INSERT, ni UPDATE, ni DELETE, ni DROP. Todas sus consultas son
 * SELECT contra information_schema (y un COUNT sobre dte_folio). Por eso es
 * seguro correrlo en produccion, en cualquier momento, sin respaldo previo.
 *
 * NO APLICA NADA. Solo informa. Aplicar sigue siendo una decision humana.
 *
 * POR QUE PHP Y NO SHELL. paso1a_migraciones.sh hace algo parecido para siete
 * migraciones, pero exige `id -u` = 0 y `docker exec` como root, porque ademas
 * respalda y escribe. Este solo mira, asi que se conecta con las credenciales
 * de la APLICACION (las mismas DB_* que usan panel/src/Db.php y public/index.php)
 * y corre igual dentro del contenedor del NAS o del VPS, sin privilegios.
 *
 * NO ASUME EL NOMBRE DE LA BASE: filtra por DATABASE(), igual que
 * paso1a_migraciones.sh. Lo que informa es siempre sobre la base a la que
 * apuntan las credenciales, no sobre una constante escrita aqui.
 *
 * DE DONDE SALE EL METODO. paso1a_migraciones.sh ya verificaba cada migracion
 * contra information_schema con el par (consulta, valor esperado). Esto lleva
 * ese mismo patron de 7 migraciones a las 23, en una estructura declarativa:
 * agregar la 024 es agregar una entrada a MIGRACIONES, no tocar logica.
 *
 * TRES VEREDICTOS, NO DOS:
 *   APLICADA     todas sus huellas presentes
 *   NO_APLICADA  ninguna presente
 *   PARCIAL      algunas si y otras no
 *
 * PARCIAL es el que justifica el script. Las dos migraciones mixtas (001 y 022)
 * mezclan CREATE TABLE IF NOT EXISTS -- que se puede repetir sin ruido -- con
 * ALTER TABLE, que revienta al repetirse. Re-ejecutar una a medias deja la base
 * en un estado que ningun archivo describe y que hoy es invisible.
 *
 * LO QUE ESTE SCRIPT NO PUEDE SABER: la huella prueba que el EFECTO esta
 * presente, no que la migracion se haya ejecutado. Una columna creada a mano es
 * indistinguible de una creada por su migracion. Sirve para decidir que falta,
 * no como bitacora de lo que paso.
 *
 * DIFERIDA A PROPOSITO NO ES LO MISMO QUE SE ME OLVIDO
 * -----------------------------------------------------------------------------
 * Una migracion puede estar sin aplicar PORQUE ASI SE DECIDIO. La 023 lleva
 * semanas asi: solo se aplica cuando no queden nulos y el codigo nuevo lleve
 * tiempo en produccion. Hasta hoy esa intencion vivia SOLO en la cabecera de su
 * .sql -- un archivo que este script nombra pero nunca abre --, asi que para el
 * codigo era indistinguible de un descuido y forzaba exit 1.
 *
 * Enganchar eso a un despliegue habria abortado TODOS los deploys para siempre.
 *
 * Por eso la entrada de una migracion puede llevar la clave 'diferida' con el
 * MOTIVO. Es un string y no un booleano a proposito: obliga a escribir por que.
 * Con un true la marca se pone en dos segundos y nadie recuerda la razon seis
 * semanas despues, que es exactamente como se llego a esta situacion.
 *
 * Lo que una marca de diferida NO hace: silenciar. Una diferida sale siempre en
 * la salida, en su propio bloque y con su motivo. Callarla seria peor que el
 * problema que resuelve.
 *
 * USO
 *   php scripts/estado_migraciones.php
 *
 * SALIDA
 *   0  todas aplicadas, o las que faltan estan TODAS marcadas como diferidas
 *   1  falta alguna SIN marcar, o hay alguna PARCIAL (marcada o no)
 *   2  no se pudo conectar o falta configuracion
 *
 * PARCIAL ABORTA AUNQUE ESTE DIFERIDA. Diferida significa "todavia no la
 * corrimos", no "la corrimos a medias": una diferida a medio aplicar es un
 * estado que nadie decidio y que ningun archivo describe.
 *
 * (El resumen no lleva el numero total escrito a mano: decia "las 23" cuando ya
 * iban 31.)
 */

// -----------------------------------------------------------------------------
//  Conexion: mismas env vars que el resto del proyecto, sin defaults silenciosos
//  para lo que identifica la base (mismo criterio que panel/src/Db.php, que
//  falla ruidoso en vez de conectar contra la base equivocada).
// -----------------------------------------------------------------------------
function conectar(): PDO
{
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER'] as $requerida) {
        if ((string) getenv($requerida) === '') {
            fwrite(STDERR, "ERROR: falta la variable de entorno {$requerida}.\n");
            exit(2);
        }
    }

    try {
        return new PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                getenv('DB_HOST'),
                getenv('DB_PORT') ?: '3306',
                getenv('DB_NAME'),
            ),
            (string) getenv('DB_USER'),
            (string) getenv('DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    } catch (PDOException $e) {
        fwrite(STDERR, 'ERROR: no se pudo conectar a la base: ' . $e->getMessage() . "\n");
        exit(2);
    }
}

// -----------------------------------------------------------------------------
//  El catalogo de migraciones y sus huellas viven en un archivo aparte desde
//  que /admin/base-datos muestra lo mismo en pantalla: una sola lista que los
//  dos leen, en vez de dos que hay que acordarse de actualizar. Ver la cabecera
//  de catalogo_migraciones.php.
//
//  require y no require_once: si ya estuviera cargado seria un error de
//  programacion que conviene que se vea, no que se silencie.
// -----------------------------------------------------------------------------
require __DIR__ . '/catalogo_migraciones.php';


/**
 * Advertencia de DATOS de la 022, separada de su veredicto a proposito.
 *
 * La columna puede existir (ALTER aplicado) y el backfill no haber corrido, o
 * haber corrido y despues haberse llenado de nulos por codigo viejo. El esquema
 * no lo puede contar: hay que mirar las filas. Es la unica consulta del script
 * que no va contra information_schema, y sigue siendo un SELECT.
 *
 * @return array{aplica:bool, nulos:int}
 */
function avisoBackfill022(PDO $pdo): array
{
    if (huellaColumnas($pdo, 'dte_folio', ['proximo_folio_inicial']) !== 1) {
        return ['aplica' => false, 'nulos' => 0];
    }

    $stmt = $pdo->query('SELECT COUNT(*) FROM dte_folio WHERE proximo_folio_inicial IS NULL');

    return ['aplica' => true, 'nulos' => (int) $stmt->fetchColumn()];
}

// -----------------------------------------------------------------------------
//  Salida
// -----------------------------------------------------------------------------
$pdo  = conectar();
$base = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

echo "\n";
echo "ESTADO DE MIGRACIONES -- base: {$base}\n";
echo "Solo lectura: este script no modifica nada.\n";
echo str_repeat('=', 96), "\n";
printf("%-5s %-46s %-13s %s\n", 'MIG', 'ARCHIVO', 'VEREDICTO', 'HUELLAS');
echo str_repeat('-', 96), "\n";

$conteo    = ['APLICADA' => 0, 'PARCIAL' => 0, 'NO_APLICADA' => 0];
$faltantes = [];   // sin aplicar y SIN marcar: estas abortan
$parciales = [];   // a medias: abortan siempre, marcadas o no
$diferidas = [];   // sin aplicar y marcadas: NO abortan, pero se muestran
$marcaVieja = [];  // marcadas como diferidas y ya aplicadas: sobra la marca

foreach (MIGRACIONES as $m) {
    $presentes = 0;
    $esperados = 0;
    $detalle   = [];

    foreach ($m['huellas'] as $h) {
        $r          = evaluarHuella($pdo, $h);
        $presentes += $r['presente'];
        $esperados += $r['esperado'];
        if ($r['presente'] !== $r['esperado']) {
            $detalle[] = sprintf('%s (%d/%d)', $h['desc'], $r['presente'], $r['esperado']);
        }
    }

    $v = veredicto($presentes, $esperados);
    $conteo[$v]++;

    // LA MARCA ES DEL DECLARANTE, EL VEREDICTO ES DE LA BASE. Se cruzan aqui y
    // no antes: el veredicto sigue describiendo lo que hay en la base, sin
    // contaminarse con lo que alguien decidio. Lo que la marca cambia es solo
    // quien aborta.
    $motivoDiferida = isset($m['diferida']) && trim((string) $m['diferida']) !== ''
        ? trim((string) $m['diferida'])
        : null;

    if ($v === 'PARCIAL') {
        // ABORTA AUNQUE ESTE DIFERIDA. Ver la cabecera: "todavia no la corrimos"
        // y "la corrimos a medias" no son lo mismo.
        $parciales[] = $m['id'];
    } elseif ($v === 'NO_APLICADA') {
        if ($motivoDiferida !== null) {
            $diferidas[] = ['id' => $m['id'], 'archivo' => $m['archivo'], 'motivo' => $motivoDiferida];
        } else {
            $faltantes[] = $m['id'];
        }
    } elseif ($v === 'APLICADA' && $motivoDiferida !== null) {
        // LA MARCA CADUCA Y HAY QUE DECIRLO. Sin este aviso, el dia que alguien
        // aplique la 023 el sistema seguiria declarandola diferida para siempre
        // y la marca se volveria permanente sin que nadie la revise.
        $marcaVieja[] = $m['id'];
    }

    printf(
        "%-5s %-46s %-13s %d/%d%s\n",
        $m['id'],
        $m['archivo'],
        // El asterisco marca "sin aplicar, pero a proposito". Se explica al pie.
        $v . ($v === 'NO_APLICADA' && $motivoDiferida !== null ? '*' : ''),
        $presentes,
        $esperados,
        $detalle === [] ? '' : '  falta: ' . implode('; ', $detalle)
    );
}

echo str_repeat('-', 96), "\n";
printf(
    "RESUMEN: %d aplicadas, %d parciales, %d diferidas a proposito, %d sin aplicar (de %d)\n",
    $conteo['APLICADA'],
    $conteo['PARCIAL'],
    count($diferidas),
    count($faltantes),
    count(MIGRACIONES)
);

if ($parciales !== []) {
    echo "\nPARCIALES (revisar a mano, el archivo no describe este estado): " . implode(', ', $parciales) . "\n";
    echo "  Una PARCIAL aborta el despliegue AUNQUE este marcada como diferida.\n";
}
if ($faltantes !== []) {
    echo "\nSIN APLICAR Y SIN MARCAR (esto aborta un despliegue): " . implode(', ', $faltantes) . "\n";
    echo "  Si alguna esta diferida a proposito, agregale la clave 'diferida' con el motivo\n";
    echo "  en su entrada de MIGRACIONES, no en una lista aparte.\n";
}

// LAS DIFERIDAS SE MUESTRAN SIEMPRE. No abortan, pero no se callan: una
// migracion que no se aplica y de la que nadie se entera es el problema que
// esta marca vino a resolver, no la solucion.
if ($diferidas !== []) {
    echo "\nDIFERIDAS A PROPOSITO (no abortan el despliegue):\n";
    foreach ($diferidas as $d) {
        printf("  %-5s %s\n", $d['id'], $d['archivo']);
        foreach (explode("\n", wordwrap($d['motivo'], 86)) as $l) {
            echo '        ', $l, "\n";
        }
    }
    echo "  El asterisco de la tabla (NO_APLICADA*) marca estas.\n";
}

if ($marcaVieja !== []) {
    echo "\nDIFERIDA PERO YA APLICADA: " . implode(', ', $marcaVieja) . "\n";
    echo "  Quita la clave 'diferida' de esa(s) entrada(s) de MIGRACIONES: la marca ya no\n";
    echo "  describe la realidad y, si se queda, nadie la va a volver a mirar.\n";
}

// --- Advertencias de datos, que el esquema no puede contestar -----------------
$aviso = avisoBackfill022($pdo);
if ($aviso['aplica']) {
    echo "\nDATOS (no es un veredicto de migracion):\n";
    if ($aviso['nulos'] === 0) {
        echo "  022 backfill: 0 filas de dte_folio con proximo_folio_inicial NULL.\n";
    } else {
        printf("  022 backfill: %d fila(s) de dte_folio con proximo_folio_inicial NULL.\n", $aviso['nulos']);
    }

    // Los TRES estados de la 023. La 023 exige cero nulos antes de correr: sin
    // eso su ALTER falla. Distinguir "lista" de "bloqueada" evita el intento.
    $es023 = huellaNulabilidad($pdo, 'dte_folio', 'proximo_folio_inicial', 'NO') === 1;
    if ($es023) {
        echo "  023: APLICADA (la columna ya es NOT NULL).\n";
    } elseif ($aviso['nulos'] === 0) {
        echo "  023: sin aplicar y LISTA (0 nulos; su ALTER pasaria).\n";
    } else {
        echo "  023: sin aplicar y BLOQUEADA (hay nulos; su ALTER fallaria).\n";
    }
}

echo "\n";

// ---------------------------------------------------------------------------
//  EXIT CODE
//
//  Aborta si falta algo QUE NADIE DECIDIO que faltara:
//
//    $faltantes  sin aplicar y sin marcar  -> 1
//    $parciales  a medias, marcada o no    -> 1
//    $diferidas  sin aplicar pero decidido -> NO cuenta
//
//  Lo consume deploy.sh entre el git pull y el build: si esto da 1, el deploy
//  se detiene sin haber construido ninguna imagen.
// ---------------------------------------------------------------------------
$aborta = $faltantes !== [] || $parciales !== [];

if ($aborta) {
    echo "VEREDICTO: hay migraciones sin aplicar que NO estan marcadas como diferidas.\n";
} elseif ($diferidas !== []) {
    printf("VEREDICTO: al dia, salvo %d diferida(s) a proposito (listadas arriba).\n", count($diferidas));
} else {
    echo "VEREDICTO: todas las migraciones aplicadas.\n";
}
echo "\n";

exit($aborta ? 1 : 0);
