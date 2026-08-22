<?php
/**
 * Cambio de clave obligatorio (GET/POST /cambiar-clave).
 *
 * La ve quien entro con una clave temporal creada por el equipo interno. Usa el
 * layout del TENANT porque es su panel: quien llega aqui es el dueno de una
 * cuenta, no del panel de control.
 *
 * NO OFRECE SALIDA salvo cerrar sesion, y no es un descuido: el bloqueo vive en
 * el router y ningun enlace de esta pantalla lo esquivaria. Poner un "mas tarde"
 * seria ofrecer un boton que no funciona.
 *
 * Las reglas son las mismas de la activacion por token: 8 caracteres y
 * confirmacion que coincida.
 */
$titulo = 'Cambia tu contrasena';
require __DIR__ . '/partials/header.php';
?>

<h1>Cambia tu contrasena</h1>

<p>
    Entraste con una contrasena temporal que genero el equipo de Sinergia, asi que
    ellos la conocen. Elige una nueva para continuar: hasta que lo hagas no vas a
    poder entrar al resto del sistema.
</p>

<?php if ($errores !== []): ?>
<div class="errores" role="alert">
    <?php foreach ($errores as $e): ?>
    <p><?= htmlspecialchars($e); ?></p>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<form method="post" action="/cambiar-clave">
    <?= csrfInput(); ?>

    <p>
        <label for="nueva">Contrasena nueva</label><br>
        <input type="password" name="password" id="nueva" required minlength="8"
               autocomplete="new-password" autofocus>
    </p>

    <p>
        <label for="confirma">Repitela</label><br>
        <input type="password" name="password_confirmacion" id="confirma" required minlength="8"
               autocomplete="new-password">
    </p>

    <p><button type="submit">Guardar y continuar</button></p>
</form>

<p class="nota">Minimo 8 caracteres. Si prefieres salir, <a href="/logout">cierra sesion</a>.</p>

<?php require __DIR__ . '/partials/footer.php'; ?>
