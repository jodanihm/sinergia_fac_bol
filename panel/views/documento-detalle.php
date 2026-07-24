<?php
$titulo = 'Documento ' . $tipoDte . '/' . $folio;
require __DIR__ . '/partials/header.php';

$nombresTipo = [33 => 'Factura', 61 => 'Nota de credito', 56 => 'Nota de debito', 39 => 'Boleta'];
$tipoNombre  = $nombresTipo[$tipoDte] ?? ('Documento tipo ' . $tipoDte);

$fmt = static function ($v): string {
    return $v === null || $v === '' ? '-' : htmlspecialchars((string) $v);
};

// Query string comun para prellenar la referencia en el form de NC/ND (M3).
$qsRef = static function (string $codigo, string $razon) use ($tipoDte, $folio, $documento): string {
    return '?' . http_build_query([
        'ref_tipo'  => $tipoDte,
        'ref_folio' => $folio,
        'ref_fecha' => $documento['fechaEmision'] ?? '',
        'ref_codigo' => $codigo,
        'ref_razon'  => $razon,
    ]);
};
?>

<h1><?= htmlspecialchars($tipoNombre); ?> N&deg; <?= $folio; ?></h1>

<?php if (! empty($flash['mensaje'])): ?>
    <p style="padding:0.5rem 0.75rem;border-radius:4px;<?= ($flash['tipo'] ?? '') === 'exito'
        ? 'background:#f1f8f1;color:#2e7d32;border:1px solid #2e7d32;'
        : 'background:#fdecea;color:#b00020;border:1px solid #b00020;'; ?>">
        <?= htmlspecialchars($flash['mensaje']); ?>
    </p>
<?php endif; ?>

<?php if (! empty($errorMotor)): ?>
    <p style="padding:0.5rem 0.75rem;border-radius:4px;background:#fdecea;color:#b00020;border:1px solid #b00020;">
        <?= htmlspecialchars($errorMotor); ?>
    </p>
<?php endif; ?>

<?php if ($documento === null): ?>
    <p>No se encontro el documento (folio <?= $folio; ?>, tipo <?= $tipoDte; ?>).</p>
<?php else: ?>
    <table class="tabla-resultado">
        <tbody>
            <tr><th style="text-align:left;">Tipo</th><td><?= htmlspecialchars($tipoNombre); ?> (<?= $tipoDte; ?>)</td></tr>
            <tr><th style="text-align:left;">Folio</th><td><?= $folio; ?></td></tr>
            <tr><th style="text-align:left;">Fecha emision</th><td><?= $fmt($documento['fechaEmision'] ?? null); ?></td></tr>
            <tr><th style="text-align:left;">Receptor</th><td>
                <?= $fmt($documento['receptorRut'] ?? null); ?>
                <?php if (! empty($documento['receptorRazonSocial'])): ?>
                    &mdash; <?= htmlspecialchars((string) $documento['receptorRazonSocial']); ?>
                <?php endif; ?>
            </td></tr>
            <tr><th style="text-align:left;">Neto</th><td><?= $fmt($documento['neto'] ?? null); ?></td></tr>
            <tr><th style="text-align:left;">IVA</th><td><?= $fmt($documento['iva'] ?? null); ?></td></tr>
            <tr><th style="text-align:left;">Total</th><td><?= $fmt($documento['total'] ?? null); ?></td></tr>
            <tr><th style="text-align:left;">Estado</th><td><?= $fmt($documento['estado'] ?? null); ?></td></tr>
            <tr><th style="text-align:left;">Track ID (SII)</th><td><?= $fmt($documento['trackId'] ?? null); ?></td></tr>
            <?php if (! empty($documento['folioRef'])): ?>
                <tr><th style="text-align:left;">Referencia</th>
                    <td>Tipo <?= (int) $documento['tipoDteRef']; ?>, folio <?= (int) $documento['folioRef']; ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <p style="margin-top:1.25rem;">
        <a href="/ventas/panel-emision/<?= $tipoDte; ?>/<?= $folio; ?>/pdf" target="_blank">Descargar PDF</a>
        &nbsp;|&nbsp;
        <a href="/ventas/panel-emision/<?= $tipoDte; ?>/<?= $folio; ?>/xml">Descargar XML</a>
    </p>

    <form method="post" action="/ventas/panel-emision/<?= $tipoDte; ?>/<?= $folio; ?>/estado-sii" style="display:inline;">
        <?= csrfInput(); ?>
        <button type="submit">Consultar estado SII</button>
    </form>

    <h2>Acciones</h2>
    <p>
        <a href="/ventas/nota-credito<?= htmlspecialchars($qsRef('1', 'Anula documento N ' . $folio)); ?>">Anular con Nota de Credito</a>
        &nbsp;|&nbsp;
        <a href="/ventas/nota-credito<?= htmlspecialchars($qsRef('3', '')); ?>">Corregir con Nota de Credito</a>
        &nbsp;|&nbsp;
        <a href="/ventas/nota-debito<?= htmlspecialchars($qsRef('', '')); ?>">Nota de Debito de referencia</a>
    </p>
<?php endif; ?>

<p><a href="/ventas/panel-emision">Volver al listado</a></p>

<?php require __DIR__ . '/partials/footer.php'; ?>
