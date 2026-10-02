<?php
/**
 * Pinta la lista de etapas ("Por pasos" del design system). La usan la barra
 * de la certificacion de factura (enlazada, una pagina por etapa) y las dos
 * tarjetas de certificacion-elegir.php (factura y boleta, sin enlace).
 *
 * Recibe:
 *   $barraSegmentos  list<array{nombre:string, clase:string}> -- clase es una
 *                    de etapa--completada | --activa | --no-gestionada | --rechazada
 *   $barraListas     int, cuantas estan completadas
 *   $barraToca       ?string, nombre de la etapa que corresponde hacer
 *   $barraEnlazada   bool, si cada etapa lleva a /certificacion/etapa/N
 *   $barraEtiqueta   ?string, nombre accesible del <nav>
 *   $etapaActual     ?int (solo con $barraEnlazada), la pagina que se esta viendo
 *
 * Cada estado se dice con icono + palabra + color (WCAG 1.4.1).
 */
$estadosEtapa = [
    'etapa--completada'    => ['check', 'Lista'],
    'etapa--activa'        => ['flecha', 'Te toca'],
    'etapa--no-gestionada' => ['sin-cargar', 'Falta una anterior'],
    'etapa--rechazada'     => ['alerta', 'Rechazada'],
];
$barraTotal = count($barraSegmentos);
$paginaVista = $barraEnlazada ? ($etapaActual ?? null) : null;
?>
<nav class="etapas" aria-label="<?= htmlspecialchars($barraEtiqueta ?? 'Etapas de la certificacion'); ?>">
    <p class="etapas__resumen">
        <strong><?= (int) $barraListas; ?> de <?= $barraTotal; ?> etapas listas.</strong>
        <?php if ($barraListas === $barraTotal): ?>
            No queda ninguna pendiente.
        <?php elseif ($barraToca !== null): ?>
            Te toca: <?= htmlspecialchars($barraToca); ?>.
        <?php endif; ?>
    </p>
    <ol class="progreso-etapas progreso-etapas--lista">
        <?php foreach ($barraSegmentos as $i => $s): ?>
            <?php
                [$iconoEtapa, $textoEtapa] = $estadosEtapa[$s['clase']] ?? ['sin-cargar', 'Pendiente'];
                $esVista = $paginaVista === $i + 1;
                $clases  = 'etapa ' . $s['clase'] . ($esVista ? ' etapa--actual' : '');
            ?>
            <li>
                <?php if ($barraEnlazada): ?>
                <a href="/certificacion/etapa/<?= $i + 1; ?>" class="<?= $clases; ?>"<?= $esVista ? ' aria-current="page"' : ''; ?>>
                <?php else: ?>
                <span class="<?= $clases; ?>">
                <?php endif; ?>
                    <span class="etapa__numero"><?= iconoSvg($iconoEtapa, 16, ''); ?><?= $i + 1; ?></span>
                    <?= htmlspecialchars($s['nombre']); ?>
                    <span class="etapa__estado"><?= $textoEtapa; ?></span>
                <?php if ($barraEnlazada): ?>
                </a>
                <?php else: ?>
                </span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
</nav>
