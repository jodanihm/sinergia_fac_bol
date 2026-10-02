<?php
/**
 * Configuracion > Datos de la empresa (ambiente de CERTIFICACION).
 *
 * TAREA DE LA PANTALLA, EN UNA FRASE: el dueno de la empresa deja cargados los
 * datos con que el SII arma la caratula de sus envios de prueba, tal como el
 * SII los tiene registrados.
 *
 * Recibe: $emisor (array<string,mixed>|null), $errores (array<string,string>),
 * $logo (array|null, puede no venir) y $flash (array|null, puede no venir).
 * handleEmpresaGet() arma $emisor desde dte_emisor; si no hay fila guardada y
 * llegan los parametros de /empresa/importar-datos-sii o /empresa/consultar-sii
 * ("Usar estos datos"), lo arma desde el query string. Tras un POST invalido,
 * $emisor son los datos enviados y $errores el detalle por campo.
 *
 * LOS 7 CAMPOS SON OBLIGATORIOS. Como lo son TODOS, no se marca cada uno con
 * asterisco (formularios.html: el asterisco en todos deja de significar algo):
 * se dice una vez en el subtitulo. La validacion la sigue haciendo
 * handleEmpresaPost(); el JavaScript del final solo la adelanta al salir de
 * cada campo, y por eso el form va con novalidate y sin required: el globo
 * nativo del navegador no dice como arreglar nada.
 *
 * TRES GRUPOS EN COLUMNAS (identificacion, domicilio, resolucion): la pantalla
 * usa todo el ancho repartiendolo, sin estirar campos. Cada grupo es una
 * pregunta distinta al SII y por eso va en su fieldset.
 *
 * LAS AYUDAS SON NORMATIVAS: describen que espera el SII. Ojo con
 * resolucion_fecha: en CERTIFICACION es la fecha de POSTULACION, no la de
 * autorizacion real. Esa distincion no puede intercambiarse con la de
 * empresa-produccion.php.
 *
 * NORMALIZACION AL SALIR: el RUT se deja como 77724622-4 (con o sin puntos al
 * pegarlo) y razon social, direccion y comuna pasan a MAYUSCULAS, que es como
 * las tiene el SII y lo que la ayuda pedia escribir a mano.
 */
$titulo = 'Datos de la empresa';
require __DIR__ . '/partials/header.php';

$e       = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$errores = $errores ?? [];
$val     = static fn (string $campo): string => htmlspecialchars((string) ($emisor[$campo] ?? ''), ENT_QUOTES, 'UTF-8');

/** Mensaje de error bajo un campo: icono + texto, vacio y oculto si no hay. */
$campoError = static function (string $campo) use ($errores, $e): string {
    $msg = $errores[$campo] ?? '';
    return '<p class="campo-error" id="error-' . $e($campo) . '"' . ($msg === '' ? ' hidden' : '') . '>'
        . iconoSvg('alerta', 16, '') . '<span>' . $e($msg) . '</span></p>';
};
/** aria-describedby (ayuda + error) y aria-invalid de un control. */
$aria = static fn (string $campo): string
    => ' aria-describedby="ayuda-' . $campo . ' error-' . $campo . '"' . (isset($errores[$campo]) ? ' aria-invalid="true"' : '');
$claseCampo = static fn (string $campo): string
    => 'form-campo' . (isset($errores[$campo]) ? ' form-campo--error' : '');
/** La ayuda se oculta mientras el campo muestra su error: ayuda O error. */
$ocultaAyuda = static fn (string $campo): string => isset($errores[$campo]) ? ' hidden' : '';

$etiquetas = [
    'rut_emisor'       => 'RUT de la empresa',
    'razon_social'     => 'Razon social',
    'giro'             => 'Giro',
    'acteco'           => 'Codigo de actividad economica',
    'dir_origen'       => 'Direccion',
    'cmna_origen'      => 'Comuna',
    'resolucion_fecha' => 'Fecha de resolucion',
];

// $logo puede no estar definido: handleEmpresaPost() re-renderiza esta vista
// cuando la validacion falla y no pasa esa clave.
$logo = $logo ?? null;
?>

<div class="dash-header">
    <div>
        <h1>Datos de la empresa <span class="badge badge--etiqueta">Certificacion</span></h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <?php /* Primero la consulta por RUT: es la unica que trae el numero y la
                 fecha de Resolucion. El archivo del SII no los trae. */ ?>
        <a class="boton-secundario" href="/empresa/consultar-sii">Consultar al SII</a>
        <a class="boton-secundario" href="/empresa/importar-datos-sii">Importar archivo del SII</a>
        <a class="boton-secundario" href="/panel">Volver al panel</a>
    </div>
