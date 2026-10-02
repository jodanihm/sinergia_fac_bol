<?php
/**
 * Certificacion aprobada: el tenant registra que el SII lo autorizo como
 * emisor electronico. El SII no lo informa solo; es una declaracion propia.
 * A la izquierda que hacer en el SII, a la derecha registrarlo aqui.
 */
$titulo = 'Certificacion aprobada';
require __DIR__ . '/partials/header.php';
?>

<div class="dash-header">
    <div>
        <h1>Certificacion aprobada</h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <a class="boton-secundario" href="/certificacion">Volver a certificacion</a>
    </div>
</div>
<p class="dash-subtitulo">
    El SII no avisa cuando tu empresa queda certificada: lo registras tu aqui cuando ocurra.
</p>

<?php require __DIR__ . '/partials/certificacion/_flash.php'; ?>

<?php if ($confirmadaAt !== null): ?>

<div class="alerta alerta--exito">
    <?= iconoSvg('check', 20, 'alerta__icono'); ?>
    <div class="alerta__cuerpo">
        <p class="alerta__titulo">Confirmaste la certificacion el <?= htmlspecialchars($confirmadaAt); ?></p>
        <p>Tu empresa quedo registrada en este panel como emisor electronico autorizado por el SII,
        segun tu propia declaracion.</p>
    </div>
</div>

<?php else: ?>

<div class="config-columnas config-columnas--2">
    <div class="panel-info">
        <p class="panel-info__titulo"><?= iconoSvg('info', 20, 'panel-info__icono'); ?>El paso final es en el SII</p>
        <p>Ya completaste el Set Basico (factura, nota de credito y nota de debito aceptadas).</p>
        <ol class="lista-pasos">
            <li>Entra a
                <a href="https://maullin.sii.cl/cvc_cgi/dte/pe_avance7" target="_blank" rel="noopener noreferrer">Declarar Cumplimiento (pe_avance7)</a>.
                <strong>No se puede deshacer en el SII</strong>: hazlo solo cuando el portal muestre todos
                los sets en "REVISADO CONFORME".</li>
            <li>Cuando el SII autorice a tu empresa como emisor electronico, vuelve aqui y registralo.</li>
        </ol>
    </div>

    <section class="tarjeta" aria-labelledby="titulo-registrar">
        <h2 id="titulo-registrar">Registrar la autorizacion</h2>
        <form method="post" action="/certificacion-aprobada/confirmar" class="form-compacto" data-espera
              data-confirmar-titulo="Registrar la certificacion como aprobada?"
              data-confirmar-texto="Queda registrado que tu empresa es emisor electronico autorizado por el SII. Hazlo solo si el SII ya te autorizo."
              data-confirmar-boton="Si, registrar">
            <?= csrfInput(); ?>
            <div class="form-campo">
                <label class="form-check" for="confirmo">
                    <input type="checkbox" name="confirmo" id="confirmo" value="1"
                           data-requerido="Marca la casilla: solo se registra cuando el SII ya te autorizo.">
                    Declare cumplimiento en el portal del SII y mi empresa fue autorizada como emisor electronico
                </label>
            </div>
            <div class="acciones-formulario">
                <button type="submit" class="boton-principal">Registrar confirmacion</button>
            </div>
        </form>
    </section>
</div>

<?php require __DIR__ . '/partials/certificacion/_ux-formularios.php'; ?>

<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
