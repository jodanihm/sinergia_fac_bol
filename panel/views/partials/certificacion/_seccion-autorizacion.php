<div class="tarjeta">
    <h2>Autorizacion</h2>
    <?php if ($certificacionConfirmadaAt !== null): ?>
    <p style="color:#2e7d32;font-weight:600;">
        Declaraste Cumplimiento el <?= htmlspecialchars((string) $certificacionConfirmadaAt); ?>.
        Verifica en el portal del SII que la empresa haya quedado AUTORIZADA como emisor
        electronico.
    </p>
    <?php else: ?>
    <p style="color:#999;">
        Esta etapa se activa cuando declares Cumplimiento en el portal del SII (etapa 5,
        "Declaracion Cumplimiento").
    </p>
    <?php endif; ?>
    <p><a href="/certificacion-aprobada">Ir a Certificacion aprobada &rarr;</a></p>
</div>
