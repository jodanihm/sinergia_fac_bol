<?php
/**
 * Configuracion > Usuarios y permisos > Rol (alta y edicion).
 *
 * TAREA DE LA PANTALLA, EN UNA FRASE: el dueno nombra un rol y marca que puede
 * hacer en cada parte del panel quien lo tenga.
 *
 * Recibe: $rol (array{id,nombre,permisos}|null -- null = alta) y $flash.
 *
 * EL ORDEN SIGUE AL TRABAJO: primero el nombre (con la explicacion de las
 * acciones al lado, que es lo que hay que saber antes de marcar), despues la
 * matriz a todo el ancho, y al final la botonera. Antes el nombre y el boton
 * de guardar vivian en la columna lateral, a la derecha de la matriz: el
 * recorrido terminaba arriba a la derecha en vez de abajo.
 *
 * LA MATRIZ NO ES UN WIDGET NUEVO. Es la misma tabla.tabla-datos con una
 * casilla por celda que ya usa facturacion-masiva-form.php para elegir notas, y
 * el "marcar toda la fila" es el mismo gesto que su "marcar-todas". Se reusa el
 * patron -- y sus clases -- en vez de inventar un editor de permisos. En movil
 * pasa a tarjetas (.tabla-datos--cards): cada modulo es una tarjeta con sus
 * tres casillas rotuladas.
 *
 * LOS NOMBRES DE CAMPO SON permisos[modulo][accion]: PHP los recibe como
 * arreglo anidado y permisosDesdePost() los filtra contra el catalogo. Una
 * casilla sin marcar NO viaja en el POST -- por eso el formulario manda el
 * estado COMPLETO de la matriz y el handler borra y reinserta, en vez de
 * intentar un diff con lo que llego.
 *
 * SIN JAVASCRIPT OBLIGATORIO. El script del final agrega el atajo de marcar
 * una fila entera, la validacion del nombre al salir del campo y la espera al
 * guardar (seccion 14); sin el, la pantalla funciona igual y el servidor sigue
 * validando el nombre.
 */
$esAlta = $rol === null;
$titulo = $esAlta ? 'Nuevo rol' : 'Editar rol';
require __DIR__ . '/partials/header.php';

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$accion = $esAlta
    ? '/configuracion/roles/nuevo'
    : '/configuracion/roles/' . (int) $rol['id'] . '/editar';

$tiene = static function (string $modulo, string $acc) use ($rol): bool {
    return $rol !== null && isset($rol['permisos'][$modulo . ':' . $acc]);
};

/* Etiquetas legibles. El catalogo es tecnico ('datos_maestros' no existe aqui,
   pero 'config' y 'certificacion' si necesitan explicarse): la pantalla dice
   que significa cada modulo, no repite su clave. */
$glosaModulo = [
    'ventas'        => 'Ventas (emision, cotizaciones, carga masiva)',
    'compras'       => 'Compras (ordenes y proveedores)',
    'maestros'      => 'Maestros (clientes y productos)',
    'informes'      => 'Informes',
    'config'        => 'Configuracion de empresa, certificado, folios y API keys',
    'certificacion' => 'Certificacion ante el SII',
    'usuarios'      => 'Usuarios',
    'auditoria'     => 'Auditoria',
    'chat'          => 'Asistente IA',
    'dashboard'     => 'Dashboard',
];
/* Las acciones del catalogo, como las lee una persona. */
$glosaAccion = [
    'ver'       => 'Ver',
    'gestionar' => 'Gestionar',
    'emitir'    => 'Emitir',
];

$flashError = ! empty($flash['mensaje']) && ($flash['tipo'] ?? '') !== 'exito';
?>

<div class="dash-header">
    <div>
        <h1><?= $e($titulo); ?></h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <a class="boton-secundario" href="/configuracion/usuarios">Volver a usuarios</a>
    </div>
</div>
<p class="dash-subtitulo">
    Elige que puede hacer quien tenga este rol. El dueno de la cuenta siempre tiene acceso completo.
</p>

