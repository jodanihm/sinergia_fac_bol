<?php
/**
 * Integraciones (GET /admin/integraciones). Lee panel/datos/integraciones.php.
 *
 * Recibe $integraciones (el catalogo) y $flash (el resultado de la ultima
 * sonda, o null).
 *
 * NO SE SONDEA AL CARGAR LA PAGINA, y es la decision de fondo de esta pantalla.
 * Dibujarla golpeando las ocho integraciones dejaria la carga a merced del mas
 * lento -- ocho por ocho segundos en el peor caso -- y mandaria trafico a
 * terceros cada vez que alguien pasa por el menu, incluso sin querer probar
 * nada. Se prueba de a una, cuando alguien lo pide.
 *
 * DOS COLUMNAS QUE PARECEN LA MISMA Y NO LO SON: "credencial" dice si la clave
 * esta configurada en este contenedor -- se sabe sin salir a la red -- y la
 * sonda dice si el otro lado contesta. Una credencial presente no significa que
 * sirva, y un host que responde no significa que la credencial este bien.
 *
 * EL TIPO DE SONDA SE MUESTRA SIEMPRE, junto al boton. Un "Responde" verde en
 * una fila de alcance significa muchisimo menos que en una autenticada, y si la
 * pantalla no lo dice, las dos se leen igual.
 */
$titulo      = 'Integraciones';
$adminActivo = 'integraciones';
require __DIR__ . '/partials/admin/header.php';

$probada = $flash['integracion'] ?? null;
?>

<h2 class="page-title">Integraciones</h2>
<p class="muted">
    Los <?= count($integraciones); ?> servicios de los que depende este sistema, con lo que resuelve
    cada uno y que se rompe si se cae. El boton prueba la conexion en el momento; nada se sondea
    solo al abrir esta pagina.
</p>

<?php if ($flash !== null && $probada !== null): ?>
<div class="veredicto <?= htmlspecialchars(Integraciones::claseEstado((string) $flash['estado'])); ?>">
    <span class="punto">&#9679;</span>
    <div>
        <h3>
            <?= htmlspecialchars((string) $flash['nombreIntegracion']); ?>:
            <?= htmlspecialchars((string) $flash['titulo']); ?>
            <span class="muted" style="font-weight:400;font-size:.85rem;">
                &middot; <?= (int) $flash['ms']; ?> ms
            </span>
        </h3>
        <p><?= htmlspecialchars((string) $flash['mensaje']); ?></p>
    </div>
</div>
<?php endif; ?>

<div class="panel">
    <div class="tabla-scroll">
    <table>
        <thead>
            <tr><th>Integracion</th><th>Host</th><th>Credencial</th><th>Probar</th></tr>
        </thead>
        <tbody>
        <?php foreach ($integraciones as $i): ?>
            <?php
            $cred  = Integraciones::estadoCredencial($i);
            $sonda = $i['sonda'] ?? null;
            ?>
            <tr<?= $probada === $i['id'] ? ' class="recien-probada"' : ''; ?>>
                <td>
                    <strong><?= htmlspecialchars((string) $i['nombre']); ?></strong>
                    <div class="muted" style="font-size:.82em;margin-top:.25rem;max-width:46rem;">
                        <?= htmlspecialchars((string) $i['para_que']); ?>
                    </div>
                    <div class="muted" style="font-size:.76em;margin-top:.35rem;">
                        <code><?= htmlspecialchars((string) $i['donde']); ?></code>
                    </div>
                </td>
                <td class="muted" style="font-size:.82em;"><?= htmlspecialchars((string) $i['host']); ?></td>
                <td>
                    <?php if ($i['credencial'] === null): ?>
                    <span class="muted" style="font-size:.82em;">no lleva</span>
                    <?php elseif ($cred['puesta']): ?>
                    <span class="tag ok">configurada</span>
                    <div class="muted" style="font-size:.74em;margin-top:.25rem;">
                        <code><?= htmlspecialchars((string) $i['credencial']); ?></code>,
                        <?= (int) $cred['largo']; ?> caracteres
                    </div>
                    <?php else: ?>
                    <span class="tag err">falta</span>
                    <div class="muted" style="font-size:.74em;margin-top:.25rem;">
                        <code><?= htmlspecialchars((string) $i['credencial']); ?></code>
                    </div>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($sonda === null): ?>
                    <span class="muted" style="font-size:.82em;">sin sonda segura</span>
                    <?php else: ?>
                    <form method="post" action="/admin/integraciones/probar">
                        <?= csrfInput(); ?>
                        <input type="hidden" name="id" value="<?= htmlspecialchars((string) $i['id']); ?>">
                        <button class="btn sm" type="submit">Probar conexion</button>
                    </form>
                    <div class="muted" style="font-size:.74em;margin-top:.35rem;">
                        <?php if ($sonda['tipo'] === 'autenticada'): ?>
                        prueba <strong>la credencial</strong>
                        <?php else: ?>
                        solo <strong>alcance</strong>: no prueba credenciales
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </td>
            </tr>
            <tr class="nota-fila">
                <td colspan="4" class="muted" style="font-size:.8em;padding-top:0;">
                    <?= htmlspecialchars((string) $i['nota']); ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<div class="panel">
    <h3>Que significa cada resultado</h3>
    <p class="muted" style="font-size:.87rem;line-height:1.6;margin:0;">
        <strong>Conexion correcta</strong> solo aparece en las sondas autenticadas y quiere decir que la
        credencial sirve de verdad. <strong>Responde</strong> es lo maximo que puede decir una sonda de
        alcance: hay red, el TLS cierra y del otro lado contesta algo &mdash; no se probo ninguna clave.
        <strong>No responde</strong> es la unica falla inequivoca: no volvio nada.
        <strong>Credencial rechazada</strong> significa que el servicio esta vivo pero no acepta la clave.
        Todas las sondas son peticiones GET a endpoints de solo lectura: ninguna emite un documento,
        manda un correo, consume una semilla del SII ni gasta cupo de un plan pagado.
    </p>
</div>

<?php require __DIR__ . '/partials/admin/footer.php'; ?>
