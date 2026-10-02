<?php
/**
 * Configuracion > Certificado digital (ambiente de CERTIFICACION).
 *
 * TAREA DE LA PANTALLA, EN UNA FRASE: subir el .pfx de la empresa y su clave
 * para que se firmen los documentos de prueba que van al SII.
 *
 * Recibe: $error (string|null). La arma procesarCertificadoGet() como null, y
 * procesarCertificadoPost() con el mensaje exacto de cada fallo. $flash puede
 * llegar si el controlador lo pasa; si no, no se pinta nada.
 *
 * LO QUE ESTA PANTALLA NO SABE, y por eso no muestra: si ya hay un certificado
 * cargado, su titular, su vigencia o su estado. La vista no recibe ese dato, asi
 * que no se afirma nada al respecto -- ni "vigente", ni "instalado", ni fechas.
 *
 * SEGURIDAD: la clave va en type="password", sin value y sin re-render tras un
 * error. No se agrega autocomplete (hoy no existe y ponerlo cambiaria el
 * comportamiento del navegador). Pegarla si se puede (WCAG 3.3.8). El archivo
 * se cifra antes de guardarse y la clave no se persiste.
 *
 * EL ERROR LLEGA COMO UN SOLO TEXTO, sin decir de que campo es. Se asigna al
 * campo por el texto conocido de cada mensaje de procesarCertificadoPost(); si
 * llega uno nuevo que no se reconoce, queda solo en el resumen de arriba, que
 * es lo que se veia antes. Ver $campoDelError.
 *
 * ANCHO: la pantalla usa todo el ancho repartido en dos columnas -- la carga a
 * la izquierda, lo que hay que saber a la derecha --, nunca estirando campos.
 *
 * ESPERA (seccion 14 del design system): spinner dentro del boton pasados
 * 300 ms, aria-busy, y pageshow para volver con Atras sin el boton girando.
 */
$titulo = 'Certificado digital';
require __DIR__ . '/partials/header.php';

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

// De que campo es el error, por su texto. Solo los mensajes que ya existen.
$campoDelError = static function (?string $error): ?string {
    if ($error === null || $error === '') {
        return null;
    }
    if (str_contains($error, 'clave del certificado es obligatoria') || str_contains($error, 'No pudimos abrir el certificado')) {
        return 'clave';
    }
    if (str_contains($error, 'archivo')) {
        return 'certificado';
    }
    return null;
};
$campoError = $campoDelError($error ?? null);
$errorDe = static fn (string $campo): string => $campoError === $campo ? (string) $error : '';
?>

<div class="dash-header">
    <div>
        <h1>Certificado digital <span class="badge badge--etiqueta">Certificacion</span></h1>
    </div>
</div>
<p class="dash-subtitulo">
    Sube el certificado con el que se firman los documentos de prueba que envias al SII.
</p>

<div class="config-ancho">

<?php if (! empty($flash['mensaje'])): ?>
    <p class="alerta alerta--<?= ($flash['tipo'] ?? '') === 'exito' ? 'exito' : 'error'; ?>" role="status">
        <?= iconoSvg(($flash['tipo'] ?? '') === 'exito' ? 'check' : 'alerta', 20, 'alerta__icono'); ?>
        <span><?= $e((string) $flash['mensaje']); ?></span>
    </p>
<?php endif; ?>

<?php
/* RESUMEN DE ERRORES: siempre en el DOM (oculto si no hay) porque el
   JavaScript reusa el mismo contenedor al validar antes de enviar. */
?>
<div class="alerta alerta--error" id="resumen-errores" role="alert" tabindex="-1"<?= empty($error) ? ' hidden' : ''; ?>>
    <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
    <div class="alerta__cuerpo">
        <p class="alerta__titulo" data-resumen-titulo>No se pudo subir el certificado</p>
        <ul class="alerta__lista" data-resumen-lista>
            <?php if (! empty($error)): ?>
                <li>
                    <?php if ($campoError !== null): ?>
                        <a href="#<?= $e($campoError); ?>"><?= $campoError === 'clave' ? 'Clave del certificado' : 'Archivo del certificado'; ?></a>:
                    <?php endif; ?>
                    <?= $e((string) $error); ?>
                </li>
            <?php endif; ?>
        </ul>
    </div>
