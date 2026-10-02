<?php
/**
 * Confirmacion inmediata despues de emitir (M3).
 *
 * SOLO SE LLEGA AQUI TRAS UN 201 DEL MOTOR: handleEmisionPost() hace
 * flashSet('exito', ...) + redirigirPrg('/ventas/resultado') unicamente en esa
 * rama; cualquier 422/502/500 re-renderiza el formulario y no pasa por aca. Y
 * handleEmisionResultadoGet() redirige a /ventas/factura si el flash no trae
 * 'resultado'. Por eso el mensaje puede afirmar que la emision fue exitosa.
 *
 * MATIZ IMPORTANTE: 201 significa que el DTE se genero, se firmo y el SII
 * ACEPTO EL ENVIO (hay trackId). NO significa que el SII ya haya aprobado el
 * documento: eso se confirma consultando el estado, y para eso esta el detalle
 * en el panel de emision. El texto de esta pantalla no promete mas que eso.
 *
 * Recibe: $resultado (array) y $navActivo.
 *
 * $resultado trae EXACTAMENTE estas 8 claves, armadas en handleEmisionPost()
 * desde el body del 201: tipoDte, folio, estado, trackId, fchEmis, neto, iva,
 * total. Todas menos tipoDte pueden venir null.
 *
 * LO QUE NO SE MUESTRA PORQUE NO LLEGA:
 *   - Receptor (RUT o razon social): el 201 del motor no lo devuelve.
 *   - Monto exento: ni el 201 ni dte_emitido lo guardan (solo neto/iva/total).
 *   - Detalle de lineas: no existe en ninguna respuesta del motor.
 * No se derivan ni se recalculan: los montos se muestran tal como llegaron.
 *
 * DISENO (octubre 2026): el aviso de exito dice QUE se emitio (tipo y folio) y
 * que falta (la aprobacion del SII); una sola accion primaria (ver el
 * documento) y el enlace duplicado del encabezado se quito. Fechas en
 * dd/mm/aaaa. "Emitir otro" vuelve al MISMO tipo de documento: antes la
 * exenta y la boleta caian a la factura afecta.
 */
$titulo = 'Documento emitido';
require __DIR__ . '/partials/header.php';

$tipoDte = (int) ($resultado['tipoDte'] ?? 0);
// CAMBIO DE TEXTO A PROPOSITO: el mapa que habia aqui decia "Factura
// electronica" para el 33 pero "Nota de credito" a secas para el 61 -- era
// incoherente consigo mismo -- y ademas no tenia la boleta, que caia al
// fallback. Ahora dice el nombre corto, igual que el resto de la interfaz.
$tipoNombre = \Plantiflex\FacturacionCl\Enums\TipoDte::nombreDe($tipoDte);

$rutaNueva = match ($tipoDte) {
    34 => '/ventas/factura-exenta',
    39 => '/ventas/boleta',
    61 => '/ventas/nota-credito',
    56 => '/ventas/nota-debito',
    default => '/ventas/factura',
};

$fmt = static function ($v): string {
    return $v === null || $v === '' ? '-' : htmlspecialchars((string) $v);
};
// Formato de monto igual al del listado de documentos: entero, miles con punto.
$fmtMonto = static function ($v): string {
    return '$' . number_format((float) $v, 0, ',', '.');
};
// Fecha chilena: 2026-10-02 -> 02/10/2026. Si llega en otro formato, tal cual.
$fmtFecha = static function ($v) use ($fmt): string {
    $t = (string) ($v ?? '');
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $t, $m) ? $m[3] . '/' . $m[2] . '/' . $m[1] : $fmt($v);
};

$folio = $resultado['folio'] ?? null;
// El detalle exige tipo Y folio en la ruta; sin folio no hay a donde ir.
$rutaDetalle = ($folio !== null && $folio !== '' && $tipoDte > 0)
    ? '/ventas/panel-emision/' . $tipoDte . '/' . (int) $folio
    : null;

// Solo se listan los montos que realmente llegaron. Un documento totalmente
// exento llega con neto 0 e iva 0: eso es el dato real y se muestra tal cual.
$montos = [];
foreach (['neto' => 'Neto', 'iva' => 'IVA', 'total' => 'Total'] as $clave => $etiqueta) {
    if (($resultado[$clave] ?? null) !== null && $resultado[$clave] !== '') {
        $montos[$clave] = [$etiqueta, $resultado[$clave]];
    }
}
?>

