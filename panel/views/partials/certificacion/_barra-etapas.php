<?php
    // Barra de progreso de 6 etapas de la certificacion de FACTURA. Cada
    // etapa se pinta VERDE si ya esta completada, AZUL si es la PRIMERA no
    // completada (la "activa": lo proximo que corresponde hacer), o GRIS si
    // todavia no le toca (falta completar alguna etapa anterior). Las etapas
    // 2-4 (Simulacion/Intercambio/Muestras Impresas) usan $etapasManuales
    // (confirmacion manual del tenant, ver migracion 010); las etapas 5 y 6
    // comparten el mismo criterio ya usado en /certificacion-aprobada:
    // $certificacionConfirmadaAt (una sola confirmacion cubre "Declaracion
    // Cumplimiento" + "Autorizacion", no hay 2 campos separados para eso).
    //
    // Compartido entre certificacion.php (resumen) y certificacion-etapa.php
    // (vista por etapa) para no duplicar este calculo -- $etapaActual (int|null,
    // ver handleCertificacionGet()/handleCertificacionEtapaGet()) marca ademas
    // cual segmento corresponde a la pagina que se esta viendo AHORA, que es
    // independiente del color de progreso: podrias estar viendo la etapa 4
    // aunque lo que toca hacer todavia sea la etapa 2.
    $etapasEstado = [
        ['nombre' => 'Set de Prueba',             'completada' => $todosAprobados],
        ['nombre' => 'Simulacion',                'completada' => $etapasManuales['simulacion']['confirmada']],
        ['nombre' => 'Intercambio',               'completada' => $etapasManuales['intercambio']['confirmada']],
        ['nombre' => 'Muestras Impresas',         'completada' => $etapasManuales['muestrasImpresas']['confirmada']],
        ['nombre' => 'Declaracion Cumplimiento',  'completada' => $certificacionConfirmadaAt !== null],
        ['nombre' => 'Autorizacion',              'completada' => $certificacionConfirmadaAt !== null],
    ];
    $todasAnterioresCompletas = true;
    foreach ($etapasEstado as &$e) {
        if ($e['completada']) {
            $e['clase'] = 'etapa--completada';
        } elseif ($todasAnterioresCompletas) {
            $e['clase'] = 'etapa--activa';
        } else {
            $e['clase'] = 'etapa--no-gestionada';
        }
        if (! $e['completada']) {
            $todasAnterioresCompletas = false;
        }
    }
    unset($e);
?>
<div class="progreso-etapas">
    <?php foreach ($etapasEstado as $i => $e): ?>
        <a href="/certificacion/etapa/<?= $i + 1; ?>"
           class="etapa <?= $e['clase']; ?><?= ($etapaActual === $i + 1) ? ' etapa--actual' : ''; ?>"
           <?php if ($e['clase'] === 'etapa--no-gestionada'): ?>title="Aun no corresponde: falta completar una etapa anterior."<?php endif; ?>>
            <span class="etapa__numero"><?= $i + 1; ?></span>
            <?= htmlspecialchars($e['nombre']); ?>
        </a>
    <?php endforeach; ?>
</div>
