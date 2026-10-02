<?php
    $d = $etapasManuales['simulacion'];

    // El helper "Generar y enviar" NO depende de $d['habilitada'] (esa
    // condicion exige el Set Basico aprobado CON SOK, ver
    // calcularEtapasManuales()): la Simulacion es una etapa independiente
    // del Set Basico segun el manual del SII, solo exige que la etapa 1
    // este EPR+3-tipos ($setBasicoSinSok, calculado en
    // calcularDatosCertificacion()). Por eso se muestra tambien en el
    // caso EPR-sin-SOK, antes de que el checkbox de confirmacion manual
    // este habilitado.
    $puedeGenerarSimulacion = ! $d['confirmada'] && ($d['habilitada'] || $setBasicoSinSok['aprobado']);
?>
<div class="config-columnas config-columnas--2">
    <section class="tarjeta" aria-labelledby="titulo-simulacion">
        <h2 id="titulo-simulacion">Simulacion</h2>
        <?php if (! $d['confirmada'] && ! $d['habilitada'] && $puedeGenerarSimulacion): ?>
            <div class="alerta alerta--info">
        <?= iconoSvg('sin-cargar', 20, 'alerta__icono'); ?>
        <div class="alerta__cuerpo">
            <p class="alerta__titulo">Ya puedes generarla, aun no confirmarla</p>
                <p>La confirmacion se habilita cuando el Set Basico este aprobado con SOK. Mientras
                tanto puedes generar y enviar la Simulacion.</p>
        </div>
    </div>
        <?php else: ?>
            <?php
                $manualNombre    = 'Simulacion';
                $manualClave     = 'simulacion';
                $manualDatos     = $d;
                $manualRequisito = 'Primero tiene que estar aprobado el Set de Prueba (etapa 1).';
                $manualConTrack  = true;
                $manualFemenino  = true;
                require __DIR__ . '/_etapa-manual.php';
            ?>
        <?php endif; ?>
    </section>
    <div class="panel-info">
        <p class="panel-info__titulo"><?= iconoSvg('info', 20, 'panel-info__icono'); ?>Como se hace</p>
        <p>Simulacion se hace en el portal del SII o por correo: el panel no puede detectarla sola.
        Confirmala aqui solo despues de completarla de verdad. Es tu propia declaracion, igual que en
        "Certificacion aprobada".</p>
        <?php if ($puedeGenerarSimulacion): ?>
        <p>Tambien puedes dejar que el panel arme y envie el lote por ti. Eso no reemplaza la
        confirmacion: el panel nunca sabe solo si el SII aprobo el contenido.</p>
        <p><a class="boton-secundario" href="/certificacion/simulacion">Generar Set de Simulacion</a></p>
        <?php endif; ?>
    </div>
</div>
