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
 * despues de un error -- igual que antes -- y no aparece en ningun atributo.
 *
 * JAVASCRIPT (rediseno 2026-11-18). Antes esta pantalla no tenia; ahora lleva
 * un script corto, en linea, que NUNCA lee ni envia el valor de ningun campo:
 *   - mostrar/ocultar contrasena: solo cambia el type del input (y lo vuelve a
 *     'password' antes de enviar, para que el gestor de credenciales lo trate
 *     como contrasena);
 *   - aviso de Bloq Mayus: getModifierState('CapsLock') del evento de teclado,
 *     sin mirar la tecla ni el contenido;
 *   - barra de carga y boton en espera al enviar, con corte de doble envio.
 * Sin JavaScript el formulario funciona exactamente igual que antes.
 *
 * TODOS LOS <svg> LLEVAN width Y height COMO ATRIBUTO, ademas de la regla CSS.
 * No es redundancia: un <svg> con viewBox pero SIN dimensiones intrinsecas se
 * estira hasta llenar su contenedor cuando el CSS no llega. Medido: sin hoja de
 * estilos estos mismos iconos renderizaban a 1409x1409 px en un viewport de
 * 1440 -- que es como se vieron en produccion la primera vez. Con el atributo,
 * el peor caso posible es un icono de 20px; el CSS solo lo afina.
 *
 * La <img> de la mascota lleva width y height por la misma razon, y ademas
 * reserva el espacio antes de que la imagen cargue (CLS).
 *
 * CLASES .lg-* PROPIAS. Las .auth-* las siguen usando pago-retorno.php y
 * panel-gestion.php, asi que no se tocan.
 *
 * RECUPERAR CONTRASENA: enlace "Olvidaste tu contrasena?" a /recuperar
 * (migracion 059). Tras fijar la nueva se vuelve aqui con ?clave=actualizada,
 * que solo enciende un aviso de texto fijo.
 *
 * NO HAY ENLACE A /registro. Es una decision de producto: el alta de tenants no
 * es autoregistro publico. La RUTA sigue existiendo y funcionando igual por URL
 * directa; aqui solo deja de ofrecerse.
 *
 *
 * $bodyClase la lee partials/header.php: anula el max-width global del <body>
 * para que esta pantalla ocupe el ancho completo.
 */
$titulo    = 'Iniciar sesion';
$bodyClase = 'auth-page lg-page';
require __DIR__ . '/partials/header.php';

// Version del archivo, para invalidar la cache del navegador tras un
// despliegue. Mismo criterio que /css/style.css en partials/header.php.
$mascotaRuta    = __DIR__ . '/../public/img/sinergin-240.png';
$mascotaVersion = @filemtime($mascotaRuta);
$mascotaSrc     = '/img/sinergin-240.png' . ($mascotaVersion ? '?v=' . $mascotaVersion : '');
$hayError       = ! empty($error);
?>

<?php /* Barra de carga superior: corre sola al cargar la pagina (CSS) y la
         retoma el script al enviar el formulario. Decorativa: el estado real
         lo anuncia el boton (aria-busy) y, al volver, el mensaje de error. */ ?>
<div class="lg-barra" aria-hidden="true"><span class="lg-barra__relleno"></span></div>

