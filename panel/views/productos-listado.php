<?php
$titulo = 'Productos y servicios';
require __DIR__ . '/partials/header.php';

$qs = ($q !== '' ? '&q=' . urlencode($q) : '') . ($incluirInactivos ? '&inactivos=1' : '');
?>

<h1>Productos y servicios</h1>

<?php if (! empty($flash)): ?>
    <p style="padding:0.5rem 0.75rem;border-radius:4px;<?= ($flash['tipo'] ?? '') === 'exito'
        ? 'background:#f1f8f1;color:#2e7d32;border:1px solid #2e7d32;'
        : 'background:#fdecea;color:#b00020;border:1px solid #b00020;'; ?>">
        <?= htmlspecialchars($flash['mensaje']); ?>
    </p>
<?php endif; ?>

<form method="get" action="/maestros/productos" style="display:flex;gap:0.5rem;align-items:center;margin:0.75rem 0;">
    <input type="text" name="q" value="<?= htmlspecialchars($q); ?>"
           placeholder="Buscar por codigo o nombre" style="flex:1;">
    <?php if ($incluirInactivos): ?><input type="hidden" name="inactivos" value="1"><?php endif; ?>
    <button type="submit" style="margin:0;">Buscar</button>
</form>

<p>
    <a href="/maestros/productos/nuevo">+ Nuevo producto</a>
    &nbsp;|&nbsp;
    <?php if ($incluirInactivos): ?>
        <a href="/maestros/productos<?= $q !== '' ? '?q=' . urlencode($q) : ''; ?>">Ver solo activos</a>
    <?php else: ?>
        <a href="/maestros/productos?inactivos=1<?= $q !== '' ? '&q=' . urlencode($q) : ''; ?>">Incluir inactivos</a>
    <?php endif; ?>
</p>

<?php if ($productos === []): ?>
    <p>No hay productos<?= $q !== '' ? ' que coincidan con la busqueda' : ''; ?>.</p>
<?php else: ?>
    <div class="tabla-scroll">
        <table class="tabla-productos">
            <thead>
                <tr>
                    <th>Codigo</th><th>Nombre</th><th>Precio</th><th>Unidad</th>
                    <th>IVA</th><th>Estado</th><th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($productos as $p): ?>
                    <tr<?= $p['activo'] ? '' : ' class="fila-inactiva"'; ?>>
                        <td><?= htmlspecialchars((string) ($p['codigo'] ?? '')); ?></td>
                        <td><?= htmlspecialchars($p['nombre']); ?></td>
                        <td><?= $p['precio_unitario'] !== null
                            ? htmlspecialchars(rtrim(rtrim(number_format($p['precio_unitario'], 4, '.', ''), '0'), '.'))
                            : '-'; ?></td>
                        <td><?= htmlspecialchars((string) ($p['unidad'] ?? '')); ?></td>
                        <td><?= $p['exento'] ? 'Exento' : 'Afecto'; ?></td>
                        <td><?= $p['activo'] ? 'Activo' : 'Inactivo'; ?></td>
                        <td class="acciones">
                            <a href="/maestros/productos/<?= (int) $p['id']; ?>/editar">Editar</a>
                            <?php if ($p['activo']): ?>
                                <form method="post" action="/maestros/productos/<?= (int) $p['id']; ?>/desactivar" style="display:inline;">
                                    <?= csrfInput(); ?>
                                    <button type="submit">Desactivar</button>
                                </form>
                            <?php else: ?>
                                <form method="post" action="/maestros/productos/<?= (int) $p['id']; ?>/activar" style="display:inline;">
                                    <?= csrfInput(); ?>
                                    <button type="submit">Activar</button>
                                </form>
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
                <a href="/maestros/productos?pagina=<?= $pagina - 1; ?><?= $qs; ?>">&larr; Anterior</a>
            <?php endif; ?>
            Pagina <?= $pagina; ?> de <?= $totalPaginas; ?> (<?= $total; ?> productos)
            <?php if ($pagina < $totalPaginas): ?>
                <a href="/maestros/productos?pagina=<?= $pagina + 1; ?><?= $qs; ?>">Siguiente &rarr;</a>
            <?php endif; ?>
        </p>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
