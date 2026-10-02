<?php
/**
 * Configuracion > Produccion > Datos de la empresa (ambiente de PRODUCCION).
 *
 * TAREA DE LA PANTALLA, EN UNA FRASE: el dueno de la empresa registra los datos
 * con que se emiten sus documentos REALES, con la Resolucion que el SII le
 * entrego al autorizarlo.
 *
 * Recibe: $emisor, $errores, $yaConfigurado (bool), $rutEditable (bool),
 * $produccion (array, solo cuando $yaConfigurado es true) y $flash (puede no
 * venir).
 *
 * DOS MODOS, tal como antes:
 *   - $yaConfigurado === true : ficha de solo lectura con los 8 datos guardados.
 *     El flujo actual NO permite editarlos desde aqui, asi que no se ofrece un
 *     boton de editar que no existe.
 *   - $yaConfigurado === false: formulario. $rutEditable decide si son 7 u 8
 *     campos, y es lo unico que cambia entre los dos caminos de alta:
 *
 *       $rutEditable === false (hay fila de certificacion, el caso de siempre):
 *         el RUT NO es un input. Se hereda de esa fila y el handler lo toma de
 *         ahi, no del POST. Se muestra como dato de lectura, no como input
 *         disabled: un disabled sugeriria que es un campo del formulario.
 *
 *       $rutEditable === true (empresa ya autorizada por el SII, sin fila de
 *         certificacion): el RUT SI es un input, porque no hay otra fuente de
 *         donde sacarlo. El handler lo valida con Rut::normalizar() mas
 *         Rut::valido(), igual que /empresa.
 *
 * DIFERENCIA NORMATIVA CON CERTIFICACION: aqui la fecha y el numero de
 * resolucion son los de la autorizacion REAL que entrego el SII, no los de la
 * postulacion. Los textos de ayuda no son intercambiables con los de
 * empresa.php.
 *
 * MISMA ESTRUCTURA QUE empresa.php (tres grupos en columnas, validacion al
 * salir, espera de la seccion 14 al guardar), para que las dos pantallas se
 * lean como hermanas. Lo que las distingue es el ambiente, dicho arriba con
 * badge, icono, texto y color.
 */
$titulo = 'Datos de la empresa (PRODUCCION)';
require __DIR__ . '/partials/header.php';

$e       = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$errores = $errores ?? [];
$val     = static fn (string $campo): string => htmlspecialchars((string) ($emisor[$campo] ?? ''), ENT_QUOTES, 'UTF-8');

$campoError = static function (string $campo) use ($errores, $e): string {
    $msg = $errores[$campo] ?? '';
    return '<p class="campo-error" id="error-' . $e($campo) . '"' . ($msg === '' ? ' hidden' : '') . '>'
        . iconoSvg('alerta', 16, '') . '<span>' . $e($msg) . '</span></p>';
};
$aria = static fn (string $campo): string
    => ' aria-describedby="ayuda-' . $campo . ' error-' . $campo . '"' . (isset($errores[$campo]) ? ' aria-invalid="true"' : '');
$claseCampo = static fn (string $campo): string
    => 'form-campo' . (isset($errores[$campo]) ? ' form-campo--error' : '');
$ocultaAyuda = static fn (string $campo): string => isset($errores[$campo]) ? ' hidden' : '';

$etiquetas = [
    'rut_emisor'        => 'RUT de la empresa',
    'razon_social'      => 'Razon social',
    'giro'              => 'Giro',
    'acteco'            => 'Codigo de actividad economica',
    'dir_origen'        => 'Direccion',
    'cmna_origen'       => 'Comuna',
    'resolucion_fecha'  => 'Fecha de la resolucion',
    'resolucion_numero' => 'Numero de la resolucion',
];
?>

<div class="dash-header">
    <div>
        <h1>Datos de la empresa <span class="badge badge--advertencia"><?= iconoSvg('alerta', 16, 'badge__icono'); ?>Produccion</span></h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <a class="boton-secundario" href="/panel">Volver al panel</a>
    </div>
</div>
<p class="dash-subtitulo">
    <?= $yaConfigurado
        ? 'Los datos con que se emiten tus documentos tributarios reales ante el SII.'
        : 'Con estos datos se emiten tus documentos reales ante el SII. Todos son obligatorios.'; ?>
</p>

<div class="config-ancho">

<?php if (! empty($flash['mensaje'])): ?>
    <?php $tipoFlash = match ($flash['tipo'] ?? '') { 'ok', 'exito' => 'exito', 'error' => 'error', default => 'advertencia' }; ?>
    <p class="alerta alerta--<?= $tipoFlash; ?>" role="<?= $tipoFlash === 'error' ? 'alert' : 'status'; ?>">
        <?= iconoSvg($tipoFlash === 'exito' ? 'check' : 'alerta', 20, 'alerta__icono'); ?>
        <span><?= $e((string) $flash['mensaje']); ?></span>
    </p>
