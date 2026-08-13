<?php
/**
 * Paso 2 del flujo de facturacion masiva: revisar la carga.
 *
 * Recibe (sin cambios): $lote, $notas, $navActivo.
 *
 *   $lote:  id, nombre_archivo, total_filas, filas_validas, filas_error,
 *           total_documentos, created_at
 *   $notas: id, identificador_externo, receptor_rut, receptor_razon_social,
 *           fecha_nota, monto_estimado, boleta_ref_folio, forma_pago,
 *           fecha_vencimiento, detalle, estado, error_mensaje, fila_original,
 *           resultado_documentos, origenes (list<string>)
 *
 * No hay usuario en $lote (la consulta no lo trae), asi que no se muestra.
 *
 * Todo lo que se calcula aqui es PRESENTACION sobre datos ya entregados: no se
 * consulta la BD de nuevo, no se persiste nada y no se altera ninguna regla.
 */
$titulo         = 'Resumen de carga';
$pasoActual     = 2;
$enlacesStepper = [1 => '/ventas/carga-masiva'];
require __DIR__ . '/partials/header.php';

$fmtMonto = static function ($v): string {
    return $v === null ? '-' : number_format((float) $v, 0, ',', '.');
};

$fmtFechaHora = static function ($v): string {
    $t = strtotime((string) $v);
    return $t === false ? (string) $v : date('Y-m-d H:i', $t);
};

/**
 * Estados reales de nota_venta (ENUM de la migracion 020). Solo se traduce la
 * etiqueta visible; el valor almacenado no se toca.
 */
$estados = [
    'pendiente'  => ['badge--neutro',  'Pendiente',  '&#9675;'],
    'en_proceso' => ['badge--proceso', 'En proceso', '&#9679;'],
    'facturada'  => ['badge--ok',      'Facturada',  '&#10003;'],
    'error'      => ['badge--error',   'Error',      '&#10007;'],
];

$total    = (int) $lote['total_filas'];
$validas  = (int) $lote['filas_validas'];
$conError = (int) $lote['filas_error'];

// Porcentajes: division directa sobre los conteos que ya trae $lote.
$pct = static function (int $parte, int $total): string {
    return $total > 0 ? (string) (int) round($parte * 100 / $total) . '%' : '-';
};

// Monto estimado del lote: suma de monto_estimado de las notas que NO estan en
// error. Las filas con error se guardan con monto 0 (ver crearNotaVentaError),
// asi que excluirlas es explicito mas que necesario. Es una suma de
// PRESENTACION sobre $notas, ya en memoria: ni consulta nueva ni dato
// persistido.
$montoEstimado = 0;
foreach ($notas as $n) {
    if ($n['estado'] !== 'error') {
        $montoEstimado += (int) $n['monto_estimado'];
    }
}
?>

<div class="dash-header">
    <div>
        <h1>Resumen de carga</h1>
    </div>
</div>
<p class="dash-subtitulo">
    Revisa el resultado de la carga antes de continuar con la facturacion.
</p>

<?php require __DIR__ . '/partials/_stepper.php'; ?>

<section class="dash-grid" aria-label="Resultado de la carga">
    <article class="kpi">
        <h2 class="kpi__etiqueta">Filas cargadas</h2>
        <p class="kpi__valor"><?= $fmtMonto($total); ?></p>
        <p class="kpi__formula">Filas con datos en el archivo</p>
    </article>
    <article class="kpi">
        <h2 class="kpi__etiqueta">Validas</h2>
        <p class="kpi__valor"><?= $fmtMonto($validas); ?></p>
        <p class="kpi__formula"><?= $pct($validas, $total); ?> del total</p>
    </article>
    <article class="kpi<?= $conError > 0 ? ' kpi--rojo' : ''; ?>">
        <h2 class="kpi__etiqueta">Con error</h2>
        <p class="kpi__valor"><?= $fmtMonto($conError); ?></p>
        <p class="kpi__formula"><?= $pct($conError, $total); ?> del total</p>
    </article>
    <article class="kpi">
        <h2 class="kpi__etiqueta">Monto estimado</h2>
        <p class="kpi__valor">$<?= $fmtMonto($montoEstimado); ?></p>
        <p class="kpi__formula">IVA incluido, calculado al cargar. No es el monto emitido.</p>
    </article>
</section>

