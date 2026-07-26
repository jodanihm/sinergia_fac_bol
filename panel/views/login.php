<?php
/**
 * Pantalla de acceso.
 *
 * Recibe: $error (string|null) y $email (string). Las pone el router en el GET
 * (/login, ambas vacias) y handleLoginPost() tras un intento fallido.
 *
 * EL CONTRATO DEL FORMULARIO NO CAMBIA. handleLoginPost() lee exactamente
 * $_POST['email'] y $_POST['password'], y el router verifica el CSRF antes de
 * llegar al handler, asi que se conservan textuales: action, method, csrfInput(),
 * los dos name, los type y los required. Al markup se agregaron solo dos cosas,
 * ninguna de las cuales altera lo que viaja en el POST:
 *   - id, para que cada <label> use for= en vez de envolver el input;
 *   - autocomplete, para que los gestores de credenciales del navegador
 *     reconozcan los campos (email / current-password son los valores del
 *     estandar HTML para una pantalla de inicio de sesion).
 *
 * LA CONTRASENA NUNCA SE RE-RENDERIZA. El input password no lleva value ni
 * despues de un error -- igual que antes --, no aparece en ningun atributo y
 * esta pantalla no tiene JavaScript.
 *
 * NO HAY ENLACE A /registro. Es una decision de producto: el alta de tenants no
 * es autoregistro publico. La RUTA sigue existiendo y funcionando igual por URL
 * directa; aqui solo deja de ofrecerse.
 *
 * LO QUE LA MAQUETA MUESTRA Y AQUI NO ESTA, porque no existe en el sistema:
 *   - "Olvidaste tu contrasena?": no hay ninguna ruta ni flujo de recuperacion
 *     en el proyecto (verificado en el router y en las vistas). Un enlace a una
 *     pagina inexistente seria peor que no ofrecerlo.
 *   - El ojo de "mostrar contrasena": exigiria JavaScript nuevo en la unica
 *     pantalla que maneja credenciales. Se deja fuera a proposito.
 *
 * $bodyClase la lee partials/header.php: anula el max-width global del <body>
 * para que esta pantalla ocupe el ancho completo.
 */
$titulo    = 'Iniciar sesion';
$bodyClase = 'auth-page';
require __DIR__ . '/partials/header.php';
?>

