<?php
/**
 * Tareas programadas (GET /admin/tareas). Lee panel/datos/tareas_programadas.php.
 *
 * LA COLUMNA QUE IMPORTA ES "CUANDO", no la expresion de cron. Quien abre esta
 * pantalla casi nunca viene a descifrar los cinco campos: viene a saber si algo
 * ya deberia haber corrido. Por eso el horario se muestra primero en castellano y
 * con la proxima corrida en hora de Chile, y la expresion queda al lado, en
 * chico, como respaldo para quien si la lee.
 *
 * ESTA LISTA ES UN CALENDARIO, NO UN MONITOR: proyecta lo que cron VA a hacer y
 * no sabe si la ultima corrida termino bien. Quien quiera eso pincha el nombre
 * y llega a admin-tarea-detalle.php, que abre la bitacora. Se mantienen
 * separadas a proposito -- la lista se lee de un vistazo y no tiene por que
 * pagar la lectura de tres archivos de log para dibujarse.
 *
 * SI UNA EXPRESION NO SE ENTIENDE no se cae la pagina: esa fila queda marcada y
 * las otras se siguen viendo. El archivo de datos esta versionado y hay un test
 * que lo valida, pero una pantalla de solo lectura que devuelve un 500 por un
 * dato mal escrito es un problema peor que el dato mal escrito.
 */
$titulo      = 'Tareas programadas';
$adminActivo = 'tareas';
require __DIR__ . '/partials/admin/header.php';

$ahora = new DateTimeImmutable('now');
?>

<h2 class="page-title">Tareas programadas</h2>
<p class="muted">
    <?= count($tareas); ?> tareas que corren solas en el servidor, sin que nadie apriete nada.
    Las horas son de Chile. Esta pagina es el <strong>calendario</strong>: dice cuando le toca a
    cada una. Para saber si la ultima vez resulto, <strong>pincha el nombre</strong> y se abre su
    bitacora.
</p>

<div class="panel">
    <h3>Cuando corre cada una</h3>
    <div class="tabla-scroll">
    <table>
        <thead>
            <tr><th>Tarea</th><th>Cada cuanto</th><th>Proximas corridas</th><th>Donde corre</th></tr>
        </thead>
        <tbody>
        <?php foreach ($tareas as $t): ?>
            <?php
            try {
                $cuando   = AgendaCron::enPalabras((string) $t['expresion']);
                $proximas = AgendaCron::proximas((string) $t['expresion'], $ahora, 3);
                $ilegible = false;
            } catch (InvalidArgumentException $e) {
                $cuando   = 'expresion no reconocida';
                $proximas = [];
                $ilegible = true;
            }
            ?>
            <tr>
                <td>
                    <a href="/admin/tareas/<?= htmlspecialchars((string) $t['id']); ?>"><strong><?= htmlspecialchars((string) $t['nombre']); ?></strong></a>
                    <div class="muted" style="font-size:.82em;margin-top:.2rem;">
                        <?= htmlspecialchars((string) $t['proposito']); ?>
                    </div>
                </td>
                <td>
                    <span class="tag <?= $ilegible ? 'err' : 'ok'; ?>"><?= htmlspecialchars($cuando); ?></span>
                    <div class="muted" style="font-size:.78em;margin-top:.3rem;">
                        <code><?= htmlspecialchars((string) $t['expresion']); ?></code>
                    </div>
                </td>
                <td>
                    <?php if ($proximas === []): ?>
                        <span class="muted">&mdash;</span>
                    <?php else: ?>
                        <?php foreach ($proximas as $i => $p): ?>
                        <div<?= $i === 0 ? '' : ' class="muted"'; ?> style="font-size:.85em;">
                            <?= htmlspecialchars($p->format('d-m-Y H:i')); ?>
                            <?php if ($i === 0): ?>
                            <span class="muted">(en <?= htmlspecialchars(AgendaCron::faltan($ahora, $p)); ?>)</span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <code style="font-size:.78em;"><?= htmlspecialchars((string) $t['contenedor']); ?></code>
                    <div class="muted" style="font-size:.78em;margin-top:.2rem;">
                        <?= htmlspecialchars((string) $t['comando']); ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<div class="panel">
    <h3>Antes de tocarle la frecuencia a una</h3>
    <p class="muted">
        Cada tarea esta escrita esperando SU intervalo. Cambiar la linea en el servidor sin
        ajustar el script de adentro es la forma mas facil de romperlas, asi que aca queda dicho
        que supone cada una y donde vive su bitacora.
    </p>
    <?php foreach ($tareas as $t): ?>
    <div class="pend-item">
        <div class="pend-head">
            <strong><?= htmlspecialchars((string) $t['nombre']); ?></strong>
            <span class="tag"><?= htmlspecialchars((string) $t['expresion']); ?></span>
        </div>
        <p class="muted"><?= htmlspecialchars((string) $t['nota']); ?></p>
        <p class="muted" style="font-size:.82em;">
            Linea de cron: <code><?= htmlspecialchars((string) $t['archivo']); ?></code>
            &middot; Bitacora: <code><?= htmlspecialchars((string) $t['log']); ?></code>
        </p>
    </div>
    <?php endforeach; ?>
</div>

<?php require __DIR__ . '/partials/admin/footer.php'; ?>
