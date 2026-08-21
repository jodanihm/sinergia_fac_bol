<?php
/**
 * Pendientes e ideas (GET /admin/pendientes). Lee panel/datos/pendientes.php.
 *
 * Esta lista es lo que FALTA, no un historial: al concretar o descartar un item
 * se borra del archivo y, si se concreto, se registra en el changelog.
 */
$titulo      = 'Pendientes e ideas';
$adminActivo = 'pendientes';
require __DIR__ . '/partials/admin/header.php';

$etiquetaEstado = [
    'nuevo'    => ['Nuevo',     'tag'],
    'en_curso' => ['En curso',  'tag ok'],
    'en_pausa' => ['En pausa',  'tag warn'],
];

$pendientes = array_filter($items, static fn (array $i): bool => $i['tipo'] === 'pendiente');
$ideas      = array_filter($items, static fn (array $i): bool => $i['tipo'] === 'idea');
?>

<h2 class="page-title">Pendientes e ideas</h2>
<p class="muted">
    <?= count($pendientes); ?> pendientes y <?= count($ideas); ?> ideas.
    Un <strong>pendiente</strong> es algo que falta hacer; una <strong>idea</strong> es algo
    que primero hay que decidir si vale la pena. Nada de esto es un olvido: cada item
    dice por que quedo fuera.
</p>

<?php foreach ([['Pendientes', $pendientes], ['Ideas', $ideas]] as [$seccion, $lista]): ?>
<?php if ($lista !== []): ?>
<div class="panel">
    <h3><?= htmlspecialchars($seccion); ?></h3>
    <?php foreach ($lista as $i): ?>
    <?php [$textoEstado, $claseEstado] = $etiquetaEstado[$i['estado']] ?? ['?', 'tag']; ?>
    <div class="pend-item">
        <div class="pend-head">
            <strong><?= htmlspecialchars((string) $i['titulo']); ?></strong>
            <span class="<?= $claseEstado; ?>"><?= htmlspecialchars($textoEstado); ?></span>
        </div>
        <p class="muted"><?= htmlspecialchars((string) $i['detalle']); ?></p>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php endforeach; ?>

<?php require __DIR__ . '/partials/admin/footer.php'; ?>
