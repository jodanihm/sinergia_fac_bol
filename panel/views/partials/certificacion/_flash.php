<?php
/**
 * Aviso tras una accion de la certificacion (PRG). Lo comparten la vista de
 * resumen, la de etapa, la de simulacion, la de aprobada y el set de pruebas.
 *
 * Recibe $flash (array|null) y, opcional, $flashTrackDestino: el texto que
 * dice donde pegar el Track ID de la simulacion ("aqui abajo" en la etapa 2,
 * un enlace a la etapa 2 en el resumen).
 *
 * Icono + texto + color: el exito y el error no se distinguen solo por el
 * verde o el rojo (WCAG 1.4.1). El error va en role="alert" porque pide hacer
 * algo; el exito en role="status", que no interrumpe.
 */
if (empty($flash['mensaje'])) {
    return;
}
$flashEsError = ($flash['tipo'] ?? '') === 'error';
?>
<div class="alerta alerta--<?= $flashEsError ? 'error' : 'exito'; ?>" role="<?= $flashEsError ? 'alert' : 'status'; ?>">
    <?= iconoSvg($flashEsError ? 'alerta' : 'check', 20, 'alerta__icono'); ?>
    <div class="alerta__cuerpo">
        <p><?= htmlspecialchars((string) $flash['mensaje']); ?></p>
    </div>
</div>

<?php if (isset($flash['simulacionTrackId'])): ?>
<div class="panel-info">
    <p class="panel-info__titulo">
        <?= iconoSvg('info', 20, 'panel-info__icono'); ?>
        Track ID del Set de Simulacion
    </p>
    <p id="track-simulacion" class="secreto-unico"><?= htmlspecialchars((string) $flash['simulacionTrackId']); ?></p>
    <p>
        Pegalo en el campo "Track ID" de Simulacion
        <?= $flashTrackDestino ?? 'en la <a href="/certificacion/etapa/2">etapa 2 (Simulacion)</a>'; ?>
        cuando confirmes que el SII aprobo el contenido.
    </p>
</div>
<?php endif; ?>
