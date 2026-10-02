<?php
/**
 * Configuracion > Usuarios y permisos.
 *
 * TAREA DE LA PANTALLA, EN UNA FRASE: el dueno de la cuenta da acceso al panel a
 * una persona nueva (y decide que puede hacer), o le corta el acceso a alguien.
 *
 * Recibe: $usuarios (list<array>), $roles (list<array>) y $flash (array|null).
 * Cada usuario trae id, email, rol, estado, activacion_token, activacion_expira
 * y created_at, ordenados por created_at ASC. Sin filtros, sin busqueda, sin
 * paginacion: el handler no los tiene y no se inventan.
 *
 * AHORA SI HAY PERMISOS, Y SON DOS COLUMNAS DISTINTAS (Fase 2).
 *
 *   `rol`     (VARCHAR 'owner'|'colaborador') sigue SIN efecto configurable:
 *             dice QUE ES el usuario. owner bypasea el gate entero.
 *   `rol_id`  (FK a la tabla rol, migracion 042) dice QUE PUEDE HACER un
 *             colaborador. Es el que se asigna aqui y el que lee exigirPermiso().
 *
 * Por eso la tabla muestra las dos: la etiqueta neutra de siempre y, al lado,
 * el rol de permisos -- que puede estar vacio, y eso significa algo (ver el
 * aviso de la columna).
 *
 * TRES ESTADOS VISUALES sobre DOS estados persistidos. El ENUM es
 * 'activo'|'inactivo'; "invitacion pendiente" es una lectura derivada
 * (estado === 'inactivo' && activacion_token no vacio), exactamente la misma
 * condicion que ya usaba la vista. No se crea ningun estado nuevo en la BD.
 *
 * ENLACE DE ACTIVACION: solo llega en $flash['link'], una vez, justo despues de
 * invitar; nunca se reconstruye ni se muestra el token guardado de una
 * invitacion pendiente. Se presenta con .secreto-unico porque es exactamente
 * eso: una cadena sensible, larga, que se ve una sola vez y hay que poder
 * seleccionar entera. El boton "Copiar link" usa el mismo camino doble que
 * apikeys.php (clipboard moderno y execCommand), porque el panel puede correr
 * sobre HTTP plano.
 *
 * UNA SOLA ACCION PRIMARIA: "Invitar usuario". "Crear rol" es secundario: los
 * dos botones azules solidos que habia se anulaban entre si.
 *
 * SOLO SE CONFIRMA LO IRREVERSIBLE (design system, Feedback). Eliminar un rol
 * pide confirmacion con un <dialog> que nombra la accion; desactivar a un
 * usuario NO, porque se deshace con "Reactivar" en la misma fila.
 *
 * ESPERA AL ENVIAR (seccion 14, Efectos de carga): el mismo patron de
 * pagos-config.php en todos los formularios de la pantalla. Ver el script.
 */
$titulo = 'Usuarios';
require __DIR__ . '/partials/header.php';

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

/** Fecha en formato chileno (dd/mm/aaaa). Si no se puede leer, se muestra tal cual. */
$fecha = static function (?string $valor) use ($e): string {
    $ts = $valor !== null && $valor !== '' ? strtotime($valor) : false;
    return $ts === false ? $e((string) $valor) : date('d/m/Y', $ts);
};

/**
 * Estado visual de la fila. Las dos primeras ramas replican la condicion que ya
 * tenia la vista; el default cubre un valor inesperado del ENUM. Cada estado
 * lleva icono propio ademas del color (WCAG 1.4.1).
 */
$estadoUsuario = static function (array $u): array {
    if ($u['estado'] === 'activo') {
        return ['badge--ok', 'Activo', 'check'];
    }
    if ($u['estado'] === 'inactivo' && ! empty($u['activacion_token'])) {
        return ['badge--advertencia', 'Invitacion pendiente', 'reloj'];
    }
    if ($u['estado'] === 'inactivo') {
        return ['badge--neutro', 'Inactivo', 'sin-cargar'];
    }
    return ['badge--neutro', (string) $u['estado'], 'sin-cargar'];
};