<?php if (! empty($flash['mensaje'])): ?>
    <p class="alerta alerta--<?= $flashError ? 'error' : 'exito'; ?>" role="<?= $flashError ? 'alert' : 'status'; ?>">
        <?= iconoSvg($flashError ? 'alerta' : 'check', 20, 'alerta__icono'); ?>
        <span><?= $e((string) $flash['mensaje']); ?></span>
    </p>
<?php endif; ?>

<div class="alerta alerta--error" id="resumen-errores" role="alert" tabindex="-1" hidden>
    <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
    <div class="alerta__cuerpo">
        <p class="alerta__titulo" data-resumen-titulo></p>
        <ul class="alerta__lista" data-resumen-lista></ul>
    </div>
</div>

<form method="post" action="<?= $e($accion); ?>" class="form-compacto" id="form-rol" novalidate>
    <?= csrfInput(); ?>

    <div class="config-columnas config-columnas--2">
        <section class="tarjeta" aria-labelledby="titulo-datos">
            <h2 id="titulo-datos">Datos del rol</h2>
            <div class="form-campo">
                <label for="nombre">Nombre</label>
                <input type="text" name="nombre" id="nombre" maxlength="60" required
                       value="<?= $e((string) ($rol['nombre'] ?? '')); ?>"
                       aria-describedby="ayuda-nombre error-nombre">
                <p class="campo-error" id="error-nombre" hidden><?= iconoSvg('alerta', 16, ''); ?><span></span></p>
                <small class="form-ayuda" id="ayuda-nombre">Como lo vas a reconocer al asignarlo. Por ejemplo: Cajero, Contador.</small>
            </div>
        </section>

        <div class="panel-info">
            <p class="panel-info__titulo">
                <?= iconoSvg('info', 20, 'panel-info__icono'); ?>
                Que significa cada accion
            </p>
            <ul class="panel-info__lista">
                <li><strong>Ver</strong>: entrar a la pantalla y leer.</li>
                <li><strong>Gestionar</strong>: crear, editar, activar y desactivar.</li>
                <li><strong>Emitir</strong>: enviar documentos al SII. Consume folios
                    y no se puede deshacer.</li>
            </ul>
        </div>
    </div>

    <section class="tarjeta" aria-labelledby="titulo-permisos">
        <h2 id="titulo-permisos">Permisos</h2>
        <p class="tarjeta__intro">
            Marca las casillas de cada parte del panel. "Marcar fila" marca las tres de una vez, y si ya
            estaban todas, las desmarca.
        </p>
        <div class="tabla-scroll">
            <table class="tabla-datos tabla-datos--cards">
                <caption class="visualmente-oculto">Permisos del rol por parte del panel</caption>
                <thead>
                    <tr>
                        <th scope="col">Parte del panel</th>
                        <?php foreach (CATALOGO_ACCIONES as $acc): ?>
                            <th scope="col" class="tabla-datos__estado"><?= $e($glosaAccion[$acc] ?? ucfirst($acc)); ?></th>
                        <?php endforeach; ?>
                        <th scope="col" class="tabla-datos__acciones">Fila</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (CATALOGO_MODULOS as $modulo): ?>
                        <?php $nombreModulo = $glosaModulo[$modulo] ?? $modulo; ?>
                        <tr>
                            <td data-label="Parte del panel"><?= $e($nombreModulo); ?></td>
                            <?php foreach (CATALOGO_ACCIONES as $acc): ?>
                                <td data-label="<?= $e($glosaAccion[$acc] ?? ucfirst($acc)); ?>" class="tabla-datos__estado">
                                    <input type="checkbox"
                                           class="permiso-casilla"
                                           data-modulo="<?= $e($modulo); ?>"
                                           name="permisos[<?= $e($modulo); ?>][<?= $e($acc); ?>]"
                                           value="1"
                                           aria-label="<?= $e(($glosaAccion[$acc] ?? $acc) . ' en ' . $nombreModulo); ?>"
                                           <?= $tiene($modulo, $acc) ? 'checked' : ''; ?>>
                                </td>
                            <?php endforeach; ?>
                            <td data-label="" class="tabla-datos__acciones">
                                <button type="button" class="boton-texto marcar-fila"
                                        data-modulo="<?= $e($modulo); ?>"
                                        aria-label="Marcar o desmarcar todo en <?= $e($nombreModulo); ?>">Marcar fila</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div class="acciones-formulario">
        <a class="boton-texto" href="/configuracion/usuarios">Cancelar</a>
        <button type="submit" class="boton-principal" id="guardar-rol"><?= $esAlta ? 'Crear rol' : 'Guardar cambios'; ?></button>
    </div>