<div class="auth-layout">

    <header class="auth-header">
        <a class="auth-marca" href="/login" aria-label="Sinergia Facturacion">
            <img src="/img/logo.png" alt="Sinergia Facturacion" class="auth-marca__logo">
        </a>
        <?php
            /* Describe lo que es la pantalla (un area que pide credenciales), no
               una certificacion ni una promesa de cifrado: el panel corre hoy
               sobre HTTP en LAN y no se puede respaldar "plataforma segura". */
        ?>
        <p class="auth-header__nota">
            <svg class="auth-icono" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M12 2 4 5v6c0 5 3.4 9.4 8 11 4.6-1.6 8-6 8-11V5l-8-3z"
                      fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
                <path d="m9 12 2 2 4-4" fill="none" stroke="currentColor"
                      stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            Acceso privado
        </p>
    </header>

    <div class="auth-main">

        <?php
            /* Zona decorativa. Es una abstraccion del panel: sin cifras, sin
               nombres, sin RUT y sin ningun dato que pueda leerse como real.
               aria-hidden porque no aporta informacion, solo contexto visual. */
        ?>
        <div class="auth-visual" aria-hidden="true">
            <div class="auth-visual__fondo"></div>
            <svg class="auth-visual__panel" viewBox="0 0 420 300" role="presentation" focusable="false">
                <rect x="0" y="0" width="420" height="300" rx="14" fill="#ffffff"/>
                <rect x="0" y="0" width="64" height="300" rx="14" fill="#1e3a8a"/>
                <rect x="52" y="0" width="12" height="300" fill="#1e3a8a"/>
                <g fill="#ffffff" opacity=".55">
                    <rect x="18" y="30" width="28" height="6" rx="3"/>
                    <rect x="18" y="52" width="28" height="6" rx="3"/>
                    <rect x="18" y="74" width="28" height="6" rx="3"/>
                    <rect x="18" y="96" width="28" height="6" rx="3"/>
                </g>
                <rect x="18" y="140" width="28" height="6" rx="3" fill="#93c5fd"/>

                <rect x="86" y="26" width="150" height="86" rx="10" fill="#f5f8ff"/>
                <rect x="102" y="44" width="62" height="7" rx="3.5" fill="#c7d7f5"/>
                <rect x="102" y="62" width="94" height="13" rx="4" fill="#1a56db" opacity=".22"/>
                <polyline points="102,98 124,88 146,93 168,74 190,80 212,62"
                          fill="none" stroke="#1a56db" stroke-width="2.6"
                          stroke-linecap="round" stroke-linejoin="round"/>

                <rect x="252" y="26" width="150" height="86" rx="10" fill="#f5f8ff"/>
                <rect x="268" y="44" width="52" height="7" rx="3.5" fill="#c7d7f5"/>
                <rect x="268" y="62" width="72" height="13" rx="4" fill="#1a56db" opacity=".22"/>
                <circle cx="366" cy="80" r="20" fill="none" stroke="#dbe6fb" stroke-width="8"/>
                <path d="M366 60a20 20 0 0 1 17 30" fill="none" stroke="#1a56db"
                      stroke-width="8" stroke-linecap="round"/>

                <rect x="86" y="132" width="316" height="146" rx="10" fill="#f5f8ff"/>
                <g fill="#dbe6fb">
                    <rect x="104" y="152" width="280" height="9" rx="4.5"/>
                    <rect x="104" y="178" width="240" height="9" rx="4.5"/>
                    <rect x="104" y="204" width="264" height="9" rx="4.5"/>
                    <rect x="104" y="230" width="210" height="9" rx="4.5"/>
                    <rect x="104" y="256" width="248" height="9" rx="4.5"/>
                </g>
            </svg>
        </div>

        <section class="auth-card" aria-labelledby="titulo-login">
            <img src="/img/logo.png" alt="" class="auth-card__logo">

            <h1 id="titulo-login" class="auth-card__titulo">Iniciar sesion</h1>
            <p class="auth-card__bajada">Accede a tu plataforma de facturacion Sinergia</p>

            <?php if (! empty($error)): ?>
                <?php
                    /* Se imprime el mensaje TEXTUAL que manda handleLoginPost(), que es
                       generico a proposito: no revela si el email existe. role="alert"
                       hace que un lector de pantalla lo anuncie al re-renderizar.
                       El comentario va en PHP y no en HTML para no repetir el texto del
                       error en el codigo fuente que llega al navegador. */
                ?>
                <p class="alerta alerta--error auth-alerta" role="alert">
                    <span class="alerta__icono" aria-hidden="true">&#9888;</span>
                    <span><?= htmlspecialchars($error); ?></span>
                </p>
            <?php endif; ?>

            <form method="post" action="/login" class="auth-form">
                <?= csrfInput(); ?>

                <div class="auth-campo">
                    <label for="login-email">Email</label>
                    <div class="auth-input">
                        <svg class="auth-input__icono" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <rect x="3" y="5" width="18" height="14" rx="2.5" fill="none"
                                  stroke="currentColor" stroke-width="1.6"/>
                            <path d="m4 7 8 5.5L20 7" fill="none" stroke="currentColor"
                                  stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <input type="email" name="email" id="login-email" autocomplete="email"
                               value="<?= htmlspecialchars($email); ?>" required>
                    </div>
                </div>

                <div class="auth-campo">
                    <label for="login-password">Contrasena</label>
                    <div class="auth-input">
                        <svg class="auth-input__icono" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <rect x="4.5" y="10" width="15" height="10" rx="2.5" fill="none"
                                  stroke="currentColor" stroke-width="1.6"/>
                            <path d="M8 10V7.5a4 4 0 0 1 8 0V10" fill="none" stroke="currentColor"
                                  stroke-width="1.6" stroke-linecap="round"/>
                        </svg>
                        <input type="password" name="password" id="login-password"
                               autocomplete="current-password" required>
                    </div>
                </div>

                <button type="submit" class="auth-boton">Entrar</button>
            </form>
        </section>
    </div>

    <footer class="auth-footer">
        <p class="auth-footer__marca">Sinergia Facturacion</p>
        <?php
            /* Unico hecho que se afirma: lo que el sistema hace. Sin SLA, sin
               disponibilidad prometida y sin estandares de seguridad. */
        ?>
        <p class="auth-footer__nota">Emision de documentos tributarios electronicos ante el SII</p>
        <p class="auth-footer__legal">&copy; <?= date('Y'); ?> Sinergia</p>
    </footer>

</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
