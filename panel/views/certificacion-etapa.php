<?php
/**
 * Certificacion de factura: una etapa. La barra de arriba dice donde estas y
 * cual toca; abajo, lo que se hace en esta etapa, repartido en columnas para
 * usar el ancho sin estirar texto ni campos.
 */
$titulosEtapa = [
    1 => 'Etapa 1: Set de Prueba',
    2 => 'Etapa 2: Simulacion',
    3 => 'Etapa 3: Intercambio',
    4 => 'Etapa 4: Muestras Impresas',
    5 => 'Etapa 5: Declaracion Cumplimiento',
    6 => 'Etapa 6: Autorizacion',
];
$subtitulosEtapa = [
    1 => 'Set Basico, Libro de Ventas y Libro de Compras: los tres tienen que quedar aceptados por el SII.',
    2 => 'Un envio de 20 a 100 documentos con factura, nota de credito y nota de debito.',
    3 => 'Respuestas de intercambio de informacion. Se hace fuera del panel y aqui se confirma.',
    4 => 'PDF con muestras impresas para el SII. Se entrega fuera del panel y aqui se confirma.',
    5 => 'Declarar Avance y Cumplimiento en el portal del SII, con los datos de esta pagina.',
    6 => 'Cuando el SII autoriza a tu empresa como emisor electronico.',
];
$titulo = $titulosEtapa[$etapaActual];
require __DIR__ . '/partials/header.php';
?>

<div class="dash-header">
    <div>
        <h1><?= htmlspecialchars($titulosEtapa[$etapaActual]); ?></h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <a class="boton-secundario" href="/certificacion">Volver al resumen</a>
    </div>
</div>
<p class="dash-subtitulo"><?= htmlspecialchars($subtitulosEtapa[$etapaActual]); ?></p>

<?php
    $flashTrackDestino = 'aqui abajo';
    require __DIR__ . '/partials/certificacion/_flash.php';
?>

<?php require __DIR__ . '/partials/certificacion/_barra-etapas.php'; ?>

<?php switch ($etapaActual):
    case 1: ?>
        <?php require __DIR__ . '/partials/certificacion/_tarjeta-set-basico.php'; ?>
        <?php // Ventas y Compras lado a lado: mismo formato, se comparan de un vistazo. ?>
        <div class="config-columnas config-columnas--2">
            <?php require __DIR__ . '/partials/certificacion/_tarjeta-libro-ventas.php'; ?>
            <?php require __DIR__ . '/partials/certificacion/_tarjeta-libro-compras.php'; ?>
        </div>
        <?php require __DIR__ . '/partials/certificacion/_ayuda-libros.php'; ?>
        <?php break;
    case 2: ?>
        <?php require __DIR__ . '/partials/certificacion/_tarjeta-etapa-simulacion.php'; ?>
        <?php break;
    case 3: ?>
        <?php require __DIR__ . '/partials/certificacion/_tarjeta-etapa-intercambio.php'; ?>
        <?php break;
    case 4: ?>
        <?php require __DIR__ . '/partials/certificacion/_tarjeta-etapa-muestras.php'; ?>
        <?php break;
    case 5: ?>
        <?php require __DIR__ . '/partials/certificacion/_seccion-declaracion-cumplimiento.php'; ?>
        <?php break;
    case 6: ?>
        <?php require __DIR__ . '/partials/certificacion/_seccion-autorizacion.php'; ?>
        <?php break;
endswitch; ?>

<?php require __DIR__ . '/partials/certificacion/_ux-formularios.php'; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