</div>
<p class="dash-subtitulo">
    Con estos datos se arma la caratula de tus documentos de prueba ante el SII. Todos son obligatorios.
</p>

<div class="config-ancho">

<?php if (! empty($flash['mensaje'])): ?>
    <?php $tipoFlash = match ($flash['tipo'] ?? '') { 'ok', 'exito' => 'exito', 'error' => 'error', default => 'advertencia' }; ?>
    <p class="alerta alerta--<?= $tipoFlash; ?>" role="<?= $tipoFlash === 'error' ? 'alert' : 'status'; ?>">
        <?= iconoSvg($tipoFlash === 'exito' ? 'check' : 'alerta', 20, 'alerta__icono'); ?>
        <span><?= $e((string) $flash['mensaje']); ?></span>
    </p>
<?php endif; ?>

<?php /* Resumen de errores: siempre en el DOM (oculto si no hay) porque el
         JavaScript reusa este mismo contenedor al validar antes de enviar. */ ?>
<div class="alerta alerta--error" id="resumen-errores" role="alert" tabindex="-1"<?= $errores === [] ? ' hidden' : ''; ?>>
    <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
    <div class="alerta__cuerpo">
        <p class="alerta__titulo" data-resumen-titulo>
            <?= count($errores) === 1 ? 'Hay 1 campo por revisar antes de guardar' : 'Hay ' . count($errores) . ' campos por revisar antes de guardar'; ?>
        </p>
        <ul class="alerta__lista" data-resumen-lista>
            <?php foreach (array_replace(array_intersect_key($etiquetas, $errores), $errores) as $campo => $msg): ?>
                <li><a href="#<?= $e($campo); ?>"><?= $e($etiquetas[$campo] ?? $campo); ?></a>: <?= $e($msg); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>

