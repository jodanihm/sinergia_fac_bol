<?php
/**
 * Alta de cuenta desde el panel de control (GET/POST /admin/tenants/nueva).
 *
 * Dos campos y nada mas: el nombre de la cuenta y el email del owner. No hay
 * campo para el NOMBRE de la persona porque la tabla usuario no tiene esa
 * columna -- guarda email, rol y estado. Agregarla seria otra migracion, y
 * ofrecer un campo que se descarta al guardar es peor que no ofrecerlo.
 */
$titulo      = 'Nueva cuenta';
$adminActivo = 'cuentas';
require __DIR__ . '/partials/admin/header.php';
?>

<p class="muted" style="margin-top:0;"><a href="/admin/tenants">&larr; Cuentas</a></p>
<h2 class="page-title">Nueva cuenta</h2>

<div class="panel" style="max-width:560px;">
    <?php if ($errores !== []): ?>
    <div class="error" role="alert">
        <?php foreach ($errores as $e): ?>
        <div><?= htmlspecialchars($e); ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <p class="muted" style="margin-top:0;">
        Crea la cuenta y su usuario propietario en un paso, sin link de activacion.
        La clave se genera sola y se muestra <strong>una sola vez</strong> en la pantalla
        siguiente: no queda guardada en ninguna parte y no se puede recuperar despues.
        Quien entre con ella tendra que cambiarla antes de poder usar el sistema.
    </p>

    <form method="post" action="/admin/tenants/nueva">
        <?= csrfInput(); ?>

        <label class="field" for="cuenta-nombre">
            <span>Nombre de la cuenta</span>
            <input type="text" name="nombre" id="cuenta-nombre" required
                   value="<?= htmlspecialchars($nombreCuenta); ?>" autofocus>
        </label>

        <label class="field" for="cuenta-email">
            <span>Email del propietario</span>
            <input type="email" name="email" id="cuenta-email" required
                   value="<?= htmlspecialchars($email); ?>" autocomplete="off">
        </label>

        <div class="actions" style="margin-top:1rem;">
            <button type="submit" class="btn">Crear cuenta</button>
            <a class="btn ghost" href="/admin/tenants">Cancelar</a>
        </div>
    </form>
</div>

<?php require __DIR__ . '/partials/admin/footer.php'; ?>
