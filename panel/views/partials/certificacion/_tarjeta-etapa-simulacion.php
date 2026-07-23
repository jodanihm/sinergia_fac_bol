<div class="tarjeta">
    <h2>Simulacion</h2>
    <p style="color:#999;">Simulacion se hace en el portal del SII o por correo: el panel no
    puede detectarla por si sola. Confirmala aqui SOLO despues de completarla realmente
    &mdash; es tu propia declaracion, igual que en "Certificacion aprobada".</p>

    <?php $d = $etapasManuales['simulacion']; ?>

    <?php
        // El helper "Generar y enviar" NO depende de $d['habilitada'] (esa
        // condicion exige el Set Basico aprobado CON SOK, ver
        // calcularEtapasManuales()): la Simulacion es una etapa independiente
        // del Set Basico segun el manual del SII, solo exige que la etapa 1
        // este EPR+3-tipos ($setBasicoSinSok, calculado en
        // calcularDatosCertificacion()). Por eso se muestra tambien en el
        // caso EPR-sin-SOK, antes de que el checkbox de confirmacion manual
        // este habilitado.
        $puedeGenerarSimulacion = ! $d['confirmada'] && ($d['habilitada'] || $setBasicoSinSok['aprobado']);
    ?>
    <?php if ($puedeGenerarSimulacion): ?>
    <p style="margin:0.5rem 0 0;">
        <a href="/certificacion/simulacion">Generar y enviar el Set de Simulacion desde aqui &rarr;</a>
        <span style="color:#999;font-size:0.85em;display:block;">Alternativa al proceso 100%
        manual: arma y envia el lote por ti. NO reemplaza la confirmacion de abajo, que sigue
        siendo manual y obligatoria &mdash; el panel nunca puede saber por si solo si el SII
        aprobo el contenido.</span>
    </p>
    <?php endif; ?>

    <?php if ($d['confirmada']): ?>
    <p style="color:#2e7d32;font-weight:600;margin:0.35rem 0 0;">
        CONFIRMADA &mdash; <?= htmlspecialchars((string) $d['fecha']); ?>
        <?php if (! empty($d['trackId'])): ?>
            (track ID <?= htmlspecialchars($d['trackId']); ?>)
        <?php endif; ?>
    </p>
    <?php elseif ($d['habilitada']): ?>
    <form method="post" action="/certificacion/confirmar-etapa" class="aviso-ambar" style="margin:0.5rem 0 0;">
        <?= csrfInput(); ?>
        <input type="hidden" name="etapa" value="simulacion">
        <label style="display:block;margin:0 0 0.5rem;font-weight:400;">
            Track ID (opcional):
            <input type="text" name="track_id" style="width:auto;display:inline-block;margin-left:0.5rem;">
        </label>
        <label style="display:block;margin:0 0 0.5rem;font-weight:400;">
            <input type="checkbox" name="confirmo" value="1" style="display:inline-block;width:auto;">
            Confirmo que ya completaste Simulacion fuera del panel.
        </label>
        <button type="submit" onclick="return confirm('Confirmas que ya completaste esta etapa?');">Confirmar</button>
    </form>
    <?php elseif ($puedeGenerarSimulacion): ?>
    <p style="color:#999;margin:0.35rem 0 0;">
        Aun no puedes confirmar Simulacion manualmente (el checkbox de abajo se habilita cuando
        el Set Basico este 100% aprobado, con SOK) &mdash; pero ya puedes generarla y enviarla
        con el link de arriba mientras esperas esa confirmacion.
    </p>
    <?php else: ?>
    <p style="color:#999;margin:0.35rem 0 0;">
        PENDIENTE &mdash; deshabilitado: primero debe el Set de Prueba (etapa 1) este APROBADO.
    </p>
    <?php endif; ?>
</div>
