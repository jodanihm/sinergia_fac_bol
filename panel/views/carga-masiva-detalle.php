<?php
$titulo = 'Lote de carga #' . $lote['id'];
require __DIR__ . '/partials/header.php';

$fmtMonto = static function ($v): string {
    return $v === null ? '-' : number_format((float) $v, 0, ',', '.');
};
?>

<h1>Lote de carga #<?= (int) $lote['id']; ?></h1>

<table class="tabla-resultado">
    <tbody>
        <tr><th style="text-align:left;">Archivo</th><td><?= htmlspecialchars((string) $lote['nombre_archivo']); ?></td></tr>
        <tr><th style="text-align:left;">Fecha de carga</th><td><?= htmlspecialchars((string) $lote['created_at']); ?></td></tr>
        <tr><th style="text-align:left;">Total filas</th><td><?= (int) $lote['total_filas']; ?></td></tr>
        <tr><th style="text-align:left;">Validas</th><td><?= (int) $lote['filas_validas']; ?></td></tr>
        <tr><th style="text-align:left;">Con error</th><td><?= (int) $lote['filas_error']; ?></td></tr>
    </tbody>
</table>

<h2 style="margin-top:1.5rem;">Notas de este lote</h2>

<?php if ($notas === []): ?>
    <p>Este lote no tiene filas.</p>
<?php else: ?>
    <div class="tabla-scroll">
        <table class="tabla-notas-venta">
            <thead>
                <tr>
                    <th>Estado</th><th>Identificador</th><th>Receptor</th>
                    <th>Fecha nota</th><th>Monto est.</th><th>Detalle</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($notas as $n): ?>
                    <?php $esError = $n['estado'] === 'error'; ?>
                    <tr<?= $esError ? ' class="fila-inactiva"' : ''; ?>>
                        <td><?= htmlspecialchars((string) $n['estado']); ?></td>
                        <td><?= htmlspecialchars((string) ($n['identificador_externo'] ?? '-')); ?></td>
                        <td>
                            <?= htmlspecialchars((string) ($n['receptor_rut'] ?? '-')); ?>
                            <?php if (! empty($n['receptor_razon_social'])): ?>
                                <br><small><?= htmlspecialchars((string) $n['receptor_razon_social']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars((string) ($n['fecha_nota'] ?? '-')); ?></td>
                        <td><?= $fmtMonto($n['monto_estimado'] ?? null); ?></td>
                        <td>
                            <?php if ($esError): ?>
                                <span style="color:#b00020;"><?= htmlspecialchars((string) $n['error_mensaje']); ?></span>
                                <?php if (! empty($n['fila_original'])): ?>
                                    <details>
                                        <summary>Fila original</summary>
                                        <pre style="white-space:pre-wrap;font-size:0.8rem;"><?= htmlspecialchars((string) $n['fila_original']); ?></pre>
                                    </details>
                                <?php endif; ?>
                            <?php elseif ($n['estado'] === 'facturada' && ! empty($n['resultado_documentos'])): ?>
                                <?php $docs = json_decode((string) $n['resultado_documentos'], true) ?: []; ?>
                                <?php foreach ($docs as $doc): ?>
                                    <?= htmlspecialchars(nombreTipoDte((int) ($doc['tipoDte'] ?? 0))); ?>
                                    folio <?= htmlspecialchars((string) ($doc['folio'] ?? '-')); ?><br>
                                <?php endforeach; ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<p style="margin-top:1.25rem;">
    <a href="/ventas/carga-masiva">Volver a carga masiva</a>
    &nbsp;|&nbsp;
    <a href="/ventas/facturacion-masiva">Ir a facturacion masiva</a>
</p>

<?php require __DIR__ . '/partials/footer.php'; ?>
