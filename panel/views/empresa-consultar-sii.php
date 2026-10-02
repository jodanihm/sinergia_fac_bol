<?php
/**
 * Configuracion > Empresa > Consultar datos al SII por RUT.
 *
 * TAREA DE LA PANTALLA, EN UNA FRASE: traer del SII la razon social y la
 * Resolucion de un RUT para revisarlas antes de escribirlas en Datos de la
 * empresa.
 *
 * Recibe: $error (string|null), $motivo (string|null), $datos (array|null, el
 * cuerpo de GET /api/v1/contribuyente del motor) y $rut (string, lo que el
 * usuario tecleo), todo de handleEmpresaConsultarSiiGet/Post().
 *
 * MISMO FLUJO QUE /empresa/importar-datos-sii: se pide un dato, se
 * PREVISUALIZA lo que devuelve, y el usuario decide con "Usar estos datos".
 * Nada se guarda aqui. El enlace arma el query string que handleEmpresaGet()
 * usa para precargar, y SOLO si todavia no hay fila guardada.
 *
 * QUE TRAE ESTA CONSULTA Y EL ARCHIVO NO: el numero y la fecha de Resolucion.
 * QUE NO TRAE: direccion, comuna ni giro. Se dice en pantalla porque es cierto.
 *
 * LOS TIPOS AUTORIZADOS SE MUESTRAN, NO SE USAN: se ensenan para que el
 * usuario confirme que el SII lo tiene habilitado para lo que va a emitir.
 *
 * LA ESPERA (seccion 14 del design system). La consulta sale a un servicio
 * externo y puede tardar varios segundos. Al enviar: nada visible bajo 300 ms,
 * despues spinner DENTRO del boton (mismo ancho) y aria-busy, y si pasa de
 * 10 s, una linea que dice que se sigue consultando. Un doble clic no gasta
 * dos consultas, que cuestan creditos.
 *
 * EL RUT MAL ESCRITO SE MARCA EN SU CAMPO ($motivo === 'rut_invalido'), no en
 * un aviso suelto: es un error de campo. Los otros motivos (sin respuesta, sin
 * credencial, ilegible) son de la consulta y van en aviso con salida a mano.
 */
$titulo = 'Consultar datos al SII';
require __DIR__ . '/partials/header.php';

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

/** Valor de texto que puede faltar: se marca como ausente, no en blanco. */
$oVacio = static function ($v) use ($e): string {
    $v = trim((string) ($v ?? ''));
    return $v === '' ? '<span class="dash-vacio-inline">Sin dato</span>' : $e($v);
};
/** Fecha aaaa-mm-dd a dd/mm/aaaa (escritura.html); otra cosa se deja tal cual. */
$fecha = static function ($v) use ($oVacio, $e): string {
    $v = trim((string) ($v ?? ''));
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) ? $e($m[3] . '/' . $m[2] . '/' . $m[1]) : $oVacio($v);
};

$errorRut = $motivo === 'rut_invalido' ? (string) $error : '';
$errorConsulta = ! empty($error) && $errorRut === '' ? (string) $error : '';

/** El formulario de consulta. Uno solo: el mismo sirve para el primer RUT y para "otro RUT". */
$formRut = static function (string $id, string $valor, string $errorCampo, bool $principal) use ($e): void { ?>
    <form method="post" action="/empresa/consultar-sii" class="form-compacto" novalidate data-validar-form data-espera
          data-espera-larga="Seguimos consultando al SII. Puede tardar hasta un minuto; no hace falta volver a apretar.">
        <?= csrfInput(); ?>
        <div class="form-campo<?= $errorCampo !== '' ? ' form-campo--error' : ''; ?> form-campo--corto">
            <label for="<?= $id; ?>">RUT de la empresa</label>
            <input type="text" name="rut_emisor" id="<?= $id; ?>" value="<?= $e($valor); ?>"
                   placeholder="77724622-4" inputmode="text" autocomplete="off" spellcheck="false"
                   data-validar="requerido rut" data-normalizar="rut" data-etiqueta="RUT de la empresa"
                   data-msg-requerido="Escribe el RUT a consultar, por ejemplo 77724622-4."
                   aria-describedby="ayuda-<?= $id; ?> error-<?= $id; ?>"<?= $errorCampo !== '' ? ' aria-invalid="true"' : ''; ?>>
            <p class="campo-error" id="error-<?= $id; ?>"<?= $errorCampo === '' ? ' hidden' : ''; ?>><?= iconoSvg('alerta', 16, ''); ?><span><?= $e($errorCampo); ?></span></p>
            <small class="form-ayuda" id="ayuda-<?= $id; ?>"<?= $errorCampo !== '' ? ' hidden' : ''; ?>>Con o sin puntos. Cada consulta se hace en el momento contra el SII.</small>
        </div>
        <p class="nota" data-aviso-espera role="status" hidden></p>
        <div class="acciones-formulario">
            <?php if ($principal): ?>
                <a class="boton-texto" href="/empresa">Completar a mano</a>
                <button type="submit" class="boton-principal">Consultar al SII</button>
            <?php else: ?>
                <button type="submit" class="boton-secundario">Consultar este RUT</button>
            <?php endif; ?>
        </div>
    </form>
<?php };
?>

