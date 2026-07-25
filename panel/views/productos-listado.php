<?php
/**
 * Maestros > Productos y servicios: listado.
 *
 * Recibe: $productos, $q, $incluirInactivos, $pagina, $totalPaginas, $total,
 * $flash, $navActivo. Todo de handleProductosListar(), 25 por pagina.
 *
 * Cada producto trae los campos de MySqlProductoRepository::mapear():
 *   id, codigo, nombre, descripcion, precio_unitario, unidad, exento, activo,
 *   created_at, updated_at.
 * codigo, descripcion, precio_unitario y unidad pueden ser null.
 *
 * FILTROS REALES: los mismos dos parametros que clientes, q e inactivos (mas
 * pagina), pero la busqueda es LIKE sobre codigo Y nombre. Orden: nombre ASC.
 *
 * PRECIO: precio_unitario es un float que puede traer decimales, asi que NO se
 * formatea a cero decimales como los montos ya emitidos del panel de emision --
 * ahi los valores son enteros definitivos, aca es un precio de catalogo. Se
 * muestran hasta 4 decimales sin ceros de relleno, igual que antes, ahora con
 * separador de miles. No se calcula IVA ni se convierte neto a bruto: el precio
 * se guarda tal como lo escribio el usuario y asi viaja al detalle del
 * documento.
 *
 * EXENTO: columna booleana del maestro. Es una caracteristica tributaria del
 * item, no un estado del registro, asi que va con badge de etiqueta y no con
 * color semantico.
 */
$titulo = 'Productos y servicios';
require __DIR__ . '/partials/header.php';

$qs = ($q !== '' ? '&q=' . urlencode($q) : '') . ($incluirInactivos ? '&inactivos=1' : '');

$hayFiltros = $q !== '' || $incluirInactivos;

$oVacio = static function (?string $v): string {
    $v = trim((string) $v);
    return $v === '' ? '<span class="dash-vacio-inline">&mdash;</span>' : htmlspecialchars($v);
};

/**
 * Precio de catalogo. Mantiene el criterio que ya usaba la vista (hasta 4
 * decimales, sin ceros de relleno) y le agrega separador de miles. El punto de
 * los miles no se ve afectado por el rtrim porque la coma decimal lo protege:
 * "2.000,0000" -> "2.000," -> "2.000".
 */
$fmtPrecio = static function ($v): ?string {
    if ($v === null) {
        return null;
    }
    $s = number_format((float) $v, 4, ',', '.');
    return '$ ' . rtrim(rtrim($s, '0'), ',');
};
?>

<div class="dash-header">
    <div>
        <h1>Productos y servicios</h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <a class="boton-principal" href="/maestros/productos/nuevo">Nuevo producto</a>
    </div>
</div>
<p class="dash-subtitulo">
    Administra el catalogo que completa el detalle de los documentos al escribir el nombre del item.
</p>

<?php if (! empty($flash['mensaje'])): ?>
    <p class="alerta alerta--<?= ($flash['tipo'] ?? '') === 'exito' ? 'exito' : 'error'; ?>" role="status">
        <span class="alerta__icono" aria-hidden="true"><?= ($flash['tipo'] ?? '') === 'exito' ? '&#10003;' : '&#9888;'; ?></span>
        <span><?= htmlspecialchars($flash['mensaje']); ?></span>
    </p>
<?php endif; ?>

<form method="get" action="/maestros/productos" class="filtros">
    <label class="filtros__campo">Buscar
        <input type="text" name="q" class="filtros__input" value="<?= htmlspecialchars($q); ?>"
               placeholder="Codigo o nombre">
    </label>
    <label class="filtros__campo form-check">
        <input type="checkbox" name="inactivos" value="1" <?= $incluirInactivos ? 'checked' : ''; ?>>
        Incluir inactivos
    </label>
    <button type="submit" class="boton-secundario">Filtrar</button>
    <?php if ($hayFiltros): ?>
        <a class="boton-texto" href="/maestros/productos">Limpiar filtros</a>
    <?php endif; ?>
</form>

