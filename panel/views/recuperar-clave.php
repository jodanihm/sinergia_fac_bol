<?php
/**
 * Recuperar contrasena (migracion 059). Una sola vista para los cuatro pasos:
 *
 *   'solicitar'  formulario con el email                 GET/POST /recuperar
 *   'enviado'    "si el correo existe, te enviamos..."    POST /recuperar
 *   'nueva'      contrasena nueva + confirmacion          GET/POST /recuperar/{token}
 *   'invalido'   enlace vencido, usado o inexistente      GET/POST /recuperar/{token}
 *
 * Recibe: $paso, $email, $errores y, en 'nueva'/'invalido', $token.
 *
 * 'enviado' NUNCA dice si el email existe: el texto es el mismo siempre. Ver la
 * seccion RECUPERAR CONTRASENA de public/index.php.
 *
 * Mismo diseno que el login (.lg-*, partials/_login-marca.php). El script de
 * abajo solo hace cosas locales: mostrar/ocultar, la barra de carga al enviar y,
 * en el paso 'nueva', el medidor de fuerza y el aviso de coincidencia. Esos dos
 * SI leen lo que se escribe, pero solo para pintarlo en esta pagina: no lo
 * guardan ni lo mandan a ningun lado fuera del propio formulario.
 */
$titulo    = 'Recuperar contrasena';
$bodyClase = 'auth-page lg-page';
require __DIR__ . '/partials/header.php';

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$mascotaRuta    = __DIR__ . '/../public/img/sinergin-240.png';
$mascotaVersion = @filemtime($mascotaRuta);
$mascotaSrc     = '/img/sinergin-240.png' . ($mascotaVersion ? '?v=' . $mascotaVersion : '');
$errores = $errores ?? [];
$token   = $token ?? '';

$iconoOjo = '<svg class="lg-ojo__ver" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>'
    . '<svg class="lg-ojo__ocultar" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><line x1="1" y1="1" x2="23" y2="23" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';
$iconoCandado = '<svg class="lg-input__icono" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="4.5" y="10" width="15" height="10" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M8 10V7.5a4 4 0 0 1 8 0V10" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';
?>

<div class="lg-barra" aria-hidden="true"><span class="lg-barra__relleno"></span></div>

