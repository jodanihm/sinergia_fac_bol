<?php
/**
 * Alta y edicion de una cotizacion.
 *
 * Recibe: $modo ('nueva'|'editar'), $accion, $cotizacion, $lineas, $errores,
 * $productos, $navActivo.
 *
 * UNA SOLA VISTA PARA LOS DOS MODOS. La unica diferencia visible es el titulo y
 * el numero, que en alta todavia no existe -- el correlativo se reserva al
 * guardar, dentro de la transaccion, y NUNCA se muestra "el proximo" antes de
 * tenerlo: dos usuarios mirando la pantalla a la vez verian el mismo numero y
 * solo uno lo recibiria.
 *
 * ESTO NO ES UN DTE. No hay Idempotency-Key, no hay tipo de documento, no hay
 * referencias y no se consume folio: guardar dos veces crea dos cotizaciones y
 * eso es un error del usuario, no una emision duplicada ante el SII.
 *
 * CONTRATO CON EL BACKEND Y EL JS -- no tocar sin revisar validarCotizacion():
 *   - name de cada control, con la sintaxis de arrays lineas[i][campo].
 *   - id/clase que usa el JS: #form-cotizacion, #tabla-lineas, #agregar-linea,
 *     .quitar-linea, .lin-nombre, .lin-precio, .lin-unidad, #productos-list.
 *   - La fila esta duplicada: aqui en PHP y en nuevaFilaHTML() del JS. Las dos
 *     deben producir el mismo DOM. Mismo criterio que emision-form.php.
 */
$titulo = $modo === 'editar' ? 'Editar cotizacion' : 'Nueva cotizacion';
require __DIR__ . '/partials/header.php';

$v = static function (string $c) use ($cotizacion): string {
    return htmlspecialchars((string) ($cotizacion[$c] ?? ''));
};
$err = static function (string $campo) use ($errores): string {
    return isset($errores[$campo]) ? ' style="border-color:#b00020;"' : '';
};

// Lineas a pintar: las que vengan (re-render o edicion) o una vacia.
$filas = ($lineas !== []) ? array_values($lineas) : [[]];

// Mapa nombre -> [precio, unidad, exento] para autocompletar al elegir del
// datalist. Mismo mecanismo que el formulario de emision.
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
        <h1><?= htmlspecialchars($titulo); ?><?php if (! empty($cotizacion['numero'])): ?>
            <span class="dash-header__sub">N&deg; <?= (int) $cotizacion['numero']; ?></span>
        <?php endif; ?></h1>
    </div>
</div>

<?php if ($errores !== []): ?>
    <div class="alerta alerta--error" role="alert">
        <p>Revisa los datos marcados:</p>
        <ul>
            <?php foreach ($errores as $campo => $mensaje): ?>
                <li><?= htmlspecialchars($mensaje); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form id="form-cotizacion" method="post" action="<?= htmlspecialchars($accion); ?>">

    <section class="tarjeta" aria-labelledby="titulo-cliente">
        <h2 id="titulo-cliente">Cliente</h2>
        <div class="form-grid">
            <div class="form-campo">
                <label for="receptor_rut">RUT</label>
                <input type="text" name="receptor_rut" id="receptor_rut" value="<?= $v('receptor_rut'); ?>"<?= $err('receptor_rut'); ?>>
                <small class="form-ayuda">Con guion y digito verificador.</small>
            </div>
            <div class="form-campo form-campo--ancho">
                <label for="receptor_razon_social">Razon social</label>
                <input type="text" name="receptor_razon_social" id="receptor_razon_social" value="<?= $v('receptor_razon_social'); ?>"<?= $err('receptor_razon_social'); ?>>
            </div>
            <div class="form-campo">
                <label for="receptor_giro">Giro</label>
                <input type="text" name="receptor_giro" id="receptor_giro" value="<?= $v('receptor_giro'); ?>">
                <small class="form-ayuda">Opcional en una cotizacion; el SII lo exige recien al facturar.</small>
            </div>
            <div class="form-campo">
                <label for="receptor_direccion">Direccion</label>
                <input type="text" name="receptor_direccion" id="receptor_direccion" value="<?= $v('receptor_direccion'); ?>">
            </div>
            <div class="form-campo">
                <label for="receptor_comuna">Comuna</label>
                <input type="text" name="receptor_comuna" id="receptor_comuna" value="<?= $v('receptor_comuna'); ?>">
            </div>
            <div class="form-campo">
                <label for="receptor_email">Correo</label>
                <input type="email" name="receptor_email" id="receptor_email" value="<?= $v('receptor_email'); ?>">
            </div>
        </div>
    </section>

    <section class="tarjeta" aria-labelledby="titulo-fechas">
        <h2 id="titulo-fechas">Fechas</h2>
        <div class="form-grid">
            <div class="form-campo form-campo--corto">
                <label for="fecha">Fecha</label>
                <input type="date" name="fecha" id="fecha" value="<?= $v('fecha'); ?>"<?= $err('fecha'); ?>>
            </div>
            <div class="form-campo form-campo--corto">
                <label for="valida_hasta">Valida hasta</label>
                <input type="date" name="valida_hasta" id="valida_hasta" value="<?= $v('valida_hasta'); ?>"<?= $err('valida_hasta'); ?>>
                <small class="form-ayuda">Opcional. Se imprime, pero no bloquea nada todavia.</small>
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
        <p class="nota">La cantidad admite decimales: media hora de servicio es una cantidad valida.</p>
    </section>

    <section class="tarjeta" aria-labelledby="titulo-notas">
        <h2 id="titulo-notas">Observaciones</h2>
        <div class="form-campo">
            <label for="notas">Texto libre</label>
            <textarea name="notas" id="notas" rows="4"><?= $v('notas'); ?></textarea>
            <small class="form-ayuda">Se imprime al final de la cotizacion.</small>
        </div>
    </section>

    <div class="acciones-grupo">
        <button type="submit" class="boton-principal">Guardar cotizacion</button>
        <a class="boton-texto" href="/ventas/cotizaciones">Volver al listado</a>
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
        // NUNCA se deja la tabla sin filas: el backend rechaza una cotizacion
        // sin lineas y quedarse con la tabla vacia obliga a recargar.
        if (tbody.rows.length > 1) { e.target.closest('tr').remove(); }
    });

    // Al elegir un producto del datalist se rellenan precio, unidad y exento
    // SOLO si estan vacios: nunca se pisa lo que el usuario ya escribio.
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
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
