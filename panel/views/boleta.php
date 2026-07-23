<?php $titulo = 'Certificacion de Boleta'; require __DIR__ . '/partials/header.php'; ?>

<h1>Certificacion de Boleta</h1>
<p>La boleta electronica (39) se certifica en un proceso <strong>APARTE</strong> del de
factura (Set Basico/Libros/Simulacion/Intercambio/Muestras/Declaracion de la seccion
"En certificacion"): tiene su propio circuito de 6 pasos ante el SII.</p>

<?php if ($flash !== null): ?>
    <?php if ($flash['tipo'] === 'error'): ?>
    <p class="errores"><?= htmlspecialchars($flash['mensaje']); ?></p>
    <?php else: ?>
    <p style="color:#2e7d32;font-weight:600;"><?= htmlspecialchars($flash['mensaje']); ?></p>
    <?php endif; ?>
<?php endif; ?>

<?php
    // Barra de progreso de 6 pasos PROPIA de boleta -- mismo espiritu visual
    // que la barra de 6 etapas de factura (certificacion.php), pero sin
    // mezclarse: pasos y estado completamente distintos. Calculo extraido a
    // resumenEtapasBoletaBarra() (public/index.php) -- la misma funcion la
    // usa /certificacion-elegir para el resumen compacto, no se duplica el
    // criterio de color en 2 lugares. Segmentos NO son links (a diferencia
    // de la barra de factura): esta pagina no esta partida en sub-vistas.
    $pasosBoleta = resumenEtapasBoletaBarra($cafBoleta, $setEmitido, $rvd !== null, $etapasBoleta);
?>
<div class="progreso-etapas">
    <?php foreach ($pasosBoleta as $i => $p): ?>
        <div class="etapa <?= $p['clase']; ?>"
             <?php if ($p['clase'] === 'etapa--no-gestionada'): ?>title="Aun no corresponde: falta completar un paso anterior."<?php elseif ($p['clase'] === 'etapa--rechazada'): ?>title="El SII rechazo (SRH) la revision del Set."<?php endif; ?>>
            <span class="etapa__numero"><?= $i + 1; ?></span>
            <?= htmlspecialchars($p['nombre']); ?>
        </div>
    <?php endforeach; ?>
</div>

<div class="tarjeta">
    <h2>Que sigue</h2>
    <ul style="padding-left:1.2rem;margin:0.5rem 0 0;">
        <li style="margin-bottom:0.5rem;">
            El paso 4 (Revision) se hace en
            <a href="https://www4.sii.cl/certBolElectDteInternet/?SET=2" target="_blank" rel="noopener noreferrer">certBolElectDteInternet (SET=2)</a>,
            informando el track ID del <strong>Set</strong> (NO el del RVD).
        </li>
        <li style="margin-bottom:0.5rem;">
            El resultado (V.B. o rechazo) llega por correo o se revisa en el portal,
            sin plazo fijo.
        </li>
        <li>
            La <strong>Declaracion de Cumplimiento de Boleta</strong> es un tramite
            <strong>DISTINTO</strong> al de factura: se hace en
            <a href="https://www4.sii.cl/certBolElectDteInternet/" target="_blank" rel="noopener noreferrer">certBolElectDteInternet</a>
            (sin <code>?SET=2</code>).
        </li>
    </ul>
</div>

<div class="tarjeta">
    <h2>2. Set de Prueba de Boleta</h2>
    <?php if ($setEmitido): ?>
    <p style="color:#2e7d32;font-weight:600;">
        EMITIDO &mdash; <?= $cantidadEmitida; ?> boleta(s) registrada(s) para este tenant.
    </p>
    <?php else: ?>
    <p style="color:#999;font-weight:600;">NO EMITIDO todavia.</p>
    <?php endif; ?>
    <p><a href="/certificacion/boleta/set">Ver / emitir el Set de Prueba de Boleta &rarr;</a></p>
</div>

<div class="tarjeta">
    <h2>3. RVD (Registro de Ventas Diario)</h2>
    <?php if ($rvd !== null): ?>
    <p style="color:#2e7d32;font-weight:600;">
        ENVIADO &mdash; <?= htmlspecialchars((string) $rvd['fecha_rvd']); ?>, track ID
        <?= htmlspecialchars((string) ($rvd['track_id'] ?? 'sin track id')); ?>.
    </p>
    <?php else: ?>
    <p style="color:#999;font-weight:600;">NO ENVIADO todavia.</p>
    <?php endif; ?>
    <p><a href="/certificacion/boleta/rvd">Ver / enviar el RVD &rarr;</a></p>
</div>