<?php if ($productos === []): ?>
    <div class="estado-vacio">
        <?php if ($q !== ''): ?>
            <h2>Sin resultados</h2>
            <p>Ningun item coincide con "<?= htmlspecialchars($q); ?>". La busqueda mira el codigo y el nombre.</p>
            <p class="estado-vacio__acciones">
                <a class="boton-principal" href="/maestros/productos">Limpiar filtros</a>
            </p>
        <?php else: ?>
            <h2>Aun no tienes productos ni servicios</h2>
            <p>Lo que guardes aqui aparece como sugerencia al escribir el detalle de un documento, con su precio y unidad.</p>
            <p class="estado-vacio__acciones">
                <a class="boton-principal" href="/maestros/productos/nuevo">Nuevo producto</a>
            </p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="tabla-scroll">
        <table class="tabla-datos tabla-productos">
            <caption>
                <?= $total; ?> producto<?= $total === 1 ? '' : 's'; ?> o servicio<?= $total === 1 ? '' : 's'; ?><?= $hayFiltros ? ' con los filtros aplicados' : ''; ?><?php
                if ($totalPaginas > 1): ?> &middot; pagina <?= $pagina; ?> de <?= $totalPaginas; ?><?php endif; ?>
            </caption>
            <thead>
                <tr>
                    <th>Producto o servicio</th>
                    <th class="tabla-datos__num">Precio unitario</th>
                    <th>Unidad</th>
                    <th>IVA</th>
                    <th class="tabla-datos__estado">Estado</th>
                    <th class="tabla-datos__acciones">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($productos as $p): ?>
                    <?php $precio = $fmtPrecio($p['precio_unitario'] ?? null); ?>
                    <tr<?= $p['activo'] ? '' : ' class="tabla-datos__fila--inactiva"'; ?>>
                        <td>
                            <?= htmlspecialchars($p['nombre']); ?>
                            <?php if (trim((string) ($p['codigo'] ?? '')) !== ''): ?>
                                <span class="tabla-datos__secundario"><?= htmlspecialchars((string) $p['codigo']); ?></span>
                            <?php endif; ?>
                            <?php if (trim((string) ($p['descripcion'] ?? '')) !== ''): ?>
                                <span class="tabla-datos__secundario"><?= htmlspecialchars((string) $p['descripcion']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="tabla-datos__num">
                            <?= $precio !== null ? htmlspecialchars($precio) : '<span class="dash-vacio-inline">&mdash;</span>'; ?>
                        </td>
                        <td><?= $oVacio($p['unidad'] ?? null); ?></td>
                        <td><span class="badge badge--etiqueta"><?= $p['exento'] ? 'Exento' : 'Afecto'; ?></span></td>
                        <td class="tabla-datos__estado">
                            <?php if ($p['activo']): ?>
                                <span class="badge badge--ok">Activo</span>
                            <?php else: ?>
                                <span class="badge badge--neutro">Inactivo</span>
                            <?php endif; ?>
                        </td>
                        <td class="tabla-datos__acciones">
                            <a href="/maestros/productos/<?= (int) $p['id']; ?>/editar">Editar</a>
                            <?php if ($p['activo']): ?>
                                <form method="post" action="/maestros/productos/<?= (int) $p['id']; ?>/desactivar" style="display:inline;">
                                    <?= csrfInput(); ?>
                                    <button type="submit" class="boton-texto">Desactivar</button>
                                </form>
                            <?php else: ?>
                                <form method="post" action="/maestros/productos/<?= (int) $p['id']; ?>/activar" style="display:inline;">
                                    <?= csrfInput(); ?>
                                    <button type="submit" class="boton-texto">Activar</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPaginas > 1): ?>
        <p class="paginacion">
            <?php if ($pagina > 1): ?>
                <a class="boton-secundario" href="/maestros/productos?pagina=<?= $pagina - 1; ?><?= $qs; ?>">&larr; Anterior</a>
            <?php endif; ?>
            <span class="nota">Pagina <?= $pagina; ?> de <?= $totalPaginas; ?> (<?= $total; ?> registros)</span>
            <?php if ($pagina < $totalPaginas): ?>
                <a class="boton-secundario" href="/maestros/productos?pagina=<?= $pagina + 1; ?><?= $qs; ?>">Siguiente &rarr;</a>
            <?php endif; ?>
        </p>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