<div class="dash-grid dash-grid--3">
    <article class="tarjeta">
        <h2>Resumen del lote</h2>
        <dl class="ficha">
            <dt>Archivo</dt>
            <dd><?= htmlspecialchars((string) $lote['nombre_archivo']); ?></dd>
            <dt>Fecha de carga</dt>
            <dd><?= htmlspecialchars($fmtFechaHora($lote['created_at'])); ?></dd>
            <dt>Lote</dt>
            <dd>#<?= (int) $lote['id']; ?></dd>
        </dl>
    </article>

    <article class="tarjeta">
        <h2>Validacion de la carga</h2>
        <ul class="validacion">
            <li class="validacion__item">
                <span class="badge badge--ok">
                    <span class="badge__icono" aria-hidden="true">&#10003;</span>Validas
                </span>
                <span class="validacion__dato"><?= $fmtMonto($validas); ?> <span class="validacion__pct">(<?= $pct($validas, $total); ?>)</span></span>
            </li>
            <li class="validacion__item">
                <span class="badge badge--error">
                    <span class="badge__icono" aria-hidden="true">&#10007;</span>Con error
                </span>
                <span class="validacion__dato"><?= $fmtMonto($conError); ?> <span class="validacion__pct">(<?= $pct($conError, $total); ?>)</span></span>
            </li>
        </ul>
        <p class="nota">
            <?php if ($conError > 0): ?>
                Las filas con error no pasan a facturacion. Corrigelas en el Excel y vuelve a cargar
                solo esas filas.
            <?php else: ?>
                Todas las filas del archivo quedaron validas.
            <?php endif; ?>
        </p>
    </article>

    <article class="tarjeta">
        <h2>Acciones</h2>
        <p class="nota">
            Las notas validas quedan pendientes de facturar junto con las de otros lotes.
        </p>
        <div class="acciones-grupo">
            <a class="boton-principal" href="/ventas/facturacion-masiva">Ir a facturacion masiva</a>
            <a class="boton-secundario" href="/ventas/carga-masiva">Volver a carga masiva</a>
        </div>
    </article>
</div>

