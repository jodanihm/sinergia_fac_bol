<?php
$titulo = 'Datos de la empresa';
require __DIR__ . '/partials/header.php';

$val = static function (string $campo) use ($emisor): string {
    return htmlspecialchars((string) ($emisor[$campo] ?? ''));
};
$err = static function (string $campo) use ($errores): ?string {
    return $errores[$campo] ?? null;
};
$ayudaEstilo = 'display:block;margin:0.15rem 0 0;color:#666;font-size:0.85rem;';
?>

<h1>Datos de la empresa</h1>
<p>Estos datos se usan para emitir tus documentos tributarios electronicos en el SII
(ambiente de certificacion).</p>

<p><a href="/empresa/importar-datos-sii">Importar datos desde el archivo del SII (opcional) &rarr;</a></p>

<details style="margin:0.75rem 0;">
    <summary style="cursor:pointer;font-weight:600;">Ayuda: el SII rechaza mis envios con
    "Rechazado por Error en Caratula"</summary>
    <div style="margin:0.5rem 0 0;padding:0.5rem 0.75rem;border-left:3px solid #999;">
        <p>La razon social, direccion y comuna deben calzar <strong>EXACTAMENTE</strong>
        con los datos que el SII tiene registrados: en <strong>MAYUSCULAS</strong> y sin
        abreviar ni truncar por cuenta propia. Descarga los datos oficiales de tu empresa
        desde
        <a href="https://maullin.sii.cl/cvc_cgi/dte/pe_construccion_dte" target="_blank" rel="noopener noreferrer">Datos para Construccion DTE (pe_construccion_dte)</a>
        y copialos tal cual.</p>
    </div>
</details>

<form method="post" action="/empresa">
    <?= csrfInput(); ?>
    <label>RUT emisor (ej. 77724622-4)
        <input type="text" name="rut_emisor" value="<?= $val('rut_emisor'); ?>" placeholder="77724622-4" required>
    </label>
    <small style="<?= htmlspecialchars($ayudaEstilo); ?>">RUT de la empresa, con guion y digito verificador.</small>
    <?php if ($err('rut_emisor')): ?><p class="error"><?= htmlspecialchars($err('rut_emisor')); ?></p><?php endif; ?>

    <label>Razon social
        <input type="text" name="razon_social" value="<?= $val('razon_social'); ?>" placeholder="Mi Empresa SpA" required>
    </label>
    <small style="<?= htmlspecialchars($ayudaEstilo); ?>">Nombre legal de la empresa, EN MAYUSCULAS y exactamente como esta registrado en el SII (ver ayuda arriba).</small>
    <?php if ($err('razon_social')): ?><p class="error"><?= htmlspecialchars($err('razon_social')); ?></p><?php endif; ?>

    <label>Giro
        <input type="text" name="giro" value="<?= $val('giro'); ?>" placeholder="Venta al por menor de ..." required>
    </label>
    <small style="<?= htmlspecialchars($ayudaEstilo); ?>">Giro comercial registrado en el SII.</small>
    <?php if ($err('giro')): ?><p class="error"><?= htmlspecialchars($err('giro')); ?></p><?php endif; ?>

    <label>Codigo de actividad economica (acteco)
        <input type="text" inputmode="numeric" name="acteco" value="<?= $val('acteco'); ?>" placeholder="620100" required>
    </label>
    <small style="<?= htmlspecialchars($ayudaEstilo); ?>">Codigo numerico de actividad economica del SII (solo numeros, sin texto).</small>
    <?php if ($err('acteco')): ?><p class="error"><?= htmlspecialchars($err('acteco')); ?></p><?php endif; ?>

    <label>Direccion
        <input type="text" name="dir_origen" value="<?= $val('dir_origen'); ?>" placeholder="Calle Ejemplo 123" required>
    </label>
    <small style="<?= htmlspecialchars($ayudaEstilo); ?>">Direccion de origen del emisor, EN MAYUSCULAS y exactamente como esta registrada en el SII (ver ayuda arriba).</small>
    <?php if ($err('dir_origen')): ?><p class="error"><?= htmlspecialchars($err('dir_origen')); ?></p><?php endif; ?>

    <label>Comuna
        <input type="text" name="cmna_origen" value="<?= $val('cmna_origen'); ?>" placeholder="Valdivia" required>
    </label>
    <small style="<?= htmlspecialchars($ayudaEstilo); ?>">Comuna de la direccion de origen, EN MAYUSCULAS y exactamente como esta registrada en el SII (ver ayuda arriba).</small>
    <?php if ($err('cmna_origen')): ?><p class="error"><?= htmlspecialchars($err('cmna_origen')); ?></p><?php endif; ?>

    <label>Fecha de resolucion SII
        <input type="date" name="resolucion_fecha" value="<?= $val('resolucion_fecha'); ?>" required>
    </label>
    <small style="<?= htmlspecialchars($ayudaEstilo); ?>">Fecha de la resolucion del SII que autoriza facturacion electronica. En AMBIENTE DE CERTIFICACION esta fecha es la FECHA EN QUE TU EMPRESA POSTULO a la certificacion (NO una fecha generica ni la de produccion, ej. NO uses 2014-08-22/Res. 80 salvo que sea realmente tu caso).</small>
    <?php if ($err('resolucion_fecha')): ?><p class="error"><?= htmlspecialchars($err('resolucion_fecha')); ?></p><?php endif; ?>

    <label>Numero de resolucion SII
        <input type="text" inputmode="numeric" name="resolucion_numero" value="<?= $val('resolucion_numero'); ?>" placeholder="80" required>
    </label>
    <small style="<?= htmlspecialchars($ayudaEstilo); ?>">Normalmente 80 (Res. Ex. N&deg;80 de 2014) en produccion. En ambiente de certificacion suele ser un numero distinto; consulta tu caso en el SII.</small>
    <?php if ($err('resolucion_numero')): ?><p class="error"><?= htmlspecialchars($err('resolucion_numero')); ?></p><?php endif; ?>

    <button type="submit">Guardar</button>
</form>

<p><a href="/panel">Volver al panel</a></p>

<?php require __DIR__ . '/partials/footer.php'; ?>
