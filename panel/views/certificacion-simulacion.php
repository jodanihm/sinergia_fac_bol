<?php
/**
 * Certificacion: generar y enviar el Set de Simulacion (etapa 2).
 *
 * A la izquierda se arma (cantidad + vista previa del conteo); a la derecha se
 * emite. Emitir quema folios reales del CAF de certificacion y le habla al
 * SII: se confirma nombrando la accion y, si pasa de 10 s, se dice que se esta
 * enviando (seccion 14 del design system).
 */
$titulo = 'Set de Simulacion';
require __DIR__ . '/partials/header.php';

$conteo = [33 => 0, 61 => 0, 56 => 0];
foreach ($documentos as $d) {
    $conteo[$d['tipoDte']]++;
}
$totalDocumentos = count($documentos);
$tieneLosTres    = $conteo[33] > 0 && $conteo[61] > 0 && $conteo[56] > 0;
$enRango         = $totalDocumentos >= 20 && $totalDocumentos <= 100;
$cumple          = $tieneLosTres && $enRango;
?>

<div class="dash-header">
    <div>
        <h1>Set de Simulacion</h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <a class="boton-secundario" href="/certificacion/etapa/2">Volver a la etapa 2</a>
    </div>
</div>
<p class="dash-subtitulo">
    Un envio de 20 a 100 documentos con factura, nota de credito y nota de debito, sin referencia al SET.
</p>

<?php require __DIR__ . '/partials/certificacion/_flash.php'; ?>

<div class="config-columnas config-columnas--2">
    <section class="tarjeta" aria-labelledby="titulo-armar">
        <h2 id="titulo-armar">Armar el lote</h2>
        <form method="get" action="/certificacion/simulacion" class="form-compacto" data-espera novalidate>
            <div class="form-campo form-campo--corto">
                <label for="total">Cantidad de documentos</label>
                <input type="number" name="total" id="total" min="20" max="100" step="1" inputmode="numeric"
                       value="<?= (int) $total; ?>" aria-describedby="ayuda-total error-total">
                <p class="campo-error" id="error-total" hidden><?= iconoSvg('alerta', 16, ''); ?><span></span></p>
                <small class="form-ayuda" id="ayuda-total">Entre 20 y 100.</small>
            </div>
            <div class="acciones-formulario">
                <button type="submit" class="boton-secundario">Actualizar vista previa</button>
            </div>
        </form>

        <?php if ($cumple): ?>
        <div class="alerta alerta--exito" role="status">
            <?= iconoSvg('check', 20, 'alerta__icono'); ?>
            <div class="alerta__cuerpo">
                <p class="alerta__titulo">Listo para enviar: <?= $totalDocumentos; ?> documentos</p>
                <p><?= $conteo[33]; ?> factura(s), <?= $conteo[61]; ?> nota(s) de credito y <?= $conteo[56]; ?> nota(s) de debito.</p>
            </div>
        </div>
        <?php else: ?>
        <div class="alerta alerta--error" role="status">
            <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
            <div class="alerta__cuerpo">
                <p class="alerta__titulo">Asi no se puede enviar: <?= $totalDocumentos; ?> documentos</p>
                <p><?= $conteo[33]; ?> factura(s), <?= $conteo[61]; ?> nota(s) de credito y <?= $conteo[56]; ?> nota(s) de debito.
                Tienen que ser entre 20 y 100 y estar los 3 tipos.</p>
            </div>
        </div>
        <?php endif; ?>
    </section>

    <section class="tarjeta" aria-labelledby="titulo-emitir">
        <h2 id="titulo-emitir">Emitir</h2>
        <p class="tarjeta__intro">
            Se envian los <?= $totalDocumentos; ?> documentos al SII en un solo envio. El SII valida
            estructura y volumen, no el texto de las glosas. Usa folios reales del CAF de certificacion.
        </p>
        <form method="post" action="/certificacion/simulacion/emitir" class="form-compacto" data-espera
              data-espera-texto="Enviando <?= $totalDocumentos; ?> documentos al SII. Puede tardar varios minutos: no cierres esta pagina."
              data-confirmar-titulo="Emitir el Set de Simulacion con <?= $totalDocumentos; ?> documentos?"
              data-confirmar-texto="Se envian al SII y se usan <?= $totalDocumentos; ?> folios reales del CAF de certificacion. Los folios usados no vuelven."
              data-confirmar-boton="Si, emitir simulacion">
            <?= csrfInput(); ?>
            <input type="hidden" name="total" value="<?= (int) $total; ?>">
            <div class="acciones-formulario">
                <button type="submit" class="boton-principal">Emitir Set de Simulacion</button>
            </div>
        </form>
    </section>
</div>

<details class="tarjeta">
    <summary>Ver el detalle de los <?= $totalDocumentos; ?> documentos</summary>
    <table class="tabla-datos tabla-datos--cards">
        <thead>
            <tr>
                <th scope="col" class="tabla-datos__num">N</th>
                <th scope="col">Tipo</th>
                <th scope="col">Glosa</th>
                <th scope="col" class="tabla-datos__num">Monto</th>
                <th scope="col">Referencia</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($documentos as $i => $d): ?>
            <tr>
                <td data-label="N" class="tabla-datos__num"><?= $i + 1; ?></td>
                <td data-label="Tipo"><?= htmlspecialchars(nombreTipoDte((int) $d['tipoDte'])); ?></td>
                <td data-label="Glosa"><?= htmlspecialchars($d['detalles'][0]['nombre']); ?></td>
                <td data-label="Monto" class="tabla-datos__num">$<?= number_format((int) $d['detalles'][0]['precioUnitario'], 0, ',', '.'); ?></td>
                <td data-label="Referencia">
                    <?php if (! empty($d['referencias'])): ?>
                        Doc <?= (int) $d['referencias'][0]['refIndiceLote'] + 1; ?>
                        (<?= htmlspecialchars((string) $d['referencias'][0]['razon']); ?>)
                    <?php else: ?>
                        <span class="tabla-datos__secundario">Sin referencia</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</details>

<?php require __DIR__ . '/partials/certificacion/_ux-formularios.php'; ?>

<script>
/* Cantidad: se valida al salir del campo y, desde ahi, mientras se corrige. */
(function () {
    var el = document.getElementById('total');
    var err = document.getElementById('error-total');
    var ayuda = document.getElementById('ayuda-total');
    var tocado = false;
    function revisar() {
        var v = el.value.trim();
        var n = Number(v);
        var msg = (v === '' || !Number.isInteger(n) || n < 20 || n > 100)
            ? 'Escribe un numero entero entre 20 y 100.' : '';
        err.querySelector('span').textContent = msg;
        err.hidden = msg === '';
        ayuda.hidden = msg !== '';
        if (msg) { el.setAttribute('aria-invalid', 'true'); } else { el.removeAttribute('aria-invalid'); }
        return msg === '';
    }
    el.addEventListener('blur', function () { tocado = true; revisar(); });
    el.addEventListener('input', function () { if (tocado) { revisar(); } });
    // En captura: corre ANTES que el patron de espera, para no dejar el boton girando.
    el.form.addEventListener('submit', function (ev) {
        if (!revisar()) { ev.preventDefault(); ev.stopImmediatePropagation(); el.focus(); }
    }, true);
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
