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
 * USO
 *   php scripts/estado_migraciones.php
 *
 * SALIDA
 *   0  las 23 aplicadas
 *   1  falta alguna (NO_APLICADA o PARCIAL)
 *   2  no se pudo conectar o falta configuracion
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
//  Las tres formas de huella. Cada una devuelve cuantos objetos encontro, y la
//  entrada de la migracion dice cuantos espera. Son SELECT y nada mas.
// -----------------------------------------------------------------------------

/** Cuenta cuantas de $tablas existen en la base actual. */
function huellaTablas(PDO $pdo, array $tablas): int
{
    $marcas = implode(',', array_fill(0, count($tablas), '?'));
    $stmt   = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables '
        . "WHERE table_schema = DATABASE() AND table_name IN ({$marcas})"
    );
    $stmt->execute($tablas);

    return (int) $stmt->fetchColumn();
}

/** Cuenta cuantas de $columnas existen en $tabla. */
function huellaColumnas(PDO $pdo, string $tabla, array $columnas): int
{
    $marcas = implode(',', array_fill(0, count($columnas), '?'));
    $stmt   = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns '
        . "WHERE table_schema = DATABASE() AND table_name = ? AND column_name IN ({$marcas})"
    );
    $stmt->execute(array_merge([$tabla], $columnas));

    return (int) $stmt->fetchColumn();
}

/**
 * 1 si $tabla.$columna existe Y su nulabilidad es la pedida; 0 si no.
 *
 * ES LA HUELLA QUE SEPARA LA 022 DE LA 023. Las dos tocan la MISMA columna
 * (dte_folio.proximo_folio_inicial): la 022 la crea NULL y la 023 la pasa a
 * NOT NULL. Una huella que solo preguntara "existe la columna" daria las dos
 * por aplicadas en cuanto corriera la 022.
 */
function huellaNulabilidad(PDO $pdo, string $tabla, string $columna, string $esperado): int
{
    $stmt = $pdo->prepare(
        'SELECT is_nullable FROM information_schema.columns '
        . 'WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
    );
    $stmt->execute([$tabla, $columna]);
    $valor = $stmt->fetchColumn();

    return ($valor !== false && strtoupper((string) $valor) === $esperado) ? 1 : 0;
}

/** 1 si existe el indice $indice en $tabla; 0 si no. */
function huellaIndice(PDO $pdo, string $tabla, string $indice): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics '
        . 'WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
    );
    $stmt->execute([$tabla, $indice]);

    return (int) $stmt->fetchColumn() > 0 ? 1 : 0;
}

/**
 * 1 si la PRIMARY KEY de $tabla es EXACTAMENTE $columnas, en ese orden.
 *
 * La 001 no agrega una PK: le CAMBIA la suya a dte_idempotencia, de
 * (ambiente, clave) a (rut_emisor, ambiente, clave). Comparar la lista completa
 * y ordenada es lo unico que distingue el antes del despues; contar columnas de
 * la PK no alcanzaria si alguien la dejara a medias.
 */
function huellaClavePrimaria(PDO $pdo, string $tabla, array $columnas): int
{
    $stmt = $pdo->prepare(
        'SELECT column_name FROM information_schema.statistics '
        . "WHERE table_schema = DATABASE() AND table_name = ? AND index_name = 'PRIMARY' "
        . 'ORDER BY seq_in_index'
    );
    $stmt->execute([$tabla]);
    $actual = array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN));

    return $actual === array_map('strtolower', $columnas) ? 1 : 0;
}

