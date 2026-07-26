<?php
/**
 * Configuracion > Produccion > API keys (ambiente de PRODUCCION).
 *
 * Estructuralmente identica a apikeys.php: mismas variables ($keys, $keyNueva,
 * $error), mismo listado, mismo bloque de secreto unico y el MISMO JavaScript de
 * copia. Cambian las rutas del action, el texto de los botones, el color del
 * badge y el panel lateral, que aqui advierte que las keys operan en produccion.
 *
 * Mismas garantias que en certificacion: el secreto aparece una sola vez en el
 * DOM, no se trunca ni se duplica en atributos, el fallback a execCommand se
 * conserva porque el panel corre sobre HTTP plano, y una key revocada sigue
 * siendo legible.
 */
$titulo = 'API keys (PRODUCCION)';
require __DIR__ . '/partials/header.php';

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
        <h1>API keys <span class="badge badge--advertencia">Produccion</span></h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <a class="boton-secundario" href="/panel">Volver al panel</a>
    </div>
</div>
<p class="dash-subtitulo">
    Estas keys autentican tus llamadas al motor de facturacion mediante el header
    <code>X-Api-Key</code> en produccion: cada documento que emitas con ellas es un
    documento tributario real ante el SII, no de prueba.
</p>

<?php if (! empty($error)): ?>
    <p class="alerta alerta--error" role="alert">
        <span class="alerta__icono" aria-hidden="true">&#9888;</span>
        <span><?= htmlspecialchars($error); ?></span>
    </p>
<?php endif; ?>

<?php if ($keyNueva !== null): ?>
<section class="tarjeta" aria-labelledby="titulo-key-nueva">
    <h2 id="titulo-key-nueva">Tu nueva API key de produccion</h2>
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
                    <h2>Aun no hay API keys de produccion</h2>
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
                                        <form method="post" action="/apikeys-produccion/revocar" style="margin:0;display:inline;"
                                              onsubmit="return confirm('Revocar esta API key de produccion? No se puede deshacer.');">
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
        <div class="panel-info panel-info--advertencia">
            <p class="panel-info__titulo">
                <span class="panel-info__icono" aria-hidden="true">&#9888;</span>
                Ambiente de produccion
            </p>
            <p>Estas credenciales autorizan emision <strong>REAL</strong> de documentos
            tributarios. Protegelas igual que una contrasena.</p>
            <p>El secreto completo se muestra una sola vez, al generarlo; despues solo queda
            visible el prefijo.</p>
            <p>Revocar una key es definitivo.</p>
        </div>

        <section class="tarjeta" aria-labelledby="titulo-generar">
            <h2 id="titulo-generar">Generar una key nueva</h2>
            <form method="post" action="/apikeys-produccion/generar" class="form-compacto">
                <?= csrfInput(); ?>
                <div class="acciones-grupo">
                    <button type="submit" class="boton-principal">Generar API key de produccion</button>
                </div>
            </form>
        </section>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
