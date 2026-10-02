<?php
/**
 * Configuracion > Cobro en linea.
 *
 * TAREA DE LA PANTALLA, EN UNA FRASE: el dueno de la empresa carga sus llaves de
 * Flow y enciende el boton de pago en los correos, sabiendo en todo momento si
 * cobra dinero real o esta en pruebas.
 *
 * Recibe: $config (array|null con proveedor, ambiente_activo, habilitado y
 * url_retorno), $credenciales (el llavero: una entrada por ambiente, con apiKey
 * y si hay secreto -- NUNCA el secreto), $errores (array<string,string>),
 * $proveedores (list<string>) y $flash (array|null, solo tras guardar).
 *
 * EL SECRETO NO SE PINTA NUNCA, NI SIQUIERA ENMASCARADO. El campo va vacio
 * siempre y al lado se dice si hay uno guardado. Un value con asteriscos seria
 * peor que inutil: quien edite otro campo lo enviaria tal cual y sobrescribiria
 * el secreto bueno con asteriscos. Dejarlo vacio significa "no lo cambies", que
 * es el mismo trato que ya reciben las claves en el resto del panel.
 *
 * EL ORDEN SIGUE AL TRABAJO, NO A LA BASE: llaves -> ambiente -> regreso del
 * cliente -> encender. "Quiero cobrar en linea" y "estas son mis llaves" siguen
 * siendo dos decisiones separadas (el interruptor vive solo, en su tarjeta),
 * pero el interruptor va AL FINAL porque depende de lo de arriba: se cargan las
 * llaves, se revisan, y recien entonces se enciende. Lo que se necesita ver
 * primero -- si hoy se cobra de verdad o no -- no es el interruptor sino el
 * aviso de estado, y ese si va arriba de todo.
 *
 * EL INTERRUPTOR DICE LO QUE VA A PASAR (regla de interruptores del design
 * system): la linea bajo el titulo se reescribe al cambiarlo, al cambiar de
 * ambiente y al escribir llaves. Las cuatro frases viajan en data-* y la de la
 * carga inicial sale de las mismas plantillas, asi que con o sin JavaScript se
 * lee lo mismo. Sin JavaScript el control nunca queda deshabilitado: la regla
 * "no se enciende sin llaves" la sigue haciendo cumplir el servidor.
 *
 * VALIDACION: la del servidor manda. El JavaScript de abajo solo adelanta las
 * mismas reglas al salir de cada campo (nunca mientras se escribe la primera
 * vez) y, al enviar con problemas, arma el mismo resumen que pintaria el
 * servidor y lleva el foco al primer campo.
 */
$titulo = 'Cobro en linea';
require __DIR__ . '/partials/header.php';

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$errores = $errores ?? [];
// El llavero llega SIEMPRE con las dos entradas, existan o no: la pantalla
// tiene que poder decir "sandbox: configurado / produccion: sin cargar" sin
// adivinar. El secreto no viaja hasta aqui, solo si esta puesto.
$llavero = $credenciales ?? [
    'sandbox'    => ['apiKey' => '', 'tieneSecreto' => false],
    'produccion' => ['apiKey' => '', 'tieneSecreto' => false],
];
$ambientes = [
    'sandbox'    => ['rotulo' => 'Sandbox',    'detalle' => 'Pruebas. Los botones de pago no cobran: sirven para revisar que todo funcione.'],
    'produccion' => ['rotulo' => 'Produccion', 'detalle' => 'Dinero real. Cada pago llega a tu cuenta de la pasarela.'],
];
$habilitado = ! empty($config['habilitado']);
// Sin fila todavia -> sandbox, igual que el default de la columna. Que la
// pantalla y la base digan lo mismo evita que alguien crea que esta en
// produccion porque el formulario venia en blanco.
$ambiente     = ($config['ambiente_activo'] ?? 'sandbox') === 'produccion' ? 'produccion' : 'sandbox';
$enProduccion = $ambiente === 'produccion';
$proveedor    = (string) ($config['proveedor'] ?? 'flow');
$nombrePasarela = ucfirst($proveedor);

