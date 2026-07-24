<?php
$titulo = $tituloDoc;
require __DIR__ . '/partials/header.php';

// Prellenado desde $form ($_POST en re-render, o [] en GET limpio).
$vr = static function (string $c) use ($form): string {
    return htmlspecialchars((string) ($form['receptor'][$c] ?? ''));
};
$vref = static function (string $c) use ($form): string {
    return htmlspecialchars((string) ($form['referencias'][0][$c] ?? ''));
};
// Resaltado del campo que devolvio el motor en el 422 (un campo a la vez).
$errStyle = static function (string $campo) use ($errorCampo): string {
    return $errorCampo !== null && $errorCampo !== '' && $errorCampo === $campo
        ? ' style="border-color:#b00020;"' : '';
};

// Detalles a renderizar: los enviados (re-render) o una linea vacia.
$detalles = (isset($form['detalles']) && is_array($form['detalles']) && $form['detalles'] !== [])
    ? array_values($form['detalles']) : [[]];

// Mapa nombre -> datos para el autocompletado de linea desde el maestro.
$mapaProductos = [];
foreach ($productos as $p) {
    $mapaProductos[(string) $p['nombre']] = [
        'precio' => $p['precio_unitario'],
        'unidad' => $p['unidad'] ?? '',
        'exento' => $p['exento'] ? 1 : 0,
    ];
}
$esNota = in_array($tipoDte, [61, 56], true);
?>

<h1><?= htmlspecialchars($tituloDoc); ?></h1>

<?php if (! empty($flashError)): ?>
    <p style="padding:0.5rem 0.75rem;border-radius:4px;background:#fdecea;color:#b00020;border:1px solid #b00020;">
        <?= htmlspecialchars($flashError); ?>
    </p>
<?php endif; ?>
<?php if (! empty($errorMsg)): ?>
    <p style="padding:0.5rem 0.75rem;border-radius:4px;background:#fdecea;color:#b00020;border:1px solid #b00020;">
        <?= htmlspecialchars($errorMsg); ?>
        <?php if (! empty($errorCampo)): ?><br><small>Campo: <?= htmlspecialchars($errorCampo); ?></small><?php endif; ?>
    </p>
<?php endif; ?>

