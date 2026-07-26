<?php
/**
 * Informes: landing con una tarjeta por informe.
 *
 * Recibe: $informes (la constante INFORMES tal cual) y $navActivo.
 *
 * No hay logica aqui: la lista sale del catalogo cerrado del front controller,
 * asi que agregar un informe es agregar una entrada a INFORMES y esta pantalla
 * lo muestra sola. Si esta vista tuviera su propia lista, las dos podrian
 * desincronizarse.
 *
 * Todos los informes exigen produccion completa (exigirProduccionCompleto() en
 * el handler): quien llega hasta aqui ya paso ese filtro, por eso la pantalla
 * no repite la advertencia.
 */
$titulo = 'Informes';
require __DIR__ . '/partials/header.php';
?>

<div class="dash-header">
    <div>
        <h1>Informes</h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <a class="boton-secundario" href="/panel">Volver al panel</a>
    </div>
</div>
<p class="dash-subtitulo">
    Reportes de tu actividad en produccion. Cada uno se puede ver en pantalla y descargar
    en PDF o Excel para usarlo fuera del panel.
</p>

<div class="dash-grid">
    <?php foreach ($informes as $clave => $inf): ?>
        <section class="tarjeta">
            <h2><a href="/informes/<?= htmlspecialchars((string) $clave); ?>"><?= htmlspecialchars((string) $inf['label']); ?></a></h2>
            <p class="nota"><?= htmlspecialchars((string) $inf['descripcion']); ?></p>
            <p>
                <?php if ($inf['periodo']): ?>
                    <span class="badge badge--etiqueta">Con rango de fechas</span>
                <?php else: ?>
                    <span class="badge badge--neutro">Estado actual</span>
                <?php endif; ?>
            </p>
        </section>
    <?php endforeach; ?>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
