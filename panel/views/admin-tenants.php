<?php $titulo = 'Superadmin: tenants'; require __DIR__ . '/partials/header.php'; ?>

<h1>Superadmin &mdash; Tenants</h1>
<p style="color:#999;">Vista de vigilancia de TODAS las cuentas del SaaS (no solo la tuya).
Solo lectura, salvo suspender/reactivar y revertir una etapa confirmada por error.</p>
<p><a href="/admin/auditoria">Ver auditoria de acciones &rarr;</a></p>

<?php if ($flash !== null): ?>
    <?php if ($flash['tipo'] === 'error'): ?>
    <p class="errores"><?= htmlspecialchars($flash['mensaje']); ?></p>
    <?php else: ?>
    <p style="color:#2e7d32;font-weight:600;"><?= htmlspecialchars($flash['mensaje']); ?></p>
    <?php endif; ?>
<?php endif; ?>

<?php
    // Etapas con boton "Revertir": indice en la barra de 6 -> columna
    // dte_emisor asociada (whitelist identica a la del handler). Indice 0
    // (Set Basico, se calcula de datos reales, no de una confirmacion) e
    // indice 5 (Autorizacion, comparte certificacion_confirmada_at con la
    // 5) NO llevan boton.
    $camposRevertiblesPorIndice = [
        1 => 'simulacion_confirmada_at',
        2 => 'intercambio_confirmado_at',
        3 => 'muestras_impresas_confirmadas_at',
        4 => 'certificacion_confirmada_at',
    ];
?>

<?php if ($resumen === []): ?>
<p>No hay cuentas registradas.</p>
<?php else: ?>
<div class="tabla-scroll">
<table>
    <thead>
        <tr>
            <th>Cuenta</th>
            <th>Estado</th>
            <th>RUT(s) emisor</th>
            <th>Etapas de certificacion (factura)</th>
            <th>Produccion</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($resumen as $fila): ?>
        <?php $c = $fila['cuenta']; ?>
        <tr>
            <td>
                <?= htmlspecialchars((string) $c['nombre']); ?><br>
                <span style="color:#999;font-size:0.85em;"><?= htmlspecialchars((string) $c['email']); ?></span>
            </td>
            <td>
                <span style="<?= $c['estado'] === 'activa' ? 'color:#2e7d32;font-weight:600;' : 'color:#b00020;font-weight:600;'; ?>">
                    <?= htmlspecialchars(strtoupper((string) $c['estado'])); ?>
                </span>
            </td>
            <td>
                <?php if ($fila['emisores'] === []): ?>
                <span style="color:#999;">(sin emisor)</span>
                <?php else: ?>
                    <?php foreach ($fila['emisores'] as $e): ?>
                    <div><?= htmlspecialchars($e['rutEmisor']); ?></div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($fila['emisores'] === []): ?>
                &mdash;
                <?php else: ?>
                    <?php foreach ($fila['emisores'] as $e): ?>
                    <div class="progreso-etapas--admin">
                        <?php foreach ($e['barra'] as $i => $etapa): ?>
                        <?php $campoRevertible = $camposRevertiblesPorIndice[$i] ?? null; ?>
                        <div class="etapa-circulo <?= $etapa['clase']; ?>" title="<?= htmlspecialchars($etapa['nombre']); ?>">
                            <?= $i + 1; ?>
                            <?php if ($campoRevertible !== null && $etapa['completada']): ?>
                            <form method="post" action="/admin/tenants/revertir-etapa" class="etapa-circulo__revertir-form"
                                  onsubmit="return confirm('Revertir la etapa &quot;<?= htmlspecialchars($etapa['nombre'], ENT_QUOTES); ?>&quot; para el RUT <?= htmlspecialchars($e['rutEmisor'], ENT_QUOTES); ?>? Esto es una correccion administrativa, NO algo rutinario.');">
                                <?= csrfInput(); ?>
                                <input type="hidden" name="rut_emisor" value="<?= htmlspecialchars($e['rutEmisor']); ?>">
                                <input type="hidden" name="campo" value="<?= htmlspecialchars($campoRevertible); ?>">
                                <button type="submit" class="etapa-circulo__revertir" title="Revertir <?= htmlspecialchars($etapa['nombre']); ?>">&times;</button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </td>
            <td style="font-size:0.85em;">
                <?php if ($fila['emisores'] === []): ?>
                &mdash;
                <?php else: ?>
                    <?php foreach ($fila['emisores'] as $e): ?>
                    <div>
                        Cert: <?= $e['tieneCertProduccion'] ? '&#10003;' : '&mdash;'; ?>
                        &nbsp;CAF: <?= $e['tieneCafProduccion'] ? '&#10003;' : '&mdash;'; ?>
                        &nbsp;API key: <?= $fila['tieneApiKeyProduccion'] ? '&#10003;' : '&mdash;'; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($c['estado'] === 'activa'): ?>
                <form method="post" action="/admin/tenants/suspender" style="margin:0;" onsubmit="return confirm('Suspender esta cuenta?');">
                    <?= csrfInput(); ?>
                    <input type="hidden" name="cuenta_id" value="<?= (int) $c['id']; ?>">
                    <button type="submit">Suspender</button>
                </form>
                <?php else: ?>
                <form method="post" action="/admin/tenants/reactivar" style="margin:0;" onsubmit="return confirm('Reactivar esta cuenta?');">
                    <?= csrfInput(); ?>
                    <input type="hidden" name="cuenta_id" value="<?= (int) $c['id']; ?>">
                    <button type="submit">Reactivar</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
