<?php
/**
 * Explorador del esquema (GET /admin/base-datos). Solo lectura.
 *
 * La columna que justifica la pantalla es AISLAMIENTO, y va primero por eso.
 * El resto -- columnas, tipos, indices, claves foraneas -- lo da cualquier
 * cliente de base de datos; lo que ninguno contesta es "en esta tabla, que me
 * separa a un contribuyente de otro".
 */
$titulo      = 'Base de datos';
$adminActivo = 'base-datos';
require __DIR__ . '/partials/admin/header.php';

/** Etiqueta y color de cada clase de aislamiento. */
$claseAislamiento = [
    AislamientoTenant::RAIZ      => ['Raiz',      'tag',       'Es la tabla de cuentas: el dueno del que cuelga todo lo demas.'],
    AislamientoTenant::DIRECTO   => ['Directo',   'tag ok',    'Tiene columna cuenta_id: el WHERE esta a la vista al leer la tabla.'],
    AislamientoTenant::INDIRECTO => ['Indirecto', 'tag warn',  'Llega a cuenta por claves foraneas. El aislamiento existe, pero hay que conocer el camino.'],
    AislamientoTenant::SIN_RUTA  => ['Sin ruta',  'tag err',   'Guarda datos de un contribuyente y la base NO puede decir de cual: no hay camino a cuenta.'],
    AislamientoTenant::GLOBAL    => ['Global',    'tag',       'No pertenece a ninguna empresa.'],
];

$orden = [
    AislamientoTenant::SIN_RUTA,
    AislamientoTenant::INDIRECTO,
    AislamientoTenant::DIRECTO,
    AislamientoTenant::GLOBAL,
    AislamientoTenant::RAIZ,
];
?>

<h2 class="page-title">Base de datos</h2>
<p class="muted">
    Esquema de <code><?= htmlspecialchars($base); ?></code>, leido de
    <code>information_schema</code> filtrando por <code>DATABASE()</code>:
    siempre describe la base a la que apunta el panel, no una escrita a mano.
    <?= (int) $totalTablas; ?> tablas, <?= (int) $totalFks; ?> claves foraneas.
</p>

<div class="panel">
    <h3>Aislamiento entre empresas</h3>
    <p class="muted" style="margin-top:-.5rem;">
        Este sistema es multi-tenant <strong>por fila</strong>: todas las cuentas comparten
        las mismas tablas y lo unico que las separa es un <code>WHERE cuenta_id = :c</code>
        que alguien tiene que acordarse de escribir. No hay un schema por empresa ni
        seguridad a nivel de fila. Un olvido de ese WHERE no es un error de pantalla:
        es un contribuyente viendo los documentos de otro. Este cuadro dice, tabla por
        tabla, que tan a la vista esta ese vinculo. Se calcula recorriendo el grafo de
        claves foraneas, no de una lista escrita a mano que quedaria vieja en la
        proxima migracion.
    </p>
    <div class="cards">
        <?php foreach ($orden as $clase): ?>
        <?php [$etiqueta, $claseTag, $ayuda] = $claseAislamiento[$clase]; ?>
        <div class="stat" title="<?= htmlspecialchars($ayuda); ?>">
            <div class="n" style="<?= $clase === AislamientoTenant::SIN_RUTA ? 'color:var(--danger);' : ''; ?>">
                <?= (int) ($conteoAislamiento[$clase] ?? 0); ?>
            </div>
            <div class="l"><?= htmlspecialchars($etiqueta); ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if (($conteoAislamiento[AislamientoTenant::SIN_RUTA] ?? 0) > 0): ?>
    <p class="muted" style="margin-bottom:0;font-size:.85rem;">
        Las tablas <strong>sin ruta</strong> son las que mas cuidado piden: cuelgan de
        <code>rut_emisor</code> y no de <code>cuenta_id</code>, asi que ninguna clave
        foranea impide que una consulta mal escrita mezcle dos empresas. Aparecen
        primero en la lista de abajo.
    </p>
    <?php endif; ?>
