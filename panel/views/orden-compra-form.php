<?php
/**
 * Alta y edicion de una orden de compra.
 *
 * Recibe: $modo ('nueva'|'editar'), $accion, $orden, $lineas, $errores,
 * $productos, $proveedores, $navActivo.
 *
 * CONTRATO CON EL BACKEND Y EL JS -- no tocar sin revisar validarOrdenCompra():
 *   - csrfInput() dentro del <form>. SIN ESA LINEA LA PANTALLA NO SE PUEDE USAR:
 *     el chequeo CSRF es central y corre antes de despachar cualquier POST.
 *   - class="form-compacto": de ella cuelga el estilo de los inputs de la tabla.
 *   - name de cada control, con la sintaxis lineas[i][campo].
 *   - id/clase que usa el JS: #form-orden, #tabla-lineas, #agregar-linea,
 *     .quitar-linea, .lin-nombre, .lin-precio, .lin-unidad, #productos-list,
 *     #proveedores-list, #proveedor_rut y #rut-aviso.
 *   - La fila esta duplicada: aqui en PHP y en nuevaFilaHTML() del JS. Las dos
 *     deben producir el mismo DOM.
 */
$titulo = $modo === 'editar' ? 'Editar orden de compra' : 'Nueva orden de compra';
require __DIR__ . '/partials/header.php';

$v   = static fn (string $c): string => htmlspecialchars((string) ($orden[$c] ?? ''));
$err = static fn (string $c): string => isset($errores[$c]) ? ' style="border-color:#b00020;"' : '';

$filas = ($lineas !== []) ? array_values($lineas) : [[]];

// Mapa nombre -> precio/unidad/exento, para autocompletar al elegir del datalist.
$mapaProductos = [];
foreach ($productos as $p) {
    $mapaProductos[(string) $p['nombre']] = [
        'precio' => $p['precio_unitario'] !== null ? (float) $p['precio_unitario'] : '',
        'unidad' => (string) ($p['unidad'] ?? ''),
        'exento' => ! empty($p['exento']),
    ];
}
?>

<div class="dash-header">
    <div>
        <h1><?= htmlspecialchars($titulo); ?><?php if (! empty($orden['numero'])): ?>
            <span class="dash-header__sub">N&deg; <?= (int) $orden['numero']; ?></span>
        <?php endif; ?></h1>
    </div>
</div>

