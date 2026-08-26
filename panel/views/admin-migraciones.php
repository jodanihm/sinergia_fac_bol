<?php
/**
 * Registro de migraciones (GET /admin/migraciones). Solo lectura.
 *
 * Recibe $base, $migraciones (catalogo + veredicto contra la base + titulo y
 * archivo en disco), $totalEnDisco, $cruce, $renombradas y $sqlMostrado.
 *
 * LO PRIMERO QUE SE MIRA NO ES LA TABLA, es el cruce entre el catalogo y los
 * archivos. Si hay un .sql que el catalogo no menciona, la tabla de abajo esta
 * incompleta y no tiene forma de saberlo: puede decir "45 aplicadas" mientras
 * hay una migracion que nadie vigila. Por eso el desajuste va arriba y en rojo,
 * y por eso cuando NO lo hay queda una linea discreta y no un recuadro verde:
 * un cartel que siempre dice "todo bien" entrena a no mirarlo.
 *
 * ORDEN DESCENDENTE, al reves que el catalogo y que la salida del chequeo de
 * despliegue: alla se lee la lista entera de arriba abajo, aqui se viene a
 * mirar la ultima que se corrio o la que falta, y en ascendente eso queda
 * siempre al final detras de cuarenta y cuatro filas que ya no cambian.
 *
 * TRES COLUMNAS DE ESTADO QUE NO SON LA MISMA:
 *   Veredicto  que dice la BASE (se evaluaron las huellas contra
 *              information_schema, aqui y ahora).
 *   Huellas    de que se dedujo ese veredicto, una por una.
 *   Diferida   que se decidio: una que falta A PROPOSITO, con su motivo.
 *
 * NO HAY BOTON DE APLICAR NI LO VA A HABER. Aplicar una migracion es una
 * decision humana, con respaldo previo y a una hora elegida; un boton que corre
 * un ALTER en produccion desde el navegador es el accidente que este proyecto
 * evita en todas partes. Esta pantalla informa, igual que el resto del panel.
 */
$titulo      = 'Migraciones';
$adminActivo = 'migraciones';
require __DIR__ . '/partials/admin/header.php';

$conteo = ['APLICADA' => 0, 'PARCIAL' => 0, 'NO_APLICADA' => 0];
$diferidas = 0;
foreach ($migraciones as $m) {
    $conteo[$m['veredicto']]++;
    if ($m['diferida'] !== null) {
        $diferidas++;
    }
}

$desajustes = count($cruce['sinEntrada']) + count($cruce['sinArchivo']) + count($renombradas);
?>

<h2 class="page-title">Migraciones</h2>
<p class="muted">
    Las <?= count($migraciones); ?> migraciones de la base, con lo que hizo cada una y si su efecto
    esta presente en <code><?= htmlspecialchars($base); ?></code> ahora mismo. El veredicto se
    evalua contra <code>information_schema</code> con las mismas huellas que usa el chequeo de
    despliegue (<code>scripts/catalogo_migraciones.php</code>): una sola lista que leen los dos.
    La huella prueba que el <strong>efecto</strong> esta presente, no que la migracion se haya
    ejecutado &mdash; una columna creada a mano es indistinguible de una creada por su migracion.
</p>

<?php if ($desajustes > 0): ?>
<div class="panel desajuste">
    <h3>El catalogo y los archivos no coinciden</h3>
    <p class="error">
        Mientras esto no cuadre, el resto de la pantalla informa de menos: lo que el catalogo no
        menciona, ninguna huella lo vigila &mdash; ni aqui ni en el despliegue.
    </p>
    <?php if ($cruce['sinEntrada'] !== []): ?>
    <p>
        <strong>Hay .sql sin entrada en el catalogo:</strong>
        <?= htmlspecialchars(implode(', ', $cruce['sinEntrada'])); ?>.
        Alguien escribio la migracion y la corrio, pero no la declaro. Nadie puede decir si esta
        aplicada. Se arregla agregando su entrada en <code>scripts/catalogo_migraciones.php</code>,
        con la huella que reconozca su efecto.
    </p>
    <?php endif; ?>
    <?php if ($cruce['sinArchivo'] !== []): ?>
    <p>
        <strong>El catalogo nombra archivos que no estan:</strong>
        <?= htmlspecialchars(implode(', ', $cruce['sinArchivo'])); ?>.
        El veredicto sigue siendo valido &mdash; lo dan las huellas, no el archivo &mdash; pero ya
        no hay donde leer que hizo esa migracion ni como volver a correrla en una base nueva.
    </p>
    <?php endif; ?>
    <?php foreach ($renombradas as $r): ?>
    <p>
        <strong>La <?= htmlspecialchars($r['id']); ?> cambio de nombre:</strong>
        el catalogo dice <code><?= htmlspecialchars($r['catalogo']); ?></code> y en disco esta
        <code><?= htmlspecialchars($r['disco']); ?></code>.
    </p>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="cards">
    <div class="stat" title="Todas sus huellas presentes en esta base.">
        <div class="n" style="color:var(--ok);"><?= $conteo['APLICADA']; ?></div>
        <div class="l">Aplicadas</div>
    </div>
    <div class="stat" title="Algunas huellas si y otras no: un estado que ningun archivo describe.">
        <div class="n" style="<?= $conteo['PARCIAL'] > 0 ? 'color:var(--danger);' : ''; ?>"><?= $conteo['PARCIAL']; ?></div>
        <div class="l">Parciales</div>
    </div>
    <div class="stat" title="Ninguna de sus huellas esta presente.">
        <div class="n"><?= $conteo['NO_APLICADA']; ?></div>
        <div class="l">Sin aplicar</div>
    </div>
    <div class="stat" title="Sin aplicar A PROPOSITO, con el motivo escrito en su entrada del catalogo.">
        <div class="n"><?= $diferidas; ?></div>
        <div class="l">Diferidas</div>
    </div>
