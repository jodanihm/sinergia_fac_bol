<?php
/**
 * Barras de consumo de folios por tipo de documento. Espera $folios, tal como
 * lo arma dashFoliosPorTipo().
 *
 * Quedarse sin folios detiene la operacion, asi que el nivel se comunica por
 * TRES canales a la vez y no solo por color (WCAG 1.4.1): color de la barra,
 * palabra de estado ("Critico" / "Bajo" / "OK") e icono.
 *
 * La barra usa role="img" con aria-label en vez de <progress> porque necesita
 * el umbral de color; el numero exacto igual va escrito al lado, asi que un
 * lector de pantalla nunca depende de la barra.
 *
 * LA BARRA Y EL NIVEL MIDEN COSAS DISTINTAS, y es a proposito. La barra pinta el
 * PORCENTAJE USADO del rango, que es lo que uno espera de una barra. El nivel
 * sale de las JORNADAS que duran los folios que quedan. Pueden discrepar -- una
 * barra corta con la etiqueta "Critico" -- y por eso debajo va escrita la cuenta:
 * sin ella, un rojo sobre 383 folios disponibles parece un error.
 */
$etiquetaNivel = ['rojo' => 'Critico', 'ambar' => 'Bajo', 'ok' => 'OK'];
$iconoNivel    = ['rojo' => '&#9888;', 'ambar' => '&#9679;', 'ok' => '&#10003;'];
?>
<?php if ($folios === []): ?>
    <p class="dash-vacio-inline">No hay CAF de produccion cargados.</p>
<?php else: ?>
<ul class="folios">
    <?php foreach ($folios as $f): ?>
        <?php
            $pctUsado = $f['totalRango'] > 0
                ? (int) round($f['usados'] * 100 / $f['totalRango'])
                : 0;
        ?>
        <li class="folio folio--<?= htmlspecialchars($f['nivel']); ?>">
            <div class="folio__cabecera">
                <span class="folio__tipo"><?= htmlspecialchars(nombreTipoDte((int) $f['tipo'])); ?></span>
                <span class="folio__nivel">
                    <span aria-hidden="true"><?= $iconoNivel[$f['nivel']]; ?></span>
                    <?= htmlspecialchars($etiquetaNivel[$f['nivel']]); ?>
                </span>
            </div>
            <div class="folio__barra"
                 role="img"
                 aria-label="<?= (int) $pctUsado; ?> por ciento de los folios usados">
                <span class="folio__relleno" style="width:<?= (int) $pctUsado; ?>%;"></span>
            </div>
            <p class="folio__detalle">
                <strong><?= number_format((float) $f['disponibles'], 0, ',', '.'); ?></strong> disponibles
                de <?= number_format((float) $f['totalRango'], 0, ',', '.'); ?>
                (<?= number_format((float) $f['usados'], 0, ',', '.'); ?> usados,
                <?= (int) $f['cafs']; ?> CAF)
            </p>
            <?php
                // LA CUENTA QUE JUSTIFICA EL COLOR, escrita. El nivel se decide
                // por jornadas y no por porcentaje (ver DASH_FOLIOS_JORNADAS_ROJO),
                // asi que si no se muestra el numero, el usuario ve un rojo sobre
                // 383 folios y no puede saber de donde sale.
                //
                // Con cero disponibles no se escribe: "0 jornadas" no agrega nada
                // sobre "0 disponibles", y la division ya no significa nada.
                $jornadas = (float) $f['jornadas'];
            ?>
            <?php if ((int) $f['disponibles'] > 0): ?>
                <p class="folio__ritmo">
                    <?= $jornadas < 1
                        ? 'No alcanzan para una jornada como las tuyas'
                        : 'Te duran ~' . number_format(floor($jornadas), 0, ',', '.')
                          . ($jornadas < 2 ? ' jornada' : ' jornadas') . ' de emision'; ?>
                    <span class="folio__ritmo-base">
                        (emites <?= number_format((float) $f['ritmo'], 1, ',', '.'); ?> al dia que facturas)
                    </span>
                </p>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
