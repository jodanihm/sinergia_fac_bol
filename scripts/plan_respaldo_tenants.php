<?php

declare(strict_types=1);

/**
 * plan_respaldo_tenants.php -- QUE HAY QUE VOLCAR PARA RESPALDAR A CADA CLIENTE.
 *
 * SOLO LECTURA Y NO RESPALDA NADA. Este script no escribe ni un byte en la base
 * ni en el disco: mira el esquema, arma el plan y lo imprime como JSON en la
 * salida estandar. Quien respalda es scripts/respaldar_tenants.sh, que corre en
 * el host porque ahi estan mysqldump (via el contenedor de MySQL), el disco
 * donde se guardan las copias y la red hacia Nextcloud.
 *
 * POR QUE ESTA PARTIDO EN DOS. La parte dificil de este respaldo no es volcar:
 * es saber QUE filas son de quien, en una base donde todos los clientes
 * comparten las mismas tablas. Eso se decide recorriendo claves foraneas, y esa
 * logica en bash seria injustificable de leer y imposible de probar. Aqui vive
 * en PHP, apoyada en PlanRespaldo, y tiene tests.
 *
 * LA SALIDA ES EL CONTRATO ENTRE LOS DOS. Un JSON con:
 *   base            nombre de la base, leido de DATABASE()
 *   tenants[]       id, nombre, slug, tipo, y sus tablas con el WHERE ya
 *                   resuelto para ESE id
 *   globales[]      tablas que no son de nadie y quedan fuera, por nombre
 *   sin_mapa[]      tablas con datos de un contribuyente que NO se pudieron
 *                   recortar. Si esta lista trae algo, el respaldo esta
 *                   incompleto y el .sh lo denuncia
 *
 * EL SLUG ES PARA EL NOMBRE DEL ARCHIVO y sale del nombre de la cuenta, no del
 * email: un nombre de archivo con arroba y puntos es incomodo de manejar en la
 * consola y en una URL de WebDAV. Lleva el id adelante, que es lo unico
 * estable: dos cuentas pueden llamarse parecido y una puede cambiar de nombre.
 *
 * USO
 *   docker exec sinergia_panel php scripts/plan_respaldo_tenants.php
 *
 * SALIDA
 *   0  plan impreso
 *   2  no se pudo conectar o falta configuracion
 */

require_once __DIR__ . '/../panel/src/AislamientoTenant.php';
require_once __DIR__ . '/../panel/src/PlanRespaldo.php';

// La conexion se arma con las MISMAS variables que usa el panel. No se lee
// ningun fichero de otro proyecto: esa fue la causa de que el respaldo del host
// fallara 85 noches seguidas (ver la cabecera de /data/backups/backup_mysql.sh).
$host = getenv('DB_HOST') ?: '';
$base = getenv('DB_NAME') ?: '';
$user = getenv('DB_USER') ?: '';
$pass = getenv('DB_PASS') ?: '';
$port = getenv('DB_PORT') ?: '3306';

if ($host === '' || $base === '' || $user === '') {
    fwrite(STDERR, "faltan las variables DB_* en este contenedor\n");
    exit(2);
}

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $base),
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'no se pudo conectar: ' . $e->getMessage() . "\n");
    exit(2);
}

// --- El esquema, tal como esta hoy. Filtrando por DATABASE() y no por un nombre
//     escrito a mano: lo que se describe es siempre la base a la que apuntan las
//     credenciales.
$tablas = $pdo->query(
    "SELECT TABLE_NAME FROM information_schema.TABLES "
    . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
)->fetchAll(PDO::FETCH_COLUMN);

$columnasPorTabla = [];
$collations       = [];
foreach ($pdo->query(
    'SELECT TABLE_NAME, COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS '
    . 'WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION'
) as $fila) {
    $columnasPorTabla[$fila['TABLE_NAME']][] = (string) $fila['COLUMN_NAME'];

    // La collation viaja porque hay comparaciones que se hacen SIN una clave
    // foranea que garantice que las dos puntas coinciden. Esta base tiene el
    // esquema partido en dos collations y una comparacion cruzada no devuelve
    // filas de mas: corta la consulta con el error 1267.
    if ($fila['COLLATION_NAME'] !== null) {
        $collations[$fila['TABLE_NAME'] . '.' . $fila['COLUMN_NAME']] = (string) $fila['COLLATION_NAME'];
    }
}

