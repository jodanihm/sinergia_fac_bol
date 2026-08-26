<?php
/**
 * Bitacora del panel de control (GET /admin/actividad).
 *
 * Recibe de handleAdminActividadGet(): $filas, $usuarios, los cinco filtros
 * vigentes, $total, $resumen, $pagina y $totalPaginas. 50 por pagina.
 *
 * NO ES LA PANTALLA DE AUDITORIA Y NO LA REPITE. Alla se responde QUE CAMBIO,
 * con el antes y el despues de cada accion administrativa. Aqui se responde QUE
 * SE HIZO: cada pantalla que se abrio, cuando, desde donde y como termino. Las
 * lecturas son la mitad que aquella no puede tener, y en este panel no son
 * inofensivas: abrir la ficha de una cuenta es ver los datos de un
 * contribuyente ajeno. El parrafo de arriba lo dice en la pantalla, porque dos
 * items de menu que suenan parecido se confunden siempre.
 *
 * EL METODO Y LA RUTA, NUNCA EL CUERPO NI LA RESPUESTA. Por el cuerpo viajan
 * las claves de POST /admin/login; por la respuesta, los datos que esta
 * bitacora existe para proteger.
 */
$titulo      = 'Actividad del panel';
$adminActivo = 'actividad';
require __DIR__ . '/partials/admin/header.php';

$hayFiltro = $efecto !== '' || $usuarioId !== '' || $rutaFiltro !== '' || $desde !== '' || $hasta !== '';

/**
 * URL de una pagina conservando los filtros vigentes. Sin esto, pasar a la
 * pagina 2 de un resultado filtrado devolveria la pagina 2 de TODO, que es la
 * forma mas silenciosa que tiene un listado de mentir.
 */
$urlPagina = static function (int $n) use ($efecto, $usuarioId, $rutaFiltro, $desde, $hasta): string {
    $parametros = array_filter([
        'efecto'  => $efecto,
        'usuario' => $usuarioId,
        'ruta'    => $rutaFiltro,
        'desde'   => $desde,
        'hasta'   => $hasta,
        'pagina'  => $n > 1 ? (string) $n : '',
    ], static fn (string $v): bool => $v !== '');

    return '/admin/actividad' . ($parametros === [] ? '' : '?' . http_build_query($parametros));
};
?>

<h2 class="page-title">Actividad del panel</h2>
<p class="muted">
    Una fila por cada peticion al panel de control: quien, que pantalla, cuando, desde que IP y
    como termino. Incluye las <strong>lecturas</strong>, que es lo que no puede tener
    <a href="/admin/auditoria">Auditoria</a> &mdash; aquella responde <em>que cambio</em>, con el
    antes y el despues; esta responde <em>que se hizo</em>. En este panel mirar tampoco es
    inofensivo: abrir la ficha de una cuenta es ver los datos de un contribuyente. Es append-only:
    una fila escrita no se edita ni se borra, y esta pantalla no tiene con que hacerlo.
</p>

<?php if (($resumen['total'] ?? 0) > 0): ?>
<div class="cards">
    <div class="stat" title="Todo lo registrado desde que existe la bitacora.">
        <div class="n"><?= (int) $resumen['total']; ?></div>
        <div class="l">Registradas<?php if (($resumen['desde_cuando'] ?? null) !== null): ?>
            <span style="display:block;font-size:.78em;">desde el <?= htmlspecialchars(date('d-m-Y', strtotime((string) $resumen['desde_cuando']))); ?></span>
        <?php endif; ?></div>
    </div>
    <div class="stat" title="Peticiones de hoy.">
        <div class="n"><?= (int) ($resumen['hoy'] ?? 0); ?></div>
        <div class="l">Hoy</div>
    </div>
    <div class="stat" title="Las que pueden haber cambiado algo (POST). El detalle de que cambio esta en Auditoria.">
        <div class="n"><?= (int) ($resumen['acciones'] ?? 0); ?></div>
        <div class="l">Acciones</div>
    </div>
    <div class="stat" title="Terminaron en 4xx o 5xx: un intento rechazado o una pantalla que fallo.">
        <div class="n" style="<?= ((int) ($resumen['rechazadas'] ?? 0)) > 0 ? 'color:var(--pk);' : ''; ?>">
            <?= (int) ($resumen['rechazadas'] ?? 0); ?>
        </div>
        <div class="l">Rechazadas o con error</div>
    </div>
</div>
<?php endif; ?>

