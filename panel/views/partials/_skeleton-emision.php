<?php
/**
 * Skeleton de arranque del formulario de emision: la misma estructura que
 * views/emision-form.php (encabezado, receptor | condiciones y detalle), con los
 * titulos reales y sin datos. Seccion 14 del design system, "Preloader de
 * arranque": el menu real al lado y la forma de lo que viene, nunca un logo
 * girando.
 *
 * Se usa en dos momentos: al llegar a la pagina (lo incluye la vista) y al
 * pinchar el enlace, mientras todavia se ve la pantalla anterior (lo clona
 * js/arranque-pantalla.js desde el <template> de partials/header.php).
 *
 * Es solo apariencia: aria-hidden, sin controles y sin ids.
 *
 * Recibe: $arranqueTitulo (opcional; sin el, el JS pone el del enlace pinchado).
 */
$arranqueCampo = static fn (string $clase = ''): string =>
    '<div class="arranque__campo ' . $clase . '"><span class="skeleton skeleton--etiqueta"></span><span class="skeleton skeleton--control"></span></div>';
?>
<div class="arranque" aria-hidden="true">
    <div class="dash-header">
        <div><h1 data-arranque-titulo><?= htmlspecialchars((string) ($arranqueTitulo ?? '')); ?></h1></div>
        <span class="skeleton skeleton--boton"></span>
    </div>
    <span class="skeleton skeleton--subtitulo"></span>
    <div class="config-columnas config-columnas--2">
        <div class="tarjeta">
            <h2>Datos del receptor</h2>
            <div class="arranque__grid">
                <?= $arranqueCampo(); ?><?= $arranqueCampo(); ?>
                <?= $arranqueCampo('arranque__campo--ancho'); ?>
                <?= $arranqueCampo('arranque__campo--ancho'); ?>
                <?= $arranqueCampo(); ?><?= $arranqueCampo(); ?>
            </div>
        </div>
        <div class="tarjeta">
            <h2>Condiciones del documento</h2>
            <div class="arranque__grid arranque__grid--1">
                <?= $arranqueCampo(); ?><?= $arranqueCampo(); ?>
            </div>
        </div>
    </div>
    <div class="tarjeta">
        <h2>Detalle del documento</h2>
        <span class="skeleton skeleton--subtitulo"></span>
        <span class="skeleton skeleton--fila"></span>
        <span class="skeleton skeleton--fila"></span>
    </div>
</div>