</div>

<form class="toolbar" method="get" action="/admin/base-datos">
    <input type="search" name="q" value="<?= htmlspecialchars($busqueda); ?>"
           placeholder="Filtrar tablas por nombre" aria-label="Filtrar tablas" style="max-width:280px;">
    <button type="submit" class="btn sm">Filtrar</button>
    <?php if ($busqueda !== ''): ?>
    <a class="btn ghost sm" href="/admin/base-datos">Limpiar</a>
    <span class="muted"><?= count($tablas); ?> de <?= (int) $totalTablas; ?> tablas</span>
    <?php endif; ?>
</form>

<?php
    // Las de mayor riesgo arriba. El orden lo decide la clase de aislamiento y,
    // dentro de cada clase, el nombre: una pantalla de auditoria tiene que
    // poner adelante lo que hay que mirar, no el orden alfabetico.
    $peso = array_flip($orden);
    usort($tablas, static function (array $a, array $b) use ($peso, $aislamiento): int {
        $pa = $peso[$aislamiento[$a['TABLE_NAME']]['clase']] ?? 99;
        $pb = $peso[$aislamiento[$b['TABLE_NAME']]['clase']] ?? 99;

        return $pa <=> $pb ?: strcmp((string) $a['TABLE_NAME'], (string) $b['TABLE_NAME']);
    });
?>

<?php if ($tablas === []): ?>
<div class="panel"><p class="muted" style="margin:0;">Ninguna tabla coincide con el filtro.</p></div>
<?php else: ?>
<div class="er-grid">
    <?php foreach ($tablas as $t): ?>
    <?php
        $nombre = (string) $t['TABLE_NAME'];
        $ais    = $aislamiento[$nombre] ?? ['clase' => AislamientoTenant::GLOBAL, 'camino' => []];
        [$etiqueta, $claseTag, $ayuda] = $claseAislamiento[$ais['clase']];
    ?>
    <div class="er-table">
        <div class="head">
            <span><?= htmlspecialchars($nombre); ?></span>
            <span class="cnt">~<?= number_format((int) $t['TABLE_ROWS'], 0, ',', '.'); ?> filas</span>
        </div>
        <div class="er-ais">
            <span class="<?= $claseTag; ?>" title="<?= htmlspecialchars($ayuda); ?>"><?= htmlspecialchars($etiqueta); ?></span>
            <?php if ($ais['camino'] !== []): ?>
            <span class="er-camino"><?= htmlspecialchars(implode('  ', $ais['camino'])); ?></span>
            <?php endif; ?>
        </div>
        <?php foreach ($columnasPorTabla[$nombre] ?? [] as $c): ?>
        <?php $destinoFk = $fkPorColumna[$nombre . '.' . $c['COLUMN_NAME']] ?? null; ?>
        <div class="er-col">
            <span class="cname">
                <?= htmlspecialchars((string) $c['COLUMN_NAME']); ?>
                <?php if ($c['COLUMN_KEY'] === 'PRI'): ?><span class="badge pk">PK</span><?php endif; ?>
                <?php if ($destinoFk !== null): ?><span class="badge fk">FK</span><?php endif; ?>
                <?php if ($destinoFk !== null): ?>
                <span class="fkref">&rarr; <?= htmlspecialchars($destinoFk); ?></span>
                <?php endif; ?>
                <?php if ($c['COLUMN_COMMENT'] !== ''): ?>
                <span class="er-comentario" title="<?= htmlspecialchars((string) $c['COLUMN_COMMENT']); ?>">?</span>
                <?php endif; ?>
            </span>
            <span class="ctype">
                <?= htmlspecialchars((string) $c['COLUMN_TYPE']); ?><?= $c['IS_NULLABLE'] === 'YES' ? ' null' : ''; ?>
            </span>
        </div>
        <?php endforeach; ?>
        <?php if (isset($indicesPorTabla[$nombre])): ?>
        <details class="er-indices">
            <summary><?= count($indicesPorTabla[$nombre]); ?> indice<?= count($indicesPorTabla[$nombre]) === 1 ? '' : 's'; ?></summary>
            <?php foreach ($indicesPorTabla[$nombre] as $ix): ?>
            <div class="er-indice">
                <code><?= htmlspecialchars((string) $ix['INDEX_NAME']); ?></code>
                (<?= htmlspecialchars((string) $ix['COLUMNAS']); ?>)
                <?= ((int) $ix['NON_UNIQUE']) === 0 ? '<span class="tag ok">unico</span>' : ''; ?>
            </div>
            <?php endforeach; ?>
        </details>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<p class="muted" style="font-size:.82rem;">
    El conteo de filas es la <strong>estimacion</strong> que guarda InnoDB en sus
    estadisticas, no un COUNT: puede errarle por mucho. Sirve para comparar tamanos,
    no para informar cifras.
