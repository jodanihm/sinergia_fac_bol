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

<div class="toolbar">
    <a class="btn sm <?= $vista === 'detalle' ? '' : 'ghost'; ?>" href="/admin/base-datos">Detalle</a>
    <a class="btn sm <?= $vista === 'diagrama' ? '' : 'ghost'; ?>" href="/admin/base-datos?vista=diagrama">Diagrama ER</a>
</div>

<?php if ($vista === 'diagrama'): ?>
<div class="panel">
    <h3>Diagrama ER</h3>
    <p class="muted" style="margin-top:-.5rem;">
        Las <?= (int) $totalTablas; ?> tablas y sus <?= (int) $totalFks; ?> claves foraneas.
        Rueda para acercar, arrastra para mover. El diagrama se arma en el servidor a partir
        del mismo <code>information_schema</code> que lee la vista de detalle.
    </p>
    <div class="mermaid-viewport" id="er-viewport">
        <div class="mermaid-controls">
            <button type="button" id="er-mas" title="Acercar">+</button>
            <button type="button" id="er-menos" title="Alejar">&minus;</button>
            <span id="er-zoom">100%</span>
            <button type="button" id="er-reset" title="Volver al 100%">&#8635;</button>
        </div>
        <div class="mermaid-canvas" id="er-canvas"></div>
    </div>
</div>

<?php
    /* El texto del diagrama viaja dentro de un <script> con un type que el
       navegador NO ejecuta ni interpreta: lo trata como texto opaco. Aun asi va
       escapado, porque el contenido sale de information_schema y una secuencia
       </script> ahi adentro cerraria la etiqueta antes de tiempo. */
?>
<script type="application/x-mermaid" id="er-fuente"><?= htmlspecialchars($diagramaEr, ENT_NOQUOTES); ?></script>
<?php
    /* Mermaid VENDORIZADO y servido local, nunca desde un CDN: el panel va
       detras de Cloudflare Tunnel y una dependencia externa es una pantalla que
       se rompe el dia que el CDN falla. Ver panel/public/js/MERMAID.md.
       Cache-busting por filemtime, igual que el CSS. */
    $erJs      = '/js/mermaid.min.js?v=' . (@filemtime(__DIR__ . '/../public/js/mermaid.min.js') ?: '1');
    $erVisorJs = '/js/admin-er.js?v=' . (@filemtime(__DIR__ . '/../public/js/admin-er.js') ?: '1');
?>
<script src="<?= htmlspecialchars($erJs); ?>"></script>
<script src="<?= htmlspecialchars($erVisorJs); ?>"></script>

<?php else: ?>

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
<?php endif; /* fin de la vista de detalle */ ?>

<p class="muted" style="font-size:.85rem;">
    Las migraciones ya no se listan aqui: viven en <a href="/admin/migraciones">Migraciones</a>,
    con su archivo, su veredicto contra esta misma base y el cruce entre el catalogo y los .sql del
    repositorio. Esta pantalla describe el esquema que hay hoy; aquella, como se llego hasta el.
</p>

<?php require __DIR__ . '/partials/admin/footer.php'; ?>
