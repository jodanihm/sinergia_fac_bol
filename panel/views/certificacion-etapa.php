<?php
$titulosEtapa = [
    1 => 'Etapa 1: Set de Prueba',
    2 => 'Etapa 2: Simulacion',
    3 => 'Etapa 3: Intercambio',
    4 => 'Etapa 4: Muestras Impresas',
    5 => 'Etapa 5: Declaracion Cumplimiento',
    6 => 'Etapa 6: Autorizacion',
];
$titulo = $titulosEtapa[$etapaActual];
require __DIR__ . '/partials/header.php';
?>

<h1><?= htmlspecialchars($titulosEtapa[$etapaActual]); ?></h1>

<?php if ($flash !== null): ?>
    <?php if ($flash['tipo'] === 'error'): ?>
    <p class="errores"><?= htmlspecialchars($flash['mensaje']); ?></p>
    <?php else: ?>
    <p style="color:#2e7d32;font-weight:600;"><?= htmlspecialchars($flash['mensaje']); ?></p>
    <?php endif; ?>
    <?php if (isset($flash['simulacionTrackId'])): ?>
    <div style="border:2px solid #2e7d32;border-radius:6px;padding:0.75rem 1rem;margin:0.5rem 0 1rem;background:#f1f8f1;">
        <strong>Track ID del Set de Simulacion, listo para copiar:</strong>
        <p style="font-family:monospace;word-break:break-all;background:#fff;padding:0.5rem;border:1px solid #ccc;border-radius:4px;margin:0.35rem 0 0;user-select:all;"><?= htmlspecialchars((string) $flash['simulacionTrackId']); ?></p>
        <span style="color:#999;font-size:0.85em;">Pegalo en el campo "Track ID" de Simulacion aqui
        abajo, cuando confirmes que el SII aprobo el contenido.</span>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/partials/certificacion/_barra-etapas.php'; ?>

<?php switch ($etapaActual):
    case 1: ?>
        <?php require __DIR__ . '/partials/certificacion/_tarjeta-set-basico.php'; ?>
        <?php require __DIR__ . '/partials/certificacion/_tarjeta-libro-ventas.php'; ?>
        <?php require __DIR__ . '/partials/certificacion/_tarjeta-libro-compras.php'; ?>
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

<p><a href="/certificacion">&larr; Volver al resumen</a></p>
<p><a href="/panel">Volver al panel</a></p>

<?php require __DIR__ . '/partials/footer.php'; ?>
