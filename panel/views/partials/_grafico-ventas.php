<?php
/**
 * Grafico SVG inline de la serie diaria del periodo. Espera $serie, tal como
 * lo arma dashSerieCompleta() (todos los dias del mes, con ceros incluidos).
 *
 * Dos series desde una linea base cero: ventas hacia arriba, notas de credito
 * hacia abajo. Asi el neto se lee como la diferencia visual sin esconder de
 * que esta compuesto, que es justo lo que un grafico de "ventas" que suma
 * notas de credito como ventas estaria ocultando.
 *
 * Sin librerias ni JS: SVG generado en el servidor. El grafico es
 * role="img" con titulo y descripcion, y debajo va SIEMPRE la tabla de datos
 * completa (dentro de un <details>), que es la fuente accesible real: nadie
 * depende de poder ver el dibujo.
 */
$maxVentas = 0;
$maxNc     = 0;
$hayDatos  = false;
foreach ($serie as $d) {
    $maxVentas = max($maxVentas, $d['ventas']);
    $maxNc     = max($maxNc, $d['notasCredito']);
    if ($d['documentos'] > 0) {
        $hayDatos = true;
    }
}
$rango = $maxVentas + $maxNc;

$fmtMonto = static function ($v): string {
    return '$' . number_format((float) $v, 0, ',', '.');
};
?>

<?php if (! $hayDatos): ?>

    <p class="dash-vacio-inline">No hubo documentos emitidos en este periodo.</p>

<?php else: ?>

    <?php
        $paso      = 14;   // ancho de columna, en unidades del viewBox
        $anchoBar  = 10;
        $alto      = 190;
        $padTop    = 10;
        $padBottom = 22;   // espacio para las etiquetas de dia
        $areaAlto  = $alto - $padTop - $padBottom;
        $ancho     = max(1, count($serie)) * $paso;
        // Linea base: proporcional, para que ventas y NC compartan escala.
        $ejeY = $rango > 0 ? $padTop + ($areaAlto * ($maxVentas / $rango)) : $padTop + $areaAlto;
    ?>

    <figure class="grafico">
        <svg class="grafico__svg"
             viewBox="0 0 <?= $ancho; ?> <?= $alto; ?>"
             preserveAspectRatio="xMidYMid meet"
             role="img"
             aria-labelledby="grafico-titulo grafico-desc">
            <title id="grafico-titulo">Ventas y notas de credito por dia</title>
            <desc id="grafico-desc">Barras diarias: las ventas se dibujan hacia arriba
            de la linea base y las notas de credito hacia abajo. Los valores exactos
            estan en la tabla de datos que acompana al grafico.</desc>

            <line x1="0" y1="<?= round($ejeY, 2); ?>" x2="<?= $ancho; ?>" y2="<?= round($ejeY, 2); ?>"
                  class="grafico__eje" />

            <?php foreach ($serie as $i => $d): ?>
                <?php
                    $x       = ($i * $paso) + (($paso - $anchoBar) / 2);
                    $altoV   = $rango > 0 ? ($d['ventas'] / $rango) * $areaAlto : 0;
                    $altoNc  = $rango > 0 ? ($d['notasCredito'] / $rango) * $areaAlto : 0;
                ?>
                <?php if ($altoV > 0): ?>
                    <rect class="grafico__venta"
                          x="<?= round($x, 2); ?>" y="<?= round($ejeY - $altoV, 2); ?>"
                          width="<?= $anchoBar; ?>" height="<?= round($altoV, 2); ?>" />
                <?php endif; ?>
                <?php if ($altoNc > 0): ?>
                    <rect class="grafico__nc"
                          x="<?= round($x, 2); ?>" y="<?= round($ejeY, 2); ?>"
                          width="<?= $anchoBar; ?>" height="<?= round($altoNc, 2); ?>" />
                <?php endif; ?>
                <?php if (((int) $d['dia']) % 5 === 0): ?>
                    <text class="grafico__dia"
                          x="<?= round($x + ($anchoBar / 2), 2); ?>"
                          y="<?= $alto - 6; ?>"
                          text-anchor="middle"><?= (int) $d['dia']; ?></text>
                <?php endif; ?>
            <?php endforeach; ?>
        </svg>

        <figcaption class="grafico__leyenda">
            <span class="grafico__clave grafico__clave--venta">Ventas (factura, boleta, nota de debito)</span>
            <span class="grafico__clave grafico__clave--nc">Notas de credito (rebajan)</span>
        </figcaption>
    </figure>

    <details class="grafico__datos">
        <summary>Ver los datos del grafico en una tabla</summary>
        <div class="tabla-scroll">
            <table>
                <caption>Detalle diario del periodo, en montos totales con IVA. Solo se
                listan los dias con documentos.</caption>
                <thead>
                    <tr>
                        <th scope="col">Dia</th>
                        <th scope="col" class="num">Documentos</th>
                        <th scope="col" class="num">Ventas</th>
                        <th scope="col" class="num">Notas de credito</th>
                        <th scope="col" class="num">Neto</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($serie as $d): ?>
                        <?php if ($d['documentos'] === 0) { continue; } ?>
                        <tr>
                            <th scope="row"><?= htmlspecialchars($d['fecha']); ?></th>
                            <td class="num"><?= (int) $d['documentos']; ?></td>
                            <td class="num"><?= $fmtMonto($d['ventas']); ?></td>
                            <td class="num"><?= $fmtMonto($d['notasCredito']); ?></td>
                            <td class="num"><?= $fmtMonto($d['neto']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </details>

<?php endif; ?>
