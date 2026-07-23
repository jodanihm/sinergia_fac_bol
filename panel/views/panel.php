<?php $titulo = 'Panel'; require __DIR__ . '/partials/header.php'; ?>

<h1>Tu progreso</h1>

<div class="estaciones">
<?php foreach ($estaciones as $i => $e): ?>
    <div class="estacion estacion--<?= htmlspecialchars($e['estado']); ?>">
        <span class="estacion__numero"><?= $i + 1; ?></span>
        <span class="estacion__titulo"><?= htmlspecialchars($e['titulo']); ?></span>
        <span class="estacion__estado">
            <?php if ($e['estado'] === 'completado'): ?>
                Completado
                <?php if (isset($e['enlace'])): ?>
                    &mdash; <a href="<?= htmlspecialchars($e['enlace']); ?>">Ver / editar</a>
                <?php endif; ?>
            <?php elseif ($e['estado'] === 'pendiente'): ?>
                Pendiente &mdash; <a href="<?= htmlspecialchars($e['enlace'] ?? '#'); ?>">completar</a>
            <?php else: ?>
                Proximamente
            <?php endif; ?>
        </span>
    </div>
<?php endforeach; ?>
</div>

<?php if ($mostrarApiKeys): ?>
<div style="margin-top:1.5rem;padding:0.75rem;border:1px solid #ddd;border-radius:6px;">
    <strong>Credenciales de API</strong>
    <p style="margin:0.4rem 0 0;">Genera o administra las API keys para conectar tus
    sistemas al motor de facturacion.</p>
    <p style="margin:0.4rem 0 0;"><a href="/apikeys">Ver / generar API keys</a></p>
</div>
<?php endif; ?>

<p><a href="/logout">Cerrar sesion</a></p>

<?php require __DIR__ . '/partials/footer.php'; ?>
