<?php

declare(strict_types=1);

/**
 * Vacia la cola dte_envio_correo: toma las filas pendientes y las envia, con
 * tope por corrida, presupuesto diario y candado contra corridas superpuestas.
 *
 * USO:
 *   php scripts/enviar_correos_pendientes.php [--dry-run] [--tope=N]
 *
 * PENSADO PARA CRON, cada 5 minutos:
 *   *_/5 * * * * docker exec sinergia_motor php /app/scripts/enviar_correos_pendientes.php >> /var/log/sinergia_correos.log 2>&1
 *   (el guion bajo de *_/5 es para no cerrar este comentario; va sin el)
 *
 * VARIABLES DE ENTORNO:
 *   DB_HOST, DB_NAME, DB_USER, DB_PASS   -> conexion MySQL (DB_PORT opcional).
 *   BREVO_API_KEY                        -> clave de la API de Brevo.
 *   CORREO_REMITENTE                     -> direccion unica del sistema.
 *   CORREO_REMITENTE_NOMBRE              -> nombre visible de respaldo.
 *   CORREO_TOPE_POR_CORRIDA              -> opcional, default 50.
 *   CORREO_TOPE_INTENTOS                 -> opcional, default 3.
 *   CORREO_PRESUPUESTO_DIARIO            -> opcional, default 250.
 *
 * EXIT CODES:
 *   0  la corrida termino sin fallos (incluye "no habia nada que enviar")
 *   1  hubo al menos un fallo de envio (las filas quedaron en 'error')
 *   2  configuracion o conexion incompleta
 *   3  otra corrida tiene el candado: esta no proceso nada
 *   4  se agoto el presupuesto diario antes de vaciar la cola
 *
 * QUE SIGNIFICA 'enviado' -- ver el docblock de PreparadorEnvio antes de creer
 * en esa columna: quiere decir que BREVO ACEPTO el mensaje, no que el receptor
 * lo recibio. Un destinatario bloqueado en Brevo devuelve 2xx igual y el correo
 * no se entrega nunca. Por eso esta corrida deja el messageId de Brevo en el
 * log: convierte un "no me llego" en una busqueda exacta en su panel.
 */

require __DIR__ . '/../vendor/autoload.php';

use Plantiflex\FacturacionCl\Correo\BrevoMailer;
use Plantiflex\FacturacionCl\Correo\PreparadorEnvio;

/**
 * TOPE POR CORRIDA. Por que 50:
 *
 *   Medido en el contenedor: generar el PDF cuesta ~175 ms (min 150, max 208) y
 *   pico de 22 MB de memoria. Sumando la ida y vuelta a Brevo, cada documento
 *   ronda 0,5-1 s. 50 documentos son ~40 s: el 13% del intervalo de 5 minutos
 *   del cron. Incluso con la red tres veces mas lenta (3 s por documento) la
 *   corrida termina en 150 s y sigue cerrando antes de que arranque la
 *   siguiente, asi que el candado casi nunca deberia entrar en juego.
 *
 *   Por arriba tampoco estorba: 50 x 288 corridas al dia son 14.400 envios de
 *   capacidad teorica, muy por encima de cualquier demanda real. El limite que
 *   manda no es este sino el PRESUPUESTO DIARIO.
 *
 *   Y drena de a poco, que es el motivo de la cadencia: los 400-600 correos que
 *   el cliente grande concentra a fin de mes se vacian en ~12 corridas, o sea
 *   una hora, en vez de estrellarse de una vez contra la cuota.
 */
const TOPE_POR_CORRIDA_DEFAULT = 50;

