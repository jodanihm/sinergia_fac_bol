<?php
/**
 * Configuracion > Empresa > Importar datos desde el archivo del SII.
 *
 * TAREA DE LA PANTALLA, EN UNA FRASE: subir el archivo "Datos para
 * Construccion DTE" del SII y revisar lo que trae antes de pasarlo a Datos de
 * la empresa.
 *
 * Recibe: $error (string|null) y $datos (DatosContribuyenteSii|null), ambos de
 * handleEmpresaImportarDatosSiiGet/Post().
 *
 * FLUJO DE DOS PASOS, sin persistencia: se sube el archivo, se PREVISUALIZA lo
 * que trae, y el usuario decide. Nada se guarda aqui. El enlace "Usar estos
 * datos" arma un query string con 6 parametros que handleEmpresaGet() usa para
 * precargar el formulario, y SOLO si todavia no hay una fila guardada. Esos 6
 * parametros son un contrato: cambiarlos o reordenarlos rompe la precarga.
 *
 * La fecha y el numero de resolucion NO vienen en el archivo: eso se dice aqui
 * porque es cierto.
 *
 * El formulario de "probar con otro archivo" es el MISMO que el inicial (mismo
 * action, method, enctype, name); solo cambia el titulo y su boton deja de ser
 * el primario, porque en ese paso la accion principal es "Usar estos datos".
 *
 * LA ESPERA (seccion 14 del design system): leer el archivo es casi siempre
 * rapido; si pasa de 300 ms el boton muestra su spinner sin cambiar de ancho, y
 * si pasa de 10 s una linea dice que se sigue leyendo.
 */
$titulo = 'Importar datos desde el SII';
require __DIR__ . '/partials/header.php';

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

/** El formulario de subida. Uno solo para los dos pasos. */
$formArchivo = static function (string $id, bool $principal): void { ?>
    <form method="post" action="/empresa/importar-datos-sii" enctype="multipart/form-data" class="form-compacto"
          novalidate data-validar-form data-espera
          data-espera-larga="Seguimos leyendo el archivo. No hace falta volver a subirlo.">
        <?= csrfInput(); ?>
        <div class="form-campo">
            <label for="<?= $id; ?>">Archivo de Datos para Construccion DTE</label>
            <input type="file" name="archivo" id="<?= $id; ?>"
                   data-validar="requerido" data-etiqueta="Archivo de Datos para Construccion DTE"
                   data-msg-requerido="Elige el archivo que descargaste del SII."
                   aria-describedby="ayuda-<?= $id; ?> error-<?= $id; ?>">
            <p class="campo-error" id="error-<?= $id; ?>" hidden><?= iconoSvg('alerta', 16, ''); ?><span></span></p>
            <?php // De donde sale el archivo se dice ANTES de elegirlo (formularios.html, campo Archivo). ?>
            <small class="form-ayuda" id="ayuda-<?= $id; ?>">Descargalo desde
                <a href="https://maullin.sii.cl/cvc_cgi/dte/pe_construccion_dte" target="_blank" rel="noopener noreferrer">pe_construccion_dte (se abre en otra pestana)</a>
                en el portal del SII, y subelo tal cual.</small>
        </div>
        <p class="nota" data-aviso-espera role="status" hidden></p>
        <div class="acciones-formulario">
            <?php if ($principal): ?>
                <a class="boton-texto" href="/empresa">Completar a mano</a>
                <button type="submit" class="boton-principal">Revisar archivo</button>
            <?php else: ?>
                <button type="submit" class="boton-secundario">Revisar este archivo</button>
            <?php endif; ?>
        </div>
    </form>
<?php };
?>

<div class="dash-header">
    <div>
        <h1>Importar datos desde el SII <span class="badge badge--etiqueta">Certificacion</span></h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <a class="boton-secundario" href="/empresa">Ir a Datos de la empresa</a>
        <a class="boton-secundario" href="/panel">Volver al panel</a>
    </div>
</div>
<p class="dash-subtitulo">
    Sube el archivo Datos para Construccion DTE del SII y revisa sus datos antes de usarlos.
</p>

<div class="config-ancho">

<div class="alerta alerta--error" id="resumen-errores" role="alert" tabindex="-1" hidden>
    <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
    <div class="alerta__cuerpo">
        <p class="alerta__titulo" data-resumen-titulo></p>
        <ul class="alerta__lista" data-resumen-lista></ul>
    </div>
</div>

