<?php $titulo = 'Certificacion aprobada'; require __DIR__ . '/partials/header.php'; ?>

<h1>Certificacion aprobada</h1>

<?php if ($flash !== null): ?>
    <?php if ($flash['tipo'] === 'error'): ?>
    <p class="errores"><?= htmlspecialchars($flash['mensaje']); ?></p>
    <?php else: ?>
    <p style="color:#2e7d32;font-weight:600;"><?= htmlspecialchars($flash['mensaje']); ?></p>
    <?php endif; ?>
<?php endif; ?>

<?php if ($confirmadaAt !== null): ?>

<p style="color:#2e7d32;font-weight:600;">Confirmaste la certificacion el
<?= htmlspecialchars($confirmadaAt); ?>.</p>
<p>Tu empresa quedo registrada en este panel como emisor electronico autorizado
por el SII, segun tu propia declaracion.</p>

<?php else: ?>

<p>El SII <strong>no informa automaticamente</strong> cuando una empresa queda
certificada: la aprobacion se declara manualmente en el portal del SII y este
panel no puede detectarla por si solo.</p>

<p>Ya completaste el set basico (factura, nota de credito y nota de debito
aceptadas). El paso final lo haces tu en el portal del SII:</p>

<ol>
    <li>Entra a
        <a href="https://maullin.sii.cl/cvc_cgi/dte/pe_avance7" target="_blank" rel="noopener noreferrer">Declarar
        Cumplimiento (pe_avance7)</a>.
        <strong>Advertencia: este paso es IRREVERSIBLE en el SII.</strong> Hazlo
        solo cuando el portal muestre todos los sets en "REVISADO CONFORME".</li>
    <li>Cuando el SII autorice a tu empresa como emisor electronico, vuelve aqui
        y registra la confirmacion.</li>
</ol>

<form method="post" action="/certificacion-aprobada/confirmar">
    <?= csrfInput(); ?>
    <label style="display:block;margin:1rem 0;">
        <input type="checkbox" name="confirmo" value="1">
        Confirmo que declare cumplimiento en el portal del SII y que mi empresa
        fue autorizada como emisor electronico.
    </label>
    <button type="submit">Registrar confirmacion</button>
</form>

<?php endif; ?>

<p><a href="/panel">Volver al panel</a></p>

<?php require __DIR__ . '/partials/footer.php'; ?>