/**
 * TOPE DE INTENTOS. Por que 3:
 *
 *   Con el punto ciego de los bloqueados aceptado (ver PreparadorEnvio), un
 *   reintento SOLO sirve para fallos transitorios: un corte de red, un 429, un
 *   5xx de Brevo. Ninguno de esos dura tres corridas de cron seguidas, o sea 15
 *   minutos. Lo que NO arregla ningun reintento es una direccion invalida o un
 *   rechazo permanente: eso falla igual la vez 3 que la vez 300.
 *
 *   Al alcanzar el tope la fila se queda en 'error' con su ultimo_error y el
 *   runner deja de tomarla. No se borra ni se oculta: queda visible para que
 *   alguien la mire. Reintentarla es una decision humana -- basta con volver su
 *   estado a 'pendiente'.
 */
const TOPE_INTENTOS_DEFAULT = 3;

/**
 * PRESUPUESTO DIARIO. Por que 250, y por que es una red y no una solucion:
 *
 *   La cuota diaria del plan de Brevo se COMPARTE con otros proyectos, asi que
 *   este runner no puede gastarla entera. 250 deja margen bajo el escalon de 300
 *   diarios del plan gratuito, que es el piso conocido.
 *
 *   ESTE NUMERO CASI SEGURO HAY QUE AJUSTARLO: se calcula sobre el plan real,
 *   que este codigo no conoce. Por eso es configurable y por eso, al agotarse,
 *   el log grita en vez de susurrar. La solucion de verdad es subir el plan
 *   antes de que entre el cliente grande; esto solo evita que la cuota se agote
 *   en silencio y deje sin correo a los demas proyectos.
 *
 *   Se cuenta del propio dte_envio_correo -- los 'enviado' con enviado_at de
 *   hoy -- para no agregar ninguna columna ni tabla.
 */
const PRESUPUESTO_DIARIO_DEFAULT = 250;

/** Nombre del candado de MySQL. Ver el comentario de adquirirCandado(). */
const CANDADO = 'sinergia_envio_correos';

function fail(string $msg, int $code = 2): never
{
    fwrite(STDERR, "ERROR: {$msg}\n");
    exit($code);
}

function linea(string $msg): void
{
    fwrite(STDOUT, date('Y-m-d H:i:s') . ' ' . $msg . "\n");
}

function requerirEnv(string $nombre): string
{
    $v = getenv($nombre);
    if ($v === false || trim($v) === '') {
        fail("Falta la variable de entorno {$nombre}.");
    }

    return $v;
}

function enteroDeEnv(string $nombre, int $porDefecto): int
{
    $v = getenv($nombre);
    if ($v === false || trim($v) === '') {
        return $porDefecto;
    }
    if (! ctype_digit(trim($v)) || (int) $v <= 0) {
        fail("{$nombre} debe ser un entero positivo, llego '{$v}'.");
    }

    return (int) $v;
}