<form method="post" action="/empresa" class="form-compacto" id="form-empresa" novalidate data-validar-form data-espera>
    <?= csrfInput(); ?>

    <section class="tarjeta" aria-labelledby="titulo-emisor">
        <h2 id="titulo-emisor">Datos del emisor</h2>
        <p class="tarjeta__intro">
            Razon social, direccion y comuna tienen que calzar EXACTAMENTE con el registro del SII.
            La configuracion de produccion se guarda por separado.
        </p>

        <div class="config-columnas config-columnas--3">
            <fieldset class="grupo-campos">
                <legend>Identificacion</legend>
                <div class="form-grid form-grid--1">
                    <div class="<?= $claseCampo('rut_emisor'); ?>">
                        <label for="rut_emisor">RUT de la empresa</label>
                        <input type="text" name="rut_emisor" id="rut_emisor" value="<?= $val('rut_emisor'); ?>"
                               placeholder="77724622-4" inputmode="text" autocomplete="off" spellcheck="false"
                               data-validar="requerido rut" data-normalizar="rut" data-etiqueta="<?= $e($etiquetas['rut_emisor']); ?>"
                               data-msg-requerido="Escribe el RUT de la empresa, por ejemplo 77724622-4."
                               <?= $aria('rut_emisor'); ?>>
                        <?= $campoError('rut_emisor'); ?>
                        <small class="form-ayuda" id="ayuda-rut_emisor"<?= $ocultaAyuda('rut_emisor'); ?>>Con o sin puntos: se ordena solo al salir del campo.</small>
                    </div>

                    <div class="<?= $claseCampo('razon_social'); ?>">
                        <label for="razon_social">Razon social</label>
                        <input type="text" name="razon_social" id="razon_social" value="<?= $val('razon_social'); ?>"
                               autocomplete="organization"
                               data-validar="requerido" data-normalizar="mayusculas" data-etiqueta="<?= $e($etiquetas['razon_social']); ?>"
                               data-msg-requerido="Escribe la razon social tal como la tiene el SII."
                               <?= $aria('razon_social'); ?>>
                        <?= $campoError('razon_social'); ?>
                        <small class="form-ayuda" id="ayuda-razon_social"<?= $ocultaAyuda('razon_social'); ?>>Nombre legal, exactamente como esta registrado en el SII. Pasa a mayusculas solo.</small>
                    </div>

                    <div class="<?= $claseCampo('giro'); ?>">
                        <label for="giro">Giro</label>
                        <input type="text" name="giro" id="giro" value="<?= $val('giro'); ?>"
                               data-validar="requerido" data-etiqueta="<?= $e($etiquetas['giro']); ?>"
                               data-msg-requerido="Escribe el giro comercial registrado en el SII."
                               <?= $aria('giro'); ?>>
                        <?= $campoError('giro'); ?>
                        <small class="form-ayuda" id="ayuda-giro"<?= $ocultaAyuda('giro'); ?>>Giro comercial registrado en el SII.</small>
                    </div>

                    <div class="<?= $claseCampo('acteco'); ?> form-campo--corto">
                        <label for="acteco">Codigo de actividad economica</label>
                        <input type="text" inputmode="numeric" name="acteco" id="acteco" value="<?= $val('acteco'); ?>"
                               placeholder="620100"
                               data-validar="requerido digitos" data-normalizar="digitos" data-etiqueta="<?= $e($etiquetas['acteco']); ?>"
                               data-msg-requerido="Escribe el codigo de actividad, por ejemplo 620100."
                               <?= $aria('acteco'); ?>>
                        <?= $campoError('acteco'); ?>
                        <small class="form-ayuda" id="ayuda-acteco"<?= $ocultaAyuda('acteco'); ?>>Codigo numerico del SII (acteco), solo numeros.</small>
                    </div>
                </div>
            </fieldset>

            <fieldset class="grupo-campos">
                <legend>Domicilio</legend>
                <div class="form-grid form-grid--1">
                    <div class="<?= $claseCampo('dir_origen'); ?>">
                        <label for="dir_origen">Direccion</label>
                        <input type="text" name="dir_origen" id="dir_origen" value="<?= $val('dir_origen'); ?>"
                               autocomplete="street-address"
                               data-validar="requerido" data-normalizar="mayusculas" data-etiqueta="<?= $e($etiquetas['dir_origen']); ?>"
                               data-msg-requerido="Escribe la direccion de origen tal como la tiene el SII."
                               <?= $aria('dir_origen'); ?>>
                        <?= $campoError('dir_origen'); ?>
                        <small class="form-ayuda" id="ayuda-dir_origen"<?= $ocultaAyuda('dir_origen'); ?>>Direccion de origen, exactamente como esta registrada en el SII. Pasa a mayusculas sola.</small>
                    </div>

                    <div class="<?= $claseCampo('cmna_origen'); ?>">
                        <label for="cmna_origen">Comuna</label>
                        <input type="text" name="cmna_origen" id="cmna_origen" value="<?= $val('cmna_origen'); ?>"
                               data-validar="requerido" data-normalizar="mayusculas" data-etiqueta="<?= $e($etiquetas['cmna_origen']); ?>"
                               data-msg-requerido="Escribe la comuna de la direccion de origen."
                               <?= $aria('cmna_origen'); ?>>
                        <?= $campoError('cmna_origen'); ?>
                        <small class="form-ayuda" id="ayuda-cmna_origen"<?= $ocultaAyuda('cmna_origen'); ?>>Comuna de la direccion de origen, como la tiene el SII.</small>
                    </div>
                </div>
            </fieldset>

            <fieldset class="grupo-campos">
                <legend>Resolucion del SII</legend>
                <div class="form-grid form-grid--1">
                    <div class="<?= $claseCampo('resolucion_fecha'); ?> form-campo--corto">
                        <label for="resolucion_fecha">Fecha de resolucion</label>
                        <input type="date" name="resolucion_fecha" id="resolucion_fecha" value="<?= $val('resolucion_fecha'); ?>"
                               data-validar="requerido" data-etiqueta="<?= $e($etiquetas['resolucion_fecha']); ?>"
                               data-msg-requerido="Elige la fecha en que tu empresa postulo a la certificacion."
                               <?= $aria('resolucion_fecha'); ?>>
                        <?= $campoError('resolucion_fecha'); ?>
                        <small class="form-ayuda" id="ayuda-resolucion_fecha"<?= $ocultaAyuda('resolucion_fecha'); ?>>
                            En CERTIFICACION es la FECHA EN QUE TU EMPRESA POSTULO, no una fecha generica ni la de
                            produccion (no uses 2014-08-22 / Res. 80 salvo que sea realmente tu caso).
                        </small>
                    </div>

                    <?php
                    /* EL NUMERO DE RESOLUCION NO SE PIDE ACA, Y NO ES UN OLVIDO.
                       En certificacion SIEMPRE va 0: es una propiedad del ambiente,
                       no de la empresa (ver EnvioDteBuilder::buildCaratula). Pedirlo
                       era una trampa: el campo sugeria 80, el numero de PRODUCCION,
                       y escribirlo aca hizo que el SII devolviera RCT "Rechazado por
                       Error en Caratula". El numero real se pide en
                       /empresa-produccion, que es donde manda. */
                    ?>
                    <div class="panel-info">
                        <p class="panel-info__titulo">
                            <?= iconoSvg('info', 20, 'panel-info__icono'); ?>
                            Numero de resolucion
                        </p>
                        <p>En certificacion el SII espera 0 y el sistema lo pone solo. El numero real de tu
                        empresa (normalmente 80, Res. Ex. N&deg;80 de 2014) se carga al configurar produccion.</p>
                    </div>
                </div>
            </fieldset>
        </div>
    </section>

    <div class="acciones-formulario">
        <a class="boton-texto" href="/panel">Cancelar</a>
        <button type="submit" class="boton-principal">Guardar datos</button>
    </div>
