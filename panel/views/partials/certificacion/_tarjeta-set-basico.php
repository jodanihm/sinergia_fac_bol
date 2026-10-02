<?php
    $pendientesSok = array_filter(
        $envios,
        static fn (array $e): bool => $e['estado'] === 'EPR'
            && array_diff([33, 61, 56], $e['tipos']) === []
            && ! isset($sokPorTrackId[$e['trackId']])
    );
?>

<section class="tarjeta" aria-labelledby="titulo-set-basico">
    <h2 id="titulo-set-basico">Set Basico</h2>

    <?php if ($setBasico['aprobado']): ?>
    <div class="alerta alerta--exito">
        <?= iconoSvg('check', 20, 'alerta__icono'); ?>
        <div class="alerta__cuerpo">
            <p class="alerta__titulo">Aprobado</p>
            <p>El envio <?= htmlspecialchars($setBasico['trackId']); ?> fue aceptado por el SII (EPR) con
            factura, nota de credito y nota de debito, y lo marcaste como SOK.</p>
        </div>
    </div>
    <?php else: ?>
    <div class="alerta alerta--info">
        <?= iconoSvg('sin-cargar', 20, 'alerta__icono'); ?>
        <div class="alerta__cuerpo">
            <p class="alerta__titulo">Pendiente</p>
        <p>Ningun envio esta aprobado todavia. Para eso el SII tiene que aceptarlo (EPR) con los 3 tipos
        de documento, y despues tu lo marcas como SOK cuando te llegue el correo del SII para ese envio.
        EPR solo confirma que el envio se proceso bien, no que el SII aprobo el contenido.</p>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($pendientesSok !== []): ?>
    <div class="alerta alerta--advertencia">
        <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
        <div class="alerta__cuerpo">
            <p class="alerta__titulo"><?= count($pendientesSok); ?> envio(s) esperan que los marques como SOK</p>
            <p>Track ID: <?= htmlspecialchars(implode(', ', array_column($pendientesSok, 'trackId'))); ?>.
            Revisa tu correo del SII antes de marcarlos.</p>
        </div>
    </div>
    <?php endif; ?>

    <h3 class="titulo-seccion">Historial de envios (<?= count($envios); ?>)</h3>
    <?php if ($envios === []): ?>
        <div class="estado-vacio">
            <?= iconoSvg('enviar', 32, 'estado-vacio__icono'); ?>
            <p class="estado-vacio__titulo">Todavia no envias el Set Basico</p>
            <p>Se arma desde el archivo de pruebas que te entrega el SII. Subelo, revisa el preview y emitelo desde ahi.</p>
            <p><a class="boton-secundario" href="/certificacion/set-pruebas">Subir archivo de pruebas</a></p>
        </div>
    <?php else: ?>
    <table class="tabla-datos tabla-datos--cards">
        <thead>
            <tr>
                <th scope="col">Track ID</th>
                <th scope="col">Emitido</th>
                <th scope="col">Documentos</th>
                <th scope="col">Estado SII</th>
                <th scope="col">SOK (revision de contenido)</th>
                <th scope="col" class="tabla-datos__acciones">Acciones</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($envios as $envio): ?>
            <?php $tieneLosTres = array_diff([33, 61, 56], $envio['tipos']) === []; ?>
            <tr>
                <td data-label="Track ID"><code><?= htmlspecialchars($envio['trackId']); ?></code></td>
                <td data-label="Emitido"><?= htmlspecialchars((string) $envio['fechaEmision']); ?></td>
                <td data-label="Documentos"><?= htmlspecialchars($envio['resumen']); ?></td>
                <td data-label="Estado SII" class="tabla-datos__estado"><span class="badge badge--etiqueta"><?= htmlspecialchars((string) $envio['estado']); ?></span></td>
                <td data-label="SOK">
                    <?php if (isset($sokPorTrackId[$envio['trackId']])): ?>
                        <span class="badge badge--ok"><?= iconoSvg('check', 16, 'badge__icono'); ?>SOK confirmado</span>
                        <span class="tabla-datos__secundario"><?= htmlspecialchars($sokPorTrackId[$envio['trackId']]); ?></span>
                    <?php elseif ($envio['estado'] === 'EPR' && $tieneLosTres): ?>
                        <form method="post" action="/certificacion/marcar-sok" class="form-en-linea" data-espera
                              data-confirmar-titulo="Marcar el envio <?= htmlspecialchars($envio['trackId']); ?> como SOK?"
                              data-confirmar-texto="Hazlo solo si ya te llego el correo del SII con SOK para este Track ID. El panel lo da por aprobado y habilita las etapas siguientes."
                              data-confirmar-boton="Si, marcar como SOK">
                            <?= csrfInput(); ?>
                            <input type="hidden" name="track_id" value="<?= htmlspecialchars($envio['trackId']); ?>">
                            <button type="submit" class="boton-secundario">Marcar como SOK</button>
                        </form>
                        <span class="tabla-datos__secundario">Solo con el correo "Resultado de Revision del Set de Prueba" en la mano.</span>
                    <?php else: ?>
                        <span class="tabla-datos__secundario">No aplica</span>
                    <?php endif; ?>
                </td>
                <td data-label="" class="tabla-datos__acciones">
                    <form method="post" action="/certificacion/actualizar" class="form-en-linea" data-espera
                          data-espera-texto="Consultando el estado al SII. Puede tardar hasta un minuto: no cierres esta pagina.">
                        <?= csrfInput(); ?>
                        <input type="hidden" name="track_id" value="<?= htmlspecialchars($envio['trackId']); ?>">
                        <button type="submit" class="boton-secundario" aria-label="Actualizar estado del envio <?= htmlspecialchars($envio['trackId']); ?>">Actualizar estado</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if ($sinTrackId !== []): ?>
    <h3 class="titulo-seccion">Documentos sin enviar</h3>
    <p class="tarjeta__intro">No tienen Track ID: no se confirmo su envio al SII, asi que no hay nada que consultar para ellos.</p>
    <table class="tabla-datos tabla-datos--cards">
        <thead>
            <tr>
                <th scope="col">Tipo</th>
                <th scope="col" class="tabla-datos__num">Folio</th>
                <th scope="col">Emitido</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($sinTrackId as $e): ?>
            <tr>
                <td data-label="Tipo"><?= htmlspecialchars(nombreTipoDte((int) $e['tipo_dte'])); ?></td>
                <td data-label="Folio" class="tabla-datos__num"><?= htmlspecialchars((string) $e['folio']); ?></td>
                <td data-label="Emitido"><?= htmlspecialchars((string) $e['fecha_emision']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <details>
        <summary>El SII rechaza mis envios con "RUT No Autorizado a Firmar"</summary>
        <p>El enrolamiento de usuarios del SII es <strong>por ambiente</strong>: estar autorizado para
        firmar y enviar en produccion (www.sii.cl) NO te autoriza en certificacion, aunque sea el mismo
        RUT y el mismo certificado.</p>
        <p>Enrolate en
        <a href="https://maullin.sii.cl/cvc_cgi/dte/eu_enrola_usuarios" target="_blank" rel="noopener noreferrer">Enrolar Usuarios (eu_enrola_usuarios)</a>
        con el RUT del <strong>administrador de la postulacion</strong>, y marca "Firmar Doctos." y
        "Enviar Doctos." para el RUT firmante.</p>
    </details>
</section>