function conectarDb(): PDO
{
    $host = requerirEnv('DB_HOST');
    $name = requerirEnv('DB_NAME');
    $user = requerirEnv('DB_USER');
    $pass = getenv('DB_PASS');
    $pass = $pass === false ? '' : $pass;
    $port = getenv('DB_PORT');
    $port = $port === false ? '3306' : $port;

    try {
        return new PDO(
            "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    } catch (PDOException $e) {
        fail('No se pudo conectar a la base: ' . $e->getMessage());
    }
}

/**
 * CANDADO CONTRA CORRIDAS SUPERPUESTAS, con GET_LOCK de MySQL.
 *
 * POR QUE GET_LOCK Y NO UN flock() SOBRE UN ARCHIVO:
 *
 *   1. Alcance. El cron entra por `docker exec`, pero nada impide que alguien
 *      lance el runner a mano desde otra shell, o que manana haya un segundo
 *      contenedor. Un flock solo protege dentro del sistema de archivos donde
 *      esta el archivo; GET_LOCK es del SERVIDOR MySQL, que es el recurso que
 *      las dos corridas comparten de verdad.
 *   2. Nunca queda trabado. MySQL suelta el lock solo cuando la conexion se
 *      cierra, incluso si el proceso muere de golpe. Un archivo de candado, en
 *      cambio, sobrevive al proceso y hay que inventar como detectar que quedo
 *      huerfano.
 *   3. Sin escrituras en disco: funciona igual con el sistema de archivos del
 *      contenedor en solo lectura.
 *
 * timeout 0 = no esperar. Si otra corrida lo tiene, esta se va enseguida: con
 * cron cada 5 minutos, quedarse esperando solo apilaria procesos.
 */
function adquirirCandado(PDO $pdo): bool
{
    $stmt = $pdo->prepare('SELECT GET_LOCK(:n, 0)');
    $stmt->execute([':n' => CANDADO]);

    return (int) $stmt->fetchColumn() === 1;
}

function soltarCandado(PDO $pdo): void
{
    $stmt = $pdo->prepare('SELECT RELEASE_LOCK(:n)');
    $stmt->execute([':n' => CANDADO]);
}

/** Enviados HOY, contados de la propia cola. Sin columna ni tabla nueva. */
function enviadosHoy(PDO $pdo): int
{
    return (int) $pdo->query(
        "SELECT COUNT(*) FROM dte_envio_correo WHERE estado = 'enviado' AND DATE(enviado_at) = CURDATE()"
    )->fetchColumn();
}

// ---------------------------------------------------------------------------
//  Argumentos y configuracion
// ---------------------------------------------------------------------------
$argumentos = array_slice($argv, 1);
$dryRun     = in_array('--dry-run', $argumentos, true);

$tope = enteroDeEnv('CORREO_TOPE_POR_CORRIDA', TOPE_POR_CORRIDA_DEFAULT);
foreach ($argumentos as $a) {
    if (str_starts_with($a, '--tope=')) {
        $n = substr($a, 7);
        if (! ctype_digit($n) || (int) $n <= 0) {
            fail("--tope debe ser un entero positivo, llego '{$n}'.");
        }
        $tope = (int) $n;
    }
}
$topeIntentos = enteroDeEnv('CORREO_TOPE_INTENTOS', TOPE_INTENTOS_DEFAULT);
$presupuesto  = enteroDeEnv('CORREO_PRESUPUESTO_DIARIO', PRESUPUESTO_DIARIO_DEFAULT);

$pdo = conectarDb();

// ---------------------------------------------------------------------------
//  Candado
// ---------------------------------------------------------------------------
if (! adquirirCandado($pdo)) {
    linea('CANDADO OCUPADO: ya hay otra corrida en curso. Esta no procesa nada.');
    exit(3);
}

// ---------------------------------------------------------------------------
//  Que filas tomar
//
//  'pendiente' siempre; y 'error' solo mientras no haya agotado los intentos,
//  que es lo que hace util el contador. Una fila que llego al tope se queda
//  quieta y visible: reintentarla es una decision humana (volverla a
//  'pendiente'), no algo que este bucle deba decidir solo.
//
//  Orden por antiguedad: created_at y, a igualdad, id. Nadie se queda atras.
// ---------------------------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT id, estado, intentos FROM dte_envio_correo '
    . "WHERE estado = 'pendiente' OR (estado = 'error' AND intentos < :tope) "
    . 'ORDER BY created_at ASC, id ASC LIMIT :lim'
);
$stmt->bindValue(':tope', $topeIntentos, PDO::PARAM_INT);
$stmt->bindValue(':lim', $tope, PDO::PARAM_INT);
$stmt->execute();
$candidatas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Cuantas quedarian por hacer en total, para que el resumen diga si el tope
// dejo trabajo para la corrida siguiente.
$stmtTot = $pdo->prepare(
    'SELECT COUNT(*) FROM dte_envio_correo '
    . "WHERE estado = 'pendiente' OR (estado = 'error' AND intentos < :tope)"
);
$stmtTot->bindValue(':tope', $topeIntentos, PDO::PARAM_INT);
$stmtTot->execute();
$pendientesTotales = (int) $stmtTot->fetchColumn();

if ($candidatas === []) {
    linea(sprintf(
        'RESUMEN enviados=0 fallidos=0 omitidos=0 pendientes_restantes=0 '
        . '(cola vacia; tope=%d intentos_max=%d presupuesto=%d usado_hoy=%d)',
        $tope,
        $topeIntentos,
        $presupuesto,
        enviadosHoy($pdo)
    ));
    soltarCandado($pdo);
    exit(0);
}