<?php endif; ?>

<?php /* EL AMBIENTE, ARRIBA Y A TODO EL ANCHO: es lo que distingue esta pantalla
         de su hermana de certificacion, y en los dos modos dice lo mismo. */ ?>
<div class="alerta alerta--advertencia">
    <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
    <div class="alerta__cuerpo">
        <p class="alerta__titulo">Ambiente de produccion (palena.sii.cl)</p>
        <p>La Resolucion que registres aqui es la autorizacion <strong>real</strong> que el SII te entrego
        por correo o portal al certificarte: no la inventes ni copies la de certificacion.</p>
    </div>
</div>

<?php if ($yaConfigurado): ?>

    <section class="tarjeta" aria-labelledby="titulo-produccion">
        <h2 id="titulo-produccion">
            Datos del emisor
            <span class="badge badge--ok"><?= iconoSvg('check', 16, 'badge__icono'); ?>Configurado</span>
        </h2>
        <?php // Tres fichas en columnas, en el mismo agrupamiento que el formulario de alta. ?>
        <div class="config-columnas config-columnas--3">
            <div class="grupo-campos">
                <h3 class="grupo-campos__titulo">Identificacion</h3>
                <dl class="ficha">
                    <dt>RUT</dt>
                    <dd><?= $e((string) $produccion['rut_emisor']); ?></dd>
                    <dt>Razon social</dt>
                    <dd><?= $e((string) $produccion['razon_social']); ?></dd>
                    <dt>Giro</dt>
                    <dd><?= $e((string) $produccion['giro']); ?></dd>
                    <dt>Actividad</dt>
                    <dd><?= $e((string) $produccion['acteco']); ?></dd>
                </dl>
            </div>
            <div class="grupo-campos">
                <h3 class="grupo-campos__titulo">Domicilio</h3>
                <dl class="ficha">
                    <dt>Direccion</dt>
                    <dd><?= $e((string) $produccion['dir_origen']); ?></dd>
                    <dt>Comuna</dt>
                    <dd><?= $e((string) $produccion['cmna_origen']); ?></dd>
                </dl>
            </div>
            <div class="grupo-campos">
                <h3 class="grupo-campos__titulo">Resolucion del SII</h3>
                <dl class="ficha">
                    <dt>Fecha</dt>
                    <dd><?php $f = (string) $produccion['resolucion_fecha']; $t = strtotime($f); echo $e($t !== false ? date('d/m/Y', $t) : $f); ?></dd>
                    <dt>Numero</dt>
                    <dd><?= $e((string) $produccion['resolucion_numero']); ?></dd>
                </dl>
            </div>
        </div>
    </section>

    <div class="acciones-formulario">
        <a class="boton-texto" href="/panel">Volver al panel</a>
        <a class="boton-principal" href="/certificado-produccion">Siguiente: certificado</a>
    </div>

