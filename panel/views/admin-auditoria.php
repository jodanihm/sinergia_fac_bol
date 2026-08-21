<?php
/**
 * Auditoria de acciones administrativas (GET /admin/auditoria).
 *
 * SOLO CAMBIO DE PRESENTACION: mismo $filas que antes, misma consulta en
 * handleAdminAuditoriaGet(). Los filtros, la paginacion y el diff legible de
 * los JSON llegan en su propia fase; aqui el JSON crudo se sigue mostrando
 * tal cual.
 */
$titulo      = 'Auditoria';
$adminActivo = 'auditoria';
require __DIR__ . '/partials/admin/header.php';
?>

<h2 class="page-title">Auditoria</h2>
<p class="muted">
    Registro de las acciones administrativas sobre las cuentas. Es append-only:
    una fila escrita no se edita ni se borra nunca.
</p>

<div class="panel">
<?php if ($filas === []): ?>
<p class="muted" style="margin:0;">Aun no hay acciones administrativas registradas.</p>
<?php else: ?>
<div class="tabla-scroll">
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
            <td><pre style="white-space:pre-wrap;margin:0;font-size:.8em;max-width:280px;"><?= htmlspecialchars((string) ($f['valor_anterior'] ?? '')); ?></pre></td>
            <td><pre style="white-space:pre-wrap;margin:0;font-size:.8em;max-width:280px;"><?= htmlspecialchars((string) ($f['valor_nuevo'] ?? '')); ?></pre></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
</div>

<?php require __DIR__ . '/partials/admin/footer.php'; ?>
