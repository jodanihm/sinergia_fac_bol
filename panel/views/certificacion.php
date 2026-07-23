<?php $titulo = 'En certificacion (sets de prueba)'; require __DIR__ . '/partials/header.php'; ?>

<h1>En certificacion (sets de prueba)</h1>
<p>La certificacion de factura ante el SII exige <strong>3 componentes</strong>: Set
Basico, Libro de Ventas y Libro de Compras. Los 3 deben quedar aceptados aqui antes de
poder declarar cumplimiento en el portal del SII (estacion siguiente). Las boletas se
certifican en un proceso APARTE.</p>

<p><a href="/certificacion/set-pruebas">Subir y previsualizar el archivo SIISetDePruebas del SII &rarr;</a></p>
<p><a href="/certificacion/simulacion">Generar y enviar el Set de Simulacion &rarr;</a></p>
<p><a href="/certificacion/intercambio">Generar respuestas de Intercambio de Informacion &rarr;</a></p>
<p><a href="/certificacion/muestras-impresas">Generar PDF de Muestras Impresas &rarr;</a></p>

<?php if ($flash !== null): ?>
    <?php if ($flash['tipo'] === 'error'): ?>
    <p class="errores"><?= htmlspecialchars($flash['mensaje']); ?></p>
    <?php else: ?>
    <p style="color:#2e7d32;font-weight:600;"><?= htmlspecialchars($flash['mensaje']); ?></p>
    <?php endif; ?>
    <?php if (isset($flash['simulacionTrackId'])): ?>
    <div style="border:2px solid #2e7d32;border-radius:6px;padding:0.75rem 1rem;margin:0.5rem 0 1rem;background:#f1f8f1;">
        <strong>Track ID del Set de Simulacion, listo para copiar:</strong>
        <p style="font-family:monospace;word-break:break-all;background:#fff;padding:0.5rem;border:1px solid #ccc;border-radius:4px;margin:0.35rem 0 0;user-select:all;"><?= htmlspecialchars((string) $flash['simulacionTrackId']); ?></p>
        <span style="color:#999;font-size:0.85em;">Pegalo en el campo "Track ID" de Simulacion, en la
        <a href="/certificacion/etapa/2">etapa 2 (Simulacion)</a>, cuando confirmes que el SII aprobo el contenido.</span>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/partials/certificacion/_barra-etapas.php'; ?>

<details class="tarjeta">
    <summary>Que sigue y donde se confirma</summary>
    <ul style="padding-left:1.2rem;margin:0.5rem 0 0;">
        <li style="margin-bottom:0.5rem;">
            <strong>Declaras Avance</strong> en
            <a href="https://maullin.sii.cl/cvc_cgi/dte/pe_avance1" target="_blank" rel="noopener noreferrer">pe_avance1</a>
            apenas emites (TU, YA) &mdash; no esperes SOK antes: declarar avance es lo que
            DISPARA la revision de contenido del SII, es al reves de lo que parece.
        </li>
        <li style="margin-bottom:0.5rem;">
            El panel <strong>NO consulta al SII solo</strong>: presiona "Actualizar estado" en
            el historial de cada componente para refrescar, y revisa
            <a href="https://maullin.sii.cl/cvc_cgi/dte/pe_avance5" target="_blank" rel="noopener noreferrer">pe_avance5</a>
            o el correo "Resultado de Revision del Set de Prueba" (SOK/SRH); el correo no esta
            garantizado, no dependas solo de el.
        </li>
        <li style="margin-bottom:0.5rem;">
            <strong>Set Basico</strong> se confirma marcando TU el checkbox "Marcar como SOK"
            tras ver "REVISADO CONFORME" (nunca por adivinar). <strong>Libros</strong> pasan
            solos a <strong>LOK/LTC</strong> tras Actualizar Estado: sin checkbox, un paso menos.
        </li>
        <li style="margin-bottom:0.5rem;">
            Si reenviaste un componente mas de una vez, el historial guarda TODOS los intentos:
            identifica cual es el <strong>VIGENTE</strong> (el trackId que declaraste en
            pe_avance1) antes de seguir.
        </li>
        <li>
            <strong>Declarar Cumplimiento</strong> (irreversible) exige el certificado del
            <strong>REPRESENTANTE LEGAL</strong> (no el firmante tecnico); verifica antes que
            el portal diga EXPLICITAMENTE que "ha finalizado con la certificacion".
        </li>
    </ul>
</details>

<p><a href="/panel">Volver al panel</a></p>

<?php require __DIR__ . '/partials/footer.php'; ?>