</form>

<div class="config-columnas config-columnas--2">
    <?php
    // EL LOGO VA FUERA DEL <form> DE ARRIBA: HTML no permite formularios
    // anidados, y este necesita enctype multipart. Son dos envios distintos.
    ?>
    <section class="tarjeta" aria-labelledby="titulo-logo">
        <h2 id="titulo-logo">Logo de la empresa</h2>
        <p class="tarjeta__intro">
            Se imprime arriba a la izquierda de tus facturas y notas, al lado de la razon social.
            Es opcional: sin logo el documento sale igual que hasta ahora.
        </p>

        <?php if ($logo !== null): ?>
            <div class="alerta alerta--exito">
                <?= iconoSvg('check', 20, 'alerta__icono'); ?>
                <div class="alerta__cuerpo">
                    <p class="alerta__titulo">Tienes un logo cargado</p>
                    <p>
                        <?= (int) $logo['ancho_px']; ?>&times;<?= (int) $logo['alto_px']; ?> pixeles,
                        <?= number_format((int) $logo['bytes'] / 1024, 0, ',', '.'); ?> KB.
                        Actualizado el <?= $e(date('d/m/Y', strtotime((string) $logo['updated_at']))); ?>.
                    </p>
                </div>
            </div>
        <?php endif; ?>

        <form method="post" action="/empresa/logo" enctype="multipart/form-data" class="form-compacto" id="form-logo"
              novalidate data-validar-form data-espera>
            <?= csrfInput(); ?>
            <div class="form-campo">
                <label for="logo">Archivo PNG</label>
                <?php // Tipo y peso maximo se dicen ANTES de elegir (formularios.html, campo Archivo). ?>
                <input type="file" name="logo" id="logo" accept="image/png"
                       data-validar="requerido" data-etiqueta="Archivo PNG"
                       data-msg-requerido="Elige el archivo PNG del logo."
                       aria-describedby="ayuda-logo error-logo">
                <p class="campo-error" id="error-logo" hidden><?= iconoSvg('alerta', 16, ''); ?><span></span></p>
                <small class="form-ayuda" id="ayuda-logo">
                    Solo PNG, hasta 512 KB. Se dibuja a 30 mm de ancho: unos 400 pixeles alcanzan.
                    <?= $logo !== null ? 'El nuevo reemplaza al actual.' : ''; ?>
                </small>
            </div>
            <div class="acciones-formulario">
                <?php if ($logo !== null): ?>
                    <?php
                    /* QUITAR VA A LA IZQUIERDA, SEPARADO Y SIN CONFIRMACION.
                       Separado de "Reemplazar" para que un clic apurado no caiga
                       en el; sin dialogo porque se deshace volviendo a subir el
                       archivo (feedback.html: se confirma solo lo irreversible).
                       Pertenece a #form-quitar-logo por el atributo form: HTML
                       no permite anidar formularios. */
                    ?>
                    <button type="submit" form="form-quitar-logo" class="boton-texto acciones-formulario__aparte">Quitar el logo</button>
                <?php endif; ?>
                <button type="submit" class="boton-secundario"><?= $logo !== null ? 'Reemplazar logo' : 'Subir logo'; ?></button>
            </div>
        </form>
        <?php if ($logo !== null): ?>
            <form method="post" action="/empresa/logo/quitar" id="form-quitar-logo" data-espera>
                <?= csrfInput(); ?>
            </form>
        <?php endif; ?>
    </section>

    <section class="tarjeta" aria-labelledby="titulo-ayuda">
        <h2 id="titulo-ayuda">Si el SII rechaza la caratula</h2>
        <p class="tarjeta__intro">Cuando el SII responde "Rechazado por Error en Caratula", casi siempre es esto:</p>
        <ul class="lista-ayuda">
            <li>Razon social, direccion y comuna deben calzar <strong>exactamente</strong> con los datos del SII:
                en mayusculas y sin abreviar ni truncar por cuenta propia.</li>
            <li>Descarga los datos oficiales desde
                <a href="https://maullin.sii.cl/cvc_cgi/dte/pe_construccion_dte" target="_blank" rel="noopener noreferrer">Datos para Construccion DTE (se abre en otra pestana)</a>
                y copialos tal cual, o subelos con <a href="/empresa/importar-datos-sii">Importar archivo del SII</a>.</li>
            <li>La fecha de resolucion en certificacion es la de postulacion, y el numero va en 0.</li>
        </ul>
    </section>
