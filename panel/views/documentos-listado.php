<?php
$titulo = 'Panel de emision';
require __DIR__ . '/partials/header.php';

$nombresTipo = [33 => 'Factura', 61 => 'Nota de credito', 56 => 'Nota de debito', 39 => 'Boleta'];

$qs = '';
foreach ($filtros as $campo => $valor) {
    $qs .= '&' . $campo . '=' . urlencode((string) $valor);
}

$fmtMonto = static function ($v): string {
    return $v === null ? '-' : number_format((float) $v, 0, ',', '.');
};
?>

<h1>Panel de emision</h1>

<?php if (! empty($errorMotor)): ?>
    <p style="padding:0.5rem 0.75rem;border-radius:4px;background:#fdecea;color:#b00020;border:1px solid #b00020;">
        <?= htmlspecialchars($errorMotor); ?>
    </p>
<?php endif; ?>

<form method="get" action="/ventas/panel-emision" style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:end;margin:0.75rem 0;">
    <label style="margin:0;">Desde
        <input type="date" name="desde" value="<?= htmlspecialchars($filtros['desde'] ?? ''); ?>" style="width:auto;">
    </label>
    <label style="margin:0;">Hasta
        <input type="date" name="hasta" value="<?= htmlspecialchars($filtros['hasta'] ?? ''); ?>" style="width:auto;">
    </label>
    <label style="margin:0;">Tipo
        <select name="tipoDte" style="width:auto;">
            <option value="">Todos</option>
            <?php foreach ($nombresTipo as $t => $n): ?>
                <option value="<?= $t; ?>" <?= (string) ($filtros['tipoDte'] ?? '') === (string) $t ? 'selected' : ''; ?>><?= htmlspecialchars($n); ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label style="margin:0;">Folio
        <input type="text" inputmode="numeric" name="folio" value="<?= htmlspecialchars($filtros['folio'] ?? ''); ?>" style="width:6rem;">
    </label>
    <label style="margin:0;">RUT receptor
        <input type="text" name="receptorRut" value="<?= htmlspecialchars($filtros['receptorRut'] ?? ''); ?>" style="width:9rem;">
    </label>
    <label style="margin:0;">Estado
        <input type="text" name="estado" value="<?= htmlspecialchars($filtros['estado'] ?? ''); ?>" style="width:8rem;">
    </label>
    <button type="submit" style="margin:0;">Filtrar</button>
    <?php if ($filtros !== []): ?>
        <a href="/ventas/panel-emision">Limpiar filtros</a>
    <?php endif; ?>
</form>

<?php if ($items === []): ?>
    <p>No hay documentos<?= $filtros !== [] ? ' que coincidan con el filtro' : ''; ?>.</p>
<?php else: ?>
    <div class="tabla-scroll">
        <table class="tabla-documentos">
            <thead>
                <tr>
                    <th>Folio</th><th>Tipo</th><th>Fecha</th><th>Receptor</th>
                    <th>Neto</th><th>IVA</th><th>Total</th><th>Estado</th><th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $it): ?>
                    <?php $tipoDte = (int) $it['tipoDte']; ?>
                    <tr>
                        <td><?= (int) $it['folio']; ?></td>
                        <td><?= htmlspecialchars($nombresTipo[$tipoDte] ?? ('Tipo ' . $tipoDte)); ?></td>
                        <td><?= htmlspecialchars((string) $it['fechaEmision']); ?></td>
                        <td>
                            <?= htmlspecialchars((string) $it['receptorRut']); ?>
                            <?php if (! empty($it['receptorRazonSocial'])): ?>
                                <br><small><?= htmlspecialchars((string) $it['receptorRazonSocial']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= $fmtMonto($it['neto'] ?? null); ?></td>
                        <td><?= $fmtMonto($it['iva'] ?? null); ?></td>
                        <td><?= $fmtMonto($it['total'] ?? null); ?></td>
                        <td><?= htmlspecialchars((string) ($it['estado'] ?? '-')); ?></td>
                        <td class="acciones">
                            <a href="/ventas/panel-emision/<?= $tipoDte; ?>/<?= (int) $it['folio']; ?>">Ver detalle</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPaginas > 1): ?>
        <p class="paginacion">
            <?php if ($pagina > 1): ?>
                <a href="/ventas/panel-emision?pagina=<?= $pagina - 1; ?><?= $qs; ?>">&larr; Anterior</a>
            <?php endif; ?>
            Pagina <?= $pagina; ?> de <?= $totalPaginas; ?> (<?= $total; ?> documentos)
            <?php if ($pagina < $totalPaginas): ?>
                <a href="/ventas/panel-emision?pagina=<?= $pagina + 1; ?><?= $qs; ?>">Siguiente &rarr;</a>
            <?php endif; ?>
        </p>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
