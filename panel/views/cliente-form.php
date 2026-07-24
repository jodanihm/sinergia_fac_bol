<?php
$titulo = $modo === 'nuevo' ? 'Nuevo cliente' : 'Editar cliente';
require __DIR__ . '/partials/header.php';

$val = static function (string $campo) use ($cliente): string {
    return htmlspecialchars((string) ($cliente[$campo] ?? ''));
};
$err = static function (string $campo) use ($errores): ?string {
    return $errores[$campo] ?? null;
};
$recomendado = 'display:block;margin:0.15rem 0 0;color:#7a4b00;font-size:0.82rem;';
?>

<h1><?= $modo === 'nuevo' ? 'Nuevo cliente' : 'Editar cliente'; ?></h1>

<form method="post" action="<?= htmlspecialchars($accion); ?>">
    <?= csrfInput(); ?>

    <label>RUT del cliente
        <input type="text" name="rut_cliente" value="<?= $val('rut_cliente'); ?>" placeholder="77724622-4" required>
    </label>
    <?php if ($err('rut_cliente')): ?><p class="error"><?= htmlspecialchars($err('rut_cliente')); ?></p><?php endif; ?>

    <label>Razon social
        <input type="text" name="razon_social" value="<?= $val('razon_social'); ?>" placeholder="Cliente SpA" required>
    </label>
    <?php if ($err('razon_social')): ?><p class="error"><?= htmlspecialchars($err('razon_social')); ?></p><?php endif; ?>

    <label>Giro
        <input type="text" name="giro" value="<?= $val('giro'); ?>">
    </label>
    <small style="<?= htmlspecialchars($recomendado); ?>">Recomendado (necesario para emitir).</small>

    <label>Direccion
        <input type="text" name="direccion" value="<?= $val('direccion'); ?>">
    </label>
    <small style="<?= htmlspecialchars($recomendado); ?>">Recomendado (necesario para emitir).</small>

    <label>Comuna
        <input type="text" name="comuna" value="<?= $val('comuna'); ?>">
    </label>
    <small style="<?= htmlspecialchars($recomendado); ?>">Recomendado (necesario para emitir).</small>

    <label>Email
        <input type="email" name="email" value="<?= $val('email'); ?>">
    </label>
    <small style="<?= htmlspecialchars($recomendado); ?>">Recomendado (necesario para emitir).</small>
    <?php if ($err('email')): ?><p class="error"><?= htmlspecialchars($err('email')); ?></p><?php endif; ?>

    <label>Telefono
        <input type="text" name="telefono" value="<?= $val('telefono'); ?>">
    </label>
    <small style="<?= htmlspecialchars($recomendado); ?>">Recomendado (necesario para emitir).</small>

    <button type="submit"><?= $modo === 'nuevo' ? 'Crear cliente' : 'Guardar cambios'; ?></button>
</form>

<p><a href="/maestros/clientes">Volver al listado</a></p>

<?php require __DIR__ . '/partials/footer.php'; ?>
