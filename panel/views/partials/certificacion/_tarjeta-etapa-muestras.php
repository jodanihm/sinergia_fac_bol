<div class="config-columnas config-columnas--2">
    <section class="tarjeta" aria-labelledby="titulo-muestras">
        <h2 id="titulo-muestras">Muestras Impresas</h2>
        <?php
            $manualNombre    = 'Muestras Impresas';
            $manualClave     = 'muestras-impresas';
            $manualDatos     = $etapasManuales['muestrasImpresas'];
            $manualRequisito = 'Primero tiene que estar confirmado Intercambio (etapa 3).';
            $manualConTrack  = false;
            $manualFemenino  = true;
            require __DIR__ . '/_etapa-manual.php';
        ?>
    </section>
    <div class="panel-info">
        <p class="panel-info__titulo"><?= iconoSvg('info', 20, 'panel-info__icono'); ?>Como se hace</p>
        <p>Las Muestras Impresas se entregan en el portal del SII o por correo: el panel no puede
        detectarlas solo. Confirmalas aqui solo despues de completarlas de verdad. Es tu propia
        declaracion, igual que en "Certificacion aprobada".</p>
        <p><a href="/certificacion/muestras-impresas">Generar el PDF de Muestras Impresas</a></p>
    </div>
</div>
