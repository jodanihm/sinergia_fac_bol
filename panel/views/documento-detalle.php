<?php
/**
 * Ficha permanente de un documento emitido (M5).
 *
 * TAREA DE LA PANTALLA, EN UNA FRASE: ver un documento emitido -- montos,
 * receptor y si el SII ya lo acepto -- y descargarlo o corregirlo con una nota.
 *
 * Recibe: $tipoDte, $folio, $documento (array|null), $errorMotor (string|null),
 * $flash (array|null), $navActivo.
 *
 * $documento son los campos que devuelve GET /api/v1/dte del motor
 * (MySqlDteEmitidoRepository::listarPorPeriodo), mas receptorRazonSocial que
 * agrega el panel resolviendo el RUT contra el maestro de clientes:
 *   folio, tipoDte, fechaEmision, receptorRut, neto, iva, total, estado,
 *   trackId, folioRef, tipoDteRef, receptorRazonSocial.
 * trackId, folioRef, tipoDteRef y receptorRazonSocial pueden ser null.
 *
 * LO QUE NO SE MUESTRA PORQUE EL MOTOR NO LO ENTREGA:
 *   - Detalle de lineas. dte_emitido guarda el XML completo, pero el listado no
 *     lo devuelve y no existe endpoint que lo desarme. Para verlo hay que
 *     descargar el PDF o el XML.
 *   - Monto exento. dte_emitido solo tiene neto, iva y total.
 *   - Fecha, codigo y razon de la referencia. Solo se persisten folio_ref y
 *     tipo_dte_ref, asi que la referencia se muestra como tipo + folio.
 *   - Datos del emisor.
 * Nada de esto se deriva ni se inventa; queda documentado como pendiente.
 *
 * TIPO 39 (boleta): esta ficha es la unica pantalla del panel que muestra
 * boletas emitidas. Se soporta igual que el resto -- mismos campos, mismos
 * archivos, mismo estado -- y las acciones relacionadas se muestran bajo la
 * MISMA condicion que antes (documento encontrado), sin agregar ni quitar nada
 * especifico para el 39. El flujo de emision de boleta no se toca.
 *
 * ANCHO: todo el ancho repartido en columnas -- datos, totales y estado en el
 * SII en una fila; archivos y correcciones en la siguiente --, nunca estirando
 * campos.
 *
 * LAS CORRECCIONES NO EMITEN NADA DESDE AQUI. Los tres enlaces abren el
 * formulario de nota de credito o debito con la referencia cargada; lo que
 * emite es ESE formulario, que tiene su propia confirmacion. Por eso no se
 * confirman aqui (confirmar lo que no tiene consecuencia entrena a apretar
 * "Si" sin leer), pero cada uno dice que va a pasar, y "Anular" va al final,
 * separado y en rojo, porque es el que deja el documento sin efecto.
 *
 * CORREO AL CLIENTE: tarjeta con el estado del envio automatico (cola
 * dte_envio_correo). Recibe ademas $correo (fila de la cola o null si el
 * documento nunca se encolo) y $errorCorreo (bool: la consulta fallo). Las
 * acciones son las MISMAS de la lista de correos y bajo la misma condicion
 * (error -> Reintentar, sin_destinatario -> Buscar correo); vuelven a esta
 * ficha con el campo volver (ver destinoTrasAccionCorreo()). "Enviar sin link"
 * no se ofrece aqui: lleva una confirmacion propia y vive en la lista.
 *
 * ESTADO EN EL SII: color, nombre, explicacion y que hacer salen de
 * datos/estados-sii.php, el mismo catalogo del listado y de la Guia.
 *
 * ESPERA AL CONSULTAR EL SII (seccion 14, Efectos de carga): la consulta va al
 * SII y puede tardar. Bajo 300 ms no se muestra nada; despues, spinner dentro
 * del boton (mismo ancho) y aria-busy; pasados 10 s se dice que se esta
 * esperando al SII con una barra indeterminada (sin final conocido, sin
 * aria-valuenow).
 */
$titulo    = 'Documento ' . $tipoDte . '/' . $folio;
$bodyClase = 'pagina-ficha-documento';
require __DIR__ . '/partials/header.php';

use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Sii\EstadoContable;
use Plantiflex\FacturacionCl\Sii\RehacerDocumento;

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$tipoNombre      = TipoDte::nombreDe($tipoDte);
$tipoNombreLargo = TipoDte::nombreDe($tipoDte, true);
$correo      = $correo ?? null;
$errorCorreo = $errorCorreo ?? false;