// -----------------------------------------------------------------------------
//  LAS 23 MIGRACIONES Y SUS HUELLAS.
//
//  Una entrada por migracion. 'huellas' es una lista; cada una se evalua y
//  aporta un "presente / esperado". El veredicto sale de cuantas huellas
//  quedaron completas, no de un booleano suelto: por eso PARCIAL es expresable.
//
//  Para agregar la 024: se agrega una entrada aqui y nada mas.
// -----------------------------------------------------------------------------
const MIGRACIONES = [
    [
        'id'      => '001',
        'archivo' => '001_tenancy.sql',
        'nota'    => 'MIXTA: 3 CREATE + 7 ALTER (incluye cambio de PK)',
        'huellas' => [
            ['tipo' => 'tablas',   'desc' => 'tablas cuenta/usuario/api_key', 'tablas' => ['cuenta', 'usuario', 'api_key'], 'esperado' => 3],
            ['tipo' => 'columnas', 'desc' => 'dte_emisor.cuenta_id',          'tabla' => 'dte_emisor',       'columnas' => ['cuenta_id'],  'esperado' => 1],
            ['tipo' => 'indice',   'desc' => 'ix_emisor_cuenta',              'tabla' => 'dte_emisor',       'indice' => 'ix_emisor_cuenta'],
            ['tipo' => 'columnas', 'desc' => 'dte_certificado.dek_envuelta',  'tabla' => 'dte_certificado',  'columnas' => ['dek_envuelta'], 'esperado' => 1],
            ['tipo' => 'columnas', 'desc' => 'dte_idempotencia.rut_emisor',   'tabla' => 'dte_idempotencia', 'columnas' => ['rut_emisor'], 'esperado' => 1],
            ['tipo' => 'pk',       'desc' => 'PK (rut_emisor,ambiente,clave)','tabla' => 'dte_idempotencia', 'columnas' => ['rut_emisor', 'ambiente', 'clave']],
        ],
    ],
    [
        'id' => '002', 'archivo' => '002_apikey_ambiente.sql', 'nota' => 'ALTER',
        'huellas' => [['tipo' => 'columnas', 'desc' => 'api_key.ambiente', 'tabla' => 'api_key', 'columnas' => ['ambiente'], 'esperado' => 1]],
    ],
    [
        'id' => '003', 'archivo' => '003_certificado_sender.sql', 'nota' => 'ALTER',
        'huellas' => [['tipo' => 'columnas', 'desc' => 'dte_certificado.rut_sender', 'tabla' => 'dte_certificado', 'columnas' => ['rut_sender'], 'esperado' => 1]],
    ],
    [
        'id' => '004', 'archivo' => '004_caf_dek.sql', 'nota' => 'ALTER',
        'huellas' => [['tipo' => 'columnas', 'desc' => 'dte_caf.dek_envuelta', 'tabla' => 'dte_caf', 'columnas' => ['dek_envuelta'], 'esperado' => 1]],
    ],
    [
        'id' => '005', 'archivo' => '005_emisor_certificacion.sql', 'nota' => 'ALTER',
        'huellas' => [['tipo' => 'columnas', 'desc' => 'dte_emisor.certificacion_confirmada_at', 'tabla' => 'dte_emisor', 'columnas' => ['certificacion_confirmada_at'], 'esperado' => 1]],
    ],
    [
        'id' => '006', 'archivo' => '006_dte_libro.sql', 'nota' => 'CREATE',
        'huellas' => [['tipo' => 'tablas', 'desc' => 'tabla dte_libro', 'tablas' => ['dte_libro'], 'esperado' => 1]],
    ],
    [
        'id' => '007', 'archivo' => '007_dte_set_pruebas_archivo.sql', 'nota' => 'CREATE',
        'huellas' => [['tipo' => 'tablas', 'desc' => 'tabla dte_set_pruebas_archivo', 'tablas' => ['dte_set_pruebas_archivo'], 'esperado' => 1]],
    ],
    [
        'id' => '008', 'archivo' => '008_dte_intercambio_respuesta.sql', 'nota' => 'CREATE',
        'huellas' => [['tipo' => 'tablas', 'desc' => 'tabla dte_intercambio_respuesta', 'tablas' => ['dte_intercambio_respuesta'], 'esperado' => 1]],
    ],
    [
        'id' => '009', 'archivo' => '009_dte_set_basico_sok.sql', 'nota' => 'CREATE',
        'huellas' => [['tipo' => 'tablas', 'desc' => 'tabla dte_set_basico_sok', 'tablas' => ['dte_set_basico_sok'], 'esperado' => 1]],
    ],
    [
        'id' => '010', 'archivo' => '010_emisor_etapas_manuales.sql', 'nota' => 'ALTER x4',
        'huellas' => [['tipo' => 'columnas', 'desc' => 'dte_emisor: 4 columnas de etapas manuales', 'tabla' => 'dte_emisor',
            'columnas' => ['simulacion_confirmada_at', 'simulacion_track_id', 'intercambio_confirmado_at', 'muestras_impresas_confirmadas_at'], 'esperado' => 4]],
    ],
    [
        'id' => '011', 'archivo' => '011_admin_auditoria.sql', 'nota' => 'CREATE',
        'huellas' => [['tipo' => 'tablas', 'desc' => 'tabla admin_auditoria', 'tablas' => ['admin_auditoria'], 'esperado' => 1]],
    ],
    [
        'id' => '012', 'archivo' => '012_dte_boleta_rvd.sql', 'nota' => 'CREATE',
        'huellas' => [['tipo' => 'tablas', 'desc' => 'tabla dte_boleta_rvd', 'tablas' => ['dte_boleta_rvd'], 'esperado' => 1]],
    ],
    [
        'id' => '013', 'archivo' => '013_dte_emisor_boleta_etapas.sql', 'nota' => 'ALTER x4',
        'huellas' => [['tipo' => 'columnas', 'desc' => 'dte_emisor: 4 columnas de etapas de boleta', 'tabla' => 'dte_emisor',
            'columnas' => ['boleta_revision_solicitada_at', 'boleta_revision_track_id', 'boleta_vobo_at', 'boleta_cumplimiento_confirmado_at'], 'esperado' => 4]],
    ],
    [
        'id' => '014', 'archivo' => '014_dte_emisor_boleta_resultado.sql', 'nota' => 'ALTER',
        'huellas' => [['tipo' => 'columnas', 'desc' => 'dte_emisor.boleta_revision_resultado', 'tabla' => 'dte_emisor', 'columnas' => ['boleta_revision_resultado'], 'esperado' => 1]],
    ],
    [
        'id' => '015', 'archivo' => '015_cliente.sql', 'nota' => 'CREATE',
        'huellas' => [['tipo' => 'tablas', 'desc' => 'tabla cliente', 'tablas' => ['cliente'], 'esperado' => 1]],
    ],
    [
        'id' => '016', 'archivo' => '016_producto.sql', 'nota' => 'CREATE',
        'huellas' => [['tipo' => 'tablas', 'desc' => 'tabla producto', 'tablas' => ['producto'], 'esperado' => 1]],
    ],
    [
        'id' => '017', 'archivo' => '017_apikey_servicio.sql', 'nota' => 'ALTER x3',
        'huellas' => [['tipo' => 'columnas', 'desc' => 'api_key: tipo/secreto_cifrado/dek_envuelta', 'tabla' => 'api_key',
            'columnas' => ['tipo', 'secreto_cifrado', 'dek_envuelta'], 'esperado' => 3]],
    ],
    [
        'id' => '018', 'archivo' => '018_dte_emitido_updated_at.sql', 'nota' => 'ALTER',
        'huellas' => [['tipo' => 'columnas', 'desc' => 'dte_emitido.updated_at', 'tabla' => 'dte_emitido', 'columnas' => ['updated_at'], 'esperado' => 1]],
    ],
    [
        'id' => '019', 'archivo' => '019_lote_carga.sql', 'nota' => 'CREATE',
        'huellas' => [['tipo' => 'tablas', 'desc' => 'tabla lote_carga', 'tablas' => ['lote_carga'], 'esperado' => 1]],
    ],
    [
        'id' => '020', 'archivo' => '020_nota_venta.sql', 'nota' => 'CREATE',
        'huellas' => [['tipo' => 'tablas', 'desc' => 'tabla nota_venta', 'tablas' => ['nota_venta'], 'esperado' => 1]],
    ],
    [
        'id' => '021', 'archivo' => '021_usuario_activacion.sql', 'nota' => 'ALTER x2 + indice UNIQUE',
        'huellas' => [
            ['tipo' => 'columnas', 'desc' => 'usuario: activacion_token/expira', 'tabla' => 'usuario', 'columnas' => ['activacion_token', 'activacion_expira'], 'esperado' => 2],
            ['tipo' => 'indice',   'desc' => 'uk_usuario_activacion_token',     'tabla' => 'usuario', 'indice' => 'uk_usuario_activacion_token'],
        ],
    ],
    [
        // La huella dice que corrio el ALTER, NO que corrio el backfill. Eso se
        // reporta aparte, como advertencia de datos (ver avisoBackfill022()).
        'id' => '022', 'archivo' => '022_dte_folio_proximo_inicial.sql', 'nota' => 'MIXTA: ALTER + UPDATE de backfill',
        'huellas' => [['tipo' => 'columnas', 'desc' => 'dte_folio.proximo_folio_inicial existe', 'tabla' => 'dte_folio', 'columnas' => ['proximo_folio_inicial'], 'esperado' => 1]],
    ],
    [
        'id' => '023', 'archivo' => '023_dte_folio_proximo_inicial_not_null.sql', 'nota' => 'ALTER (misma columna que la 022)',
        'huellas' => [['tipo' => 'nulabilidad', 'desc' => 'proximo_folio_inicial es NOT NULL', 'tabla' => 'dte_folio', 'columna' => 'proximo_folio_inicial', 'esperado_nulabilidad' => 'NO']],
    ],
];

