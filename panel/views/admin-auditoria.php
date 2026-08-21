<?php
/**
 * Auditoria de acciones administrativas (GET /admin/auditoria).
 *
 * Recibe de handleAdminAuditoriaGet(): $filas (con su 'diff' ya calculado),
 * $acciones, $autores, los cuatro filtros vigentes, $total, $pagina y
 * $totalPaginas. 50 por pagina.
 *
 * El diff manda y el JSON crudo queda detras de un <details>, no al reves:
 * la pregunta que trae aqui es "que cambio", y el snapshot completo es la
 * prueba que se mira despues, cuando el diff no alcanza.
 */
$titulo      = 'Auditoria';
$adminActivo = 'auditoria';
require __DIR__ . '/partials/admin/header.php';

$hayFiltro = $accion !== '' || $usuarioId !== '' || $desde !== '' || $hasta !== '';

/**
 * URL de una pagina conservando los filtros vigentes. Sin esto, pasar a la
 * pagina 2 de un resultado filtrado devolveria la pagina 2 de TODO, que es la
 * forma mas silenciosa que tiene un listado de mentir.
 */
$urlPagina = static function (int $n) use ($accion, $usuarioId, $desde, $hasta): string {
    $parametros = array_filter([
        'accion'  => $accion,
        'usuario' => $usuarioId,
        'desde'   => $desde,
        'hasta'   => $hasta,
        'pagina'  => $n > 1 ? (string) $n : '',
    ], static fn (string $v): bool => $v !== '');

    return '/admin/auditoria' . ($parametros === [] ? '' : '?' . http_build_query($parametros));
};
?>

<h2 class="page-title">Auditoria</h2>
<p class="muted">
    Registro de las acciones administrativas sobre las cuentas. Es append-only:
    una fila escrita no se edita ni se borra nunca.
</p>

<form class="toolbar" method="get" action="/admin/auditoria">
    <select name="accion" aria-label="Filtrar por accion" style="max-width:200px;">
        <option value="">Todas las acciones</option>
        <?php foreach ($acciones as $a): ?>
        <option value="<?= htmlspecialchars((string) $a); ?>" <?= $accion === $a ? 'selected' : ''; ?>>
            <?= htmlspecialchars((string) $a); ?>
        </option>
        <?php endforeach; ?>
    </select>
    <select name="usuario" aria-label="Filtrar por usuario" style="max-width:230px;">
        <option value="">Todos los usuarios</option>
        <?php foreach ($autores as $au): ?>
        <?php $idAutor = (string) $au['usuario_id']; ?>
        <option value="<?= htmlspecialchars($idAutor); ?>" <?= $usuarioId === $idAutor ? 'selected' : ''; ?>>
            <?= htmlspecialchars((string) ($au['email'] ?? ('usuario #' . $idAutor))); ?>
        </option>
        <?php endforeach; ?>
    </select>
    <input type="date" name="desde" value="<?= htmlspecialchars($desde); ?>" aria-label="Desde" style="max-width:170px;">
    <input type="date" name="hasta" value="<?= htmlspecialchars($hasta); ?>" aria-label="Hasta" style="max-width:170px;">
    <button type="submit" class="btn sm">Filtrar</button>
    <?php if ($hayFiltro): ?>
    <a class="btn ghost sm" href="/admin/auditoria">Limpiar</a>
    <?php endif; ?>
    <span class="muted">
        <?= (int) $total; ?> accion<?= $total === 1 ? '' : 'es'; ?><?= $hayFiltro ? ' con estos filtros' : ''; ?>
    </span>
</form>

<div class="panel">
<?php if ($filas === []): ?>
<p class="muted" style="margin:0;">
    <?= $hayFiltro ? 'Ninguna accion coincide con estos filtros.' : 'Aun no hay acciones administrativas registradas.'; ?>
</p>
<?php else: ?>
<div class="tabla-scroll">
<table>
    <thead>
        <tr>
            <th>Fecha</th>
            <th>Quien</th>
            <th>Accion</th>
            <th>Entidad</th>
            <th>Que cambio</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($filas as $f): ?>
        <tr>
            <td class="muted" style="white-space:nowrap;"><?= htmlspecialchars((string) $f['created_at']); ?></td>
            <td><?= htmlspecialchars((string) ($f['usuario_email'] ?? ('usuario #' . $f['usuario_id']))); ?></td>
            <td><span class="tag"><?= htmlspecialchars((string) $f['accion']); ?></span></td>
            <td class="muted" style="white-space:nowrap;">
                <?php if ($f['entidad_tipo'] === 'cuenta'): ?>
                <a href="/admin/tenants/<?= (int) $f['entidad_id']; ?>">cuenta #<?= (int) $f['entidad_id']; ?></a>
                <?php else: ?>
                <?= htmlspecialchars((string) $f['entidad_tipo']); ?> #<?= (int) $f['entidad_id']; ?>
                <?php endif; ?>
            </td>
            <td>
                <?php if (! $f['diff']['legible']): ?>
                <span class="tag err">JSON ilegible</span>
                <span class="muted" style="font-size:.8rem;">Se muestra crudo mas abajo.</span>
                <?php elseif ($f['diff']['cambios'] === []): ?>
                <span class="muted">Sin cambios de valor.</span>
                <?php else: ?>
                <ul class="diff">
                    <?php foreach ($f['diff']['cambios'] as $c): ?>
                    <li>
                        <code class="diff__clave"><?= htmlspecialchars($c['clave']); ?></code>
                        <span class="diff__antes"><?= $c['antes'] === null ? '(no estaba)' : htmlspecialchars($c['antes']); ?></span>
                        <span class="diff__flecha">&rarr;</span>
                        <span class="diff__despues"><?= $c['despues'] === null ? '(se quito)' : htmlspecialchars($c['despues']); ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>

                <details class="diff__crudo">
                    <summary>Snapshot completo</summary>
                    <div class="diff__crudo-cols">
                        <div>
                            <div class="muted">Antes</div>
                            <pre><?= htmlspecialchars((string) ($f['valor_anterior'] ?? '(vacio)')); ?></pre>
                        </div>
                        <div>
                            <div class="muted">Despues</div>
                            <pre><?= htmlspecialchars((string) ($f['valor_nuevo'] ?? '(vacio)')); ?></pre>
                        </div>
                    </div>
                </details>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php if ($totalPaginas > 1): ?>
<div class="toolbar" style="margin:1rem 0 0;">
    <?php if ($pagina > 1): ?>
    <a class="btn ghost sm" href="<?= htmlspecialchars($urlPagina($pagina - 1)); ?>">&larr; Anterior</a>
    <?php endif; ?>
    <span class="muted">Pagina <?= (int) $pagina; ?> de <?= (int) $totalPaginas; ?></span>
    <?php if ($pagina < $totalPaginas): ?>
    <a class="btn ghost sm" href="<?= htmlspecialchars($urlPagina($pagina + 1)); ?>">Siguiente &rarr;</a>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>
</div>

<?php require __DIR__ . '/partials/admin/footer.php'; ?>
