<?php
/**
 * Configuracion > Produccion > API keys (ambiente de PRODUCCION).
 *
 * Gemela de apikeys.php: mismas variables ($keys, $keyNueva, $error), mismo
 * listado, mismo bloque de secreto unico, el MISMO JavaScript de copia (id
 * "key-nueva" y onclick copiarKeyNueva textuales) y la misma confirmacion de
 * revocar. Cambian las rutas, el badge, los textos y el aviso de produccion,
 * que va arriba: estas keys emiten documentos tributarios reales.
 *
 * Mismas garantias que en certificacion: el secreto aparece una sola vez en el
 * DOM, no se trunca ni se duplica en atributos, el fallback a execCommand se
 * conserva porque el panel corre sobre HTTP plano, y una key revocada sigue
 * siendo legible. Ver el comentario de apikeys.php para el resto.
 */
$titulo = 'API keys (PRODUCCION)';
require __DIR__ . '/partials/header.php';

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

/** Badge del estado. ENUM cerrado; el default cubre un valor inesperado. */
$badgeEstado = static function (string $estado): array {
    return match ($estado) {
        'activa'   => ['badge--ok', 'Activa', 'check'],
        'revocada' => ['badge--neutro', 'Revocada', 'sin-cargar'],
        default    => ['badge--neutro', $estado, 'sin-cargar'],
    };
};
/** Fecha en formato chileno (dd/mm/aaaa hh:mm). Si no se puede leer, va tal cual. */
$fecha = static function (?string $v): string {
    if ($v === null || $v === '') {
        return 'Nunca';
    }
    $t = strtotime($v);
    return $t === false ? $v : date('d/m/Y H:i', $t);
};
?>

<div class="dash-header">
    <div>
        <h1>API keys <span class="badge badge--advertencia">Produccion</span></h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <form method="post" action="/apikeys-produccion/generar" id="form-generar" data-espera>
            <?= csrfInput(); ?>
            <button type="submit" class="boton-principal" data-espera-boton>Generar API key</button>
        </form>
    </div>
</div>
<p class="dash-subtitulo">
    Autentican las llamadas de tus sistemas al motor de facturacion (header <code>X-Api-Key</code>) en produccion.
</p>

<div class="alerta alerta--advertencia">
    <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
    <div class="alerta__cuerpo">
        <p class="alerta__titulo">Emiten documentos reales</p>
        <p>Cada documento que se emita con estas keys es un documento tributario real ante el SII, no de prueba.
        Protegelas igual que una contrasena.</p>
    </div>
</div>

<?php if (! empty($flash['mensaje'])): ?>
    <p class="alerta alerta--<?= ($flash['tipo'] ?? '') === 'exito' ? 'exito' : 'error'; ?>" role="status">
        <?= iconoSvg(($flash['tipo'] ?? '') === 'exito' ? 'check' : 'alerta', 20, 'alerta__icono'); ?>
        <span><?= $e((string) $flash['mensaje']); ?></span>
    </p>
<?php endif; ?>

<?php if (! empty($error)): ?>
    <p class="alerta alerta--error" role="alert">
        <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
        <span><?= $e((string) $error); ?></span>
    </p>
<?php endif; ?>

<?php if ($keyNueva !== null): ?>
<section class="tarjeta" aria-labelledby="titulo-key-nueva">
    <h2 id="titulo-key-nueva">Tu nueva API key de produccion</h2>
    <div class="alerta alerta--advertencia" role="status">
        <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
        <div class="alerta__cuerpo">
            <p class="alerta__titulo">Copiala ahora: no vas a poder verla de nuevo</p>
            <p>Guardala en tu gestor de credenciales. Desde aqui solo volveras a ver su prefijo.</p>
        </div>
    </div>
    <p id="key-nueva" class="secreto-unico"><?= htmlspecialchars($keyNueva); ?></p>
    <div class="acciones-grupo">
        <button type="button" class="boton-secundario" onclick="copiarKeyNueva(this)">Copiar key</button>
    </div>
