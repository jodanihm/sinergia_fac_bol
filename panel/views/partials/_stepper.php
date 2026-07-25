<?php
/**
 * Stepper del flujo de facturacion masiva. SOLO PRESENTACION: no consulta nada,
 * no decide nada, no conoce rutas de negocio.
 *
 * Variables que espera:
 *   $pasoActual      int 1..3 (obligatorio en la practica; si falta, asume 1)
 *   $enlacesStepper  array<int,string> opcional, paso => href. Un paso con
 *                    enlace se pinta como <a>; sin enlace, como <span>. Sirve
 *                    para que mas adelante los pasos ya recorridos puedan
 *                    volver atras, sin que este partial sepa a donde.
 *
 * POR QUE NO REUSA .progreso-etapas: esa barra es de la certificacion (6 etapas
 * rectangulares, con estados propios como "no gestionada" y "rechazada") y hoy
 * la consumen admin-tenants.php, boleta.php, certificacion-elegir.php y
 * partials/certificacion/_barra-etapas.php. Su anatomia es distinta a la de
 * este stepper (circulo numerado + conector + etiqueta al lado) y evolucionarla
 * pondria en riesgo esas cuatro vistas. Por eso clases propias.
 *
 * ACCESIBILIDAD: va como lista ordenada dentro de un <nav> etiquetado; el paso
 * en curso lleva aria-current="step" y cada paso incluye su estado en texto
 * (visualmente oculto) para no depender solo del color.
 */
$pasosStepper = [
    1 => 'Cargar archivo',
    2 => 'Revisar carga',
    3 => 'Facturar lote',
];

$pasoActualStepper = isset($pasoActual) ? (int) $pasoActual : 1;
$enlacesStepperMap = isset($enlacesStepper) && is_array($enlacesStepper) ? $enlacesStepper : [];
?>
<nav class="stepper" aria-label="Etapas del proceso">
    <ol class="stepper__lista">
        <?php foreach ($pasosStepper as $numero => $etiqueta): ?>
            <?php
                if ($numero < $pasoActualStepper) {
                    $estado      = 'completado';
                    $textoEstado = 'Completado';
                } elseif ($numero === $pasoActualStepper) {
                    $estado      = 'activo';
                    $textoEstado = 'Etapa actual';
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
                    <span class="stepper__marca" aria-hidden="true"><?= $estado === 'completado' ? '&#10003;' : $numero; ?></span>
                    <span class="stepper__etiqueta"><?= $numero; ?>. <?= htmlspecialchars($etiqueta); ?></span>
                    <span class="visualmente-oculto"><?= $textoEstado; ?></span>
                <?php if ($href !== null): ?>
                    </a>
                <?php else: ?>
                    </span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
</nav>
