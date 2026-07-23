<?php
    $pendientesSok = array_filter(
        $envios,
        static fn (array $e): bool => $e['estado'] === 'EPR'
            && array_diff([33, 61, 56], $e['tipos']) === []
            && ! isset($sokPorTrackId[$e['trackId']])
    );
?>

<div class="tarjeta">
    <h2>Set Basico</h2>

    <details style="margin:0 0 0.75rem;">
        <summary>Ayuda: el SII rechaza mis envios con "RUT No Autorizado a Firmar"</summary>
        <div style="margin:0.5rem 0 0;padding:0.5rem 0.75rem;border-left:3px solid #999;">
            <p>El enrolamiento de usuarios del SII es <strong>por ambiente</strong>: estar
            autorizado para firmar/enviar en produccion (www.sii.cl) NO te autoriza en
            certificacion, aunque sea el mismo RUT y el mismo certificado.</p>
            <p>Debes enrolarte en
            <a href="https://maullin.sii.cl/cvc_cgi/dte/eu_enrola_usuarios" target="_blank" rel="noopener noreferrer">Enrolar Usuarios (eu_enrola_usuarios)</a>,
            entrando con el RUT del <strong>administrador de la postulacion</strong>, y marcar
            las casillas <strong>"Firmar Doctos."</strong> y <strong>"Enviar Doctos."</strong>
            para el RUT firmante.</p>
        </div>
    </details>

    <?php if ($setBasico['aprobado']): ?>
    <p style="color:#2e7d32;font-weight:600;">
        APROBADO &mdash; el envio <?= htmlspecialchars($setBasico['trackId']); ?> fue aceptado
        por el SII (EPR) con los 3 tipos de documento (factura, nota de credito y nota de debito),
        y confirmado SOK por ti.
    </p>
    <?php else: ?>
    <p style="color:#999;font-weight:600;">
        PENDIENTE &mdash; ningun envio esta APROBADO todavia. Un envio se considera aprobado
        solo cuando el SII lo acepta (EPR) con los 3 tipos de documento Y ademas TU lo marcas
        como SOK abajo, despues de recibir el correo real del SII para ese envio especifico.
        Estar en EPR NO alcanza por si solo: EPR solo confirma que el envio se proceso bien
        tecnicamente, no que el SII aprobo el contenido.
    </p>
    <?php endif; ?>

    <details>
        <summary>
            Historial de envios (<?= count($envios); ?>)
            <?php if ($pendientesSok !== []): ?>
                &mdash; <?= count($pendientesSok); ?> pendiente(s) de SOK
            <?php endif; ?>
        </summary>

        <div style="margin-top:0.75rem;">
        <?php if ($pendientesSok !== []): ?>
        <p class="aviso-ambar">
            Hay <?= count($pendientesSok); ?> envio(s) en EPR con los 3 tipos, pendientes de que
            los marques como SOK: <?= htmlspecialchars(implode(', ', array_column($pendientesSok, 'trackId'))); ?>.
            Revisa tu correo del SII antes de marcarlos.
        </p>
        <?php endif; ?>

        <?php if ($envios === []): ?>
        <p>Aun no has emitido ningun documento de este set.</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Track ID</th>
                    <th>Fecha de emision</th>
                    <th>Documentos</th>
                    <th>Estado</th>
                    <th>SOK (revision de contenido)</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($envios as $envio): ?>
                <?php $tieneLosTres = array_diff([33, 61, 56], $envio['tipos']) === []; ?>
                <tr>
                    <td><?= htmlspecialchars($envio['trackId']); ?></td>
                    <td><?= htmlspecialchars((string) $envio['fechaEmision']); ?></td>
                    <td><?= htmlspecialchars($envio['resumen']); ?></td>
                    <td><?= htmlspecialchars((string) $envio['estado']); ?></td>
                    <td>
                        <?php if (isset($sokPorTrackId[$envio['trackId']])): ?>
                            <strong style="color:#2e7d32;">SOK confirmado</strong>
                            <div style="color:#999;font-size:0.85em;"><?= htmlspecialchars($sokPorTrackId[$envio['trackId']]); ?></div>
                        <?php elseif ($envio['estado'] === 'EPR' && $tieneLosTres): ?>
                            <form method="post" action="/certificacion/marcar-sok" style="margin:0 0 0.25rem;">
                                <?= csrfInput(); ?>
                                <input type="hidden" name="track_id" value="<?= htmlspecialchars($envio['trackId']); ?>">
                                <button type="submit" onclick="return confirm('Confirmas que ya recibiste el correo del SII con SOK para este trackId especifico?');">Marcar como SOK</button>
                            </form>
                            <div style="color:#999;font-size:0.8em;max-width:220px;">
                                Marca esto SOLO despues de recibir el correo del SII "Resultado de
                                Revision del Set de Prueba" confirmando SOK para ESTE trackId. NO
                                marques por adivinar.
                            </div>
                        <?php else: ?>
                            &mdash;
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="post" action="/certificacion/actualizar" style="margin:0;">
                            <?= csrfInput(); ?>
                            <input type="hidden" name="track_id" value="<?= htmlspecialchars($envio['trackId']); ?>">
                            <button type="submit">Actualizar estado</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php if ($sinTrackId !== []): ?>
        <h3>Documentos sin enviar</h3>
        <p>Estos documentos no tienen track ID registrado: no se confirmo su envio al SII, asi
        que no hay nada que consultar para ellos.</p>
        <table>
            <thead>
                <tr>
                    <th>Tipo</th>
                    <th>Folio</th>
                    <th>Fecha emision</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($sinTrackId as $e): ?>
                <tr>
                    <td><?= htmlspecialchars(nombreTipoDte((int) $e['tipo_dte'])); ?></td>
                    <td><?= htmlspecialchars((string) $e['folio']); ?></td>
                    <td><?= htmlspecialchars((string) $e['fecha_emision']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        </div>
    </details>
</div>
