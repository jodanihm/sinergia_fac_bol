<?php $titulo = 'Certificado digital'; require __DIR__ . '/partials/header.php'; ?>

<h1>Certificado digital</h1>
<p>Sube tu certificado digital (.pfx o .p12) emitido por el SII para tu RUT, junto
con la clave asociada. El archivo se cifra antes de guardarse; la clave nunca se
almacena.</p>

<?php if (! empty($error)): ?>
<p class="errores"><?= htmlspecialchars($error); ?></p>
<?php endif; ?>

<form method="post" action="/certificado" enctype="multipart/form-data">
    <?= csrfInput(); ?>
    <label>Archivo del certificado (.pfx / .p12)
        <input type="file" name="certificado" accept=".pfx,.p12" required>
    </label>
    <label>Clave del certificado
        <input type="password" name="clave" required>
    </label>
    <button type="submit">Subir certificado</button>
</form>

<p><a href="/panel">Volver al panel</a></p>

<?php require __DIR__ . '/partials/footer.php'; ?>