</div>

<form method="post" action="/certificado" enctype="multipart/form-data" class="form-compacto" id="form-certificado" novalidate>
    <?= csrfInput(); ?>

    <div class="config-columnas config-columnas--2">
        <section class="tarjeta" aria-labelledby="titulo-carga">
            <h2 id="titulo-carga">Cargar certificado</h2>
            <p class="tarjeta__intro">Los dos datos son obligatorios.</p>

            <div class="form-grid form-grid--1">

            <div class="form-campo<?= $campoError === 'certificado' ? ' form-campo--error' : ''; ?>">
                <label for="certificado">Archivo del certificado</label>
                <div class="upload">
                    <div class="upload__area" data-zona-archivo="certificado">
                        <?= iconoSvg('carga-masiva', 32, 'upload__icono'); ?>
                        <p class="upload__texto">Arrastra aqui tu archivo .pfx o .p12, o eligelo desde tu equipo</p>
                        <input class="visualmente-oculto" type="file" name="certificado" id="certificado" accept=".pfx,.p12" required
                               aria-describedby="ayuda-certificado error-certificado"
                               <?= $campoError === 'certificado' ? 'aria-invalid="true"' : ''; ?>
                               data-extensiones=".pfx,.p12" data-max-bytes="33554432">
                        <label class="boton-secundario upload__boton" for="certificado">Elegir archivo</label>
                        <p class="upload__meta" id="ayuda-certificado">Solo .pfx o .p12. Peso maximo 32 MB.</p>
                        <p class="upload__seleccionado" id="certificado-seleccionado" aria-live="polite"></p>
                    </div>
                </div>
                <p class="campo-error" id="error-certificado"<?= $errorDe('certificado') === '' ? ' hidden' : ''; ?>>
                    <?= iconoSvg('alerta', 16, ''); ?><span><?= $e($errorDe('certificado')); ?></span>
                </p>
            </div>

            <div class="form-campo form-campo--corto<?= $campoError === 'clave' ? ' form-campo--error' : ''; ?>">
                <label for="clave">Clave del certificado</label>
                <input type="password" name="clave" id="clave" required
                       aria-describedby="ayuda-clave error-clave"<?= $campoError === 'clave' ? ' aria-invalid="true"' : ''; ?>>
                <p class="campo-error" id="error-clave"<?= $errorDe('clave') === '' ? ' hidden' : ''; ?>>
                    <?= iconoSvg('alerta', 16, ''); ?><span><?= $e($errorDe('clave')); ?></span>
                </p>
                <small class="form-ayuda" id="ayuda-clave"<?= $errorDe('clave') !== '' ? ' hidden' : ''; ?>>Se usa solo para abrir el archivo. No se guarda.</small>
            </div>
            </div>
        </section>

        <div class="panel-info">
            <p class="panel-info__titulo">
                <?= iconoSvg('info', 20, 'panel-info__icono'); ?>
                Ambiente de certificacion
            </p>
            <ul class="panel-info__lista">
                <li>Firma los documentos de prueba que envias al SII mientras te certificas.</li>
                <li>El archivo se cifra antes de guardarse.</li>
                <li>La clave nunca se guarda.</li>
                <li>Se guarda por separado del certificado de produccion.</li>
            </ul>
        </div>
    </div>

    <div class="acciones-formulario">
        <a class="boton-texto" href="/panel">Cancelar</a>
        <button type="submit" class="boton-principal" id="boton-enviar">Subir certificado</button>
    </div>
</form>

</div>

<script>
/* Zona de arrastre, validacion antes de subir y espera del envio.
   Las reglas son las del servidor (procesarCertificadoPost); el servidor manda. */
