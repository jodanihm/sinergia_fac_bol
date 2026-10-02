<div class="config-columnas config-columnas--2">
    <section class="tarjeta" aria-labelledby="titulo-intercambio">
        <h2 id="titulo-intercambio">Intercambio</h2>
        <?php
            $manualNombre    = 'Intercambio';
            $manualClave     = 'intercambio';
            $manualDatos     = $etapasManuales['intercambio'];
            $manualRequisito = 'Primero tiene que estar confirmada la Simulacion (etapa 2).';
            $manualConTrack  = false;
            $manualFemenino  = false;
            require __DIR__ . '/_etapa-manual.php';
        ?>
    </section>
    <div class="panel-info">
        <p class="panel-info__titulo"><?= iconoSvg('info', 20, 'panel-info__icono'); ?>Como se hace</p>
        <p>Intercambio se hace en el portal del SII o por correo: el panel no puede detectarlo solo.
        Confirmalo aqui solo despues de completarlo de verdad. Es tu propia declaracion, igual que en
        "Certificacion aprobada".</p>
        <p><a href="/certificacion/intercambio">Generar las respuestas de Intercambio</a></p>
    </div>
</div>