$llavesListas = static fn (string $amb): bool
    => $llavero[$amb]['apiKey'] !== '' && $llavero[$amb]['tieneSecreto'];
$algunaLlave  = $llavesListas('sandbox') || $llavesListas('produccion');

// Etiqueta de cada campo en el resumen de errores, y a que control lleva el
// enlace. El error de ambiente apunta a la opcion marcada: es la que hay que
// cambiar.
$camposResumen = [
    'proveedor'          => ['proveedor', 'Pasarela'],
    'apikey_sandbox'     => ['apikey_sandbox', 'API key de Sandbox'],
    'secreto_sandbox'    => ['secreto_sandbox', 'Secret key de Sandbox'],
    'apikey_produccion'  => ['apikey_produccion', 'API key de Produccion'],
    'secreto_produccion' => ['secreto_produccion', 'Secret key de Produccion'],
    'ambiente_activo'    => ['ambiente_' . $ambiente, 'Ambiente activo'],
    'url_retorno'        => ['url_retorno', 'Pagina de regreso'],
];

/** Mensaje de error bajo un campo: icono + texto, y vacio y oculto si no hay. */
$campoError = static function (string $campo) use ($errores, $e): string {
    $msg = $errores[$campo] ?? '';
    return '<p class="campo-error" id="error-' . $e($campo) . '"' . ($msg === '' ? ' hidden' : '') . '>'
        . iconoSvg('alerta', 16, '') . '<span>' . $e($msg) . '</span></p>';
};
/** La ayuda se oculta mientras el campo muestra su error: ayuda O error, no los dos. */
$ocultaAyuda = static fn (string $campo): string => isset($errores[$campo]) ? ' hidden' : '';
/** aria-invalid + aria-describedby (ayuda y error) de un control. */
$aria = static function (string $campo, string $ayudaId = '') use ($errores): string {
    $ids = trim($ayudaId . ' error-' . $campo);
    return ' aria-describedby="' . $ids . '"' . (isset($errores[$campo]) ? ' aria-invalid="true"' : '');
};
$claseCampo = static fn (string $campo): string
    => 'form-campo' . (isset($errores[$campo]) ? ' form-campo--error' : '');

// Las cuatro frases del interruptor. {amb} es el ambiente elegido.
$frases = [
    'on'          => 'Las facturas y facturas exentas que envies llevaran un boton para pagarlas en {amb}. Las notas de credito nunca lo llevan.',
    'off'         => 'Tus correos salen sin boton de pago, como hasta ahora. Los links ya enviados siguen funcionando.',
    'bloqueado'   => 'Se puede encender cuando cargues las dos llaves de {amb}.',
    'faltan'      => 'Faltan las llaves de {amb}: cargalas arriba o elige el otro ambiente para poder guardar.',
];
$fraseInicial = str_replace(
    '{amb}',
    $ambientes[$ambiente]['rotulo'],
    $frases[$llavesListas($ambiente) ? ($habilitado ? 'on' : 'off') : ($habilitado ? 'faltan' : 'off')]
);
?>

<div class="dash-header">
    <div>
        <h1>Cobro en linea</h1>
    </div>
</div>
<p class="dash-subtitulo">
    Tus correos de factura pueden llevar un boton para pagarla. El dinero llega a tu cuenta de <?= $e($nombrePasarela); ?>, no a la nuestra.
</p>

<div class="config-ancho">

<?php if (! empty($flash['mensaje'])): ?>
    <p class="alerta alerta--<?= ($flash['tipo'] ?? '') === 'exito' ? 'exito' : 'advertencia'; ?>" role="status">
        <?= iconoSvg(($flash['tipo'] ?? '') === 'exito' ? 'check' : 'alerta', 20, 'alerta__icono'); ?>
        <span><?= $e((string) $flash['mensaje']); ?></span>
    </p>
<?php endif; ?>

