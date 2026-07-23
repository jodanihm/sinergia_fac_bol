<?php $titulo = 'Iniciar sesion'; require __DIR__ . '/partials/header.php'; ?>

<h1>Iniciar sesion</h1>

<?php if (! empty($error)): ?>
<p class="errores"><?= htmlspecialchars($error); ?></p>
<?php endif; ?>

<form method="post" action="/login">
    <?= csrfInput(); ?>
    <label>Email
        <input type="email" name="email" value="<?= htmlspecialchars($email); ?>" required>
    </label>
    <label>Contrasena
        <input type="password" name="password" required>
    </label>
    <button type="submit">Entrar</button>
</form>

<p>&iquest;Aun no tienes cuenta? <a href="/registro">Registrate</a></p>

<?php require __DIR__ . '/partials/footer.php'; ?>