</form>

<script>
(function () {
    // Atajo de comodidad, no funcionalidad: marca las tres casillas de una fila.
    // Si este script no corre, la matriz sigue siendo usable casilla por casilla.
    document.querySelectorAll('.marcar-fila').forEach(function (boton) {
        boton.addEventListener('click', function () {
            var modulo = boton.getAttribute('data-modulo');
            var casillas = document.querySelectorAll('.permiso-casilla[data-modulo="' + modulo + '"]');
            // Si ya estaban todas marcadas, el boton las desmarca: un solo control
            // para las dos direcciones, sin agregar un segundo boton por fila.
            var todas = Array.prototype.every.call(casillas, function (c) { return c.checked; });
            casillas.forEach(function (c) { c.checked = ! todas; });
        });
    });

    var form = document.getElementById('form-rol');
    var nombre = document.getElementById('nombre');
    var boton = document.getElementById('guardar-rol');
    var resumen = document.getElementById('resumen-errores');
    var tocado = false;

    /* Mismas reglas que validarNombreRol() en el servidor. */
    function errorNombre() {
        var v = nombre.value.trim();
        if (v === '') { return 'Escribe un nombre para el rol, por ejemplo Cajero.'; }
        if (v.length > 60) { return 'Acorta el nombre: puede tener hasta 60 caracteres.'; }
        return '';
    }
    function pintar(msg) {
        var err = document.getElementById('error-nombre');
        err.querySelector('span').textContent = msg;
        err.hidden = msg === '';
        document.getElementById('ayuda-nombre').hidden = msg !== '';
        nombre.closest('.form-campo').classList.toggle('form-campo--error', msg !== '');
        if (msg === '') { nombre.removeAttribute('aria-invalid'); } else { nombre.setAttribute('aria-invalid', 'true'); }
    }
    nombre.addEventListener('blur', function () {
        if (nombre.value.trim() === '' && !tocado) { return; }
        tocado = true;
        pintar(errorNombre());
    });
    nombre.addEventListener('input', function () { if (tocado) { pintar(errorNombre()); } });

    /* ESPERA AL GUARDAR -- seccion 14 del design system, Efectos de carga.
       Mismo patron que pagos-config.php: doble envio cortado en el acto y sin
       nada visible; a los 300 ms spinner dentro del boton (mismo ancho),
       aria-busy en boton y formulario; el resultado lo anuncia la pantalla de
       llegada; el foco no se mueve durante la espera. */
    var enviando = false;
    var umbral = null;
    function ocupado(si) {
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
    window.addEventListener('pageshow', function (ev) {
        if (ev.persisted) { clearTimeout(umbral); enviando = false; ocupado(false); }
    });

    form.addEventListener('submit', function (ev) {
        if (enviando) { ev.preventDefault(); return; }
        tocado = true;
        var msg = errorNombre();
        pintar(msg);
        if (msg !== '') {
            ev.preventDefault();
            var lista = resumen.querySelector('[data-resumen-lista]');
            lista.textContent = '';
            var li = document.createElement('li');
            var a = document.createElement('a');
            a.href = '#nombre';
            a.textContent = 'Nombre';
            li.appendChild(a);
            li.appendChild(document.createTextNode(': ' + msg));
            lista.appendChild(li);
            resumen.querySelector('[data-resumen-titulo]').textContent = 'Hay 1 campo por revisar antes de guardar';
            resumen.hidden = false;
            nombre.focus();
            return;
        }
        resumen.hidden = true;
        enviando = true;
        umbral = setTimeout(function () { ocupado(true); }, 300);
    });
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
