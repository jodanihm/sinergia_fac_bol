<?php
/**
 * Cuenta creada: la clave temporal, una sola vez (POST /admin/tenants/nueva).
 *
 * ESTA PANTALLA ES EL UNICO LUGAR DONDE LA CLAVE EXISTE EN TEXTO PLANO. En la
 * base solo esta su hash; no paso por el flash, ni por la sesion, ni por el
 * log, ni por admin_auditoria. Si se cierra sin copiarla, la unica salida es
 * que el dueno de la cuenta use el flujo de activacion o que se cree otra.
 *
 * Se dibuja como respuesta DIRECTA del POST, sin redirect: con uno, la clave
 * tendria que viajar en la sesion para sobrevivir al salto, que es justo lo que
 * se quiere evitar.
 */
$titulo      = 'Cuenta creada';
$adminActivo = 'cuentas';
require __DIR__ . '/partials/admin/header.php';
?>

<h2 class="page-title">Cuenta creada</h2>

<div class="panel" style="max-width:620px;border-color:var(--ok);">
    <p class="msg-ok" style="margin-top:0;">
        <strong><?= htmlspecialchars($nombreCuenta); ?></strong> (cuenta #<?= (int) $cuentaId; ?>) esta activa,
        con <?= htmlspecialchars($email); ?> como propietario.
    </p>

    <h3>Clave temporal</h3>
    <?php /* user-select:all para poder seleccionarla de una pasada: el panel
             corre sobre HTTP plano y no hay copia automatica confiable. Mismo
             criterio que .secreto-unico del panel del tenant. */ ?>
    <p class="clave-unica"><?= htmlspecialchars($clave); ?></p>

    <p class="error">
        Copiala ahora. No se guarda en ninguna parte y no vas a poder verla de nuevo:
        en la base solo queda su version cifrada.
    </p>
    <p class="muted">
        Entregala por un canal aparte del email de la cuenta. La primera vez que esta
        persona entre, el sistema no la va a dejar pasar a ninguna pantalla hasta que
        la reemplace por una que solo ella conozca.
    </p>

    <div class="actions" style="margin-top:1.25rem;">
        <a class="btn" href="/admin/tenants/<?= (int) $cuentaId; ?>">Ver la ficha</a>
        <a class="btn ghost" href="/admin/tenants">Volver a Cuentas</a>
        <a class="btn ghost" href="/admin/tenants/nueva">Crear otra</a>
    </div>
</div>

<?php require __DIR__ . '/partials/admin/footer.php'; ?>
