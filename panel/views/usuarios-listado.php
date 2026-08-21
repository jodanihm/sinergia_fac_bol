<?php
/**
 * Configuracion > Usuarios y permisos.
 *
 * Recibe: $usuarios (list<array>) y $flash (array|null).
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
 * seleccionar entera. Su duracion (48 horas) y su uso unico los afirma el
 * propio mensaje del backend, que se muestra textual.
 *
 * No hay JavaScript en esta vista y no se agrega ninguno.
 */
$titulo = 'Usuarios';
require __DIR__ . '/partials/header.php';

/**
 * Estado visual de la fila. Las dos primeras ramas replican la condicion que ya
 * tenia la vista; el default cubre un valor inesperado del ENUM.
 */
$estadoUsuario = static function (array $u): array {
    if ($u['estado'] === 'activo') {
        return ['badge--ok', 'Activo'];
    }
    if ($u['estado'] === 'inactivo' && ! empty($u['activacion_token'])) {
        return ['badge--advertencia', 'Invitacion pendiente'];
    }
    if ($u['estado'] === 'inactivo') {
        return ['badge--neutro', 'Inactivo'];
    }
    return ['badge--neutro', (string) $u['estado']];
};

$req = '<span class="campo-obligatorio" aria-hidden="true">*</span>'
    . '<span class="visualmente-oculto">(obligatorio)</span>';
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
    Invita personas a tu empresa y administra su acceso al panel.
</p>

<?php if (! empty($flash['mensaje'])): ?>
    <p class="alerta alerta--<?= ($flash['tipo'] ?? '') === 'exito' ? 'exito' : 'error'; ?>" role="status">
        <span class="alerta__icono" aria-hidden="true"><?= ($flash['tipo'] ?? '') === 'exito' ? '&#10003;' : '&#9888;'; ?></span>
        <span><?= htmlspecialchars($flash['mensaje']); ?></span>
    </p>
    <?php if (! empty($flash['link'])): ?>
        <section class="tarjeta" aria-labelledby="titulo-link">
            <h2 id="titulo-link">Link de activacion</h2>
            <p class="secreto-unico"><?= htmlspecialchars($flash['link']); ?></p>
            <p class="nota">Compartelo con la persona invitada por el canal que prefieras.
            Desde aqui no volveras a verlo.</p>
        </section>
    <?php endif; ?>
<?php endif; ?>