<div class="dash-header">
    <div>
        <h1>Documento emitido</h1>
    </div>
</div>
<p class="dash-subtitulo">
    Se genero, se firmo y el SII recibio el envio. Su aprobacion se confirma en el detalle.
</p>

<div class="config-ancho">

<div class="alerta alerta--exito" role="status">
    <?= iconoSvg('check', 20, 'alerta__icono'); ?>
    <div class="alerta__cuerpo">
        <p class="alerta__titulo">
            Se emitio la <?= htmlspecialchars($tipoNombre); ?><?= ($folio !== null && $folio !== '') ? ' N&deg; ' . htmlspecialchars((string) $folio) : ''; ?>
        </p>
        <p>
            El SII recibio el envio<?= ! empty($resultado['trackId']) ? ' (Track ID ' . htmlspecialchars((string) $resultado['trackId']) . ')' : ''; ?>.
            Todavia falta que lo apruebe: revisalo en unos minutos en el detalle del documento.
        </p>
    </div>
</div>

<div class="config-columnas config-columnas--2">
        <section class="tarjeta" aria-labelledby="titulo-documento">
            <h2 id="titulo-documento">Documento</h2>
            <dl class="ficha">
                <dt>Tipo</dt>
                <dd><?= htmlspecialchars($tipoNombre); ?> (<?= $tipoDte; ?>)</dd>

                <dt>Folio</dt>
                <dd><?= $fmt($folio); ?></dd>

                <dt>Fecha de emision</dt>
                <dd><?= $fmtFecha($resultado['fchEmis'] ?? null); ?></dd>

                <dt>Estado</dt>
                <dd>
                    <?php if (($resultado['estado'] ?? null) !== null && $resultado['estado'] !== ''): ?>
                        <?php // Enviado, aun sin respuesta final del SII: badge "en curso", con icono de reloj ademas del color. ?>
                        <span class="badge badge--proceso"><?= iconoSvg('reloj', 16, 'badge__icono'); ?><?= htmlspecialchars((string) $resultado['estado']); ?></span>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </dd>

                <?php if (! empty($resultado['trackId'])): ?>
                    <dt>Track ID</dt>
                    <dd class="dato-copiable"><?= $fmt($resultado['trackId']); ?></dd>
                <?php endif; ?>
            </dl>
            <p class="nota">El Track ID es el identificador del envio en el SII. Sirve para el seguimiento y para soporte.</p>
        </section>

        <?php if ($montos !== []): ?>
            <section class="tarjeta" aria-labelledby="titulo-totales">
                <h2 id="titulo-totales">Totales del documento</h2>
                <dl class="ficha ficha--montos">
                    <?php foreach ($montos as $clave => [$etiqueta, $valor]): ?>
                        <?php $esTotal = $clave === 'total'; ?>
                        <dt<?= $esTotal ? ' class="ficha__total"' : ''; ?>><?= htmlspecialchars($etiqueta); ?></dt>
                        <dd<?= $esTotal ? ' class="ficha__total"' : ''; ?>><?= $fmtMonto($valor); ?></dd>
                    <?php endforeach; ?>
                </dl>
                <p class="nota">Montos calculados por el motor al generar el documento. No son una estimacion.</p>
            </section>
        <?php else: ?>
            <div class="panel-info">
                <p class="panel-info__titulo">
                    <?= iconoSvg('info', 20, 'panel-info__icono'); ?>
                    Totales en el detalle
                </p>
                <p>El motor no devolvio los montos en esta respuesta. Los ves en el detalle del documento y en su PDF.</p>
            </div>
        <?php endif; ?>
</div>

<?php /* Una sola accion primaria. Lo que sale de aqui va a la izquierda. */ ?>
<div class="acciones-formulario">
    <a class="boton-texto acciones-formulario__aparte" href="/panel">Volver al panel</a>
    <a class="boton-secundario" href="<?= htmlspecialchars($rutaNueva); ?>">Emitir otra <?= htmlspecialchars(strtolower($tipoNombre)); ?></a>
    <?php if ($rutaDetalle !== null): ?>
        <a class="boton-secundario" href="<?= htmlspecialchars($rutaDetalle); ?>/pdf" target="_blank" rel="noopener">Descargar PDF<span class="visualmente-oculto"> (se abre en otra pestana)</span></a>
        <a class="boton-principal" href="<?= htmlspecialchars($rutaDetalle); ?>">Ver documento emitido</a>
    <?php endif; ?>
</div>

</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
