<div class="tarjeta">
    <h2>Intercambio</h2>
    <p style="color:#999;">Intercambio se hace en el portal del SII o por correo: el panel no
    puede detectarlo por si solo. Confirmalo aqui SOLO despues de completarlo realmente
    &mdash; es tu propia declaracion, igual que en "Certificacion aprobada".</p>

    <?php $d = $etapasManuales['intercambio']; ?>

    <?php if ($d['confirmada']): ?>
    <p style="color:#2e7d32;font-weight:600;margin:0.35rem 0 0;">
        CONFIRMADA &mdash; <?= htmlspecialchars((string) $d['fecha']); ?>
    </p>
    <?php elseif ($d['habilitada']): ?>
    <form method="post" action="/certificacion/confirmar-etapa" class="aviso-ambar" style="margin:0.5rem 0 0;">
        <?= csrfInput(); ?>
        <input type="hidden" name="etapa" value="intercambio">
        <label style="display:block;margin:0 0 0.5rem;font-weight:400;">
            <input type="checkbox" name="confirmo" value="1" style="display:inline-block;width:auto;">
            Confirmo que ya completaste Intercambio fuera del panel.
        </label>
        <button type="submit" onclick="return confirm('Confirmas que ya completaste esta etapa?');">Confirmar</button>
    </form>
    <?php else: ?>
    <p style="color:#999;margin:0.35rem 0 0;">
        PENDIENTE &mdash; deshabilitado: primero debe Simulacion este confirmada.
    </p>
    <?php endif; ?>
</div>