<section aria-labelledby="titulo-notas">
    <h2 class="titulo-seccion" id="titulo-notas">Detalle de notas cargadas</h2>

    <?php
    /* POR QUE HAY MENOS FACTURAS QUE FILAS.
       Sin esta nota, un archivo de 180 filas que produce 40 facturas se lee como
       un error de carga. Se explica UNA sola vez y solo cuando de verdad hubo
       agrupamiento -- si cada fila fue su propia factura, no hay nada que
       aclarar.
       total_documentos viene en 0 para los lotes anteriores a la migracion 041:
       para ellos esta nota no aparece, que es lo correcto porque no se agrupo
       nada. */
    $documentos = (int) ($lote['total_documentos'] ?? 0);
    ?>
    <?php if ($documentos > 0 && $documentos < $validas): ?>
        <p class="nota">
            Las <strong><?= $validas; ?> filas validas</strong> del archivo produjeron
            <strong><?= $documentos; ?> facturas</strong>: las filas de un mismo cliente se
            juntaron en un solo documento con varias lineas. Dos filas del mismo cliente
            quedan <em>separadas</em> cuando difieren en fecha, forma de pago, vencimiento o
            boleta a anular &mdash; esas cuatro son condiciones del documento y no se pueden
            mezclar en uno solo.
        </p>
    <?php endif; ?>

    <?php if ($notas === []): ?>
        <div class="estado-vacio">
            <h2>Este lote no tiene filas</h2>
            <p>El archivo se registro pero no quedo ninguna nota asociada. Revisa el archivo
            original y vuelve a cargarlo.</p>
            <p class="estado-vacio__acciones">
                <a class="boton-principal" href="/ventas/carga-masiva">Volver a carga masiva</a>
            </p>
        </div>
    <?php else: ?>
        <div class="tabla-scroll">
            <table class="tabla-datos">
                <caption>
                    El numero de la primera columna es el orden dentro de la carga, no la fila
                    del Excel: las filas completamente vacias del archivo se omiten.
                </caption>
                <thead>
                    <tr>
                        <th scope="col" class="tabla-datos__num">N&deg;</th>
                        <th scope="col">Identificador</th>
                        <th scope="col">Receptor</th>
                        <th scope="col">Fecha nota</th>
                        <th scope="col" class="tabla-datos__num">Monto estimado</th>
                        <th scope="col" class="tabla-datos__estado">Estado</th>
                        <th scope="col">Observacion</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($notas as $i => $n): ?>
                        <?php
                            $estado = (string) $n['estado'];
                            [$claseBadge, $etiqueta, $icono] = $estados[$estado]
                                // Un estado no mapeado se muestra crudo antes que ocultarse.
                                ?? ['badge--neutro', $estado, '&#9675;'];
                            $esError = $estado === 'error';
                        ?>
                        <tr<?= $esError ? ' class="tabla-datos__fila--inactiva"' : ''; ?>>
                            <td class="tabla-datos__num"><?= $i + 1; ?></td>
                            <td>
                                <?php
                                /* QUE FILAS DEL EXCEL FORMARON ESTA FACTURA.
                                   Desde el agrupamiento por cliente, una nota puede
                                   venir de VARIAS filas, y sin decirlo el usuario no
                                   tendria como cuadrar su archivo con lo que ve. Los
                                   origenes salen de nota_venta_origen; en los lotes
                                   anteriores hay uno solo y se ve igual que antes. */
                                $origenes = is_array($n['origenes'] ?? null) ? $n['origenes'] : [];
                                ?>
                                <?= htmlspecialchars((string) ($origenes[0] ?? $n['identificador_externo'] ?? '')) ?: '&mdash;'; ?>
                                <?php if (count($origenes) > 1): ?>
                                    <span class="tabla-datos__secundario">
                                        + <?= count($origenes) - 1; ?>
                                        fila<?= count($origenes) === 2 ? '' : 's'; ?> mas del mismo cliente:
                                        <?= htmlspecialchars(implode(', ', array_slice($origenes, 1, 5))); ?><?= count($origenes) > 6 ? ', ...' : ''; ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (! empty($n['boleta_ref_folio'])): ?>
                                    <span class="tabla-datos__secundario">Anula boleta <?= (int) $n['boleta_ref_folio']; ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= htmlspecialchars((string) ($n['receptor_rut'] ?? '')) ?: '&mdash;'; ?>
                                <?php if (! empty($n['receptor_razon_social'])): ?>
                                    <span class="tabla-datos__secundario"><?= htmlspecialchars((string) $n['receptor_razon_social']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars((string) ($n['fecha_nota'] ?? '')) ?: '&mdash;'; ?></td>
                            <td class="tabla-datos__num"><?= $esError ? '&mdash;' : $fmtMonto($n['monto_estimado']); ?></td>
                            <td class="tabla-datos__estado">
                                <span class="badge <?= $claseBadge; ?>">
                                    <span class="badge__icono" aria-hidden="true"><?= $icono; ?></span><?= htmlspecialchars($etiqueta); ?>
                                </span>
                            </td>
                            <td class="celda-observacion">
                                <?php if ($esError && ! empty($n['error_mensaje'])): ?>
                                    <?php
                                        // error_mensaje es la union de los motivos con '; '
                                        // (ver crearNotaVentaError). Se separa solo para
                                        // leerlos como lista; el texto no se altera.
                                        $motivos = array_filter(array_map('trim', explode(';', (string) $n['error_mensaje'])));
                                    ?>
                                    <ul class="motivos">
                                        <?php foreach ($motivos as $motivo): ?>
                                            <li><?= htmlspecialchars($motivo); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <?php
                                        // fila_original es el JSON de los 14 valores crudos del
                                        // Excel, no un numero de fila. Se decodifica y se muestra
                                        // como pares columna/valor: el dato sirve para encontrar
                                        // la fila en el archivo, pero volcar el JSON crudo no.
                                        $original = json_decode((string) ($n['fila_original'] ?? ''), true);
                                    ?>
                                    <?php if (is_array($original) && $original !== []): ?>
                                        <details class="fila-original">
                                            <summary>Ver los datos de esta fila</summary>
                                            <dl class="ficha ficha--compacta">
                                                <?php
                                                    // Se recorre NOTA_VENTA_ENCABEZADOS y no las claves
                                                    // del JSON: fila_original vive en una columna JSON
                                                    // de MySQL, que reordena las claves del objeto (por
                                                    // longitud), asi que iterarlas directo mostraria las
                                                    // columnas en un orden que no es el del archivo.
                                                ?>
                                                <?php foreach (NOTA_VENTA_ENCABEZADOS as $columna): ?>
                                                    <?php $valor = (string) ($original[$columna] ?? ''); ?>
                                                    <?php if (trim($valor) === '') { continue; } ?>
                                                    <dt><?= htmlspecialchars($columna); ?></dt>
                                                    <dd><?= htmlspecialchars($valor); ?></dd>
                                                <?php endforeach; ?>
                                            </dl>
                                        </details>
                                    <?php endif; ?>
                                <?php elseif ($estado === 'facturada' && ! empty($n['resultado_documentos'])): ?>
                                    <?php foreach ((json_decode((string) $n['resultado_documentos'], true) ?: []) as $doc): ?>
                                        <span class="tabla-datos__secundario">
                                            <?= htmlspecialchars(nombreTipoDte((int) ($doc['tipoDte'] ?? 0))); ?>
                                            folio <?= htmlspecialchars((string) ($doc['folio'] ?? '-')); ?>
                                        </span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