</section>
<script>
    // navigator.clipboard.writeText() exige "contexto seguro" (HTTPS o
    // localhost) -- el panel corre hoy en LAN por HTTP plano, asi que ese
    // API no existe (o writeText() rechaza) y la copia fallaba en silencio,
    // sin avisar nada. Fallback al metodo clasico (textarea oculto +
    // execCommand('copy')) cuando el moderno no esta disponible, con
    // feedback real en AMBOS caminos (nunca decir "Copiada" si no se sabe
    // que funciono).
    function copiarKeyNueva(boton) {
        var texto = document.getElementById('key-nueva').textContent.trim();
        var textoOriginal = boton.textContent;

        function avisar(ok) {
            boton.textContent = ok ? 'Copiada' : 'No se pudo copiar';
            setTimeout(function () {
                boton.textContent = textoOriginal;
            }, 1500);
        }

        function copiarClasico() {
            var textarea = document.createElement('textarea');
            textarea.value = texto;
            textarea.style.position = 'fixed';
            textarea.style.left = '-9999px';
            textarea.style.top = '0';
            document.body.appendChild(textarea);
            textarea.focus();
            textarea.select();
            var ok = false;
            try {
                ok = document.execCommand('copy');
            } catch (e) {
                ok = false;
            }
            document.body.removeChild(textarea);
            avisar(ok);
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(texto).then(function () {
                avisar(true);
            }).catch(copiarClasico);
        } else {
            copiarClasico();
        }
    }
</script>
<?php endif; ?>

<div class="layout-principal-lateral">
    <div>
    <section class="tarjeta" aria-labelledby="titulo-keys">
        <h2 id="titulo-keys">Keys existentes</h2>
        <?php if ($keys === []): ?>
            <div class="estado-vacio estado-vacio--centrado">
                <?= iconoSvg('apikeys', 32, 'estado-vacio__icono'); ?>
                <h3>Todavia no tienes API keys de produccion</h3>
                <p>Genera una para que tus sistemas puedan emitir documentos reales a traves del motor.</p>
                <p class="estado-vacio__acciones">
                    <button type="submit" form="form-generar" class="boton-secundario">Generar la primera key</button>
                </p>
            </div>
        <?php else: ?>
            <div class="tabla-scroll">
                <table class="tabla-datos tabla-datos--cards">
                    <caption><?= count($keys); ?> key<?= count($keys) === 1 ? '' : 's'; ?></caption>
                    <thead>
                        <tr>
                            <th scope="col">Prefijo</th>
                            <th scope="col" class="tabla-datos__estado">Estado</th>
                            <th scope="col">Creada</th>
                            <th scope="col">Ultimo uso</th>
                            <th scope="col" class="tabla-datos__acciones"><span class="visualmente-oculto">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($keys as $k): ?>
                            <?php [$claseBadge, $textoBadge, $iconoBadge] = $badgeEstado((string) $k['estado']); ?>
                            <tr<?= $k['estado'] === 'revocada' ? ' class="tabla-datos__fila--inactiva"' : ''; ?>>
                                <td data-label="Prefijo"><code><?= $e((string) $k['prefijo']); ?></code></td>
                                <td data-label="Estado" class="tabla-datos__estado"><span class="badge <?= $claseBadge; ?>"><?= iconoSvg($iconoBadge, 16, 'badge__icono'); ?><?= $e($textoBadge); ?></span></td>
                                <td data-label="Creada"><?= $e($fecha((string) $k['created_at'])); ?></td>
                                <td data-label="Ultimo uso"><?= $e($fecha($k['last_used_at'] ?? null)); ?></td>
                                <td class="tabla-datos__acciones">
                                    <?php if ($k['estado'] === 'activa'): ?>
                                    <form method="post" action="/apikeys-produccion/revocar" data-revocar="<?= $e((string) $k['prefijo']); ?>">
                                        <?= csrfInput(); ?>
                                        <input type="hidden" name="id" value="<?= (int) $k['id']; ?>">
                                        <button type="submit" class="boton-texto boton-texto--peligro"
                                                aria-label="Revocar la key <?= $e((string) $k['prefijo']); ?>">Revocar</button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    </div>

    <div class="panel-info">
        <p class="panel-info__titulo">
            <?= iconoSvg('info', 20, 'panel-info__icono'); ?>
            Como funcionan
        </p>
        <ul class="panel-info__lista">
            <li>Se envian en el header <code>X-Api-Key</code> de cada llamada al motor.</li>
            <li>El secreto completo se muestra una sola vez, al generarla.</li>
            <li>Despues solo queda visible el prefijo.</li>
            <li>Revocar una key es definitivo.</li>
        </ul>
    </div>
