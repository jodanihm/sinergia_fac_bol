<?php
/**
 * Configuracion > Produccion > Certificado digital (ambiente de PRODUCCION).
 *
 * Estructuralmente identica a certificado.php: mismo formulario, mismos names,
 * mismos controles y el mismo unico dato de entrada, $error (string|null), que
 * arma el MISMO handler -- procesarCertificadoGet/Post(), parametrizado por
 * ambiente. Solo cambian la ruta del action, el texto del boton, el color del
 * badge y el contenido del panel lateral, que aqui advierte en vez de informar.
 *
 * Los textos de advertencia son los que ya traia esta vista; no se agregan
 * afirmaciones tributarias nuevas.
 *
 * SEGURIDAD: mismas garantias que en certificacion -- type="password" sin value,
 * sin re-render de la clave tras error, sin autocomplete.
 */
$titulo = 'Certificado digital (PRODUCCION)';
require __DIR__ . '/partials/header.php';

$req = '<span class="campo-obligatorio" aria-hidden="true">*</span>'
    . '<span class="visualmente-oculto">(obligatorio)</span>';
?>

<div class="dash-header">
    <div>
        <h1>Certificado digital <span class="badge badge--advertencia">Produccion</span></h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <a class="boton-secundario" href="/panel">Volver al panel</a>
    </div>
</div>
<p class="dash-subtitulo">
    Sube el certificado con el que se firman tus documentos tributarios reales ante el SII.
</p>

<?php if (! empty($error)): ?>
    <p class="alerta alerta--error" role="alert">
        <span class="alerta__icono" aria-hidden="true">&#9888;</span>
        <span><?= htmlspecialchars($error); ?></span>
    </p>
<?php endif; ?>

<form method="post" action="/certificado-produccion" enctype="multipart/form-data" class="form-compacto">
    <?= csrfInput(); ?>

    <div class="layout-principal-lateral">
        <div>
            <section class="tarjeta" aria-labelledby="titulo-carga">
                <h2 id="titulo-carga">Cargar certificado</h2>
                <div class="form-grid form-grid--1">
                    <div class="form-campo">
                        <label for="certificado">Archivo del certificado (.pfx / .p12) <?= $req; ?></label>
                        <input type="file" name="certificado" id="certificado" accept=".pfx,.p12" required>
                        <small class="form-ayuda">Selecciona el archivo .pfx o .p12 de un certificado digital valido para firmar documentos tributarios.</small>
                    </div>

                    <div class="form-campo form-campo--corto">
                        <label for="clave">Clave del certificado <?= $req; ?></label>
                        <input type="password" name="clave" id="clave" required>
                        <small class="form-ayuda">Se usa solo para abrir el archivo. No se almacena.</small>
                    </div>
                </div>
            </section>
        </div>

        <div>
            <div class="panel-info panel-info--advertencia">
                <p class="panel-info__titulo">
                    <span class="panel-info__icono" aria-hidden="true">&#9888;</span>
                    Ambiente de produccion
                </p>
                <p>Esta pagina es del ambiente de PRODUCCION (palena.sii.cl).</p>
                <p>El certificado que subas aqui firma documentos <strong>TRIBUTARIOS REALES</strong>
                ante el SII, no de prueba.</p>
                <p>No la uses hasta que tu empresa este realmente AUTORIZADA como emisor
                electronico (estacion 6/7).</p>
                <p>Mismo mecanismo de cifrado que en certificacion, guardado en una fila
                separada.</p>
            </div>
        </div>
    </div>

    <div class="acciones-grupo">
        <button type="submit" class="boton-principal">Subir certificado de produccion</button>
        <a class="boton-secundario" href="/caf-produccion">Siguiente: CAF de produccion &rarr;</a>
        <a class="boton-texto" href="/panel">Cancelar</a>
    </div>
</form>

<?php require __DIR__ . '/partials/footer.php'; ?>
