<?php
/**
 * Paso 2 del flujo de facturacion masiva: revisar la carga.
 *
 * TAREA DE LA PANTALLA, EN UNA FRASE: ver que filas del archivo quedaron listas
 * para facturar y cuales hay que corregir, y seguir a la facturacion.
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
 *
 * ANCHO: cifras arriba, despues tres tarjetas lado a lado (lote, validacion y
 * que sigue) y el detalle a ancho completo. Bajo 720px la tabla pasa a
 * tarjetas, una por nota, con la etiqueta de cada dato.
 *
 * UNA SOLA ACCION PRIMARIA: "Ir a facturacion masiva". Volver es secundario,
 * tambien en el empty state.
 */
$titulo         = 'Resumen de carga';
$pasoActual     = 2;
$enlacesStepper = [1 => '/ventas/carga-masiva'];
require __DIR__ . '/partials/header.php';

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

// Cantidades con punto de miles; el monto ademas con "$" (escritura.html).
$fmtNum = static function ($v): string {
    return $v === null ? '-' : number_format((float) $v, 0, ',', '.');
};
$fmtMonto = static function ($v) use ($fmtNum): string {
    return $v === null ? '-' : '$' . $fmtNum($v);
};

// Fechas en formato chileno: dd/mm/aaaa (y hh:mm cuando trae hora).
$fmtFechaHora = static function ($v): string {
    $t = strtotime((string) $v);
    return $t === false ? (string) $v : date('d/m/Y H:i', $t);
};
$fmtFecha = static function ($v): string {
    $s = trim((string) $v);
    if ($s === '') {
        return '';
    }
    $t = strtotime($s);
    return $t === false ? $s : date('d/m/Y', $t);
};

/**
 * Estados reales de nota_venta (ENUM de la migracion 020). Solo se traduce la
 * etiqueta visible; el valor almacenado no se toca. Cada estado lleva icono +
 * palabra + color (WCAG 1.4.1).
 */