<?php
/* RESUMEN DE ERRORES. Existe siempre en el DOM (oculto si no hay nada) porque
   el JavaScript reusa este mismo contenedor al validar antes de enviar: un solo
   lugar, un solo aspecto, una sola region que se anuncia. */
?>
<div class="alerta alerta--error" id="resumen-errores" role="alert" tabindex="-1"<?= $errores === [] ? ' hidden' : ''; ?>>
    <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
    <div class="alerta__cuerpo">
        <p class="alerta__titulo" data-resumen-titulo>
            <?= count($errores) === 1 ? 'Hay 1 campo por revisar antes de guardar' : 'Hay ' . count($errores) . ' campos por revisar antes de guardar'; ?>
        </p>
        <ul class="alerta__lista" data-resumen-lista>
            <?php // En el orden de la pantalla, no en el que los encontro el servidor. ?>
            <?php foreach (array_replace(array_intersect_key($camposResumen, $errores), $errores) as $campo => $msg): ?>
                <?php [$destino, $etiqueta] = $camposResumen[$campo] ?? [$campo, $campo]; ?>
                <li><a href="#<?= $e($destino); ?>"><?= $e($etiqueta); ?></a>: <?= $e($msg); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>

<?php
/* EL ESTADO ACTUAL, ARRIBA Y CON ICONO, COLOR Y TEXTO. Es el dato que decide si
   un clic en el correo de un cliente mueve dinero de verdad, y tiene que verse
   antes que ningun campo. Con errores no se pinta: $config trae entonces lo que
   se intento guardar, no lo que esta guardado, y el aviso mentiria. */
?>
<?php if ($errores === []): ?>
    <?php if ($habilitado && $enProduccion): ?>
        <div class="alerta alerta--exito">
            <?= iconoSvg('check', 20, 'alerta__icono'); ?>
            <div class="alerta__cuerpo">
                <p class="alerta__titulo">Cobrando en Produccion</p>
                <p>Cada boton de pago de tus correos cobra dinero real, que llega a tu cuenta de <?= $e($nombrePasarela); ?>.</p>
            </div>
        </div>
    <?php elseif ($habilitado): ?>
        <div class="alerta alerta--advertencia">
            <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
            <div class="alerta__cuerpo">
                <p class="alerta__titulo">Activo en Sandbox: los pagos no son reales</p>
                <p>Los botones de pago sirven para probar, pero ningun cliente puede pagarte de verdad. Cuando quieras cobrar, elige Produccion.</p>
            </div>
        </div>
    <?php elseif (! $algunaLlave): ?>
        <div class="panel-info">
            <p class="panel-info__titulo">
                <?= iconoSvg('info', 20, 'panel-info__icono'); ?>
                Todavia no cobras en linea
            </p>
            <p>Mientras no lo enciendas, tus correos salen exactamente como hasta ahora. Para empezar:</p>
            <ol class="lista-pasos">
                <li>Crea tu cuenta de comercio en <?= $e($nombrePasarela); ?>.</li>
                <li>Copia su API key y su Secret key y pegalas abajo.</li>
                <li>Prueba en Sandbox. Cuando todo funcione, elige Produccion.</li>
                <li>Enciende el cobro al final de esta pagina.</li>
            </ol>
        </div>
    <?php else: ?>
        <div class="panel-info">
            <p class="panel-info__titulo">
                <?= iconoSvg('info', 20, 'panel-info__icono'); ?>
                El cobro en linea esta apagado
            </p>
            <p>Tus correos salen sin boton de pago. Las llaves quedan guardadas: puedes encenderlo en <a href="#habilitado">Activacion</a>.</p>
        </div>
    <?php endif; ?>
<?php endif; ?>

