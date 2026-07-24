<?php
$titulo = 'Auditoria';
require __DIR__ . '/partials/header.php';

$qs = '';
if ($desde !== '') { $qs .= '&desde=' . urlencode($desde); }
if ($hasta !== '') { $qs .= '&hasta=' . urlencode($hasta); }
if ($accionFiltro !== '') { $qs .= '&accion=' . urlencode($accionFiltro); }
?>

<h1>Auditoria</h1>

<p>Historial de acciones administrativas que afectan a tu cuenta (incluye las que ejecuta el equipo de soporte sobre tu cuenta especificamente, nunca las de otras cuentas).</p>

<form method="get" action="/auditoria" style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:end;margin:0.75rem 0;">
    <label style="margin:0;">Desde
        <input type="date" name="desde" value="<?= htmlspecialchars($desde); ?>" style="width:auto;">
    </label>
    <label style="margin:0;">Hasta
        <input type="date" name="hasta" value="<?= htmlspecialchars($hasta); ?>" style="width:auto;">
    </label>
    <label style="margin:0;">Accion
        <input type="text" name="accion" value="<?= htmlspecialchars($accionFiltro); ?>" placeholder="ej. usuario, apikey" style="width:auto;">
    </label>
    <button type="submit" style="margin:0;">Filtrar</button>
    <?php if ($desde !== '' || $hasta !== '' || $accionFiltro !== ''): ?>
        <a href="/auditoria">Limpiar filtros</a>
    <?php endif; ?>
</form>

<?php if ($filas === []): ?>
    <p>No hay eventos de auditoria<?= ($desde !== '' || $hasta !== '' || $accionFiltro !== '') ? ' que coincidan con el filtro' : ' todavia'; ?>.</p>
<?php else: ?>
    <div class="tabla-scroll">
        <table class="tabla-auditoria">
            <thead>
                <tr><th>Fecha</th><th>Quien</th><th>Accion</th><th>Entidad</th><th>Detalle</th></tr>
            </thead>
            <tbody>
                <?php foreach ($filas as $f): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $f['created_at']); ?></td>
                        <td><?= htmlspecialchars((string) ($f['usuario_email'] ?? 'Sistema')); ?></td>
                        <td><?= htmlspecialchars((string) $f['accion']); ?></td>
                        <td><?= htmlspecialchars((string) $f['entidad_tipo']); ?> #<?= (int) $f['entidad_id']; ?></td>
                        <td>
                            <?php if (! empty($f['valor_anterior']) || ! empty($f['valor_nuevo'])): ?>
                                <details>
                                    <summary>Ver detalle</summary>
                                    <?php if (! empty($f['valor_anterior'])): ?>
                                        <p><small>Antes:</small><br>
                                        <pre style="white-space:pre-wrap;font-size:0.8rem;"><?= htmlspecialchars((string) $f['valor_anterior']); ?></pre></p>
                                    <?php endif; ?>
                                    <?php if (! empty($f['valor_nuevo'])): ?>
                                        <p><small>Despues:</small><br>
                                        <pre style="white-space:pre-wrap;font-size:0.8rem;"><?= htmlspecialchars((string) $f['valor_nuevo']); ?></pre></p>
                                    <?php endif; ?>
                                </details>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPaginas > 1): ?>
        <p class="paginacion">
            <?php if ($pagina > 1): ?>
                <a href="/auditoria?pagina=<?= $pagina - 1; ?><?= $qs; ?>">&larr; Anterior</a>
            <?php endif; ?>
            Pagina <?= $pagina; ?> de <?= $totalPaginas; ?> (<?= $total; ?> eventos)
            <?php if ($pagina < $totalPaginas): ?>
                <a href="/auditoria?pagina=<?= $pagina + 1; ?><?= $qs; ?>">Siguiente &rarr;</a>
            <?php endif; ?>
        </p>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