<form class="toolbar" method="get" action="/admin/actividad">
    <select name="efecto" aria-label="Filtrar por tipo" style="max-width:190px;">
        <option value="">Lecturas y acciones</option>
        <option value="accion" <?= $efecto === 'accion' ? 'selected' : ''; ?>>Solo acciones</option>
        <option value="lectura" <?= $efecto === 'lectura' ? 'selected' : ''; ?>>Solo lecturas</option>
    </select>
    <select name="usuario" aria-label="Filtrar por usuario" style="max-width:230px;">
        <option value="">Todos los usuarios</option>
        <?php foreach ($usuarios as $u): ?>
        <?php $idU = (string) $u['usuario_id']; ?>
        <option value="<?= htmlspecialchars($idU); ?>" <?= $usuarioId === $idU ? 'selected' : ''; ?>>
            <?= htmlspecialchars((string) ($u['email'] ?? ($idU === '' ? 'sin sesion' : 'usuario #' . $idU))); ?>
        </option>
        <?php endforeach; ?>
    </select>
    <input type="search" name="ruta" value="<?= htmlspecialchars($rutaFiltro); ?>"
           placeholder="Pantalla, ej: tenants" aria-label="Filtrar por pantalla" style="max-width:210px;">
    <input type="date" name="desde" value="<?= htmlspecialchars($desde); ?>" aria-label="Desde" style="max-width:170px;">
    <input type="date" name="hasta" value="<?= htmlspecialchars($hasta); ?>" aria-label="Hasta" style="max-width:170px;">
    <button type="submit" class="btn sm">Filtrar</button>
    <?php if ($hayFiltro): ?>
    <a class="btn ghost sm" href="/admin/actividad">Limpiar</a>
    <?php endif; ?>
    <span class="muted">
        <?= (int) $total; ?> peticion<?= $total === 1 ? '' : 'es'; ?><?= $hayFiltro ? ' con estos filtros' : ''; ?>
    </span>
</form>

<div class="panel">
<?php if ($filas === []): ?>
<p class="muted" style="margin:0;">
    <?= $hayFiltro
        ? 'Ninguna peticion coincide con estos filtros.'
        : 'Todavia no hay nada registrado. La bitacora empieza a llenarse con la proxima pantalla que se abra.'; ?>
</p>
<?php else: ?>
<div class="tabla-scroll">
<table>
    <thead>
        <tr>
            <th>Cuando</th><th>Quien</th><th>Que</th><th>Tipo</th><th>Resultado</th><th>Desde</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($filas as $f): ?>
        <tr>
            <td style="white-space:nowrap;"><?= htmlspecialchars(date('d-m-Y H:i:s', strtotime((string) $f['created_at']))); ?></td>
            <td>
                <?php if (($f['usuario_email'] ?? null) !== null): ?>
                <?= htmlspecialchars((string) $f['usuario_email']); ?>
                <?php elseif (($f['usuario_id'] ?? null) !== null): ?>
                <span class="muted">usuario #<?= (int) $f['usuario_id']; ?></span>
                <?php else: ?>
                <?php /* Sin sesion: solo puede pasar en un ingreso fallido a /admin/login,
                         y es justamente el evento que mas interesa de esa ruta. */ ?>
                <span class="tag warn">sin sesion</span>
                <?php endif; ?>
            </td>
            <td>
                <code style="font-size:.82em;"><?= htmlspecialchars((string) $f['metodo']); ?> <?= htmlspecialchars((string) $f['ruta']); ?></code>
                <?php if ((string) $f['parametros'] !== ''): ?>
                <div class="muted" style="font-size:.76rem;word-break:break-all;"><?= htmlspecialchars((string) $f['parametros']); ?></div>
                <?php endif; ?>
            </td>
            <td>
                <span class="tag<?= $f['efecto'] === 'accion' ? ' warn' : ''; ?>">
                    <?= $f['efecto'] === 'accion' ? 'Accion' : 'Lectura'; ?>
                </span>
            </td>
            <td style="white-space:nowrap;">
                <span class="tag <?= htmlspecialchars(ActividadAdmin::claseHttp((int) $f['http'])); ?>">
                    <?= (int) $f['http']; ?>
                </span>
                <span class="muted" style="font-size:.78rem;"><?= (int) $f['ms']; ?> ms</span>
            </td>
            <td class="muted" style="font-size:.8rem;white-space:nowrap;">
                <?= $f['ip'] === null ? '&mdash;' : htmlspecialchars((string) $f['ip']); ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php if ($totalPaginas > 1): ?>
<div class="chips" style="margin-top:1rem;align-items:center;">
    <?php if ($pagina > 1): ?>
    <a class="btn ghost sm" href="<?= htmlspecialchars($urlPagina($pagina - 1)); ?>">Anteriores</a>
    <?php endif; ?>
    <span class="muted">Pagina <?= (int) $pagina; ?> de <?= (int) $totalPaginas; ?></span>
    <?php if ($pagina < $totalPaginas): ?>
    <a class="btn ghost sm" href="<?= htmlspecialchars($urlPagina($pagina + 1)); ?>">Siguientes</a>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>
</div>

<p class="muted" style="font-size:.82rem;">
    Se registra el metodo y la pantalla, nunca lo que se escribio en un formulario ni lo que se
    mostro en la respuesta. Los parametros de la URL se guardan con el valor oculto si la clave
    parece un secreto. Abrir esta misma pantalla tambien queda anotado: consultar una auditoria
    es un acto, y una bitacora con un agujero del tamano de quien la lee no sirve para lo que
    existe.
</p>

<?php require __DIR__ . '/partials/admin/footer.php'; ?>
