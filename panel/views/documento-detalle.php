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
 * ESPERA AL CONSULTAR EL SII (seccion 14, Efectos de carga): la consulta va al
 * SII y puede tardar. Bajo 300 ms no se muestra nada; despues, spinner dentro
 * del boton (mismo ancho) y aria-busy; pasados 10 s se dice que se esta
 * esperando al SII con una barra indeterminada (sin final conocido, sin
 * aria-valuenow).
 */
$titulo = 'Documento ' . $tipoDte . '/' . $folio;
require __DIR__ . '/partials/header.php';

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

// El nombre sale de TipoDte::nombreDe(), que trae el fallback adentro. Antes
// habia aqui un mapa propio, uno de los seis que existian con nombres y
// fallbacks distintos entre si.
$tipoNombre = \Plantiflex\FacturacionCl\Enums\TipoDte::nombreDe($tipoDte);

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

/**
 * Badge del estado. El estado NO tiene catalogo cerrado: al emitir el motor
 * escribe 'enviado', y al consultar guarda TAL CUAL el <ESTADO> que devuelve el
 * SII (EPR, RCT, RCH, SOK...), que puede ser cualquier codigo. Por eso solo se
 * colorean los tres valores que produce el propio codigo y cuyo significado
 * esta fuera de discusion; cualquier codigo del SII se muestra en neutro y con
 * su valor crudo, sin traducir. Pintar de verde un codigo que no entendemos
 * seria afirmar una aprobacion que nadie verifico. Identico al del listado,
 * con icono por variante (el estado no va solo en color).
 *
 * @return array{0:string,1:string,2:string} clase, texto, icono
 */
