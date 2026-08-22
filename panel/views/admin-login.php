<?php
/**
 * Acceso al panel de control (GET/POST /admin/login).
 *
 * NO USA partials/admin/header.php, y no es un descuido. Ese shell dibuja el
 * sidebar y la topbar, y las dos suponen una sesion: la topbar consulta el
 * email del usuario por Auth::usuarioId(), que sin sesion no existe, y el
 * sidebar ofreceria nueve enlaces que responderian 403 uno por uno. Una
 * pantalla de acceso con el menu del area a la que todavia no se entro es un
 * menu que miente.
 *
 * Lo que SI comparte es el tema: carga admin.css y usa sus tokens, asi que se
 * ve como el panel de control y no como el panel del tenant. Es la misma
 * distincion visual que justifica que admin.css exista -- quien mira la
 * pantalla sabe a cual de los dos esta entrando antes de leer nada.
 *
 * Recibe $error (string|null) y $email (string), igual que views/login.php.
 *
 * LA CONTRASENA NUNCA SE RE-RENDERIZA: el input no lleva value ni despues de
 * un intento fallido, no aparece en ningun atributo, y esta pantalla no tiene
 * JavaScript.
 */
$adminCssRuta    = __DIR__ . '/../public/css/admin.css';
$adminCssVersion = @filemtime($adminCssRuta);
$adminCssHref    = '/css/admin.css' . ($adminCssVersion ? '?v=' . $adminCssVersion : '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Panel de control</title>
<link rel="stylesheet" href="<?= htmlspecialchars($adminCssHref); ?>">
<?php /* Esta URL no debe aparecer en buscadores: no es una pagina del producto. */ ?>
<meta name="robots" content="noindex, nofollow">
</head>
<body>
<div class="login-wrap">
    <main class="login-card">
        <h1>Sinergia &middot; Control</h1>
        <p class="sub">Acceso del equipo interno</p>

        <?php if (! empty($error)): ?>
        <?php
            /* Texto TAL CUAL lo manda el handler. Es el mismo para las tres
               formas de fallo -- email inexistente, clave incorrecta, y usuario
               valido que no es superadmin -- a proposito: distinguirlas
               convertiria esta pantalla en una forma de averiguar que cuentas
               existen. */
        ?>
        <p class="error" role="alert"><?= htmlspecialchars($error); ?></p>
        <?php endif; ?>

        <form method="post" action="/admin/login">
            <?= csrfInput(); ?>

            <label class="field" for="admin-email">
                <span>Email</span>
                <input type="email" name="email" id="admin-email" autocomplete="email"
                       value="<?= htmlspecialchars($email); ?>" required autofocus>
            </label>

            <label class="field" for="admin-password">
                <span>Contrasena</span>
                <input type="password" name="password" id="admin-password"
                       autocomplete="current-password" required>
            </label>

            <button type="submit" class="btn" style="width:100%;margin-top:.5rem;">Entrar</button>
        </form>

        <p class="muted login-pie">
            Si buscas el panel de tu empresa, entra por <a href="/login">/login</a>.
        </p>
    </main>
</div>
</body>
</html>
