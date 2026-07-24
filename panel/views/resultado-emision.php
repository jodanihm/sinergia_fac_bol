<?php
$titulo = 'Documento emitido';
require __DIR__ . '/partials/header.php';

$nombres = [33 => 'Factura electronica', 61 => 'Nota de credito', 56 => 'Nota de debito'];
$tipoDte = (int) ($resultado['tipoDte'] ?? 0);
$tipoNombre = $nombres[$tipoDte] ?? ('Documento tipo ' . $tipoDte);

$rutaNueva = match ($tipoDte) {
    61 => '/ventas/nota-credito',
    56 => '/ventas/nota-debito',
    default => '/ventas/factura',
};

$fmt = static function ($v): string {
    return $v === null || $v === '' ? '-' : htmlspecialchars((string) $v);
};
?>

<h1>Documento emitido</h1>

<p style="padding:0.5rem 0.75rem;border-radius:4px;background:#f1f8f1;color:#2e7d32;border:1px solid #2e7d32;">
    Se emitio la <?= htmlspecialchars($tipoNombre); ?> correctamente.
</p>

<table class="tabla-resultado">
    <tbody>
        <tr><th style="text-align:left;">Tipo</th><td><?= htmlspecialchars($tipoNombre); ?> (<?= $tipoDte; ?>)</td></tr>
        <tr><th style="text-align:left;">Folio</th><td><?= $fmt($resultado['folio'] ?? null); ?></td></tr>
        <tr><th style="text-align:left;">Estado</th><td><?= $fmt($resultado['estado'] ?? null); ?></td></tr>
        <tr><th style="text-align:left;">Track ID (SII)</th><td><?= $fmt($resultado['trackId'] ?? null); ?></td></tr>
        <tr><th style="text-align:left;">Fecha emision</th><td><?= $fmt($resultado['fchEmis'] ?? null); ?></td></tr>
        <tr><th style="text-align:left;">Neto</th><td><?= $fmt($resultado['neto'] ?? null); ?></td></tr>
        <tr><th style="text-align:left;">IVA</th><td><?= $fmt($resultado['iva'] ?? null); ?></td></tr>
        <tr><th style="text-align:left;">Total</th><td><?= $fmt($resultado['total'] ?? null); ?></td></tr>
    </tbody>
</table>

<p style="margin-top:1.25rem;">
    <a href="<?= htmlspecialchars($rutaNueva); ?>">Emitir otro documento</a>
    &nbsp;|&nbsp;
    <a href="/panel">Volver al panel</a>
</p>

<?php require __DIR__ . '/partials/footer.php'; ?>
