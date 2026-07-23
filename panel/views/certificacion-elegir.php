<?php $titulo = 'Certificacion: elige un proceso'; require __DIR__ . '/partials/header.php'; ?>

<h1>Certificacion</h1>
<p>Factura y Boleta son procesos <strong>independientes</strong> ante el SII, cada uno con
su propio circuito de 6 pasos. Elige cual quieres revisar.</p>

<div class="tarjeta">
    <h2>Factura</h2>
    <p style="font-weight:600;"><?= $completadasFactura; ?> de 6 pasos completados.</p>
    <p style="color:#999;"><?= htmlspecialchars($textoFactura); ?></p>
    <div class="progreso-etapas">
        <?php foreach ($segmentosFactura as $s): ?>
            <div class="etapa <?= $s['clase']; ?>"><?= htmlspecialchars($s['nombre']); ?></div>
        <?php endforeach; ?>
    </div>
    <p><a href="/certificacion">Ir a Certificacion de Factura &rarr;</a></p>
</div>

<div class="tarjeta">
    <h2>Boleta</h2>
    <p style="font-weight:600;"><?= $completadasBoleta; ?> de 6 pasos completados.</p>
    <p style="color:#999;"><?= htmlspecialchars($textoBoleta); ?></p>
    <div class="progreso-etapas">
        <?php foreach ($segmentosBoleta as $s): ?>
            <div class="etapa <?= $s['clase']; ?>"><?= htmlspecialchars($s['nombre']); ?></div>
        <?php endforeach; ?>
    </div>
    <p><a href="/certificacion/boleta">Ir a Certificacion de Boleta &rarr;</a></p>
</div>

<p><a href="/panel">Volver al panel</a></p>

<?php require __DIR__ . '/partials/footer.php'; ?>
