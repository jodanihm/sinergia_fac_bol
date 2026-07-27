<?php
/**
 * Paso 2 de la carga de CAF: revisar antes de guardar.
 *
 * Recibe: $ambiente ('certificacion'|'produccion'), $tipo, $desde, $hasta,
 * $declarado (lo que el usuario escribio, '' en el primer render) y $error.
 *
 * UNA SOLA VISTA PARA LOS DOS AMBIENTES, mismo criterio que emision-form.php
 * con factura/NC/ND: lo unico que bifurca es $esProduccion, que cambia la ruta
 * del form y agrega la advertencia de folios reales.
 *
 * EL CAF NO ESTA AQUI. El archivo quedo cifrado en $_SESSION['caf_pendiente']
 * en el paso 1 y nunca vuelve a viajar por HTTP: contiene la <RSASK>, la clave
 * privada del CAF. Esta pantalla solo muestra los cuatro datos ya parseados
 * (tipo y rango) y pide uno nuevo. No hay campo oculto con el XML, ni con la
 * DEK, ni con la huella.
 *
 * POR QUE EXISTE ESTA PANTALLA: cargar un CAF fija el folio desde el que se
 * van a emitir documentos tributarios reales, y ese dato no se puede corregir
 * despues sin apoyo. Un guardado de un solo clic no da oportunidad de revisar
 * el rango contra lo que el emisor realmente ya uso.
 *
 * Sin JavaScript.
 */
$esProduccion = $ambiente === 'produccion';
$titulo       = $esProduccion ? 'Revisar CAF de produccion' : 'Revisar CAF';
$rutaConfirmar = $esProduccion ? '/caf-produccion/confirmar' : '/caf/confirmar';
$rutaCancelar  = $esProduccion ? '/caf-produccion' : '/caf';

require __DIR__ . '/partials/header.php';

$fmtNum = static function ($v): string {
    return number_format((float) $v, 0, ',', '.');
};
$totalFolios = $hasta - $desde + 1;
?>

<div class="dash-header">
    <div>
        <h1>
            Revisa el CAF antes de guardarlo
            <?php if ($esProduccion): ?>
                <span class="badge badge--advertencia">Produccion</span>
            <?php else: ?>
                <span class="badge badge--etiqueta">Certificacion</span>
            <?php endif; ?>
        </h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <a class="boton-secundario" href="<?= htmlspecialchars($rutaCancelar); ?>">Cancelar</a>
    </div>
</div>
<p class="dash-subtitulo">
    El archivo todavia <strong>no se ha guardado</strong>. Confirma que el rango es el correcto
    y desde que folio debe emitir Sinergia.
</p>

<?php if (! empty($error)): ?>
    <p class="alerta alerta--error" role="alert">
        <span class="alerta__icono" aria-hidden="true">&#9888;</span>
        <span><?= htmlspecialchars($error); ?></span>
    </p>
<?php endif; ?>

<div class="layout-principal-lateral">
    <div>
        <section class="tarjeta" aria-labelledby="titulo-archivo">
            <h2 id="titulo-archivo">Lo que dice el archivo del SII</h2>
            <p class="nota">Estos datos salen del CAF que subiste y no se pueden modificar aqui.</p>
            <dl class="ficha">
                <dt>Tipo de documento</dt>
                <dd><?= htmlspecialchars(nombreTipoDte((int) $tipo)); ?></dd>

                <dt>Rango autorizado</dt>
                <dd><?= (int) $desde; ?> a <?= (int) $hasta; ?></dd>

                <dt>Total de folios del rango</dt>
                <dd><?= $fmtNum($totalFolios); ?></dd>
            </dl>
        </section>

        <section class="tarjeta" aria-labelledby="titulo-desde">
            <h2 id="titulo-desde">Desde que folio va a emitir Sinergia</h2>

            <form method="post" action="<?= htmlspecialchars($rutaConfirmar); ?>" class="form-compacto">
                <?= csrfInput(); ?>

                <div class="form-grid form-grid--1">
                    <div class="form-campo form-campo--corto<?= ! empty($error) ? ' form-campo--error' : ''; ?>">
                        <label for="proximo-folio">Proximo folio disponible</label>
                        <input type="text" inputmode="numeric" name="proximo_folio" id="proximo-folio"
                               value="<?= htmlspecialchars((string) $declarado); ?>"
                               placeholder="<?= (int) $desde; ?>"
                               aria-describedby="ayuda-proximo-folio">
                    </div>
                </div>

                <div id="ayuda-proximo-folio">
                    <p class="nota">
                        <strong>Si este CAF es nuevo</strong> y no has emitido ningun documento de este
                        rango, dejalo vacio. Sinergia parte desde el <?= (int) $desde; ?>.
                    </p>
                    <p class="nota">
                        <strong>Si vienes de otro sistema</strong> y ya usaste parte del rango, escribe el
                        primer folio que <em>aun no has emitido</em>. Los folios anteriores quedan fuera:
                        Sinergia no los va a usar ni los va a contar como emitidos por ti.
                    </p>
                </div>

                <p class="alerta alerta--error" role="note">
                    <span class="alerta__icono" aria-hidden="true">&#9888;</span>
                    <span>
                        Este dato no se puede corregir despues sin apoyo. Un numero <strong>menor</strong>
                        al real repite folios que ya emitiste ante el SII; uno <strong>mayor</strong> los
                        salta. Los dos son problemas tributarios reales.
                    </span>
                </p>

                <div class="acciones-grupo">
                    <button type="submit" class="boton-principal">Confirmar y guardar CAF</button>
                    <a class="boton-texto" href="<?= htmlspecialchars($rutaCancelar); ?>">Cancelar</a>
                </div>
            </form>
        </section>
    </div>

    <div>
        <?php if ($esProduccion): ?>
            <div class="panel-info panel-info--advertencia">
                <p class="panel-info__titulo">
                    <span class="panel-info__icono" aria-hidden="true">&#9888;</span>
                    Ambiente de produccion
                </p>
                <p>Los folios que autoriza este CAF son <strong>REALES</strong>: cada documento
                timbrado con ellos es un documento tributario real ante el SII, no de prueba.</p>
                <p>Revisa el rango y el proximo folio con el detalle de folios que te entrego tu
                sistema anterior antes de confirmar.</p>
            </div>
        <?php endif; ?>

        <div class="panel-info">
            <p class="panel-info__titulo">
                <span class="panel-info__icono" aria-hidden="true">&#9432;</span>
                Que pasa al confirmar
            </p>
            <ul class="panel-info__lista">
                <li>El CAF se guarda cifrado y queda disponible para emitir.</li>
                <li>El contador de folios arranca en el numero que indiques.</li>
                <li>Si dejas el campo vacio, arranca al inicio del rango.</li>
                <li>Si cancelas o cierras esta pagina, el archivo se descarta y no se guarda nada.</li>
            </ul>
        </div>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
