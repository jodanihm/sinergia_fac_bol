<?php
/**
 * Listado del maestro de proveedores.
 *
 * Recibe: $proveedores, $q, $incluirInactivos, $pagina, $totalPaginas, $total,
 * $flash, $navActivo.
 *
 * SIN AVISO DE "INCOMPLETOS", a diferencia de clientes-listado.php. Alli la
 * marca existe porque sin giro, direccion y comuna el SII no acepta la factura.
 * A un proveedor NO se le emite ningun DTE, asi que esa regla no existe y no se
 * copia: mostrarla aqui inventaria una obligacion que nadie tiene.
 */
$titulo = 'Proveedores';
require __DIR__ . '/partials/header.php';
?>

<div class="dash-header">
    <div>
        <h1>Proveedores</h1>
        <p class="dash-header__sub">A quienes les compras. Solo RUT y razon social son obligatorios.</p>
    </div>
    <a class="boton-principal" href="/compras/proveedores/nuevo">+ Nuevo proveedor</a>
</div>

<?php if (! empty($flash['mensaje'])): ?>
    <div class="alerta alerta--<?= htmlspecialchars((string) ($flash['tipo'] ?? 'exito')); ?>" role="status">
        <?= htmlspecialchars((string) $flash['mensaje']); ?>
    </div>
<?php endif; ?>

<form method="get" action="/compras/proveedores" class="filtros">
    <div class="form-campo">
        <label for="q">Buscar</label>
        <input type="search" name="q" id="q" value="<?= htmlspecialchars((string) $q); ?>"
               placeholder="RUT, razon social o contacto">
    </div>
    <div class="form-campo form-campo--check">
        <label><input type="checkbox" name="inactivos" value="1"<?= $incluirInactivos ? ' checked' : ''; ?>> Incluir inactivos</label>
    </div>
    <button type="submit" class="boton-secundario">Filtrar</button>
</form>

<?php if ($proveedores === []): ?>
    <p class="vacio">No hay proveedores que coincidan.
        <a href="/compras/proveedores/nuevo">Crear el primero</a>.</p>
<?php else: ?>
    <div class="tabla-scroll">
        <table class="tabla-datos">
            <thead>
                <tr>
                    <th>RUT</th>
                    <th>Razon social</th>
                    <th>Contacto</th>
                    <th>Correo</th>
                    <th>Condiciones</th>
                    <th><span class="visualmente-oculto">Acciones</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($proveedores as $p): ?>
                    <tr<?= empty($p['activo']) ? ' class="tabla-datos__fila--inactiva"' : ''; ?>>
                        <td><?= htmlspecialchars((string) $p['rut_proveedor']); ?></td>
                        <td><?= htmlspecialchars((string) $p['razon_social']); ?></td>
                        <td><?= htmlspecialchars((string) ($p['contacto'] ?? '')) ?: '&mdash;'; ?></td>
                        <td><?= htmlspecialchars((string) ($p['email'] ?? '')) ?: '&mdash;'; ?></td>
                        <td><?= htmlspecialchars((string) ($p['condiciones_pago'] ?? '')) ?: '&mdash;'; ?></td>
                        <td>
                            <a class="boton-texto" href="/compras/proveedores/<?= (int) $p['id']; ?>/editar">Editar</a>
                            <?php /* Baja LOGICA, nunca fisica: por eso es un POST con token y no un enlace. */ ?>
                            <form method="post" action="/compras/proveedores/<?= (int) $p['id']; ?>/<?= empty($p['activo']) ? 'activar' : 'desactivar'; ?>" class="form-inline">
                                <?= csrfInput(); ?>
                                <button type="submit" class="boton-texto"><?= empty($p['activo']) ? 'Activar' : 'Desactivar'; ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPaginas > 1): ?>
        <nav class="paginacion" aria-label="Paginacion">
            <?php for ($pg = 1; $pg <= $totalPaginas; $pg++): ?>
                <?php $qs = http_build_query(array_filter([
                    'q' => $q, 'inactivos' => $incluirInactivos ? '1' : '', 'pagina' => $pg,
                ], static fn ($v) => $v !== '' && $v !== null)); ?>
                <a class="paginacion__item<?= $pg === $pagina ? ' paginacion__item--actual' : ''; ?>"
                   href="/compras/proveedores?<?= htmlspecialchars($qs); ?>"><?= $pg; ?></a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>

    <p class="nota"><?= (int) $total; ?> proveedor(es).</p>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
