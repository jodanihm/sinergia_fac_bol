<section class="tarjeta" aria-labelledby="titulo-autorizacion">
    <h2 id="titulo-autorizacion">Autorizacion</h2>
    <?php if ($certificacionConfirmadaAt !== null): ?>
    <div class="alerta alerta--exito">
        <?= iconoSvg('check', 20, 'alerta__icono'); ?>
        <div class="alerta__cuerpo">
            <p class="alerta__titulo">Declaraste Cumplimiento el <?= htmlspecialchars((string) $certificacionConfirmadaAt); ?></p>
            <p>Revisa en el portal del SII que tu empresa haya quedado autorizada como emisor electronico.</p>
        </div>
    </div>
    <?php else: ?>
    <div class="alerta alerta--info">
        <?= iconoSvg('sin-cargar', 20, 'alerta__icono'); ?>
        <div class="alerta__cuerpo">
            <p class="alerta__titulo">Todavia no corresponde</p>
        <p>Esta etapa se activa cuando declares Cumplimiento en el portal del SII (etapa 5).</p>
        </div>
    </div>
    <?php endif; ?>
    <p><a class="boton-secundario" href="/certificacion-aprobada">Ir a Certificacion aprobada</a></p>
</section>
