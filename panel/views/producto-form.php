<?php
$titulo = $modo === 'nuevo' ? 'Nuevo producto' : 'Editar producto';
require __DIR__ . '/partials/header.php';

$val = static function (string $campo) use ($producto): string {
    return htmlspecialchars((string) ($producto[$campo] ?? ''));
};
$err = static function (string $campo) use ($errores): ?string {
    return $errores[$campo] ?? null;
};
$opcional = 'display:block;margin:0.15rem 0 0;color:#666;font-size:0.82rem;';
?>

<h1><?= $modo === 'nuevo' ? 'Nuevo producto' : 'Editar producto'; ?></h1>

<form method="post" action="<?= htmlspecialchars($accion); ?>">
    <?= csrfInput(); ?>

    <label>Nombre
        <input type="text" name="nombre" value="<?= $val('nombre'); ?>" placeholder="Servicio de ejemplo" required>
    </label>
    <?php if ($err('nombre')): ?><p class="error"><?= htmlspecialchars($err('nombre')); ?></p><?php endif; ?>

    <label>Codigo (SKU)
        <input type="text" name="codigo" value="<?= $val('codigo'); ?>">
    </label>
    <small style="<?= htmlspecialchars($opcional); ?>">Opcional. Si lo usas, debe ser unico dentro de tu empresa.</small>
    <?php if ($err('codigo')): ?><p class="error"><?= htmlspecialchars($err('codigo')); ?></p><?php endif; ?>

    <label>Descripcion
        <input type="text" name="descripcion" value="<?= $val('descripcion'); ?>">
    </label>
    <small style="<?= htmlspecialchars($opcional); ?>">Opcional.</small>

    <label>Precio unitario
        <input type="text" inputmode="decimal" name="precio_unitario" value="<?= $val('precio_unitario'); ?>" placeholder="1990 o 1990.50">
    </label>
    <small style="<?= htmlspecialchars($opcional); ?>">Opcional. Numero, con o sin decimales (ej. 1990 o 1990.50).</small>
    <?php if ($err('precio_unitario')): ?><p class="error"><?= htmlspecialchars($err('precio_unitario')); ?></p><?php endif; ?>

    <label>Unidad
        <input type="text" name="unidad" value="<?= $val('unidad'); ?>" placeholder="UN, KG, HH...">
    </label>
    <small style="<?= htmlspecialchars($opcional); ?>">Opcional. Unidad de medida.</small>

    <label style="display:flex;align-items:center;gap:0.5rem;font-weight:600;margin-top:1rem;">
        <input type="checkbox" name="exento" value="1" style="width:auto;margin:0;" <?= ! empty($producto['exento']) ? 'checked' : ''; ?>>
        Exento de IVA
    </label>
    <small style="<?= htmlspecialchars($opcional); ?>">Marca esta casilla solo si el producto/servicio es exento de IVA.</small>

    <button type="submit"><?= $modo === 'nuevo' ? 'Crear producto' : 'Guardar cambios'; ?></button>
</form>

<p><a href="/maestros/productos">Volver al listado</a></p>

<?php require __DIR__ . '/partials/footer.php'; ?>
