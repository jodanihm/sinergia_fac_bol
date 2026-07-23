<?php $titulo = 'Certificado digital (PRODUCCION)'; require __DIR__ . '/partials/header.php'; ?>

<h1>Certificado digital &mdash; PRODUCCION</h1>

<div class="seccion-manual">
    <strong>Atencion:</strong> esta pagina es del ambiente de PRODUCCION (palena.sii.cl).
    El certificado que subas aqui firma documentos TRIBUTARIOS REALES ante el SII, no
    de prueba. No la uses hasta que tu empresa este realmente AUTORIZADA como emisor
    electronico (estacion 6/7).
</div>

<p>Sube tu certificado digital (.pfx o .p12) emitido por el SII para tu RUT, junto
con la clave asociada. El archivo se cifra antes de guardarse; la clave nunca se
almacena. Mismo mecanismo de cifrado que en certificacion, guardado en una fila
separada (ambiente = produccion).</p>

<?php if (! empty($error)): ?>
<p class="errores"><?= htmlspecialchars($error); ?></p>
<?php endif; ?>

<form method="post" action="/certificado-produccion" enctype="multipart/form-data">
    <?= csrfInput(); ?>
    <label>Archivo del certificado (.pfx / .p12)
        <input type="file" name="certificado" accept=".pfx,.p12" required>
    </label>
    <label>Clave del certificado
        <input type="password" name="clave" required>
    </label>
    <button type="submit">Subir certificado de produccion</button>
</form>

<p><a href="/caf-produccion">Siguiente: CAF de produccion &rarr;</a></p>
<p><a href="/panel">Volver al panel</a></p>

<?php require __DIR__ . '/partials/footer.php'; ?>
