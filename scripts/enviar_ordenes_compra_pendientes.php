<?php

declare(strict_types=1);

/**
 * RUNNER: vacia la cola orden_compra_envio (migracion 038).
 *
 * =============================================================================
 * POR QUE UN RUNNER PROPIO Y NO enviar_correos_pendientes.php
 * =============================================================================
 *
 * Aquel vacia dte_envio_correo, cuya fila cuelga de dte_emitido_id con FK
 * OBLIGATORIA, y arma el mensaje con PreparadorEnvio, que hace JOIN a
 * dte_emitido, exige el XML guardado y titula con TipoDte::nombreDe(). Una orden
 * de compra no tiene folio, ni ambiente, ni fila en dte_emitido: no hay de donde
 * colgarla. Lo que SI se reusa es el transporte (BrevoMailer) y la forma del
 * runner: leer pendientes, preparar, enviar, registrar el desenlace.
 *
 * CORRE EN EL PANEL, no en el motor. Medido: los dos contenedores comparten red,
 * imagen y env_file, y el panel tiene salida a internet -- el chat le habla a
 * DeepSeek desde ahi y funciona en produccion. Se elige el panel porque la orden
 * de compra NO TOCA EL MOTOR en ningun punto; ponerlo alla obligaria al motor a
 * conocer un concepto que no es suyo, que es el mismo argumento por el que
 * cotizacion_id no vive en dte_emitido.
 *
 * =============================================================================
 * QUE SIGNIFICA estado='enviado' -- LEER ESTO ANTES DE CONFIAR EN ESA COLUMNA
 * =============================================================================
 *
 * 'enviado' quiere decir "BREVO ACEPTO EL MENSAJE", NO "el proveedor lo
 * recibio". Son cosas distintas y la API no permite distinguirlas al enviar.
 *
 * Si el destinatario esta en la lista de bloqueo de Brevo (rebote duro previo,
 * queja de spam, baja voluntaria), la API responde 2xx igual y el correo NUNCA
 * se entrega. El bloqueo aparece despues y por otro canal.
 *
 * Se acepta ese punto ciego a proposito, con la MISMA mitigacion que
 * PreparadorEnvio: se guarda el message_id de Brevo en la fila. Eso convierte un
 * "no me llego" en una busqueda exacta en el panel de Brevo, en vez de una
 * discusion. Cerrarlo de verdad exige recibir los webhooks de Brevo.
 *
 * -----------------------------------------------------------------------------
 * USO
 *   php scripts/enviar_ordenes_compra_pendientes.php [--limite=20] [--seco]
 *
 *   --seco  prepara todo (consulta, PDF, cuerpo) y NO envia. Sirve para ver que
 *           saldria sin gastar un correo ni tocar la cola.
 * -----------------------------------------------------------------------------
 */

ini_set('memory_limit', '256M');

$RAIZ = dirname(__DIR__);
require $RAIZ . '/vendor/autoload.php';
require $RAIZ . '/panel/src/Db.php';

use Plantiflex\FacturacionCl\Correo\BrevoMailer;
use Plantiflex\FacturacionCl\Pdf\LogoEmpresa;
use Plantiflex\FacturacionCl\Pdf\OrdenCompraPdfGenerator;
use Plantiflex\Integration\Facturacion\MySqlOrdenCompraRepository;

/** Tope de filas por corrida. Un runner que vacia una cola enorme de una vez
 *  monopoliza la conexion y el saldo de Brevo; mejor varias pasadas cortas. */
const LIMITE_POR_DEFECTO = 20;

/** Cuantas veces se reintenta una fila antes de dejarla quieta. */
const MAX_INTENTOS = 3;

$opciones = getopt('', ['limite::', 'seco']);
$limite   = isset($opciones['limite']) ? max(1, (int) $opciones['limite']) : LIMITE_POR_DEFECTO;
$seco     = array_key_exists('seco', $opciones);

function linea(string $m): void
{
    echo date('c'), ' ', $m, "\n";
}

$pdo  = Db::conexion();
$repo = new MySqlOrdenCompraRepository($pdo);