$fmt = static function ($v) use ($e): string {
    return $v === null || $v === '' ? 'Sin dato' : $e((string) $v);
};
/** Monto en pesos chilenos: $25.000, sin decimales ni espacio. */
$fmtMonto = static function ($v): string {
    return '$' . number_format((float) $v, 0, ',', '.');
};
/** Fecha corta chilena (dd/mm/aaaa). Si no se puede leer, se muestra tal cual. */
$fmtFecha = static function ($v) use ($e): string {
    $s  = (string) $v;
    $ts = $s !== '' ? strtotime($s) : false;
    return $s === '' ? 'Sin dato' : ($ts === false ? $e($s) : date('d/m/Y', $ts));
};
/** Fecha y hora (dd/mm/aaaa hh:mm). '' si no hay valor. */
$fmtFechaHora = static function ($v) use ($e): string {
    if ($v === null || $v === '') {
        return '';
    }
    $ts = strtotime((string) $v);
    return $ts === false ? $e((string) $v) : date('d/m/Y H:i', $ts);
};

// Query string comun para prellenar la referencia en el form de NC/ND (M3).
$qsRef = static function (string $codigo, string $razon) use ($tipoDte, $folio, $documento): string {
    return '?' . http_build_query([
        'ref_tipo'  => $tipoDte,
        'ref_folio' => $folio,
        'ref_fecha' => $documento['fechaEmision'] ?? '',
        'ref_codigo' => $codigo,
        'ref_razon'  => $razon,
    ]);
};

$rutaBase = '/ventas/panel-emision/' . $tipoDte . '/' . $folio;

$iconoTipo = match ($tipoDte) {
    39, 41  => 'boleta',
    56      => 'nota-debito',
    61      => 'nota-credito',
    default => 'factura',
};

/* --- Estado en el SII, desde el catalogo compartido -------------------- */
$catalogoEstados = require __DIR__ . '/../datos/estados-sii.php';
$est = null;
if ($documento !== null) {
    $codigoSii = trim((string) ($documento['estado'] ?? ''));
    foreach ($catalogoEstados['estados'] as $x) {
        if ((string) ($x['codigo'] ?? '') === $codigoSii) {
            $g   = $catalogoEstados['grupos'][$x['grupo']];
            $est = $x + ['tono' => $g['tono'], 'icono' => $g['icono'], 'grupoTitulo' => $g['titulo']];
            break;
        }
    }
    // Codigo que el catalogo no conoce: crudo y en neutro, sin inventar texto.
    $est ??= [
        'codigo' => $codigoSii, 'nombre' => $codigoSii, 'tono' => 'neutro', 'icono' => 'info',
        'grupoTitulo' => 'Otro codigo',
        'explica' => 'Codigo que respondio el SII y que todavia no tiene descripcion en la guia.',
        'hacer'   => 'Consulta el estado de nuevo o revisa la respuesta del SII mas abajo.',
    ];
    $est['codigo'] = $codigoSii;
}
$rehacible  = $documento !== null && RehacerDocumento::permite($tipoDte, $documento['estado'] ?? null);
$fueraTotal = $est !== null && $est['codigo'] !== '' && EstadoContable::esRechazado($est['codigo']);

/* --- Correo al cliente ----------------------------------------------------
   Un estado de presentacion por caso. 'esperando' no es un estado de la cola:
   es 'pendiente' con el link de pago todavia 'pendiente' (mismo criterio que
   la lista de correos), y se separa porque ese correo NO sale solo. */
