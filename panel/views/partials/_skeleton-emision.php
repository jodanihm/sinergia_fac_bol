<?php
/**
 * Skeleton de arranque del formulario de emision: la misma estructura de
 * views/emision-form.php, con las MISMAS clases de layout, titulos y encabezados
 * reales, y sin datos. Dura al menos 1 segundo en pantalla (decision del dueno
 * del producto, ver la vista), asi que tiene que medir lo mismo que el formulario:
 * si no, al terminar la pantalla salta.
 *
 * Se usa en dos momentos: al llegar a la pagina (lo incluye la vista) y al
 * pinchar el enlace, con la pantalla anterior a la vista (lo clona
 * js/arranque-pantalla.js desde el <template> de partials/header.php).
 *
 * Es solo apariencia: aria-hidden, sin controles y sin ids.
 *
 * Recibe, opcionales: $arranqueTitulo (sin el, el JS pone el del enlace) y
 * $arranqueVariante (factura | boleta | nota; el JS la cambia al clonar). Las
 * variantes solo esconden bloques por CSS, para no tener tres plantillas.
 */
$arranqueCampo = static fn (string $clase = '', string $extra = ''): string =>
    '<div class="form-campo ' . $clase . '"><span class="skeleton skeleton--etiqueta"></span>'
    . '<span class="skeleton skeleton--control"></span>' . $extra . '</div>';
$arranqueContador = '<span class="skeleton skeleton--contador"></span>';
$arranqueAyuda = static fn (string $ancho = '90%'): string =>
    '<span class="skeleton skeleton--ayuda" style="width:' . $ancho . '"></span>';
?>
<div class="arranque arranque--<?= htmlspecialchars((string) ($arranqueVariante ?? 'factura')); ?>" aria-hidden="true">
    <div class="dash-header">
        <div><h1 data-arranque-titulo><?= htmlspecialchars((string) ($arranqueTitulo ?? '')); ?></h1></div>
        <div class="acciones-grupo acciones-grupo--header"><span class="skeleton skeleton--boton"></span></div>
    </div>
    <p class="dash-subtitulo"><span class="skeleton skeleton--subtitulo"></span></p>
    <div class="config-ancho">
        <div class="config-columnas config-columnas--2">
            <section class="tarjeta">
                <div class="tarjeta__cabecera">
                    <h2>Datos del receptor</h2>
                    <span class="skeleton skeleton--boton"></span>
                </div>
                <div class="form-grid">
                    <?= $arranqueCampo('', $arranqueAyuda() . $arranqueAyuda() . $arranqueAyuda('70%') . '<span class="arranque--solo-boleta">' . $arranqueAyuda() . $arranqueAyuda() . $arranqueAyuda('60%') . '</span>'); ?>
                    <?= $arranqueCampo('', $arranqueContador); ?>
                    <?= $arranqueCampo('form-campo--ancho arranque--sin-boleta', $arranqueContador); ?>
                    <?= $arranqueCampo('form-campo--ancho arranque--sin-boleta', $arranqueContador); ?>
                    <?= $arranqueCampo('arranque--sin-boleta', $arranqueContador); ?>
                    <?= $arranqueCampo('', $arranqueAyuda('80%')); ?>
                    <div class="form-campo form-campo--ancho"><span class="skeleton skeleton--check"></span></div>
                    <div class="form-campo form-campo--ancho"><?= $arranqueAyuda('85%'); ?><?= $arranqueAyuda('40%'); ?></div>
                </div>
            </section>
            <div>
                <section class="tarjeta">
                    <h2>Condiciones del documento</h2>
                    <div class="form-grid form-grid--1">
                        <?= $arranqueCampo('form-campo--corto arranque--solo-factura', $arranqueAyuda('70%')); ?>
                        <?= $arranqueCampo('form-campo--corto', $arranqueAyuda('80%')); ?>
                    </div>
                </section>
                <div class="panel-info arranque--sin-nota">
                    <span class="skeleton skeleton--subtitulo"></span>
                    <?= $arranqueAyuda('95%'); ?><?= $arranqueAyuda('60%'); ?><?= $arranqueAyuda('95%'); ?><?= $arranqueAyuda('70%'); ?>
                </div>
            </div>
        </div>
        <section class="tarjeta arranque--solo-nota">
            <h2>Referencia del documento</h2>
            <span class="skeleton skeleton--subtitulo"></span>
            <div class="form-grid">
                <?= $arranqueCampo(); ?><?= $arranqueCampo(); ?>
                <?= $arranqueCampo('form-campo--ancho'); ?><?= $arranqueCampo('form-campo--ancho'); ?><?= $arranqueCampo('form-campo--ancho'); ?>
            </div>
        </section>
        <section class="tarjeta">
            <h2>Detalle del documento</h2>
            <p class="form-ayuda"><span class="skeleton skeleton--subtitulo"></span></p>
            <div class="tabla-scroll">
                <table class="tabla-datos tabla-editable">
                    <thead>
                        <tr>
                            <th class="col-producto">Producto / servicio</th>
                            <th class="col-cantidad">Cantidad</th>
                            <th class="col-precio">Precio sin IVA ($)</th>
                            <th class="col-precio">Precio final ($)</th>
                            <th class="col-unidad">Unidad</th>
                            <th class="col-exento">Exento</th>
                            <th class="col-accion"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="col-producto"><span class="skeleton skeleton--control"></span></td>
                            <td class="col-cantidad"><span class="skeleton skeleton--control"></span></td>
                            <td class="col-precio"><span class="skeleton skeleton--control"></span></td>
                            <td class="col-precio"><span class="skeleton skeleton--control"></span></td>
                            <td class="col-unidad"><span class="skeleton skeleton--control"></span></td>
                            <td class="col-exento"><span class="skeleton skeleton--check"></span></td>
                            <td class="col-accion"></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p><span class="skeleton skeleton--boton"></span></p>
            <h3>Resumen</h3>
            <dl class="ficha ficha--montos">
                <dt>Subtotal sin IVA</dt><dd><span class="skeleton skeleton--monto"></span></dd>
                <dt>IVA (19%)</dt><dd><span class="skeleton skeleton--monto"></span></dd>
                <dt class="ficha__total">Total a pagar</dt><dd class="ficha__total"><span class="skeleton skeleton--monto"></span></dd>
            </dl>
        </section>
    </div>
</div>
