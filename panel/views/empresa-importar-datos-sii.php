<?php $titulo = 'Importar datos desde el SII'; require __DIR__ . '/partials/header.php'; ?>

<h1>Importar datos desde el SII</h1>
<p>Sube el archivo de <strong>Datos para Construccion DTE</strong> que descargaste desde
<a href="https://maullin.sii.cl/cvc_cgi/dte/pe_construccion_dte" target="_blank" rel="noopener noreferrer">pe_construccion_dte</a>.
Esto es una ayuda OPCIONAL: solo previsualiza los datos, no cambia nada todavia. Puedes
seguir completando <a href="/empresa">Datos de la empresa</a> a mano si prefieres.</p>

<?php if (! empty($error)): ?>
<p class="errores"><?= htmlspecialchars($error); ?></p>
<?php endif; ?>

<?php if ($datos === null): ?>

<form method="post" action="/empresa/importar-datos-sii" enctype="multipart/form-data">
    <?= csrfInput(); ?>
    <label>Archivo de Datos para Construccion DTE
        <input type="file" name="archivo" required>
    </label>
    <button type="submit">Previsualizar</button>
</form>

<?php else: ?>

<h2>Vista previa</h2>
<table>
    <tbody>
        <tr><td>RUT</td><td><?= htmlspecialchars($datos->rut); ?></td></tr>
        <tr><td>Razon social</td><td><?= htmlspecialchars($datos->razonSocial); ?></td></tr>
        <tr><td>Direccion</td><td><?= htmlspecialchars($datos->direccion); ?></td></tr>
        <tr><td>Comuna</td><td><?= htmlspecialchars($datos->comuna); ?></td></tr>
        <tr><td>Giro</td><td><?= htmlspecialchars($datos->giro); ?></td></tr>
        <tr><td>Acteco principal</td><td><?= (int) $datos->actecoPrincipal(); ?></td></tr>
    </tbody>
</table>

<h3>Actividades economicas</h3>
<table>
    <thead>
        <tr>
            <th>Codigo</th>
            <th>Descripcion</th>
            <th>Afecto a IVA</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($datos->actividades as $act): ?>
        <tr>
            <td><?= (int) $act->codigo; ?></td>
            <td><?= htmlspecialchars($act->descripcion); ?></td>
            <td><?= $act->afectoIva ? 'SI' : 'NO'; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<p style="color:#999;">La fecha y el numero de Resolucion NO vienen en este archivo:
deberas completarlos a mano en el siguiente paso, igual que siempre.</p>

<p>
    <a href="/empresa?<?= htmlspecialchars(http_build_query([
        'rut_emisor'   => $datos->rut,
        'razon_social' => $datos->razonSocial,
        'giro'         => $datos->giro,
        'acteco'       => (string) $datos->actecoPrincipal(),
        'dir_origen'   => $datos->direccion,
        'cmna_origen'  => $datos->comuna,
    ])); ?>"><strong>Usar estos datos &rarr;</strong></a>
</p>

<h3>Probar con otro archivo</h3>
<form method="post" action="/empresa/importar-datos-sii" enctype="multipart/form-data">
    <?= csrfInput(); ?>
    <label>Archivo de Datos para Construccion DTE
        <input type="file" name="archivo" required>
    </label>
    <button type="submit">Previsualizar</button>
</form>

<?php endif; ?>

<p><a href="/empresa">Ir a Datos de la empresa (sin importar) &rarr;</a></p>
<p><a href="/panel">Volver al panel</a></p>

<?php require __DIR__ . '/partials/footer.php'; ?>