// -----------------------------------------------------------------------------
//  Evaluacion
// -----------------------------------------------------------------------------
function evaluarHuella(PDO $pdo, array $h): array
{
    switch ($h['tipo']) {
        case 'tablas':
            return ['presente' => huellaTablas($pdo, $h['tablas']), 'esperado' => $h['esperado']];
        case 'columnas':
            return ['presente' => huellaColumnas($pdo, $h['tabla'], $h['columnas']), 'esperado' => $h['esperado']];
        case 'indice':
            return ['presente' => huellaIndice($pdo, $h['tabla'], $h['indice']), 'esperado' => 1];
        case 'pk':
            return ['presente' => huellaClavePrimaria($pdo, $h['tabla'], $h['columnas']), 'esperado' => 1];
        case 'nulabilidad':
            return ['presente' => huellaNulabilidad($pdo, $h['tabla'], $h['columna'], $h['esperado_nulabilidad']), 'esperado' => 1];
    }

    throw new RuntimeException("tipo de huella desconocido: {$h['tipo']}");
}

/**
 * El veredicto sale de comparar totales, no de un AND de booleanos: una
 * migracion con 6 huellas de las que 2 estan presentes es PARCIAL, y eso es
 * justamente lo que hoy no se ve.
 */
function veredicto(int $presentes, int $esperados): string
{
    if ($presentes === $esperados) {
        return 'APLICADA';
    }

    return $presentes === 0 ? 'NO_APLICADA' : 'PARCIAL';
}

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
$faltantes = [];
$parciales = [];

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
    if ($v === 'NO_APLICADA') {
        $faltantes[] = $m['id'];
    }
    if ($v === 'PARCIAL') {
        $parciales[] = $m['id'];
    }

    printf(
        "%-5s %-46s %-13s %d/%d%s\n",
        $m['id'],
        $m['archivo'],
        $v,
        $presentes,
        $esperados,
        $detalle === [] ? '' : '  falta: ' . implode('; ', $detalle)
    );
}

echo str_repeat('-', 96), "\n";
printf(
    "RESUMEN: %d aplicadas, %d parciales, %d sin aplicar (de %d)\n",
    $conteo['APLICADA'],
    $conteo['PARCIAL'],
    $conteo['NO_APLICADA'],
    count(MIGRACIONES)
);

if ($parciales !== []) {
    echo "\nPARCIALES (revisar a mano, el archivo no describe este estado): " . implode(', ', $parciales) . "\n";
}
if ($faltantes !== []) {
    echo "SIN APLICAR: " . implode(', ', $faltantes) . "\n";
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

// Exit code: 0 solo si las 23 estan completas. Pensado para engancharlo a un
// despliegue mas adelante sin rehacer nada.
exit($conteo['APLICADA'] === count(MIGRACIONES) ? 0 : 1);