// LAS COLUMNAS DE CADA FK SE REAGRUPAN POR CONSTRAINT. information_schema
// entrega una fila por columna; una FK compuesta partida en dos produciria un
// JOIN por la mitad de la clave, que trae filas de OTRAS empresas. El
// ORDINAL_POSITION manda: (rut_emisor, ambiente) no es lo mismo que
// (ambiente, rut_emisor).
$grupos = [];
foreach ($pdo->query(
    'SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME '
    . 'FROM information_schema.KEY_COLUMN_USAGE '
    . 'WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL '
    . 'ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION'
) as $fila) {
    $clave = $fila['TABLE_NAME'] . "\0" . $fila['CONSTRAINT_NAME'];

    $grupos[$clave]['tabla']         = (string) $fila['TABLE_NAME'];
    $grupos[$clave]['refTabla']      = (string) $fila['REFERENCED_TABLE_NAME'];
    $grupos[$clave]['columnas'][]    = (string) $fila['COLUMN_NAME'];
    $grupos[$clave]['refColumnas'][] = (string) $fila['REFERENCED_COLUMN_NAME'];
}

$plan = PlanRespaldo::construir($tablas, $columnasPorTabla, array_values($grupos), $collations);

// --- Las cuentas. TODAS, incluidas las suspendidas y las internas: una cuenta
//     suspendida es justamente la que mas urge tener respaldada, porque es la
//     candidata a que alguien la borre.
$cuentas = $pdo->query('SELECT id, nombre, email, tipo FROM cuenta ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

$globales = [];
$sinMapa  = [];
$tablasDeTenant = [];

foreach ($plan as $tabla => $detalle) {
    if ($detalle['modo'] === PlanRespaldo::GLOBAL) {
        $globales[] = $tabla;
        continue;
    }
    if ($detalle['modo'] === PlanRespaldo::SIN_MAPA) {
        $sinMapa[] = $tabla;
        continue;
    }
    $tablasDeTenant[$tabla] = $detalle;
}

$salida = [
    'generado'  => date('Y-m-d H:i:s'),
    'base'      => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
    'globales'  => $globales,
    'sin_mapa'  => $sinMapa,
    'tenants'   => [],
];

foreach ($cuentas as $cuenta) {
    $id     = (int) $cuenta['id'];
    $nombre = (string) $cuenta['nombre'];

    $tablasDelTenant = [];
    foreach ($tablasDeTenant as $tabla => $detalle) {
        $tablasDelTenant[] = [
            'tabla' => $tabla,
            'modo'  => $detalle['modo'],
            // El unico valor que entra al WHERE es este entero, que sale de la
            // clave primaria de cuenta. No hay nada aqui que venga de afuera.
            'where' => sprintf((string) $detalle['where'], $id),
        ];
    }

    $salida['tenants'][] = [
        'id'     => $id,
        'nombre' => $nombre,
        'tipo'   => (string) $cuenta['tipo'],
        'slug'   => $id . '-' . slugDeCuenta($nombre !== '' ? $nombre : (string) $cuenta['email']),
        'tablas' => $tablasDelTenant,
    ];
}

echo json_encode($salida, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
exit(0);

/**
 * Nombre de archivo seguro a partir del nombre de la cuenta.
 *
 * Se queda con letras, numeros y guiones. No es cosmetica: este texto termina
 * en una ruta del disco y en una URL de WebDAV, y un nombre de empresa trae
 * puntos, comas, tildes y a veces una barra.
 */
function slugDeCuenta(string $nombre): string
{
    $limpio = strtolower(strtr($nombre, [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u',
        'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ñ' => 'n', 'Ü' => 'u',
    ]));
    $limpio = (string) preg_replace('/[^a-z0-9]+/', '-', $limpio);
    $limpio = trim($limpio, '-');

    return $limpio === '' ? 'cuenta' : substr($limpio, 0, 40);
}
