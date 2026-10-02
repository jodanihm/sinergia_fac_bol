<?php
/**
 * Certificacion de FACTURA ante el SII: el resumen.
 *
 * TAREA: ver en que etapa va la certificacion y llegar a la herramienta que
 * toca. Por eso el orden: donde estoy (barra de etapas) -> con que lo hago
 * (herramientas, en columnas) -> que viene despues (ayuda plegada).
 */
$titulo = 'Certificacion de factura';
require __DIR__ . '/partials/header.php';
?>

<div class="dash-header">
    <div>
        <h1>Certificacion de factura</h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <a class="boton-secundario" href="/certificacion-elegir">Ver factura y boleta</a>
    </div>
</div>
<p class="dash-subtitulo">
    El SII exige Set Basico, Libro de Ventas y Libro de Compras aceptados antes de declarar cumplimiento.
</p>

<?php require __DIR__ . '/partials/certificacion/_flash.php'; ?>

<?php require __DIR__ . '/partials/certificacion/_barra-etapas.php'; ?>

<h2 class="titulo-seccion">Herramientas</h2>
<div class="config-columnas config-columnas--2 config-columnas--4">
    <section class="tarjeta" aria-labelledby="h-set-pruebas">
        <h3 id="h-set-pruebas" class="titulo-seccion">Archivo de pruebas</h3>
        <p class="tarjeta__intro">Sube el SIISetDePruebas, revisa el preview y emite el Set Basico.</p>
        <a class="boton-secundario" href="/certificacion/set-pruebas">Subir archivo de pruebas</a>
    </section>
    <section class="tarjeta" aria-labelledby="h-simulacion">
        <h3 id="h-simulacion" class="titulo-seccion">Set de Simulacion</h3>
        <p class="tarjeta__intro">Arma y envia el lote de 20 a 100 documentos de la etapa 2.</p>
        <a class="boton-secundario" href="/certificacion/simulacion">Generar simulacion</a>
    </section>
    <section class="tarjeta" aria-labelledby="h-intercambio">
        <h3 id="h-intercambio" class="titulo-seccion">Intercambio</h3>
        <p class="tarjeta__intro">Genera las respuestas de Intercambio de Informacion de la etapa 3.</p>
        <a class="boton-secundario" href="/certificacion/intercambio">Generar respuestas</a>
    </section>
    <section class="tarjeta" aria-labelledby="h-muestras">
        <h3 id="h-muestras" class="titulo-seccion">Muestras Impresas</h3>
        <p class="tarjeta__intro">Genera el PDF con las muestras impresas de la etapa 4.</p>
        <a class="boton-secundario" href="/certificacion/muestras-impresas">Generar PDF</a>
    </section>
</div>

<details class="tarjeta">
    <summary>Que sigue y donde se confirma</summary>
    <ul class="lista-ayuda">
        <li>
            <strong>Declaras Avance</strong> en
            <a href="https://maullin.sii.cl/cvc_cgi/dte/pe_avance1" target="_blank" rel="noopener noreferrer">pe_avance1</a>
            apenas emites, sin esperar el SOK: declarar avance es lo que dispara la revision de
            contenido del SII.
        </li>
        <li>
            El panel <strong>no consulta al SII solo</strong>: usa "Actualizar estado" en el historial
            de cada componente y revisa
            <a href="https://maullin.sii.cl/cvc_cgi/dte/pe_avance5" target="_blank" rel="noopener noreferrer">pe_avance5</a>
            o el correo "Resultado de Revision del Set de Prueba" (SOK/SRH). El correo no esta
            garantizado: no dependas solo de el.
        </li>
        <li>
            El <strong>Set Basico</strong> lo confirmas tu con "Marcar como SOK" despues de ver
            "REVISADO CONFORME", nunca por adivinar. Los <strong>libros</strong> pasan solos a
            LOK/LTC al actualizar el estado.
        </li>
        <li>
            Si reenviaste un componente, el historial guarda todos los intentos: identifica el
            <strong>vigente</strong> (el Track ID que declaraste en pe_avance1) antes de seguir.
        </li>
        <li>
            <strong>Declarar Cumplimiento</strong> no se puede deshacer y exige el certificado del
            <strong>representante legal</strong> (no el del firmante tecnico). Antes, verifica que el
            portal diga explicitamente que "ha finalizado con la certificacion".
        </li>
    </ul>
</details>

<?php require __DIR__ . '/partials/footer.php'; ?>
