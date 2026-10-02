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
    //
    // ES EL COMPONENTE "POR PASOS" DE LA SECCION 14 DEL DESIGN SYSTEM (Efectos
    // de carga): dice cuantas etapas faltan y cual es la actual, que es
    // justamente lo que una barra continua no puede decir. Por eso:
    //   - una linea de resumen arriba ("2 de 6 listas. Te toca: Simulacion");
    //   - cada etapa dice su estado con icono + texto visible + color, y no solo
    //     con el color (antes "aun no corresponde" vivia en un title, que en
    //     tactil no existe);
    //   - lista ordenada dentro de un <nav>, con aria-current en la pagina que
    //     se esta viendo.
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

    $barraListas = count(array_filter($etapasEstado, static fn (array $e): bool => $e['completada']));
    $barraToca   = null;
    foreach ($etapasEstado as $e) {
        if ($e['clase'] === 'etapa--activa') {
            $barraToca = $e['nombre'];
            break;
        }
    }
    $barraSegmentos = $etapasEstado;
    $barraEnlazada  = true;
    require __DIR__ . '/_lista-etapas.php';
