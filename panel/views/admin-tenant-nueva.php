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

        <label class="field" for="cuenta-tipo">
            <span>Tipo de cuenta</span>
            <?php /* SIN NINGUNO PRESELECCIONADO, a proposito. Un valor por defecto seria
                     una respuesta que nadie dio, y a los dos dias se lee como un dato
                     confirmado: es justo el problema que este campo vino a resolver.
                     'Sin definir' tampoco se ofrece aqui -- existe para las cuentas que ya
                     estaban cuando el sistema no lo preguntaba, no para las nuevas. */ ?>
            <select name="tipo" id="cuenta-tipo" required>
                <option value="">Elegir...</option>
                <?php foreach (TipoCuenta::catalogo() as $claveTipo => [$etiquetaTipo, , $ayudaTipo]): ?>
                <?php if ($claveTipo === 'sin_definir') { continue; } ?>
                <option value="<?= htmlspecialchars($claveTipo); ?>" title="<?= htmlspecialchars($ayudaTipo); ?>"
                    <?= $tipo === $claveTipo ? 'selected' : ''; ?>><?= htmlspecialchars($etiquetaTipo); ?></option>
                <?php endforeach; ?>
            </select>
            <small class="muted">
                Separa lo comercial de lo interno. No cambia ningun permiso ni ningun limite:
                es el dato con el que se puede contestar cuantos clientes hay de verdad.
            </small>
        </label>

        <label class="field" for="cuenta-plan">
            <span>Plan contratado</span>
            <?php /* 'Sin plan' SI se ofrece aqui -- es la respuesta correcta para una
                     cuenta interna o de demostracion --, pero 'Sin definir' no: ese valor
                     existe para las cuentas que ya estaban cuando el sistema no lo
                     preguntaba, no para las que se crean ahora. */ ?>
            <select name="plan" id="cuenta-plan" required>
                <option value="">Elegir...</option>
                <?php foreach (PlanCuenta::catalogo() as $clavePlan => [$etiquetaPlan, , $ayudaPlan]): ?>
                <?php if ($clavePlan === 'sin_definir') { continue; } ?>
                <option value="<?= htmlspecialchars($clavePlan); ?>" title="<?= htmlspecialchars($ayudaPlan); ?>"
                    <?= $plan === $clavePlan ? 'selected' : ''; ?>><?= htmlspecialchars($etiquetaPlan); ?></option>
                <?php endforeach; ?>
            </select>
            <small class="muted">
                Referencia de la pagina de venta. El sistema no cobra ni controla el tope de
                facturas del plan.
            </small>
        </label>

        <div class="actions" style="margin-top:1rem;">
            <button type="submit" class="btn">Crear cuenta</button>
            <a class="btn ghost" href="/admin/tenants">Cancelar</a>
        </div>
    </form>
</div>

<?php require __DIR__ . '/partials/admin/footer.php'; ?>
