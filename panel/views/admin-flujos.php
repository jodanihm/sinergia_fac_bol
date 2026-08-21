<?php
/**
 * Flujos (GET /admin/flujos). Lee panel/datos/flujos.php, sin base.
 *
 * Cada paso lleva SU RUTA en la etiqueta "donde". Es la diferencia entre una
 * explicacion y una guia: quien atiende el telefono necesita poder decir "anda
 * a /caf-produccion", no "carga los folios en algun lado del panel".
 */
$titulo      = 'Flujos';
$adminActivo = 'flujos';
require __DIR__ . '/partials/admin/header.php';
?>

<h2 class="page-title">Flujos</h2>
<p class="muted">
    Los procesos completos del producto, con la ruta exacta de cada paso.
    Reconstruidos leyendo el router y los guards, no de memoria.
</p>

<?php foreach ($flujos as $f): ?>
<div class="panel">
    <h3><?= htmlspecialchars((string) $f['titulo']); ?></h3>
    <p class="muted" style="margin-top:-.5rem;"><?= htmlspecialchars((string) $f['resumen']); ?></p>

    <div class="flow-diagram">
        <?php foreach ($f['diagrama'] as $i => $caja): ?>
        <?php if ($i > 0): ?><span class="flow-arrow">&rarr;</span><?php endif; ?>
        <div class="flow-node"><?= htmlspecialchars((string) $caja); ?></div>
        <?php endforeach; ?>
    </div>

    <h4 style="font-size:.9rem;margin:1.25rem 0 .5rem;">Que necesitas antes de empezar</h4>
    <div class="chips">
        <?php foreach ($f['necesitas'] as $n): ?>
        <span class="chip"><?= htmlspecialchars((string) $n); ?></span>
        <?php endforeach; ?>
    </div>

    <h4 style="font-size:.9rem;margin:1.25rem 0 .5rem;">Paso a paso</h4>
    <div class="steps">
        <?php foreach ($f['pasos'] as $i => $paso): ?>
        <div class="step">
            <div class="step-num"><?= $i + 1; ?></div>
            <div class="step-body">
                <div class="step-title">
                    <?= htmlspecialchars((string) $paso['titulo']); ?>
                    <span class="step-where"><?= htmlspecialchars((string) $paso['donde']); ?></span>
                </div>
                <div class="step-detail"><?= htmlspecialchars((string) $paso['detalle']); ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

<?php require __DIR__ . '/partials/admin/footer.php'; ?>
