<?php
/**
 * Paso 2 de la carga de CAF: revisar antes de guardar.
 *
 * TAREA DE LA PANTALLA, EN UNA FRASE: confirmar que el rango del CAF es el
 * correcto y declarar desde que folio va a emitir Sinergia.
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
 * privada del CAF. Esta pantalla solo muestra los datos ya parseados (tipo y
 * rango) y pide uno nuevo. No hay campo oculto con el XML, ni con la DEK, ni
 * con la huella.
 *
 * POR QUE EXISTE ESTA PANTALLA: cargar un CAF fija el folio desde el que se
 * van a emitir documentos tributarios reales, y ese dato no se puede corregir
 * despues sin apoyo. ES la confirmacion de algo irreversible, por eso el boton
 * nombra la accion y la consecuencia esta escrita junto al campo.
 *
 * El unico error posible es del campo de folio: se pinta bajo el campo y en el
 * resumen de arriba, y el foco va al campo. El JavaScript adelanta las mismas
 * dos reglas de confirmarCafPost() al salir del campo; sin JavaScript la
 * pantalla funciona igual.
 *
 * ESPERA (seccion 14): spinner dentro del boton pasados 300 ms y aria-busy.
 */
$esProduccion = $ambiente === 'produccion';
$titulo       = $esProduccion ? 'Revisar CAF de produccion' : 'Revisar CAF';
$rutaConfirmar = $esProduccion ? '/caf-produccion/confirmar' : '/caf/confirmar';
$rutaCancelar  = $esProduccion ? '/caf-produccion' : '/caf';

require __DIR__ . '/partials/header.php';

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$fmtNum = static function ($v): string {
    return number_format((float) $v, 0, ',', '.');
};
$totalFolios = $hasta - $desde + 1;
$hayError    = ! empty($error);
?>

<div class="dash-header">
    <div>
        <h1>
            Revisar el CAF
            <?php if ($esProduccion): ?>
                <span class="badge badge--advertencia">Produccion</span>
            <?php else: ?>
                <span class="badge badge--etiqueta">Certificacion</span>
            <?php endif; ?>
        </h1>
    </div>
</div>
<p class="dash-subtitulo">
    El archivo todavia <strong>no se ha guardado</strong>. Revisa el rango y desde que folio emite Sinergia.
</p>

<div class="config-ancho">

<?php if ($esProduccion): ?>
    <div class="alerta alerta--advertencia">
        <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
        <div class="alerta__cuerpo">
            <p class="alerta__titulo">Folios reales</p>
            <p>Cada documento timbrado con este CAF es un documento tributario real ante el SII, no de prueba.
            Revisa el rango y el proximo folio con el detalle de folios que te entrego tu sistema anterior.</p>
        </div>
    </div>
<?php endif; ?>

<div class="alerta alerta--error" id="resumen-errores" role="alert" tabindex="-1"<?= $hayError ? '' : ' hidden'; ?>>
    <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
    <div class="alerta__cuerpo">
        <p class="alerta__titulo">Revisa el proximo folio antes de guardar</p>
        <ul class="alerta__lista">
            <li><a href="#proximo-folio">Proximo folio disponible</a>: <span data-resumen-texto><?= $e((string) ($error ?? '')); ?></span></li>
        </ul>
    </div>
</div>

