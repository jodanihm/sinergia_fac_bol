<?php if ($todosAprobados): ?>
<div class="config-columnas config-columnas--2">
    <section class="seccion-manual" aria-labelledby="titulo-pasos-finales">
        <h2 id="titulo-pasos-finales">Pasos finales en el portal del SII</h2>
        <p>Los 3 componentes estan aprobados. Ahora, en el portal del SII (fuera de este panel):</p>
        <ol class="lista-pasos">
            <li>Declara Avance de <strong>cada componente por separado</strong> (Set Basico, Libro de
                Ventas, Libro de Compras) en
                <a href="https://maullin.sii.cl/cvc_cgi/dte/pe_avance1" target="_blank" rel="noopener noreferrer">Declarar Avance (pe_avance1)</a>,
                con los datos exactos de la tabla.</li>
            <li>Espera el correo <strong>SOK</strong> de cada componente. El correo manda: el portal
                suele atrasarse.</li>
            <li>Verifica que los 3 queden en <strong>"REVISADO CONFORME"</strong> en
                <a href="https://maullin.sii.cl/cvc_cgi/dte/pe_avance5" target="_blank" rel="noopener noreferrer">Ver estado de la postulacion (pe_avance5)</a>.</li>
            <li>Recien entonces <strong>Declara Cumplimiento</strong>. Ese paso no se puede deshacer
                en el SII.</li>
        </ol>
    </section>

    <section class="tarjeta" aria-labelledby="titulo-datos-avance">
        <h2 id="titulo-datos-avance">Datos para "Declarar Avance"</h2>
        <p class="tarjeta__intro">Copialos tal cual en los campos "N Envio" y "Fecha Envio" de cada componente.</p>
        <table class="tabla-datos tabla-datos--cards">
            <thead>
                <tr>
                    <th scope="col">Componente</th>
                    <th scope="col">N Envio (Track ID)</th>
                    <th scope="col">Fecha Envio (dd-mm-aaaa)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ([
                    ['Set Basico', $setBasico['trackId'], $fechaSetBasico],
                    ['Libro de Ventas', $libroVentasAprobado['trackId'], $fechaLibroVentas],
                    ['Libro de Compras', $libroComprasAprobado['trackId'], $fechaLibroCompras],
                ] as [$componente, $track, $fecha]): ?>
                <tr>
                    <td data-label="Componente"><strong><?= $componente; ?></strong></td>
                    <td data-label="N Envio"><code class="dato-copiable"><?= htmlspecialchars((string) $track); ?></code></td>
                    <td data-label="Fecha Envio"><code class="dato-copiable"><?= htmlspecialchars((string) $fecha); ?></code></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="nota">Estos envios no pueden tener documentos con reparos o rechazos.</p>
        <p><a class="boton-secundario" href="/certificacion-aprobada">Ir a Certificacion aprobada</a></p>
    </section>
</div>
<?php else: ?>
<div class="panel-info">
    <p class="panel-info__titulo"><?= iconoSvg('sin-cargar', 20, 'panel-info__icono'); ?>Todavia no corresponde</p>
    <p>Estos pasos se activan cuando los 3 componentes del Set de Prueba esten aprobados. Falta al menos uno.</p>
    <p><a href="/certificacion/etapa/1">Ver el Set de Prueba</a></p>
</div>
<?php endif; ?>
