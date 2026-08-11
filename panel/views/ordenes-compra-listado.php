<?php
/**
 * Listado de ordenes de compra.
 *
 * Recibe: $ordenes, $q, $incluirInactivas, $pagina, $totalPaginas, $total,
 * $flash, $navActivo.
 *
 * SIN COLUMNA DE ESTADO, y es deliberado: no hay estados de seguimiento
 * (enviada/recibida/cerrada). Lo unico que se sabe es si la orden esta activa y
 * cuanto suma. Si se muestra el estado de un ENVIO -- que es otra cosa -- va en
 * el detalle, donde esta el historial de la cola.
 *
 * EL TOTAL SALE DE LA COLUMNA, no de un calculo aqui: los totales quedaron
 * congelados al guardar para que el papel del proveedor y la pantalla no puedan
 * discrepar.
 */
$titulo = 'Ordenes de compra';
require __DIR__ . '/partials/header.php';

$monto = static fn ($n): string => '$ ' . number_format((float) $n, 0, ',', '.');
?>

<div class="dash-header">
    <div>
        <h1>Ordenes de compra</h1>
        <p class="dash-header__sub">Lo que le pides a tus proveedores. No pasa por el SII.</p>
    </div>
    <a class="boton-principal" href="/compras/ordenes/nueva">+ Nueva orden</a>
</div>

<?php if (! empty($flash['mensaje'])): ?>
    <div class="alerta alerta--<?= htmlspecialchars((string) ($flash['tipo'] ?? 'exito')); ?>" role="status">
        <?= htmlspecialchars((string) $flash['mensaje']); ?>
    </div>
<?php endif; ?>

<form method="get" action="/compras/ordenes" class="filtros">
    <div class="form-campo">
        <label for="q">Buscar</label>
        <input type="search" name="q" id="q" value="<?= htmlspecialchars((string) $q); ?>"
               placeholder="Numero, RUT o razon social">
    </div>
    <div class="form-campo form-campo--check">
        <label><input type="checkbox" name="inactivas" value="1"<?= $incluirInactivas ? ' checked' : ''; ?>> Incluir anuladas</label>
    </div>
    <button type="submit" class="boton-secundario">Filtrar</button>
</form>

<?php if ($ordenes === []): ?>
    <p class="vacio">No hay ordenes que coincidan.
        <a href="/compras/ordenes/nueva">Crear la primera</a>.</p>
<?php else: ?>
    <div class="tabla-scroll">
        <table class="tabla-datos">
            <thead>
                <tr>
                    <th>N&deg;</th>
                    <th>Fecha</th>
                    <th>Proveedor</th>
                    <th>Entrega</th>
                    <th class="tabla-datos__num">Neto</th>
                    <th class="tabla-datos__num">Total</th>
                    <th><span class="visualmente-oculto">Acciones</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ordenes as $o): ?>
                    <tr<?= empty($o['activo']) ? ' class="tabla-datos__fila--inactiva"' : ''; ?>>
                        <td><a href="/compras/ordenes/<?= (int) $o['id']; ?>"><?= (int) $o['numero']; ?></a></td>
                        <td><?= htmlspecialchars(date('d-m-Y', (int) strtotime((string) $o['fecha']))); ?></td>
                        <td>
                            <?= htmlspecialchars((string) $o['proveedor_razon_social']); ?>
                            <span class="tabla-datos__secundario"><?= htmlspecialchars((string) $o['proveedor_rut']); ?></span>
                        </td>
                        <td><?= ! empty($o['fecha_entrega'])
                            ? htmlspecialchars(date('d-m-Y', (int) strtotime((string) $o['fecha_entrega'])))
                            : '&mdash;'; ?></td>
                        <td class="tabla-datos__num"><?= $monto($o['neto']); ?></td>
                        <td class="tabla-datos__num"><?= $monto($o['total']); ?></td>
                        <td>
                            <a class="boton-texto" href="/compras/ordenes/<?= (int) $o['id']; ?>">Ver</a>
                            <a class="boton-texto" href="/compras/ordenes/<?= (int) $o['id']; ?>/pdf" target="_blank" rel="noopener">PDF</a>
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
                    'q' => $q, 'inactivas' => $incluirInactivas ? '1' : '', 'pagina' => $pg,
                ], static fn ($v) => $v !== '' && $v !== null)); ?>
                <a class="paginacion__item<?= $pg === $pagina ? ' paginacion__item--actual' : ''; ?>"
                   href="/compras/ordenes?<?= htmlspecialchars($qs); ?>"><?= $pg; ?></a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>

    <p class="nota"><?= (int) $total; ?> orden(es).</p>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