// ---------------------------------------------------------------------------
//  Dry-run: lista y se va sin tocar nada
// ---------------------------------------------------------------------------
if ($dryRun) {
    linea(sprintf(
        '--- DRY RUN: no se llama a Brevo y no se modifica ninguna fila (tope=%d, candidatas=%d de %d) ---',
        $tope,
        count($candidatas),
        $pendientesTotales
    ));
    $listas = 0;
    foreach ($candidatas as $c) {
        $id    = (int) $c['id'];
        $envio = PreparadorEnvio::preparar($pdo, $id);
        if ($envio['ok'] === false) {
            linea(sprintf('  fila %-6d OMITIDA  %s', $id, $envio['motivo']));
            continue;
        }
        $listas++;
        linea(sprintf(
            '  fila %-6d ENVIARIA a %-32s tipo %d folio %-6d  xml %d B  pdf %d B  asunto: %s',
            $id,
            $envio['destinatario'],
            $envio['tipoDte'],
            $envio['folio'],
            strlen($envio['adjuntos'][0]['contenido']),
            strlen($envio['adjuntos'][1]['contenido']),
            $envio['asunto']
        ));
    }
    linea(sprintf(
        'RESUMEN(dry-run) enviaria=%d omitiria=%d de %d candidatas; presupuesto=%d usado_hoy=%d',
        $listas,
        count($candidatas) - $listas,
        count($candidatas),
        $presupuesto,
        enviadosHoy($pdo)
    ));
    soltarCandado($pdo);
    exit(0);
}

// ---------------------------------------------------------------------------
//  Envio real
// ---------------------------------------------------------------------------
try {
    $mailer = BrevoMailer::desdeEntorno();
} catch (Throwable $e) {
    soltarCandado($pdo);
    fail('correo no configurado: ' . $e->getMessage());
}

$enviados          = 0;
$fallidos          = 0;
$omitidos          = 0;
$usadoHoy          = enviadosHoy($pdo);
$presupuestoAgotado = false;