<div class="tarjeta">
    <h2>Pasos 4-6 (fuera del panel)</h2>
    <p style="color:#999;">Estos pasos se hacen en el portal del SII, fuera de este panel.
    Confirma cada uno aqui SOLO despues de completarlo realmente &mdash; es tu propia
    declaracion, el panel no puede detectarlos por si solo.</p>

    <?php
        $subEtapasBoleta = [
            'revision' => [
                'nombre'      => 'Revision (SET=2)',
                'datos'       => $etapasBoleta['revision'],
                'conTrackId'  => true,
                'conResultado' => false,
            ],
            'vobo' => [
                'nombre'      => 'Resultado de la revision (V.B. del SII)',
                'datos'       => $etapasBoleta['vobo'],
                'conTrackId'  => false,
                'conResultado' => true,
            ],
            'cumplimiento' => [
                'nombre'      => 'Declaracion de Cumplimiento de Boleta',
                'datos'       => $etapasBoleta['cumplimiento'],
                'conTrackId'  => false,
                'conResultado' => false,
            ],
        ];
    ?>

    <?php foreach ($subEtapasBoleta as $clave => $sub): ?>
        <?php $d = $sub['datos']; ?>
        <div style="border-top:1px solid #eee;padding:0.75rem 0;">
            <strong><?= htmlspecialchars($sub['nombre']); ?></strong>

            <?php if ($d['confirmada']): ?>
            <p style="<?= ($sub['conResultado'] && $d['resultado'] === 'rechazado') ? 'color:#b00020;font-weight:600;' : 'color:#2e7d32;font-weight:600;'; ?>margin:0.35rem 0 0;">
                <?php if ($sub['conResultado']): ?>
                    <?= $d['resultado'] === 'rechazado' ? 'RECHAZADO' : 'APROBADO'; ?> &mdash; <?= htmlspecialchars((string) $d['fecha']); ?>
                <?php else: ?>
                    CONFIRMADA &mdash; <?= htmlspecialchars((string) $d['fecha']); ?>
                <?php endif; ?>
                <?php if ($sub['conTrackId'] && ! empty($d['trackId'])): ?>
                    (track ID <?= htmlspecialchars($d['trackId']); ?>)
                <?php endif; ?>
            </p>
            <?php if ($sub['conResultado'] && $d['resultado'] === 'rechazado'): ?>
            <p class="aviso-ambar" style="margin:0.35rem 0 0;">
                El SII rechazo (SRH) la revision del Set: la Declaracion de Cumplimiento queda
                bloqueada. Hay que rehacer el Set de Boleta, el RVD y pedir una Revision nueva.
            </p>
            <?php endif; ?>
            <?php elseif ($d['habilitada']): ?>
            <form method="post" action="/certificacion/boleta/confirmar-etapa" class="aviso-ambar" style="margin:0.5rem 0 0;">
                <?= csrfInput(); ?>
                <input type="hidden" name="etapa" value="<?= htmlspecialchars($clave); ?>">
                <?php if ($sub['conTrackId']): ?>
                <label style="display:block;margin:0 0 0.5rem;font-weight:400;">
                    Track ID del Set de Boleta:
                    <input type="text" name="track_id" required style="width:auto;display:inline-block;margin-left:0.5rem;">
                </label>
                <?php endif; ?>
                <?php if ($sub['conResultado']): ?>
                <label style="display:block;margin:0 0 0.35rem;font-weight:400;">
                    <input type="radio" name="resultado" value="aprobado" required style="display:inline-block;width:auto;">
                    El SII APROBO (V.B.)
                </label>
                <label style="display:block;margin:0 0 0.5rem;font-weight:400;">
                    <input type="radio" name="resultado" value="rechazado" required style="display:inline-block;width:auto;">
                    El SII RECHAZO (SRH)
                </label>
                <?php endif; ?>
                <label style="display:block;margin:0 0 0.5rem;font-weight:400;">
                    <input type="checkbox" name="confirmo" value="1" style="display:inline-block;width:auto;">
                    Confirmo que ya completaste <?= htmlspecialchars($sub['nombre']); ?> fuera del panel.
                </label>
                <button type="submit" onclick="return confirm('Confirmas que ya completaste este paso?');">Confirmar</button>
            </form>
            <?php else: ?>
            <p style="color:#999;margin:0.35rem 0 0;">
                PENDIENTE &mdash; deshabilitado: falta completar el paso anterior.
            </p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<p><a href="/certificacion">Volver a En certificacion</a></p>
<p><a href="/panel">Volver al panel</a></p>

<?php require __DIR__ . '/partials/footer.php'; ?>