$correoVista = null;
if ($documento !== null && ! $errorCorreo) {
    if ($correo === null) {
        $correoVista = ['neutro', 'sin-cargar', 'No se envio por correo',
            'Este documento no paso por el envio automatico de correos. Pasa, por ejemplo, con documentos emitidos antes de activarlo.'];
    } else {
        $esperandoPago = ($correo['pago_estado'] ?? null) === 'pendiente' && $correo['estado'] === 'pendiente';
        $correoVista = match (true) {
            $correo['estado'] === 'enviado' => ['exito', 'check', 'Enviado al cliente',
                'El correo con el documento salio' . ($correo['enviado_at'] ? ' el ' . $fmtFechaHora($correo['enviado_at']) : '') . '.'],
            $esperandoPago => ['alerta', 'reloj', 'Esperando el link de pago',
                'El correo sale cuando la pasarela entregue el link de pago. Si no llega, puedes enviarlo sin link desde Envio de correos.'],
            $correo['estado'] === 'pendiente' => ['proceso', 'reloj', 'En cola de envio',
                (int) $correo['intentos'] > 0
                    ? 'Fallo ' . (int) $correo['intentos'] . ' vez' . ((int) $correo['intentos'] === 1 ? '' : 'es') . ' y se vuelve a intentar solo. Sale en los proximos minutos.'
                    : 'Sale en los proximos minutos, en la siguiente pasada del envio automatico.'],
            $correo['estado'] === 'error' => ['error', 'alerta', 'No se pudo enviar',
                'El envio fallo despues de ' . (int) $correo['intentos'] . ' intento' . ((int) $correo['intentos'] === 1 ? '' : 's') . '. Puedes reintentarlo.'],
            $correo['estado'] === 'sin_destinatario' => ['alerta', 'sin-cargar', 'Sin correo del cliente',
                'Cuando se emitio, el cliente no tenia correo registrado. Agregaselo en tus clientes y busca de nuevo.'],
            default => ['neutro', 'info', (string) $correo['estado'], 'Estado del envio sin descripcion.'],
        };
    }
}
$receptorNombre = $documento !== null
    ? (string) (($documento['receptorRazonSocial'] ?? '') !== '' ? $documento['receptorRazonSocial'] : ($documento['receptorRut'] ?? ''))
    : '';
$iniciales = '';
foreach (array_slice(preg_split('/\s+/', trim((string) ($documento['receptorRazonSocial'] ?? ''))) ?: [], 0, 2) as $pal) {
    if ($pal !== '' && preg_match('/^[\p{L}\p{N}]/u', $pal) === 1) {
        $iniciales .= mb_strtoupper(mb_substr($pal, 0, 1));
    }
}
$tonoAvatar = abs(crc32((string) ($documento['receptorRut'] ?? ''))) % 6;
?>

<a class="fd-volver" href="/ventas/panel-emision"><?= iconoSvg('chevron-izq', 16, ''); ?><span>Panel de emision</span></a>

<div class="pe-cabecera fd-cabecera">
    <div class="pe-cabecera__titulo">
        <span class="pe-cabecera__emblema" aria-hidden="true"><?= iconoSvg($iconoTipo, 24, ''); ?></span>
        <div>
            <h1><?= $e($tipoNombre); ?> N&deg; <?= $folio; ?></h1>
            <p class="pe-cabecera__sub">
                <?php if ($documento !== null): ?>
                    Emitido el <?= $fmtFecha($documento['fechaEmision'] ?? ''); ?> a <?= $e($receptorNombre); ?>
                <?php else: ?>
                    Documento registrado en el panel de emision.
                <?php endif; ?>
            </p>
        </div>
    </div>
    <?php if ($documento !== null): ?>
        <div class="pe-cabecera__acciones">
            <a class="boton-secundario pe-boton" href="<?= $e($rutaBase); ?>/xml"><?= iconoSvg('codigo', 16, ''); ?><span>Descargar XML</span></a>
            <a class="boton-principal pe-boton" href="<?= $e($rutaBase); ?>/pdf" target="_blank" rel="noopener"><?= iconoSvg('descargar', 16, ''); ?><span>Ver PDF</span><span class="visualmente-oculto"> (se abre en otra pestana)</span></a>
        </div>
    <?php endif; ?>
</div>

<?php if (! empty($flash['mensaje'])): ?>
    <?php $flashOk = ($flash['tipo'] ?? '') === 'exito'; ?>
    <p class="alerta alerta--<?= $flashOk ? 'exito' : 'error'; ?>" role="<?= $flashOk ? 'status' : 'alert'; ?>">
        <?= iconoSvg($flashOk ? 'check' : 'alerta', 20, 'alerta__icono'); ?>
        <span><?= $e((string) $flash['mensaje']); ?></span>
    </p>
<?php endif; ?>

<?php if (! empty($errorMotor)): ?>
    <div class="alerta alerta--error" role="alert">
        <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
        <div class="alerta__cuerpo">
            <p class="alerta__titulo">No pudimos traer este documento</p>
            <p><?= $e((string) $errorMotor); ?> Vuelve a intentarlo en un momento.</p>
        </div>
    </div>
