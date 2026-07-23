<?php $titulo = 'API keys'; require __DIR__ . '/partials/header.php'; ?>

<h1>API keys</h1>
<p>Estas keys autentican tus llamadas al motor de facturacion (header
<code>X-Api-Key</code>). Por ahora solo se generan keys de <strong>certificacion</strong>.</p>

<?php if (! empty($error)): ?>
<p class="errores"><?= htmlspecialchars($error); ?></p>
<?php endif; ?>

<?php if ($keyNueva !== null): ?>
<div style="border:2px solid #2e7d32;border-radius:6px;padding:1rem;margin:1rem 0;background:#f1f8f1;">
    <strong>Copia esta key ahora; no podras verla de nuevo.</strong>
    <p id="key-nueva" style="font-family:monospace;word-break:break-all;background:#fff;padding:0.5rem;border:1px solid #ccc;border-radius:4px;"><?= htmlspecialchars($keyNueva); ?></p>
    <button type="button" onclick="copiarKeyNueva(this)">Copiar</button>
</div>
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

<h2>Generar una key nueva</h2>
<form method="post" action="/apikeys/generar">
    <?= csrfInput(); ?>
    <button type="submit">Generar API key de certificacion</button>
</form>

<h2>Keys existentes</h2>
<?php if ($keys === []): ?>
<p>Aun no tienes ninguna API key generada.</p>
<?php else: ?>
<table>
    <thead>
        <tr>
            <th>Prefijo</th>
            <th>Ambiente</th>
            <th>Estado</th>
            <th>Creada</th>
            <th>Ultimo uso</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($keys as $k): ?>
        <tr<?= $k['estado'] === 'revocada' ? ' style="color:#999;"' : ''; ?>>
            <td><?= htmlspecialchars((string) $k['prefijo']); ?></td>
            <td><?= htmlspecialchars((string) $k['ambiente']); ?></td>
            <td><?= htmlspecialchars((string) $k['estado']); ?></td>
            <td><?= htmlspecialchars((string) $k['created_at']); ?></td>
            <td><?= htmlspecialchars((string) ($k['last_used_at'] ?? 'nunca')); ?></td>
            <td>
                <?php if ($k['estado'] === 'activa'): ?>
                <form method="post" action="/apikeys/revocar" style="margin:0;"
                      onsubmit="return confirm('Revocar esta API key? No se puede deshacer.');">
                    <?= csrfInput(); ?>
                    <input type="hidden" name="id" value="<?= (int) $k['id']; ?>">
                    <button type="submit">Revocar</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<p><a href="/panel">Volver al panel</a></p>

<?php require __DIR__ . '/partials/footer.php'; ?>
