<?php
/**
 * Cuentas del SaaS (GET /admin/tenants).
 *
 * SOLO CAMBIO DE PRESENTACION respecto de la version anterior: pasa del layout
 * del tenant al del panel de control. Los datos que recibe, la barra de 6
 * etapas, el mapa de campos revertibles y los tres formularios POST son
 * IDENTICOS -- handleAdminTenantsGet() y los handlers de suspender, reactivar
 * y revertir no se tocaron.
 *
 * "Cuentas" y no "Tenants" en la interfaz: es como se llama la tabla, y es la
 * palabra que usa quien atiende el telefono.
 */
$titulo      = 'Cuentas';
$adminActivo = 'cuentas';
require __DIR__ . '/partials/admin/header.php';
?>

<h2 class="page-title">Cuentas</h2>
<p class="muted">
    Todas las cuentas del SaaS, no solo la tuya. Solo lectura, salvo suspender
    o reactivar una cuenta y revertir una etapa confirmada por error.
</p>

<?php if ($flash !== null): ?>
    <?php if ($flash['tipo'] === 'error'): ?>
    <p class="error"><?= htmlspecialchars($flash['mensaje']); ?></p>
    <?php else: ?>
    <p class="msg-ok"><?= htmlspecialchars($flash['mensaje']); ?></p>
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

<div class="panel">
<?php if ($resumen === []): ?>
<p class="muted" style="margin:0;">No hay cuentas registradas.</p>
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
                <span class="muted" style="font-size:.85em;"><?= htmlspecialchars((string) $c['email']); ?></span>
            </td>
            <td>
                <span class="tag <?= $c['estado'] === 'activa' ? 'ok' : 'err'; ?>">
                    <?= htmlspecialchars(strtoupper((string) $c['estado'])); ?>
                </span>
            </td>
            <td>
                <?php if ($fila['emisores'] === []): ?>
                <span class="muted">(sin emisor)</span>
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
            <td style="font-size:.85em;">
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
                    <button type="submit" class="btn ghost sm">Suspender</button>
                </form>
                <?php else: ?>
                <form method="post" action="/admin/tenants/reactivar" style="margin:0;" onsubmit="return confirm('Reactivar esta cuenta?');">
                    <?= csrfInput(); ?>
                    <input type="hidden" name="cuenta_id" value="<?= (int) $c['id']; ?>">
                    <button type="submit" class="btn sm">Reactivar</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
</div>

<?php require __DIR__ . '/partials/admin/footer.php'; ?>
