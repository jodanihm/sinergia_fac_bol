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
        </li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>