(function () {
    var form = document.getElementById('form-certificado');
    if (!form) { return; }
    var boton = document.getElementById('boton-enviar');
    var resumen = document.getElementById('resumen-errores');
    var etiquetas = { certificado: 'Archivo del certificado', clave: 'Clave del certificado' };

    function pintar(campo, msg) {
        var el = document.getElementById(campo);
        var err = document.getElementById('error-' + campo);
        err.querySelector('span').textContent = msg;
        err.hidden = msg === '';
        el.closest('.form-campo').classList.toggle('form-campo--error', msg !== '');
        if (msg === '') { el.removeAttribute('aria-invalid'); } else { el.setAttribute('aria-invalid', 'true'); }
        var ayuda = document.getElementById('ayuda-' + campo);
        if (ayuda && campo !== 'certificado') { ayuda.hidden = msg !== ''; }
    }

    /* Tipo y peso se revisan ANTES de subir: decir "maximo 32 MB" despues de
       mandar el archivo por una conexion movil es maltrato. */
    function reglaArchivo() {
        var input = document.getElementById('certificado');
        var f = input.files && input.files[0];
        if (!f) { return 'Elige el archivo .pfx o .p12 del certificado.'; }
        var ok = input.getAttribute('data-extensiones').split(',').some(function (x) {
            return f.name.toLowerCase().slice(-x.length) === x;
        });
        if (!ok) { return 'Ese archivo no es .pfx ni .p12. Elige el certificado que te entrego tu proveedor.'; }
        if (f.size > Number(input.getAttribute('data-max-bytes'))) { return 'El archivo pesa mas de 32 MB. Un certificado pesa unos pocos KB: revisa que sea el correcto.'; }
        return '';
    }
    function reglaClave() {
        return document.getElementById('clave').value === '' ? 'Escribe la clave del certificado.' : '';
    }
    var reglas = { certificado: reglaArchivo, clave: reglaClave };

    /* Zona de arrastre + boton. */
    var input = document.getElementById('certificado');
    var zona = form.querySelector('[data-zona-archivo]');
    var seleccionado = document.getElementById('certificado-seleccionado');
    function alElegir() {
        var f = input.files && input.files[0];
        seleccionado.textContent = f ? 'Elegido: ' + f.name : '';
        pintar('certificado', f ? reglaArchivo() : '');
    }
    input.addEventListener('change', alElegir);
    ['dragenter', 'dragover'].forEach(function (t) {
        zona.addEventListener(t, function (ev) { ev.preventDefault(); zona.setAttribute('data-arrastrando', 'true'); });
    });
    ['dragleave', 'drop'].forEach(function (t) {
        zona.addEventListener(t, function () { zona.removeAttribute('data-arrastrando'); });
    });
    zona.addEventListener('drop', function (ev) {
        ev.preventDefault();
        if (ev.dataTransfer && ev.dataTransfer.files.length) {
            input.files = ev.dataTransfer.files;
            alElegir();
        }
    });

    /* La clave se valida al salir del campo, y desde ahi mientras se corrige. */
    var claveTocada = false;
    var clave = document.getElementById('clave');
    clave.addEventListener('blur', function () { claveTocada = true; pintar('clave', reglaClave()); });
    clave.addEventListener('input', function () { if (claveTocada) { pintar('clave', reglaClave()); } });

    /* ESPERA AL ENVIAR -- seccion 14: nada visible bajo 300 ms; despues,
       spinner dentro del boton sin cambiar su ancho, y aria-busy. */
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
        var malos = [];
        ['certificado', 'clave'].forEach(function (campo) {
            var msg = reglas[campo]();
            pintar(campo, msg);
            if (msg !== '') { malos.push([campo, msg]); }
        });
        if (malos.length > 0) {
            ev.preventDefault();
            var lista = resumen.querySelector('[data-resumen-lista]');
            lista.textContent = '';
            malos.forEach(function (m) {
                var li = document.createElement('li');
                var a = document.createElement('a');
                a.href = '#' + m[0];
                a.textContent = etiquetas[m[0]];
                li.appendChild(a);
                li.appendChild(document.createTextNode(': ' + m[1]));
                lista.appendChild(li);
            });
            resumen.querySelector('[data-resumen-titulo]').textContent = malos.length === 1
                ? 'Hay 1 dato por revisar antes de subir'
                : 'Hay ' + malos.length + ' datos por revisar antes de subir';
            resumen.hidden = false;
            document.getElementById(malos[0][0]).focus();
            return;
        }
        enviando = true;
        umbral = setTimeout(function () { ocupado(true); }, 300);
    });

    /* Tras un POST con error, el foco va al campo con problema (o al resumen). */
    var primero = form.querySelector('[aria-invalid="true"]');
    if (primero) { primero.focus(); } else if (!resumen.hidden) { resumen.focus(); }
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
