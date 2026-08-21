<?php
/**
 * Configuracion > Usuarios y permisos > Rol (alta y edicion).
 *
 * Recibe: $rol (array{id,nombre,permisos}|null -- null = alta) y $flash.
 *
 * LA MATRIZ NO ES UN WIDGET NUEVO. Es la misma tabla.tabla-datos con una
 * casilla por celda que ya usa facturacion-masiva-form.php para elegir notas, y
 * el "marcar toda la fila" es el mismo gesto que su "marcar-todas". Se reusa el
 * patron -- y sus clases -- en vez de inventar un editor de permisos.
 *
 * LOS NOMBRES DE CAMPO SON permisos[modulo][accion]: PHP los recibe como
 * arreglo anidado y permisosDesdePost() los filtra contra el catalogo. Una
 * casilla sin marcar NO viaja en el POST -- por eso el formulario manda el
 * estado COMPLETO de la matriz y el handler borra y reinserta, en vez de
 * intentar un diff con lo que llego.
 *
 * SIN JAVASCRIPT OBLIGATORIO. El bloque del final solo agrega el atajo de
 * marcar una fila entera; sin el, la pantalla funciona igual, casilla por
 * casilla. Ningun dato depende de que el script corra.
 */
$esAlta = $rol === null;
$titulo = $esAlta ? 'Nuevo rol' : 'Editar rol';
require __DIR__ . '/partials/header.php';

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

$req = '<span class="campo-obligatorio" aria-hidden="true">*</span>'
    . '<span class="visualmente-oculto">(obligatorio)</span>';
?>

<div class="dash-header">
    <div>
        <h1><?= htmlspecialchars($titulo); ?></h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <a class="boton-secundario" href="/configuracion/usuarios">Volver a usuarios</a>
    </div>
</div>
<p class="dash-subtitulo">
    Elige que puede hacer quien tenga este rol. Solo aplica a colaboradores:
    el dueno de la cuenta siempre tiene acceso completo.
</p>

<?php if (! empty($flash['mensaje'])): ?>
    <p class="alerta alerta--<?= ($flash['tipo'] ?? '') === 'exito' ? 'exito' : 'error'; ?>" role="status">
        <span class="alerta__icono" aria-hidden="true"><?= ($flash['tipo'] ?? '') === 'exito' ? '&#10003;' : '&#9888;'; ?></span>
        <span><?= htmlspecialchars($flash['mensaje']); ?></span>
    </p>
<?php endif; ?>

<form method="post" action="<?= htmlspecialchars($accion); ?>">
    <?= csrfInput(); ?>

    <div class="layout-principal-lateral">
        <div>
            <section class="tarjeta" aria-labelledby="titulo-permisos">
                <h2 id="titulo-permisos">Permisos</h2>
                <div class="tabla-scroll">
                    <table class="tabla-datos">
                        <thead>
                            <tr>
                                <th>Modulo</th>
                                <th class="tabla-datos__estado">Ver</th>
                                <th class="tabla-datos__estado">Gestionar</th>
                                <th class="tabla-datos__estado">Emitir</th>
                                <th class="tabla-datos__acciones">Fila</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (CATALOGO_MODULOS as $modulo): ?>
                                <tr>
                                    <td><?= htmlspecialchars($glosaModulo[$modulo] ?? $modulo); ?></td>
                                    <?php foreach (CATALOGO_ACCIONES as $acc): ?>
                                        <td class="tabla-datos__estado">
                                            <input type="checkbox"
                                                   class="permiso-casilla"
                                                   data-modulo="<?= htmlspecialchars($modulo); ?>"
                                                   name="permisos[<?= htmlspecialchars($modulo); ?>][<?= htmlspecialchars($acc); ?>]"
                                                   value="1"
                                                   aria-label="<?= htmlspecialchars($acc . ' en ' . ($glosaModulo[$modulo] ?? $modulo)); ?>"
                                                   <?= $tiene($modulo, $acc) ? 'checked' : ''; ?>>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="tabla-datos__acciones">
                                        <button type="button" class="boton-texto marcar-fila"
                                                data-modulo="<?= htmlspecialchars($modulo); ?>">Todo</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <div>
            <div class="panel-info">
                <p class="panel-info__titulo">
                    <span class="panel-info__icono" aria-hidden="true">&#9432;</span>
                    Que significa cada accion
                </p>
                <ul class="panel-info__lista">
                    <li><strong>Ver</strong>: entrar a la pantalla y leer.</li>
                    <li><strong>Gestionar</strong>: crear, editar, activar y desactivar.</li>
                    <li><strong>Emitir</strong>: enviar documentos al SII. Consume folios
                        y no se puede deshacer.</li>
                </ul>
            </div>

            <section class="tarjeta" aria-labelledby="titulo-datos">
                <h2 id="titulo-datos">Datos del rol</h2>
                <div class="form-grid form-grid--1">
                    <div class="form-campo">
                        <label for="nombre">Nombre <?= $req; ?></label>
                        <input type="text" name="nombre" id="nombre" maxlength="60" required
                               value="<?= htmlspecialchars((string) ($rol['nombre'] ?? '')); ?>">
                        <small class="form-ayuda">Como lo vas a reconocer al asignarlo. Ej: Cajero, Contador.</small>
                    </div>
                </div>
                <div class="acciones-grupo">
                    <button type="submit" class="boton-principal"><?= $esAlta ? 'Crear rol' : 'Guardar cambios'; ?></button>
                    <a class="boton-texto" href="/configuracion/usuarios">Cancelar</a>
                </div>
            </section>
        </div>
    </div>
</form>

<script>
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
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
