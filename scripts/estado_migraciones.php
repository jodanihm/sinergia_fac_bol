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

/**
 * Cuantos de $valores estan declarados en el ENUM de $tabla.$columna.
 *
 * ES EL MISMO PROBLEMA QUE RESOLVIO huellaNulabilidad() PARA EL PAR 022/023, y
 * por eso hace falta un tipo de huella propio: la 039 no crea la columna
 * chat_consulta.desenlace -- esa existe desde la 035 --, le AGREGA valores. Una
 * huella que solo preguntara "existe la columna" daria la 039 por aplicada en
 * cuanto hubiera corrido la 035.
 *
 * Devuelve CUANTOS encontro y no un si/no, para que la entrada declare cuantos
 * espera y un ENUM ampliado a medias salga PARCIAL en vez de NO_APLICADA.
 *
 * Se busca el valor ENTRE COMILLAS ('armando' y no armando): COLUMN_TYPE llega
 * como enum('a','b','c') y un valor suelto podria ser subcadena de otro.
 */
function huellaValoresEnum(PDO $pdo, string $tabla, string $columna, array $valores): int
{
    $stmt = $pdo->prepare(
        'SELECT column_type FROM information_schema.columns '
        . 'WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
    );
    $stmt->execute([$tabla, $columna]);
    $tipo = $stmt->fetchColumn();
    if ($tipo === false) {
        return 0;   // no existe ni la columna
    }

    $presentes = 0;
    foreach ($valores as $valor) {
        if (str_contains((string) $tipo, "'" . $valor . "'")) {
            $presentes++;
        }
    }

    return $presentes;
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
//  LAS MIGRACIONES Y SUS HUELLAS.  (decia "las 23" y ya iban 29; el numero se
//  quita para que no vuelva a quedar viejo cada vez que se agrega una)
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
        // DIFERIDA A PROPOSITO, no olvidada. El razonamiento completo -- las dos
        // condiciones que hay que cumplir y por que la migracion se partio en
        // dos -- esta en la cabecera de 023_dte_folio_proximo_inicial_not_null.sql,
        // que es donde corresponde y donde se mantiene. Aqui va lo justo para
        // que el verificador sepa que su ausencia no es un descuido.
        'diferida' => 'Se aplica SOLO cuando no queden nulos en dte_folio y el codigo que escribe '
            . 'proximo_folio_inicial lleve tiempo en produccion. Las dos condiciones y el porque de '
            . 'partirla en dos estan en la cabecera de su .sql. Ver tambien el bloque DATOS de mas '
            . 'abajo, que dice si hoy esta LISTA o BLOQUEADA.',
        'huellas' => [['tipo' => 'nulabilidad', 'desc' => 'proximo_folio_inicial es NOT NULL', 'tabla' => 'dte_folio', 'columna' => 'proximo_folio_inicial', 'esperado_nulabilidad' => 'NO']],
    ],
    [
        'id' => '024', 'archivo' => '024_dte_envio_correo.sql', 'nota' => 'CREATE',
        'huellas' => [['tipo' => 'tablas', 'desc' => 'tabla dte_envio_correo', 'tablas' => ['dte_envio_correo'], 'esperado' => 1]],
    ],
    [
        // DOS huellas y no una: la migracion toca DOS tablas, y con una sola
        // huella un ALTER a medias (por ejemplo, corte entre los dos PREPARE) se
        // reportaria como APLICADA. Separadas, ese caso sale como PARCIAL, que es
        // lo que de verdad paso.
        'id' => '025', 'archivo' => '025_nota_venta_tipo_dte.sql', 'nota' => 'ALTER (2 tablas)',
        'huellas' => [
            ['tipo' => 'columnas', 'desc' => 'nota_venta.tipo_dte', 'tabla' => 'nota_venta', 'columnas' => ['tipo_dte'], 'esperado' => 1],
            ['tipo' => 'columnas', 'desc' => 'lote_carga.tipo_dte', 'tabla' => 'lote_carga', 'columnas' => ['tipo_dte'], 'esperado' => 1],
        ],
    ],
    [
        // Una huella POR TABLA, mismo criterio que la 025: la migracion toca dos
        // tablas con dos ALTER independientes, y con una sola huella un corte
        // entre ambos se reportaria como APLICADA. Cada huella pide las DOS
        // columnas de su tabla, asi que tambien detecta un ALTER truncado.
        'id' => '026', 'archivo' => '026_forma_pago_vencimiento.sql', 'nota' => 'ALTER (2 tablas)',
        'huellas' => [
            ['tipo' => 'columnas', 'desc' => 'dte_emitido.forma_pago + fecha_vencimiento', 'tabla' => 'dte_emitido', 'columnas' => ['forma_pago', 'fecha_vencimiento'], 'esperado' => 2],
            ['tipo' => 'columnas', 'desc' => 'nota_venta.forma_pago + fecha_vencimiento', 'tabla' => 'nota_venta', 'columnas' => ['forma_pago', 'fecha_vencimiento'], 'esperado' => 2],
        ],
    ],
    [
        // Una sola tabla y una sola columna, asi que basta una huella: no hay
        // ALTER parcial posible que esta no detecte.
        'id' => '027', 'archivo' => '027_dte_emitido_glosa_sii.sql', 'nota' => 'ALTER (1 tabla)',
        'huellas' => [
            ['tipo' => 'columnas', 'desc' => 'dte_emitido.glosa_sii', 'tabla' => 'dte_emitido', 'columnas' => ['glosa_sii'], 'esperado' => 1],
        ],
    ],
    [
        // UNA huella con las DOS columnas: van en un solo ALTER bajo una sola
        // guarda, asi que no existe el corte a medias que obligaba a la 025 y
        // la 026 a llevar una huella por tabla.
        'id' => '028', 'archivo' => '028_dte_emitido_exento_impuesto_adicional.sql', 'nota' => 'ALTER (1 tabla)',
        'huellas' => [
            ['tipo' => 'columnas', 'desc' => 'dte_emitido.exento + impuesto_adicional', 'tabla' => 'dte_emitido', 'columnas' => ['exento', 'impuesto_adicional'], 'esperado' => 2],
        ],
    ],
    [
        // Una sola columna en una sola tabla: igual que la 027, una huella basta
        // y no hay ALTER parcial posible que se le escape.
        'id' => '029', 'archivo' => '029_usuario_demo.sql', 'nota' => 'ALTER (1 tabla)',
        'huellas' => [
            ['tipo' => 'columnas', 'desc' => 'usuario.demo', 'tabla' => 'usuario', 'columnas' => ['demo'], 'esperado' => 1],
        ],
    ],
    [
        // Las CUATRO columnas van en un solo ALTER bajo una sola guarda, igual
        // que la 028: una huella con las cuatro alcanza.
        'id' => '030', 'archivo' => '030_dte_emitido_contadores_sii.sql', 'nota' => 'ALTER (1 tabla)',
        'huellas' => [
            ['tipo' => 'columnas', 'desc' => 'dte_emitido.sii_*', 'tabla' => 'dte_emitido',
             'columnas' => ['sii_informados', 'sii_aceptados', 'sii_rechazados', 'sii_reparos'], 'esperado' => 4],
        ],
    ],
    [
        // DOS huellas y no una: la tabla puede existir sin su UNIQUE si alguien
        // la creo a mano, y ese UNIQUE es lo que garantiza UN logo por empresa.
        // Ademas se comprueba que NO tenga columna ambiente: el logo es de la
        // empresa, y si apareciera ahi seria otra tabla distinta de esta.
        'id' => '031', 'archivo' => '031_dte_logo.sql', 'nota' => 'CREATE (1 tabla)',
        'huellas' => [
            ['tipo' => 'tablas', 'desc' => 'dte_logo', 'tablas' => ['dte_logo'], 'esperado' => 1],
            ['tipo' => 'indice', 'desc' => 'uk_logo_emisor', 'tabla' => 'dte_logo', 'indice' => 'uk_logo_emisor'],
        ],
    ],
    [
        // TRES huellas, una por tabla, y ademas el UNIQUE del correlativo.
        // uk_cotizacion_numero es lo que impide que dos altas simultaneas se
        // lleven el mismo numero si alguna vez fallara el FOR UPDATE del
        // repositorio: es la ultima linea de defensa y tiene que estar.
        'id' => '032', 'archivo' => '032_cotizacion.sql', 'nota' => 'CREATE (3 tablas)',
        'huellas' => [
            ['tipo' => 'tablas', 'desc' => 'cotizacion, cotizacion_linea, cotizacion_correlativo',
             'tablas' => ['cotizacion', 'cotizacion_linea', 'cotizacion_correlativo'], 'esperado' => 3],
            ['tipo' => 'indice', 'desc' => 'uk_cotizacion_numero', 'tabla' => 'cotizacion', 'indice' => 'uk_cotizacion_numero'],
            ['tipo' => 'columnas', 'desc' => 'cotizacion_linea.cantidad_facturada', 'tabla' => 'cotizacion_linea',
             'columnas' => ['cantidad_facturada'], 'esperado' => 1],
        ],
    ],
    [
        // El UNIQUE de la clave de idempotencia es la huella que importa: es lo
        // que permitiria construir un reconciliador el dia que haga falta. Sin
        // el, un descuento que fallara despues del 201 no tendria como
        // identificarse para repararlo sin adivinar.
        'id' => '033', 'archivo' => '033_cotizacion_factura.sql', 'nota' => 'CREATE (2 tablas)',
        'huellas' => [
            ['tipo' => 'tablas', 'desc' => 'cotizacion_factura, cotizacion_factura_linea',
             'tablas' => ['cotizacion_factura', 'cotizacion_factura_linea'], 'esperado' => 2],
            ['tipo' => 'indice', 'desc' => 'uk_cot_factura_idem', 'tabla' => 'cotizacion_factura', 'indice' => 'uk_cot_factura_idem'],
            ['tipo' => 'indice', 'desc' => 'uk_cot_factura_linea', 'tabla' => 'cotizacion_factura_linea', 'indice' => 'uk_cot_factura_linea'],
        ],
    ],
    [
        // Una tabla y su columna. Si falta, el chat no tiene donde contar y cada
        // pregunta gastaria saldo sin tope.
        'id' => '034', 'archivo' => '034_chat_consulta_uso.sql', 'nota' => 'CREATE (1 tabla)',
        'huellas' => [
            ['tipo' => 'tablas', 'desc' => 'chat_consulta_uso', 'tablas' => ['chat_consulta_uso'], 'esperado' => 1],
            ['tipo' => 'columnas', 'desc' => 'chat_consulta_uso.consultas', 'tabla' => 'chat_consulta_uso',
             'columnas' => ['consultas'], 'esperado' => 1],
        ],
    ],
    [
        // El historial de preguntas. REVIERTE la decision de la 034 de no
        // guardarlas, y la 035 explica por que: la tarjeta de actividad reciente
        // le da el motivo que antes faltaba.
        'id' => '035', 'archivo' => '035_chat_consulta.sql', 'nota' => 'CREATE (1 tabla)',
        'huellas' => [
            ['tipo' => 'tablas', 'desc' => 'chat_consulta', 'tablas' => ['chat_consulta'], 'esperado' => 1],
            ['tipo' => 'columnas', 'desc' => 'chat_consulta.pregunta/desenlace', 'tabla' => 'chat_consulta',
             'columnas' => ['pregunta', 'desenlace'], 'esperado' => 2],
        ],
    ],
    [
        // El UNIQUE es la huella que importa: es lo que permite que un mismo RUT
        // sea cliente Y proveedor de la misma cuenta sin chocar, que fue el
        // motivo entero de hacer una tabla aparte en vez de generalizar cliente.
        'id' => '036', 'archivo' => '036_proveedor.sql', 'nota' => 'CREATE (1 tabla)',
        'huellas' => [
            ['tipo' => 'tablas', 'desc' => 'proveedor', 'tablas' => ['proveedor'], 'esperado' => 1],
            ['tipo' => 'indice', 'desc' => 'uk_proveedor_rut', 'tabla' => 'proveedor', 'indice' => 'uk_proveedor_rut'],
            ['tipo' => 'columnas', 'desc' => 'proveedor.contacto/condiciones_pago', 'tabla' => 'proveedor',
             'columnas' => ['contacto', 'condiciones_pago'], 'esperado' => 2],
        ],
    ],
    [
        // Las tres tablas y el UNIQUE del correlativo, que es la ultima linea de
        // defensa si alguna vez fallara el FOR UPDATE del repositorio.
        'id' => '037', 'archivo' => '037_orden_compra.sql', 'nota' => 'CREATE (3 tablas)',
        'huellas' => [
            ['tipo' => 'tablas', 'desc' => 'orden_compra, orden_compra_linea, orden_compra_correlativo',
             'tablas' => ['orden_compra', 'orden_compra_linea', 'orden_compra_correlativo'], 'esperado' => 3],
            ['tipo' => 'indice', 'desc' => 'uk_orden_compra_numero', 'tabla' => 'orden_compra', 'indice' => 'uk_orden_compra_numero'],
            // Los totales CONGELADOS: si estas columnas faltaran, alguien los
            // estaria recalculando al mostrar y el papel del proveedor podria
            // dejar de coincidir con la pantalla.
            ['tipo' => 'columnas', 'desc' => 'orden_compra.neto/iva/total', 'tabla' => 'orden_compra',
             'columnas' => ['neto', 'exento', 'iva', 'total'], 'esperado' => 4],
        ],
    ],
    [
        // La cola PROPIA. Si esta faltara, el envio no tendria donde encolarse y
        // la tentacion seria colgarlo de dte_envio_correo, que tiene FK
        // obligatoria a dte_emitido y no admite una orden de compra.
        'id' => '038', 'archivo' => '038_orden_compra_envio.sql', 'nota' => 'CREATE (1 tabla)',
        'huellas' => [
            ['tipo' => 'tablas', 'desc' => 'orden_compra_envio', 'tablas' => ['orden_compra_envio'], 'esperado' => 1],
            ['tipo' => 'indice', 'desc' => 'uk_oc_envio', 'tabla' => 'orden_compra_envio', 'indice' => 'uk_oc_envio'],
            ['tipo' => 'columnas', 'desc' => 'orden_compra_envio.message_id', 'tabla' => 'orden_compra_envio',
             'columnas' => ['message_id'], 'esperado' => 1],
        ],
    ],
    [
        // NO se comprueba "existe chat_consulta.desenlace": esa columna es de la
        // 035 y daria esta migracion por aplicada sin que nadie corriera el ALTER.
        // Lo que la 039 hace es AMPLIAR su ENUM, asi que la huella mira los
        // valores -- mismo criterio que la nulabilidad separa la 022 de la 023
        // sobre una sola columna.
        //
        // Si faltaran, el sintoma no seria un error visible: en modo no estricto
        // MySQL guardaria la cadena vacia y el historial del chat empezaria a
        // mentir en silencio sobre como termino cada turno.
        'id' => '039', 'archivo' => '039_chat_consulta_desenlace_armado.sql', 'nota' => 'ALTER (1 tabla)',
        'huellas' => [
            ['tipo' => 'valores_enum', 'desc' => "chat_consulta.desenlace 'armando'/'borrador'",
             'tabla' => 'chat_consulta', 'columna' => 'desenlace',
             'valores' => ['armando', 'borrador'], 'esperado' => 2],
        ],
    ],
    [
        // DOS huellas, y la segunda no sobra. La columna tiene que ser NOT NULL:
        // MySqlChatUsoRepository::limiteDiario() castea lo que lee con (int), y un
        // NULL se convertiria en 0 -- que es un tope valido y significa "chat
        // apagado para esta cuenta". O sea que una columna nulable no daria un
        // error: apagaria el chat en silencio para quien tuviera NULL.
        'id' => '040', 'archivo' => '040_cuenta_chat_limite_diario.sql', 'nota' => 'ALTER (1 tabla)',
        'huellas' => [
            ['tipo' => 'columnas', 'desc' => 'cuenta.chat_limite_diario', 'tabla' => 'cuenta',
             'columnas' => ['chat_limite_diario'], 'esperado' => 1],
            ['tipo' => 'nulabilidad', 'desc' => 'cuenta.chat_limite_diario NOT NULL', 'tabla' => 'cuenta',
             'columna' => 'chat_limite_diario', 'esperado_nulabilidad' => 'NO'],
        ],
    ],
    [
        // EL UNIQUE ES LA HUELLA QUE IMPORTA, no la tabla. Desde que las filas del
        // mismo cliente se agrupan, nota_venta.identificador_externo guarda solo el
        // PRIMERO de cada grupo: uk_nota_venta_origen es el que impide recargar el
        // mismo Excel. Sin el, la tabla existiria y la proteccion no.
        'id' => '041', 'archivo' => '041_nota_venta_origen.sql', 'nota' => 'CREATE (1 tabla) + ALTER',
        'huellas' => [
            ['tipo' => 'tablas', 'desc' => 'nota_venta_origen', 'tablas' => ['nota_venta_origen'], 'esperado' => 1],
            ['tipo' => 'indice', 'desc' => 'uk_nota_venta_origen', 'tabla' => 'nota_venta_origen',
             'indice' => 'uk_nota_venta_origen'],
            ['tipo' => 'columnas', 'desc' => 'lote_carga.total_documentos', 'tabla' => 'lote_carga',
             'columnas' => ['total_documentos'], 'esperado' => 1],
        ],
    ],
    [
        'id' => '042', 'archivo' => '042_rol_permiso.sql', 'nota' => 'CREATE (2 tablas) + ALTER',
        'huellas' => [
            ['tipo' => 'tablas', 'desc' => 'rol + permiso', 'tablas' => ['rol', 'permiso'], 'esperado' => 2],
            ['tipo' => 'indice', 'desc' => 'uk_rol_cuenta_nombre', 'tabla' => 'rol',
             'indice' => 'uk_rol_cuenta_nombre'],
            ['tipo' => 'columnas', 'desc' => 'usuario.rol_id', 'tabla' => 'usuario',
             'columnas' => ['rol_id'], 'esperado' => 1],
        ],
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
        case 'valores_enum':
            return ['presente' => huellaValoresEnum($pdo, $h['tabla'], $h['columna'], $h['valores']), 'esperado' => $h['esperado']];
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
