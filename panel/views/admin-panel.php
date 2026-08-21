<?php
/**
 * Portada del panel de control (GET /admin). Solo lectura.
 *
 * El bloque de alertas se dibuja SOLO si hay algo que decir. Un panel que
 * siempre muestra un recuadro "Alertas: ninguna" entrena a no mirarlo; si el
 * recuadro aparece, aparecio por algo.
 */
$titulo      = 'Panel de control';
$adminActivo = 'panel';
require __DIR__ . '/partials/admin/header.php';
?>

<h2 class="page-title">Panel de control</h2>

<div class="cards">
    <div class="stat">
        <div class="n"><?= (int) $totalCuentas; ?></div>
        <div class="l">Cuentas</div>
    </div>
    <div class="stat">
        <div class="n"><?= (int) $activas; ?></div>
        <div class="l">Activas<?= $suspendidas > 0 ? ' (' . (int) $suspendidas . ' suspendidas)' : ''; ?></div>
    </div>
    <div class="stat">
        <div class="n"><?= (int) $puedenEmitir; ?></div>
        <div class="l">Emiten en produccion</div>
    </div>
    <div class="stat">
        <div class="n"><?= (int) $dteMes; ?></div>
        <div class="l">Documentos, ultimos 30 dias</div>
    </div>
    <div class="stat">
        <div class="n"><?= (int) $usuariosActivos; ?></div>
        <div class="l">Usuarios activos</div>
    </div>
    <div class="stat">
        <div class="n"><?= $ultimoCambio !== null ? htmlspecialchars((string) $ultimoCambio['version']) : '&mdash;'; ?></div>
        <div class="l">Version actual</div>
    </div>
</div>

<?php if ($alertaFolios !== [] || $alertaCorreos !== []): ?>
<div class="panel" style="margin-top:1.5rem;border-color:var(--pk);">
    <h3>Alertas</h3>

    <?php if ($alertaFolios !== []): ?>
    <p class="muted">Folios de produccion por agotarse:</p>
    <div class="tabla-scroll">
    <table>
        <thead>
            <tr><th>Cuenta</th><th>Documento</th><th>Folios disponibles</th><th>Nivel</th></tr>
        </thead>
        <tbody>
        <?php foreach ($alertaFolios as $a): ?>
            <tr>
                <td><a href="/admin/tenants"><?= htmlspecialchars($a['cuenta']); ?></a></td>
                <td><?= htmlspecialchars($a['tipo']); ?></td>
                <td><?= (int) $a['disponibles']; ?></td>
                <td><span class="tag <?= $a['nivel'] === 'rojo' ? 'err' : 'warn'; ?>"><?= htmlspecialchars($a['nivel']); ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <?php if ($alertaCorreos !== []): ?>
    <p class="muted" style="margin-top:1rem;">Correos que quedaron en error:</p>
    <div class="tabla-scroll">
    <table>
        <thead>
            <tr><th>Cuenta</th><th>Correos fallidos</th></tr>
        </thead>
        <tbody>
        <?php foreach ($alertaCorreos as $a): ?>
            <tr>
                <td><a href="/admin/tenants"><?= htmlspecialchars((string) $a['nombre']); ?></a></td>
                <td><?= (int) $a['fallidos']; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="panel" style="margin-top:1.5rem;">
    <h3>Accesos</h3>
    <div class="actions">
        <a class="btn sm" href="/admin/tenants">Cuentas</a>
        <a class="btn sm" href="/admin/base-datos">Base de datos</a>
        <a class="btn sm" href="/admin/changelog">Changelog</a>
    </div>
</div>

<?php if ($ultimoCambio !== null): ?>
<div class="panel">
    <h3>Ultimo cambio</h3>
    <p class="muted">
        <b style="color:var(--accent);">v<?= htmlspecialchars((string) $ultimoCambio['version']); ?></b>
        &middot; <?= htmlspecialchars((string) $ultimoCambio['titulo']); ?>
        (<?= htmlspecialchars((string) $ultimoCambio['fecha']); ?>)
        <span class="cl-tag"><?= htmlspecialchars((string) $ultimoCambio['tag']); ?></span>
    </p>
    <ul>
        <?php foreach ($ultimoCambio['items'] as $item): ?>
        <li><?= htmlspecialchars((string) $item); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="panel">
    <h3>Ultimas acciones administrativas</h3>
    <?php if ($ultimasAcciones === []): ?>
    <p class="muted">Aun no hay acciones administrativas registradas.</p>
    <?php else: ?>
    <div class="tabla-scroll">
    <table>
        <thead>
            <tr><th>Fecha</th><th>Quien</th><th>Accion</th><th>Entidad</th></tr>
        </thead>
        <tbody>
        <?php foreach ($ultimasAcciones as $a): ?>
            <tr>
                <td><?= htmlspecialchars((string) $a['created_at']); ?></td>
                <td><?= htmlspecialchars((string) ($a['usuario_email'] ?? ('usuario #' . $a['usuario_id']))); ?></td>
                <td><?= htmlspecialchars((string) $a['accion']); ?></td>
                <td><?= htmlspecialchars((string) $a['entidad_tipo']); ?> #<?= (int) $a['entidad_id']; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p style="margin-bottom:0;"><a href="/admin/auditoria">Ver la auditoria completa &rarr;</a></p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/admin/footer.php'; ?>