// LAS FILAS A TRABAJAR. Se piden pendientes y con intentos por debajo del tope:
// una fila que ya fallo tres veces no se vuelve a intentar sola -- alguien tiene
// que mirarla. 'sin_destinatario' NO entra: no hay a quien mandarle y reintentar
// no lo arregla.
$stmt = $pdo->prepare(
    'SELECT e.id, e.orden_compra_id, e.cuenta_id, e.intento_de, e.destinatario, e.intentos '
    . 'FROM orden_compra_envio e '
    . "WHERE e.estado IN ('pendiente','error') AND e.intentos < :max "
    . 'ORDER BY e.id ASC LIMIT ' . $limite
);
$stmt->execute([':max' => MAX_INTENTOS]);
$filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($filas === []) {
    linea('cola vacia: nada que enviar.');
    exit(0);
}
linea(sprintf('%d fila(s) por enviar%s.', count($filas), $seco ? ' (SECO: no se envia nada)' : ''));

$mailer = $seco ? null : BrevoMailer::desdeEntorno();
$enviados = 0;
$errores  = 0;

foreach ($filas as $fila) {
    $envioId = (int) $fila['id'];
    $ocId    = (int) $fila['orden_compra_id'];
    $cuenta  = (int) $fila['cuenta_id'];

    // EL INTENTO SE CUENTA ANTES DE INTENTAR. Si el proceso muere a mitad -- un
    // timeout, un OOM --, la fila no queda para siempre disponible con el mismo
    // contador: al reintentar ya lleva uno mas y el tope acaba deteniendola. Es
    // preferible perder un intento a entrar en un bucle infinito de reenvios.
    $pdo->prepare('UPDATE orden_compra_envio SET intentos = intentos + 1 WHERE id = ?')->execute([$envioId]);

    try {
        $oc = $repo->buscarPorId($cuenta, $ocId);
        if ($oc === null) {
            // No deberia pasar (hay FK), pero si pasa hay que decirlo y seguir.
            $repo->marcarEnvio($envioId, 'error', null, 'la orden de compra no existe o es de otra cuenta');
            $errores++;
            linea("envio {$envioId}: la orden {$ocId} no existe para la cuenta {$cuenta}.");
            continue;
        }

        $destinatario = trim((string) ($fila['destinatario'] ?? ''));
        if ($destinatario === '' || ! filter_var($destinatario, FILTER_VALIDATE_EMAIL)) {
            // No es un error reintentable: sin direccion no hay nada que hacer.
            $repo->marcarEnvio($envioId, 'sin_destinatario', null, 'la fila no tiene un correo valido');
            linea("envio {$envioId}: sin destinatario valido; no se reintenta.");
            continue;
        }

        [$emisor, $logo] = datosEmisor($pdo, $cuenta);
        $pdfBytes = (new OrdenCompraPdfGenerator())->generar($emisor, $oc, $oc['lineas'], $logo);

        $numero  = (int) $oc['numero'];
        $empresa = (string) ($emisor['RznSoc'] ?? 'nuestra empresa');
        $asunto  = sprintf('Orden de compra N %d - %s', $numero, $empresa);
        $cuerpo  = cuerpoHtml($oc, $empresa);
        $nombre  = sprintf('orden_compra_%d.pdf', $numero);

        if ($seco) {
            linea(sprintf('envio %d: SECO -- a %s, asunto "%s", adjunto %s (%d bytes).',
                $envioId, $destinatario, $asunto, $nombre, strlen($pdfBytes)));
            continue;
        }

        $res = $mailer->enviar(
            $destinatario,
            $asunto,
            $cuerpo,
            [['nombre' => $nombre, 'contenido' => $pdfBytes]],
            // Reply-To al correo de la CUENTA: la respuesta del proveedor tiene
            // que llegarle a la empresa que compra, no al remitente del sistema.
            correoDeLaCuenta($pdo, $cuenta),
            $empresa,
            (string) $oc['proveedor_razon_social'],
        );

        $status    = (int) ($res['status'] ?? 0);
        $messageId = is_string($res['messageId'] ?? null) ? $res['messageId'] : null;

        if ($status >= 200 && $status < 300) {
            // 'enviado' = BREVO ACEPTO. Ver la advertencia de la cabecera: NO
            // significa que el proveedor lo haya recibido. El message_id es lo
            // unico que permite rastrearlo despues.
            $repo->marcarEnvio($envioId, 'enviado', $messageId, null);
            $enviados++;
            linea(sprintf('envio %d: aceptado por Brevo (HTTP %d, messageId %s). '
                . 'ACEPTADO NO ES RECIBIDO.', $envioId, $status, $messageId ?? 'sin id'));
        } else {
            $repo->marcarEnvio($envioId, 'error', $messageId, 'HTTP ' . $status);
            $errores++;
            linea("envio {$envioId}: Brevo respondio HTTP {$status}.");
        }
    } catch (Throwable $e) {
        // UN FALLO NO CORTA LA COLA. Las filas siguientes no tienen la culpa, y
        // dejar la cola a medias por una obliga a correr el runner otra vez sin
        // saber donde se quedo.
        $repo->marcarEnvio($envioId, 'error', null, substr($e->getMessage(), 0, 500));
        $errores++;
        linea("envio {$envioId}: excepcion - " . $e->getMessage());
    }
}

