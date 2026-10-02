<?php
/**
 * Estado y confirmacion de una etapa que se hace FUERA del panel (Simulacion,
 * Intercambio, Muestras Impresas). Las tres eran el mismo marcado copiado;
 * ahora las arma este partial con:
 *
 *   $manualNombre     'Simulacion' | 'Intercambio' | 'Muestras Impresas'
 *   $manualClave      valor del hidden "etapa" del POST
 *   $manualDatos      $etapasManuales[...] (confirmada, habilitada, fecha, trackId?)
 *   $manualRequisito  que falta para habilitarla (texto)
 *   $manualConTrack   bool, si pide Track ID (solo Simulacion)
 *   $manualFemenino   bool, para "confirmada"/"confirmado"
 *
 * LA CASILLA ES LA CONFIRMACION. Antes ademas saltaba un confirm() del
 * navegador ("Aceptar"): dos confirmaciones para una declaracion propia
 * entrenan a apretar "Si" sin leer. Ahora la casilla es obligatoria y, si
 * falta, se dice bajo ella y el foco vuelve a ella.
 */
$d = $manualDatos;
$manualId = 'confirmo-' . $manualClave;
?>
<?php if ($d['confirmada']): ?>
    <div class="alerta alerta--exito">
        <?= iconoSvg('check', 20, 'alerta__icono'); ?>
        <div class="alerta__cuerpo">
            <p class="alerta__titulo"><?= $manualNombre; ?> <?= $manualFemenino ? 'confirmada' : 'confirmado'; ?></p>
            <p>
                El <?= htmlspecialchars((string) $d['fecha']); ?>
                <?php if (! empty($d['trackId'])): ?>
                    &middot; Track ID <code><?= htmlspecialchars((string) $d['trackId']); ?></code>
                <?php endif; ?>
            </p>
        </div>
    </div>
<?php elseif ($d['habilitada']): ?>
    <form method="post" action="/certificacion/confirmar-etapa" class="form-compacto" data-espera>
        <?= csrfInput(); ?>
        <input type="hidden" name="etapa" value="<?= $manualClave; ?>">
        <?php if ($manualConTrack): ?>
        <div class="form-campo form-campo--corto">
            <label for="track-<?= $manualClave; ?>">Track ID (opcional)</label>
            <input type="text" name="track_id" id="track-<?= $manualClave; ?>" inputmode="numeric" autocomplete="off"
                   aria-describedby="ayuda-track-<?= $manualClave; ?>">
            <small class="form-ayuda" id="ayuda-track-<?= $manualClave; ?>">El del envio que el SII aprobo.</small>
        </div>
        <?php endif; ?>
        <div class="form-campo">
            <label class="form-check" for="<?= $manualId; ?>">
                <input type="checkbox" name="confirmo" value="1" id="<?= $manualId; ?>"
                       data-requerido="Marca la casilla: solo se confirma lo que ya completaste en el SII.">
                Ya complete <?= $manualNombre; ?> fuera del panel
            </label>
        </div>
        <div class="acciones-formulario">
            <button type="submit" class="boton-principal">Confirmar <?= $manualNombre; ?></button>
        </div>
    </form>
<?php else: ?>
    <div class="alerta alerta--info">
        <?= iconoSvg('sin-cargar', 20, 'alerta__icono'); ?>
        <div class="alerta__cuerpo">
            <p class="alerta__titulo">Todavia no se puede confirmar</p>
        <p><?= $manualRequisito; ?></p>
        </div>
    </div>
<?php endif; ?>