<div class="lg">

    <?php require __DIR__ . '/partials/_login-marca.php'; ?>

    <div class="lg-panel">
        <header class="lg-cabecera">
            <a class="lg-cabecera__logo" href="/login" aria-label="Sinergia Facturacion">
                <img src="/img/logo.png" alt="Sinergia Facturacion" width="254" height="80">
            </a>
            <?php /* Describe lo que es la pantalla (un area que pide credenciales), no
                     una certificacion ni una promesa de cifrado. */ ?>
            <div class="lg-cabecera__acciones">
                <span class="lg-chip">
                    <svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 2 4 5v6c0 5 3.4 9.4 8 11 4.6-1.6 8-6 8-11V5l-8-3z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="m9 12 2 2 4-4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Acceso privado
                </span>
                <?php /* Abre en pestana nueva: planes.html no tiene navegacion de vuelta. */ ?>
                <a class="lg-enlace-planes" href="/planes.html" target="_blank" rel="noopener">Ver planes<span class="visualmente-oculto"> (se abre en otra pestana)</span>
                    <svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7 17 17 7M9 7h8v8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </a>
            </div>
        </header>

        <div class="lg-centro">
            <section class="lg-tarjeta<?= $hayError ? ' lg-tarjeta--error' : ''; ?>" aria-labelledby="titulo-login">
                <h1 id="titulo-login" class="lg-titulo">Bienvenido de vuelta</h1>
                <p class="lg-bajada">Ingresa a tu plataforma de facturacion Sinergia.</p>

                <?php if (! $hayError && ($_GET['sesion'] ?? '') === 'cerrada'): ?>
                    <?php /* Llega aqui desde /logout. Texto fijo segun un valor fijo de la url. */ ?>
                    <div class="lg-despedida" role="status">
                        <span class="lg-despedida__mano" aria-hidden="true">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M18 11V6a2 2 0 0 0-4 0v5"/><path d="M14 10V4a2 2 0 0 0-4 0v6"/><path d="M10 10.5V6a2 2 0 0 0-4 0v8"/><path d="M18 8a2 2 0 1 1 4 0v6a8 8 0 0 1-8 8h-2c-2.8 0-4.5-.86-5.99-2.34l-3.6-3.6a2 2 0 0 1 2.83-2.82L7 15"/></svg>
                        </span>
                        <div>
                            <strong>Cerraste sesion. Hasta pronto!</strong>
                            <span>Tu sesion quedo cerrada en este navegador.</span>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (! $hayError && ($_GET['clave'] ?? '') === 'actualizada'): ?>
                    <?php /* Llega aqui tras /recuperar/{token}. Solo un texto fijo segun un
                             valor fijo de la url: nada de lo que viene en ella se imprime. */ ?>
                    <div class="lg-alerta lg-alerta--exito" role="status">
                        <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="1.8"/><polyline points="8 12.5 11 15.5 16 9.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span>Listo, tu contrasena se actualizo. Ingresa con la nueva.</span>
                    </div>
                <?php endif; ?>

                <?php if ($hayError): ?>
                    <?php
                        /* Se imprime el mensaje TEXTUAL que manda handleLoginPost(), que es
                           generico a proposito: no revela si el email existe. role="alert"
                           hace que un lector de pantalla lo anuncie al re-renderizar. */
                    ?>
                    <div class="lg-alerta" role="alert">
                        <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="1.8"/><line x1="12" y1="8" x2="12" y2="12.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="12" cy="16" r="1.1" fill="currentColor"/></svg>
                        <span><?= htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>

                <form method="post" action="/login" class="lg-form" id="lg-form">
                    <?= csrfInput(); ?>

                    <div class="lg-campo">
                        <label for="login-email" class="lg-etiqueta">Email</label>
                        <div class="lg-input">
                            <svg class="lg-input__icono" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <rect x="3" y="5" width="18" height="14" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.6"/>
                                <path d="m4 7 8 5.5L20 7" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <input type="email" name="email" id="login-email" autocomplete="email"
                                   placeholder="tu@empresa.cl" spellcheck="false"
                                   value="<?= htmlspecialchars($email); ?>" required<?= $email === '' ? ' autofocus' : ''; ?>>
                        </div>
                    </div>

                    <div class="lg-campo">
                        <div class="lg-etiqueta-fila">
                            <label for="login-password" class="lg-etiqueta">Contrasena</label>
                            <a class="lg-olvide" href="/recuperar">Olvidaste tu contrasena?</a>
                        </div>
                        <div class="lg-input lg-input--clave">
                            <svg class="lg-input__icono" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <rect x="4.5" y="10" width="15" height="10" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.6"/>
                                <path d="M8 10V7.5a4 4 0 0 1 8 0V10" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                            </svg>
                            <input type="password" name="password" id="login-password"
                                   autocomplete="current-password" placeholder="Tu contrasena"
                                   aria-describedby="lg-mayus" required<?= $email !== '' ? ' autofocus' : ''; ?>>
                            <?php /* Sin JS queda oculto (hidden): no hay boton muerto. */ ?>
                            <button type="button" class="lg-ojo" id="lg-ojo" aria-controls="login-password"
                                    aria-pressed="false" aria-label="Mostrar contrasena" hidden>
                                <svg class="lg-ojo__ver" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>
                                <svg class="lg-ojo__ocultar" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><line x1="1" y1="1" x2="23" y2="23" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                            </button>
                        </div>
                        <p class="lg-mayus" id="lg-mayus" aria-live="polite"></p>
                    </div>

                    <button type="submit" class="lg-boton" id="lg-boton">
                        <span class="lg-boton__texto">Entrar</span>
                        <span class="lg-boton__espera" aria-hidden="true">
                            <span class="lg-spinner"></span>Verificando tus datos
                        </span>
                        <svg class="lg-boton__flecha" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><line x1="5" y1="12" x2="19" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><polyline points="12 5 19 12 12 19" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span class="lg-boton__progreso" aria-hidden="true"></span>
                    </button>
                    <p class="visualmente-oculto" id="lg-estado" aria-live="polite"></p>
                </form>
            </section>
        </div>

        <footer class="lg-pie">
            <?php /* Unico hecho que se afirma: lo que el sistema hace. Sin SLA ni
                     estandares de seguridad. */ ?>
            <span>Emision de documentos tributarios electronicos ante el SII</span>
            <span>&copy; <?= date('Y'); ?> Sinergia</span>
        </footer>
    </div>