<?php if ($errores !== []): ?>
    <div class="alerta alerta--error" role="alert">
        <p>Revisa los datos marcados:</p>
        <ul>
            <?php foreach ($errores as $mensaje): ?>
                <li><?= htmlspecialchars($mensaje); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form id="form-orden" method="post" action="<?= htmlspecialchars($accion); ?>" class="form-compacto">
    <?= csrfInput(); ?>

    <section class="tarjeta" aria-labelledby="titulo-proveedor">
        <h2 id="titulo-proveedor">Proveedor</h2>
        <div class="form-grid">
            <div class="form-campo">
                <label for="proveedor_rut">RUT</label>
                <input type="text" list="proveedores-list" name="proveedor_rut" id="proveedor_rut"
                       value="<?= $v('proveedor_rut'); ?>" placeholder="76543210-9"
                       aria-describedby="rut-aviso"<?= $err('proveedor_rut'); ?>>
                <small class="form-ayuda" id="rut-aviso" aria-live="polite">Elige uno de tus proveedores o escribe un RUT nuevo.</small>
            </div>
            <div class="form-campo form-campo--ancho">
                <label for="proveedor_razon_social">Razon social</label>
                <input type="text" name="proveedor_razon_social" id="proveedor_razon_social"
                       value="<?= $v('proveedor_razon_social'); ?>"<?= $err('proveedor_razon_social'); ?>>
            </div>
            <div class="form-campo">
                <label for="proveedor_giro">Giro</label>
                <input type="text" name="proveedor_giro" id="proveedor_giro" value="<?= $v('proveedor_giro'); ?>">
            </div>
            <div class="form-campo">
                <label for="proveedor_direccion">Direccion</label>
                <input type="text" name="proveedor_direccion" id="proveedor_direccion" value="<?= $v('proveedor_direccion'); ?>">
            </div>
            <div class="form-campo">
                <label for="proveedor_comuna">Comuna</label>
                <input type="text" name="proveedor_comuna" id="proveedor_comuna" value="<?= $v('proveedor_comuna'); ?>">
            </div>
            <div class="form-campo">
                <label for="proveedor_contacto">Contacto</label>
                <input type="text" name="proveedor_contacto" id="proveedor_contacto" value="<?= $v('proveedor_contacto'); ?>">
            </div>
            <div class="form-campo">
                <label for="proveedor_email">Correo</label>
                <input type="email" name="proveedor_email" id="proveedor_email" value="<?= $v('proveedor_email'); ?>">
                <small class="form-ayuda">A esta direccion se enviara la orden.</small>
            </div>
        </div>
        <?php
        /* EL DATALIST PROPONE, NO OBLIGA. Un RUT que no este en el maestro se
           escribe a mano y se guarda igual: proveedor_id queda NULL y los datos
           van sueltos en las columnas congeladas de la orden. Comprar una vez a
           alguien que no es proveedor habitual es un caso normal.

           POR QUE ADEMAS DEL fetch Y NO EN VEZ DE EL: el fetch autocompleta
           DESPUES de teclear el RUT completo, asi que sin el datalist habria que
           saberselo de memoria. Es la misma queja que se resolvio en cotizacion
           con los clientes. */
        ?>
        <datalist id="proveedores-list">
            <?php foreach ($proveedores as $p): ?>
                <option value="<?= htmlspecialchars((string) $p['rut_proveedor']); ?>"><?= htmlspecialchars((string) $p['razon_social']); ?></option>
            <?php endforeach; ?>
        </datalist>
    </section>

    <section class="tarjeta" aria-labelledby="titulo-entrega">
        <h2 id="titulo-entrega">Fechas y entrega</h2>
        <div class="form-grid">
            <div class="form-campo form-campo--corto">
                <label for="fecha">Fecha</label>
                <input type="date" name="fecha" id="fecha" value="<?= $v('fecha'); ?>"<?= $err('fecha'); ?>>
            </div>
            <div class="form-campo form-campo--corto">
                <label for="fecha_entrega">Fecha de entrega</label>
                <input type="date" name="fecha_entrega" id="fecha_entrega" value="<?= $v('fecha_entrega'); ?>"<?= $err('fecha_entrega'); ?>>
            </div>
            <div class="form-campo form-campo--ancho">
                <label for="lugar_entrega">Lugar de entrega</label>
                <input type="text" name="lugar_entrega" id="lugar_entrega" value="<?= $v('lugar_entrega'); ?>">
            </div>
            <div class="form-campo form-campo--ancho">
                <label for="condiciones_pago">Condiciones de pago</label>
                <input type="text" name="condiciones_pago" id="condiciones_pago" value="<?= $v('condiciones_pago'); ?>">
                <small class="form-ayuda">Se copian del proveedor al elegirlo; aqui se pueden cambiar.</small>
            </div>
        </div>
    </section>

    <section class="tarjeta" aria-labelledby="titulo-lineas">
        <h2 id="titulo-lineas">Detalle</h2>
        <div class="tabla-scroll">
            <table id="tabla-lineas" class="tabla-datos tabla-editable">
                <thead>
                    <tr>
                        <th class="col-producto">Producto / servicio</th>
                        <th class="col-cantidad">Cantidad</th>
                        <th class="col-precio">Precio unit.</th>
                        <th class="col-unidad">Unidad</th>
                        <th class="col-descuento">Desc. %</th>
                        <th class="col-exento">Exento</th>
                        <th class="col-accion"><span class="visualmente-oculto">Accion</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($filas as $i => $l): ?>
                        <tr>
                            <td class="col-producto"><input type="text" list="productos-list" name="lineas[<?= $i; ?>][nombre]" value="<?= htmlspecialchars((string) ($l['nombre'] ?? '')); ?>" class="lin-nombre" aria-label="Producto o servicio"<?= $err("lineas[{$i}].nombre"); ?>></td>
                            <td class="col-cantidad"><input type="text" inputmode="decimal" name="lineas[<?= $i; ?>][cantidad]" value="<?= htmlspecialchars((string) ($l['cantidad'] ?? '')); ?>" aria-label="Cantidad"<?= $err("lineas[{$i}].cantidad"); ?>></td>
                            <td class="col-precio"><input type="text" inputmode="decimal" name="lineas[<?= $i; ?>][precio_unitario]" value="<?= htmlspecialchars((string) ($l['precio_unitario'] ?? '')); ?>" class="lin-precio" aria-label="Precio unitario"<?= $err("lineas[{$i}].precio_unitario"); ?>></td>
                            <td class="col-unidad"><input type="text" name="lineas[<?= $i; ?>][unidad]" value="<?= htmlspecialchars((string) ($l['unidad'] ?? '')); ?>" class="lin-unidad" aria-label="Unidad"></td>
                            <td class="col-descuento"><input type="text" inputmode="decimal" name="lineas[<?= $i; ?>][descuento_pct]" value="<?= htmlspecialchars((string) ($l['descuento_pct'] ?? '')); ?>" aria-label="Descuento por linea"<?= $err("lineas[{$i}].descuento_pct"); ?>></td>
                            <td class="col-exento"><input type="checkbox" name="lineas[<?= $i; ?>][exento]" value="1" aria-label="Exento de IVA" <?= ! empty($l['exento']) ? 'checked' : ''; ?>></td>
                            <td class="col-accion"><button type="button" class="quitar-linea" title="Quitar linea" aria-label="Quitar linea">&times;</button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <datalist id="productos-list">
            <?php foreach ($productos as $p): ?>
                <option value="<?= htmlspecialchars((string) $p['nombre']); ?>"></option>
            <?php endforeach; ?>
        </datalist>
        <p><button type="button" id="agregar-linea" class="boton-secundario">+ Agregar linea</button></p>
        <p class="nota">
            La cantidad admite decimales. El IVA se calcula sobre las lineas afectas, una sola
            vez sobre el total &mdash; no linea por linea.
        </p>
    </section>

    <section class="tarjeta" aria-labelledby="titulo-notas">
        <h2 id="titulo-notas">Observaciones</h2>
        <div class="form-campo">
            <label for="notas">Texto libre</label>
            <textarea name="notas" id="notas" rows="4"><?= $v('notas'); ?></textarea>
            <small class="form-ayuda">Se imprime al final de la orden.</small>
        </div>
    </section>

    <div class="acciones-grupo">
        <button type="submit" class="boton-principal">Guardar orden</button>
        <a class="boton-texto" href="/compras/ordenes">Volver al listado</a>
    </div>
