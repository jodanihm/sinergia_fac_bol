<?php
/**
 * Configuracion > Certificado digital (ambiente de CERTIFICACION).
 *
 * Recibe UNA sola variable: $error (string|null). La arma procesarCertificadoGet()
 * como null, y procesarCertificadoPost() con el mensaje exacto de cada fallo.
 *
 * LO QUE ESTA PANTALLA NO SABE, y por eso no muestra: si ya hay un certificado
 * cargado, su titular, su vigencia o su estado. La vista no recibe ese dato, asi
 * que no se afirma nada al respecto -- ni "vigente", ni "instalado", ni fechas.
 *
 * SEGURIDAD: la clave va en type="password", sin value y sin re-render tras un
 * error. No se agrega autocomplete (hoy no existe y ponerlo cambiaria el
 * comportamiento del navegador). El archivo se cifra antes de guardarse y la
 * clave no se persiste: ambas afirmaciones vienen del texto original de esta
 * vista y del flujo de procesarCertificadoPost().
 *
 * AMBIENTE: la distincion certificacion/produccion se comunica por DOS canales
 * -- el badge del encabezado y el color del panel lateral -- para no depender
 * solo del color. Mismo patron en certificado-produccion.php.
 */
$titulo = 'Certificado digital';
require __DIR__ . '/partials/header.php';

$req = '<span class="campo-obligatorio" aria-hidden="true">*</span>'
    . '<span class="visualmente-oculto">(obligatorio)</span>';
?>

<div class="dash-header">
    <div>
        <h1>Certificado digital <span class="badge badge--etiqueta">Certificacion</span></h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <a class="boton-secundario" href="/panel">Volver al panel</a>
    </div>
</div>
<p class="dash-subtitulo">
    Sube el certificado con el que se firman los documentos que envias al ambiente de certificacion.
</p>

<?php if (! empty($error)): ?>
    <p class="alerta alerta--error" role="alert">
        <span class="alerta__icono" aria-hidden="true">&#9888;</span>
        <span><?= htmlspecialchars($error); ?></span>
    </p>
<?php endif; ?>

<form method="post" action="/certificado" enctype="multipart/form-data" class="form-compacto">
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
            <div class="panel-info">
                <p class="panel-info__titulo">
                    <span class="panel-info__icono" aria-hidden="true">&#9432;</span>
                    Ambiente de certificacion
                </p>
                <ul class="panel-info__lista">
                    <li>Firma los documentos de prueba que envias al SII mientras te certificas.</li>
                    <li>El archivo se cifra antes de guardarse.</li>
                    <li>La clave nunca se almacena.</li>
                    <li>Se guarda por separado del certificado de produccion.</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="acciones-grupo">
        <button type="submit" class="boton-principal">Subir certificado</button>
        <a class="boton-texto" href="/panel">Cancelar</a>
    </div>
</form>

<?php require __DIR__ . '/partials/footer.php'; ?>
