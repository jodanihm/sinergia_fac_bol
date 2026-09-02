<?php

declare(strict_types=1);

/**
 * Envia por correo al receptor UN documento ya emitido, tomandolo de la cola
 * dte_envio_correo (migracion 024), con su XML y su PDF adjuntos.
 *
 * USO:
 *   php scripts/enviar_correo.php <id_de_dte_envio_correo> [--dry-run]
 *
 * EJEMPLO:
 *   docker exec sinergia_motor php scripts/enviar_correo.php 42 --dry-run
 *
 * VARIABLES DE ENTORNO:
 *   DB_HOST, DB_NAME, DB_USER, DB_PASS   -> conexion MySQL (DB_PORT opcional).
 *   BREVO_API_KEY                        -> clave de la API de Brevo.
 *   CORREO_REMITENTE                     -> direccion unica del sistema.
 *   CORREO_REMITENTE_NOMBRE              -> nombre visible de respaldo.
 *
 * EXIT CODES:
 *   0  enviado (o dry-run completo sin errores)
 *   1  la fila no esta en condiciones de enviarse (ya enviada, sin destinatario,
 *      sin XML, tipo sin PDF). NO es un fallo del sistema: es un no-op explicado.
 *   2  configuracion o conexion incompleta (falta una env var, no hay base)
 *   3  el envio se intento y fallo (la fila queda en estado 'error')
 *
 * POR QUE CORRE EN EL CONTENEDOR DEL MOTOR Y NO EN EL DEL PANEL:
 *   El PDF no esta guardado en ninguna parte, se genera al vuelo desde el XML.
 *   El panel no puede generarlo: se lo pide al motor por HTTP con una key de
 *   servicio, y las funciones que arman esa llamada viven DENTRO de su front
 *   controller (arrastran Auth, sesion y redirects). El motor, en cambio, tiene
 *   el XML en su propia base y los generadores en proceso, asi que no necesita
 *   ni key ni HTTP contra si mismo.
 *
 * ESTE SCRIPT NO INCLUYE NINGUN FRONT CONTROLLER. Solo vendor/autoload.php.
 *
 * DONDE VIVE LA LOGICA: el armado del correo y el registro del resultado estan
 * en Plantiflex\FacturacionCl\Correo\PreparadorEnvio, compartidos con
 * scripts/enviar_correos_pendientes.php (el runner). Aqui solo queda el
 * envoltorio CLI. Hay UN camino de envio, no dos.
 *
 * ALCANCE: envia UNA fila, la que se le pide por id. Sin bucle, sin reintentos
 * y sin topes: eso es del runner.
 */

require __DIR__ . '/../vendor/autoload.php';

use Plantiflex\FacturacionCl\Correo\BrevoMailer;
use Plantiflex\FacturacionCl\Correo\PreparadorEnvio;
use Plantiflex\FacturacionCl\Pago\ResolutorLinkPago;
use Plantiflex\Integration\Facturacion\CertificadoCrypto;

function fail(string $msg, int $code = 2): never
{
    fwrite(STDERR, "ERROR: {$msg}\n");
    exit($code);
}

function aviso(string $msg): void
{
    fwrite(STDOUT, $msg . "\n");
}