</p>
<?php endif; ?>

<div class="panel">
    <h3>Migraciones</h3>
    <p class="muted" style="margin-top:-.5rem;">
        Mismo catalogo y mismas huellas que usa el chequeo de despliegue
        (<code>scripts/catalogo_migraciones.php</code>): una sola lista que leen los dos.
        La huella prueba que el <strong>efecto</strong> esta presente, no que la migracion
        se haya ejecutado &mdash; una columna creada a mano es indistinguible de una
        creada por su migracion.
    </p>
    <?php
        $conteoMigr = ['APLICADA' => 0, 'PARCIAL' => 0, 'NO_APLICADA' => 0];
        foreach ($migraciones as $m) {
            $conteoMigr[$m['veredicto']]++;
        }
    ?>
    <div class="chips" style="margin-bottom:1rem;">
        <span class="tag ok"><?= $conteoMigr['APLICADA']; ?> aplicadas</span>
        <?php if ($conteoMigr['PARCIAL'] > 0): ?>
        <span class="tag err"><?= $conteoMigr['PARCIAL']; ?> parciales</span>
        <?php endif; ?>
        <?php if ($conteoMigr['NO_APLICADA'] > 0): ?>
        <span class="tag warn"><?= $conteoMigr['NO_APLICADA']; ?> sin aplicar</span>
        <?php endif; ?>
    </div>
    <?php if ($conteoMigr['PARCIAL'] > 0): ?>
    <p class="error">
        Hay migraciones a medio aplicar. Ese es el estado que ningun archivo describe:
        las migraciones mixtas combinan <code>CREATE TABLE IF NOT EXISTS</code>, que se
        puede repetir sin ruido, con <code>ALTER TABLE</code>, que revienta al repetirse.
        Revisar a mano antes de volver a correr nada.
    </p>
    <?php endif; ?>
    <div class="tabla-scroll">
    <table>
        <thead><tr><th>Id</th><th>Archivo</th><th>Que hace</th><th>Huellas</th><th>Veredicto</th></tr></thead>
        <tbody>
        <?php foreach ($migraciones as $m): ?>
            <tr>
                <td><?= htmlspecialchars($m['id']); ?></td>
                <td><code style="font-size:.8em;"><?= htmlspecialchars($m['archivo']); ?></code></td>
                <td class="muted" style="font-size:.85em;">
                    <?= htmlspecialchars($m['nota']); ?>
                    <?php if ($m['diferida'] !== null): ?>
                    <details>
                        <summary style="cursor:pointer;color:var(--pk);">Diferida a proposito</summary>
                        <p class="muted" style="font-size:.95em;margin:.3rem 0 0;"><?= htmlspecialchars($m['diferida']); ?></p>
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
        El asterisco marca las que estan sin aplicar <strong>a proposito</strong>, con su
        motivo escrito. Diferida no es lo mismo que olvidada.
    </p>
</div>

<?php require __DIR__ . '/partials/admin/footer.php'; ?>