<div class="config-columnas config-columnas--2">
    <div>
        <section class="tarjeta" aria-labelledby="titulo-archivo">
            <h2 id="titulo-archivo">Lo que dice el archivo del SII</h2>
            <p class="tarjeta__intro">Estos datos salen del CAF que subiste y no se pueden cambiar aqui.</p>
            <dl class="ficha">
                <dt>Tipo de documento</dt>
                <dd><?= $e(nombreTipoDte((int) $tipo)); ?></dd>

                <dt>Rango autorizado</dt>
                <dd><?= (int) $desde; ?> a <?= (int) $hasta; ?></dd>

                <dt>Total de folios del rango</dt>
                <dd><?= $fmtNum($totalFolios); ?></dd>
            </dl>
        </section>

        <div class="panel-info">
            <p class="panel-info__titulo">
                <?= iconoSvg('info', 20, 'panel-info__icono'); ?>
                Que pasa al guardar
            </p>
            <ul class="panel-info__lista">
                <li>El CAF se guarda cifrado y queda disponible para emitir.</li>
                <li>El contador de folios arranca en el numero que indiques.</li>
                <li>Si dejas el campo vacio, arranca al inicio del rango.</li>
                <li>Si cancelas o cierras esta pagina, el archivo se descarta y no se guarda nada.</li>
            </ul>
        </div>
    </div>

    <section class="tarjeta" aria-labelledby="titulo-desde">
        <h2 id="titulo-desde">Desde que folio va a emitir Sinergia</h2>

        <form method="post" action="<?= $e($rutaConfirmar); ?>" class="form-compacto" id="form-revision" novalidate
              data-desde="<?= (int) $desde; ?>" data-hasta="<?= (int) $hasta; ?>">
            <?= csrfInput(); ?>

            <div class="form-campo form-campo--corto<?= $hayError ? ' form-campo--error' : ''; ?>">
                <label for="proximo-folio">Proximo folio disponible (opcional)</label>
                <input type="text" inputmode="numeric" name="proximo_folio" id="proximo-folio"
                       value="<?= $e((string) $declarado); ?>"
                       placeholder="<?= (int) $desde; ?>"
                       aria-describedby="ayuda-proximo-folio error-proximo-folio"<?= $hayError ? ' aria-invalid="true"' : ''; ?>>
            </div>
            <p class="campo-error" id="error-proximo-folio"<?= $hayError ? '' : ' hidden'; ?>>
                <?= iconoSvg('alerta', 16, ''); ?><span><?= $e((string) ($error ?? '')); ?></span>
            </p>

            <ul class="lista-ayuda" id="ayuda-proximo-folio">
                <li><strong>Si este CAF es nuevo</strong> y no has emitido ningun documento de este rango, dejalo vacio:
                    Sinergia parte desde el <?= (int) $desde; ?>.</li>
                <li><strong>Si vienes de otro sistema</strong> y ya usaste parte del rango, escribe el primer folio que
                    <em>aun no has emitido</em>. Los anteriores quedan fuera: Sinergia no los usa ni los cuenta como tuyos.</li>
            </ul>

            <div class="alerta alerta--advertencia">
                <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
                <div class="alerta__cuerpo">
                    <p class="alerta__titulo">Este dato no se puede corregir despues sin apoyo</p>
                    <p>Un numero <strong>menor</strong> al real repite folios que ya emitiste ante el SII; uno
                    <strong>mayor</strong> los salta. Los dos son problemas tributarios reales.</p>
                </div>
            </div>

            <div class="acciones-formulario">
                <a class="boton-texto" href="<?= $e($rutaCancelar); ?>">Cancelar</a>
                <button type="submit" class="boton-principal" id="boton-enviar">Guardar este CAF</button>
            </div>
        </form>
    </section>
</div>

</div>

<script>
/* Mismas dos reglas que confirmarCafPost(), adelantadas al salir del campo, y
   espera del envio (seccion 14). El servidor sigue decidiendo. */
(function () {
    var form = document.getElementById('form-revision');
    if (!form) { return; }
    var input = document.getElementById('proximo-folio');
    var resumen = document.getElementById('resumen-errores');
    var boton = document.getElementById('boton-enviar');
    var desde = Number(form.getAttribute('data-desde'));
    var hasta = Number(form.getAttribute('data-hasta'));
    var tocado = false;

    function regla() {
        var v = input.value.trim();
        if (v === '') { return ''; }
        if (!/^[0-9]+$/.test(v)) { return 'Escribe solo el numero, sin puntos ni espacios. Por ejemplo: ' + desde + '.'; }
        var n = Number(v);
        if (n < desde || n > hasta) { return 'Escribe un folio entre ' + desde + ' y ' + hasta + ', o dejalo vacio para partir en ' + desde + '.'; }
        return '';
    }
    function pintar(msg) {
        var err = document.getElementById('error-proximo-folio');
        err.querySelector('span').textContent = msg;
        err.hidden = msg === '';
        input.closest('.form-campo').classList.toggle('form-campo--error', msg !== '');
        if (msg === '') { input.removeAttribute('aria-invalid'); } else { input.setAttribute('aria-invalid', 'true'); }
    }
    input.addEventListener('blur', function () { tocado = true; pintar(regla()); });
    input.addEventListener('input', function () { if (tocado) { pintar(regla()); } });

    var enviando = false;
    var umbral = null;
    function ocupado(si) {
        if (si) {
            boton.setAttribute('data-cargando', 'true');
            boton.setAttribute('aria-busy', 'true');
            form.setAttribute('aria-busy', 'true');
            boton.disabled = true;
        } else {
            boton.removeAttribute('data-cargando');
            boton.removeAttribute('aria-busy');
            form.removeAttribute('aria-busy');
            boton.disabled = false;
        }
    }
    window.addEventListener('pageshow', function (ev) {
        if (ev.persisted) { clearTimeout(umbral); enviando = false; ocupado(false); }
    });
    form.addEventListener('submit', function (ev) {
        if (enviando) { ev.preventDefault(); return; }
        var msg = regla();
        pintar(msg);
        if (msg !== '') {
            ev.preventDefault();
            resumen.querySelector('[data-resumen-texto]').textContent = msg;
            resumen.hidden = false;
            input.focus();
            return;
        }
        enviando = true;
        umbral = setTimeout(function () { ocupado(true); }, 300);
    });

    if (input.getAttribute('aria-invalid') === 'true') { input.focus(); }
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