<div class="dash-header">
    <div>
        <h1>Consultar datos al SII <span class="badge badge--etiqueta">Certificacion</span></h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <a class="boton-secundario" href="/empresa">Ir a Datos de la empresa</a>
        <a class="boton-secundario" href="/panel">Volver al panel</a>
    </div>
</div>
<p class="dash-subtitulo">
    Trae del SII la razon social y la Resolucion de un RUT, para revisarlas antes de escribirlas.
</p>

<div class="config-ancho">

<?php /* Resumen de errores: el JavaScript lo usa al validar antes de enviar. El
         error de RUT del servidor ya se pinta bajo el campo; un campo solo no
         necesita ademas un resumen arriba. */ ?>
<div class="alerta alerta--error" id="resumen-errores" role="alert" tabindex="-1" hidden>
    <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
    <div class="alerta__cuerpo">
        <p class="alerta__titulo" data-resumen-titulo></p>
        <ul class="alerta__lista" data-resumen-lista></ul>
    </div>
</div>

<?php if ($errorConsulta !== ''): ?>
    <div class="alerta alerta--<?= $motivo === 'sin_respuesta' ? 'advertencia' : 'error'; ?>" role="alert">
        <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
        <div class="alerta__cuerpo">
            <p class="alerta__titulo">No pudimos traer los datos del SII</p>
            <p><?= $e($errorConsulta); ?> <a href="/empresa">Completar a mano</a></p>
        </div>
    </div>
<?php endif; ?>

<?php if ($datos === null): ?>

    <div class="config-columnas config-columnas--2">
        <section class="tarjeta" aria-labelledby="titulo-rut">
            <h2 id="titulo-rut">RUT a consultar</h2>
            <?php $formRut('rut_emisor', (string) $rut, $errorRut, true); ?>
        </section>

        <div class="panel-info">
            <p class="panel-info__titulo">
                <?= iconoSvg('info', 20, 'panel-info__icono'); ?>
                Como funciona
            </p>
            <ol class="lista-pasos">
                <li>Escribes el RUT y el SII responde su razon social y su Resolucion.</li>
                <li>Revisas lo que respondio. Aqui no se guarda nada.</li>
                <li>Si esta bien, "Usar estos datos" los lleva a Datos de la empresa.</li>
            </ol>
            <p>Es una ayuda opcional. Direccion, comuna y giro no vienen en esta consulta.</p>
        </div>
    </div>

