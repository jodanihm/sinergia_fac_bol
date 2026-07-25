<?php $titulo = 'Panel'; require __DIR__ . '/partials/header.php'; ?>

<h1>Tu progreso</h1>

<div class="estaciones">
<?php foreach ($estaciones as $i => $e): ?>
    <?php
        // La estacion 7 trae 'subpasos' solo cuando la certificacion ya esta
        // confirmada. Sin esa clave se pinta igual que siempre ("Proximamente").
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
    <div class="estacion estacion--<?= htmlspecialchars($e['estado']); ?>">
        <span class="estacion__numero"><?= $i + 1; ?></span>
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
    </div>

    <?php if ($tieneSubpasos): ?>
    <div style="display:flex;flex-direction:column;gap:0.35rem;margin-left:2.25rem;">
        <?php foreach ($e['subpasos'] as $p): ?>
            <?php
                $ok = (bool) $p['completado'];
                // Los obligatorios en naranja cuando faltan (hay que hacerlos);
                // el opcional en gris (no bloquea nada).
                if ($ok) {
                    $colorBorde = '#2e7d32';
                    $fondo      = '#f1f8f1';
                    $colorTexto = '#2e7d32';
                } elseif ($p['obligatorio']) {
                    $colorBorde = '#ed6c02';
                    $fondo      = '#fff8f0';
                    $colorTexto = '#ed6c02';
                } else {
                    $colorBorde = '#bbb';
                    $fondo      = '#fafafa';
                    $colorTexto = '#777';
                }
                $estiloFila = 'display:flex;align-items:center;gap:0.6rem;padding:0.45rem 0.6rem;'
                    . 'border:1px solid ' . $colorBorde . ';border-left-width:4px;border-radius:5px;'
                    . 'background:' . $fondo . ';';
            ?>
            <div style="<?= htmlspecialchars($estiloFila); ?>">
                <span style="flex:1;">
                    <?= htmlspecialchars((string) $p['titulo']); ?>
                    <?php if (! $p['obligatorio']): ?>
                        <span style="color:#777;font-size:0.8rem;">&mdash; no bloquea la emision</span>
                    <?php endif; ?>
                </span>
                <span style="font-weight:600;color:<?= htmlspecialchars($colorTexto); ?>;">
                    <?= $ok ? 'Configurado' : 'Falta'; ?>
                </span>
                <a href="<?= htmlspecialchars((string) $p['destino']); ?>"><?= $ok ? 'Ver' : 'Completar'; ?></a>
            </div>
        <?php endforeach; ?>

        <p style="margin:0.35rem 0 0;color:#666;font-size:0.85rem;">
            "Configurado" quiere decir que el dato ya esta cargado en esta aplicacion.
            La autorizacion como emisor electronico la otorga el SII, no este panel.
        </p>
    </div>
    <?php endif; ?>
<?php endforeach; ?>
</div>

<?php if ($mostrarApiKeys): ?>
<div style="margin-top:1.5rem;padding:0.75rem;border:1px solid #ddd;border-radius:6px;">
    <strong>Credenciales de API</strong>
    <p style="margin:0.4rem 0 0;">Genera o administra las API keys para conectar tus
    sistemas al motor de facturacion.</p>
    <p style="margin:0.4rem 0 0;"><a href="/apikeys">Ver / generar API keys</a></p>
</div>
<?php endif; ?>

<p><a href="/logout">Cerrar sesion</a></p>

<?php require __DIR__ . '/partials/footer.php'; ?>