/** El tipo de usuario como lo dice una persona, no la clave de la columna `rol`. */
$tipoUsuario = static fn (string $rol): string => match ($rol) {
    'owner'       => 'Dueno de la cuenta',
    'superadmin'  => 'Soporte',
    'colaborador' => 'Colaborador',
    default       => $rol,
};

$flashError = ! empty($flash['mensaje']) && ($flash['tipo'] ?? '') !== 'exito';
?>

<div class="dash-header">
    <div>
        <h1>Usuarios y permisos</h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <a class="boton-secundario" href="/panel">Volver al panel</a>
    </div>
</div>
<p class="dash-subtitulo">
    Invita personas a tu empresa y decide que puede hacer cada una en el panel.
</p>

<?php if (! empty($flash['mensaje'])): ?>
    <?php // Un error pide accion: role="alert". Un exito solo informa: role="status". ?>
    <p class="alerta alerta--<?= $flashError ? 'error' : 'exito'; ?>" role="<?= $flashError ? 'alert' : 'status'; ?>">
        <?= iconoSvg($flashError ? 'alerta' : 'check', 20, 'alerta__icono'); ?>
        <span><?= $e((string) $flash['mensaje']); ?></span>
    </p>
    <?php if (! empty($flash['link'])): ?>
        <section class="tarjeta" aria-labelledby="titulo-link">
            <h2 id="titulo-link">Link de activacion</h2>
            <p class="tarjeta__intro">
                Compartelo con la persona invitada por el canal que prefieras. Desde aqui no volveras a verlo.
            </p>
            <p id="link-activacion" class="secreto-unico"><?= $e((string) $flash['link']); ?></p>
            <div class="acciones-grupo">
                <button type="button" class="boton-secundario" id="copiar-link">Copiar link</button>
                <span class="nota" id="copiar-link-estado" role="status"></span>
            </div>
        </section>
    <?php endif; ?>
<?php endif; ?>

<?php
/* A TODO EL ANCHO, EN DOS FILAS. Arriba, la accion principal (invitar) y su
   explicacion, lado a lado. Abajo, la tabla de usuarios a todo el ancho: con
   seis columnas no cabia en los dos tercios de la columna principal y Estado,
   Alta y Acciones quedaban cortados detras de un scroll horizontal. */
