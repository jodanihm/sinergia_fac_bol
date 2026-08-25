<?php
/**
 * Ideas (GET /admin/ideas). Lee panel/datos/ideas.php.
 *
 * PANTALLA APARTE DE LOS PENDIENTES, y el motivo esta escrito largo en el
 * archivo de datos: una idea no es trabajo, es una pregunta sin responder. En
 * la misma lista que el backlog se leia como trabajo comprometido y hacia
 * imposible responder "cuanto falta".
 *
 * DELIBERADAMENTE POBRE COMPARADA CON /admin/pendientes: sin contadores, sin
 * filtros, sin cambio de estado. Una idea tiene dos situaciones posibles --
 * sin mirar, o mirada y postergada -- y no se prioriza contra nada, porque
 * todavia no se sabe si vale. Ponerle la maquinaria del backlog seria fingir un
 * proceso que no existe.
 */
$titulo      = 'Ideas';
$adminActivo = 'ideas';
require __DIR__ . '/partials/admin/header.php';

$etiquetaEstado = [
    'nuevo'    => ['Sin mirar', 'tag'],
    'en_pausa' => ['Postergada', 'tag warn'],
];
?>

<h2 class="page-title">Ideas</h2>
<p class="muted">
    <?= count($ideas); ?> cosas que primero hay que <strong>decidir</strong> si valen la pena.
    No son trabajo comprometido: por eso no estan en
    <a href="/admin/pendientes">Pendientes</a>, no tienen prioridad y no cuentan como backlog.
    El dia que se acepta una, se borra de aqui y nace como pendiente; si se descarta, se borra
    y ya.
</p>

<div class="panel">
    <?php if ($ideas === []): ?>
    <p class="muted" style="margin:0;">No hay ninguna idea sin decidir.</p>
    <?php else: ?>
    <?php foreach ($ideas as $i): ?>
    <?php [$textoEstado, $claseEstado] = $etiquetaEstado[$i['estado']] ?? ['?', 'tag']; ?>
    <div class="pend-item">
        <div class="pend-head">
            <strong><?= htmlspecialchars((string) $i['titulo']); ?></strong>
            <span class="<?= $claseEstado; ?>"><?= htmlspecialchars($textoEstado); ?></span>
        </div>
        <p class="muted"><?= htmlspecialchars((string) $i['detalle']); ?></p>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/admin/footer.php'; ?>
