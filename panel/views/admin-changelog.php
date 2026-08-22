<?php
/**
 * Changelog (GET /admin/changelog). Lee panel/datos/changelog.php, sin base.
 * Lo mas nuevo arriba, que es como esta guardado el array.
 */
$titulo      = 'Changelog';
$adminActivo = 'changelog';
require __DIR__ . '/partials/admin/header.php';
?>

<h2 class="page-title">Changelog</h2>
<p class="muted">
    <?= count($entradas); ?> entradas. Por cada cambio en el proyecto se agrega una arriba,
    subiendo la version, escrita para que la entienda quien no programa.
</p>

<div class="panel">
    <?php foreach ($entradas as $e): ?>
    <div class="cl-entry">
        <div class="meta">
            <div class="ver">v<?= htmlspecialchars((string) $e['version']); ?></div>
            <div class="date"><?= htmlspecialchars((string) $e['fecha']); ?></div>
            <span class="cl-tag"><?= htmlspecialchars((string) $e['tag']); ?></span>
        </div>
        <div>
            <h4><?= htmlspecialchars((string) $e['titulo']); ?></h4>
            <ul>
                <?php foreach ($e['items'] as $item): ?>
                <li><?= htmlspecialchars((string) $item); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php require __DIR__ . '/partials/admin/footer.php'; ?>