function requerirEnv(string $nombre): string
{
    $v = getenv($nombre);
    if ($v === false || trim($v) === '') {
        fail("Falta la variable de entorno {$nombre}.");
    }

    return $v;
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

// ---------------------------------------------------------------------------
//  Argumentos
// ---------------------------------------------------------------------------
/**
 * Arma el resolutor del link de pago, o null si falta configuracion.
 *
 * Devuelve null en vez de lanzar para que el llamador decida: aqui se traduce a
 * un fail(), porque este CLI lo corre una persona y tiene que enterarse.
 */
function resolutorLinkPago(PDO $pdo): ?ResolutorLinkPago
{
    $llaveHex = getenv('CRYPTO_MASTER_KEY');
    $llave    = is_string($llaveHex) ? @hex2bin($llaveHex) : false;
    $url      = trim((string) (getenv('PANEL_URL_PUBLICA') ?: ''));
    if ($llave === false || strlen($llave) !== CertificadoCrypto::KEY_LENGTH || $url === '') {
        return null;
    }
    $crypto = new CertificadoCrypto($llave);

    return new ResolutorLinkPago(
        $pdo,
        static fn (string $cifrado): string => $crypto->descifrar($cifrado),
        rtrim($url, '/') . '/pagos/flow/confirmacion',
    );
}

$argumentos = array_slice($argv, 1);
$dryRun     = in_array('--dry-run', $argumentos, true);
$idCrudo    = null;
foreach ($argumentos as $a) {
    if ($a !== '--dry-run') {
        $idCrudo = $a;
        break;
    }
}
if ($idCrudo === null || ! ctype_digit($idCrudo) || (int) $idCrudo <= 0) {
    fail("Uso: php scripts/enviar_correo.php <id_de_dte_envio_correo> [--dry-run]");
}
$envioId = (int) $idCrudo;

$pdo = conectarDb();

// ---------------------------------------------------------------------------
//  Preparacion: consulta, guardas, PDF y armado del mensaje
// ---------------------------------------------------------------------------
// EL LINK DE PAGO ANTES DE PREPARAR, igual que en el runner de la cola.
//
// Este CLI existe para mandar un documento suelto a mano, y tiene que seguir
// exactamente la misma regla: si no hay puerta trasera, no hay forma de mandar
// por error una factura sin el link que su empresa pidio. En --dry-run NO se
// resuelve, por lo mismo de siempre: resolver crea una orden de cobro real.
if (! $dryRun) {
    $resolutor = resolutorLinkPago($pdo);
    if ($resolutor === null) {
        fail('No se puede resolver el link de pago (falta CRYPTO_MASTER_KEY o PANEL_URL_PUBLICA). '
            . 'No se manda nada: el correo podria salir sin el link que la empresa pidio.');
    }
    $pago = $resolutor->resolver($envioId);
    if ($pago['verdicto'] === 'esperar') {
        fwrite(STDOUT, "Fila {$envioId}: esperando el link de pago ({$pago['motivo']}). NO se envia nada.\n");
        exit(1);
    }
}

$envio = PreparadorEnvio::preparar($pdo, $envioId);

if ($envio['ok'] === false) {
    // El canal importa: un "ya estaba enviada" es un no-op informativo y va a
    // STDOUT; un "no tiene XML" es un error y va a STDERR con el prefijo ERROR:.
    if ($envio['canal'] === 'stderr') {
        fail($envio['motivo'], $envio['codigo']);
    }
    aviso($envio['motivo']);
    exit($envio['codigo']);
}

// ---------------------------------------------------------------------------
//  Dry-run: TODO menos la llamada a Brevo
//
//  Pensado para poder correrse contra produccion sin mandar nada: no construye
//  el mailer (asi que no exige BREVO_API_KEY) y no toca la fila.
// ---------------------------------------------------------------------------
if ($dryRun) {
    aviso('--- DRY RUN: no se llama a Brevo y no se modifica la fila ---');
    aviso(sprintf('fila            : %d (estado %s, intentos %d)', $envioId, $envio['estado'], $envio['intentos']));
    aviso(sprintf('documento       : tipo %d folio %d, emisor %s', $envio['tipoDte'], $envio['folio'], $envio['rutEmisor']));
    aviso(sprintf('destinatario    : %s', $envio['destinatario']));
    aviso(sprintf('remitente visible: %s', $envio['remitenteNombre'] ?? '(sin razon social; se usaria CORREO_REMITENTE_NOMBRE)'));
    aviso(sprintf('reply-to        : %s', $envio['replyTo'] ?? '(sin cuenta.email; el correo saldria sin Reply-To)'));
    aviso(sprintf('asunto          : %s', $envio['asunto']));
    foreach ($envio['adjuntos'] as $a) {
        aviso(sprintf(
            'adjunto         : %-28s %8d bytes crudos  %8d en base64  md5=%s',
            $a['nombre'],
            strlen($a['contenido']),
            (int) (ceil(strlen($a['contenido']) / 3) * 4),
            md5($a['contenido'])
        ));
    }
    exit(0);
}

// ---------------------------------------------------------------------------
//  Envio real
// ---------------------------------------------------------------------------
try {
    $mailer = BrevoMailer::desdeEntorno();
} catch (Throwable $e) {
    // Configuracion incompleta: se aborta ANTES de tocar la fila. La fila queda
    // 'pendiente' a proposito -- no hubo intento que registrar.
    fail('correo no configurado: ' . $e->getMessage());
}

try {
    $res = $mailer->enviar(
        destinatarioEmail: $envio['destinatario'],
        asunto:            $envio['asunto'],
        htmlCuerpo:        $envio['cuerpo'],
        adjuntos:          $envio['adjuntos'],
        replyToEmail:      $envio['replyTo'],
        remitenteNombre:   $envio['remitenteNombre'],
    );
    $ok      = $res['status'] >= 200 && $res['status'] < 300;
    $detalle = 'HTTP ' . $res['status'] . ' ' . substr($res['body'], 0, 400);
} catch (Throwable $e) {
    $ok      = false;
    $detalle = $e->getMessage();
}

PreparadorEnvio::registrarResultado($pdo, $envioId, $ok, $detalle);

if ($ok) {
    aviso("Fila {$envioId}: ENVIADO a {$envio['destinatario']} ({$detalle})");
    exit(0);
}

fail("Fila {$envioId}: fallo el envio - {$detalle}", 3);
