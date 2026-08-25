<?php
/**
 * Bitacora de UNA tarea programada (GET /admin/tareas/{id}).
 *
 * Recibe $tarea (la fila del catalogo, o null si el id no existe) y $bitacora
 * (lo que devolvio BitacoraTarea::leer(), o null en el 404).
 *
 * EL VEREDICTO VA ARRIBA Y EL LOG CRUDO ABAJO, en ese orden y no al reves.
 * Quien entra viene con una pregunta de una linea -- "esto anda o no" -- y el
 * log crudo no se la responde: son diez mil lineas donde el 99% dice "cola
 * vacia". El veredicto la contesta; el log esta despues para quien necesite
 * comprobarlo o entender que paso exactamente.
 *
 * LO QUE EL VEREDICTO NO DICE, y esta escrito en la pagina: que la tarea se
 * EJECUTO. Estos archivos son la salida del script, asi que solo prueban lo que
 * el script alcanzo a escribir. Si el cron nunca lo llamo, no hay linea y no
 * hay como distinguirlo desde aca de un script que corrio y se callo. El latido
 * de verdad esta en el journal del host (journalctl -u cron), fuera del
 * alcance del contenedor. Prometer mas que eso seria justo el tipo de falso OK
 * que hace que la gente deje de revisar el servidor.
 */
$titulo      = 'Tareas programadas';
$adminActivo = 'tareas';
require __DIR__ . '/partials/admin/header.php';
?>

<p class="muted" style="margin-top:0;"><a href="/admin/tareas">&larr; Tareas programadas</a></p>

<?php if ($tarea === null): ?>

<h2 class="page-title">Esa tarea no existe</h2>
<p class="muted">
    No hay ninguna tarea programada con ese identificador. Las que hay estan en
    <a href="/admin/tareas">la lista</a>.
</p>

<?php else: ?>

<?php
$ahora       = new DateTimeImmutable('now');
$ultimaSenal = BitacoraTarea::ultimaSenal($bitacora['lineas']);
$fallos      = BitacoraTarea::contarFallos($bitacora['lineas']);

// El intervalo sale de la expresion, no de un numero escrito a mano: es la
// distancia entre dos corridas consecutivas. Si la expresion no se entiende se
// pasa null y el veredicto se abstiene de opinar sobre el silencio.
$intervalo = null;
$cuando    = $tarea['expresion'];

try {
    $cuando = AgendaCron::enPalabras((string) $tarea['expresion']);
    $dos    = AgendaCron::proximas((string) $tarea['expresion'], $ahora, 2);

    if (count($dos) === 2) {
        $intervalo = $dos[1]->getTimestamp() - $dos[0]->getTimestamp();
    }
} catch (InvalidArgumentException $e) {
    // La lista ya marca esta condicion; aca solo se evita opinar de mas.
}

$v = BitacoraTarea::veredicto(
    (bool) $bitacora['disponible'],
    (string) $bitacora['motivo'],
    (string) $tarea['bitacora'],
    $ultimaSenal,
    $ahora,
    $intervalo,
    $fallos,
    count($bitacora['lineas'])
);

$punto = ['ok' => '&#9679;', 'atencion' => '&#9679;', 'sin_datos' => '&#9679;'][$v['estado']] ?? '&#9679;';
?>

<h2 class="page-title"><?= htmlspecialchars((string) $tarea['nombre']); ?></h2>
<p class="muted"><?= htmlspecialchars((string) $tarea['proposito']); ?></p>

<div class="veredicto <?= htmlspecialchars($v['estado']); ?>">
    <span class="punto"><?= $punto; ?></span>
    <div>
        <h3><?= htmlspecialchars($v['titulo']); ?></h3>
        <p><?= htmlspecialchars($v['detalle']); ?></p>
    </div>
</div>

<div class="panel">
    <h3>La tarea</h3>
    <div class="tabla-scroll">
    <table>
        <tbody>
            <tr>
                <th style="width:14rem;">Cada cuanto</th>
                <td><?= htmlspecialchars($cuando); ?> <code><?= htmlspecialchars((string) $tarea['expresion']); ?></code></td>
            </tr>
            <tr>
                <th>Proximas corridas</th>
                <td>
                    <?php
                    try {
                        $proximas = AgendaCron::proximas((string) $tarea['expresion'], $ahora, 3);
                    } catch (InvalidArgumentException $e) {
                        $proximas = [];
                    }
                    ?>
                    <?php if ($proximas === []): ?>
                        <span class="muted">&mdash;</span>
                    <?php else: ?>
                        <?= implode('  &middot;  ', array_map(
                            static fn (DateTimeImmutable $p): string => htmlspecialchars($p->format('d-m-Y H:i')),
                            $proximas
                        )); ?>
                    <?php endif; ?>
                </td>
            </tr>
            <tr><th>Donde corre</th><td><code><?= htmlspecialchars((string) $tarea['contenedor']); ?></code> &mdash; <?= htmlspecialchars((string) $tarea['comando']); ?></td></tr>
            <tr><th>Linea de cron</th><td><code><?= htmlspecialchars((string) $tarea['archivo']); ?></code></td></tr>
            <tr><th>Bitacora</th><td><code><?= htmlspecialchars((string) $tarea['log']); ?></code></td></tr>
        </tbody>
    </table>
    </div>
    <p class="muted" style="font-size:.85rem;margin-bottom:0;"><?= htmlspecialchars((string) $tarea['nota']); ?></p>
</div>

<div class="panel">
    <h3>Bitacora</h3>

    <?php if (!$bitacora['disponible']): ?>
    <p class="muted">
        No se pudo leer <code><?= htmlspecialchars((string) $tarea['log']); ?></code>:
        <?= htmlspecialchars((string) $bitacora['motivo']); ?>.
        El panel la lee por un montaje de solo lectura declarado en
        <code>docker-compose.vps.yml</code>. El montaje viaja con el proyecto, pero el archivo
        lo crea el cron del host la primera vez que escribe: en una maquina donde esos crones
        no esten instalados no hay bitacora que mostrar.
    </p>
    <?php else: ?>

    <p class="muted" style="font-size:.85rem;">
        <?= count($bitacora['lineas']); ?> ultimas lineas
        <?php if ($bitacora['truncado']): ?>
        de un archivo de <?= number_format($bitacora['tamano'] / 1024, 0, ',', '.'); ?> KB
        <?php endif; ?>
        <?php if ($bitacora['modificado'] !== null): ?>
        &middot; escrita por ultima vez el <?= htmlspecialchars(date('d-m-Y H:i', (int) $bitacora['modificado'])); ?>
        <?php endif; ?>
        <?php if ($tarea['bitacora'] === 'eventos'): ?>
        &middot; <strong>este log solo escribe cuando pasa algo</strong>, no en cada corrida
        <?php endif; ?>
    </p>

    <?php if ($bitacora['lineas'] === []): ?>
    <p class="muted">El archivo esta vacio.</p>
    <?php else: ?>
    <pre class="bitacora"><?php foreach ($bitacora['lineas'] as $l): ?><span class="ln <?= BitacoraTarea::clasificar($l); ?>"><?= htmlspecialchars($l); ?></span><?php endforeach; ?></pre>
    <?php endif; ?>

    <p class="muted" style="font-size:.82rem;margin-bottom:0;">
        Esto es lo que el script escribio. Que el cron lo HAYA LLAMADO se comprueba en el
        journal del host, con <code>journalctl -u cron</code>: si el script nunca arranco no
        hay linea, y desde aca no se distingue de uno que corrio y no tenia nada que decir.
    </p>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php require __DIR__ . '/partials/admin/footer.php'; ?>
