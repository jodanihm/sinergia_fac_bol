<?php
/**
 * Tarjeta de un libro de la certificacion (Ventas o Compras). Los dos eran el
 * mismo marcado copiado; ahora son este partial con cuatro parametros, que
 * fijan _tarjeta-libro-ventas.php y _tarjeta-libro-compras.php:
 *
 *   $libroNombre    'Ventas' | 'Compras'
 *   $libroAprobado  array{aprobado:bool, trackId:?string}
 *   $libroEnvios    list<array> historial de envios
 *   $libroAccion    ruta del POST de emision
 *
 * Usa ademas $setBasico y $setBasicoSinSok, del controlador.
 */
$libroId = 'libro-' . strtolower($libroNombre);
?>
<section class="tarjeta" aria-labelledby="titulo-<?= $libroId; ?>">
    <h2 id="titulo-<?= $libroId; ?>">Libro de <?= $libroNombre; ?></h2>

    <?php if ($libroAprobado['aprobado']): ?>
    <div class="alerta alerta--exito">
        <?= iconoSvg('check', 20, 'alerta__icono'); ?>
        <div class="alerta__cuerpo">
            <p class="alerta__titulo">Aprobado</p>
            <p>El envio <?= htmlspecialchars((string) $libroAprobado['trackId']); ?> fue aceptado por el SII (LOK/LTC).</p>
        </div>
    </div>
    <?php else: ?>
    <div class="alerta alerta--info">
        <?= iconoSvg('sin-cargar', 20, 'alerta__icono'); ?>
        <div class="alerta__cuerpo">
            <p class="alerta__titulo">Pendiente</p>
        <p>Aun no hay un envio del Libro de <?= $libroNombre; ?> aceptado por el SII (LOK/LTC).</p>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($setBasico['aprobado']): ?>
    <form method="post" action="<?= $libroAccion; ?>" class="form-compacto" data-espera
          data-espera-texto="Enviando el Libro de <?= $libroNombre; ?> al SII. Puede tardar hasta un minuto: no cierres esta pagina."
          data-confirmar-titulo="Enviar el Libro de <?= $libroNombre; ?> al SII?"
          data-confirmar-texto="Se envia como libro TOTAL del periodo de certificacion. Si despues hay que corregirlo, el reenvio va como RECTIFICA."
          data-confirmar-boton="Si, enviar el libro">
        <?= csrfInput(); ?>
        <div class="acciones-formulario">
            <button type="submit" class="boton-secundario">Enviar Libro de <?= $libroNombre; ?></button>
        </div>
    </form>
    <?php else: ?>
    <p class="tarjeta__intro">Se habilita cuando el Set Basico este aprobado (EPR con SOK).</p>
    <?php if ($setBasicoSinSok['aprobado']): ?>
    <div class="alerta alerta--advertencia">
        <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
        <div class="alerta__cuerpo">
            <p class="alerta__titulo">Puedes enviarlo antes del SOK, con riesgo</p>
            <p>El SII acepto tecnicamente el Set Basico (EPR con los 3 tipos) pero aun no confirma el
            contenido. Si despues lo rechaza, tendras que rehacer el Set Basico y este libro.</p>
        </div>
    </div>
    <form method="post" action="<?= $libroAccion; ?>" class="form-compacto" data-espera
          data-espera-texto="Enviando el Libro de <?= $libroNombre; ?> al SII. Puede tardar hasta un minuto: no cierres esta pagina."
          data-confirmar-titulo="Enviar el Libro de <?= $libroNombre; ?> sin esperar el SOK?"
          data-confirmar-texto="Si el SII rechaza despues el Set Basico, tendras que rehacer el Set Basico y este libro."
          data-confirmar-boton="Si, enviar sin esperar">
        <?= csrfInput(); ?>
        <input type="hidden" name="modo" value="sin_sok">
        <div class="form-campo">
            <label class="form-check" for="acepto-<?= $libroId; ?>">
                <input type="checkbox" name="acepto_riesgo" id="acepto-<?= $libroId; ?>" value="1"
                       data-requerido="Marca la casilla para confirmar que entiendes el riesgo.">
                Entiendo el riesgo y quiero enviarlo de todas formas
            </label>
        </div>
        <div class="acciones-formulario">
            <button type="submit" class="boton-secundario">Enviar sin esperar el SOK</button>
        </div>
    </form>
    <?php endif; ?>
    <?php endif; ?>

    <h3 class="titulo-seccion">Historial de envios (<?= count($libroEnvios); ?>)</h3>
    <?php if ($libroEnvios === []): ?>
        <p class="tarjeta__intro">Todavia no envias ningun Libro de <?= $libroNombre; ?>.</p>
    <?php else: ?>
    <table class="tabla-datos tabla-datos--cards">
        <thead>
            <tr>
                <th scope="col">Track ID</th>
                <th scope="col">Enviado</th>
                <th scope="col">Periodo y tipo</th>
                <th scope="col">Estado SII</th>
                <th scope="col" class="tabla-datos__acciones">Acciones</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($libroEnvios as $libro): ?>
            <tr>
                <td data-label="Track ID">
                    <?php if (! empty($libro['track_id'])): ?>
                        <code><?= htmlspecialchars((string) $libro['track_id']); ?></code>
                    <?php else: ?>
                        <span class="tabla-datos__secundario">Sin Track ID</span>
                    <?php endif; ?>
                </td>
                <td data-label="Enviado"><?= htmlspecialchars((string) $libro['created_at']); ?></td>
                <td data-label="Periodo y tipo"><?= htmlspecialchars($libro['periodo_tributario'] . ' (' . $libro['tipo_libro'] . '/' . $libro['tipo_envio'] . ')'); ?></td>
                <td data-label="Estado SII" class="tabla-datos__estado"><span class="badge badge--etiqueta"><?= htmlspecialchars((string) $libro['estado']); ?></span></td>
                <td data-label="" class="tabla-datos__acciones">
                    <?php if (! empty($libro['track_id'])): ?>
                    <form method="post" action="/certificacion/actualizar-libro" class="form-en-linea" data-espera
                          data-espera-texto="Consultando el estado al SII. Puede tardar hasta un minuto: no cierres esta pagina.">
                        <?= csrfInput(); ?>
                        <input type="hidden" name="track_id" value="<?= htmlspecialchars((string) $libro['track_id']); ?>">
                        <button type="submit" class="boton-secundario" aria-label="Actualizar estado del libro <?= htmlspecialchars((string) $libro['track_id']); ?>">Actualizar estado</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>