<form method="post" action="/configuracion/pagos" class="form-compacto" id="form-pagos" novalidate>
    <?= csrfInput(); ?>

    <section class="tarjeta" aria-labelledby="titulo-llaves">
        <h2 id="titulo-llaves">Llaves de <?= $e($nombrePasarela); ?></h2>
        <p class="tarjeta__intro">
            Salen de tu panel de comercio en <?= $e($nombrePasarela); ?>. Cada ambiente tiene las suyas,
            y guardar unas no toca las otras.
        </p>

        <?php if (count($proveedores) > 1): ?>
            <div class="<?= $claseCampo('proveedor'); ?> form-campo--corto">
                <label for="proveedor">Pasarela</label>
                <select name="proveedor" id="proveedor"<?= $aria('proveedor'); ?>>
                    <?php foreach ($proveedores as $p): ?>
                        <option value="<?= $e($p); ?>" <?= $proveedor === $p ? 'selected' : ''; ?>><?= $e(ucfirst($p)); ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $campoError('proveedor'); ?>
            </div>
        <?php else: ?>
            <?php // Con una sola pasarela, un desplegable de una opcion es ruido: no hay nada que elegir. ?>
            <input type="hidden" name="proveedor" value="<?= $e($proveedores[0] ?? $proveedor); ?>">
        <?php endif; ?>

        <?php
            /* LAS DOS PAREJAS, CADA UNA EN SU BLOQUE. Antes habia un solo par de
               campos y el ambiente decidia cual se usaba, asi que pasar a
               produccion sobrescribia las llaves de sandbox -- y dejar el secreto
               en blanco (lo que la propia pantalla recomienda al editar) juntaba
               la API key de un ambiente con la Secret key del otro. Separadas,
               eso no se puede escribir.
               LADO A LADO, un ambiente por columna: la pagina usa todo el ancho
               repartiendolo en columnas, no estirando campos. Asi Sandbox y
               Produccion se comparan de un vistazo (cual tiene llaves y cual
               no), y cada input queda del ancho de su columna, que es donde la
               etiqueta y el dato se siguen leyendo como pareja. Dentro de cada
               bloque, API key arriba y Secret key abajo. */
        ?>
        <div class="config-columnas config-columnas--2">
        <?php foreach ($ambientes as $amb => $a): ?>
            <fieldset class="grupo-campos" data-llaves="<?= $amb; ?>"
                      data-secreto-guardado="<?= $llavero[$amb]['tieneSecreto'] ? '1' : '0'; ?>">
                <legend>
                    <?= $e($a['rotulo']); ?>
                    <?php if ($llavesListas($amb)): ?>
                        <span class="badge badge--ok"><?= iconoSvg('check', 16, 'badge__icono'); ?>Configuradas</span>
                    <?php else: ?>
                        <span class="badge badge--neutro"><?= iconoSvg('sin-cargar', 16, 'badge__icono'); ?>Sin cargar</span>
                    <?php endif; ?>
                </legend>

                <div class="form-grid form-grid--1">
                    <div class="<?= $claseCampo('apikey_' . $amb); ?>">
                        <label for="apikey_<?= $amb; ?>">API key</label>
                        <input type="text" name="apikey_<?= $amb; ?>" id="apikey_<?= $amb; ?>"
                               value="<?= $e($llavero[$amb]['apiKey']); ?>"
                               autocomplete="off" spellcheck="false" autocapitalize="off"
                               <?= $aria('apikey_' . $amb, 'ayuda-apikey_' . $amb); ?>>
                        <?= $campoError('apikey_' . $amb); ?>
                        <small class="form-ayuda" id="ayuda-apikey_<?= $amb; ?>"<?= $ocultaAyuda('apikey_' . $amb); ?>>Identifica tu comercio. No es secreta.</small>
                    </div>

                    <div class="<?= $claseCampo('secreto_' . $amb); ?>">
                        <label for="secreto_<?= $amb; ?>">Secret key</label>
                        <input type="password" name="secreto_<?= $amb; ?>" id="secreto_<?= $amb; ?>"
                               value="" autocomplete="new-password" spellcheck="false" autocapitalize="off"
                               <?= $aria('secreto_' . $amb, 'ayuda-secreto_' . $amb); ?>>
                        <?= $campoError('secreto_' . $amb); ?>
                        <small class="form-ayuda" id="ayuda-secreto_<?= $amb; ?>"<?= $ocultaAyuda('secreto_' . $amb); ?>>
                            <?php if ($llavero[$amb]['tieneSecreto']): ?>
                                Hay una guardada y cifrada. Dejalo en blanco para conservarla.
                            <?php else: ?>
                                Se guarda cifrada y no se vuelve a mostrar. Va siempre con su API key.
                            <?php endif; ?>
                        </small>
                    </div>
                </div>
            </fieldset>
        <?php endforeach; ?>
        </div>
    </section>

    <?php
    /* LAS TRES DECISIONES CORTAS, EN UNA FILA. En pantalla ancha van lado a
       lado y en el mismo orden del trabajo (de izquierda a derecha): ambiente,
       regreso, encender. El orden del DOM es el visual, asi que con Tab y en
       movil se recorren igual que antes. */
    ?>
    <div class="config-columnas config-columnas--3">
    <section class="tarjeta" aria-labelledby="titulo-ambiente">
        <h2 id="titulo-ambiente">Ambiente activo</h2>
        <fieldset class="opciones" role="radiogroup" aria-describedby="ayuda-ambiente error-ambiente_activo"
                  <?= isset($errores['ambiente_activo']) ? 'aria-invalid="true"' : ''; ?>>
            <legend class="visualmente-oculto">Donde se crean los cobros de las facturas nuevas</legend>
            <?php foreach ($ambientes as $amb => $a): ?>
                <label class="opcion" for="ambiente_<?= $amb; ?>">
                    <input type="radio" name="ambiente_activo" id="ambiente_<?= $amb; ?>" value="<?= $amb; ?>"
                           <?= $ambiente === $amb ? 'checked' : ''; ?>>
                    <span>
                        <span class="opcion__titulo"><?= $e($a['rotulo']); ?></span>
                        <span class="opcion__detalle"><?= $e($a['detalle']); ?></span>
                    </span>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <?= $campoError('ambiente_activo'); ?>
        <small class="form-ayuda" id="ayuda-ambiente"<?= $ocultaAyuda('ambiente_activo'); ?>>
            Afecta solo a las facturas nuevas: cada link ya enviado sigue cobrando donde nacio.
            Cambiar de ambiente no borra las llaves del otro.
        </small>
    </section>

    <section class="tarjeta" aria-labelledby="titulo-regreso">
        <h2 id="titulo-regreso">Despues de pagar</h2>
        <div class="<?= $claseCampo('url_retorno'); ?>">
            <label for="url_retorno">Pagina de regreso (opcional)</label>
            <input type="url" name="url_retorno" id="url_retorno" value="<?= $e((string) ($config['url_retorno'] ?? '')); ?>"
                   placeholder="https://tuempresa.cl/gracias" inputmode="url" autocomplete="url" spellcheck="false"
                   <?= $aria('url_retorno', 'ayuda-url_retorno'); ?>>
            <?= $campoError('url_retorno'); ?>
            <?php // La misma url que arma ResolutorLinkPago cuando el campo va vacio. ?>
            <small class="form-ayuda" id="ayuda-url_retorno"<?= $ocultaAyuda('url_retorno'); ?>>
                A donde vuelve tu cliente al terminar de pagar. Si lo dejas vacio, vuelve a
                <?= $e(rtrim(trim((string) getenv('PANEL_URL_PUBLICA')), '/') . RUTA_RETORNO_PAGO); ?>,
                una pagina nuestra que le confirma el pago.
            </small>
        </div>
    </section>

    <section class="tarjeta" aria-labelledby="titulo-activacion">
        <h2 id="titulo-activacion">Activacion</h2>
        <label class="interruptor" for="habilitado">
            <input type="checkbox" role="switch" name="habilitado" id="habilitado" value="1"
                   <?= $habilitado ? 'checked' : ''; ?> aria-describedby="detalle-habilitado">
            <span class="interruptor__texto">
                <span class="interruptor__titulo">Incluir boton de pago en mis correos</span>
                <span class="interruptor__detalle" id="detalle-habilitado"
                      data-on="<?= $e($frases['on']); ?>" data-off="<?= $e($frases['off']); ?>"
                      data-bloqueado="<?= $e($frases['bloqueado']); ?>" data-faltan="<?= $e($frases['faltan']); ?>"><?= $e($fraseInicial); ?></span>
            </span>
        </label>
        <ul class="lista-ayuda">
            <li>Si <?= $e($nombrePasarela); ?> no responde, el correo espera y se reintenta solo, en vez de salir sin el boton.</li>
            <li>Un correo atascado mas de 6 horas aparece en Ventas &gt; Correos, donde puedes enviarlo sin link. Apagar el cobro libera todos.</li>
            <li>Para dejar fuera a un cliente concreto, hazlo desde su ficha en Maestros &gt; Clientes.</li>
        </ul>
    </section>
    </div>

    <div class="acciones-formulario">
        <button type="submit" class="boton-principal" id="guardar-pagos">Guardar cambios</button>
    </div>