</div>

<dialog class="dialogo-confirmar" id="dialogo-revocar" aria-labelledby="dialogo-revocar-titulo">
    <h2 class="dialogo-confirmar__titulo" id="dialogo-revocar-titulo">Revocar la key de produccion <span data-dialogo-prefijo></span></h2>
    <p>Los sistemas que la usen dejan de poder emitir documentos reales desde ese momento.</p>
    <p>No se puede deshacer: para volver a conectarlos tendras que generar una key nueva.</p>
    <div class="acciones-formulario">
        <button type="button" class="boton-secundario" data-dialogo-volver>Volver</button>
        <button type="button" class="boton-peligro" data-dialogo-confirmar>Si, revocar la key</button>
    </div>
</dialog>

<script>
/* Espera de los envios (seccion 14) y confirmacion de revocar con <dialog>. */
(function () {
    /* Boton trabajando: nada visible bajo 300 ms; despues spinner dentro del
       boton (mismo ancho), aria-busy y disabled. */
    var umbrales = [];
    function ocupado(form, boton, si) {
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
    function esperar(form, boton) {
        form.setAttribute('data-enviando', '1');
        umbrales.push(setTimeout(function () { ocupado(form, boton, true); }, 300));
    }
    /* Volver con Atras restaura la pagina tal como quedo: sin esto, el boton
       seguiria girando y el dialogo abierto. */
    window.addEventListener('pageshow', function (ev) {
        if (!ev.persisted) { return; }
        umbrales.forEach(clearTimeout);
        document.querySelectorAll('form').forEach(function (f) {
            f.removeAttribute('data-enviando');
            f.removeAttribute('data-confirmado');
            f.removeAttribute('aria-busy');
        });
        document.querySelectorAll('[data-cargando]').forEach(function (b) {
            b.removeAttribute('data-cargando');
            b.removeAttribute('aria-busy');
            b.disabled = false;
        });
        var d = document.getElementById('dialogo-revocar');
        if (d.open) { d.close(); }
    });

    var generar = document.getElementById('form-generar');
    generar.addEventListener('submit', function (ev) {
        if (generar.hasAttribute('data-enviando')) { ev.preventDefault(); return; }
        esperar(generar, generar.querySelector('[data-espera-boton]'));
    });

    var dialogo = document.getElementById('dialogo-revocar');
    var confirmar = dialogo.querySelector('[data-dialogo-confirmar]');
    var pendiente = null;
    var tieneDialogo = typeof dialogo.showModal === 'function';

    document.querySelectorAll('form[data-revocar]').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            if (form.hasAttribute('data-confirmado')) { return; }
            ev.preventDefault();
            var prefijo = form.getAttribute('data-revocar');
            if (!tieneDialogo) {
                if (window.confirm('Revocar la key de produccion ' + prefijo + '? Los sistemas que la usen dejan de poder llamar al motor. No se puede deshacer.')) {
                    form.setAttribute('data-confirmado', '1');
                    form.submit();
                }
                return;
            }
            pendiente = form;
            dialogo.querySelector('[data-dialogo-prefijo]').textContent = prefijo;
            dialogo.showModal();
        });
    });
    dialogo.querySelector('[data-dialogo-volver]').addEventListener('click', function () { dialogo.close(); });
    dialogo.addEventListener('close', function () {
        if (pendiente && !pendiente.hasAttribute('data-confirmado')) {
            pendiente.querySelector('button[type="submit"]').focus();
            pendiente = null;
        }
    });
    confirmar.addEventListener('click', function () {
        if (!pendiente || pendiente.hasAttribute('data-confirmado')) { return; }
        pendiente.setAttribute('data-confirmado', '1');
        esperar(pendiente, confirmar);
        pendiente.submit();
    });
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
