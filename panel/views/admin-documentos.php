<?php
/**
 * Documentos (GET /admin/documentos). Lee panel/datos/documentos.php.
 *
 * Agrupado por 'grupo' y con la columna "Para quien" adelante: en este producto
 * la mayoria de los documentos no son informes internos sino DTE, y quien los
 * recibe -- un tercero, el SII -- cambia por completo lo que significa un error.
 */
$titulo      = 'Documentos';
$adminActivo = 'documentos';
require __DIR__ . '/partials/admin/header.php';

$porGrupo = [];
foreach ($documentos as $d) {
    $porGrupo[$d['grupo']][] = $d;
}
?>

<h2 class="page-title">Documentos</h2>
<p class="muted">
    <?= count($documentos); ?> documentos imprimibles. Los tipos de DTE marcados como listos
    estan comprobados contra <code>TIPOS_PERMITIDOS_PDF</code> en el motor: un tipo que no
    este ahi no tiene PDF por mas que el panel lo emita.
</p>

<?php foreach ($porGrupo as $grupo => $lista): ?>
<div class="panel">
    <h3><?= htmlspecialchars((string) $grupo); ?></h3>
    <div class="tabla-scroll">
    <table>
        <thead>
            <tr><th>Documento</th><th>Para quien</th><th>Desde</th><th>Estado</th><th>Prioridad</th></tr>
        </thead>
        <tbody>
        <?php foreach ($lista as $d): ?>
            <tr>
                <td>
                    <?= htmlspecialchars((string) $d['nombre']); ?>
                    <div class="muted" style="font-size:.82em;margin-top:.2rem;"><?= htmlspecialchars((string) $d['nota']); ?></div>
                </td>
                <td class="muted"><?= htmlspecialchars((string) $d['para']); ?></td>
                <td><code style="font-size:.78em;"><?= htmlspecialchars((string) $d['desde']); ?></code></td>
                <td><span class="tag <?= $d['estado'] === 'listo' ? 'ok' : 'warn'; ?>"><?= htmlspecialchars((string) $d['estado']); ?></span></td>
                <td class="muted"><?= htmlspecialchars((string) $d['prioridad']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endforeach; ?>

<?php require __DIR__ . '/partials/admin/footer.php'; ?>
