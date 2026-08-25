<?php

declare(strict_types=1);

/**
 * Siembra el backlog inicial en la tabla pendiente (migracion 044).
 *
 * PARA QUE. Los pendientes vivian en panel/datos/pendientes.php, un array a
 * mano. La 044 los mueve a la base para poder cambiarles el estado desde la
 * pantalla sin desplegar. Este script hace ese traslado UNA vez, y despues
 * queda como el registro de donde salio cada item: si manana alguien vacia la
 * tabla, esto la repuebla con el mismo contenido.
 *
 * POR QUE NO VA EN LA MIGRACION. Ninguna de las 44 migraciones de este proyecto
 * trae datos: son esquema y nada mas. Mezclar contenido ahi rompe esa
 * propiedad -- una migracion se aplica una vez y no se vuelve a mirar, mientras
 * que un dato semilla se corrige, se reordena y se vuelve a correr. Mismo
 * criterio que scripts/sembrar_demo.php.
 *
 * EL CONTENIDO ESTA AQUI DENTRO Y NO SE LEE DEL ARCHIVO VIEJO, a proposito.
 * panel/datos/pendientes.php se borra en el mismo commit que introduce esto: un
 * script que dependa de un archivo que ya no existe es un script roto que nadie
 * descubre hasta que lo necesita.
 *
 * ES IDEMPOTENTE POR TITULO. Correrlo dos veces no duplica nada: cada item se
 * salta si ya hay uno con el mismo titulo. NO ACTUALIZA el que ya esta, y eso
 * tambien es a proposito -- si alguien movio un pendiente a 'en_curso' desde la
 * pantalla, este script no tiene por que devolverlo a 'abierto'. La semilla
 * siembra; no manda.
 *
 * NO BORRA NADA. A diferencia de sembrar_demo.php, que limpia lo suyo antes de
 * escribir, aca no hay un DELETE previo: la tabla puede tener items creados
 * despues, y esos no son de este script.
 *
 * Variables de entorno: DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS.
 *
 * Uso:
 *   docker exec sinergia_panel php scripts/sembrar_pendientes.php
 *   docker exec sinergia_panel php scripts/sembrar_pendientes.php --dry-run
 */

// -----------------------------------------------------------------------------
//  El backlog, tal como quedo al migrarlo. Los seis venian de
//  panel/datos/pendientes.php; area, categoria, prioridad y severidad son
//  nuevas -- el archivo no las tenia -- y se asignaron leyendo cada detalle.
// -----------------------------------------------------------------------------
const SEMILLA = [
    [
        'area' => 'datos', 'categoria' => 'seguridad', 'prioridad' => 'P1', 'severidad' => 'alta',
        'estado' => 'abierto',
        'titulo' => '12 tablas guardan documentos sin poder decir de que empresa son',
        'detalle' => 'Lo dejo a la vista la columna de aislamiento de /admin/base-datos: dte_emitido, '
            . 'dte_caf, dte_certificado, dte_folio, dte_libro, dte_idempotencia y companhia cuelgan de '
            . 'rut_emisor y no tienen cuenta_id ni ninguna clave foranea hacia cuenta. Son las que guardan '
            . 'los documentos tributarios, o sea las que mas caro cuestan si se filtran, y hoy ninguna '
            . 'restriccion de la base impide que una consulta mal escrita mezcle dos contribuyentes. '
            . 'Agregarles cuenta_id es una migracion grande y con backfill; antes hay que decidir si '
            . 'conviene eso o una convencion verificada de otra forma. No es un bug abierto: es un riesgo '
            . 'estructural que ahora se puede mirar.',
    ],
    [
        'area' => 'datos', 'categoria' => 'deuda', 'prioridad' => 'P2', 'severidad' => 'media',
        'estado' => 'abierto',
        'titulo' => 'La cuenta demo hace fallar la consulta de veredictos cada 15 minutos',
        'detalle' => 'Desde el 05-08-2026 a las 12:00, cada corrida de consultar_veredictos_pendientes.php '
            . 'registra tres FALLO con "Fallo descifrado AES-256-GCM" para el RUT 76543210-3, que es '
            . 'DEMO_RUT en scripts/sembrar_demo.php. No es un dato corrupto ni una llave rotada: la siembra '
            . 'de la demo inserta un certificado de RELLENO a proposito, y lo dice en su cabecera -- se '
            . 'puede porque ninguna ruta GET del panel lo descifra, y el unico camino que descifra material '
            . 'criptografico es el de emision, que es POST y esta bloqueado para la demo. Ese razonamiento '
            . 'tiene un hueco: el CRON no es ninguna de las dos cosas. La siembra deja tres documentos en '
            . 'estado "enviado" con track_id (folios 78, 79 y 80), el cron los toma como pendientes de '
            . 'veredicto, intenta descifrar el certificado para autenticarse ante el SII y falla. Va a '
            . 'seguir fallando para siempre, porque nada lo saca de ese estado. Cuesta poco -- que la '
            . 'siembra deje esos tres en un estado terminal (EPR o RCT) como los otros 280, o que el cron '
            . 'salte el RUT de la demo -- y lo caro es lo otro: 48 de las ultimas 60 lineas de esa bitacora '
            . 'son este fallo, asi que un fallo NUEVO y real llega a un log donde ya nadie mira. Se vio al '
            . 'estrenar /admin/tareas, que es justo para lo que se hizo.',
    ],
    [
        'area' => 'panel', 'categoria' => 'producto', 'prioridad' => 'P2', 'severidad' => 'media',
        'estado' => 'abierto',
        'titulo' => 'Vencimiento de los certificados digitales',
        'detalle' => 'dte_certificado no guarda la vigencia: la fecha vive DENTRO del certificado, en '
            . 'cert_data_cifrado. Por eso la ficha de cuenta no la lista y la portada no tiene la alerta '
            . 'de "certificados proximos a vencer" que si tiene las de folios y correos. Calcularla '
            . 'obligaria a descifrar el certificado de cada cuenta en cada carga de pantalla, que no es '
            . 'aceptable; la salida razonable es guardar la fecha al cargarlo, en una columna nueva.',
    ],
    [
        'area' => 'transversal', 'categoria' => 'deuda', 'prioridad' => 'P2', 'severidad' => 'media',
        'estado' => 'abierto',
        'titulo' => 'La suite tiene 38 errores y 5 fallos de arrastre',
        'detalle' => 'Vienen de antes de este trabajo y no los introdujo ningun cambio reciente: se usan '
            . 'como linea base para comparar antes y despues de cada entrega. Mientras esten, la suite no '
            . 'sirve como semaforo -- nadie puede mirar "esta en rojo" y sacar una conclusion --, asi que '
            . 'cada verificacion tiene que compararse contra el mismo numero a mano. Hay que revisarlos y '
            . 'dejarlos en cero o declararlos ignorados con su motivo.',
    ],
    [
        'area' => 'datos', 'categoria' => 'deuda', 'prioridad' => 'P3', 'severidad' => 'baja',
        'estado' => 'abierto',
        'titulo' => 'No hay cuenta de demostracion en la base local',
        'detalle' => 'scripts/sembrar_demo.php siembra contra la base del VPS. En una base local recien '
            . 'creada el panel queda sin datos y las pantallas se ven vacias, que es un mal primer contacto '
            . 'para quien clona el repo por primera vez y no sabe si esta viendo un bug o una base sin '
            . 'sembrar.',
    ],
    [
        'area' => 'infra', 'categoria' => 'deuda', 'prioridad' => 'P3', 'severidad' => 'baja',
        'estado' => 'abierto',
        'titulo' => 'graphify update se niega a escribir el grafo',
        'detalle' => 'El grafo de graphify-out/ queda desactualizado cuando la actualizacion no puede '
            . 'escribir. Mientras tanto, las consultas al grafo responden sobre una foto vieja del codigo, '
            . 'que es peor que no tener grafo: da respuestas con confianza sobre archivos que ya cambiaron.',
    ],
];

