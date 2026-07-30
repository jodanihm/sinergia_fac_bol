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
 * ESTE SCRIPT NO INCLUYE NINGUN FRONT CONTROLLER. Solo vendor/autoload.php. Los
 * generadores de PDF son autoloadables por composer (PSR-4 Plantiflex\
 * FacturacionCl\ -> src/) y registran ellos mismos el autoloader de LibreDTE.
 *
 * ALCANCE: envia UNA fila, la que se le pide por id. El runner que vacia la cola
 * y su cron son otra entrega; aqui no hay bucle ni reintentos automaticos.
 */

require __DIR__ . '/../vendor/autoload.php';

use Plantiflex\FacturacionCl\Correo\BrevoMailer;
use Plantiflex\FacturacionCl\Pdf\BoletaPdfGenerator;
use Plantiflex\FacturacionCl\Pdf\DtePdfGenerator;

/**
 * COPIA DELIBERADA de TIPOS_PERMITIDOS_PDF de public/index.php (linea ~73).
 *
 * No se puede reusar la original: es una const de ESE archivo, que es el front
 * controller del motor, e incluirlo desde un CLI dispararia Auth, la sesion y el
 * router. Los generadores de PDF tampoco validan el tipo por su cuenta -- quien
 * filtra es pdfDte() alla y este script aca.
 *
 * SI SE AGREGA O QUITA UN TIPO, HAY QUE TOCAR LOS DOS SITIOS. El front
 * controller lleva el comentario espejo avisando de esta copia.
 */
const TIPOS_CON_PDF = [33, 61, 56, 39];

/** Etiqueta legible por tipo de DTE, para el asunto y el cuerpo del correo. */
const NOMBRE_TIPO_DTE = [
    33 => 'Factura electronica',
    61 => 'Nota de credito electronica',
    56 => 'Nota de debito electronica',
    39 => 'Boleta electronica',
];

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
//  1. La fila y todo lo que hace falta, POR JOINS NUMERICOS
//
//  El esquema vive en DOS familias de collation: las tablas del motor son
//  utf8mb4_0900_ai_ci y las creadas por las migraciones del panel son
//  utf8mb4_unicode_ci. Cruzarlas por una columna de TEXTO (rut, por ejemplo)
//  revienta con "Illegal mix of collations". Por eso todos los JOIN de aqui van
//  por id numerico (BIGINT), que no tiene collation:
//
//      dte_envio_correo.dte_emitido_id -> dte_emitido.id       (tipo, folio, rut, xml)
//      dte_envio_correo.cuenta_id      -> dte_emisor.cuenta_id (razon social)
//      dte_envio_correo.cuenta_id      -> cuenta.id            (Reply-To)
//
//  dte_envio_correo ya guarda cuenta_id y destinatario como FOTO, tomada al
//  encolar, justamente para no depender de esos cruces.
// ---------------------------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT q.id, q.estado, q.destinatario, q.intentos, q.cuenta_id, '
    . '       e.tipo_dte, e.folio, e.rut_emisor, e.xml, '
    . '       em.razon_social, '
    . '       c.email AS cuenta_email '
    . 'FROM dte_envio_correo q '
    . 'JOIN dte_emitido e ON e.id = q.dte_emitido_id '
    . "LEFT JOIN dte_emisor em ON em.cuenta_id = q.cuenta_id AND em.ambiente = 'produccion' "
    . 'LEFT JOIN cuenta c ON c.id = q.cuenta_id '
    . 'WHERE q.id = :id LIMIT 1'
);
$stmt->execute([':id' => $envioId]);
$fila = $stmt->fetch(PDO::FETCH_ASSOC);

if ($fila === false) {
    fail("No existe la fila {$envioId} en dte_envio_correo.", 1);
}

// ---------------------------------------------------------------------------
//  2. Guardas: nunca reenviar, nunca enviar a la nada
// ---------------------------------------------------------------------------
if ($fila['estado'] !== 'pendiente') {
    aviso("Fila {$envioId}: estado '{$fila['estado']}', no 'pendiente'. NO se envia nada.");
    exit(1);
}
$destinatario = trim((string) ($fila['destinatario'] ?? ''));
if ($destinatario === '') {
    aviso("Fila {$envioId}: sin destinatario. NO se envia nada.");
    exit(1);
}
$tipoDte = (int) $fila['tipo_dte'];
$folio   = (int) $fila['folio'];
if (! in_array($tipoDte, TIPOS_CON_PDF, true)) {
    aviso("Fila {$envioId}: tipo {$tipoDte} no tiene generador de PDF. NO se envia nada.");
    exit(1);
}

// EL XML PUEDE FALTAR, Y ES POR DISENO: persistirEmitido() del motor es
// best-effort y se traga sus errores, asi que hay filas de dte_emitido sin xml
// (el propio MySqlDteEmitidoRepository::obtenerXml las trata como "sin XML").
$xmlBytes = (string) ($fila['xml'] ?? '');
if ($xmlBytes === '') {
    fail("Fila {$envioId}: dte_emitido {$tipoDte}/{$folio} no tiene XML guardado; no hay nada que adjuntar.", 1);
}

// ---------------------------------------------------------------------------
//  3. El PDF, generado en proceso desde ESOS MISMOS BYTES
// ---------------------------------------------------------------------------
try {
    $pdfBytes = $tipoDte === 39
        ? (new BoletaPdfGenerator())->generarDesdeEnvioXml($xmlBytes, $tipoDte, $folio)
        : (new DtePdfGenerator())->generarDesdeEnvioXml($xmlBytes, false, $tipoDte, $folio);
} catch (Throwable $e) {
    fail("Fila {$envioId}: fallo la generacion del PDF - " . $e->getMessage(), 3);
}

