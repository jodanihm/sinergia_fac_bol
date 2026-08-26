<?php
/**
 * Cuentas del SaaS (GET /admin/tenants).
 *
 * Los datos que recibe, la barra de 6 etapas y el mapa de campos revertibles
 * vienen de handleAdminTenantsGet(); suspender, reactivar y revertir etapa no
 * se han tocado desde que se paso al layout del panel de control.
 *
 * LA COLUMNA "TIPO" ES LA UNICA QUE NO DESCRIBE EL ESTADO TECNICO DE LA CUENTA
 * sino su relacion comercial: si paga, si esta evaluando, o si es de la casa.
 * Va segunda, pegada al estado, porque las dos juntas son el resumen que se
 * viene a buscar aqui -- "activa" no dice nada sobre si es un cliente. Su tag
 * se pinta rojo cuando dice "Sin definir": eso es trabajo pendiente, no un
 * estado de reposo.
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
    Todas las cuentas del SaaS, no solo la tuya. Solo lectura, salvo cambiar el tipo de cuenta,
    suspender o reactivar una, y revertir una etapa confirmada por error. Los tres cambios quedan
    en <a href="/admin/auditoria">Auditoria</a>.
</p>

<?php if ($flash !== null): ?>
    <?php if ($flash['tipo'] === 'error'): ?>
    <p class="error"><?= htmlspecialchars($flash['mensaje']); ?></p>
    <?php else: ?>
    <p class="msg-ok"><?= htmlspecialchars($flash['mensaje']); ?></p>
    <?php endif; ?>
<?php endif; ?>

<?php
    // Filtros. Formulario GET y no POST: asi el resultado queda en la URL y se
    // puede guardar, compartir o recargar. Un POST aqui obligaria ademas a
    // pasar por el CSRF central, que existe para las MUTACIONES; buscar no
    // muta nada.
    $hayFiltro = $busqueda !== '' || $estado !== '' || $tipo !== '';

    // Cuantas cuentas hay de cada tipo. Se dibuja siempre que haya alguna
    // cuenta, incluso con el filtro puesto, porque son las cifras de la cartera
    // ENTERA: el handler las cuenta sin filtros justamente para eso.
    $comerciales = 0;
    foreach (TipoCuenta::comerciales() as $claveComercial) {
        $comerciales += $porTipo[$claveComercial] ?? 0;
    }
?>

<?php if ($totalCuentas > 0): ?>
<div class="chips" style="margin-bottom:1rem;">
    <?php foreach (TipoCuenta::catalogo() as $claveTipo => [$etiquetaTipo, $claseTipo, $ayudaTipo]): ?>
    <?php if (($porTipo[$claveTipo] ?? 0) === 0) { continue; } ?>
    <a class="<?= htmlspecialchars($claseTipo); ?>" title="<?= htmlspecialchars($ayudaTipo); ?>"
       style="text-decoration:none;"
       href="/admin/tenants?tipo=<?= urlencode($claveTipo); ?>"><?= (int) $porTipo[$claveTipo]; ?> <?= htmlspecialchars($etiquetaTipo); ?></a>
    <?php endforeach; ?>
    <span class="muted" style="font-size:.85rem;">
        <?php /* La cifra que antes no se podia sacar de ninguna pantalla. */ ?>
        <?= $comerciales; ?> de <?= (int) $totalCuentas; ?> son cuentas comerciales (de pago o en trial).
    </span>
</div>
<?php endif; ?>
<div class="toolbar">
    <a class="btn" href="/admin/tenants/nueva">Nueva cuenta</a>
    <span class="muted">Crea la cuenta y su propietario en un paso, con clave temporal.</span>
</div>

<form class="toolbar" method="get" action="/admin/tenants">
    <input type="search" name="q" value="<?= htmlspecialchars($busqueda); ?>"
           placeholder="Nombre, email o RUT" aria-label="Buscar cuenta" style="max-width:280px;">
    <select name="estado" aria-label="Filtrar por estado" style="max-width:170px;">
        <option value="">Todos los estados</option>
        <option value="activa" <?= $estado === 'activa' ? 'selected' : ''; ?>>Activas</option>
        <option value="suspendida" <?= $estado === 'suspendida' ? 'selected' : ''; ?>>Suspendidas</option>
    </select>
    <select name="tipo" aria-label="Filtrar por tipo de cuenta" style="max-width:190px;">
        <option value="">Todos los tipos</option>
        <?php foreach (TipoCuenta::catalogo() as $claveTipo => [$etiquetaTipo, , ]): ?>
        <option value="<?= htmlspecialchars($claveTipo); ?>" <?= $tipo === $claveTipo ? 'selected' : ''; ?>>
            <?= htmlspecialchars($etiquetaTipo); ?>
        </option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn sm">Buscar</button>
    <?php if ($hayFiltro): ?>
    <a class="btn ghost sm" href="/admin/tenants">Limpiar</a>
    <span class="muted"><?= count($resumen); ?> de <?= (int) $totalCuentas; ?> cuentas</span>
    <?php endif; ?>
</form>

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
<p class="muted" style="margin:0;">
    <?= $hayFiltro ? 'Ninguna cuenta coincide con la busqueda.' : 'No hay cuentas registradas.'; ?>
</p>
<?php else: ?>
<div class="tabla-scroll">
<table>
    <thead>
        <tr>
            <th>Cuenta</th>
            <th>Estado</th>
            <th>Tipo</th>
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
                <a href="/admin/tenants/<?= (int) $c['id']; ?>"><?= htmlspecialchars((string) $c['nombre']); ?></a><br>
                <span class="muted" style="font-size:.85em;"><?= htmlspecialchars((string) $c['email']); ?></span>
            </td>
            <td>
                <span class="tag <?= $c['estado'] === 'activa' ? 'ok' : 'err'; ?>">
                    <?= htmlspecialchars(strtoupper((string) $c['estado'])); ?>
                </span>
            </td>
            <td>
                <?php $tipoActual = (string) $c['tipo']; ?>
                <span class="<?= htmlspecialchars(TipoCuenta::clase($tipoActual)); ?>"
                      title="<?= htmlspecialchars(TipoCuenta::ayuda($tipoActual)); ?>">
                    <?= htmlspecialchars(TipoCuenta::etiqueta($tipoActual)); ?>
                </span>
                <?php /* El cambio pide confirmacion y nombra el valor nuevo: es un dato
                         con consecuencias comerciales, no una preferencia de pantalla. */ ?>
                <form method="post" action="/admin/tenants/tipo" style="margin:.4rem 0 0;display:flex;gap:.3rem;flex-wrap:wrap;"
                      onsubmit="return confirm('Cambiar el tipo de <?= htmlspecialchars((string) $c['nombre'], ENT_QUOTES); ?>? Queda registrado en la auditoria.');">
                    <?= csrfInput(); ?>
                    <input type="hidden" name="cuenta_id" value="<?= (int) $c['id']; ?>">
                    <select name="tipo" aria-label="Tipo de la cuenta <?= htmlspecialchars((string) $c['nombre']); ?>"
                            style="max-width:150px;font-size:.8rem;">
                        <?php foreach (TipoCuenta::catalogo() as $claveTipo => [$etiquetaTipo, , ]): ?>
                        <option value="<?= htmlspecialchars($claveTipo); ?>" <?= $tipoActual === $claveTipo ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($etiquetaTipo); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn ghost sm">Cambiar</button>
                </form>
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