$estados = [
    'pendiente'  => ['badge--neutro',  'Pendiente',  'sin-cargar'],
    'en_proceso' => ['badge--proceso', 'En proceso', 'reloj'],
    'facturada'  => ['badge--ok',      'Facturada',  'check'],
    'error'      => ['badge--error',   'Con error',  'alerta'],
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
    Revisa que filas quedaron listas para facturar y cuales hay que corregir en el Excel.
</p>

<?php require __DIR__ . '/partials/_stepper.php'; ?>

<div class="config-ancho">

<section class="dash-grid" aria-label="Resultado de la carga">
    <article class="kpi">
        <h2 class="kpi__etiqueta">Filas cargadas</h2>
        <p class="kpi__valor"><?= $fmtNum($total); ?></p>
        <p class="kpi__formula">Filas con datos en el archivo</p>
    </article>
    <article class="kpi">
        <h2 class="kpi__etiqueta">Validas</h2>
        <p class="kpi__valor"><?= $fmtNum($validas); ?></p>
        <p class="kpi__formula"><?= $pct($validas, $total); ?> del total</p>
    </article>
    <article class="kpi<?= $conError > 0 ? ' kpi--rojo' : ''; ?>">
        <h2 class="kpi__etiqueta">Con error</h2>
        <p class="kpi__valor"><?= $fmtNum($conError); ?></p>
        <p class="kpi__formula"><?= $pct($conError, $total); ?> del total</p>
    </article>
    <article class="kpi">
        <h2 class="kpi__etiqueta">Monto estimado</h2>
        <p class="kpi__valor"><?= $fmtMonto($montoEstimado); ?></p>
        <p class="kpi__formula">IVA incluido, calculado al cargar. No es el monto emitido.</p>
    </article>
</section>

<div class="config-columnas config-columnas--3">
    <section class="tarjeta" aria-labelledby="titulo-lote">
        <h2 id="titulo-lote">Lote</h2>
        <dl class="ficha">
            <dt>Archivo</dt>
            <dd><?= $e((string) $lote['nombre_archivo']); ?></dd>
            <dt>Fecha de carga</dt>
            <dd><?= $e($fmtFechaHora($lote['created_at'])); ?></dd>
            <dt>Numero</dt>
            <dd>#<?= (int) $lote['id']; ?></dd>
        </dl>
    </section>

    <section class="tarjeta" aria-labelledby="titulo-validacion">
        <h2 id="titulo-validacion">Validacion</h2>
        <ul class="validacion">
            <li class="validacion__item">
                <span class="badge badge--ok"><?= iconoSvg('check', 16, 'badge__icono'); ?>Validas</span>
                <span class="validacion__dato"><?= $fmtNum($validas); ?> <span class="validacion__pct">(<?= $pct($validas, $total); ?>)</span></span>
            </li>
            <li class="validacion__item">
                <span class="badge badge--error"><?= iconoSvg('alerta', 16, 'badge__icono'); ?>Con error</span>
                <span class="validacion__dato"><?= $fmtNum($conError); ?> <span class="validacion__pct">(<?= $pct($conError, $total); ?>)</span></span>
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
    </section>

    <section class="tarjeta" aria-labelledby="titulo-sigue">
        <h2 id="titulo-sigue">Que sigue</h2>
        <p class="tarjeta__intro">
            Las notas validas quedan pendientes de facturar, junto con las de otros archivos.
            Las eliges y las emites en el paso 3.
        </p>
        <div class="acciones-formulario">
            <a class="boton-secundario" href="/ventas/carga-masiva">Cargar otro archivo</a>
            <a class="boton-principal" href="/ventas/facturacion-masiva">Ir a facturacion masiva</a>
        </div>
    </section>
</div>

<section class="tarjeta" aria-labelledby="titulo-notas">
    <h2 id="titulo-notas">Notas cargadas</h2>

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
        <div class="alerta alerta--info">
            <?= iconoSvg('info', 20, 'alerta__icono'); ?>
            <div class="alerta__cuerpo">
                <p class="alerta__titulo"><?= $fmtNum($validas); ?> filas validas produjeron <?= $fmtNum($documentos); ?> facturas</p>
                <p>
                    Las filas de un mismo cliente se juntaron en un solo documento con varias lineas.
                    Quedan separadas cuando difieren en fecha, forma de pago, vencimiento o boleta a
                    anular: esas cuatro son condiciones del documento y no se pueden mezclar.
                </p>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($notas === []): ?>
        <div class="estado-vacio estado-vacio--centrado">
            <?= iconoSvg('carga-masiva', 32, 'estado-vacio__icono'); ?>
            <h3>Este lote no tiene filas</h3>
            <p>El archivo se registro pero no quedo ninguna nota asociada. Revisa el archivo
            original y vuelve a cargarlo.</p>
            <p class="estado-vacio__acciones">
                <a class="boton-secundario" href="/ventas/carga-masiva">Volver a carga masiva</a>
            </p>
        </div>
    <?php else: ?>
        <div class="tabla-scroll">
            <table class="tabla-datos tabla-datos--cards">
                <caption>
                    <?= $fmtNum(count($notas)); ?> nota<?= count($notas) === 1 ? '' : 's'; ?>. El N&deg; es el orden
                    dentro de la carga, no la fila del Excel: las filas vacias se omiten.
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
                                ?? ['badge--neutro', $estado, 'sin-cargar'];
                            $esError = $estado === 'error';
                        ?>
                        <tr<?= $esError ? ' class="tabla-datos__fila--inactiva"' : ''; ?>>
                            <td data-label="N&deg;" class="tabla-datos__num"><?= $i + 1; ?></td>
                            <td data-label="Identificador">
                                <span>
                                <?php
                                /* QUE FILAS DEL EXCEL FORMARON ESTA FACTURA.
                                   Desde el agrupamiento por cliente, una nota puede
                                   venir de VARIAS filas, y sin decirlo el usuario no
                                   tendria como cuadrar su archivo con lo que ve. Los
                                   origenes salen de nota_venta_origen; en los lotes
                                   anteriores hay uno solo y se ve igual que antes. */
                                $origenes = is_array($n['origenes'] ?? null) ? $n['origenes'] : [];
                                ?>
                                <?= $e((string) ($origenes[0] ?? $n['identificador_externo'] ?? '')) ?: '&mdash;'; ?>
                                <?php if (count($origenes) > 1): ?>
                                    <span class="tabla-datos__secundario">
                                        + <?= count($origenes) - 1; ?>
                                        fila<?= count($origenes) === 2 ? '' : 's'; ?> mas del mismo cliente:
                                        <?= $e(implode(', ', array_slice($origenes, 1, 5))); ?><?= count($origenes) > 6 ? ', ...' : ''; ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (! empty($n['boleta_ref_folio'])): ?>
                                    <span class="tabla-datos__secundario">Anula boleta <?= (int) $n['boleta_ref_folio']; ?></span>
                                <?php endif; ?>
                                </span>
                            </td>
                            <td data-label="Receptor">
                                <span>
                                    <?= $e((string) ($n['receptor_rut'] ?? '')) ?: '&mdash;'; ?>
                                    <?php if (! empty($n['receptor_razon_social'])): ?>
                                        <span class="tabla-datos__secundario"><?= $e((string) $n['receptor_razon_social']); ?></span>
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td data-label="Fecha nota"><?= $e($fmtFecha($n['fecha_nota'] ?? '')) ?: '&mdash;'; ?></td>
                            <td data-label="Monto estimado" class="tabla-datos__num"><?= $esError ? '&mdash;' : $fmtMonto($n['monto_estimado']); ?></td>
                            <td data-label="Estado" class="tabla-datos__estado">
                                <span class="badge <?= $claseBadge; ?>"><?= iconoSvg($icono, 16, 'badge__icono'); ?><?= $e($etiqueta); ?></span>
                            </td>
                            <td data-label="Observacion" class="celda-observacion">
                                <?php if ($esError && ! empty($n['error_mensaje'])): ?>
                                    <div>
                                    <?php
                                        // error_mensaje es la union de los motivos con '; '
                                        // (ver crearNotaVentaError). Se separa solo para
                                        // leerlos como lista; el texto no se altera.
                                        $motivos = array_filter(array_map('trim', explode(';', (string) $n['error_mensaje'])));
                                    ?>
                                    <ul class="motivos">
                                        <?php foreach ($motivos as $motivo): ?>
                                            <li><?= $e($motivo); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <?php
                                        // fila_original es el JSON de los valores crudos del
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
                                                    <dt><?= $e($columna); ?></dt>
                                                    <dd><?= $e($valor); ?></dd>
                                                <?php endforeach; ?>
                                            </dl>
                                        </details>
                                    <?php endif; ?>
                                    </div>
                                <?php elseif ($estado === 'facturada' && ! empty($n['resultado_documentos'])): ?>
                                    <span>
                                    <?php foreach ((json_decode((string) $n['resultado_documentos'], true) ?: []) as $doc): ?>
                                        <span class="tabla-datos__secundario">
                                            <?= $e(nombreTipoDte((int) ($doc['tipoDte'] ?? 0))); ?>
                                            folio <?= $e((string) ($doc['folio'] ?? '-')); ?>
                                        </span>
                                    <?php endforeach; ?>
                                    </span>
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

</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