// ---------------------------------------------------------------------------
//  4. El correo
// ---------------------------------------------------------------------------
$etiquetaTipo = NOMBRE_TIPO_DTE[$tipoDte] ?? "Documento tributario tipo {$tipoDte}";
$razonSocial  = trim((string) ($fila['razon_social'] ?? ''));
$replyTo      = trim((string) ($fila['cuenta_email'] ?? ''));
$rutEmisor    = (string) $fila['rut_emisor'];

$asunto = sprintf('%s N %d - %s', $etiquetaTipo, $folio, $razonSocial !== '' ? $razonSocial : $rutEmisor);

$cuerpo = sprintf(
    "<p>Estimado(a),</p>\n"
    . "<p>Adjuntamos su <strong>%s N&deg; %d</strong>, emitida por <strong>%s</strong> (RUT %s).</p>\n"
    . "<p>Se adjuntan dos archivos:</p>\n"
    . "<ul><li>El XML con firma electronica, valido ante el SII.</li>\n"
    . "<li>Una representacion impresa en PDF.</li></ul>\n"
    . "<p>Si necesita responder, puede hacerlo directamente a este correo.</p>\n",
    htmlspecialchars($etiquetaTipo, ENT_QUOTES, 'UTF-8'),
    $folio,
    htmlspecialchars($razonSocial !== '' ? $razonSocial : $rutEmisor, ENT_QUOTES, 'UTF-8'),
    htmlspecialchars($rutEmisor, ENT_QUOTES, 'UTF-8')
);

// Nombres que sirvan de verdad al abrir el correo: RUT_tipo_folio.
$baseNombre = sprintf('%s_%d_%d', str_replace('.', '', $rutEmisor), $tipoDte, $folio);

// EL XML VA EN BYTES CRUDOS, TAL COMO SALIO DE LA BASE.
//
// NADA de mb_convert_encoding, utf8_encode, htmlspecialchars ni normalizacion de
// saltos de linea sobre $xmlBytes. Ese XML esta FIRMADO y va en ISO-8859-1:
// cualquier transcodificacion, por inocente que parezca, cambia los bytes sobre
// los que se calculo la firma y la invalida ante el SII. El receptor recibiria
// un documento que no valida.
//
// El base64 lo hace BrevoMailer::enviar() sobre estos mismos bytes, en un solo
// paso y sin tocarlos.
$adjuntos = [
    ['nombre' => $baseNombre . '.xml', 'contenido' => $xmlBytes],
    ['nombre' => $baseNombre . '.pdf', 'contenido' => $pdfBytes],
];

// ---------------------------------------------------------------------------
//  5. Dry-run: TODO menos la llamada a Brevo
//
//  Pensado para poder correrse contra produccion sin mandar nada: no construye
//  el mailer (asi que no exige BREVO_API_KEY) y no toca la fila.
// ---------------------------------------------------------------------------
if ($dryRun) {
    aviso('--- DRY RUN: no se llama a Brevo y no se modifica la fila ---');
    aviso(sprintf('fila            : %d (estado %s, intentos %d)', $envioId, $fila['estado'], (int) $fila['intentos']));
    aviso(sprintf('documento       : tipo %d folio %d, emisor %s', $tipoDte, $folio, $rutEmisor));
    aviso(sprintf('destinatario    : %s', $destinatario));
    aviso(sprintf('remitente visible: %s', $razonSocial !== '' ? $razonSocial : '(sin razon social; se usaria CORREO_REMITENTE_NOMBRE)'));
    aviso(sprintf('reply-to        : %s', $replyTo !== '' ? $replyTo : '(sin cuenta.email; el correo saldria sin Reply-To)'));
    aviso(sprintf('asunto          : %s', $asunto));
    foreach ($adjuntos as $a) {
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
//  6. Envio real
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
        destinatarioEmail: $destinatario,
        asunto:            $asunto,
        htmlCuerpo:        $cuerpo,
        adjuntos:          $adjuntos,
        replyToEmail:      $replyTo !== '' ? $replyTo : null,
        remitenteNombre:   $razonSocial !== '' ? $razonSocial : null,
    );
    $ok      = $res['status'] >= 200 && $res['status'] < 300;
    $detalle = 'HTTP ' . $res['status'] . ' ' . substr($res['body'], 0, 400);
} catch (Throwable $e) {
    $ok      = false;
    $detalle = $e->getMessage();
}

// ---------------------------------------------------------------------------
//  7. La fila NUNCA queda 'pendiente' despues de un intento
// ---------------------------------------------------------------------------
if ($ok) {
    $pdo->prepare(
        "UPDATE dte_envio_correo SET estado = 'enviado', enviado_at = NOW(), "
        . 'intentos = intentos + 1, ultimo_error = NULL WHERE id = :id'
    )->execute([':id' => $envioId]);
    aviso("Fila {$envioId}: ENVIADO a {$destinatario} ({$detalle})");
    exit(0);
}

$pdo->prepare(
    "UPDATE dte_envio_correo SET estado = 'error', intentos = intentos + 1, "
    . 'ultimo_error = :err WHERE id = :id'
)->execute([':err' => substr($detalle, 0, 500), ':id' => $envioId]);
fail("Fila {$envioId}: fallo el envio - {$detalle}", 3);
