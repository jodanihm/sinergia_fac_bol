<?php $titulo = 'Set de Simulacion'; require __DIR__ . '/partials/header.php'; ?>

<h1>Set de Simulacion</h1>
<p>Genera y envia el Set de Simulacion de certificacion: UN envio con los 3 tipos de
documento (factura, nota de credito y nota de debito), entre 20 y 100 documentos en
total, SIN referencia al SET. El SII valida estructura y volumen del envio, no el
contenido literal de las glosas.</p>

<?php if ($flash !== null): ?>
    <?php if ($flash['tipo'] === 'error'): ?>
    <p class="errores"><?= htmlspecialchars($flash['mensaje']); ?></p>
    <?php else: ?>
    <p style="color:#2e7d32;font-weight:600;"><?= htmlspecialchars($flash['mensaje']); ?></p>
    <?php endif; ?>
<?php endif; ?>

<form method="get" action="/certificacion/simulacion" style="margin:0.75rem 0;">
    <label>Cantidad total de documentos (20-100)
        <input type="number" name="total" min="20" max="100" value="<?= (int) $total; ?>" style="width:auto;display:inline-block;">
    </label>
    <button type="submit">Actualizar vista previa</button>
</form>

<?php
    $conteo = [33 => 0, 61 => 0, 56 => 0];
    foreach ($documentos as $d) {
        $conteo[$d['tipoDte']]++;
    }
    $totalDocumentos = count($documentos);
    $tieneLosTres    = $conteo[33] > 0 && $conteo[61] > 0 && $conteo[56] > 0;
    $enRango         = $totalDocumentos >= 20 && $totalDocumentos <= 100;
    $cumple          = $tieneLosTres && $enRango;
?>

<p style="<?= $cumple ? 'color:#2e7d32;font-weight:600;' : 'color:#b00020;font-weight:600;'; ?>">
    <?= $totalDocumentos; ?> documentos en total &mdash;
    <?= $conteo[33]; ?> Factura(s), <?= $conteo[61]; ?> Nota(s) de Credito, <?= $conteo[56]; ?> Nota(s) de Debito.
    <?= $cumple ? '(cumple 20-100 y trae los 3 tipos)' : '(REVISA: falta algun tipo o esta fuera de rango)'; ?>
</p>

<details>
    <summary>Ver el detalle de los <?= $totalDocumentos; ?> documentos</summary>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Tipo</th>
                <th>Glosa</th>
                <th>Monto</th>
                <th>Referencia</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($documentos as $i => $d): ?>
            <tr>
                <td><?= $i + 1; ?></td>
                <td><?= htmlspecialchars(nombreTipoDte((int) $d['tipoDte'])); ?></td>
                <td><?= htmlspecialchars($d['detalles'][0]['nombre']); ?></td>
                <td><?= number_format((int) $d['detalles'][0]['precioUnitario'], 0, ',', '.'); ?></td>
                <td>
                    <?php if (! empty($d['referencias'])): ?>
                        Doc #<?= (int) $d['referencias'][0]['refIndiceLote'] + 1; ?>
                        (<?= htmlspecialchars((string) $d['referencias'][0]['razon']); ?>)
                    <?php else: ?>
                        &mdash;
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</details>

<form method="post" action="/certificacion/simulacion/emitir" style="margin:1rem 0;"
      onsubmit="return confirm('Emitir el Set de Simulacion con <?= $totalDocumentos; ?> documentos? Esto consume folios reales del CAF.');">
    <?= csrfInput(); ?>
    <input type="hidden" name="total" value="<?= (int) $total; ?>">
    <button type="submit">Emitir Set de Simulacion (<?= $totalDocumentos; ?> documentos)</button>
</form>

<p><a href="/certificacion">Volver a Certificacion &rarr;</a></p>
<p><a href="/panel">Volver al panel</a></p>

<?php require __DIR__ . '/partials/footer.php'; ?>