<form method="post" action="<?= htmlspecialchars($accion); ?>" id="form-emision">
    <?= csrfInput(); ?>
    <input type="hidden" name="idem_key" value="<?= htmlspecialchars($idemKey); ?>">

    <h2>Receptor</h2>
    <label>RUT
        <input type="text" name="receptor[rut]" id="receptor-rut" value="<?= $vr('rut'); ?>" placeholder="76543210-9"<?= $errStyle('receptor.rut'); ?>>
    </label>
    <small id="rut-aviso" style="display:block;margin:0.15rem 0 0;color:#666;font-size:0.82rem;"></small>
    <label>Razon social
        <input type="text" name="receptor[razonSocial]" id="receptor-razonSocial" value="<?= $vr('razonSocial'); ?>"<?= $errStyle('receptor.razonSocial'); ?>>
    </label>
    <label>Giro
        <input type="text" name="receptor[giro]" id="receptor-giro" value="<?= $vr('giro'); ?>"<?= $errStyle('receptor.giro'); ?>>
    </label>
    <label>Direccion
        <input type="text" name="receptor[direccion]" id="receptor-direccion" value="<?= $vr('direccion'); ?>"<?= $errStyle('receptor.direccion'); ?>>
    </label>
    <label>Comuna
        <input type="text" name="receptor[comuna]" id="receptor-comuna" value="<?= $vr('comuna'); ?>"<?= $errStyle('receptor.comuna'); ?>>
    </label>
    <label>Email
        <input type="email" name="receptor[email]" id="receptor-email" value="<?= $vr('email'); ?>"<?= $errStyle('receptor.email'); ?>>
    </label>
    <label style="display:flex;align-items:center;gap:0.5rem;font-weight:600;">
        <input type="checkbox" name="guardar_cliente" value="1" style="width:auto;margin:0;" <?= ! empty($form['guardar_cliente']) ? 'checked' : ''; ?>>
        Guardar en mis clientes
    </label>

    <h2>Detalle</h2>
    <div class="tabla-scroll">
        <table id="tabla-detalle">
            <thead>
                <tr>
                    <th>Producto / servicio</th><th>Cantidad</th><th>Precio unit.</th>
                    <th>Unidad</th><th>Exento</th><th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($detalles as $i => $d): ?>
                    <tr>
                        <td><input type="text" list="productos-list" name="detalles[<?= $i; ?>][nombre]" value="<?= htmlspecialchars((string) ($d['nombre'] ?? '')); ?>" class="det-nombre"<?= $errStyle("detalles[{$i}].nombre"); ?>></td>
                        <td><input type="text" inputmode="decimal" name="detalles[<?= $i; ?>][cantidad]" value="<?= htmlspecialchars((string) ($d['cantidad'] ?? '')); ?>" style="width:6rem;"<?= $errStyle("detalles[{$i}].cantidad"); ?>></td>
                        <td><input type="text" inputmode="decimal" name="detalles[<?= $i; ?>][precioUnitario]" value="<?= htmlspecialchars((string) ($d['precioUnitario'] ?? '')); ?>" class="det-precio" style="width:8rem;"<?= $errStyle("detalles[{$i}].precioUnitario"); ?>></td>
                        <td><input type="text" name="detalles[<?= $i; ?>][unidad]" value="<?= htmlspecialchars((string) ($d['unidad'] ?? '')); ?>" class="det-unidad" style="width:5rem;"></td>
                        <td style="text-align:center;"><input type="checkbox" name="detalles[<?= $i; ?>][exento]" value="1" class="det-exento" <?= ! empty($d['exento']) ? 'checked' : ''; ?>></td>
                        <td><button type="button" class="quitar-linea" title="Quitar">&times;</button></td>
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
    <p><button type="button" id="agregar-linea">+ Agregar linea</button></p>

    <label style="display:flex;align-items:center;gap:0.5rem;font-weight:600;">
        <input type="checkbox" name="montosSonBrutos" value="1" style="width:auto;margin:0;" <?= ! empty($form['montosSonBrutos']) ? 'checked' : ''; ?>>
        Los precios ingresados incluyen IVA (brutos)
    </label>
    <label>Descuento global (%)
        <input type="text" inputmode="decimal" name="descuentoGlobalPct" value="<?= htmlspecialchars((string) ($form['descuentoGlobalPct'] ?? '')); ?>" style="width:8rem;"<?= $errStyle('descuentoGlobalPct'); ?>>
    </label>
    <label>Observaciones
        <input type="text" name="observaciones" value="<?= htmlspecialchars((string) ($form['observaciones'] ?? '')); ?>">
    </label>

    <?php if ($esNota): ?>
        <h2>Referencia al documento de origen</h2>
        <p><small>Una nota de credito/debito debe referenciar el documento que corrige o anula.</small></p>
        <label>Tipo de documento de referencia (TpoDocRef)
            <input type="text" inputmode="numeric" name="referencias[0][tipoDocumento]" value="<?= $vref('tipoDocumento'); ?>" placeholder="33"<?= $errStyle('referencias'); ?>>
        </label>
        <label>Folio de referencia
            <input type="text" inputmode="numeric" name="referencias[0][folio]" value="<?= $vref('folio'); ?>">
        </label>
        <label>Fecha de referencia (YYYY-MM-DD)
            <input type="date" name="referencias[0][fecha]" value="<?= $vref('fecha'); ?>">
        </label>
        <label>Codigo de referencia
            <select name="referencias[0][codigo]">
                <?php $codSel = (string) ($form['referencias'][0]['codigo'] ?? ''); ?>
                <option value="1" <?= $codSel === '1' ? 'selected' : ''; ?>>1 - Anula documento</option>
                <option value="2" <?= $codSel === '2' ? 'selected' : ''; ?>>2 - Corrige texto</option>
                <option value="3" <?= $codSel === '3' ? 'selected' : ''; ?>>3 - Corrige montos</option>
            </select>
        </label>
        <label>Razon de la referencia
            <input type="text" name="referencias[0][razon]" value="<?= $vref('razon'); ?>" placeholder="Anula factura N...">
        </label>
    <?php endif; ?>

    <button type="submit" style="margin-top:1.25rem;">Emitir <?= htmlspecialchars($tituloDoc); ?></button>
