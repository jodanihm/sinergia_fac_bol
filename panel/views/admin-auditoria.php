<?php $titulo = 'Superadmin: auditoria'; require __DIR__ . '/partials/header.php'; ?>

<h1>Superadmin &mdash; Auditoria</h1>
<p><a href="/admin/tenants">&larr; Volver a tenants</a></p>

<?php if ($filas === []): ?>
<p>Aun no hay acciones administrativas registradas.</p>
<?php else: ?>
<table>
    <thead>
        <tr>
            <th>Fecha</th>
            <th>Quien</th>
            <th>Accion</th>
            <th>Entidad</th>
            <th>Valor anterior</th>
            <th>Valor nuevo</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($filas as $f): ?>
        <tr>
            <td><?= htmlspecialchars((string) $f['created_at']); ?></td>
            <td><?= htmlspecialchars((string) ($f['usuario_email'] ?? ('usuario #' . $f['usuario_id']))); ?></td>
            <td><?= htmlspecialchars((string) $f['accion']); ?></td>
            <td><?= htmlspecialchars((string) $f['entidad_tipo']); ?> #<?= (int) $f['entidad_id']; ?></td>
            <td><pre style="white-space:pre-wrap;margin:0;font-size:0.8em;max-width:280px;"><?= htmlspecialchars((string) ($f['valor_anterior'] ?? '')); ?></pre></td>
            <td><pre style="white-space:pre-wrap;margin:0;font-size:0.8em;max-width:280px;"><?= htmlspecialchars((string) ($f['valor_nuevo'] ?? '')); ?></pre></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
