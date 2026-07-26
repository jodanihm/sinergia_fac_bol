<?php
/**
 * Configuracion > API keys (ambiente de CERTIFICACION).
 *
 * Recibe: $keys (list<array>), $keyNueva (string|null) y $error (string|null).
 * Cada key trae id, prefijo, ambiente, estado, last_used_at y created_at,
 * ordenadas por created_at DESC. $keyNueva solo llega tras generar y vale
 * "prefijo.secreto": es la UNICA vez que el secreto existe fuera del hash.
 *
 * EL SECRETO APARECE UNA SOLA VEZ EN EL DOM, dentro de #key-nueva. No se
 * duplica en value, data-*, title ni en ningun otro atributo, no se trunca y no
 * se guarda en almacenamiento del navegador. Truncarlo impediria copiarlo.
 *
 * ESTADO: ENUM cerrado 'activa'|'revocada'. Una key revocada es un estado final
 * valido, no un error, y sus datos siguen siendo legibles: se atenua con
 * .tabla-datos__fila--inactiva (#6e6e6e sobre #fafafa, 4.89:1) en vez del
 * color:#999 anterior, que daba 2.37:1 y no cumplia AA.
 *
 * EL JAVASCRIPT DE COPIA NO SE TOCA. El panel corre sobre HTTP plano en LAN, y
 * ahi navigator.clipboard no existe: el fallback a execCommand es la unica via
 * que funciona. Los ganchos que la funcion necesita son el id "key-nueva" y el
 * onclick del boton; ambos se conservan textuales.
 */
$titulo = 'API keys';
require __DIR__ . '/partials/header.php';

/** Badge del estado. ENUM cerrado; el default cubre un valor inesperado. */
$badgeEstado = static function (string $estado): array {
    return match ($estado) {
        'activa'   => ['badge--ok', 'Activa'],
        'revocada' => ['badge--neutro', 'Revocada'],
        default    => ['badge--neutro', $estado],
    };
};
?>

<div class="dash-header">
    <div>
        <h1>API keys <span class="badge badge--etiqueta">Certificacion</span></h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <a class="boton-secundario" href="/panel">Volver al panel</a>
    </div>
</div>
<p class="dash-subtitulo">
    Estas keys autentican tus llamadas al motor de facturacion mediante el header
    <code>X-Api-Key</code> en el ambiente de certificacion.
</p>

<?php if (! empty($error)): ?>
    <p class="alerta alerta--error" role="alert">
        <span class="alerta__icono" aria-hidden="true">&#9888;</span>
        <span><?= htmlspecialchars($error); ?></span>
    </p>
<?php endif; ?>

<?php if ($keyNueva !== null): ?>
<section class="tarjeta" aria-labelledby="titulo-key-nueva">
    <h2 id="titulo-key-nueva">Tu nueva API key</h2>
    <p class="alerta alerta--exito" role="status">
        <span class="alerta__icono" aria-hidden="true">&#9888;</span>
        <span><strong>Copia esta key ahora; no podras verla de nuevo.</strong></span>
    </p>
    <p id="key-nueva" class="secreto-unico"><?= htmlspecialchars($keyNueva); ?></p>
    <div class="acciones-grupo">
        <button type="button" class="boton-secundario" onclick="copiarKeyNueva(this)">Copiar</button>
    </div>
    <p class="nota">Guardala en tu gestor de credenciales. Desde aqui solo volveras a ver su prefijo.</p>
</section>
<script>
    // navigator.clipboard.writeText() exige "contexto seguro" (HTTPS o
    // localhost) -- el panel corre hoy en LAN por HTTP plano, asi que ese
    // API no existe (o writeText() rechaza) y la copia fallaba en silencio,
    // sin avisar nada. Fallback al metodo clasico (textarea oculto +
    // execCommand('copy')) cuando el moderno no esta disponible, con
    // feedback real en AMBOS caminos (nunca decir "Copiado!" si no se sabe
    // que funciono).
    function copiarKeyNueva(boton) {
        var texto = document.getElementById('key-nueva').textContent.trim();
        var textoOriginal = boton.textContent;

        function avisar(ok) {
            boton.textContent = ok ? 'Copiado!' : 'No se pudo copiar';
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
                <div class="estado-vacio">
                    <h2>Aun no hay API keys en este ambiente</h2>
                    <p>Genera una para conectar tus sistemas al motor de facturacion.</p>
                </div>
            <?php else: ?>
                <div class="tabla-scroll">
                    <table class="tabla-datos">
                        <caption><?= count($keys); ?> key<?= count($keys) === 1 ? '' : 's'; ?></caption>
                        <thead>
                            <tr>
                                <th>Prefijo</th>
                                <th>Ambiente</th>
                                <th class="tabla-datos__estado">Estado</th>
                                <th>Creada</th>
                                <th>Ultimo uso</th>
                                <th class="tabla-datos__acciones">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($keys as $k): ?>
                                <?php [$claseBadge, $textoBadge] = $badgeEstado((string) $k['estado']); ?>
                                <tr<?= $k['estado'] === 'revocada' ? ' class="tabla-datos__fila--inactiva"' : ''; ?>>
                                    <td><code><?= htmlspecialchars((string) $k['prefijo']); ?></code></td>
                                    <td><span class="badge badge--etiqueta"><?= htmlspecialchars((string) $k['ambiente']); ?></span></td>
                                    <td class="tabla-datos__estado"><span class="badge <?= $claseBadge; ?>"><?= htmlspecialchars($textoBadge); ?></span></td>
                                    <td><?= htmlspecialchars((string) $k['created_at']); ?></td>
                                    <td><?= htmlspecialchars((string) ($k['last_used_at'] ?? 'nunca')); ?></td>
                                    <td class="tabla-datos__acciones">
                                        <?php if ($k['estado'] === 'activa'): ?>
                                        <form method="post" action="/apikeys/revocar" style="margin:0;display:inline;"
                                              onsubmit="return confirm('Revocar esta API key? No se puede deshacer.');">
                                            <?= csrfInput(); ?>
                                            <input type="hidden" name="id" value="<?= (int) $k['id']; ?>">
                                            <button type="submit" class="boton-texto">Revocar</button>
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

    <div>
        <div class="panel-info">
            <p class="panel-info__titulo">
                <span class="panel-info__icono" aria-hidden="true">&#9432;</span>
                Ambiente de certificacion
            </p>
            <ul class="panel-info__lista">
                <li>Autentican tus llamadas al motor con el header <code>X-Api-Key</code>.</li>
                <li>El secreto completo se muestra una sola vez, al generarlo.</li>
                <li>Despues solo queda visible el prefijo.</li>
                <li>Revocar una key es definitivo.</li>
            </ul>
        </div>

        <section class="tarjeta" aria-labelledby="titulo-generar">
            <h2 id="titulo-generar">Generar una key nueva</h2>
            <form method="post" action="/apikeys/generar" class="form-compacto">
                <?= csrfInput(); ?>
                <div class="acciones-grupo">
                    <button type="submit" class="boton-principal">Generar API key de certificacion</button>
                </div>
            </form>
        </section>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
