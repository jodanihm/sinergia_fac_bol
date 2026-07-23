<?php $titulo = 'Muestras Impresas'; require __DIR__ . '/partials/header.php'; ?>

<h1>Muestras Impresas (Documentos Impresos)</h1>
<p>Genera aqui los PDF con timbre PDF417 del Set Basico (todos los documentos) y de la
Simulacion (una muestra de cada tipo) para subirlos al SII. La subida en si sigue siendo
manual: el SII no tiene API para esta etapa, solo un portal web.</p>

<?php if ($flash !== null): ?>
    <?php if ($flash['tipo'] === 'error'): ?>
    <p class="errores"><?= htmlspecialchars($flash['mensaje']); ?></p>
    <?php else: ?>
    <p style="color:#2e7d32;font-weight:600;"><?= htmlspecialchars($flash['mensaje']); ?></p>
    <?php endif; ?>
<?php endif; ?>

<?php if ($error !== null): ?>
<p class="errores"><?= htmlspecialchars($error); ?></p>
<?php endif; ?>

<h2>1. Set Basico</h2>
<?php if ($setBasico['aprobado']): ?>
<p style="color:#2e7d32;font-weight:600;">APROBADO &mdash; envio <?= htmlspecialchars((string) $setBasico['trackId']); ?>.</p>
<?php else: ?>
<p style="color:#999;font-weight:600;">
PENDIENTE &mdash; el Set Basico debe estar aprobado (EPR) antes de poder generar las
muestras impresas.
</p>
<?php endif; ?>

<h2>2. Simulacion</h2>
<?php if ($simulacion['ambiguo']): ?>
<p class="errores">
Se encontraron <?= count($simulacion['candidatos']); ?> envios que podrian ser la
Simulacion (mas de 8 documentos, aceptados por el SII, distintos del Set Basico). No se
adivina cual es: elige el correcto abajo antes de generar las muestras.
</p>
<?php elseif ($simulacion['aprobado']): ?>
<p style="color:#2e7d32;font-weight:600;">Detectada &mdash; envio <?= htmlspecialchars((string) $simulacion['trackId']); ?>.</p>
<?php else: ?>
<p style="color:#999;">
Aun no se detecta un envio de Simulacion (un envio aceptado, distinto del Set Basico, con
mas de 8 documentos). Si ya la emitiste, puede que el SII aun no la haya aceptado (EPR).
</p>
<?php endif; ?>

<?php if ($planificado !== null): ?>
<h2>3. Documentos a incluir (<?= count($planificado); ?>)</h2>
<table>
    <thead>
        <tr>
            <th>Origen</th>
            <th>Tipo DTE</th>
            <th>Folio</th>
            <th>Lleva cedible</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($planificado as $doc): ?>
        <tr>
            <td><?= $doc['origen'] === 'prueba' ? 'Set Basico' : 'Simulacion'; ?></td>
            <td><?= htmlspecialchars((string) $doc['tipoDte']); ?></td>
            <td><?= htmlspecialchars((string) $doc['folio']); ?></td>
            <td><?= $doc['tipoDte'] === 33 ? 'Si' : 'No'; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<h2>Generar y descargar el ZIP</h2>
<form method="post" action="/certificacion/muestras-impresas.zip">
    <?= csrfInput(); ?>
    <?php if ($simulacion['ambiguo']): ?>
    <label>Envio de Simulacion
        <select name="track_id_simulacion" required>
            <option value="">-- elige el envio --</option>
            <?php foreach ($simulacion['candidatos'] as $c): ?>
            <option value="<?= htmlspecialchars($c['trackId']); ?>">
                <?= htmlspecialchars($c['trackId']); ?> &mdash; <?= htmlspecialchars($c['fechaEmision']); ?>
                (<?= htmlspecialchars($c['resumen']); ?>, <?= (int) $c['cantidad']; ?> documentos)
            </option>
            <?php endforeach; ?>
        </select>
    </label>
    <?php endif; ?>
    <button type="submit" <?= $setBasico['aprobado'] ? '' : 'disabled'; ?>>Generar Muestras Impresas (ZIP)</button>
</form>

<h2>Paso manual siguiente (fuera de este sistema)</h2>
<p class="errores" style="background:#fff7e6;color:#7a4b00;">
Sube los PDF del ZIP en
<a href="https://www4.sii.cl/pdfdteInternet/" target="_blank" rel="noopener noreferrer">https://www4.sii.cl/pdfdteInternet/</a>
(Ingreso de Muestras Impresas: RUT Empresa + RUT Proveedor = tu RUT &rarr; Crear).
<strong>Arrastra TODOS los archivos del ZIP de una sola vez, no en tandas.</strong> Subir
de a poco deja "slots huerfanos" en naranja que despues no se pueden resolver (bug real ya
vivido en esta certificacion). Verifica que todo quede en verde antes de hacer clic en
Enviar al SII.
</p>

<p><a href="/certificacion">Volver a En certificacion</a></p>

<?php require __DIR__ . '/partials/footer.php'; ?>