<?php if (! empty($error)): ?>
    <div class="alerta alerta--error" role="alert">
        <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
        <div class="alerta__cuerpo">
            <p class="alerta__titulo">No pudimos leer el archivo</p>
            <p><?= $e((string) $error); ?> Tambien puedes <a href="/empresa">completar los datos a mano</a>.</p>
        </div>
    </div>
<?php endif; ?>

<?php if ($datos === null): ?>

    <div class="config-columnas config-columnas--2">
        <section class="tarjeta" aria-labelledby="titulo-archivo">
            <h2 id="titulo-archivo">Archivo del SII</h2>
            <?php $formArchivo('archivo', true); ?>
        </section>

        <div class="panel-info">
            <p class="panel-info__titulo">
                <?= iconoSvg('info', 20, 'panel-info__icono'); ?>
                Como funciona
            </p>
            <ol class="lista-pasos">
                <li>Subes el archivo y ves lo que trae. Aqui no se guarda nada.</li>
                <li>Si esta bien, "Usar estos datos" los lleva a Datos de la empresa.</li>
                <li>Alli completas la fecha de resolucion, que el archivo no trae, y guardas.</li>
            </ol>
            <p>Es una ayuda opcional: tambien puedes completar los datos a mano.</p>
        </div>
    </div>

<?php else: ?>

    <div class="alerta alerta--exito" role="status">
        <?= iconoSvg('check', 20, 'alerta__icono'); ?>
        <div class="alerta__cuerpo">
            <p class="alerta__titulo">Archivo leido</p>
            <p>Todavia no se guardo nada: revisa los datos y confirma con "Usar estos datos".</p>
        </div>
    </div>

    <div class="config-columnas config-columnas--2">
        <section class="tarjeta" aria-labelledby="titulo-previa">
            <h2 id="titulo-previa">Datos del contribuyente</h2>
            <dl class="ficha">
                <dt>RUT</dt>
                <dd><?= $e($datos->rut); ?></dd>

                <dt>Razon social</dt>
                <dd><?= $e($datos->razonSocial); ?></dd>

                <dt>Direccion</dt>
                <dd><?= $e($datos->direccion); ?></dd>

                <dt>Comuna</dt>
                <dd><?= $e($datos->comuna); ?></dd>

                <dt>Giro</dt>
                <dd><?= $e($datos->giro); ?></dd>

                <dt>Actividad principal</dt>
                <dd><?= (int) $datos->actecoPrincipal(); ?></dd>
            </dl>
        </section>

        <div>
            <div class="panel-info panel-info--advertencia">
                <p class="panel-info__titulo">
                    <?= iconoSvg('alerta', 20, 'panel-info__icono'); ?>
                    Falta completar a mano
                </p>
                <p>La fecha y el numero de Resolucion no vienen en este archivo: los completas en el
                paso siguiente, o traelos con <a href="/empresa/consultar-sii">Consultar al SII</a>.</p>
            </div>

            <section class="tarjeta" aria-labelledby="titulo-otro">
                <h2 id="titulo-otro">Probar con otro archivo</h2>
                <?php $formArchivo('archivo-otro', false); ?>
            </section>
        </div>
    </div>

    <section class="tarjeta" aria-labelledby="titulo-actividades">
        <h2 id="titulo-actividades">Actividades economicas</h2>
        <table class="tabla-datos tabla-datos--cards">
            <thead>
                <tr>
                    <th class="tabla-datos__num">Codigo</th>
                    <th>Descripcion</th>
                    <th>Afecta a IVA</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($datos->actividades as $act): ?>
                    <tr>
                        <td class="tabla-datos__num" data-label="Codigo"><?= (int) $act->codigo; ?></td>
                        <td data-label="Descripcion"><?= $e($act->descripcion); ?></td>
                        <td data-label="Afecta a IVA"><?= $act->afectoIva ? 'Si' : 'No'; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <div class="acciones-formulario">
        <a class="boton-texto" href="/empresa">Seguir sin importar</a>
        <a class="boton-principal" href="/empresa?<?= $e(http_build_query([
            'rut_emisor'   => $datos->rut,
            'razon_social' => $datos->razonSocial,
            'giro'         => $datos->giro,
            'acteco'       => (string) $datos->actecoPrincipal(),
            'dir_origen'   => $datos->direccion,
            'cmna_origen'  => $datos->comuna,
        ])); ?>">Usar estos datos</a>
    </div>

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