<div class="layout-principal-lateral">
    <div>
        <section class="tarjeta" aria-labelledby="titulo-usuarios">
            <h2 id="titulo-usuarios">Usuarios de tu cuenta</h2>
            <?php if ($usuarios === []): ?>
                <div class="estado-vacio">
                    <h2>Aun no hay usuarios registrados</h2>
                    <p>Invita a alguien con su correo para darle acceso al panel.</p>
                </div>
            <?php else: ?>
                <div class="tabla-scroll">
                    <table class="tabla-datos">
                        <caption><?= count($usuarios); ?> usuario<?= count($usuarios) === 1 ? '' : 's'; ?></caption>
                        <thead>
                            <tr>
                                <th>Email</th>
                                <th>Tipo</th>
                                <th>Rol de permisos</th>
                                <th class="tabla-datos__estado">Estado</th>
                                <th>Alta</th>
                                <th class="tabla-datos__acciones">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($usuarios as $u): ?>
                                <?php
                                    $pendienteActivacion = $u['estado'] === 'inactivo' && ! empty($u['activacion_token']);
                                    [$claseBadge, $textoBadge] = $estadoUsuario($u);
                                ?>
                                <tr<?= $u['estado'] !== 'activo' ? ' class="tabla-datos__fila--inactiva"' : ''; ?>>
                                    <td><?= htmlspecialchars((string) $u['email']); ?></td>
                                    <td><span class="badge badge--etiqueta"><?= htmlspecialchars((string) $u['rol']); ?></span></td>
                                    <td>
                                        <?php if ($u['rol'] === 'owner' || $u['rol'] === 'superadmin'): ?>
                                            <span class="tabla-datos__secundario">acceso completo</span>
                                        <?php elseif (! empty($u['rol_nombre'])): ?>
                                            <span class="badge badge--ok"><?= htmlspecialchars((string) $u['rol_nombre']); ?></span>
                                        <?php else: ?>
                                            <?php /* Sin rol asignado NO es neutro: es 403 en lo ya protegido. */ ?>
                                            <span class="badge badge--advertencia">Sin rol</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="tabla-datos__estado"><span class="badge <?= $claseBadge; ?>"><?= htmlspecialchars($textoBadge); ?></span></td>
                                    <td><?= htmlspecialchars((string) $u['created_at']); ?></td>
                                    <td class="tabla-datos__acciones">
                                        <?php if ($u['estado'] === 'activo'): ?>
                                            <form method="post" action="/configuracion/usuarios/<?= (int) $u['id']; ?>/desactivar" style="display:inline;">
                                                <?= csrfInput(); ?>
                                                <button type="submit" class="boton-texto">Desactivar</button>
                                            </form>
                                        <?php elseif (! $pendienteActivacion): ?>
                                            <form method="post" action="/configuracion/usuarios/<?= (int) $u['id']; ?>/activar" style="display:inline;">
                                                <?= csrfInput(); ?>
                                                <button type="submit" class="boton-texto">Reactivar</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="tabla-datos__secundario">invita de nuevo arriba para reenviar el link</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div>
        <div class="panel-info">
            <p class="panel-info__titulo">
                <span class="panel-info__icono" aria-hidden="true">&#9432;</span>
                Como funciona la invitacion
            </p>
            <ul class="panel-info__lista">
                <li>Se genera un link de activacion valido 48 horas y de un solo uso.</li>
                <li>No se envia por correo: lo copias y lo compartes por el canal que prefieras.</li>
                <li>La persona invitada define su propia contrasena al activarse.</li>
                <li>El rol de permisos se elige al invitar y se puede cambiar despues.</li>
            </ul>
        </div>

        <section class="tarjeta" aria-labelledby="titulo-invitar">
            <h2 id="titulo-invitar">Invitar usuario</h2>
            <form method="post" action="/configuracion/usuarios" class="form-compacto">
                <?= csrfInput(); ?>
                <div class="form-grid form-grid--1">
                    <div class="form-campo">
                        <label for="email">Email <?= $req; ?></label>
                        <input type="email" name="email" id="email" required>
                        <small class="form-ayuda">Se genera un link de activacion para compartir a mano.</small>
                    </div>
                    <div class="form-campo">
                        <label for="rol_id">Rol de permisos</label>
                        <select name="rol_id" id="rol_id">
                            <?php foreach ($roles as $r): ?>
                                <option value="<?= (int) $r['id']; ?>"<?= $r['nombre'] === 'Administrador' ? ' selected' : ''; ?>>
                                    <?= htmlspecialchars($r['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                            <?php /* La opcion vacia va AL FINAL y no primero: dejar a
                                     alguien sin permisos es una decision, no el camino
                                     por descuido. "Administrador" viene preseleccionado. */ ?>
                            <option value="">Sin rol (no podra entrar a certificacion)</option>
                        </select>
                        <small class="form-ayuda">
                            Sin rol, el colaborador entra al panel pero recibe un error de
                            permisos en las areas ya protegidas &mdash; hoy, Certificacion SII.
                        </small>
                    </div>
                </div>
                <div class="acciones-grupo">
                    <button type="submit" class="boton-principal">Invitar</button>
                </div>
            </form>
        </section>
    </div>
</div>

<section class="tarjeta" aria-labelledby="titulo-roles">
    <div class="dash-header">
        <div>
            <h2 id="titulo-roles">Roles de permisos</h2>
        </div>
        <div class="acciones-grupo acciones-grupo--header">
            <a class="boton-principal" href="/configuracion/roles/nuevo">Nuevo rol</a>
        </div>
    </div>
    <p class="nota">
        Un rol es un conjunto de permisos. Solo se aplica a colaboradores: el dueno
        de la cuenta siempre tiene acceso completo.
    </p>

    <?php if ($roles === []): ?>
        <div class="estado-vacio">
            <h2>Aun no hay roles</h2>
            <p>Crea uno para poder asignarlo a tus colaboradores.</p>
        </div>
    <?php else: ?>
        <div class="tabla-scroll">
            <table class="tabla-datos">
                <caption><?= count($roles); ?> rol<?= count($roles) === 1 ? '' : 'es'; ?></caption>
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th class="tabla-datos__num">Permisos</th>
                        <th class="tabla-datos__num">Colaboradores</th>
                        <th class="tabla-datos__acciones">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($roles as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['nombre']); ?></td>
                            <td class="tabla-datos__num"><?= (int) $r['permisos']; ?></td>
                            <td class="tabla-datos__num"><?= (int) $r['usuarios']; ?></td>
                            <td class="tabla-datos__acciones">
                                <a class="boton-texto" href="/configuracion/roles/<?= (int) $r['id']; ?>/editar">Editar</a>
                                <?php if ((int) $r['usuarios'] === 0): ?>
                                    <form method="post" action="/configuracion/roles/<?= (int) $r['id']; ?>/eliminar" style="display:inline;">
                                        <?= csrfInput(); ?>
                                        <button type="submit" class="boton-texto">Eliminar</button>
                                    </form>
                                <?php else: ?>
                                    <?php /* Sin boton, y no deshabilitado: el handler lo
                                             rechaza igual (y la FK detras), pero ofrecer un
                                             boton que siempre falla es una promesa falsa. */ ?>
                                    <span class="tabla-datos__secundario">en uso</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
