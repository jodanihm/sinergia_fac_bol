<?php $titulo = 'Crear cuenta'; require __DIR__ . '/partials/header.php'; ?>

<h1>Crear cuenta</h1>

<?php if (! empty($errores)): ?>
<ul class="errores">
    <?php foreach ($errores as $e): ?>
    <li><?= htmlspecialchars($e); ?></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<form method="post" action="/registro">
    <?= csrfInput(); ?>
    <label>Email
        <input type="email" name="email" value="<?= htmlspecialchars($email); ?>" required>
    </label>
    <label>Contrasena (minimo 8 caracteres)
        <input type="password" name="password" minlength="8" required>
    </label>
    <label>Confirmar contrasena
        <input type="password" name="password_confirmacion" minlength="8" required>
    </label>
    <button type="submit">Crear cuenta</button>
</form>

<p>&iquest;Ya tienes cuenta? <a href="/login">Inicia sesion</a></p>

<?php require __DIR__ . '/partials/footer.php'; ?>
