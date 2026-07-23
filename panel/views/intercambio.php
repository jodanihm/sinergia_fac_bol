<?php $titulo = 'Intercambio de Informacion'; require __DIR__ . '/partials/header.php'; ?>

<h1>Intercambio de Informacion</h1>
<p>El SII entrega, en el portal de Intercambio, un archivo EnvioDTE con documentos de
prueba dirigidos a tu empresa (que debes ACEPTAR) y a veces a un RUT ajeno (que debes
RECHAZAR automaticamente). Sube ese archivo aqui para generar las 3 respuestas que el SII
exige de vuelta.</p>

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

<?php if ($errorParseo !== null): ?>
<p class="errores"><?= htmlspecialchars($errorParseo); ?></p>
<?php endif; ?>

<h2><?= $fila === null ? 'Subir el EnvioDTE recibido' : 'Regenerar (subir un EnvioDTE nuevo)'; ?></h2>
<?php if ($fila !== null): ?>
<p style="color:#999;">Ya generaste respuestas para un EnvioDTE
<?php if ($fila['numero_intercambio'] !== null): ?>
(set de intercambio numero <?= htmlspecialchars((string) $fila['numero_intercambio']); ?>)
<?php endif; ?>
el <?= htmlspecialchars((string) $fila['created_at']); ?>. Subir un archivo nuevo reemplaza
lo generado antes.</p>
<?php endif; ?>
<form method="post" action="/certificacion/intercambio" enctype="multipart/form-data">
    <?= csrfInput(); ?>
    <label>Archivo EnvioDTE (.xml)
        <input type="file" name="archivo" accept=".xml" required>
    </label>
    <button type="submit">Generar respuestas</button>
</form>

<?php if ($documentos !== null): ?>

<h2>Documentos del envio recibido</h2>
<table>
    <thead>
        <tr>
            <th>Tipo DTE</th>
            <th>Folio</th>
            <th>RUT Emisor</th>
            <th>RUT Receptor</th>
            <th>Monto Total</th>
            <th>Resultado</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($documentos as $doc): ?>
        <tr>
            <td><?= htmlspecialchars((string) $doc['TipoDTE']); ?></td>
            <td><?= htmlspecialchars((string) $doc['Folio']); ?></td>
            <td><?= htmlspecialchars((string) $doc['RUTEmisor']); ?></td>
            <td><?= htmlspecialchars((string) $doc['RUTRecep']); ?></td>
            <td><?= htmlspecialchars((string) $doc['MntTotal']); ?></td>
            <td>
            <?php if ($doc['aceptado']): ?>
                <strong style="color:#2e7d32;">ACEPTADO</strong> (dirigido a tu empresa)
            <?php else: ?>
                <strong style="color:#b3261e;">RECHAZADO</strong> (RUT ajeno, no es tu empresa)
            <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<h2>Descargar las 3 respuestas generadas</h2>
<ul>
    <li><a href="/certificacion/intercambio/acuse.xml">respuesta_acuse.xml</a> (RecepcionEnvio, Archivo 1)</li>
    <li><a href="/certificacion/intercambio/recibos.xml">respuesta_recibos.xml</a> (EnvioRecibos, Archivo 2)</li>
    <li><a href="/certificacion/intercambio/resultado.xml">respuesta_resultado.xml</a> (ResultadoDTE, Archivo 3)</li>
</ul>

<h2>Paso manual siguiente (fuera de este sistema)</h2>
<p class="errores" style="background:#fff7e6;color:#7a4b00;">
Este paso NO se puede automatizar: el SII solo lo recibe por su portal web, no tiene API
para esto. Sube los 3 archivos descargados arriba en
<a href="https://www4.sii.cl/pfeInternet/#menu" target="_blank" rel="noopener noreferrer">https://www4.sii.cl/pfeInternet/#menu</a>
&rarr; <strong>SET DE INTERCAMBIO</strong> &rarr; <strong>Subir archivos XML de respuesta de Intercambio</strong>,
en <strong>Archivo 1</strong> (respuesta_acuse.xml), <strong>Archivo 2</strong>
(respuesta_recibos.xml) y <strong>Archivo 3</strong> (respuesta_resultado.xml)
respectivamente. La respuesta del portal es EN LINEA, sin espera de funcionario.
</p>

<?php endif; ?>

<p><a href="/certificacion">Volver a En certificacion</a></p>

<?php require __DIR__ . '/partials/footer.php'; ?>