</form>

<script>
(function () {
    var PRODUCTOS = <?= json_encode($mapaProductos, JSON_UNESCAPED_UNICODE); ?>;
    var tbody = document.querySelector('#tabla-lineas tbody');
    var idx = <?= count($filas); ?>;

    // MISMA FILA QUE LA DE PHP. Si cambia una, cambia la otra.
    function nuevaFilaHTML(i) {
        return '<tr>'
            + '<td class="col-producto"><input type="text" list="productos-list" name="lineas[' + i + '][nombre]" class="lin-nombre" aria-label="Producto o servicio"></td>'
            + '<td class="col-cantidad"><input type="text" inputmode="decimal" name="lineas[' + i + '][cantidad]" aria-label="Cantidad"></td>'
            + '<td class="col-precio"><input type="text" inputmode="decimal" name="lineas[' + i + '][precio_unitario]" class="lin-precio" aria-label="Precio unitario"></td>'
            + '<td class="col-unidad"><input type="text" name="lineas[' + i + '][unidad]" class="lin-unidad" aria-label="Unidad"></td>'
            + '<td class="col-descuento"><input type="text" inputmode="decimal" name="lineas[' + i + '][descuento_pct]" aria-label="Descuento por linea"></td>'
            + '<td class="col-exento"><input type="checkbox" name="lineas[' + i + '][exento]" value="1" aria-label="Exento de IVA"></td>'
            + '<td class="col-accion"><button type="button" class="quitar-linea" title="Quitar linea" aria-label="Quitar linea">&times;</button></td>'
            + '</tr>';
    }

    document.getElementById('agregar-linea').addEventListener('click', function () {
        tbody.insertAdjacentHTML('beforeend', nuevaFilaHTML(idx));
        idx++;
    });

    tbody.addEventListener('click', function (e) {
        if (!e.target.classList.contains('quitar-linea')) { return; }
        // NUNCA se deja la tabla sin filas: el backend rechaza una orden sin
        // lineas y quedarse con la tabla vacia obliga a recargar.
        if (tbody.rows.length > 1) { e.target.closest('tr').remove(); }
    });

    // Producto elegido del datalist: rellena precio, unidad y exento SOLO si
    // estan vacios. Nunca se pisa lo que el usuario ya escribio.
    tbody.addEventListener('change', function (e) {
        if (!e.target.classList.contains('lin-nombre')) { return; }
        var p = PRODUCTOS[e.target.value];
        if (!p) { return; }
        var fila = e.target.closest('tr');
        var precio = fila.querySelector('.lin-precio');
        var unidad = fila.querySelector('.lin-unidad');
        var exento = fila.querySelector('input[type=checkbox]');
        if (precio && precio.value === '' && p.precio !== '') { precio.value = p.precio; }
        if (unidad && unidad.value === '') { unidad.value = p.unidad; }
        if (exento && p.exento) { exento.checked = true; }
    });

    // AUTOCOMPLETADO DEL PROVEEDOR POR RUT. Reusa el endpoint propio
    // /compras/proveedor-por-rut, con la misma forma que /ventas/cliente-por-rut.
    // Se dispara en 'change' ademas de en 'blur' porque elegir del datalist con
    // el raton no siempre produce blur.
    var rut = document.getElementById('proveedor_rut');
    var aviso = document.getElementById('rut-aviso');
    function buscarProveedor() {
        var vRut = rut.value.trim();
        if (vRut === '') { aviso.textContent = 'Elige uno de tus proveedores o escribe un RUT nuevo.'; return; }
        fetch('/compras/proveedor-por-rut?rut=' + encodeURIComponent(vRut))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.estado === 'rut_invalido') { aviso.textContent = 'RUT invalido.'; return; }
                if (data.estado === 'no_encontrado') {
                    // NO ES UN ERROR: se compra a proveedores ocasionales.
                    aviso.textContent = 'Proveedor nuevo: completa sus datos y se guardan con la orden.';
                    return;
                }
                var p = data.proveedor || {};
                [['proveedor_razon_social', p.razon_social], ['proveedor_giro', p.giro],
                 ['proveedor_direccion', p.direccion], ['proveedor_comuna', p.comuna],
                 ['proveedor_email', p.email], ['proveedor_contacto', p.contacto],
                 ['condiciones_pago', p.condiciones_pago]].forEach(function (par) {
                    var el = document.getElementById(par[0]);
                    if (el && el.value === '' && par[1]) { el.value = par[1]; }
                });
                aviso.textContent = p.activo === false || p.activo === 0
                    ? 'Proveedor encontrado (INACTIVO en tus maestros).'
                    : 'Proveedor encontrado: datos autocompletados.';
            })
            .catch(function () { aviso.textContent = ''; });
    }
    rut.addEventListener('blur', buscarProveedor);
    rut.addEventListener('change', buscarProveedor);
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