?>
<div class="config-columnas config-columnas--2">
    <section class="tarjeta" aria-labelledby="titulo-invitar">
        <h2 id="titulo-invitar">Invitar usuario</h2>
        <p class="tarjeta__intro">Se genera un link de activacion para que lo compartas a mano.</p>

        <div class="alerta alerta--error" id="resumen-errores" role="alert" tabindex="-1" hidden>
            <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
            <div class="alerta__cuerpo">
                <p class="alerta__titulo" data-resumen-titulo></p>
                <ul class="alerta__lista" data-resumen-lista></ul>
            </div>
        </div>

        <form method="post" action="/configuracion/usuarios" class="form-compacto" id="form-invitar" novalidate data-espera>
            <?= csrfInput(); ?>
            <div class="form-grid form-grid--1">
                <div class="form-campo">
                    <label for="email">Email</label>
                    <input type="email" name="email" id="email" required
                           inputmode="email" autocomplete="off" autocapitalize="off" spellcheck="false"
                           placeholder="nombre@empresa.cl"
                           aria-describedby="ayuda-email error-email">
                    <p class="campo-error" id="error-email" hidden><?= iconoSvg('alerta', 16, ''); ?><span></span></p>
                    <small class="form-ayuda" id="ayuda-email">El correo de la persona que va a entrar al panel.</small>
                </div>
                <div class="form-campo">
                    <label for="rol_id">Rol de permisos</label>
                    <select name="rol_id" id="rol_id" aria-describedby="ayuda-rol_id">
                        <?php foreach ($roles as $r): ?>
                            <option value="<?= (int) $r['id']; ?>"<?= $r['nombre'] === 'Administrador' ? ' selected' : ''; ?>>
                                <?= $e((string) $r['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                        <?php /* La opcion vacia va AL FINAL y no primero: dejar a
                                 alguien sin permisos es una decision, no el camino
                                 por descuido. "Administrador" viene preseleccionado. */ ?>
                        <option value="">Sin rol (no podra entrar a certificacion)</option>
                    </select>
                    <small class="form-ayuda" id="ayuda-rol_id">
                        Se puede cambiar despues. Sin rol, entra al panel pero no a las areas
                        protegidas, como Certificacion SII.
                    </small>
                </div>
            </div>
            <div class="acciones-formulario">
                <button type="submit" class="boton-principal">Invitar usuario</button>
            </div>
        </form>
    </section>

    <div class="panel-info">
        <p class="panel-info__titulo">
            <?= iconoSvg('info', 20, 'panel-info__icono'); ?>
            Como funciona la invitacion
        </p>
        <ul class="panel-info__lista">
            <li>El link de activacion vale 48 horas y sirve una sola vez.</li>
            <li>No se envia por correo: lo copias y lo compartes por el canal que prefieras.</li>
            <li>La persona invitada define su propia contrasena al activarse.</li>
        </ul>
    </div>
</div>

<section class="tarjeta" aria-labelledby="titulo-usuarios">
    <h2 id="titulo-usuarios">Usuarios de tu cuenta</h2>
    <?php if ($usuarios === []): ?>
        <div class="estado-vacio">
            <?= iconoSvg('usuarios', 32, 'estado-vacio__icono'); ?>
            <p class="estado-vacio__titulo">Todavia no hay nadie mas en tu cuenta</p>
            <p>Invita a alguien con su correo para darle acceso al panel.</p>
            <p><a class="boton-secundario" href="#email">Invitar usuario</a></p>
        </div>
    <?php else: ?>
        <div class="tabla-scroll">
            <table class="tabla-datos tabla-datos--cards">
                <caption><?= count($usuarios); ?> usuario<?= count($usuarios) === 1 ? '' : 's'; ?></caption>
                <thead>
                    <tr>
                        <th scope="col">Usuario</th>
                        <th scope="col">Rol de permisos</th>
                        <th scope="col" class="tabla-datos__estado">Estado</th>
                        <th scope="col">Alta</th>
                        <th scope="col" class="tabla-datos__acciones">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($usuarios as $u): ?>
                        <?php
                            $pendienteActivacion = $u['estado'] === 'inactivo' && ! empty($u['activacion_token']);
                            [$claseBadge, $textoBadge, $iconoBadge] = $estadoUsuario($u);
                            $email = (string) $u['email'];
                        ?>
                        <tr<?= $u['estado'] !== 'activo' ? ' class="tabla-datos__fila--inactiva"' : ''; ?>>
                            <?php /* El tipo va bajo el correo y en palabras de persona
                                     ("Dueno de la cuenta"), no como columna con la clave
                                     interna ('owner'): ahorra una columna -- la tabla no
                                     cabia sin scroll -- y saca vocabulario del modelo. */ ?>
                            <td data-label="Usuario">
                                <?= $e($email); ?>
                                <span class="tabla-datos__secundario"><?= $e($tipoUsuario((string) $u['rol'])); ?></span>
                            </td>
                            <td data-label="Rol de permisos">
                                <?php if ($u['rol'] === 'owner' || $u['rol'] === 'superadmin'): ?>
                                    <span class="tabla-datos__secundario">Acceso completo</span>
                                <?php elseif (! empty($u['rol_nombre'])): ?>
                                    <span class="badge badge--etiqueta"><?= $e((string) $u['rol_nombre']); ?></span>
                                <?php else: ?>
                                    <?php /* Sin rol asignado NO es neutro: es 403 en lo ya protegido. */ ?>
                                    <span class="badge badge--advertencia"><?= iconoSvg('alerta', 16, 'badge__icono'); ?>Sin rol</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Estado" class="tabla-datos__estado">
                                <span class="badge <?= $claseBadge; ?>"><?= iconoSvg($iconoBadge, 16, 'badge__icono'); ?><?= $e($textoBadge); ?></span>
                            </td>
                            <td data-label="Alta"><?= $fecha((string) $u['created_at']); ?></td>
                            <td data-label="" class="tabla-datos__acciones">
                                <?php if ($u['estado'] === 'activo'): ?>
                                    <form method="post" action="/configuracion/usuarios/<?= (int) $u['id']; ?>/desactivar" class="form-en-linea" data-espera>
                                        <?= csrfInput(); ?>
                                        <button type="submit" class="boton-texto boton-texto--peligro"
                                                aria-label="Desactivar a <?= $e($email); ?>">Desactivar</button>
                                    </form>
                                <?php elseif (! $pendienteActivacion): ?>
                                    <form method="post" action="/configuracion/usuarios/<?= (int) $u['id']; ?>/activar" class="form-en-linea" data-espera>
                                        <?= csrfInput(); ?>
                                        <button type="submit" class="boton-texto"
                                                aria-label="Reactivar a <?= $e($email); ?>">Reactivar</button>
                                    </form>
                                <?php else: ?>
                                    <span class="tabla-datos__secundario">Reenviar: invitalo de nuevo</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="tarjeta" aria-labelledby="titulo-roles">
    <div class="tarjeta__cabecera">
        <h2 id="titulo-roles">Roles de permisos</h2>
        <a class="boton-secundario" href="/configuracion/roles/nuevo">Crear rol</a>
    </div>
    <p class="tarjeta__intro">
        Un rol es un conjunto de permisos. Solo se aplica a colaboradores: el dueno
        de la cuenta siempre tiene acceso completo.
    </p>

    <?php if ($roles === []): ?>
        <div class="estado-vacio">
            <?= iconoSvg('escudo', 32, 'estado-vacio__icono'); ?>
            <p class="estado-vacio__titulo">Todavia no tienes roles</p>
            <p>Crea uno para decidir que pueden hacer tus colaboradores.</p>
            <p><a class="boton-secundario" href="/configuracion/roles/nuevo">Crear rol</a></p>
        </div>
    <?php else: ?>
        <div class="tabla-scroll">
            <table class="tabla-datos tabla-datos--cards">
                <caption><?= count($roles); ?> rol<?= count($roles) === 1 ? '' : 'es'; ?></caption>
                <thead>
                    <tr>
                        <th scope="col">Nombre</th>
                        <th scope="col" class="tabla-datos__num">Permisos</th>
                        <th scope="col" class="tabla-datos__num">Colaboradores</th>
                        <th scope="col" class="tabla-datos__acciones">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($roles as $r): ?>
                        <?php $nombreRol = (string) $r['nombre']; ?>
                        <tr>
                            <td data-label="Nombre"><?= $e($nombreRol); ?></td>
                            <td data-label="Permisos" class="tabla-datos__num"><?= (int) $r['permisos']; ?></td>
                            <td data-label="Colaboradores" class="tabla-datos__num"><?= (int) $r['usuarios']; ?></td>
                            <td data-label="" class="tabla-datos__acciones">
                                <a class="boton-texto" href="/configuracion/roles/<?= (int) $r['id']; ?>/editar"
                                   aria-label="Editar el rol <?= $e($nombreRol); ?>">Editar</a>
                                <?php if ((int) $r['usuarios'] === 0): ?>
                                    <form method="post" action="/configuracion/roles/<?= (int) $r['id']; ?>/eliminar" class="form-en-linea"
                                          data-espera
                                          data-confirmar-titulo="Eliminar el rol <?= $e($nombreRol); ?>?"
                                          data-confirmar-texto="Se borra con sus <?= (int) $r['permisos']; ?> permisos. Ningun colaborador lo tiene asignado. No se puede deshacer."
                                          data-confirmar-boton="Si, eliminar el rol">
                                        <?= csrfInput(); ?>
                                        <button type="submit" class="boton-texto boton-texto--peligro"
                                                aria-label="Eliminar el rol <?= $e($nombreRol); ?>">Eliminar</button>
                                    </form>
                                <?php else: ?>
                                    <?php /* Sin boton, y no deshabilitado: el handler lo
                                             rechaza igual (y la FK detras), pero ofrecer un
                                             boton que siempre falla es una promesa falsa. */ ?>
                                    <span class="tabla-datos__secundario">En uso: no se puede eliminar</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<dialog class="dialogo-confirmar" id="dialogo-confirmar" aria-labelledby="dialogo-confirmar-titulo" aria-describedby="dialogo-confirmar-texto">
    <h2 class="dialogo-confirmar__titulo" id="dialogo-confirmar-titulo"></h2>
    <p id="dialogo-confirmar-texto"></p>
    <div class="acciones-formulario">
        <button type="button" class="boton-secundario" data-dialogo-volver>Volver</button>
        <button type="button" class="boton-peligro" data-dialogo-confirmar></button>
    </div>
</dialog>

<script>
(function () {
    /* ESPERA AL ENVIAR -- seccion 14 del design system, Efectos de carga. El
       mismo patron de pagos-config.php para cada formulario con data-espera:
       el doble envio se corta en el acto pero sin nada visible; pasados 300 ms
       el boton entra en carga (spinner dentro, mismo ancho, aria-busy en el
       boton y en el formulario); el resultado lo anuncia la pantalla de
       llegada; el foco no se mueve durante la espera. */
    function ocupado(form, si) {
        var boton = form.querySelector('[type="submit"]');
        if (!boton) { return; }
        if (si) {
            boton.setAttribute('data-cargando', 'true');
            boton.setAttribute('aria-busy', 'true');
            form.setAttribute('aria-busy', 'true');
            boton.disabled = true;
        } else {
            boton.removeAttribute('data-cargando');
            boton.removeAttribute('aria-busy');
            form.removeAttribute('aria-busy');
            boton.disabled = false;
        }
    }
    function esperar(form) {
        form.dataset.enviando = '1';
        form._umbral = setTimeout(function () { ocupado(form, true); }, 300);
    }
    /* Volver con "Atras" restaura la pagina tal como quedo: sin esto, con el
       boton girando para siempre. */
    window.addEventListener('pageshow', function (ev) {
        if (!ev.persisted) { return; }
        document.querySelectorAll('form[data-espera]').forEach(function (f) {
            clearTimeout(f._umbral);
            delete f.dataset.enviando;
            delete f.dataset.confirmado;
            ocupado(f, false);
        });
    });

    /* Confirmacion de lo irreversible: <dialog> con titulo, consecuencia y un
       boton que nombra la accion. Escape cierra y el foco vuelve solo. Sin
       soporte de <dialog>, cae al confirm() nativo con el mismo texto. */
    var dialogo = document.getElementById('dialogo-confirmar');
    var pendiente = null;
    function confirmar(form) {
        var titulo = form.getAttribute('data-confirmar-titulo');
        var texto = form.getAttribute('data-confirmar-texto');
        if (!dialogo || typeof dialogo.showModal !== 'function') {
            return window.confirm(titulo + '\n\n' + texto);
        }
        pendiente = form;
        document.getElementById('dialogo-confirmar-titulo').textContent = titulo;
        document.getElementById('dialogo-confirmar-texto').textContent = texto;
        dialogo.querySelector('[data-dialogo-confirmar]').textContent = form.getAttribute('data-confirmar-boton');
        dialogo.showModal();
        // El foco entra en "Volver": un Enter apurado no borra nada.
        dialogo.querySelector('[data-dialogo-volver]').focus();
        return false;
    }
    if (dialogo) {
        dialogo.querySelector('[data-dialogo-volver]').addEventListener('click', function () { dialogo.close(); });
        dialogo.querySelector('[data-dialogo-confirmar]').addEventListener('click', function () {
            var form = pendiente;
            dialogo.close();
            if (form) {
                form.dataset.confirmado = '1';
                esperar(form);
                form.submit();
            }
        });
    }

    /* Validacion del correo: al salir del campo, y desde ahi mientras se
       corrige. Se pasa a minusculas y sin espacios, como pide el design
       system para el correo. */
    var email = document.getElementById('email');
    var tocado = false;
    function errorEmail() {
        var v = email.value.trim();
        if (v === '') { return 'Escribe el correo de la persona que quieres invitar.'; }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) { return 'Escribe un correo completo, por ejemplo nombre@empresa.cl.'; }
        return '';
    }
    function pintarEmail(msg) {
        var err = document.getElementById('error-email');
        err.querySelector('span').textContent = msg;
        err.hidden = msg === '';
        document.getElementById('ayuda-email').hidden = msg !== '';
        email.closest('.form-campo').classList.toggle('form-campo--error', msg !== '');
        if (msg === '') { email.removeAttribute('aria-invalid'); } else { email.setAttribute('aria-invalid', 'true'); }
    }
    if (email) {
        email.addEventListener('blur', function () {
            email.value = email.value.trim().toLowerCase();
            if (email.value === '' && !tocado) { return; }
            tocado = true;
            pintarEmail(errorEmail());
        });
        email.addEventListener('input', function () { if (tocado) { pintarEmail(errorEmail()); } });
    }

    document.querySelectorAll('form[data-espera]').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            if (form.dataset.enviando) { ev.preventDefault(); return; }

            if (form.id === 'form-invitar') {
                email.value = email.value.trim().toLowerCase();
                tocado = true;
                var msg = errorEmail();
                pintarEmail(msg);
                var resumen = document.getElementById('resumen-errores');
                if (msg !== '') {
                    ev.preventDefault();
                    var lista = resumen.querySelector('[data-resumen-lista]');
                    lista.textContent = '';
                    var li = document.createElement('li');
                    var a = document.createElement('a');
                    a.href = '#email';
                    a.textContent = 'Email';
                    li.appendChild(a);
                    li.appendChild(document.createTextNode(': ' + msg));
                    lista.appendChild(li);
                    resumen.querySelector('[data-resumen-titulo]').textContent = 'Hay 1 campo por revisar antes de invitar';
                    resumen.hidden = false;
                    email.focus();
                    return;
                }
                resumen.hidden = true;
            }

            if (form.hasAttribute('data-confirmar-titulo') && !form.dataset.confirmado) {
                if (!confirmar(form)) { ev.preventDefault(); return; }
            }
            esperar(form);
        });
    });

    /* Copiar el link de activacion. navigator.clipboard exige HTTPS o
       localhost; sobre HTTP plano se cae a execCommand. El resultado se dice
       en una region aparte y no en el boton, para que el boton no cambie de
       ancho, y solo se dice "copiado" si de verdad funciono. */
    var copiar = document.getElementById('copiar-link');
    if (copiar) {
        copiar.addEventListener('click', function () {
            var texto = document.getElementById('link-activacion').textContent.trim();
            var estado = document.getElementById('copiar-link-estado');
            function avisar(ok) {
                estado.textContent = ok ? 'Link copiado.' : 'No se pudo copiar: seleccionalo y copialo a mano.';
            }
            function clasico() {
                var t = document.createElement('textarea');
                t.value = texto;
                t.setAttribute('readonly', '');
                t.className = 'visualmente-oculto';
                document.body.appendChild(t);
                t.select();
                var ok = false;
                try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
                document.body.removeChild(t);
                copiar.focus();
                avisar(ok);
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(texto).then(function () { avisar(true); }).catch(clasico);
            } else {
                clasico();
            }
        });
    }
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