linea(sprintf('fin: %d enviado(s), %d con error.', $enviados, $errores));
exit($errores > 0 ? 1 : 0);

// ===========================================================================

/**
 * Emisor para el impreso y su logo. Misma fuente que el PDF de cotizacion:
 * dte_emisor, prefiriendo produccion.
 *
 * @return array{0:array<string,mixed>, 1:?string}
 */
function datosEmisor(PDO $pdo, int $cuentaId): array
{
    $stmt = $pdo->prepare(
        'SELECT rut_emisor, razon_social, giro, dir_origen, cmna_origen FROM dte_emisor '
        . "WHERE cuenta_id = :c ORDER BY FIELD(ambiente, 'produccion', 'certificacion') LIMIT 1"
    );
    $stmt->execute([':c' => $cuentaId]);
    $f = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($f === false) {
        return [[], null];
    }

    $logo = LogoEmpresa::paraTcpdf(LogoEmpresa::leer($pdo, (string) $f['rut_emisor']));

    return [[
        'RUTEmisor'  => $f['rut_emisor'],
        'RznSoc'     => $f['razon_social'],
        'GiroEmis'   => $f['giro'],
        'DirOrigen'  => $f['dir_origen'],
        'CmnaOrigen' => $f['cmna_origen'],
    ], $logo];
}

function correoDeLaCuenta(PDO $pdo, int $cuentaId): ?string
{
    $stmt = $pdo->prepare('SELECT email FROM cuenta WHERE id = ?');
    $stmt->execute([$cuentaId]);
    $email = $stmt->fetchColumn();

    return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
}

/**
 * El cuerpo del correo. Sobrio y corto: lo que importa va en el PDF adjunto, y
 * un cuerpo largo solo da mas superficie para que un filtro de spam se moleste.
 *
 * @param array<string,mixed> $oc
 */
function cuerpoHtml(array $oc, string $empresa): string
{
    $e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    $entrega = ! empty($oc['fecha_entrega'])
        ? '<p>Fecha de entrega solicitada: <strong>' . $e(date('d-m-Y', (int) strtotime((string) $oc['fecha_entrega']))) . '</strong>'
            . (! empty($oc['lugar_entrega']) ? ' en ' . $e($oc['lugar_entrega']) : '') . '.</p>'
        : '';

    return '<p>Estimados ' . $e($oc['proveedor_razon_social']) . ':</p>'
        . '<p>Adjuntamos la orden de compra N&deg; <strong>' . (int) $oc['numero'] . '</strong> '
        . 'por un total de <strong>$ ' . number_format((float) $oc['total'], 0, ',', '.') . '</strong>.</p>'
        . $entrega
        . (! empty($oc['condiciones_pago'])
            ? '<p>Condiciones de pago: ' . $e($oc['condiciones_pago']) . '.</p>' : '')
        . '<p>Cualquier consulta, responde a este correo.</p>'
        . '<p>' . $e($empresa) . '</p>';
}
