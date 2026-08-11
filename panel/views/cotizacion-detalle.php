<?php
/**
 * Detalle de una cotizacion.
 *
 * Recibe: $cotizacion (con 'lineas'), $editable, $flash, $navActivo.
 *
 * $editable NO SALE DE estado_cache. Lo calcula el handler con
 * tieneFacturacion(), que suma cantidad_facturada de las lineas. El cache es
 * para filtrar el listado con indice; si se desfasara, usarlo aqui dejaria
 * editar una cotizacion ya facturada y romperia el vinculo por id de linea de
 * las facturas emitidas. Ver la nota de la migracion 032.
 *
 * LA COLUMNA "PENDIENTE" YA SE MUESTRA aunque la conversion a factura sea de la
 * segunda entrega: la base ya lleva el saldo (nace en 0 y nada lo mueve
 * todavia), y tenerla a la vista desde el principio evita que la pantalla
 * cambie de forma cuando llegue la conversion.
 */
$titulo = 'Cotizacion N° ' . (int) $cotizacion['numero'];
require __DIR__ . '/partials/header.php';

$monto = static fn ($n): string => '$ ' . number_format((float) $n, 0, ',', '.');

// Cantidad con decimales solo cuando los tiene. Mismo criterio que el PDF: el
// saldo admite fracciones, asi que "1" no puede verse como "1,0000".
$cant = static function ($n): string {
    $f = (float) $n;

    return abs($f - round($f)) < 0.00005
        ? number_format($f, 0, ',', '.')
        : rtrim(rtrim(number_format($f, 4, ',', '.'), '0'), ',');
};

$neto = 0.0;
$exento = 0.0;
foreach ($cotizacion['lineas'] as $l) {
    $n = (float) $l['cantidad'] * (float) $l['precio_unitario']
        * (1 - ((float) $l['descuento_pct']) / 100);
    if (! empty($l['exento'])) {
        $exento += $n;
    } else {
        $neto += $n;
    }
}
$iva   = round($neto * 0.19);
$total = round($neto) + round($exento) + $iva;
?>

<div class="dash-header">
    <div>
        <h1>Cotizacion N&deg; <?= (int) $cotizacion['numero']; ?></h1>
        <p class="dash-header__sub">
            <?= htmlspecialchars((string) $cotizacion['receptor_razon_social']); ?>
            &middot; <?= htmlspecialchars(date('d-m-Y', strtotime((string) $cotizacion['fecha']))); ?>
        </p>
    </div>
    <div class="acciones-grupo">
        <a class="boton-secundario" href="/ventas/cotizaciones/<?= (int) $cotizacion['id']; ?>/pdf" target="_blank" rel="noopener">Ver PDF</a>
        <?php if ($editable): ?>
            <a class="boton-principal" href="/ventas/cotizaciones/<?= (int) $cotizacion['id']; ?>/editar">Editar</a>
        <?php endif; ?>
    </div>
</div>

<?php if (! empty($flash['mensaje'])): ?>
    <div class="alerta alerta--<?= htmlspecialchars((string) ($flash['tipo'] ?? 'exito')); ?>" role="status">
        <?= htmlspecialchars((string) $flash['mensaje']); ?>
    </div>
<?php endif; ?>

<?php if (! $editable): ?>
    <div class="alerta alerta--advertencia" role="status">
        Esta cotizacion ya tiene facturacion y no se puede editar. Cambiar sus lineas
        romperia el vinculo de las facturas ya emitidas.
    </div>
<?php endif; ?>

<section class="tarjeta">
    <h2>Cliente</h2>
    <dl class="ficha">
        <dt>RUT</dt><dd><?= htmlspecialchars((string) $cotizacion['receptor_rut']); ?></dd>
        <dt>Razon social</dt><dd><?= htmlspecialchars((string) $cotizacion['receptor_razon_social']); ?></dd>
        <?php foreach ([
            'Giro'      => $cotizacion['receptor_giro'],
            'Direccion' => $cotizacion['receptor_direccion'],
            'Comuna'    => $cotizacion['receptor_comuna'],
            'Correo'    => $cotizacion['receptor_email'],
        ] as $etiqueta => $valor): ?>
            <?php if (trim((string) $valor) !== ''): ?>
                <dt><?= $etiqueta; ?></dt><dd><?= htmlspecialchars((string) $valor); ?></dd>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if (! empty($cotizacion['valida_hasta'])): ?>
            <dt>Valida hasta</dt><dd><?= htmlspecialchars(date('d-m-Y', strtotime((string) $cotizacion['valida_hasta']))); ?></dd>
        <?php endif; ?>
    </dl>
</section>

<section class="tarjeta">
    <h2>Detalle</h2>
    <div class="tabla-scroll">
        <table class="tabla-datos">
            <thead>
                <tr>
                    <th>Item</th>
                    <th class="tabla-datos__num">Cantidad</th>
                    <th>Unidad</th>
                    <th class="tabla-datos__num">P. unitario</th>
                    <th class="tabla-datos__num">Desc.</th>
                    <th class="tabla-datos__num">Facturado</th>
                    <th class="tabla-datos__num">Pendiente</th>
                    <th class="tabla-datos__num">Neto</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cotizacion['lineas'] as $l): ?>
                    <?php
                    $pendiente = (float) $l['cantidad'] - (float) $l['cantidad_facturada'];
                    $netoLinea = (float) $l['cantidad'] * (float) $l['precio_unitario']
                        * (1 - ((float) $l['descuento_pct']) / 100);
                    ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars((string) $l['nombre']); ?>
                            <?php if (trim((string) $l['descripcion']) !== ''): ?>
                                <span class="tabla-datos__secundario"><?= htmlspecialchars((string) $l['descripcion']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="tabla-datos__num"><?= $cant($l['cantidad']); ?></td>
                        <td><?= htmlspecialchars((string) ($l['unidad'] ?? '')); ?></td>
                        <td class="tabla-datos__num"><?= $monto($l['precio_unitario']); ?></td>
                        <td class="tabla-datos__num"><?= ((float) $l['descuento_pct']) > 0 ? $cant($l['descuento_pct']) . '%' : '&mdash;'; ?></td>
                        <td class="tabla-datos__num"><?= $cant($l['cantidad_facturada']); ?></td>
                        <td class="tabla-datos__num"><?= $cant($pendiente); ?></td>
                        <td class="tabla-datos__num"><?= $monto($netoLinea); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <dl class="ficha ficha--totales">
        <?php if ($neto > 0): ?>
            <dt>Neto</dt><dd><?= $monto(round($neto)); ?></dd>
            <dt>IVA 19%</dt><dd><?= $monto($iva); ?></dd>
        <?php endif; ?>
        <?php if ($exento > 0): ?>
            <dt>Exento</dt><dd><?= $monto(round($exento)); ?></dd>
        <?php endif; ?>
        <dt><strong>Total</strong></dt><dd><strong><?= $monto($total); ?></strong></dd>
    </dl>
    <p class="nota">
        El IVA se muestra al 19% para que el cliente vea el mismo numero que le va a
        llegar en la factura. Una cotizacion no declara impuestos: no se emite nada al SII.
    </p>
</section>

<?php if (trim((string) $cotizacion['notas']) !== ''): ?>
    <section class="tarjeta">
        <h2>Observaciones</h2>
        <p><?= nl2br(htmlspecialchars((string) $cotizacion['notas'])); ?></p>
    </section>
<?php endif; ?>

<p><a class="boton-texto" href="/ventas/cotizaciones">Volver al listado</a></p>

<?php require __DIR__ . '/partials/footer.php'; ?>