</div>

</div>

<script>
/* FORMULARIOS DE CONFIGURACION: validacion al salir, normalizacion de datos
   chilenos y espera al enviar. Es el mismo patron de pagos-config.php,
   escrito por atributos para que las vistas de empresa lo compartan textual:
     - data-validar="requerido rut digitos" en el control (reglas separadas
       por espacio), data-msg-requerido con el texto del caso vacio y
       data-etiqueta con el nombre que va al resumen de errores.
     - data-normalizar="rut|mayusculas|digitos": se arregla al salir del
       campo lo que el sistema puede arreglar solo (formularios.html).
     - form[data-espera]: patron de la seccion 14 (Efectos de carga).
     - form[data-espera-larga="texto"]: si la espera pasa de 10 s, se dice
       que se esta haciendo, en el [data-aviso-espera] del formulario.
   La validacion del servidor manda; esto solo la adelanta. */
(function () {
    function rutNormalizado(v) {
        var limpio = v.replace(/[^0-9kK]/g, '').toUpperCase();
        if (limpio.length < 2) { return v.trim(); }
        return limpio.slice(0, -1) + '-' + limpio.slice(-1);
    }
    function rutValido(v) {
        var m = /^(\d{1,8})-([\dK])$/.exec(v);
        if (!m) { return false; }
        var suma = 0, mult = 2;
        for (var i = m[1].length - 1; i >= 0; i--) {
            suma += parseInt(m[1].charAt(i), 10) * mult;
            mult = mult === 7 ? 2 : mult + 1;
        }
        var dv = 11 - (suma % 11);
        return m[2] === (dv === 11 ? '0' : dv === 10 ? 'K' : String(dv));
    }
    var normalizar = {
        rut: rutNormalizado,
        mayusculas: function (v) { return v.toLocaleUpperCase('es-CL'); },
        digitos: function (v) { return v.replace(/\D/g, ''); }
    };
    function mensaje(el) {
        var v = el.value.trim();
        var reglas = (el.getAttribute('data-validar') || '').split(' ');
        if (reglas.indexOf('requerido') !== -1 && v === '') {
            return el.getAttribute('data-msg-requerido') || 'Completa este dato.';
        }
        if (v === '') { return ''; }
        if (reglas.indexOf('rut') !== -1 && !rutValido(v)) {
            return 'El digito verificador no calza con el RUT. Revisalo, por ejemplo 77724622-4.';
        }
        if (reglas.indexOf('digitos') !== -1 && !/^\d+$/.test(v)) {
            return 'Escribe solo numeros, por ejemplo ' + (el.getAttribute('placeholder') || '620100') + '.';
        }
        return '';
    }
    function pintar(el, msg) {
        var err = document.getElementById('error-' + el.id);
        if (!err) { return; }
        err.querySelector('span').textContent = msg;
        err.hidden = msg === '';
        var ayuda = document.getElementById('ayuda-' + el.id);
        if (ayuda) { ayuda.hidden = msg !== ''; }
        var campo = el.closest('.form-campo');
        if (campo) { campo.classList.toggle('form-campo--error', msg !== ''); }
        if (msg === '') { el.removeAttribute('aria-invalid'); } else { el.setAttribute('aria-invalid', 'true'); }
    }

    document.querySelectorAll('form[data-validar-form]').forEach(function (form) {
        var controles = Array.prototype.slice.call(form.querySelectorAll('[data-validar]'));
        var tocados = {};
        controles.forEach(function (el) {
            el.addEventListener('blur', function () {
                var n = el.getAttribute('data-normalizar');
                if (n && normalizar[n] && el.value.trim() !== '') { el.value = normalizar[n](el.value.trim()); }
                // Primer aviso al SALIR, y nunca por un campo que solo se recorrio con Tab.
                if (el.value.trim() === '' && !tocados[el.id]) { return; }
                tocados[el.id] = true;
                pintar(el, mensaje(el));
            });
            el.addEventListener('input', function () {
                if (tocados[el.id]) { pintar(el, mensaje(el)); }
            });
        });

        form.addEventListener('submit', function (ev) {
            if (form.getAttribute('data-enviando') === '1') { return; }
            var malos = [];
            controles.forEach(function (el) {
                var n = el.getAttribute('data-normalizar');
                if (n && normalizar[n] && el.value.trim() !== '') { el.value = normalizar[n](el.value.trim()); }
                tocados[el.id] = true;
                var msg = mensaje(el);
                pintar(el, msg);
                if (msg !== '') { malos.push([el, msg]); }
            });
            if (malos.length === 0) { return; }
            ev.preventDefault();
            ev.stopImmediatePropagation();
            var resumen = document.getElementById('resumen-errores');
            if (resumen) {
                var lista = resumen.querySelector('[data-resumen-lista]');
                lista.textContent = '';
                malos.forEach(function (m) {
                    var li = document.createElement('li');
                    var a = document.createElement('a');
                    a.href = '#' + m[0].id;
                    a.textContent = m[0].getAttribute('data-etiqueta') || m[0].id;
                    li.appendChild(a);
                    li.appendChild(document.createTextNode(': ' + m[1]));
                    lista.appendChild(li);
                });
                resumen.querySelector('[data-resumen-titulo]').textContent = malos.length === 1
                    ? 'Hay 1 campo por revisar antes de guardar'
                    : 'Hay ' + malos.length + ' campos por revisar antes de guardar';
                resumen.hidden = false;
            }
            malos[0][0].focus();
        });
    });

    /* ESPERA AL ENVIAR -- seccion 14 del design system, Efectos de carga.
       El doble envio se corta en el acto pero sin nada visible; pasados
       300 ms el boton entra en carga (spinner dentro, mismo ancho, aria-busy
       en boton y formulario); pasados 10 s se dice que se esta haciendo. La
       permanencia minima de 400 ms la da la navegacion. El resultado lo
       anuncia la pantalla de llegada. El foco no se mueve durante la espera. */
    document.querySelectorAll('form[data-espera]').forEach(function (form) {
        // form.elements y no querySelector: incluye el boton asociado con el
        // atributo form="" aunque viva fuera, y excluye el de otro formulario
        // que viva dentro (el "Quitar el logo" de empresa.php).
        var boton = Array.prototype.filter.call(form.elements, function (el) { return el.type === 'submit'; })[0] || null;
        var aviso = form.querySelector('[data-aviso-espera]');
        var t300 = null, t10s = null;
        function ocupado(si) {
            if (!boton) { return; }
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
                if (aviso) { aviso.hidden = true; aviso.textContent = ''; }
            }
        }
        form.addEventListener('submit', function (ev) {
            if (ev.defaultPrevented) { return; }
            if (form.getAttribute('data-enviando') === '1') { ev.preventDefault(); return; }
            form.setAttribute('data-enviando', '1');
            t300 = setTimeout(function () { ocupado(true); }, 300);
            if (aviso && form.getAttribute('data-espera-larga')) {
                t10s = setTimeout(function () {
                    aviso.textContent = form.getAttribute('data-espera-larga');
                    aviso.hidden = false;
                }, 10000);
            }
        });
        /* Volver con "Atras" restaura la pagina tal como quedo: con el boton
           girando para siempre si no se limpia aqui. */
        window.addEventListener('pageshow', function (ev) {
            if (!ev.persisted) { return; }
            clearTimeout(t300); clearTimeout(t10s);
            form.removeAttribute('data-enviando');
            ocupado(false);
        });
    });

    /* Tras un POST con errores, el foco va directo al primer campo con problema. */
    var primero = document.querySelector('form [aria-invalid="true"]');
    if (primero) { primero.focus(); }
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