<?php else: ?>

    <?php
    $autorizado = ! empty($datos['autorizado']);
    $documentos = is_array($datos['documentos'] ?? null) ? $datos['documentos'] : [];
    ?>

    <?php if ($autorizado): ?>
        <div class="alerta alerta--exito" role="status">
            <?= iconoSvg('check', 20, 'alerta__icono'); ?>
            <div class="alerta__cuerpo">
                <p class="alerta__titulo">Datos obtenidos del SII</p>
                <p>Todavia no se guardo nada: revisalos y confirma con "Usar estos datos".</p>
            </div>
        </div>
    <?php else: ?>
        <?php
        /* NO AUTORIZADO NO ES UN ERROR DE LA CONSULTA: el SII respondio y esto es
           lo que dijo. Va en ambar y no en rojo, y NO se ofrece "usar estos
           datos": precargar la resolucion de un RUT que el SII no tiene
           habilitado seria darle por bueno un dato que no sirve para emitir. */
        ?>
        <div class="alerta alerta--advertencia" role="status">
            <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
            <div class="alerta__cuerpo">
                <p class="alerta__titulo">El SII no tiene este RUT habilitado como emisor electronico</p>
                <p>Revisa el RUT, o sigue a mano si sabes que la habilitacion esta en tramite.</p>
            </div>
        </div>
    <?php endif; ?>

    <div class="config-columnas config-columnas--2">
        <section class="tarjeta" aria-labelledby="titulo-previa">
            <h2 id="titulo-previa">Datos del contribuyente</h2>
            <dl class="ficha">
                <dt>RUT</dt>
                <dd><?= $oVacio($datos['rut'] ?? ''); ?></dd>

                <dt>Razon social</dt>
                <dd><?= $oVacio($datos['razonSocial'] ?? ''); ?></dd>

                <dt>Numero de Resolucion</dt>
                <dd><?= ($datos['resolucionNumero'] ?? null) === null ? $oVacio('') : (int) $datos['resolucionNumero']; ?></dd>

                <dt>Fecha de Resolucion</dt>
                <dd><?= $fecha($datos['resolucionFecha'] ?? ''); ?></dd>

                <dt>Direccion regional</dt>
                <dd><?= $oVacio($datos['direccionRegional'] ?? ''); ?></dd>

                <dt>Software</dt>
                <dd><?= $oVacio($datos['software'] ?? ''); ?></dd>
            </dl>
        </section>

        <div>
            <div class="panel-info panel-info--advertencia">
                <p class="panel-info__titulo">
                    <?= iconoSvg('alerta', 20, 'panel-info__icono'); ?>
                    Falta completar a mano
                </p>
                <p>La <strong>direccion</strong>, la <strong>comuna</strong> y el <strong>giro</strong>
                no vienen en esta consulta: los completas en el paso siguiente.</p>
            </div>

            <section class="tarjeta" aria-labelledby="titulo-otro">
                <h2 id="titulo-otro">Consultar otro RUT</h2>
                <?php $formRut('rut-otro', '', '', false); ?>
            </section>
        </div>
    </div>

    <?php if ($documentos !== []): ?>
        <section class="tarjeta" aria-labelledby="titulo-documentos">
            <h2 id="titulo-documentos">Documentos autorizados</h2>
            <p class="tarjeta__intro">Para que confirmes que puedes emitir lo que necesitas. Esta pantalla no valida contra esta lista.</p>
            <table class="tabla-datos tabla-datos--cards">
                <thead>
                    <tr>
                        <th class="tabla-datos__num">Codigo</th>
                        <th>Descripcion</th>
                        <th>Autorizado</th>
                        <th class="tabla-datos__estado">Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($documentos as $doc): ?>
                        <tr>
                            <td class="tabla-datos__num" data-label="Codigo"><?= (int) ($doc['codigo'] ?? 0); ?></td>
                            <td data-label="Descripcion"><?= $e((string) ($doc['descripcion'] ?? '')); ?></td>
                            <td data-label="Autorizado"><?= $fecha($doc['autorizado'] ?? ''); ?></td>
                            <td class="tabla-datos__estado" data-label="Estado">
                                <?php if (! empty($doc['vigente'])): ?>
                                    <span class="badge badge--ok"><?= iconoSvg('check', 16, 'badge__icono'); ?>Vigente</span>
                                <?php else: ?>
                                    <span class="badge badge--neutro"><?= iconoSvg('sin-cargar', 16, 'badge__icono'); ?>Desautorizado</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>

    <div class="acciones-formulario">
        <a class="boton-texto" href="/empresa">Seguir sin usar esta consulta</a>
        <?php if ($autorizado): ?>
            <?php
            /* Los parametros del flujo del archivo son un contrato (ver
               empresa-importar-datos-sii.php). Aqui se mandan solo los que esta
               consulta SI resuelve, mas los dos de resolucion. razon_social va
               siempre porque es la llave que activa la precarga en
               handleEmpresaGet(). */
            $qs = http_build_query([
                'rut_emisor'        => (string) ($datos['rut'] ?? ''),
                'razon_social'      => (string) ($datos['razonSocial'] ?? ''),
                'resolucion_numero' => ($datos['resolucionNumero'] ?? null) === null ? '' : (string) (int) $datos['resolucionNumero'],
                'resolucion_fecha'  => (string) ($datos['resolucionFecha'] ?? ''),
            ]);
            ?>
            <a class="boton-principal" href="/empresa?<?= $e($qs); ?>">Usar estos datos</a>
        <?php endif; ?>
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
