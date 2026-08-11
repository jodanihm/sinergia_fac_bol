<?php
/**
 * Listado de cotizaciones.
 *
 * Recibe: $cotizaciones, $q, $estado, $incluirInactivas, $pagina,
 * $totalPaginas, $total, $flash, $navActivo.
 *
 * EL FILTRO POR ESTADO USA estado_cache, y es el unico sitio donde ese cache se
 * usa: existe justamente para poder filtrar con indice (ix_cotizacion_estado).
 * Para decidir si una cotizacion SE PUEDE EDITAR no se mira este badge sino las
 * cantidades -- eso lo resuelve el detalle, con tieneFacturacion().
 */
$titulo = 'Cotizaciones';
require __DIR__ . '/partials/header.php';

$badge = static function (string $e): array {
    return match ($e) {
        'sin_facturar' => ['badge--neutro', 'Sin facturar'],
        'parcial'      => ['badge--advertencia', 'Facturada en parte'],
        'facturada'    => ['badge--exito', 'Facturada'],
        default        => ['badge--neutro', $e],
    };
};
$monto = static function ($n): string {
    return '$ ' . number_format((float) $n, 0, ',', '.');
};
?>

<div class="dash-header">
    <div>
        <h1>Cotizaciones</h1>
        <p class="dash-header__sub">Documento interno: no pasa por el SII y no consume folio.</p>
    </div>
    <a class="boton-principal" href="/ventas/cotizaciones/nueva">+ Nueva cotizacion</a>
</div>

<?php if (! empty($flash['mensaje'])): ?>
    <div class="alerta alerta--<?= htmlspecialchars((string) ($flash['tipo'] ?? 'exito')); ?>" role="status">
        <?= htmlspecialchars((string) $flash['mensaje']); ?>
    </div>
<?php endif; ?>

<form method="get" action="/ventas/cotizaciones" class="filtros">
    <div class="form-campo">
        <label for="q">Buscar</label>
        <input type="search" name="q" id="q" value="<?= htmlspecialchars((string) $q); ?>" placeholder="Numero, RUT o razon social">
    </div>
    <div class="form-campo form-campo--corto">
        <label for="estado">Estado</label>
        <select name="estado" id="estado">
            <option value="">Todos</option>
            <?php foreach (['sin_facturar' => 'Sin facturar', 'parcial' => 'Facturada en parte', 'facturada' => 'Facturada'] as $k => $et): ?>
                <option value="<?= $k; ?>"<?= $estado === $k ? ' selected' : ''; ?>><?= $et; ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-campo form-campo--check">
        <label><input type="checkbox" name="inactivas" value="1"<?= $incluirInactivas ? ' checked' : ''; ?>> Incluir anuladas</label>
    </div>
    <button type="submit" class="boton-secundario">Filtrar</button>
</form>

<?php if ($cotizaciones === []): ?>
    <p class="vacio">No hay cotizaciones que coincidan.
        <a href="/ventas/cotizaciones/nueva">Crear la primera</a>.</p>
<?php else: ?>
    <div class="tabla-scroll">
        <table class="tabla-datos">
            <thead>
                <tr>
                    <th>N&deg;</th>
                    <th>Fecha</th>
                    <th>Cliente</th>
                    <th class="tabla-datos__num">Total</th>
                    <th>Estado</th>
                    <th><span class="visualmente-oculto">Acciones</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cotizaciones as $c): ?>
                    <?php [$clase, $texto] = $badge((string) $c['estado_cache']); ?>
                    <tr<?= empty($c['activo']) ? ' class="tabla-datos__fila--inactiva"' : ''; ?>>
                        <td><a href="/ventas/cotizaciones/<?= (int) $c['id']; ?>"><?= (int) $c['numero']; ?></a></td>
                        <td><?= htmlspecialchars(date('d-m-Y', strtotime((string) $c['fecha']))); ?></td>
                        <td>
                            <?= htmlspecialchars((string) $c['receptor_razon_social']); ?>
                            <span class="tabla-datos__secundario"><?= htmlspecialchars((string) $c['receptor_rut']); ?></span>
                        </td>
                        <td class="tabla-datos__num"><?= $monto($c['total_estimado']); ?></td>
                        <td><span class="badge <?= $clase; ?>"><?= $texto; ?></span></td>
                        <td>
                            <a class="boton-texto" href="/ventas/cotizaciones/<?= (int) $c['id']; ?>">Ver</a>
                            <a class="boton-texto" href="/ventas/cotizaciones/<?= (int) $c['id']; ?>/pdf" target="_blank" rel="noopener">PDF</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPaginas > 1): ?>
        <nav class="paginacion" aria-label="Paginacion">
            <?php for ($p = 1; $p <= $totalPaginas; $p++): ?>
                <?php
                $qs = http_build_query(array_filter([
                    'q' => $q, 'estado' => $estado,
                    'inactivas' => $incluirInactivas ? '1' : '', 'pagina' => $p,
                ], static fn ($v) => $v !== '' && $v !== null));
                ?>
                <a class="paginacion__item<?= $p === $pagina ? ' paginacion__item--actual' : ''; ?>"
                   href="/ventas/cotizaciones?<?= htmlspecialchars($qs); ?>"><?= $p; ?></a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>

    <p class="nota"><?= (int) $total; ?> cotizacion(es).</p>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