</div>

<?php if ($conteo['PARCIAL'] > 0): ?>
<p class="error">
    Hay migraciones a medio aplicar. Ese es el estado que ningun archivo describe: las migraciones
    mixtas combinan <code>CREATE TABLE IF NOT EXISTS</code>, que se puede repetir sin ruido, con
    <code>ALTER TABLE</code>, que revienta al repetirse. Revisar a mano antes de volver a correr
    nada.
</p>
<?php endif; ?>

<?php if ($sqlMostrado !== null): ?>
<div class="panel" id="sql">
    <h3>
        <code><?= htmlspecialchars($sqlMostrado['archivo']); ?></code>
        <a class="btn ghost sm" style="float:right;" href="/admin/migraciones">Cerrar</a>
    </h3>
    <p class="muted" style="margin-top:-.5rem;">
        El archivo tal cual esta en el repositorio. Se muestra de a uno y a pedido: mandar los
        <?= (int) $totalEnDisco; ?> en cada carga serian cientos de kB para que se lea, como mucho, uno.
    </p>
    <pre class="sql-migracion"><?= htmlspecialchars($sqlMostrado['sql']); ?></pre>
</div>
<?php endif; ?>

<div class="panel">
    <h3>El registro</h3>
    <p class="muted" style="margin-top:-.5rem;">
        De la mas nueva a la mas vieja: lo que se viene a mirar aqui es lo ultimo que se agrego.
    </p>
    <div class="tabla-scroll">
    <table>
        <thead>
            <tr><th>Id</th><th>Que hizo</th><th>Huellas</th><th>Veredicto</th></tr>
        </thead>
        <tbody>
        <?php foreach ($migraciones as $m): ?>
            <tr<?= ($sqlMostrado['id'] ?? null) === $m['id'] ? ' class="fila-abierta"' : ''; ?>>
                <td><?= htmlspecialchars($m['id']); ?></td>
                <td>
                    <?php if ($m['titulo'] !== ''): ?>
                    <div><?= htmlspecialchars($m['titulo']); ?></div>
                    <?php endif; ?>
                    <div class="muted" style="font-size:.8em;">
                        <?php if ($m['enDisco'] !== null): ?>
                        <a href="/admin/migraciones?sql=<?= urlencode($m['id']); ?>#sql"><code><?= htmlspecialchars($m['enDisco']); ?></code></a>
                        <?php else: ?>
                        <code><?= htmlspecialchars($m['archivo']); ?></code>
                        <span class="tag err">no esta en disco</span>
                        <?php endif; ?>
                        <?php if ($m['nota'] !== ''): ?>
                        &middot; <?= htmlspecialchars($m['nota']); ?>
                        <?php endif; ?>
                    </div>
                    <?php if ($m['diferida'] !== null): ?>
                    <details>
                        <summary style="cursor:pointer;color:var(--pk);font-size:.82rem;">Diferida a proposito</summary>
                        <p class="muted" style="font-size:.82rem;margin:.3rem 0 0;"><?= htmlspecialchars($m['diferida']); ?></p>
                    </details>
                    <?php endif; ?>
                </td>
                <td>
                    <details>
                        <summary class="muted" style="cursor:pointer;font-size:.82rem;">
                            <?= (int) $m['presentes']; ?> de <?= (int) $m['esperados']; ?>
                        </summary>
                        <?php foreach ($m['huellas'] as $h): ?>
                        <div style="font-size:.78rem;padding:.1rem 0;">
                            <span style="color:<?= $h['ok'] ? 'var(--ok)' : 'var(--danger)'; ?>;"><?= $h['ok'] ? '&#10003;' : '&times;'; ?></span>
                            <?= htmlspecialchars($h['desc']); ?>
                        </div>
                        <?php endforeach; ?>
                    </details>
                </td>
                <td>
                    <?php
                        $claseVeredicto = match ($m['veredicto']) {
                            'APLICADA' => 'tag ok',
                            'PARCIAL'  => 'tag err',
                            default    => 'tag warn',
                        };
                    ?>
                    <span class="<?= $claseVeredicto; ?>"><?= htmlspecialchars($m['veredicto']); ?></span>
                    <?php if ($m['veredicto'] === 'NO_APLICADA' && $m['diferida'] !== null): ?>*<?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="muted" style="margin-bottom:0;font-size:.82rem;">
        El asterisco marca las que estan sin aplicar <strong>a proposito</strong>, con su motivo
        escrito. Diferida no es lo mismo que olvidada.
        <?php if ($desajustes === 0): ?>
        Catalogo y archivos coinciden: las mismas <?= (int) $totalEnDisco; ?> en las dos listas.
        <?php endif; ?>
    </p>
</div>

<?php require __DIR__ . '/partials/admin/footer.php'; ?>
