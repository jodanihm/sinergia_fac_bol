<?php $titulo = 'CAF de produccion'; require __DIR__ . '/partials/header.php'; ?>

<h1>CAF &mdash; PRODUCCION</h1>

<div class="seccion-manual">
    <strong>Atencion:</strong> esta pagina es del ambiente de PRODUCCION. Los folios que
    autoriza el CAF que subas aqui son REALES: cada documento timbrado con ellos es un
    documento tributario real ante el SII, no de prueba. NO reutilices como dato real un
    CAF de certificacion; solo hazlo si estas probando el mecanismo a proposito.
</div>

<p>Cada CAF autoriza UN tipo de documento y un rango de folios: si necesitas emitir
varios tipos (factura, nota de credito, nota de debito, boleta), debes subir un CAF
por cada uno. El tipo y el rango se leen directo del archivo, no hace falta
escribirlos.</p>

<?php if (! empty($error)): ?>
<p class="errores"><?= htmlspecialchars($error); ?></p>
<?php endif; ?>

<h2>Tipos de documento</h2>
<ul style="list-style:none;padding:0;">
<?php foreach (NOMBRES_TIPO_DTE as $tipo => $nombre): ?>
    <?php
        $cargado = false;
        foreach ($cafs as $c) {
            if ((int) $c['tipo_dte'] === $tipo) {
                $cargado = true;
                break;
            }
        }
        $estiloEstado = $cargado ? 'color:#2e7d32;font-weight:600;' : 'color:#ed6c02;font-weight:600;';
    ?>
    <li style="margin:0.25rem 0;">
        <?= htmlspecialchars($nombre); ?> (<?= (int) $tipo; ?>):
        <span style="<?= htmlspecialchars($estiloEstado); ?>"><?= $cargado ? 'CARGADO' : 'FALTA'; ?></span>
    </li>
<?php endforeach; ?>
</ul>

<h2>CAF cargados (produccion)</h2>
<?php if ($cafs === []): ?>
<p>Aun no tienes ningun CAF de produccion cargado.</p>
<?php else: ?>
<table>
    <thead>
        <tr>
            <th>Tipo</th>
            <th>Rango</th>
            <th>Proximo folio</th>
            <th>Folios restantes</th>
            <th>Estado</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($cafs as $c): ?>
        <tr>
            <td><?= htmlspecialchars(nombreTipoDte((int) $c['tipo_dte'])); ?></td>
            <td><?= htmlspecialchars((string) $c['folio_desde']); ?>&ndash;<?= htmlspecialchars((string) $c['folio_hasta']); ?></td>
            <td><?= htmlspecialchars((string) $c['proximo_folio']); ?></td>
            <td><?= htmlspecialchars((string) $c['folios_restantes']); ?></td>
            <td><?= htmlspecialchars((string) $c['estado']); ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<h2>Subir un CAF de produccion nuevo</h2>
<form method="post" action="/caf-produccion" enctype="multipart/form-data">
    <?= csrfInput(); ?>
    <label>Archivo CAF (.xml)
        <input type="file" name="caf" accept=".xml" required>
    </label>
    <button type="submit">Subir CAF de produccion</button>
</form>

<p><a href="/panel">Volver al panel</a></p>

<?php require __DIR__ . '/partials/footer.php'; ?>