</form>

</div>

<script>
/* Validacion al salir del campo, interruptor con su frase y boton que se
   bloquea al enviar. Las reglas son las mismas de handlePagosConfigPost():
   el servidor sigue siendo quien decide. */
(function () {
    var form = document.getElementById('form-pagos');
    if (!form) { return; }

    var rotulos = { sandbox: 'Sandbox', produccion: 'Produccion' };
    var tocados = {};

    function valor(id) {
        var el = document.getElementById(id);
        return el ? el.value.trim() : '';
    }
    function secretoGuardado(amb) {
        var fs = form.querySelector('[data-llaves="' + amb + '"]');
        return !!fs && fs.getAttribute('data-secreto-guardado') === '1';
    }
    function ambienteElegido() {
        var r = form.querySelector('input[name="ambiente_activo"]:checked');
        return r ? r.value : 'sandbox';
    }
    function llavesListas(amb) {
        return valor('apikey_' + amb) !== '' && (valor('secreto_' + amb) !== '' || secretoGuardado(amb));
    }

    /* Mismas reglas que el servidor, con los mismos textos. */
    var reglas = {
        url_retorno: function () {
            var v = valor('url_retorno');
            return v !== '' && v.indexOf('https://') !== 0
                ? 'La direccion tiene que empezar por https://, por ejemplo https://tuempresa.cl/gracias.' : '';
        }
    };
    ['sandbox', 'produccion'].forEach(function (amb) {
        reglas['apikey_' + amb] = function () {
            return valor('apikey_' + amb) === '' && valor('secreto_' + amb) !== ''
                ? 'Pega la API key de ' + rotulos[amb] + ': la Secret key sola no sirve para cobrar.' : '';
        };
        reglas['secreto_' + amb] = function () {
            return valor('apikey_' + amb) !== '' && valor('secreto_' + amb) === '' && !secretoGuardado(amb)
                ? 'Pega la Secret key de ' + rotulos[amb] + ': una API key sin su secreto no sirve para cobrar.' : '';
        };
    });

    function pintar(campo, msg) {
        var el = document.getElementById(campo);
        var err = document.getElementById('error-' + campo);
        if (!el || !err) { return; }
        err.querySelector('span').textContent = msg;
        err.hidden = msg === '';
        var ayuda = document.getElementById('ayuda-' + campo);
        if (ayuda) { ayuda.hidden = msg !== ''; }
        el.closest('.form-campo').classList.toggle('form-campo--error', msg !== '');
        if (msg === '') { el.removeAttribute('aria-invalid'); } else { el.setAttribute('aria-invalid', 'true'); }
    }

    /* Primer aviso al salir; desde ahi, se revalida mientras se corrige. */
    Object.keys(reglas).forEach(function (campo) {
        var el = document.getElementById(campo);
        if (!el) { return; }
        el.addEventListener('blur', function () {
            if (el.value.trim() === '' && !tocados[campo]) { return; }
            tocados[campo] = true;
            pintar(campo, reglas[campo]());
        });
        el.addEventListener('input', function () {
            if (tocados[campo]) { pintar(campo, reglas[campo]()); }
        });
    });

    /* El interruptor dice que va a pasar, y de que depende. */
    var sw = document.getElementById('habilitado');
    var detalle = document.getElementById('detalle-habilitado');
    function actualizarInterruptor() {
        var amb = ambienteElegido();
        var listas = llavesListas(amb);
        var clave = listas ? (sw.checked ? 'on' : 'off') : (sw.checked ? 'faltan' : 'bloqueado');
        // Solo se deshabilita APAGADO: deshabilitarlo encendido lo sacaria del
        // POST y guardaria "apagado" sin que nadie lo pidiera.
        sw.disabled = !listas && !sw.checked;
        detalle.textContent = detalle.getAttribute('data-' + clave).replace('{amb}', rotulos[amb]);
    }
    form.addEventListener('input', actualizarInterruptor);
    form.addEventListener('change', actualizarInterruptor);
    actualizarInterruptor();

    /* Al enviar: mismo resumen que el del servidor y foco al primer problema. */
    var resumen = document.getElementById('resumen-errores');
    var etiquetas = {
        apikey_sandbox: 'API key de Sandbox', secreto_sandbox: 'Secret key de Sandbox',
        apikey_produccion: 'API key de Produccion', secreto_produccion: 'Secret key de Produccion',
        url_retorno: 'Pagina de regreso'
    };
    var boton = document.getElementById('guardar-pagos');

    /* ESPERA AL GUARDAR -- seccion 14 del design system, Efectos de carga.
       - El doble envio se corta EN EL ACTO, pero sin nada visible: un
         indicador que aparece y se va en menos de 300 ms se lee como un
         parpadeo defectuoso, no como carga.
       - Pasados 300 ms, el boton entra en carga: spinner DENTRO del boton,
         texto transparente y mismo ancho (nunca "Guardando...", que lo
         encoge o lo estira), aria-busy en el boton y en el formulario.
       - La permanencia minima de 400 ms la da sola la navegacion: el spinner
         sigue hasta que el navegador pinta la pantalla siguiente.
       - El resultado lo anuncia la pantalla de llegada: "Cambios guardados"
         (role="status") o el resumen de errores (role="alert").
       - El foco no se mueve durante la espera. */
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
    /* Volver con "Atras" restaura la pagina de la cache del navegador tal como
       quedo: con el boton girando para siempre. */
    window.addEventListener('pageshow', function (ev) {
        if (ev.persisted) { clearTimeout(umbral); enviando = false; ocupado(false); }
    });

    form.addEventListener('submit', function (ev) {
        if (enviando) { ev.preventDefault(); return; }
        var malos = [];
        Object.keys(reglas).forEach(function (campo) {
            var msg = reglas[campo]();
            tocados[campo] = true;
            pintar(campo, msg);
            if (msg !== '') { malos.push([campo, msg]); }
        });

        if (malos.length > 0) {
            ev.preventDefault();
            // En el orden de la pantalla: el foco va al PRIMER campo que se ve.
            malos.sort(function (x, y) {
                return document.getElementById(x[0]).compareDocumentPosition(document.getElementById(y[0]))
                    & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1;
            });
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
                ? 'Hay 1 campo por revisar antes de guardar'
                : 'Hay ' + malos.length + ' campos por revisar antes de guardar';
            resumen.hidden = false;
            document.getElementById(malos[0][0]).focus();
            return;
        }

        // Un doble clic no puede mandar dos veces las llaves.
        enviando = true;
        umbral = setTimeout(function () { ocupado(true); }, 300);
    });

    /* Tras un POST con errores, el foco va directo al primer campo con problema. */
    var primero = form.querySelector('[aria-invalid="true"]');
    if (primero) {
        var destino = primero.matches('fieldset') ? primero.querySelector('input:checked') : primero;
        if (destino) { destino.focus(); }
    }
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
