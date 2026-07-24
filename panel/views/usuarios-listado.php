<?php
$titulo = 'Usuarios';
require __DIR__ . '/partials/header.php';
?>

<h1>Usuarios</h1>

<?php if (! empty($flash['mensaje'])): ?>
    <p style="padding:0.5rem 0.75rem;border-radius:4px;<?= ($flash['tipo'] ?? '') === 'exito'
        ? 'background:#f1f8f1;color:#2e7d32;border:1px solid #2e7d32;'
        : 'background:#fdecea;color:#b00020;border:1px solid #b00020;'; ?>">
        <?= htmlspecialchars($flash['mensaje']); ?>
        <?php if (! empty($flash['link'])): ?>
            <br><code style="user-select:all;word-break:break-all;"><?= htmlspecialchars($flash['link']); ?></code>
        <?php endif; ?>
    </p>
<?php endif; ?>

<h2>Invitar usuario</h2>
<p>
    Se genera un link de activacion (valido 48 horas, un solo uso) para que la persona
    invitada defina su propia contrasena. Copialo y compartelo por el canal que prefieras
    (Slack, WhatsApp, en persona) -- no se envia por correo.
</p>
<form method="post" action="/configuracion/usuarios" style="display:flex;gap:0.5rem;align-items:end;">
    <?= csrfInput(); ?>
    <label style="margin:0;flex:1;">Email
        <input type="email" name="email" required>
    </label>
    <button type="submit" style="margin:0;">Invitar</button>
</form>

<h2 style="margin-top:2rem;">Usuarios de tu cuenta</h2>

<?php if ($usuarios === []): ?>
    <p>No hay usuarios.</p>
<?php else: ?>
    <div class="tabla-scroll">
        <table class="tabla-usuarios">
            <thead>
                <tr><th>Email</th><th>Rol</th><th>Estado</th><th>Alta</th><th>Acciones</th></tr>
            </thead>
            <tbody>
                <?php foreach ($usuarios as $u): ?>
                    <?php $pendienteActivacion = $u['estado'] === 'inactivo' && ! empty($u['activacion_token']); ?>
                    <tr<?= $u['estado'] !== 'activo' ? ' class="fila-inactiva"' : ''; ?>>
                        <td><?= htmlspecialchars((string) $u['email']); ?></td>
                        <td><?= htmlspecialchars((string) $u['rol']); ?></td>
                        <td>
                            <?php if ($pendienteActivacion): ?>
                                invitacion pendiente
                            <?php else: ?>
                                <?= htmlspecialchars((string) $u['estado']); ?>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars((string) $u['created_at']); ?></td>
                        <td class="acciones">
                            <?php if ($u['estado'] === 'activo'): ?>
                                <form method="post" action="/configuracion/usuarios/<?= (int) $u['id']; ?>/desactivar" style="display:inline;">
                                    <?= csrfInput(); ?>
                                    <button type="submit">Desactivar</button>
                                </form>
                            <?php elseif (! $pendienteActivacion): ?>
                                <form method="post" action="/configuracion/usuarios/<?= (int) $u['id']; ?>/activar" style="display:inline;">
                                    <?= csrfInput(); ?>
                                    <button type="submit">Reactivar</button>
                                </form>
                            <?php else: ?>
                                <small>invita de nuevo arriba para reenviar el link</small>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
