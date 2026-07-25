<?php
/**
 * Dashboard de PROGRESO (7 estaciones). Es el dashboard que ve un tenant
 * mientras no tenga completos los 3 pasos obligatorios de produccion; con
 * ellos completos, handlePanelGet() renderiza panel-gestion.php en su lugar.
 *
 * Los sub-pasos de la estacion 7 usan clases reales (.subpasos / .subpaso),
 * no estilos inline: este bloque se comparte con el progreso colapsado del
 * dashboard de gestion.
 */
$titulo = 'Panel';
require __DIR__ . '/partials/header.php';
?>

<h1>Tu progreso</h1>

<?php require __DIR__ . '/partials/_estaciones.php'; ?>

<?php if ($mostrarApiKeys): ?>
<section class="tarjeta" aria-labelledby="titulo-credenciales">
    <h2 id="titulo-credenciales">Credenciales de API</h2>
    <p>Genera o administra las API keys para conectar tus sistemas al motor de
    facturacion.</p>
    <p><a href="/apikeys">Ver / generar API keys</a></p>
</section>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