</div>

<script>
(function () {
    'use strict';
    /* Ver el docblock: este script NUNCA lee .value de ningun campo. */
    var form = document.getElementById('lg-form');
    var clave = document.getElementById('login-password');
    var ojo = document.getElementById('lg-ojo');
    var mayus = document.getElementById('lg-mayus');
    var boton = document.getElementById('lg-boton');
    var estado = document.getElementById('lg-estado');
    var barra = document.querySelector('.lg-barra');
    if (!form || !clave || !boton) { return; }

    // Mostrar / ocultar contrasena.
    if (ojo) {
        ojo.hidden = false;
        ojo.addEventListener('click', function () {
            var ver = clave.type === 'password';
            clave.type = ver ? 'text' : 'password';
            ojo.setAttribute('aria-pressed', ver ? 'true' : 'false');
            ojo.setAttribute('aria-label', ver ? 'Ocultar contrasena' : 'Mostrar contrasena');
            clave.focus();
        });
    }

    // Aviso de Bloq Mayus: solo el estado del modificador, nunca la tecla.
    function revisarMayus(ev) {
        if (!ev.getModifierState) { return; }
        var activo = ev.getModifierState('CapsLock');
        mayus.textContent = activo ? 'Bloq Mayus esta activado' : '';
        mayus.classList.toggle('lg-mayus--visible', activo);
    }
    clave.addEventListener('keydown', revisarMayus);
    clave.addEventListener('keyup', revisarMayus);
    clave.addEventListener('blur', function () { mayus.textContent = ''; mayus.classList.remove('lg-mayus--visible'); });

    // Envio: corta el doble envio, muestra la espera y la barra de carga.
    var enviando = false;
    function enEspera(si) {
        boton.classList.toggle('lg-boton--cargando', si);
        boton.setAttribute('aria-busy', si ? 'true' : 'false');
        boton.disabled = si;
        if (barra) { barra.classList.toggle('lg-barra--enviando', si); }
        estado.textContent = si ? 'Verificando tus datos' : '';
    }
    form.addEventListener('submit', function (ev) {
        if (enviando) { ev.preventDefault(); return; }
        // El navegador ya valido required/type=email antes de este evento.
        enviando = true;
        clave.type = 'password';
        if (ojo) { ojo.setAttribute('aria-pressed', 'false'); ojo.setAttribute('aria-label', 'Mostrar contrasena'); }
        // disabled va DESPUES de que el navegador arme el envio: un boton
        // deshabilitado en el mismo tick puede cancelar el submit en Safari.
        setTimeout(function () { enEspera(true); }, 0);
    });
    // Volver con "Atras" (bfcache) restaura la pantalla sin la espera colgada.
    window.addEventListener('pageshow', function (ev) {
        if (ev.persisted) { enviando = false; enEspera(false); }
    });
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
