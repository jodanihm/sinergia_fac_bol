<?php
/**
 * Stepper del flujo de facturacion masiva. SOLO PRESENTACION: no consulta nada,
 * no decide nada, no conoce rutas de negocio.
 *
 * Variables que espera:
 *   $pasoActual      int 1..3 (obligatorio en la practica; si falta, asume 1)
 *   $enlacesStepper  array<int,string> opcional, paso => href. Un paso con
 *                    enlace se pinta como <a>; sin enlace, como <span>. Sirve
 *                    para que los pasos ya recorridos puedan volver atras, sin
 *                    que este partial sepa a donde.
 *
 * ES EL COMPONENTE "POR PASOS" DE LA SECCION 14 DEL DESIGN SYSTEM (Efectos de
 * carga): dice cuantos pasos hay, en cual estas y cuantos faltan, que es
 * justamente lo que una barra continua no puede decir. Por eso:
 *   - una linea de resumen arriba ("Paso 2 de 3: Revisar carga. Queda 1.");
 *   - cada paso dice su estado con icono + texto VISIBLE + color ("Listo",
 *     "Estas aqui", "Pendiente"), no solo con el color ni con un texto oculto;
 *   - lista ordenada dentro de un <nav>, con aria-current en el paso actual.
 *
 * POR QUE NO REUSA .progreso-etapas: esa barra es de la certificacion (6 etapas
 * rectangulares, con estados propios como "no gestionada" y "rechazada") y su
 * anatomia es distinta a la de este stepper (circulo + conector + etiqueta al
 * lado). Comparten el criterio, no el dibujo.
 *
 * LO USAN SOLO carga-masiva-form.php, carga-masiva-detalle.php y
 * facturacion-masiva-form.php.
 */
$pasosStepper = [
    1 => 'Cargar archivo',
    2 => 'Revisar carga',
    3 => 'Facturar notas',
];

$pasoActualStepper = isset($pasoActual) ? (int) $pasoActual : 1;
$enlacesStepperMap = isset($enlacesStepper) && is_array($enlacesStepper) ? $enlacesStepper : [];
$totalPasos        = count($pasosStepper);
$quedanPasos       = $totalPasos - $pasoActualStepper;
?>
<nav class="stepper" aria-label="Pasos de la facturacion masiva">
    <p class="stepper__resumen">
        <strong>Paso <?= $pasoActualStepper; ?> de <?= $totalPasos; ?>: <?= htmlspecialchars($pasosStepper[$pasoActualStepper] ?? ''); ?>.</strong>
        <?= $quedanPasos === 0 ? 'Es el ultimo.' : ($quedanPasos === 1 ? 'Queda 1 paso.' : 'Quedan ' . $quedanPasos . ' pasos.'); ?>
    </p>
    <ol class="stepper__lista">
        <?php foreach ($pasosStepper as $numero => $etiqueta): ?>
            <?php
                if ($numero < $pasoActualStepper) {
                    $estado      = 'completado';
                    $textoEstado = 'Listo';
                } elseif ($numero === $pasoActualStepper) {
                    $estado      = 'activo';
                    $textoEstado = 'Estas aqui';
                } else {
                    $estado      = 'pendiente';
                    $textoEstado = 'Pendiente';
                }
                $href = $estado === 'completado' ? ($enlacesStepperMap[$numero] ?? null) : null;
            ?>
            <li class="stepper__paso stepper__paso--<?= $estado; ?>">
                <?php if ($href !== null): ?>
                    <a class="stepper__contenido" href="<?= htmlspecialchars($href); ?>">
                <?php else: ?>
                    <span class="stepper__contenido"<?= $estado === 'activo' ? ' aria-current="step"' : ''; ?>>
                <?php endif; ?>
                    <span class="stepper__marca" aria-hidden="true"><?= $estado === 'completado' ? iconoSvg('check', 16, '') : $numero; ?></span>
                    <span class="stepper__texto">
                        <span class="stepper__etiqueta"><?= $numero; ?>. <?= htmlspecialchars($etiqueta); ?></span>
                        <span class="stepper__estado"><?= $textoEstado; ?></span>
                    </span>
                <?php if ($href !== null): ?>
                    </a>
                <?php else: ?>
                    </span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
</nav>
