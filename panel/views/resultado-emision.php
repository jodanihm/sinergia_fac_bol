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
 */
$titulo = 'Documento emitido';
require __DIR__ . '/partials/header.php';

$nombres    = [33 => 'Factura electronica', 61 => 'Nota de credito', 56 => 'Nota de debito'];
$tipoDte    = (int) ($resultado['tipoDte'] ?? 0);
$tipoNombre = $nombres[$tipoDte] ?? ('Documento tipo ' . $tipoDte);

$rutaNueva = match ($tipoDte) {
    61 => '/ventas/nota-credito',
    56 => '/ventas/nota-debito',
    default => '/ventas/factura',
};

$fmt = static function ($v): string {
    return $v === null || $v === '' ? '-' : htmlspecialchars((string) $v);
};
// Formato de monto igual al del listado de documentos: entero, miles con punto.
$fmtMonto = static function ($v): string {
    return '$ ' . number_format((float) $v, 0, ',', '.');
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
    <?php if ($rutaDetalle !== null): ?>
        <a class="boton-secundario" href="<?= htmlspecialchars($rutaDetalle); ?>">Ver en el panel de emision</a>
    <?php endif; ?>
</div>
<p class="dash-subtitulo">
    El documento se genero, se firmo y el SII acepto el envio.
    La aprobacion del SII se confirma consultando el estado en el detalle.
</p>

<p class="alerta alerta--exito" role="status">
    <span class="alerta__icono" aria-hidden="true">&#10003;</span>
    <span>Se emitio la <?= htmlspecialchars($tipoNombre); ?> correctamente.</span>
</p>

<div class="layout-principal-lateral">
    <div>
        <section class="tarjeta" aria-labelledby="titulo-documento">
            <h2 id="titulo-documento">Documento</h2>
            <dl class="ficha">
                <dt>Tipo</dt>
                <dd><?= htmlspecialchars($tipoNombre); ?> (<?= $tipoDte; ?>)</dd>

                <dt>Folio</dt>
                <dd><?= $fmt($folio); ?></dd>

                <dt>Fecha de emision</dt>
                <dd><?= $fmt($resultado['fchEmis'] ?? null); ?></dd>

                <dt>Estado</dt>
                <dd>
                    <?php if (($resultado['estado'] ?? null) !== null && $resultado['estado'] !== ''): ?>
                        <span class="badge badge--proceso"><?= htmlspecialchars((string) $resultado['estado']); ?></span>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </dd>

                <?php if (! empty($resultado['trackId'])): ?>
                    <dt>Track ID</dt>
                    <dd><?= $fmt($resultado['trackId']); ?></dd>
                <?php endif; ?>
            </dl>
            <p class="nota">El Track ID es el identificador del envio en el SII. Sirve para el seguimiento y para soporte.</p>
        </section>
    </div>

    <div>
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
        <?php endif; ?>
    </div>
</div>

<div class="acciones-grupo">
    <?php if ($rutaDetalle !== null): ?>
        <a class="boton-principal" href="<?= htmlspecialchars($rutaDetalle); ?>">Ver documento emitido</a>
        <a class="boton-secundario" href="<?= htmlspecialchars($rutaDetalle); ?>/pdf" target="_blank" rel="noopener">Descargar PDF</a>
    <?php endif; ?>
    <a class="boton-secundario" href="<?= htmlspecialchars($rutaNueva); ?>">Emitir otro documento</a>
    <a class="boton-texto" href="/panel">Volver al panel</a>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