<?php else: ?>

    <div class="alerta alerta--error" id="resumen-errores" role="alert" tabindex="-1"<?= $errores === [] ? ' hidden' : ''; ?>>
        <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
        <div class="alerta__cuerpo">
            <p class="alerta__titulo" data-resumen-titulo>
                <?= count($errores) === 1 ? 'Hay 1 campo por revisar antes de guardar' : 'Hay ' . count($errores) . ' campos por revisar antes de guardar'; ?>
            </p>
            <ul class="alerta__lista" data-resumen-lista>
                <?php foreach (array_replace(array_intersect_key($etiquetas, $errores), $errores) as $campo => $msg): ?>
                    <?php // Sin RUT editable no hay control al que llevar: el error se lee en la ficha. ?>
                    <li><?php if ($campo === 'rut_emisor' && ! $rutEditable): ?><?= $e($etiquetas[$campo]); ?><?php else: ?><a href="#<?= $e($campo); ?>"><?= $e($etiquetas[$campo] ?? $campo); ?></a><?php endif; ?>: <?= $e($msg); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <form method="post" action="/empresa-produccion" class="form-compacto" id="form-empresa-produccion" novalidate data-validar-form data-espera>
        <?= csrfInput(); ?>

        <section class="tarjeta" aria-labelledby="titulo-emisor">
            <h2 id="titulo-emisor">Datos del emisor</h2>
            <?php if (! $rutEditable): ?>
                <p class="tarjeta__intro">Vienen precargados desde tu configuracion de certificacion: corrigelos si algo cambio para produccion.</p>
            <?php endif; ?>

            <div class="config-columnas config-columnas--3">
                <fieldset class="grupo-campos">
                    <legend>Identificacion</legend>
                    <div class="form-grid form-grid--1">
                        <?php if ($rutEditable): ?>
                            <div class="<?= $claseCampo('rut_emisor'); ?>">
                                <label for="rut_emisor">RUT de la empresa</label>
                                <input type="text" name="rut_emisor" id="rut_emisor" value="<?= $val('rut_emisor'); ?>"
                                       placeholder="77724622-4" inputmode="text" autocomplete="off" spellcheck="false"
                                       data-validar="requerido rut" data-normalizar="rut" data-etiqueta="<?= $e($etiquetas['rut_emisor']); ?>"
                                       data-msg-requerido="Escribe el RUT que el SII autorizo, por ejemplo 77724622-4."
                                       <?= $aria('rut_emisor'); ?>>
                                <?= $campoError('rut_emisor'); ?>
                                <small class="form-ayuda" id="ayuda-rut_emisor"<?= $ocultaAyuda('rut_emisor'); ?>>El RUT que el SII autorizo como emisor electronico. Con o sin puntos.</small>
                            </div>
                        <?php else: ?>
                            <div class="form-campo">
                                <dl class="ficha ficha--compacta">
                                    <dt>RUT</dt>
                                    <dd><?= $e((string) ($emisor['rut_emisor'] ?? '')); ?></dd>
                                </dl>
                                <?= $campoError('rut_emisor'); ?>
                                <small class="form-ayuda"<?= $ocultaAyuda('rut_emisor'); ?>>Es el mismo RUT de certificacion y no se cambia aqui.</small>
                            </div>
                        <?php endif; ?>

                        <div class="<?= $claseCampo('razon_social'); ?>">
                            <label for="razon_social">Razon social</label>
                            <input type="text" name="razon_social" id="razon_social" value="<?= $val('razon_social'); ?>"
                                   autocomplete="organization"
                                   data-validar="requerido" data-normalizar="mayusculas" data-etiqueta="<?= $e($etiquetas['razon_social']); ?>"
                                   data-msg-requerido="Escribe la razon social tal como la tiene el SII."
                                   <?= $aria('razon_social'); ?>>
                            <?= $campoError('razon_social'); ?>
                            <small class="form-ayuda" id="ayuda-razon_social"<?= $ocultaAyuda('razon_social'); ?>>Exactamente como la tiene el SII. Pasa a mayusculas sola.</small>
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
                            <small class="form-ayuda" id="ayuda-dir_origen"<?= $ocultaAyuda('dir_origen'); ?>>Exactamente como la tiene el SII. Pasa a mayusculas sola.</small>
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
                    <legend>Resolucion de autorizacion</legend>
                    <div class="form-grid form-grid--1">
                        <div class="<?= $claseCampo('resolucion_fecha'); ?> form-campo--corto">
                            <label for="resolucion_fecha">Fecha de la resolucion</label>
                            <input type="date" name="resolucion_fecha" id="resolucion_fecha" value="<?= $val('resolucion_fecha'); ?>"
                                   data-validar="requerido" data-etiqueta="<?= $e($etiquetas['resolucion_fecha']); ?>"
                                   data-msg-requerido="Elige la fecha de la resolucion con que el SII te autorizo."
                                   <?= $aria('resolucion_fecha'); ?>>
                            <?= $campoError('resolucion_fecha'); ?>
                            <small class="form-ayuda" id="ayuda-resolucion_fecha"<?= $ocultaAyuda('resolucion_fecha'); ?>>
                                La de la Resolucion con que el SII te AUTORIZO como emisor electronico (correo o portal
                                de autorizacion). No es la fecha de postulacion ni se inventa.
                            </small>
                        </div>

                        <div class="<?= $claseCampo('resolucion_numero'); ?> form-campo--corto">
                            <label for="resolucion_numero">Numero de la resolucion</label>
                            <input type="text" inputmode="numeric" name="resolucion_numero" id="resolucion_numero"
                                   value="<?= $val('resolucion_numero'); ?>" placeholder="80"
                                   data-validar="requerido digitos" data-normalizar="digitos" data-etiqueta="<?= $e($etiquetas['resolucion_numero']); ?>"
                                   data-msg-requerido="Escribe el numero de la resolucion, por ejemplo 80."
                                   <?= $aria('resolucion_numero'); ?>>
                            <?= $campoError('resolucion_numero'); ?>
                            <small class="form-ayuda" id="ayuda-resolucion_numero"<?= $ocultaAyuda('resolucion_numero'); ?>>
                                El de la Resolucion Exenta que el SII te entrego (ej. Res. Ex. SII N&deg;80 de 2014).
                            </small>
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

<?php endif; ?>

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
