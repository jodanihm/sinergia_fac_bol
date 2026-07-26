<?php
/**
 * Vista GENERICA de los seis informes.
 *
 * Recibe: $clave, $definicion (la entrada de INFORMES), $columnas, $filas,
 * $totales, $desde, $hasta, $razonSocial, $rutEmisor, $navActivo.
 *
 * UNA SOLA VISTA PARA LOS SEIS, mismo criterio que emision-form.php con
 * factura/NC/ND: la unica bifurcacion es $definicion['periodo'], que decide si
 * se pintan los filtros de fecha. Seis archivos casi identicos serian seis
 * sitios donde arreglar el mismo detalle.
 *
 * $columnas, $filas y $totales vienen YA FORMATEADOS de
 * informeColumnasYFilas(), la misma funcion que alimenta el PDF y el Excel.
 * Esta vista no calcula ni formatea nada: si lo hiciera, la pantalla podria
 * mostrar algo distinto de lo que se descarga.
 *
 * Los anchos en $columnas estan en milimetros y son solo para el PDF; aqui se
 * ignoran y manda el layout de .tabla-datos.
 */
$titulo = $definicion['label'];
require __DIR__ . '/partials/header.php';

// El rango viaja a las dos descargas para que bajen exactamente lo que se ve.
$qs = ($definicion['periodo'] && $desde !== '' && $hasta !== '')
    ? '?desde=' . urlencode($desde) . '&hasta=' . urlencode($hasta)
    : '';
?>

<div class="dash-header">
    <div>
        <h1><?= htmlspecialchars((string) $definicion['label']); ?></h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <a class="boton-secundario" href="/informes">Todos los informes</a>
    </div>
</div>
<p class="dash-subtitulo">
    <?= htmlspecialchars((string) $definicion['descripcion']); ?>
</p>

<?php if ($definicion['periodo']): ?>
    <form method="get" action="/informes/<?= htmlspecialchars((string) $clave); ?>" class="filtros">
        <label class="filtros__campo">Desde
            <input type="date" name="desde" class="filtros__input filtros__input--medio" value="<?= htmlspecialchars((string) $desde); ?>">
        </label>
        <label class="filtros__campo">Hasta
            <input type="date" name="hasta" class="filtros__input filtros__input--medio" value="<?= htmlspecialchars((string) $hasta); ?>">
        </label>
        <button type="submit" class="boton-secundario">Aplicar</button>
    </form>
<?php endif; ?>

<section class="tarjeta" aria-labelledby="titulo-informe">
    <div class="dash-header">
        <div>
            <h2 id="titulo-informe">
                <?php if ($definicion['periodo']): ?>
                    Periodo <?= htmlspecialchars((string) $desde); ?> a <?= htmlspecialchars((string) $hasta); ?>
                <?php else: ?>
                    Estado actual
                <?php endif; ?>
            </h2>
        </div>
        <div class="acciones-grupo acciones-grupo--header">
            <a class="boton-secundario" href="/informes/<?= htmlspecialchars((string) $clave); ?>/pdf<?= $qs; ?>">Descargar PDF</a>
            <a class="boton-secundario" href="/informes/<?= htmlspecialchars((string) $clave); ?>/excel<?= $qs; ?>">Descargar Excel</a>
        </div>
    </div>

    <?php if ($filas === []): ?>
        <div class="estado-vacio">
            <h2>Sin datos para mostrar</h2>
            <p>
                <?php if ($definicion['periodo']): ?>
                    No hay documentos emitidos en el periodo seleccionado. Prueba con otro rango de fechas.
                <?php else: ?>
                    Todavia no hay folios cargados para este emisor.
                <?php endif; ?>
            </p>
        </div>
    <?php else: ?>
        <div class="tabla-scroll">
            <table class="tabla-datos">
                <caption><?= count($filas); ?> fila<?= count($filas) === 1 ? '' : 's'; ?></caption>
                <thead>
                    <tr>
                        <?php foreach ($columnas as $c): ?>
                            <th<?= $c['alineacion'] === 'R' ? ' class="tabla-datos__num"' : ''; ?>><?= htmlspecialchars((string) $c['titulo']); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($filas as $fila): ?>
                        <tr>
                            <?php foreach ($fila as $j => $celda): ?>
                                <td<?= ($columnas[$j]['alineacion'] ?? 'L') === 'R' ? ' class="tabla-datos__num"' : ''; ?>><?= htmlspecialchars(informeCelda($celda, $columnas[$j]['num'] ?? false)); ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php if ($totales !== null): ?>
                    <tfoot>
                        <tr>
                            <?php foreach ($totales as $j => $celda): ?>
                                <th<?= ($columnas[$j]['alineacion'] ?? 'L') === 'R' ? ' class="tabla-datos__num"' : ''; ?>><?= htmlspecialchars(informeCelda($celda, $columnas[$j]['num'] ?? false)); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    <?php endif; ?>
</section>

<div class="panel-info">
    <p class="panel-info__titulo">
        <span class="panel-info__icono" aria-hidden="true">&#9432;</span>
        Sobre estos datos
    </p>
    <ul class="panel-info__lista">
        <li>Solo incluyen documentos de <strong>produccion</strong> de <?= htmlspecialchars((string) $rutEmisor); ?>.</li>
        <li>Las descargas contienen exactamente las filas que ves en pantalla.</li>
        <?php if ($clave === 'facturacion'): ?>
            <li>El neto del periodo resta las notas de credito, igual que el dashboard.</li>
        <?php endif; ?>
        <?php if ($clave === 'detalle' || $clave === 'clientes'): ?>
            <li>La razon social sale de tu maestro de clientes: un receptor que no este ahi aparece sin nombre.</li>
        <?php endif; ?>
        <?php if ($clave === 'detalle'): ?>
            <li>El monto exento no aparece porque no queda guardado al emitir.</li>
        <?php endif; ?>
    </ul>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