<div class="lg">
    <?php require __DIR__ . '/partials/_login-marca.php'; ?>

    <div class="lg-panel">
        <header class="lg-cabecera">
            <a class="lg-cabecera__logo" href="/login" aria-label="Sinergia Facturacion">
                <img src="/img/logo.png" alt="Sinergia Facturacion" width="254" height="80">
            </a>
            <div class="lg-cabecera__acciones">
                <a class="lg-enlace-planes" href="/login">
                    <svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><polyline points="15 18 9 12 15 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Volver al inicio de sesion
                </a>
            </div>
        </header>

        <div class="lg-centro">
            <section class="lg-tarjeta<?= $errores !== [] ? ' lg-tarjeta--error' : ''; ?>" aria-labelledby="titulo-recuperar">

                <?php if ($paso === 'solicitar'): ?>
                    <span class="lg-paso-icono" aria-hidden="true">
                        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
                    </span>
                    <h1 id="titulo-recuperar" class="lg-titulo">Recupera tu contrasena</h1>
                    <p class="lg-bajada">Escribe el email con el que entras. Te enviaremos un enlace para elegir una contrasena nueva.</p>

                    <?php if ($errores !== []): ?>
                        <div class="lg-alerta" role="alert">
                            <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="1.8"/><line x1="12" y1="8" x2="12" y2="12.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="12" cy="16" r="1.1" fill="currentColor"/></svg>
                            <span><?= $e(implode(' ', $errores)); ?></span>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="/recuperar" class="lg-form" data-lg-form>
                        <?= csrfInput(); ?>
                        <div class="lg-campo">
                            <label for="recuperar-email" class="lg-etiqueta">Email</label>
                            <div class="lg-input">
                                <svg class="lg-input__icono" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="3" y="5" width="18" height="14" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="m4 7 8 5.5L20 7" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                <input type="email" name="email" id="recuperar-email" autocomplete="email" placeholder="tu@empresa.cl"
                                       spellcheck="false" value="<?= $e((string) $email); ?>" required autofocus>
                            </div>
                        </div>
                        <button type="submit" class="lg-boton" data-lg-boton>
                            <span class="lg-boton__texto">Enviarme el enlace</span>
                            <span class="lg-boton__espera" aria-hidden="true"><span class="lg-spinner"></span>Enviando</span>
                            <span class="lg-boton__progreso" aria-hidden="true"></span>
                        </button>
                        <p class="visualmente-oculto" data-lg-estado aria-live="polite"></p>
                    </form>
                    <p class="lg-nota">Te acordaste? <a href="/login">Vuelve a iniciar sesion</a></p>

                <?php elseif ($paso === 'enviado'): ?>
                    <span class="lg-paso-icono lg-paso-icono--exito" aria-hidden="true">
                        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" focusable="false"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 7L2 7"/></svg>
                    </span>
                    <h1 id="titulo-recuperar" class="lg-titulo" tabindex="-1">Revisa tu correo</h1>
                    <div role="status">
                        <p class="lg-bajada">Si <strong><?= $e((string) $email); ?></strong> corresponde a una cuenta, te enviamos un enlace para elegir una contrasena nueva.</p>
                    </div>
                    <ul class="lg-pasos">
                        <li><span>1</span>Abre el correo de Sinergia Facturacion (revisa tambien spam).</li>
                        <li><span>2</span>Presiona "Elegir una contrasena nueva". El enlace vence en 60 minutos.</li>
                        <li><span>3</span>Vuelve a entrar con tu contrasena nueva.</li>
                    </ul>
                    <a class="lg-boton lg-boton--secundario" href="/login">Volver al inicio de sesion</a>
                    <p class="lg-nota">No llego? <a href="/recuperar">Pide otro enlace</a></p>

                <?php elseif ($paso === 'nueva'): ?>
                    <span class="lg-paso-icono" aria-hidden="true">
                        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
                    </span>
                    <h1 id="titulo-recuperar" class="lg-titulo">Elige una contrasena nueva</h1>
                    <p class="lg-bajada">Para <strong><?= $e((string) $email); ?></strong>. Usa al menos 8 caracteres.</p>

                    <?php if ($errores !== []): ?>
                        <div class="lg-alerta" role="alert">
                            <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="1.8"/><line x1="12" y1="8" x2="12" y2="12.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="12" cy="16" r="1.1" fill="currentColor"/></svg>
                            <span><?= $e(implode(' ', $errores)); ?></span>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="/recuperar/<?= $e($token); ?>" class="lg-form" data-lg-form>
                        <?= csrfInput(); ?>
                        <?php /* Usuario oculto para que el gestor de contrasenas asocie la
                                 nueva con la cuenta correcta. No viaja con name: no se envia. */ ?>
                        <input type="email" autocomplete="username" value="<?= $e((string) $email); ?>" class="visualmente-oculto" tabindex="-1" aria-hidden="true" readonly>

                        <div class="lg-campo">
                            <label for="nueva-clave" class="lg-etiqueta">Contrasena nueva</label>
                            <div class="lg-input lg-input--clave">
                                <?= $iconoCandado; ?>
                                <input type="password" name="password" id="nueva-clave" autocomplete="new-password"
                                       minlength="8" required autofocus aria-describedby="fuerza-texto">
                                <button type="button" class="lg-ojo" data-lg-ojo="nueva-clave" aria-controls="nueva-clave"
                                        aria-pressed="false" aria-label="Mostrar contrasena" hidden><?= $iconoOjo; ?></button>
                            </div>
                            <div class="lg-fuerza" data-lg-fuerza aria-hidden="true"><span></span><span></span><span></span><span></span></div>
                            <p class="lg-fuerza__texto" id="fuerza-texto" aria-live="polite">Minimo 8 caracteres.</p>
                        </div>

                        <div class="lg-campo">
                            <label for="nueva-clave-2" class="lg-etiqueta">Repite la contrasena</label>
                            <div class="lg-input lg-input--clave">
                                <?= $iconoCandado; ?>
                                <input type="password" name="password_confirmacion" id="nueva-clave-2" autocomplete="new-password"
                                       minlength="8" required aria-describedby="coincide-texto">
                                <button type="button" class="lg-ojo" data-lg-ojo="nueva-clave-2" aria-controls="nueva-clave-2"
                                        aria-pressed="false" aria-label="Mostrar contrasena" hidden><?= $iconoOjo; ?></button>
                            </div>
                            <p class="lg-coincide" id="coincide-texto" aria-live="polite"></p>
                        </div>

                        <button type="submit" class="lg-boton" data-lg-boton>
                            <span class="lg-boton__texto">Guardar contrasena</span>
                            <span class="lg-boton__espera" aria-hidden="true"><span class="lg-spinner"></span>Guardando</span>
                            <span class="lg-boton__progreso" aria-hidden="true"></span>
                        </button>
                        <p class="visualmente-oculto" data-lg-estado aria-live="polite"></p>
                    </form>

                <?php else: /* invalido */ ?>
                    <span class="lg-paso-icono lg-paso-icono--alerta" aria-hidden="true">
                        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" focusable="false"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </span>
                    <h1 id="titulo-recuperar" class="lg-titulo">Este enlace ya no sirve</h1>
                    <p class="lg-bajada">Los enlaces para recuperar la contrasena vencen a los 60 minutos y se pueden usar una sola vez. Pide uno nuevo y usalo apenas te llegue.</p>
                    <a class="lg-boton" href="/recuperar">Pedir un enlace nuevo</a>
                    <p class="lg-nota"><a href="/login">Volver al inicio de sesion</a></p>
                <?php endif; ?>

            </section>
        </div>

        <footer class="lg-pie">
            <span>Emision de documentos tributarios electronicos ante el SII</span>
            <span>&copy; <?= date('Y'); ?> Sinergia</span>
        </footer>
    </div>