// -----------------------------------------------------------------------------

$dryRun = in_array('--dry-run', $argv, true);

function fail(string $mensaje, int $codigo = 1): never
{
    fwrite(STDERR, "ERROR: {$mensaje}\n");
    exit($codigo);
}

function requerirEnv(string $nombre): string
{
    $v = getenv($nombre);

    if ($v === false || $v === '') {
        fail("Falta la variable de entorno {$nombre}.");
    }

    return $v;
}

$host = requerirEnv('DB_HOST');
$port = getenv('DB_PORT') ?: '3306';
$name = requerirEnv('DB_NAME');
$user = requerirEnv('DB_USER');
$pass = requerirEnv('DB_PASS');

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    fail('No se pudo conectar a la base: ' . $e->getMessage(), 2);
}

// La tabla la crea la migracion 044, no este script. Si no esta, se dice cual
// falta en vez de dejar que PDO tire un "table doesn't exist" pelado.
$existe = $pdo->query("SHOW TABLES LIKE 'pendiente'")->fetchColumn();

if ($existe === false) {
    fail("La tabla 'pendiente' no existe. Falta aplicar la migracion 044_pendiente.sql.", 3);
}

$buscar   = $pdo->prepare('SELECT id FROM pendiente WHERE titulo = :t LIMIT 1');
$insertar = $pdo->prepare(
    'INSERT INTO pendiente (area, categoria, prioridad, severidad, estado, titulo, detalle) '
    . 'VALUES (:area, :categoria, :prioridad, :severidad, :estado, :titulo, :detalle)'
);

$creados = 0;
$saltados = 0;

echo $dryRun ? "*** DRY-RUN: no se escribe nada ***\n" : '';

foreach (SEMILLA as $p) {
    $buscar->execute([':t' => $p['titulo']]);

    if ($buscar->fetchColumn() !== false) {
        $saltados++;
        printf("  SALTADO  %s\n", $p['titulo']);
        continue;
    }

    if (! $dryRun) {
        $insertar->execute([
            ':area'      => $p['area'],
            ':categoria' => $p['categoria'],
            ':prioridad' => $p['prioridad'],
            ':severidad' => $p['severidad'],
            ':estado'    => $p['estado'],
            ':titulo'    => $p['titulo'],
            ':detalle'   => $p['detalle'],
        ]);
    }

    $creados++;
    printf("  %s  %-3s %-12s %s\n", $dryRun ? 'CREARIA' : 'CREADO ', $p['prioridad'], $p['area'], $p['titulo']);
}

printf("\nRESUMEN creados=%d saltados=%d (de %d en la semilla)\n", $creados, $saltados, count(SEMILLA));
exit(0);
