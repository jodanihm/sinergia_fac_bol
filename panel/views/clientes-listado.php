<?php
$titulo = 'Clientes';
require __DIR__ . '/partials/header.php';

$qs = ($q !== '' ? '&q=' . urlencode($q) : '') . ($incluirInactivos ? '&inactivos=1' : '');
?>

<h1>Clientes</h1>

<?php if (! empty($flash)): ?>
    <p style="padding:0.5rem 0.75rem;border-radius:4px;<?= ($flash['tipo'] ?? '') === 'exito'
        ? 'background:#f1f8f1;color:#2e7d32;border:1px solid #2e7d32;'
        : 'background:#fdecea;color:#b00020;border:1px solid #b00020;'; ?>">
        <?= htmlspecialchars($flash['mensaje']); ?>
    </p>
<?php endif; ?>

<form method="get" action="/maestros/clientes" style="display:flex;gap:0.5rem;align-items:center;margin:0.75rem 0;">
    <input type="text" name="q" value="<?= htmlspecialchars($q); ?>"
           placeholder="Buscar por RUT o razon social" style="flex:1;">
    <?php if ($incluirInactivos): ?><input type="hidden" name="inactivos" value="1"><?php endif; ?>
    <button type="submit" style="margin:0;">Buscar</button>
</form>

<p>
    <a href="/maestros/clientes/nuevo">+ Nuevo cliente</a>
    &nbsp;|&nbsp;
    <?php if ($incluirInactivos): ?>
        <a href="/maestros/clientes<?= $q !== '' ? '?q=' . urlencode($q) : ''; ?>">Ver solo activos</a>
    <?php else: ?>
        <a href="/maestros/clientes?inactivos=1<?= $q !== '' ? '&q=' . urlencode($q) : ''; ?>">Incluir inactivos</a>
    <?php endif; ?>
</p>

<?php if ($clientes === []): ?>
    <p>No hay clientes<?= $q !== '' ? ' que coincidan con la busqueda' : ''; ?>.</p>
<?php else: ?>
    <div class="tabla-scroll">
        <table class="tabla-clientes">
            <thead>
                <tr>
                    <th>RUT</th><th>Razon social</th><th>Comuna</th><th>Email</th>
                    <th>Estado</th><th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($clientes as $c): ?>
                    <tr<?= $c['activo'] ? '' : ' class="fila-inactiva"'; ?>>
                        <td><?= htmlspecialchars($c['rut_cliente']); ?></td>
                        <td><?= htmlspecialchars($c['razon_social']); ?></td>
                        <td><?= htmlspecialchars((string) ($c['comuna'] ?? '')); ?></td>
                        <td><?= htmlspecialchars((string) ($c['email'] ?? '')); ?></td>
                        <td><?= $c['activo'] ? 'Activo' : 'Inactivo'; ?></td>
                        <td class="acciones">
                            <a href="/maestros/clientes/<?= (int) $c['id']; ?>/editar">Editar</a>
                            <?php if ($c['activo']): ?>
                                <form method="post" action="/maestros/clientes/<?= (int) $c['id']; ?>/desactivar" style="display:inline;">
                                    <?= csrfInput(); ?>
                                    <button type="submit">Desactivar</button>
                                </form>
                            <?php else: ?>
                                <form method="post" action="/maestros/clientes/<?= (int) $c['id']; ?>/activar" style="display:inline;">
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
                <a href="/maestros/clientes?pagina=<?= $pagina - 1; ?><?= $qs; ?>">&larr; Anterior</a>
            <?php endif; ?>
            Pagina <?= $pagina; ?> de <?= $totalPaginas; ?> (<?= $total; ?> clientes)
            <?php if ($pagina < $totalPaginas): ?>
                <a href="/maestros/clientes?pagina=<?= $pagina + 1; ?><?= $qs; ?>">Siguiente &rarr;</a>
            <?php endif; ?>
        </p>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
