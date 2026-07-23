<?php $titulo = 'Set de pruebas del SII - preview'; require __DIR__ . '/partials/header.php'; ?>

<h1>Archivo de pruebas del SII (preview y emision)</h1>
<p>Sube aqui el archivo <code>SIISetDePruebas&lt;RUT&gt;.txt</code> que el SII te entrego
al solicitar el set de pruebas de certificacion (Set Basico + Libro de Ventas + Libro de
Compras). Puedes revisar el preview antes de emitir el Set Basico al SII.</p>

<?php if ($flash !== null): ?>
    <?php if ($flash['tipo'] === 'error'): ?>
    <p class="errores"><?= htmlspecialchars($flash['mensaje']); ?></p>
    <?php else: ?>
    <p style="color:#2e7d32;font-weight:600;"><?= htmlspecialchars($flash['mensaje']); ?></p>
    <?php endif; ?>

    <?php if (isset($flash['erroresConstruccion'])): ?>
    <ul class="errores">
        <?php foreach ($flash['erroresConstruccion'] as $errorConstruccion): ?>
        <li><?= htmlspecialchars($errorConstruccion); ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <?php if (isset($flash['resultadoEmision'])): ?>
    <table>
        <thead>
            <tr>
                <th>Tipo DTE</th>
                <th>Folio</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($flash['resultadoEmision']['documentos'] as $doc): ?>
            <tr>
                <td><?= htmlspecialchars((string) $doc['tipoDte']); ?></td>
                <td><?= htmlspecialchars((string) $doc['folio']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
<?php endif; ?>

<?php if ($error !== null): ?>
<p class="errores"><?= htmlspecialchars($error); ?></p>
<?php endif; ?>

<?php if ($errorParseo !== null): ?>
<p class="errores"><?= htmlspecialchars($errorParseo); ?></p>
<?php endif; ?>

<h2>Subir archivo</h2>
<form method="post" action="/certificacion/set-pruebas" enctype="multipart/form-data">
    <?= csrfInput(); ?>
    <label>Archivo SIISetDePruebas (.txt)
        <input type="file" name="archivo" accept=".txt" required>
    </label>
    <button type="submit">Subir y previsualizar</button>
</form>

<?php if ($archivo !== null): ?>
<p style="color:#999;">Archivo actual: <?= htmlspecialchars($archivo['nombre_archivo']); ?>
(subido el <?= htmlspecialchars((string) $archivo['created_at']); ?>). Subir uno nuevo
reemplaza este.</p>
<?php endif; ?>

<?php if ($parseado !== null): ?>

<?php if ($parseado->advertencias !== []): ?>
<h2>Advertencias del parser</h2>
<p class="errores">Estas lineas del archivo no se pudieron interpretar. El resto del
preview de abajo si refleja el archivo correctamente:</p>
<ul class="errores">
    <?php foreach ($parseado->advertencias as $advertencia): ?>
    <li><?= htmlspecialchars($advertencia); ?></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<h2>Numeros de atencion</h2>
<table>
    <tbody>
        <tr>
            <th>Set Basico</th>
            <td><?= htmlspecialchars((string) $parseado->numeroAtencionSetBasico); ?></td>
        </tr>
        <tr>
            <th>Libro de Ventas</th>
            <td><?= htmlspecialchars((string) ($parseado->numeroAtencionLibroVentas ?? 'no consta')); ?></td>
        </tr>
        <tr>
            <th>Libro de Compras</th>
            <td><?= htmlspecialchars((string) ($parseado->numeroAtencionLibroCompras ?? 'no consta')); ?></td>
        </tr>
    </tbody>
</table>

<h2>Set basico &mdash; <?= count($parseado->casos); ?> caso(s)</h2>
<?php foreach ($parseado->casos as $caso): ?>
<h3>Caso <?= htmlspecialchars((string) $caso->numeroCaso); ?> &mdash; <?= htmlspecialchars($caso->tipoDocumento); ?></h3>

<?php if ($caso->referenciaCaso !== null): ?>
<p>Referencia: caso <?= htmlspecialchars((string) $caso->referenciaCaso); ?>
&mdash; razon: <?= htmlspecialchars((string) ($caso->razonReferencia ?? 'no consta')); ?></p>
<?php endif; ?>

<?php if ($caso->items !== []): ?>
<table>
    <thead>
        <tr>
            <th>Item</th>
            <th>Cantidad</th>
            <th>Precio unitario</th>
            <th>Descuento</th>
            <th>Exento</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($caso->items as $item): ?>
        <tr>
            <td><?= htmlspecialchars($item->nombre); ?></td>
            <td><?= htmlspecialchars((string) $item->cantidad); ?></td>
            <td><?= $item->precioUnitario !== null ? htmlspecialchars((string) $item->precioUnitario) : '&mdash;'; ?></td>
            <td><?= $item->descuentoPorcentaje !== null ? htmlspecialchars((string) $item->descuentoPorcentaje) . '%' : '&mdash;'; ?></td>
            <td><?= $item->exento ? 'Si' : 'No'; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php else: ?>
<p style="color:#999;">Este caso no tiene tabla de items.</p>
<?php endif; ?>

<?php if ($caso->descuentoGlobalPct !== null): ?>
<p>Descuento global sobre items afectos: <strong><?= htmlspecialchars((string) $caso->descuentoGlobalPct); ?>%</strong></p>
<?php endif; ?>

<?php endforeach; ?>

<h2>Libro de compras &mdash; <?= count($parseado->casosLibroCompras); ?> documento(s)</h2>
<table>
    <thead>
        <tr>
            <th>Tipo documento</th>
            <th>Folio</th>
            <th>Observacion</th>
            <th>Monto exento</th>
            <th>Monto afecto</th>
            <th>Caso especial detectado</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($parseado->casosLibroCompras as $lc): ?>
        <?php
            $flags = [];
            if ($lc->ivaUsoComun) {
                $flags[] = 'IVA USO COMUN';
            }
            if ($lc->ivaNoRecuperable) {
                $flags[] = 'IVA NO RECUPERABLE (entrega gratuita)';
            }
            if ($lc->retencionTotalIva) {
                $flags[] = 'RETENCION TOTAL DEL IVA';
            }
            if ($lc->folioReferenciado !== null) {
                $flags[] = 'Descuento a folio ' . $lc->folioReferenciado;
            }
        ?>
        <tr<?= $flags !== [] ? ' style="background:#fff7e6;"' : ''; ?>>
            <td><?= htmlspecialchars($lc->tipoDocumentoTexto); ?></td>
            <td><?= htmlspecialchars((string) $lc->folio); ?></td>
            <td><?= htmlspecialchars($lc->observacion); ?></td>
            <td><?= htmlspecialchars((string) $lc->montoExento); ?></td>
            <td><?= htmlspecialchars((string) $lc->montoAfecto); ?></td>
            <td>
            <?php if ($flags !== []): ?>
                <strong style="color:#b45309;"><?= htmlspecialchars(implode(' / ', $flags)); ?></strong>
            <?php else: ?>
                &mdash;
            <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php if ($parseado->factorProporcionalidadIvaUsoComun !== null): ?>
<p>Factor de proporcionalidad del IVA uso comun: <strong><?= htmlspecialchars((string) $parseado->factorProporcionalidadIvaUsoComun); ?></strong></p>
<?php endif; ?>

<h2>Emision</h2>
<?php if ($parseado->advertencias !== []): ?>
<p style="color:#999;">Hay advertencias del parser arriba: revisalas antes de emitir --
el Set Basico se construira solo con lo que el parser SI pudo interpretar.</p>
<?php endif; ?>
<p>Al emitir, se envian los <?= count($parseado->casos); ?> documentos del Set Basico al
SII en un solo envio (folios reales, consume CAF). Si algun caso no se puede construir sin
adivinar, no se emite nada: se te mostrara cual y por que.</p>
<form method="post" action="/certificacion/set-pruebas/emitir">
    <?= csrfInput(); ?>
    <button type="submit">Emitir Set Basico</button>
</form>

<?php endif; ?>

<p><a href="/certificacion">Volver a En certificacion</a></p>

<?php require __DIR__ . '/partials/footer.php'; ?>
