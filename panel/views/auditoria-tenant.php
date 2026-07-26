<?php
/**
 * Auditoria del tenant.
 *
 * Recibe: $filas, $desde, $hasta, $accionFiltro, $pagina, $totalPaginas, $total.
 * Cada fila trae id, usuario_id, usuario_email (del LEFT JOIN, puede ser null),
 * accion, entidad_tipo, entidad_id, valor_anterior, valor_nuevo y created_at,
 * ordenadas por created_at DESC. 25 por pagina.
 *
 * NO HAY IP en admin_auditoria: la tabla no la guarda, asi que no se muestra.
 *
 * valor_anterior y valor_nuevo son columnas JSON de MySQL que registrarAuditoria()
 * serializa desde un array. Llegan como STRING JSON o null. Esta vista NO los
 * decodifica ni los reformatea: mostrar json_decode/JSON_PRETTY_PRINT cambiaria
 * la salida de cualquier valor que no fuera JSON valido, y el contenido crudo es
 * justamente lo que sirve para soporte. Solo se mejora el envoltorio -- wrapping
 * y tipografia -- con .bloque-codigo.
 *
 * "Antes" y "Despues" son organizacion visual, no interpretacion: no se compara,
 * no se colorea por diferencia y no se resalta ningun campo.
 *
 * El detalle sigue en un <details> nativo, sin JavaScript. El contenido tecnico
 * aparece UNICAMENTE ahi: no se duplica en title, data-*, aria-label ni en un
 * preview dentro de la tabla.
 */
$titulo = 'Auditoria';
require __DIR__ . '/partials/header.php';

// Query string de paginacion: arrastra los tres filtros. Identico al anterior.
$qs = '';
if ($desde !== '') { $qs .= '&desde=' . urlencode($desde); }
if ($hasta !== '') { $qs .= '&hasta=' . urlencode($hasta); }
if ($accionFiltro !== '') { $qs .= '&accion=' . urlencode($accionFiltro); }

$hayFiltros = $desde !== '' || $hasta !== '' || $accionFiltro !== '';
?>

<div class="dash-header">
    <div>
        <h1>Auditoria</h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <a class="boton-secundario" href="/panel">Volver al panel</a>
    </div>
</div>
<p class="dash-subtitulo">
    Historial de acciones administrativas que afectan a tu cuenta (incluye las que ejecuta
    el equipo de soporte sobre tu cuenta especificamente, nunca las de otras cuentas).
</p>

<form method="get" action="/auditoria" class="filtros">
    <label class="filtros__campo">Desde
        <input type="date" name="desde" class="filtros__input filtros__input--medio" value="<?= htmlspecialchars($desde); ?>">
    </label>
    <label class="filtros__campo">Hasta
        <input type="date" name="hasta" class="filtros__input filtros__input--medio" value="<?= htmlspecialchars($hasta); ?>">
    </label>
    <label class="filtros__campo">Accion
        <input type="text" name="accion" class="filtros__input filtros__input--medio" value="<?= htmlspecialchars($accionFiltro); ?>" placeholder="ej. usuario, apikey">
    </label>
    <button type="submit" class="boton-secundario">Filtrar</button>
    <?php if ($hayFiltros): ?>
        <a class="boton-texto" href="/auditoria">Limpiar filtros</a>
    <?php endif; ?>
</form>

<?php if ($filas === []): ?>
    <div class="estado-vacio">
        <?php if ($hayFiltros): ?>
            <h2>Sin resultados</h2>
            <p>Ningun evento coincide con los filtros aplicados.</p>
        <?php else: ?>
            <h2>Aun no hay eventos registrados</h2>
            <p>Aqui apareceran las acciones administrativas sobre tu cuenta a medida que ocurran.</p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <section class="tarjeta" aria-labelledby="titulo-eventos">
        <h2 id="titulo-eventos">Eventos</h2>
        <div class="tabla-scroll">
            <table class="tabla-datos">
                <caption>
                    <?= $total; ?> evento<?= $total === 1 ? '' : 's'; ?><?= $hayFiltros ? ' con los filtros aplicados' : ''; ?><?php
                    if ($totalPaginas > 1): ?> &middot; pagina <?= $pagina; ?> de <?= $totalPaginas; ?><?php endif; ?>
                </caption>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Quien</th>
                        <th>Accion</th>
                        <th>Entidad</th>
                        <th>Detalle</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($filas as $f): ?>
                        <?php $tieneDetalle = ! empty($f['valor_anterior']) || ! empty($f['valor_nuevo']); ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $f['created_at']); ?></td>
                            <td><?= htmlspecialchars((string) ($f['usuario_email'] ?? 'Sistema')); ?></td>
                            <td><code><?= htmlspecialchars((string) $f['accion']); ?></code></td>
                            <td>
                                <?= htmlspecialchars((string) $f['entidad_tipo']); ?>
                                <span class="tabla-datos__secundario">#<?= (int) $f['entidad_id']; ?></span>
                            </td>
                            <td>
                                <?php if ($tieneDetalle): ?>
                                    <details>
                                        <summary>Ver detalle</summary>
                                        <?php if (! empty($f['valor_anterior'])): ?>
                                            <p class="nota">Antes</p>
                                            <pre class="bloque-codigo"><?= htmlspecialchars((string) $f['valor_anterior']); ?></pre>
                                        <?php endif; ?>
                                        <?php if (! empty($f['valor_nuevo'])): ?>
                                            <p class="nota">Despues</p>
                                            <pre class="bloque-codigo"><?= htmlspecialchars((string) $f['valor_nuevo']); ?></pre>
                                        <?php endif; ?>
                                    </details>
                                <?php else: ?>
                                    <span class="dash-vacio-inline">Sin detalle</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($totalPaginas > 1): ?>
        <p class="paginacion">
            <?php if ($pagina > 1): ?>
                <a class="boton-secundario" href="/auditoria?pagina=<?= $pagina - 1; ?><?= $qs; ?>">&larr; Anterior</a>
            <?php endif; ?>
            <span class="nota">Pagina <?= $pagina; ?> de <?= $totalPaginas; ?> (<?= $total; ?> eventos)</span>
            <?php if ($pagina < $totalPaginas): ?>
                <a class="boton-secundario" href="/auditoria?pagina=<?= $pagina + 1; ?><?= $qs; ?>">Siguiente &rarr;</a>
            <?php endif; ?>
        </p>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