</div>

<script>
(function () {
    'use strict';
    var barra = document.querySelector('.lg-barra');

    // Mostrar / ocultar cada contrasena.
    document.querySelectorAll('[data-lg-ojo]').forEach(function (ojo) {
        var campo = document.getElementById(ojo.getAttribute('data-lg-ojo'));
        if (!campo) { return; }
        ojo.hidden = false;
        ojo.addEventListener('click', function () {
            var ver = campo.type === 'password';
            campo.type = ver ? 'text' : 'password';
            ojo.setAttribute('aria-pressed', ver ? 'true' : 'false');
            ojo.setAttribute('aria-label', ver ? 'Ocultar contrasena' : 'Mostrar contrasena');
            campo.focus();
        });
    });

    // Medidor de fuerza y coincidencia (solo en el paso 'nueva'). Calculo local:
    // largo y variedad de caracteres. Es una guia, no una regla: la unica regla
    // que valida el servidor es el minimo de 8.
    var clave = document.getElementById('nueva-clave');
    var clave2 = document.getElementById('nueva-clave-2');
    var fuerza = document.querySelector('[data-lg-fuerza]');
    var fuerzaTexto = document.getElementById('fuerza-texto');
    var coincide = document.getElementById('coincide-texto');
    function nivel(v) {
        if (v.length < 8) { return 0; }
        var n = 1;
        if (v.length >= 12) { n++; }
        var tipos = (/[a-z]/.test(v) ? 1 : 0) + (/[A-Z]/.test(v) ? 1 : 0) + (/[0-9]/.test(v) ? 1 : 0) + (/[^A-Za-z0-9]/.test(v) ? 1 : 0);
        if (tipos >= 3) { n++; }
        if (v.length >= 14 && tipos >= 3) { n++; }
        return Math.min(n, 4);
    }
    var textos = ['Minimo 8 caracteres.', 'Aceptable. Mas largo es mas seguro.', 'Buena.', 'Muy buena.', 'Excelente.'];
    function revisar() {
        if (!clave) { return; }
        var v = clave.value;
        var n = v.length === 0 ? 0 : nivel(v);
        fuerza.setAttribute('data-nivel', v.length === 0 ? '' : String(n));
        fuerzaTexto.textContent = v.length > 0 && v.length < 8 ? 'Faltan ' + (8 - v.length) + ' caracteres.' : textos[n];
        if (clave2 && clave2.value.length > 0) {
            var ok = clave2.value === v;
            coincide.textContent = ok ? 'Las contrasenas coinciden.' : 'Todavia no coinciden.';
            coincide.className = 'lg-coincide ' + (ok ? 'lg-coincide--ok' : 'lg-coincide--no');
        } else if (coincide) {
            coincide.textContent = '';
            coincide.className = 'lg-coincide';
        }
    }
    if (clave) {
        clave.addEventListener('input', revisar);
        if (clave2) { clave2.addEventListener('input', revisar); }
    }

    // Envio: corta el doble envio y muestra la espera con la barra de carga.
    document.querySelectorAll('[data-lg-form]').forEach(function (form) {
        var boton = form.querySelector('[data-lg-boton]');
        var estado = form.querySelector('[data-lg-estado]');
        var enviando = false;
        function enEspera(si) {
            boton.classList.toggle('lg-boton--cargando', si);
            boton.setAttribute('aria-busy', si ? 'true' : 'false');
            boton.disabled = si;
            if (barra) { barra.classList.toggle('lg-barra--enviando', si); }
            if (estado) { estado.textContent = si ? 'Procesando' : ''; }
        }
        form.addEventListener('submit', function (ev) {
            if (enviando) { ev.preventDefault(); return; }
            if (clave && clave2 && clave.value !== clave2.value) {
                ev.preventDefault();
                revisar();
                clave2.focus();
                return;
            }
            enviando = true;
            form.querySelectorAll('[data-lg-ojo]').forEach(function (o) {
                var c = document.getElementById(o.getAttribute('data-lg-ojo'));
                if (c) { c.type = 'password'; }
            });
            setTimeout(function () { enEspera(true); }, 0);
        });
        window.addEventListener('pageshow', function (ev) {
            if (ev.persisted) { enviando = false; enEspera(false); }
        });
    });
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
