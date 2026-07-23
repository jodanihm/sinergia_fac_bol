<div class="seccion-manual">
<h2>Pasos finales en el portal del SII</h2>
<?php if ($todosAprobados): ?>
<p>Los 3 componentes estan aprobados. Ahora, EN EL PORTAL DEL SII (fuera de este panel):</p>
<ol>
    <li>Declara Avance de <strong>cada componente por separado</strong> (Set Basico, Libro
        de Ventas, Libro de Compras) en
        <a href="https://maullin.sii.cl/cvc_cgi/dte/pe_avance1" target="_blank" rel="noopener noreferrer">Declarar Avance (pe_avance1)</a>,
        con los datos exactos de la tabla de abajo.</li>
    <li>Espera el correo <strong>SOK</strong> de cada componente (el correo manda: el
        portal suele atrasarse respecto a el).</li>
    <li>Verifica que los 3 queden en <strong>"REVISADO CONFORME"</strong> en
        <a href="https://maullin.sii.cl/cvc_cgi/dte/pe_avance5" target="_blank" rel="noopener noreferrer">Ver estado de la postulacion (pe_avance5)</a>.</li>
    <li>Recien entonces, ve a la siguiente estacion y <strong>Declara Cumplimiento</strong>
        (ese paso es irreversible en el SII).</li>
</ol>

<h3>Datos para "Declarar Avance"</h3>
<p>Copia estos valores tal cual en el formulario del SII (campos "N Envio" y "Fecha
Envio" de cada componente):</p>
<table>
    <thead>
        <tr>
            <th>Componente</th>
            <th>N Envio (Track ID)</th>
            <th>Fecha Envio (dd-mm-aaaa)</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>Set Basico</td>
            <td><?= htmlspecialchars((string) $setBasico['trackId']); ?></td>
            <td><?= htmlspecialchars((string) $fechaSetBasico); ?></td>
        </tr>
        <tr>
            <td>Libro de Ventas</td>
            <td><?= htmlspecialchars((string) $libroVentasAprobado['trackId']); ?></td>
            <td><?= htmlspecialchars((string) $fechaLibroVentas); ?></td>
        </tr>
        <tr>
            <td>Libro de Compras</td>
            <td><?= htmlspecialchars((string) $libroComprasAprobado['trackId']); ?></td>
            <td><?= htmlspecialchars((string) $fechaLibroCompras); ?></td>
        </tr>
    </tbody>
</table>
<p style="color:#999;">Importante: el SII exige que estos envios NO contengan
documentos con reparos o rechazos. Tras declarar avance, el SII manda un correo
<strong>SOK</strong> por cada componente; ese correo manda sobre el portal
(pe_avance5 se atrasa respecto a el).</p>

<p><a href="/certificacion-aprobada">Ir a Certificacion aprobada &rarr;</a></p>
<?php else: ?>
<p style="color:#999;">
    Estos pasos se activan cuando los 3 componentes de arriba esten APROBADOS. Todavia
    falta al menos uno.
</p>
<?php endif; ?>
</div>
