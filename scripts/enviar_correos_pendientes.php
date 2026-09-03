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
use Plantiflex\FacturacionCl\Pago\ResolutorLinkPago;
use Plantiflex\Integration\Facturacion\CertificadoCrypto;

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
/**
 * Horas que un documento puede llevar esperando el link de pago antes de que la
 * corrida lo grite. No es un tope que suelte nada: es un aviso. Quien decide
 * mandar la factura sin link es una persona, desde el panel.
 */
const LINK_PAGO_ALARMA_HORAS = 6;

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
    'SELECT id, estado, intentos, dte_emitido_id FROM dte_envio_correo '
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

// ---------------------------------------------------------------------------
//  NO HAY NADA QUE HACER: SILENCIO TOTAL.
//
//  POR QUE. Este script lo llama el cron cada 5 minutos, o sea 288 veces al
//  dia, y la cola esta vacia casi siempre. Medido tras una noche: 172 lineas,
//  el 100% diciendo "cola vacia". Un fallo real queda enterrado entre ellas, y
//  un log que nadie puede leer no sirve de nada. Este archivo pasa a ser de
//  EVENTOS: si no hubo evento, no hay linea.
//
//  NO SE PIERDE LA SENAL DE VIDA DEL CRON. Cada ejecucion queda registrada por
//  el propio cron en el journal, con su linea CMD completa
//  (journalctl -u cron). El journal es el latido; esto es la bitacora.
//
//  EL SILENCIO EXIGE LAS TRES CONDICIONES, y las tres importan:
//    - cero candidatas: si hubo aunque sea una, se registra lo que paso con
//      ella, aunque termine omitida;
//    - NO --dry-run: ese lo corre una persona a mano, y un silencio la haria
//      pensar que el script se rompio. Con --dry-run SIEMPRE se imprime;
//    - sin errores: cualquier fallo pasa antes por fail(), que escribe a STDERR
//      y sale con su codigo, asi que nunca llega hasta aca callado. Un
//      "no se pudo conectar a la base" se ve igual que siempre.
//
//  El presupuesto agotado tampoco se calla: no pasa por esta rama, porque para
//  agotarse tuvo que haber candidatas.
//
//  Sin flag para esto a proposito: el silencio es la conducta por defecto y el
//  cron es el unico llamador no interactivo.
// ---------------------------------------------------------------------------
if ($candidatas === []) {
    if (! $dryRun) {
        soltarCandado($pdo);
        exit(0);
    }

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
        // El estado del link se LEE de la base, no se resuelve: resolver crearia
        // una orden de cobro real, y este modo promete no tocar nada. Por eso
        // aqui puede decir 'sin orden' de un documento al que en la corrida de
        // verdad si le tocaria link.
        $st = $pdo->prepare('SELECT estado FROM dte_pago_link WHERE dte_emitido_id = :d LIMIT 1');
        $st->execute([':d' => (int) $c['dte_emitido_id']]);
        $estadoLink = (string) ($st->fetchColumn() ?: 'sin orden');

        linea(sprintf(
            '  fila %-6d ENVIARIA a %-32s tipo %d folio %-6d  xml %d B  pdf %d B  link: %-9s asunto: %s',
            $id,
            $envio['destinatario'],
            $envio['tipoDte'],
            $envio['folio'],
            strlen($envio['adjuntos'][0]['contenido']),
            strlen($envio['adjuntos'][1]['contenido']),
            $estadoLink,
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

// ---------------------------------------------------------------------------
//  El resolutor del link de pago
//
//  SE CONSTRUYE AQUI Y NO EN --dry-run, Y ESA ES LA LINEA MAS IMPORTANTE DE
//  ESTE BLOQUE. Resolver el link CREA UNA ORDEN DE COBRO REAL contra la pasarela
//  de la empresa. El modo seco promete no tocar nada, asi que alli no se
//  construye ni se llama: lo que muestra es el link que YA exista guardado.
//
//  SI FALTA LA CONFIGURACION PARA CIFRAR, el resolutor no se puede construir. En
//  ese caso NO se sigue como si nada: se avisa a gritos y se deja $resolutor en
//  null, que hace que ningun correo con cobro activo se mande a medias. Un fallo
//  de configuracion nuestro no puede acabar en facturas enviadas sin el link que
//  la empresa pidio.
// ---------------------------------------------------------------------------
$resolutor    = null;
$errorPasarela = null;
try {
    $llaveHex = getenv('CRYPTO_MASTER_KEY');
    $llave    = is_string($llaveHex) ? @hex2bin($llaveHex) : false;
    if ($llave === false || strlen($llave) !== CertificadoCrypto::KEY_LENGTH) {
        throw new RuntimeException('CRYPTO_MASTER_KEY ausente o mal formada');
    }
    // La RAIZ publica del panel. Aqui solo se comprueba que exista; que sea
    // ALCANZABLE de verdad -- https, no localhost, no una IP privada -- lo valida
    // el resolutor en cada orden, porque esas reglas dependen del ambiente de
    // cada empresa y no se pueden decidir una vez para todas.
    $urlPublica = trim((string) (getenv('PANEL_URL_PUBLICA') ?: ''));
    if ($urlPublica === '') {
        throw new RuntimeException('falta PANEL_URL_PUBLICA (la url publica del panel)');
    }
    $crypto    = new CertificadoCrypto($llave);
    $resolutor = new ResolutorLinkPago(
        $pdo,
        static fn (string $cifrado): string => $crypto->descifrar($cifrado),
        $urlPublica,
    );
} catch (Throwable $e) {
    $errorPasarela = $e->getMessage();
    linea('*** NO SE PUEDE RESOLVER EL LINK DE PAGO: ' . $errorPasarela . '. ***');
    linea('*** Los correos de empresas con cobro en linea activo QUEDARAN ESPERANDO. ***');
}

$enviados          = 0;
$fallidos          = 0;
$omitidos          = 0;
$aplazados         = 0;
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

        // EL LINK DE PAGO, ANTES DE PREPARAR NADA.
        //
        // Va aqui y no dentro de PreparadorEnvio por dos motivos: esa clase
        // declara que no decide politica, y sobre todo --dry-run la ejecuta --
        // meter ahi la llamada habria hecho que el modo seco creara cobros
        // reales.
        //
        // 'esperar' hace continue SIN pasar por preparar() ni por
        // registrarResultado(), asi que la fila queda intacta: mismo estado,
        // mismos intentos, sin gastar presupuesto. Si un fallo de pasarela
        // pasara por el camino de error, tres caidas dejarian la factura en
        // 'error' con intentos=3 y el runner no la miraria nunca mas.
        if ($resolutor === null) {
            // Sin resolutor no se puede saber si a este correo le toca link. Se
            // aplaza en vez de mandarlo pelado, por lo mismo de siempre.
            $aplazados++;
            linea(sprintf('fila %-6d APLAZADA no se pudo resolver el link de pago (%s)', $id, (string) $errorPasarela));
            continue;
        }

        $pago = $resolutor->resolver($id);
        if ($pago['verdicto'] === 'esperar') {
            $aplazados++;
            linea(sprintf('fila %-6d APLAZADA esperando el link de pago: %s', $id, $pago['motivo']));
            continue;
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

// LA ALARMA DEL LINK DE PAGO.
//
// Un correo aplazado no aparece como fallo en ninguna parte: su fila sigue
// 'pendiente' con sus intentos intactos, que es justo lo que se quiso. El precio
// de esa limpieza es que un atasco largo seria INVISIBLE, y una factura sin
// enviar a las diez de la noche del dia 30 no es un detalle.
//
// Por eso se mira el reloj y no el contador: lo que importa no es cuantas veces
// se intento, es CUANTO LLEVA ESPERANDO. Pasado el umbral, la linea sale a
// gritos y el script termina con codigo 5 para que el cron lo pueda distinguir
// de una corrida normal.
$atascados = (int) $pdo->query(
    'SELECT COUNT(*) FROM dte_pago_link p '
    . 'INNER JOIN dte_envio_correo q ON q.dte_emitido_id = p.dte_emitido_id '
    . "WHERE p.estado = 'pendiente' AND q.estado = 'pendiente' "
    . '  AND p.created_at < DATE_SUB(NOW(), INTERVAL ' . LINK_PAGO_ALARMA_HORAS . ' HOUR)'
)->fetchColumn();

if ($atascados > 0) {
    linea(sprintf(
        '*** %d documento(s) llevan mas de %d h esperando el link de pago y NO SE HAN ENVIADO. '
        . 'Revisa Ventas > Correos: o se arregla la pasarela, o se mandan sin link. ***',
        $atascados,
        LINK_PAGO_ALARMA_HORAS
    ));
}

linea(sprintf(
    'RESUMEN enviados=%d fallidos=%d omitidos=%d aplazados=%d pendientes_restantes=%d '
    . '(tope=%d intentos_max=%d presupuesto=%d usado_hoy=%d)',
    $enviados,
    $fallidos,
    $omitidos,
    $aplazados,
    $restantes,
    $tope,
    $topeIntentos,
    $presupuesto,
    $usadoHoy
));

soltarCandado($pdo);

// El presupuesto agotado va PRIMERO porque es mas grave: ahi no sale ningun
// correo. Un atasco de cobro retiene solo a las empresas con cobro activo.
if ($presupuestoAgotado) {
    exit(4);
}

// Codigo 5: la corrida fue bien, pero hay documentos atascados esperando cobro.
// Un codigo propio deja que el cron o un monitor lo distingan de "todo normal"
// sin tener que leer el texto del log.
if ($atascados > 0) {
    exit(5);
}

exit($fallidos > 0 ? 1 : 0);
