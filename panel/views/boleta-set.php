<?php $titulo = 'Set de Prueba de Boleta'; require __DIR__ . '/partials/header.php'; ?>

<h1>Set de Prueba de Boleta</h1>
<p>Estos 5 casos son el Set de Prueba de Boleta que entrega el SII -- <strong>universal</strong>,
igual para cualquier contribuyente (confirmado: sin numero de atencion ni variacion por
tenant, a diferencia del Set de Pruebas de factura). Se envian los 5 en UN solo EnvioBOLETA.</p>

<?php if ($flash !== null): ?>
    <?php if ($flash['tipo'] === 'error'): ?>
    <p class="errores"><?= htmlspecialchars($flash['mensaje']); ?></p>
    <?php else: ?>
    <p style="color:#2e7d32;font-weight:600;"><?= htmlspecialchars($flash['mensaje']); ?></p>
    <?php endif; ?>
<?php endif; ?>

<table>
    <thead>
        <tr>
            <th>Caso</th>
            <th>Item</th>
            <th>Cantidad</th>
            <th>Precio Unitario (con IVA)</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($casos as $caso): ?>
        <?php foreach ($caso['detalles'] as $i => $d): ?>
        <tr>
            <td><?= $i === 0 ? htmlspecialchars($caso['nombre']) : ''; ?></td>
            <td>
                <?= htmlspecialchars($d['nombre']); ?>
                <?php if (! empty($d['exento'])): ?> <em>(exento)</em><?php endif; ?>
                <?php if (! empty($d['unidad'])): ?> <em>(<?= htmlspecialchars($d['unidad']); ?>)</em><?php endif; ?>
            </td>
            <td><?= (int) $d['cantidad']; ?></td>
            <td><?= number_format((int) $d['precioUnitario'], 0, ',', '.'); ?></td>
        </tr>
        <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
</table>

<form method="post" action="/certificacion/boleta/set/emitir" style="margin:1rem 0;"
      onsubmit="return confirm('Emitir el Set de Prueba de Boleta (5 boletas)? Esto consume folios reales del CAF de boleta.');">
    <?= csrfInput(); ?>
    <button type="submit">Emitir Set de Prueba de Boleta (5 boletas)</button>
</form>

<p><a href="/certificacion/boleta">Volver a Certificacion de Boleta &rarr;</a></p>
<p><a href="/panel">Volver al panel</a></p>

<?php require __DIR__ . '/partials/footer.php'; ?>
