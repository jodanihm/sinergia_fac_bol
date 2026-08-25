<?php
/**
 * Ficha de un pendiente (GET /admin/pendientes/{id}).
 *
 * Recibe $item (la fila, o null si no existe) y $cerradoPor (el email de quien
 * lo cerro, o null).
 *
 * EL CAMBIO DE ESTADO SON CINCO BOTONES Y NO UN <select> CON "Guardar". Es una
 * accion, no la edicion de un formulario: cada boton dice a donde va el item y
 * se llega en un click. Un select mas un submit son tres interacciones para lo
 * mismo, y dejan el estado a medio cambiar si alguien se va sin apretar.
 *
 * EL ESTADO ACTUAL NO TIENE BOTON: se muestra como etiqueta. Un boton que no
 * hace nada es una promesa incumplida, y ademas su POST escribiria una fila de
 * auditoria que dice que se cambio de 'abierto' a 'abierto'.
 *
 * SOLO SE EDITA EL ESTADO. El titulo, el detalle y los cuatro ejes de
 * clasificacion se siguen escribiendo en scripts/sembrar_pendientes.php y se
 * versionan: son texto que hay que pensar, no un dato que se toca al pasar. El
 * estado es lo contrario -- cambia varias veces por semana -- y era justo lo
 * que obligaba a desplegar para anotar algo. Se resolvio eso y nada mas, a
 * proposito.
 */
$titulo      = 'Pendientes';
$adminActivo = 'pendientes';
require __DIR__ . '/partials/admin/header.php';
?>

<p class="muted" style="margin-top:0;"><a href="/admin/pendientes">&larr; Pendientes</a></p>

<?php if ($item === null): ?>

<h2 class="page-title">Ese pendiente no existe</h2>
<p class="muted">
    No hay ningun pendiente con ese numero. Puede que se haya borrado.
    Los que hay estan en <a href="/admin/pendientes">la lista</a>.
</p>

<?php else: ?>

<h2 class="page-title">
    <span class="muted">#<?= (int) $item['id']; ?></span>
    <?= htmlspecialchars((string) $item['titulo']); ?>
</h2>

<div class="chips" style="margin-bottom:1.25rem;">
    <span class="pri pri--<?= htmlspecialchars((string) $item['prioridad']); ?>"><?= htmlspecialchars((string) $item['prioridad']); ?></span>
    <span class="chip"><?= htmlspecialchars(Pendientes::AREAS[$item['area']] ?? (string) $item['area']); ?></span>
    <span class="chip"><?= htmlspecialchars(Pendientes::CATEGORIAS[$item['categoria']] ?? (string) $item['categoria']); ?></span>
    <span class="tag <?= Pendientes::claseSeveridad((string) $item['severidad']); ?>">severidad <?= htmlspecialchars((string) $item['severidad']); ?></span>
    <span class="tag <?= Pendientes::claseEstado((string) $item['estado']); ?>"><?= htmlspecialchars(Pendientes::ESTADOS[$item['estado']] ?? (string) $item['estado']); ?></span>
</div>

<div class="panel">
    <h3>El detalle</h3>
    <p style="line-height:1.65;margin:0;"><?= nl2br(htmlspecialchars((string) $item['detalle'])); ?></p>
</div>

<div class="panel">
    <h3>Cambiar el estado</h3>
    <p class="muted" style="font-size:.85rem;">
        Cada cambio queda en la <a href="/admin/auditoria">auditoria</a>, con quien lo hizo y como
        estaba antes. Pasar a <strong>hecho</strong> o <strong>descartado</strong> sella la fecha de
        cierre; sacarlo de ahi la borra y el item vuelve al listado.
    </p>
    <div class="actions">
        <?php foreach (Pendientes::ESTADOS as $clave => $etiqueta): ?>
            <?php if ($clave === $item['estado']): ?>
        <span class="tag <?= Pendientes::claseEstado($clave); ?>">ahora: <?= htmlspecialchars($etiqueta); ?></span>
            <?php else: ?>
        <form method="post" action="/admin/pendientes/<?= (int) $item['id']; ?>/estado" style="display:inline;">
            <?= csrfInput(); ?>
            <input type="hidden" name="estado" value="<?= htmlspecialchars($clave); ?>">
            <button class="btn ghost sm" type="submit">Pasar a <?= htmlspecialchars($etiqueta); ?></button>
        </form>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>

<div class="panel">
    <h3>Ficha</h3>
    <div class="tabla-scroll">
    <table>
        <tbody>
            <tr><th style="width:14rem;">Creado</th><td><?= htmlspecialchars(date('d-m-Y H:i', strtotime((string) $item['created_at']))); ?></td></tr>
            <tr><th>Ultimo cambio</th><td><?= htmlspecialchars(date('d-m-Y H:i', strtotime((string) $item['updated_at']))); ?></td></tr>
            <tr>
                <th>Cerrado</th>
                <td>
                    <?php if ($item['cerrado_at'] === null): ?>
                    <span class="muted">sigue abierto</span>
                    <?php else: ?>
                    <?= htmlspecialchars(date('d-m-Y H:i', strtotime((string) $item['cerrado_at']))); ?>
                    <?php if ($cerradoPor !== null): ?>
                    por <?= htmlspecialchars($cerradoPor); ?>
                    <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
        </tbody>
    </table>
    </div>
</div>

<?php endif; ?>

<?php require __DIR__ . '/partials/admin/footer.php'; ?>