</form>

<p><a href="/panel">Volver al panel</a></p>

<script>
(function () {
    var PRODUCTOS = <?= json_encode($mapaProductos, JSON_UNESCAPED_UNICODE); ?>;
    var tbody = document.querySelector('#tabla-detalle tbody');
    var idx = <?= count($detalles); ?>;

    function rellenarDesdeProducto(fila) {
        var nombre = fila.querySelector('.det-nombre').value;
        var p = PRODUCTOS[nombre];
        if (!p) { return; }
        var precio = fila.querySelector('.det-precio');
        var unidad = fila.querySelector('.det-unidad');
        var exento = fila.querySelector('.det-exento');
        if (precio && p.precio !== null && precio.value === '') { precio.value = p.precio; }
        if (unidad && unidad.value === '') { unidad.value = p.unidad || ''; }
        if (exento) { exento.checked = p.exento === 1; }
    }

    function nuevaFilaHTML(n) {
        return '<td><input type="text" list="productos-list" name="detalles[' + n + '][nombre]" class="det-nombre"></td>' +
            '<td><input type="text" inputmode="decimal" name="detalles[' + n + '][cantidad]" style="width:6rem;"></td>' +
            '<td><input type="text" inputmode="decimal" name="detalles[' + n + '][precioUnitario]" class="det-precio" style="width:8rem;"></td>' +
            '<td><input type="text" name="detalles[' + n + '][unidad]" class="det-unidad" style="width:5rem;"></td>' +
            '<td style="text-align:center;"><input type="checkbox" name="detalles[' + n + '][exento]" value="1" class="det-exento"></td>' +
            '<td><button type="button" class="quitar-linea" title="Quitar">&times;</button></td>';
    }

    document.getElementById('agregar-linea').addEventListener('click', function () {
        var tr = document.createElement('tr');
        tr.innerHTML = nuevaFilaHTML(idx++);
        tbody.appendChild(tr);
    });

    tbody.addEventListener('click', function (e) {
        if (e.target.classList.contains('quitar-linea')) {
            if (tbody.querySelectorAll('tr').length > 1) { e.target.closest('tr').remove(); }
        }
    });
    tbody.addEventListener('change', function (e) {
        if (e.target.classList.contains('det-nombre')) { rellenarDesdeProducto(e.target.closest('tr')); }
    });

    // Autocompletado del receptor por RUT.
    var rut = document.getElementById('receptor-rut');
    var aviso = document.getElementById('rut-aviso');
    rut.addEventListener('blur', function () {
        var v = rut.value.trim();
        if (v === '') { aviso.textContent = ''; return; }
        fetch('/ventas/cliente-por-rut?rut=' + encodeURIComponent(v))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.estado === 'rut_invalido') { aviso.textContent = 'RUT invalido.'; return; }
                if (data.estado === 'no_encontrado') {
                    aviso.textContent = 'Cliente nuevo (puedes completar sus datos y marcar "Guardar en mis clientes").';
                    return;
                }
                var c = data.cliente || {};
                document.getElementById('receptor-razonSocial').value = c.razon_social || '';
                document.getElementById('receptor-giro').value = c.giro || '';
                document.getElementById('receptor-direccion').value = c.direccion || '';
                document.getElementById('receptor-comuna').value = c.comuna || '';
                document.getElementById('receptor-email').value = c.email || '';
                aviso.textContent = c.activo === false
                    ? 'Cliente encontrado (INACTIVO en tus maestros).'
                    : 'Cliente encontrado: datos autocompletados.';
            })
            .catch(function () { aviso.textContent = ''; });
    });
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
