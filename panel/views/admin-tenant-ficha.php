<?php
/**
 * Ficha de una cuenta (GET /admin/tenants/{id}). SOLO LECTURA: esta vista no
 * dibuja ni un formulario. Suspender, reactivar y revertir etapa siguen
 * viviendo en el listado, que es donde estaban.
 *
 * Cada bloque se dibuja solo si tiene algo que mostrar, salvo los que dicen
 * explicitamente que estan vacios (usuarios, emisores): una cuenta sin
 * usuarios es una anomalia que hay que ver, no un bloque que esconder.
 */
$titulo      = 'Cuenta: ' . $cuenta['nombre'];
$adminActivo = 'cuentas';
require __DIR__ . '/partials/admin/header.php';

/** Un guion cuando el dato no existe, para que la tabla no muestre celdas en blanco. */
$o = static fn ($v): string => ($v === null || $v === '') ? '&mdash;' : htmlspecialchars((string) $v);
?>

<p class="muted" style="margin-top:0;"><a href="/admin/tenants">&larr; Cuentas</a></p>

<div class="toolbar">
    <?php /* Enlace y no formulario: entrar es navegacion, no una mutacion de
             datos del tenant. El confirm() dice que la accion queda registrada,
             porque queda: entrar al panel de un contribuyente deja su fila en la
             auditoria aunque no se toque nada. */ ?>
    <a class="btn sm" href="/admin/tenants/<?= (int) $cuenta['id']; ?>/ver"
       onclick="return confirm('Vas a ver el panel de <?= htmlspecialchars((string) $cuenta['nombre'], ENT_QUOTES); ?> en modo solo lectura. Queda registrado en la auditoria.');">
        Ver como este tenant
    </a>
    <span class="muted">Abre el panel del cliente con sus datos reales, sin poder modificar nada.</span>
</div>

<h2 class="page-title">
    <?= htmlspecialchars((string) $cuenta['nombre']); ?>
    <span class="tag <?= $cuenta['estado'] === 'activa' ? 'ok' : 'err'; ?>">
        <?= htmlspecialchars(strtoupper((string) $cuenta['estado'])); ?>
    </span>
</h2>

<div class="cards">
    <div class="stat">
        <div class="n">#<?= (int) $cuenta['id']; ?></div>
        <div class="l">Id de cuenta</div>
    </div>
    <div class="stat">
        <div class="n" style="font-size:1rem;"><?= htmlspecialchars((string) $cuenta['email']); ?></div>
        <div class="l">Email de contacto</div>
    </div>
    <div class="stat">
        <div class="n" style="font-size:1rem;"><?= htmlspecialchars((string) $cuenta['created_at']); ?></div>
        <div class="l">Fecha de alta</div>
    </div>
    <div class="stat">
        <div class="n" style="font-size:1rem;color:<?= $puedeEmitir['falta'] === null ? 'var(--ok)' : 'var(--pk)'; ?>;">
            <?= $puedeEmitir['falta'] === null ? 'Si' : 'Falta ' . htmlspecialchars((string) $puedeEmitir['falta']); ?>
        </div>
        <div class="l">Puede emitir en produccion</div>
    </div>
</div>