<?php endif; ?>

<?php if ($documento === null): ?>
    <div class="estado-vacio estado-vacio--centrado">
        <?= iconoSvg('factura', 32, 'estado-vacio__icono'); ?>
        <h2 class="estado-vacio__titulo">No encontramos este documento</h2>
        <p>No hay un documento de tipo <?= $tipoDte; ?> con folio <?= $folio; ?> en tu cuenta. Puede que el folio este mal escrito.</p>
        <p class="estado-vacio__acciones">
            <a class="boton-principal" href="/ventas/panel-emision">Ir al listado de documentos</a>
        </p>
    </div>
<?php else: ?>
    <?php
    // LA GLOSA DEL SII (migracion 027). Traduce el codigo con las palabras del
    // propio SII. Solo se muestra si existe: un documento nunca consultado no
    // tiene glosa, y una fila vacia sugeriria que se consulto y no dijo nada.
    $glosaSii = trim((string) ($documento['glosaSii'] ?? ''));
    ?>

    <?php if ($rehacible): ?>
        <?php /* RECHAZADO POR EL SII: no existe para el SII, asi que no se corrige con
                 notas (una NC o ND que lo referencie tambien seria rechazada). Lo que
                 corresponde es emitirlo de nuevo, con otro folio. El enlace solo abre
                 el formulario precargado; nada se emite hasta confirmarlo ahi. */ ?>
        <section class="fd-rechazo" aria-labelledby="titulo-rehacer">
            <span class="fd-rechazo__icono" aria-hidden="true"><?= iconoSvg('alerta', 22, ''); ?></span>
            <div class="fd-rechazo__texto">
                <h2 id="titulo-rehacer">El SII rechazo este documento</h2>
                <p>No cuenta como emitido y no hay que anularlo. Emitelo de nuevo con los mismos datos, corrigiendo lo que causo el rechazo. Se usara un folio nuevo, y nada se emite hasta que lo confirmes.</p>
            </div>
            <a class="fd-rechazo__boton" href="<?= $e($rutaBase); ?>/rehacer">
                <?= iconoSvg('rehacer', 16, 'pe-rehacer__icono'); ?><span>Rehacer con los mismos datos</span>
            </a>
        </section>
    <?php endif; ?>

    <div class="fd-grid">
        <div class="fd-col">
            <section class="fd-tarjeta fd-resumen" aria-labelledby="titulo-totales">
                <h2 id="titulo-totales" class="visualmente-oculto">Totales</h2>
                <div class="fd-resumen__total">
                    <span class="fd-rotulo">Total</span>
                    <span class="fd-resumen__monto"><?= $fmtMonto($documento['total'] ?? 0); ?></span>
                </div>
                <dl class="fd-resumen__desglose">
                    <div><dt>Neto</dt><dd><?= $fmtMonto($documento['neto'] ?? 0); ?></dd></div>
                    <div><dt>IVA</dt><dd><?= $fmtMonto($documento['iva'] ?? 0); ?></dd></div>
                    <div><dt>Emision</dt><dd><?= $fmtFecha($documento['fechaEmision'] ?? null); ?></dd></div>
                </dl>
                <div class="fd-resumen__receptor">
                    <span class="pe-avatar pe-avatar--<?= $tonoAvatar; ?>" aria-hidden="true"><?= $iniciales !== '' ? $e($iniciales) : iconoSvg('clientes', 16, ''); ?></span>
                    <div>
                        <span class="fd-rotulo">Receptor</span>
                        <p class="fd-resumen__nombre"><?= $e($receptorNombre); ?></p>
                        <?php if (! empty($documento['receptorRazonSocial'])): ?>
                            <p class="fd-resumen__rut"><?= $fmt($documento['receptorRut'] ?? null); ?></p>
                        <?php else: ?>
                            <p class="fd-resumen__rut">La razon social aparece cuando el receptor esta en tus <a href="/maestros/clientes">clientes</a>.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <section class="fd-tarjeta fd-estado fd-tono--<?= $est['tono']; ?>" aria-labelledby="titulo-estado" id="zona-estado">
                <div class="fd-tarjeta__cabecera">
                    <span class="fd-tarjeta__icono" aria-hidden="true"><?= iconoSvg('informe-estados', 18, ''); ?></span>
                    <h2 id="titulo-estado">Estado en el SII</h2>
                </div>
                <div class="fd-estado__principal">
                    <span class="pe-estado pe-estado--<?= $est['tono']; ?>">
                        <?= iconoSvg($est['icono'], 14, 'pe-estado__icono'); ?>
                        <span><?= $e($est['nombre'] !== '' ? $est['nombre'] : 'Sin estado'); ?></span>
                        <?php if ($est['codigo'] !== '' && $est['codigo'] !== $est['nombre']): ?>
                            <span class="pe-estado__codigo"><?= $e($est['codigo']); ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="fd-estado__grupo"><?= $e($est['grupoTitulo']); ?></span>
                </div>
                <p class="fd-estado__explica"><?= $e($est['explica']); ?></p>
                <p class="fd-estado__hacer"><strong>Que hacer:</strong> <?= $e($est['hacer']); ?></p>
                <?php if ($fueraTotal): ?>
                    <p class="fd-etiquetas"><span class="pe-tip__etiqueta">Fuera de los totales de venta</span></p>
                <?php endif; ?>
                <?php if ($glosaSii !== ''): ?>
                    <blockquote class="fd-glosa">
                        <span class="fd-rotulo">Respuesta del SII</span>
                        <p><?= $e($glosaSii); ?></p>
                    </blockquote>
                <?php endif; ?>
                <dl class="fd-datos">
                    <div><dt>Track ID</dt><dd class="dato-copiable tabla-datos__cifra"><?= $fmt($documento['trackId'] ?? null); ?></dd></div>
                </dl>
                <form method="post" action="<?= $e($rutaBase); ?>/estado-sii" id="form-estado-sii" data-espera-larga>
                    <?= csrfInput(); ?>
                    <div class="fd-acciones">
                        <button type="submit" class="boton-secundario pe-boton" id="consultar-estado"><?= iconoSvg('rehacer', 16, ''); ?><span>Consultar estado SII</span></button>
                    </div>
                    <div class="espera-larga" data-espera-larga-aviso hidden>
                        <div class="progreso-indeterminado" role="progressbar" aria-label="Esperando la respuesta del SII"></div>
                        <p>Seguimos esperando al SII. A veces tarda; no cierres esta pagina.</p>
                    </div>
                </form>
                <p class="fd-nota">Pregunta al SII por este envio con su Track ID y guarda el estado que responda, tal cual. No emite nada ni usa folios.</p>
            </section>
        </div>

        <div class="fd-col">
            <section class="fd-tarjeta fd-correo<?= $correoVista ? ' fd-tono--' . $correoVista[0] : ''; ?>" aria-labelledby="titulo-correo">
                <div class="fd-tarjeta__cabecera">
                    <span class="fd-tarjeta__icono" aria-hidden="true"><?= iconoSvg('envio-correo', 18, ''); ?></span>
                    <h2 id="titulo-correo">Correo al cliente</h2>
                </div>
                <?php if ($errorCorreo): ?>
                    <p class="fd-estado__explica">No pudimos revisar el envio por correo de este documento. Vuelve a cargar la pagina en un momento.</p>
                <?php else: ?>
                    <?php [$cTono, $cIcono, $cTitulo, $cTexto] = $correoVista; ?>
                    <div class="fd-correo__estado">
                        <span class="fd-correo__icono" aria-hidden="true"><?= iconoSvg($cIcono, 20, ''); ?></span>
                        <div>
                            <p class="fd-correo__titulo"><?= $e($cTitulo); ?></p>
                            <p class="fd-correo__texto"><?= $cTexto; /* compuesto arriba con valores ya escapados */ ?></p>
                        </div>
                    </div>
                    <?php if ($correo !== null): ?>
                        <dl class="fd-datos">
                            <div><dt>Destinatario</dt><dd><?= ! empty($correo['destinatario']) ? $e((string) $correo['destinatario']) : 'Sin correo'; ?></dd></div>
                            <div><dt>Intentos</dt><dd class="tabla-datos__cifra"><?= (int) $correo['intentos']; ?></dd></div>
                        </dl>
                        <?php
                        // Linea de tiempo: emitido -> en cola -> enviado. El ultimo
                        // paso cambia segun el estado (error / sin correo).
                        $enviado = $correo['estado'] === 'enviado';
                        $falla   = in_array($correo['estado'], ['error', 'sin_destinatario'], true);
                        ?>
                        <ol class="fd-linea">
                            <li class="fd-linea__paso fd-linea__paso--hecho">
                                <span class="fd-linea__punto" aria-hidden="true"><?= iconoSvg('check', 12, ''); ?></span>
                                <span class="fd-linea__nombre">Documento emitido</span>
                                <span class="fd-linea__fecha"><?= $fmtFecha($documento['fechaEmision'] ?? null); ?></span>
                            </li>
                            <li class="fd-linea__paso fd-linea__paso--hecho">
                                <span class="fd-linea__punto" aria-hidden="true"><?= iconoSvg('check', 12, ''); ?></span>
                                <span class="fd-linea__nombre">Puesto en cola de envio</span>
                                <span class="fd-linea__fecha"><?= $fmtFechaHora($correo['created_at']); ?></span>
                            </li>
                            <li class="fd-linea__paso <?= $enviado ? 'fd-linea__paso--hecho' : ($falla ? 'fd-linea__paso--falla' : 'fd-linea__paso--actual'); ?>">
                                <span class="fd-linea__punto" aria-hidden="true"><?= iconoSvg($enviado ? 'check' : ($falla ? 'cerrar' : 'reloj'), 12, ''); ?></span>
                                <span class="fd-linea__nombre"><?= $enviado ? 'Enviado al cliente' : ($falla ? $e($cTitulo) : 'Envio al cliente'); ?></span>
                                <span class="fd-linea__fecha"><?= $enviado ? $fmtFechaHora($correo['enviado_at']) : ($falla ? $fmtFechaHora($correo['updated_at']) : 'Pendiente'); ?></span>
                            </li>
                        </ol>
                        <?php if ($correo['estado'] === 'error' && ! empty($correo['ultimo_error'])): ?>
                            <blockquote class="fd-glosa fd-glosa--error">
                                <span class="fd-rotulo">Ultimo error</span>
                                <p><?= $e((string) $correo['ultimo_error']); ?></p>
                            </blockquote>
                        <?php endif; ?>
                        <div class="fd-acciones">
                            <?php if ($correo['estado'] === 'error'): ?>
                                <form method="post" action="/ventas/correos/<?= (int) $correo['id']; ?>/reintentar" class="form-en-linea">
                                    <?= csrfInput(); ?>
                                    <input type="hidden" name="volver" value="<?= $e($rutaBase); ?>">
                                    <button type="submit" class="boton-principal pe-boton"><?= iconoSvg('rehacer', 16, ''); ?><span>Reintentar envio</span></button>
                                </form>
                            <?php elseif ($correo['estado'] === 'sin_destinatario'): ?>
                                <form method="post" action="/ventas/correos/<?= (int) $correo['id']; ?>/buscar-destinatario" class="form-en-linea">
                                    <?= csrfInput(); ?>
                                    <input type="hidden" name="volver" value="<?= $e($rutaBase); ?>">
                                    <button type="submit" class="boton-principal pe-boton"><?= iconoSvg('buscar', 16, ''); ?><span>Buscar correo del cliente</span></button>
                                </form>
                                <a class="boton-secundario pe-boton" href="/maestros/clientes"><?= iconoSvg('clientes', 16, ''); ?><span>Ir a clientes</span></a>
                            <?php endif; ?>
                            <a class="fd-enlace" href="/ventas/correos">Ver todos los envios <?= iconoSvg('flecha', 14, ''); ?></a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <section class="fd-tarjeta" aria-labelledby="titulo-info">
                <div class="fd-tarjeta__cabecera">
                    <span class="fd-tarjeta__icono" aria-hidden="true"><?= iconoSvg($iconoTipo, 18, ''); ?></span>
                    <h2 id="titulo-info">Datos del documento</h2>
                </div>
                <dl class="fd-datos">
                    <div><dt>Tipo</dt><dd><?= $e($tipoNombreLargo); ?> (<?= $tipoDte; ?>)</dd></div>
                    <div><dt>Folio</dt><dd class="tabla-datos__cifra"><?= $folio; ?></dd></div>
                    <div><dt>Fecha de emision</dt><dd class="tabla-datos__cifra"><?= $fmtFecha($documento['fechaEmision'] ?? null); ?></dd></div>
                    <div><dt>RUT receptor</dt><dd class="tabla-datos__cifra"><?= $fmt($documento['receptorRut'] ?? null); ?></dd></div>
                    <?php if (! empty($documento['folioRef'])): ?>
                        <div><dt>Referencia</dt><dd><?= $e(TipoDte::nombreDe((int) $documento['tipoDteRef'])); ?> N&deg; <?= (int) $documento['folioRef']; ?></dd></div>
                    <?php endif; ?>
                </dl>
                <p class="fd-nota">El detalle de las lineas esta en el PDF y en el XML.</p>
            </section>

            <?php if (! $rehacible): ?>
            <section class="fd-tarjeta" aria-labelledby="titulo-correcciones">
                <div class="fd-tarjeta__cabecera">
                    <span class="fd-tarjeta__icono" aria-hidden="true"><?= iconoSvg('nota-credito', 18, ''); ?></span>
                    <h2 id="titulo-correcciones">Corregir este documento</h2>
                </div>
                <p class="fd-nota fd-nota--intro">Cada opcion abre el formulario de la nota con este documento ya referenciado. Nada se emite hasta que lo confirmes ahi.</p>
                <ul class="fd-correcciones">
                    <li>
                        <a href="/ventas/nota-credito<?= $e($qsRef('3', '')); ?>">
                            <span class="fd-correcciones__icono" aria-hidden="true"><?= iconoSvg('nota-credito', 18, ''); ?></span>
                            <span><strong>Corregir montos</strong><small>Nota de credito que rebaja parte del monto, por ejemplo un descuento o una devolucion parcial.</small></span>
                            <?= iconoSvg('chevron-der', 16, 'fd-correcciones__flecha'); ?>
                        </a>
                    </li>
                    <li>
                        <a href="/ventas/nota-debito<?= $e($qsRef('', '')); ?>">
                            <span class="fd-correcciones__icono" aria-hidden="true"><?= iconoSvg('nota-debito', 18, ''); ?></span>
                            <span><strong>Agregar un cobro</strong><small>Nota de debito que suma un monto que falto cobrar en este documento.</small></span>
                            <?= iconoSvg('chevron-der', 16, 'fd-correcciones__flecha'); ?>
                        </a>
                    </li>
                    <li class="fd-correcciones__peligro">
                        <a href="/ventas/nota-credito<?= $e($qsRef('1', 'Anula documento N ' . $folio)); ?>">
                            <span class="fd-correcciones__icono" aria-hidden="true"><?= iconoSvg('cerrar', 18, ''); ?></span>
                            <span><strong>Anular el documento</strong><small>Nota de credito por el total: este documento queda sin efecto ante el SII.</small></span>
                            <?= iconoSvg('chevron-der', 16, 'fd-correcciones__flecha'); ?>
                        </a>
                    </li>
                </ul>
            </section>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<script>
