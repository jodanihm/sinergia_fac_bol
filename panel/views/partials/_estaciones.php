<?php
/**
 * Lista de estaciones de progreso. Extraido de panel.php para poder reusarlo
 * tal cual en el dashboard de gestion (donde va colapsado dentro de un
 * <details> mientras el tenant no haya emitido su primer documento).
 *
 * Espera $estaciones, tal como lo arma handlePanelGet().
 *
 * La estacion 7 puede traer 'subpasos'; el resto no. Cada indicador de estado
 * lleva SIEMPRE texto ademas del color (WCAG 1.4.1: el color no puede ser el
 * unico canal de informacion).
 */
?>
<ol class="estaciones">
<?php foreach ($estaciones as $i => $e): ?>
    <?php
        $tieneSubpasos = isset($e['subpasos']);

        $obligatoriosTotal  = 0;
        $obligatoriosListos = 0;
        if ($tieneSubpasos) {
            foreach ($e['subpasos'] as $p) {
                if (! $p['obligatorio']) {
                    continue;
                }
                $obligatoriosTotal++;
                if ($p['completado']) {
                    $obligatoriosListos++;
                }
            }
        }
    ?>
    <li class="estacion estacion--<?= htmlspecialchars($e['estado']); ?>">
        <span class="estacion__numero" aria-hidden="true"><?= $i + 1; ?></span>
        <span class="estacion__titulo"><?= htmlspecialchars($e['titulo']); ?></span>
        <span class="estacion__estado">
            <?php if ($tieneSubpasos): ?>
                Configurado <?= $obligatoriosListos; ?> de <?= $obligatoriosTotal; ?> pasos obligatorios
            <?php elseif ($e['estado'] === 'completado'): ?>
                Completado
                <?php if (isset($e['enlace'])): ?>
                    &mdash; <a href="<?= htmlspecialchars($e['enlace']); ?>">Ver / editar</a>
                <?php endif; ?>
            <?php elseif ($e['estado'] === 'pendiente'): ?>
                Pendiente &mdash; <a href="<?= htmlspecialchars($e['enlace'] ?? '#'); ?>">completar</a>
            <?php else: ?>
                Proximamente
            <?php endif; ?>
        </span>
    </li>

    <?php if ($tieneSubpasos): ?>
    <li class="estaciones__subpasos">
        <ul class="subpasos">
            <?php foreach ($e['subpasos'] as $p): ?>
                <?php
                    $ok        = (bool) $p['completado'];
                    $modificar = $ok ? 'subpaso--ok' : ($p['obligatorio'] ? 'subpaso--falta' : 'subpaso--opcional');
                ?>
                <li class="subpaso <?= $modificar; ?>">
                    <span class="subpaso__titulo">
                        <?= htmlspecialchars((string) $p['titulo']); ?>
                        <?php if (! $p['obligatorio']): ?>
                            <span class="subpaso__aclaracion">&mdash; no bloquea la emision</span>
                        <?php endif; ?>
                    </span>
                    <span class="subpaso__estado">
                        <span class="subpaso__icono" aria-hidden="true"><?= $ok ? '&#10003;' : '&#9679;'; ?></span>
                        <?= $ok ? 'Configurado' : 'Falta'; ?>
                    </span>
                    <a class="subpaso__accion" href="<?= htmlspecialchars((string) $p['destino']); ?>">
                        <?= $ok ? 'Ver' : 'Completar'; ?>
                        <span class="visualmente-oculto"><?= htmlspecialchars((string) $p['titulo']); ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="subpasos__nota">
            "Configurado" quiere decir que el dato ya esta cargado en esta aplicacion.
            La autorizacion como emisor electronico la otorga el SII, no este panel.
        </p>
    </li>
    <?php endif; ?>
<?php endforeach; ?>
</ol>