foreach ($candidatas as $c) {
    $id = (int) $c['id'];

    // PRESUPUESTO: se comprueba ANTES de cada envio, no una vez al principio.
    // Otro proceso pudo consumir cuota mientras esta corrida avanzaba.
    if ($usadoHoy >= $presupuesto) {
        $presupuestoAgotado = true;
        break;
    }

    // GUARDA POR FILA, NO ALREDEDOR DEL BUCLE. Mismo criterio que el encolado de
    // la entrega 2b: si una fila revienta, las demas tienen que enviarse igual.
    // Envolver el foreach entero convertiria un fallo aislado en una corrida
    // perdida.
    try {
        // RE-ARMADO DE UN REINTENTO. PreparadorEnvio exige estado 'pendiente' --
        // esa guarda es lo que impide que enviar_correo.php reenvie algo ya
        // enviado, y no se toca. Asi que un reintento se expresa como lo que es:
        // devolver la fila a 'pendiente'. Es exactamente lo que haria una
        // persona a mano, hecho por el runner mientras queden intentos.
        //
        // intentos NO se toca aqui: lo sube registrarResultado() al cerrar el
        // intento, y es lo que hace que el tope avance de verdad.
        //
        // El WHERE repite las dos condiciones en vez de confiar en lo que
        // devolvio el SELECT: entre aquella consulta y este UPDATE pudo pasar
        // otra cosa.
        if ($c['estado'] === 'error') {
            $rearme = $pdo->prepare(
                "UPDATE dte_envio_correo SET estado = 'pendiente' "
                . "WHERE id = :id AND estado = 'error' AND intentos < :tope"
            );
            $rearme->execute([':id' => $id, ':tope' => $topeIntentos]);
            if ($rearme->rowCount() !== 1) {
                $omitidos++;
                linea(sprintf('fila %-6d OMITIDA  ya no estaba en error o agoto los intentos', $id));
                continue;
            }
            linea(sprintf('fila %-6d REINTENTO (intento %d de %d)', $id, (int) $c['intentos'] + 1, $topeIntentos));
        }

        $envio = PreparadorEnvio::preparar($pdo, $id);

        if ($envio['ok'] === false) {
            // No se puede enviar y no es un fallo de envio: no se toca la fila,
            // no se cuenta como fallida y no consume presupuesto.
            $omitidos++;
            linea(sprintf('fila %-6d OMITIDA  %s', $id, $envio['motivo']));
            continue;
        }

        $res = $mailer->enviar(
            destinatarioEmail: $envio['destinatario'],
            asunto:            $envio['asunto'],
            htmlCuerpo:        $envio['cuerpo'],
            adjuntos:          $envio['adjuntos'],
            replyToEmail:      $envio['replyTo'],
            remitenteNombre:   $envio['remitenteNombre'],
        );

        $ok = $res['status'] >= 200 && $res['status'] < 300;

        // EL messageId AL LOG. Es la unica forma de rastrear despues un correo
        // que Brevo acepto y no entrego (destinatario bloqueado): con este id la
        // busqueda en el panel de Brevo es exacta.
        $cuerpoResp = json_decode($res['body'], true);
        $messageId  = is_array($cuerpoResp) ? (string) ($cuerpoResp['messageId'] ?? '') : '';
        $detalle    = 'HTTP ' . $res['status'] . ' ' . substr($res['body'], 0, 300);

        PreparadorEnvio::registrarResultado($pdo, $id, $ok, $detalle);

        if ($ok) {
            $enviados++;
            $usadoHoy++;
            linea(sprintf(
                'fila %-6d ENVIADO  a %-32s messageId=%s',
                $id,
                $envio['destinatario'],
                $messageId !== '' ? $messageId : '(sin messageId en la respuesta)'
            ));
        } else {
            $fallidos++;
            linea(sprintf('fila %-6d FALLO    %s', $id, $detalle));
        }
    } catch (Throwable $e) {
        // Cualquier cosa inesperada de ESTA fila: se registra y se sigue con la
        // siguiente. registrarResultado va en su propio try porque si lo que
        // fallo fue la base, tampoco va a poder escribir.
        $fallidos++;
        try {
            PreparadorEnvio::registrarResultado($pdo, $id, false, $e->getMessage());
        } catch (Throwable $e2) {
            linea(sprintf('fila %-6d no se pudo registrar el fallo: %s', $id, $e2->getMessage()));
        }
        linea(sprintf('fila %-6d FALLO    %s', $id, $e->getMessage()));
    }
}

// ---------------------------------------------------------------------------
//  Resumen: una sola linea, pensada para leerse en un log de cron
// ---------------------------------------------------------------------------
$stmtTot->execute();
$restantes = (int) $stmtTot->fetchColumn();

if ($presupuestoAgotado) {
    linea(sprintf(
        '*** PRESUPUESTO DIARIO AGOTADO: %d de %d correos usados hoy. '
        . 'Quedan %d en cola SIN ENVIAR y asi seguiran hasta manana. '
        . 'Esto es una red, no una solucion: hay que subir el plan de Brevo o '
        . 'ajustar CORREO_PRESUPUESTO_DIARIO al plan real. ***',
        $usadoHoy,
        $presupuesto,
        $restantes
    ));
}

linea(sprintf(
    'RESUMEN enviados=%d fallidos=%d omitidos=%d pendientes_restantes=%d '
    . '(tope=%d intentos_max=%d presupuesto=%d usado_hoy=%d)',
    $enviados,
    $fallidos,
    $omitidos,
    $restantes,
    $tope,
    $topeIntentos,
    $presupuesto,
    $usadoHoy
));

soltarCandado($pdo);

if ($presupuestoAgotado) {
    exit(4);
}
exit($fallidos > 0 ? 1 : 0);