(function () {
    /* ESPERA AL CONSULTAR EL SII -- seccion 14 del design system.
       - El doble envio se corta en el acto, sin nada visible: un indicador que
         aparece y se va en menos de 300 ms se lee como un parpadeo defectuoso.
       - A los 300 ms: spinner dentro del boton (mismo ancho) y aria-busy en
         el boton y en la zona del estado.
       - A los 10 s: se dice que se esta esperando al SII, con una barra
         indeterminada (no hay porcentaje real que mostrar).
       - El foco no se mueve. pageshow restaura todo al volver con "Atras". */
    var form = document.getElementById('form-estado-sii');
    var boton = document.getElementById('consultar-estado');
    if (!form || !boton) { return; }
    var zona = document.getElementById('zona-estado');
    var aviso = form.querySelector('[data-espera-larga-aviso]');
    var enviando = false;
    var umbral = null;
    var largo = null;
    function ocupado(si) {
        if (si) {
            boton.setAttribute('data-cargando', 'true');
            boton.setAttribute('aria-busy', 'true');
            zona.setAttribute('aria-busy', 'true');
            boton.disabled = true;
        } else {
            boton.removeAttribute('data-cargando');
            boton.removeAttribute('aria-busy');
            zona.removeAttribute('aria-busy');
            boton.disabled = false;
            aviso.hidden = true;
        }
    }
    window.addEventListener('pageshow', function (ev) {
        if (ev.persisted) { clearTimeout(umbral); clearTimeout(largo); enviando = false; ocupado(false); }
    });
    form.addEventListener('submit', function (ev) {
        if (enviando) { ev.preventDefault(); return; }
        enviando = true;
        umbral = setTimeout(function () { ocupado(true); }, 300);
        largo = setTimeout(function () { aviso.hidden = false; }, 10000);
    });
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
