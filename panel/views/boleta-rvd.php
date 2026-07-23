<?php $titulo = 'RVD de Boleta'; require __DIR__ . '/partials/header.php'; ?>

<h1>RVD (Registro de Ventas Diario)</h1>
<p>Resumen calculado <strong>DINAMICAMENTE</strong> a partir de las boletas ya emitidas
(no requiere verificar la aritmetica a mano). Revisa los montos antes de enviar.</p>

<?php if ($flash !== null): ?>
    <?php if ($flash['tipo'] === 'error'): ?>
    <p class="errores"><?= htmlspecialchars($flash['mensaje']); ?></p>
    <?php else: ?>
    <p style="color:#2e7d32;font-weight:600;"><?= htmlspecialchars($flash['mensaje']); ?></p>
    <?php endif; ?>
<?php endif; ?>

<p>Fecha del RVD: <strong><?= htmlspecialchars($fecha); ?></strong></p>

<table>
    <thead>
        <tr>
            <th>Tipo Documento</th>
            <th>Neto</th>
            <th>IVA</th>
            <th>Exento</th>
            <th>Total</th>
            <th>Folios</th>
            <th>Rangos</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($resumenes as $r): ?>
        <tr>
            <td><?= htmlspecialchars(nombreTipoDte($r['tipoDocumento'])); ?></td>
            <td><?= number_format($r['mntNeto'], 0, ',', '.'); ?></td>
            <td><?= number_format($r['mntIva'], 0, ',', '.'); ?></td>
            <td><?= number_format($r['mntExento'] ?? 0, 0, ',', '.'); ?></td>
            <td><?= number_format($r['mntTotal'], 0, ',', '.'); ?></td>
            <td><?= $r['foliosUtilizados']; ?></td>
            <td>
                <?php foreach ($r['rangos'] as $rango): ?>
                    <?= $rango[0]; ?>-<?= $rango[1]; ?>
                <?php endforeach; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<form method="post" action="/certificacion/boleta/rvd/emitir" style="margin:1rem 0;"
      onsubmit="return confirm('Enviar el RVD con estos montos al SII?');">
    <?= csrfInput(); ?>
    <button type="submit">Enviar RVD</button>
</form>

<p><a href="/certificacion/boleta">Volver a Certificacion de Boleta &rarr;</a></p>
<p><a href="/panel">Volver al panel</a></p>

<?php require __DIR__ . '/partials/footer.php'; ?>
