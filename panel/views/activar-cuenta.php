<?php $titulo = 'Activar acceso'; require __DIR__ . '/partials/header.php'; ?>

<h1>Activar tu acceso</h1>

<?php if ($resolucion['estado'] === 'invalido'): ?>
    <p style="padding:0.5rem 0.75rem;border-radius:4px;background:#fdecea;color:#b00020;border:1px solid #b00020;">
        Este link no es valido. Puede que ya se haya usado, o que nunca haya existido.
        Pide a quien administra tu cuenta que te invite de nuevo.
    </p>
    <p><a href="/login">Ir a iniciar sesion</a></p>
<?php elseif ($resolucion['estado'] === 'vencido'): ?>
    <p style="padding:0.5rem 0.75rem;border-radius:4px;background:#fdecea;color:#b00020;border:1px solid #b00020;">
        Este link de activacion vencio (son validos 48 horas). Pide a quien administra tu
        cuenta que te invite de nuevo desde "Usuarios" -- el mismo email genera un link nuevo.
    </p>
    <p><a href="/login">Ir a iniciar sesion</a></p>
<?php else: ?>
    <p>Definiendo el acceso para <strong><?= htmlspecialchars((string) $resolucion['usuario']['email']); ?></strong>.</p>

    <?php if (! empty($errores)): ?>
        <ul class="errores">
            <?php foreach ($errores as $e): ?>
                <li><?= htmlspecialchars($e); ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <form method="post" action="/activar/<?= htmlspecialchars($token); ?>">
        <?= csrfInput(); ?>
        <label>Contrasena (minimo 8 caracteres)
            <input type="password" name="password" minlength="8" required>
        </label>
        <label>Confirmar contrasena
            <input type="password" name="password_confirmacion" minlength="8" required>
        </label>
        <button type="submit">Activar</button>
    </form>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