<div class="panel" style="margin-top:1.5rem;">
    <h3>Usuarios</h3>
    <?php if ($usuarios === []): ?>
    <p class="muted" style="margin:0;">Esta cuenta no tiene usuarios.</p>
    <?php else: ?>
    <div class="tabla-scroll">
    <table>
        <thead>
            <tr><th>Email</th><th>Tipo</th><th>Rol asignado</th><th>Estado</th><th>Demo</th><th>Alta</th></tr>
        </thead>
        <tbody>
        <?php foreach ($usuarios as $u): ?>
            <tr>
                <td><?= htmlspecialchars((string) $u['email']); ?></td>
                <td><span class="tag"><?= htmlspecialchars((string) $u['rol']); ?></span></td>
                <td><?= $o($u['rol_nombre']); ?></td>
                <td>
                    <span class="tag <?= $u['estado'] === 'activo' ? 'ok' : 'err'; ?>">
                        <?= htmlspecialchars((string) $u['estado']); ?>
                    </span>
                </td>
                <td><?= ((int) $u['demo']) === 1 ? '<span class="tag warn">demo</span>' : '&mdash;'; ?></td>
                <td class="muted"><?= $o($u['created_at']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="muted" style="margin-bottom:0;font-size:.82rem;">
        "Tipo" es <code>usuario.rol</code>: que ES el usuario, y owner y superadmin se saltan el gate
        de permisos entero. "Rol asignado" es <code>usuario.rol_id</code>: que PUEDE HACER un
        colaborador. Un owner no necesita rol asignado, por eso la columna va vacia.
    </p>
    <?php endif; ?>
</div>

<?php if ($roles !== []): ?>
<div class="panel">
    <h3>Roles de la cuenta</h3>
    <div class="tabla-scroll">
    <table>
        <thead><tr><th>Rol</th><th>Permisos</th><th>Usuarios</th><th>Creado</th></tr></thead>
        <tbody>
        <?php foreach ($roles as $r): ?>
            <tr>
                <td><?= htmlspecialchars((string) $r['nombre']); ?></td>
                <td><?= (int) $r['permisos']; ?></td>
                <td><?= (int) $r['usuarios']; ?></td>
                <td class="muted"><?= $o($r['created_at']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<div class="panel">
    <h3>Emisores</h3>
    <?php if ($emisores === []): ?>
    <p class="muted" style="margin:0;">Esta cuenta todavia no cargo ninguna empresa emisora.</p>
    <?php else: ?>
    <div class="tabla-scroll">
    <table>
        <thead>
            <tr><th>RUT</th><th>Ambiente</th><th>Razon social</th><th>Resolucion</th><th>Etapas de certificacion</th></tr>
        </thead>
        <tbody>
        <?php foreach ($emisores as $e): ?>
            <tr>
                <td><?= htmlspecialchars((string) $e['rut_emisor']); ?></td>
                <td>
                    <span class="tag <?= $e['ambiente'] === 'produccion' ? 'warn' : ''; ?>">
                        <?= htmlspecialchars((string) $e['ambiente']); ?>
                    </span>
                </td>
                <td>
                    <?= htmlspecialchars((string) $e['razon_social']); ?><br>
                    <span class="muted" style="font-size:.85em;">
                        <?= $o($e['giro']); ?> &middot; <?= $o($e['cmna_origen']); ?>
                    </span>
                </td>
                <td class="muted">
                    <?= $o($e['resolucion_numero']); ?><br>
                    <span style="font-size:.85em;"><?= $o($e['resolucion_fecha']); ?></span>
                </td>
                <td>
                    <?php if ($e['resumen'] === null): ?>
                    <span class="muted">&mdash;</span>
                    <?php else: ?>
                    <div class="progreso-etapas--admin">
                        <?php foreach ($e['resumen']['barra'] as $i => $etapa): ?>
                        <div class="etapa-circulo <?= $etapa['clase']; ?>" title="<?= htmlspecialchars($etapa['nombre']); ?>"><?= $i + 1; ?></div>
                        <?php endforeach; ?>
                    </div>
                    <span class="muted" style="font-size:.8em;">
                        Cert. produccion: <?= $e['resumen']['tieneCertProduccion'] ? 'si' : 'no'; ?> &middot;
                        CAF produccion: <?= $e['resumen']['tieneCafProduccion'] ? 'si' : 'no'; ?>
                    </span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php if ($certificados !== []): ?>
<div class="panel">
    <h3>Certificados digitales</h3>
    <div class="tabla-scroll">
    <table>
        <thead><tr><th>RUT emisor</th><th>Ambiente</th><th>RUT del titular</th><th>Cargado</th></tr></thead>
        <tbody>
        <?php foreach ($certificados as $c): ?>
            <tr>
                <td><?= htmlspecialchars((string) $c['rut_emisor']); ?></td>
                <td><span class="tag"><?= htmlspecialchars((string) $c['ambiente']); ?></span></td>
                <td><?= $o($c['rut_sender']); ?></td>
                <td class="muted"><?= $o($c['created_at']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="muted" style="margin-bottom:0;font-size:.82rem;">
        El contenido del certificado y su clave estan cifrados y no se muestran nunca.
        La fecha de vencimiento vive dentro del propio certificado, asi que no se puede
        listar aqui sin descifrarlo.
    </p>
</div>
<?php endif; ?>

<?php if ($folios !== []): ?>
<div class="panel">
    <h3>Folios (CAF)</h3>
    <div class="tabla-scroll">
    <table>
        <thead>
            <tr>
                <th>RUT emisor</th><th>Ambiente</th><th>Documento</th><th>Rango</th>
                <th>Proximo folio</th><th>Consumidos</th><th>Disponibles</th><th>CAF</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($folios as $f): ?>
            <?php
                // Mismo criterio que dashFoliosPorTipo(): un CAF agotado o con
                // el proximo folio pasado del tope ya no entrega nada.
                $agotado     = $f['estado'] === 'agotado' || (int) $f['proximo_folio'] > (int) $f['folio_hasta'];
                $disponibles = $agotado ? 0 : (int) $f['folio_hasta'] - (int) $f['proximo_folio'] + 1;
                $consumidos  = max((int) $f['proximo_folio'] - (int) $f['proximo_folio_inicial'], 0);
            ?>
            <tr>
                <td><?= htmlspecialchars((string) $f['rut_emisor']); ?></td>
                <td><span class="tag <?= $f['ambiente'] === 'produccion' ? 'warn' : ''; ?>"><?= htmlspecialchars((string) $f['ambiente']); ?></span></td>
                <td><?= htmlspecialchars(nombreTipoDte((int) $f['tipo_dte'])); ?></td>
                <td class="muted"><?= (int) $f['folio_desde']; ?> &ndash; <?= (int) $f['folio_hasta']; ?></td>
                <td><?= (int) $f['proximo_folio']; ?></td>
                <td><?= $consumidos; ?></td>
                <td><?= $disponibles === 0 ? '<span class="tag err">0</span>' : $disponibles; ?></td>
                <td><span class="tag <?= $f['estado'] === 'activo' ? 'ok' : 'err'; ?>"><?= htmlspecialchars((string) $f['estado']); ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<div class="panel">
    <h3>API keys</h3>
    <?php if ($apiKeys === []): ?>
    <p class="muted" style="margin:0;">Esta cuenta no tiene credenciales de API.</p>
    <?php else: ?>
    <div class="tabla-scroll">
    <table>
        <thead>
            <tr><th>Prefijo</th><th>Ambiente</th><th>Tipo</th><th>Alcance</th><th>Estado</th><th>Ultimo uso</th><th>Creada</th></tr>
        </thead>
        <tbody>
        <?php foreach ($apiKeys as $k): ?>
            <tr>
                <td><code><?= htmlspecialchars((string) $k['prefijo']); ?>&hellip;</code></td>
                <td><span class="tag <?= $k['ambiente'] === 'produccion' ? 'warn' : ''; ?>"><?= htmlspecialchars((string) $k['ambiente']); ?></span></td>
                <td><?= htmlspecialchars((string) $k['tipo']); ?></td>
                <td class="muted"><?= $o($k['rut_emisor_scope']); ?></td>
                <td><span class="tag <?= $k['estado'] === 'activa' ? 'ok' : 'err'; ?>"><?= htmlspecialchars((string) $k['estado']); ?></span></td>
                <td class="muted"><?= $o($k['last_used_at']); ?></td>
                <td class="muted"><?= $o($k['created_at']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="muted" style="margin-bottom:0;font-size:.82rem;">
        Solo el prefijo: la clave se guarda cifrada y con hash, y no es recuperable desde aqui.
        Las keys de tipo <code>servicio</code> las genera el propio panel para hablarle al motor;
        el cliente no las ve ni las crea.
    </p>
    <?php endif; ?>
</div>

<?php if ($porMes !== []): ?>
<div class="panel">
    <h3>Documentos emitidos por mes</h3>
    <div class="tabla-scroll">
    <table>
        <thead><tr><th>Mes</th><th>Ambiente</th><th>Documentos</th></tr></thead>
        <tbody>
        <?php foreach ($porMes as $m): ?>
            <tr>
                <td><?= htmlspecialchars((string) $m['mes']); ?></td>
                <td><span class="tag <?= $m['ambiente'] === 'produccion' ? 'warn' : ''; ?>"><?= htmlspecialchars((string) $m['ambiente']); ?></span></td>
                <td><?= (int) $m['n']; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="muted" style="margin-bottom:0;font-size:.82rem;">Ultimos 12 meses. Los meses sin documentos no aparecen.</p>
</div>
<?php endif; ?>

<?php if ($documentos !== []): ?>
<div class="panel">
    <h3>Ultimos documentos</h3>
    <div class="tabla-scroll">
    <table>
        <thead>
            <tr><th>Emitido</th><th>RUT emisor</th><th>Ambiente</th><th>Documento</th><th>Folio</th><th>Fecha</th><th>Total</th><th>Estado</th></tr>
        </thead>
        <tbody>
        <?php foreach ($documentos as $d): ?>
            <tr>
                <td class="muted"><?= $o($d['created_at']); ?></td>
                <td><?= htmlspecialchars((string) $d['rut_emisor']); ?></td>
                <td><span class="tag <?= $d['ambiente'] === 'produccion' ? 'warn' : ''; ?>"><?= htmlspecialchars((string) $d['ambiente']); ?></span></td>
                <td><?= htmlspecialchars(nombreTipoDte((int) $d['tipo_dte'])); ?></td>
                <td><?= (int) $d['folio']; ?></td>
                <td class="muted"><?= $o($d['fecha_emision']); ?></td>
                <td><?= number_format((int) $d['total'], 0, ',', '.'); ?></td>
                <td><?= $o($d['estado']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<div class="panel">
    <h3>Acciones administrativas sobre esta cuenta</h3>
    <?php if ($auditoria === []): ?>
    <p class="muted" style="margin:0;">Nunca se ejecuto una accion administrativa sobre esta cuenta.</p>
    <?php else: ?>
    <div class="tabla-scroll">
    <table>
        <thead><tr><th>Fecha</th><th>Quien</th><th>Accion</th><th>Entidad</th><th>Cambio</th></tr></thead>
        <tbody>
        <?php foreach ($auditoria as $a): ?>
            <tr>
                <td class="muted"><?= $o($a['created_at']); ?></td>
                <td><?= htmlspecialchars((string) ($a['usuario_email'] ?? ('usuario #' . $a['usuario_id']))); ?></td>
                <td><?= htmlspecialchars((string) $a['accion']); ?></td>
                <td class="muted"><?= htmlspecialchars((string) $a['entidad_tipo']); ?> #<?= (int) $a['entidad_id']; ?></td>
                <td>
                    <details>
                        <summary class="muted" style="cursor:pointer;font-size:.82rem;">Ver</summary>
                        <pre style="white-space:pre-wrap;margin:.4rem 0 0;font-size:.78rem;"><?= htmlspecialchars((string) ($a['valor_anterior'] ?? '')); ?>
&rarr;
<?= htmlspecialchars((string) ($a['valor_nuevo'] ?? '')); ?></pre>
                    </details>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/admin/footer.php'; ?>