$badgeEstado = static function (?string $estado): array {
    $e = trim((string) $estado);
    if ($e === '') {
        return ['badge--neutro', 'Sin estado', 'sin-cargar'];
    }
    return match ($e) {
        'enviado'     => ['badge--proceso', 'Enviado al SII', 'reloj'],
        'sin_trackid' => ['badge--advertencia', 'Sin track ID', 'alerta'],
        'desconocido' => ['badge--neutro', 'Desconocido', 'sin-cargar'],
        default       => ['badge--neutro', $e, 'info'],
    };
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
?>

<div class="dash-header">
    <div>
        <h1><?= $e($tipoNombre); ?> N&deg; <?= $folio; ?></h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <a class="boton-secundario" href="/ventas/panel-emision">Volver al listado</a>
    </div>
</div>
<p class="dash-subtitulo">
    <?php if ($documento !== null): ?>
        Emitido el <?= $fmtFecha($documento['fechaEmision'] ?? ''); ?> a
        <?= $e((string) (($documento['receptorRazonSocial'] ?? '') !== '' ? $documento['receptorRazonSocial'] : ($documento['receptorRut'] ?? ''))); ?>.
    <?php else: ?>
        Documento registrado en el panel de emision.
    <?php endif; ?>
</p>

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
    [$claseBadge, $textoBadge, $iconoBadge] = $badgeEstado($documento['estado'] ?? null);
    // LA GLOSA DEL SII (migracion 027). Va pegada al badge, porque es lo que
    // traduce el codigo: el estado dice "RCT" y la glosa dice "Rechazado por
    // Error en Caratula". Solo se muestra si existe -- un documento nunca
    // consultado no tiene glosa, y una fila vacia sugeriria que se consulto y
    // no dijo nada.
    $glosaSii = trim((string) ($documento['glosaSii'] ?? ''));
    ?>
    <div class="config-ancho">
        <div class="config-columnas config-columnas--3">
            <section class="tarjeta" aria-labelledby="titulo-info">
                <h2 id="titulo-info">Datos del documento</h2>
                <dl class="ficha">
                    <dt>Tipo</dt>
                    <dd><?= $e($tipoNombre); ?> (<?= $tipoDte; ?>)</dd>

                    <dt>Folio</dt>
                    <dd class="tabla-datos__cifra"><?= $folio; ?></dd>

                    <dt>Fecha de emision</dt>
                    <dd class="tabla-datos__cifra"><?= $fmtFecha($documento['fechaEmision'] ?? null); ?></dd>

                    <dt>Receptor</dt>
                    <dd>
                        <?php if (! empty($documento['receptorRazonSocial'])): ?>
                            <?= $e((string) $documento['receptorRazonSocial']); ?>
                            <span class="tabla-datos__secundario"><?= $fmt($documento['receptorRut'] ?? null); ?></span>
                        <?php else: ?>
                            <?= $fmt($documento['receptorRut'] ?? null); ?>
                        <?php endif; ?>
                    </dd>

                    <?php if (! empty($documento['folioRef'])): ?>
                        <dt>Referencia</dt>
                        <dd>
                            <?= $e(\Plantiflex\FacturacionCl\Enums\TipoDte::nombreDe((int) $documento['tipoDteRef'])); ?>
                            N&deg; <?= (int) $documento['folioRef']; ?>
                        </dd>
                    <?php endif; ?>
                </dl>
                <?php if (empty($documento['receptorRazonSocial'])): ?>
                    <p class="nota">La razon social aparece cuando el receptor esta en tus <a href="/maestros/clientes">clientes</a>.</p>
                <?php endif; ?>
            </section>

            <section class="tarjeta" aria-labelledby="titulo-totales">
                <h2 id="titulo-totales">Totales</h2>
                <dl class="ficha ficha--montos">
                    <dt>Neto</dt>
                    <dd><?= $fmtMonto($documento['neto'] ?? 0); ?></dd>
                    <dt>IVA</dt>
                    <dd><?= $fmtMonto($documento['iva'] ?? 0); ?></dd>
                    <dt class="ficha__total">Total</dt>
                    <dd class="ficha__total"><?= $fmtMonto($documento['total'] ?? 0); ?></dd>
                </dl>
                <p class="nota">El detalle de las lineas esta en el PDF y en el XML.</p>
            </section>

            <section class="tarjeta" aria-labelledby="titulo-estado" id="zona-estado">
                <h2 id="titulo-estado">Estado en el SII</h2>
                <p><span class="badge <?= $claseBadge; ?>"><?= iconoSvg($iconoBadge, 16, 'badge__icono'); ?><?= $e($textoBadge); ?></span></p>
                <?php if ($glosaSii !== ''): ?>
                    <p class="nota"><strong>Respuesta del SII:</strong> <?= $e($glosaSii); ?></p>
                <?php endif; ?>
                <dl class="ficha ficha--compacta">
                    <dt>Track ID</dt>
                    <dd class="dato-copiable tabla-datos__cifra"><?= $fmt($documento['trackId'] ?? null); ?></dd>
                </dl>
                <form method="post" action="<?= $e($rutaBase); ?>/estado-sii" id="form-estado-sii" data-espera-larga>
                    <?= csrfInput(); ?>
                    <div class="acciones-formulario">
                        <button type="submit" class="boton-secundario" id="consultar-estado">Consultar estado SII</button>
                    </div>
                    <div class="espera-larga" data-espera-larga-aviso hidden>
                        <div class="progreso-indeterminado" role="progressbar" aria-label="Esperando la respuesta del SII"></div>
                        <p>Seguimos esperando al SII. A veces tarda; no cierres esta pagina.</p>
                    </div>
                </form>
                <p class="nota">Pregunta al SII por este envio con su Track ID y guarda el estado que responda, tal cual. No emite nada ni usa folios.</p>
            </section>
        </div>

        <div class="config-columnas config-columnas--2">
            <section class="tarjeta" aria-labelledby="titulo-archivos">
                <h2 id="titulo-archivos">Archivos</h2>
                <p class="tarjeta__intro">El PDF es la representacion impresa para tu cliente; el XML es el documento tributario que recibio el SII.</p>
                <div class="acciones-grupo">
                    <a class="boton-secundario" href="<?= $e($rutaBase); ?>/pdf" target="_blank" rel="noopener">Ver PDF<span class="visualmente-oculto"> (se abre en otra pestana)</span></a>
                    <a class="boton-secundario" href="<?= $e($rutaBase); ?>/xml">Descargar XML</a>
                </div>
            </section>

            <section class="tarjeta" aria-labelledby="titulo-correcciones">
                <h2 id="titulo-correcciones">Corregir este documento</h2>
                <p class="tarjeta__intro">Cada opcion abre el formulario de la nota con este documento ya referenciado. Nada se emite hasta que lo confirmes ahi.</p>
                <ul class="acciones-lista">
                    <li>
                        <a class="boton-secundario" href="/ventas/nota-credito<?= $e($qsRef('3', '')); ?>">Corregir montos</a>
                        <p>Nota de credito que rebaja parte del monto, por ejemplo un descuento o una devolucion parcial.</p>
                    </li>
                    <li>
                        <a class="boton-secundario" href="/ventas/nota-debito<?= $e($qsRef('', '')); ?>">Agregar un cobro</a>
                        <p>Nota de debito que suma un monto que falto cobrar en este documento.</p>
                    </li>
                    <li class="acciones-lista__aparte">
                        <a class="boton-texto boton-texto--peligro" href="/ventas/nota-credito<?= $e($qsRef('1', 'Anula documento N ' . $folio)); ?>">Anular el documento</a>
                        <p>Nota de credito por el total: este documento queda sin efecto ante el SII.</p>
                    </li>
                </ul>
            </section>
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
