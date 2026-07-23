<div class="tarjeta">
    <h2>Libro de Ventas</h2>

    <?php if ($libroVentasAprobado['aprobado']): ?>
    <p style="color:#2e7d32;font-weight:600;">
        APROBADO &mdash; el envio <?= htmlspecialchars($libroVentasAprobado['trackId']); ?> fue
        aceptado por el SII (LOK/LTC).
    </p>
    <?php else: ?>
    <p style="color:#999;font-weight:600;">
        PENDIENTE &mdash; aun no hay un envio de Libro de Ventas aceptado por el SII (LOK/LTC).
    </p>
    <?php endif; ?>

    <?php if ($setBasico['aprobado']): ?>
    <form method="post" action="/certificacion/emitir-libro-ventas" style="margin:0.5rem 0;">
        <?= csrfInput(); ?>
        <button type="submit">Emitir Libro de Ventas</button>
    </form>
    <?php else: ?>
    <p style="color:#999;">El Set Basico debe estar APROBADO (EPR) antes de poder emitir el Libro de Ventas.</p>
    <?php if ($setBasicoSinSok['aprobado']): ?>
    <div class="aviso-ambar" style="margin-top:0.5rem;">
        <p style="margin:0 0 0.5rem;">
            <strong>Opcion de riesgo:</strong> el SII acepto TECNICAMENTE tu envio del Set
            Basico (EPR con los 3 tipos de documento), pero AUN no confirmo el contenido (sin
            SOK). Si construyes el Libro de Ventas ahora y el SII rechaza despues el
            contenido del Set Basico, tendras que rehacer el Set Basico Y el Libro de Ventas.
        </p>
        <form method="post" action="/certificacion/emitir-libro-ventas"
              onsubmit="return confirm('Estas seguro? Si el Set Basico es rechazado despues, tendras que rehacer el Set Basico Y este Libro.');">
            <?= csrfInput(); ?>
            <input type="hidden" name="modo" value="sin_sok">
            <label style="display:block;margin:0 0 0.5rem;font-weight:400;">
                <input type="checkbox" name="acepto_riesgo" value="1" required style="display:inline-block;width:auto;">
                Entiendo el riesgo y quiero emitir de todas formas.
            </label>
            <button type="submit">Emitir Libro de Ventas (sin esperar SOK)</button>
        </form>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <details>
        <summary>Historial de envios (<?= count($librosVentas); ?>)</summary>
        <div style="margin-top:0.75rem;">
        <?php if ($librosVentas === []): ?>
        <p>Aun no has enviado ningun Libro de Ventas.</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Track ID</th>
                    <th>Fecha de envio</th>
                    <th>Periodo / tipo</th>
                    <th>Estado</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($librosVentas as $libro): ?>
                <tr>
                    <td><?= htmlspecialchars((string) ($libro['track_id'] ?? 'sin track id')); ?></td>
                    <td><?= htmlspecialchars((string) $libro['created_at']); ?></td>
                    <td><?= htmlspecialchars($libro['periodo_tributario'] . ' (' . $libro['tipo_libro'] . '/' . $libro['tipo_envio'] . ')'); ?></td>
                    <td><?= htmlspecialchars((string) $libro['estado']); ?></td>
                    <td>
                        <?php if (! empty($libro['track_id'])): ?>
                        <form method="post" action="/certificacion/actualizar-libro" style="margin:0;">
                            <?= csrfInput(); ?>
                            <input type="hidden" name="track_id" value="<?= htmlspecialchars((string) $libro['track_id']); ?>">
                            <button type="submit">Actualizar estado</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        </div>
    </details>
</div>
