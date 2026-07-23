<?php
$titulo = 'Datos de la empresa (PRODUCCION)';
require __DIR__ . '/partials/header.php';

$val = static function (string $campo) use ($emisor): string {
    return htmlspecialchars((string) ($emisor[$campo] ?? ''));
};
$err = static function (string $campo) use ($errores): ?string {
    return $errores[$campo] ?? null;
};
$ayudaEstilo = 'display:block;margin:0.15rem 0 0;color:#666;font-size:0.85rem;';
?>

<h1>Datos de la empresa &mdash; PRODUCCION</h1>

<div class="seccion-manual">
    <strong>Atencion:</strong> esta pagina es del ambiente de PRODUCCION (palena.sii.cl).
    La Resolucion que registres aqui es la autorizacion REAL que el SII te entrego por
    correo/portal al certificarte &mdash; no la inventes ni copies la de certificacion.
</div>

<?php if ($yaConfigurado): ?>

<p style="color:#2e7d32;font-weight:600;">Ya configuraste los datos de produccion para
esta empresa.</p>
<table>
    <tbody>
        <tr><td>RUT emisor</td><td><?= htmlspecialchars((string) $produccion['rut_emisor']); ?></td></tr>
        <tr><td>Razon social</td><td><?= htmlspecialchars((string) $produccion['razon_social']); ?></td></tr>
        <tr><td>Giro</td><td><?= htmlspecialchars((string) $produccion['giro']); ?></td></tr>
        <tr><td>Acteco</td><td><?= htmlspecialchars((string) $produccion['acteco']); ?></td></tr>
        <tr><td>Direccion</td><td><?= htmlspecialchars((string) $produccion['dir_origen']); ?></td></tr>
        <tr><td>Comuna</td><td><?= htmlspecialchars((string) $produccion['cmna_origen']); ?></td></tr>
        <tr><td>Fecha de resolucion</td><td><?= htmlspecialchars((string) $produccion['resolucion_fecha']); ?></td></tr>
        <tr><td>Numero de resolucion</td><td><?= htmlspecialchars((string) $produccion['resolucion_numero']); ?></td></tr>
    </tbody>
</table>

<p><a href="/certificado-produccion">Siguiente: certificado de produccion &rarr;</a></p>

<?php else: ?>

<p>Estos datos ya vienen precargados desde tu fila de certificacion (puedes corregirlos
si algo cambio para produccion). El RUT emisor es el mismo de certificacion y no se
puede cambiar aqui.</p>

<p><strong>RUT emisor:</strong> <?= htmlspecialchars((string) ($emisor['rut_emisor'] ?? '')); ?></p>

<?php if ($err('rut_emisor')): ?><p class="error"><?= htmlspecialchars($err('rut_emisor')); ?></p><?php endif; ?>

<form method="post" action="/empresa-produccion">
    <?= csrfInput(); ?>
    <label>Razon social
        <input type="text" name="razon_social" value="<?= $val('razon_social'); ?>" required>
    </label>
    <?php if ($err('razon_social')): ?><p class="error"><?= htmlspecialchars($err('razon_social')); ?></p><?php endif; ?>

    <label>Giro
        <input type="text" name="giro" value="<?= $val('giro'); ?>" required>
    </label>
    <?php if ($err('giro')): ?><p class="error"><?= htmlspecialchars($err('giro')); ?></p><?php endif; ?>

    <label>Codigo de actividad economica (acteco)
        <input type="text" inputmode="numeric" name="acteco" value="<?= $val('acteco'); ?>" required>
    </label>
    <?php if ($err('acteco')): ?><p class="error"><?= htmlspecialchars($err('acteco')); ?></p><?php endif; ?>

    <label>Direccion
        <input type="text" name="dir_origen" value="<?= $val('dir_origen'); ?>" required>
    </label>
    <?php if ($err('dir_origen')): ?><p class="error"><?= htmlspecialchars($err('dir_origen')); ?></p><?php endif; ?>

    <label>Comuna
        <input type="text" name="cmna_origen" value="<?= $val('cmna_origen'); ?>" required>
    </label>
    <?php if ($err('cmna_origen')): ?><p class="error"><?= htmlspecialchars($err('cmna_origen')); ?></p><?php endif; ?>

    <label>Fecha de la Resolucion de autorizacion (real)
        <input type="date" name="resolucion_fecha" value="<?= $val('resolucion_fecha'); ?>" required>
    </label>
    <small style="<?= htmlspecialchars($ayudaEstilo); ?>">La fecha de la Resolucion con
    que el SII te AUTORIZO como emisor electronico (correo/portal de autorizacion). No es
    una fecha de postulacion ni se inventa.</small>
    <?php if ($err('resolucion_fecha')): ?><p class="error"><?= htmlspecialchars($err('resolucion_fecha')); ?></p><?php endif; ?>

    <label>Numero de la Resolucion de autorizacion (real)
        <input type="text" inputmode="numeric" name="resolucion_numero" value="<?= $val('resolucion_numero'); ?>" placeholder="80" required>
    </label>
    <small style="<?= htmlspecialchars($ayudaEstilo); ?>">El numero de Resolucion Exenta
    que el SII te entrego al autorizarte (ej. Res. Ex. SII N&deg;80 de 2014). Debe ser un
    numero entero positivo.</small>
    <?php if ($err('resolucion_numero')): ?><p class="error"><?= htmlspecialchars($err('resolucion_numero')); ?></p><?php endif; ?>

    <button type="submit">Guardar</button>
</form>

<?php endif; ?>

<p><a href="/panel">Volver al panel</a></p>

<?php require __DIR__ . '/partials/footer.php'; ?>
