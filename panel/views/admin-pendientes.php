<?php
/**
 * Backlog (GET /admin/pendientes). Lee la tabla pendiente (migracion 044).
 *
 * Recibe $items (las filas ya filtradas y ordenadas), $contadores (los seis
 * numeros de arriba, SIN filtrar) y $filtros (lo que se pidio, ya limpio).
 *
 * LOS CONTADORES NO SIGUEN AL FILTRO, y esta escrito en Pendientes::contadores()
 * por que: si lo siguieran, elegir "solo infra" pondria "0 P0 abiertos" y quien
 * lo lea de reojo entiende que no hay ningun P0 en el producto. Un numero que
 * cambia de significado segun un <select> que esta mas abajo es peor que no
 * tenerlo.
 *
 * EL FILTRO VIAJA POR GET, no por POST, para que una busqueda se pueda guardar
 * en favoritos y mandar por chat: "mira los P1 de seguridad" es una URL.
 *
 * SE PINCHA LA FILA ENTERA, Y SIGUE SIENDO UN ENLACE DE VERDAD. El titulo es un
 * <a> normal y la celda lo estira sobre toda la fila por CSS (.backlog__titulo
 * a::after). La alternativa habitual -- data-href mas un onclick -- deja una
 * pantalla que no funciona sin JS, no se abre en pestana nueva con el boton del
 * medio y no muestra el destino en la barra de estado. Aqui las tres cosas
 * salen gratis porque nunca dejo de ser un <a>.
 */
$titulo      = 'Pendientes';
$adminActivo = 'pendientes';
require __DIR__ . '/partials/admin/header.php';

// Si el filtro esta en su estado por defecto, "no hay resultados" significa que
// el backlog esta vacio y ofrecer "ver todos" no lleva a ninguna parte.
$hayFiltro = $filtros['area'] !== null
    || $filtros['categoria'] !== null
    || $filtros['prioridad'] !== null
    || $filtros['estado'] !== 'sin_cerrar'
    || $filtros['q'] !== '';
?>

<h2 class="page-title">Lo que falta por hacer</h2>
<p class="muted">
    Backlog vivo, ordenado por prioridad. El <a href="/admin/changelog">changelog</a> registra lo
    que ya se hizo; esto registra lo que falta. Click sobre una fila para ver el detalle y cambiar
    su estado. Lo que todavia hay que <em>decidir</em> vive aparte, en
    <a href="/admin/ideas">Ideas</a>.
</p>

<div class="cards">
    <div class="stat"><div class="n"><?= $contadores['sin_cerrar']; ?></div><div class="l">sin cerrar</div></div>
    <div class="stat"><div class="n"><?= $contadores['p0']; ?></div><div class="l">P0 abiertos</div></div>
    <div class="stat"><div class="n"><?= $contadores['p1']; ?></div><div class="l">P1 abiertos</div></div>
    <div class="stat"><div class="n"><?= $contadores['en_curso']; ?></div><div class="l">en curso</div></div>
    <div class="stat"><div class="n"><?= $contadores['bloqueados']; ?></div><div class="l">bloqueados</div></div>
    <div class="stat"><div class="n"><?= $contadores['hechos']; ?></div><div class="l">hechos</div></div>
</div>

<form method="get" action="/admin/pendientes" class="filtros">
    <label class="field">
        <span>Area</span>
        <select name="area">
            <option value="">Todas</option>
            <?php foreach (Pendientes::AREAS as $clave => $etiqueta): ?>
            <option value="<?= htmlspecialchars($clave); ?>" <?= $filtros['area'] === $clave ? 'selected' : ''; ?>><?= htmlspecialchars($etiqueta); ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="field">
        <span>Categoria</span>
        <select name="categoria">
            <option value="">Todas</option>
            <?php foreach (Pendientes::CATEGORIAS as $clave => $etiqueta): ?>
            <option value="<?= htmlspecialchars($clave); ?>" <?= $filtros['categoria'] === $clave ? 'selected' : ''; ?>><?= htmlspecialchars($etiqueta); ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="field">
        <span>Prioridad</span>
        <select name="prioridad">
            <option value="">Todas</option>
            <?php foreach (Pendientes::PRIORIDADES as $clave => $etiqueta): ?>
            <option value="<?= htmlspecialchars($clave); ?>" <?= $filtros['prioridad'] === $clave ? 'selected' : ''; ?>><?= htmlspecialchars($etiqueta); ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="field">
        <span>Estado</span>
        <select name="estado">
            <option value="sin_cerrar" <?= $filtros['estado'] === 'sin_cerrar' ? 'selected' : ''; ?>>Sin cerrar</option>
            <option value="todos" <?= $filtros['estado'] === null ? 'selected' : ''; ?>>Todos</option>
            <?php foreach (Pendientes::ESTADOS as $clave => $etiqueta): ?>
            <option value="<?= htmlspecialchars($clave); ?>" <?= $filtros['estado'] === $clave ? 'selected' : ''; ?>><?= htmlspecialchars($etiqueta); ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="field filtros__buscar">
        <span>Buscar</span>
        <input type="search" name="q" value="<?= htmlspecialchars($filtros['q']); ?>" placeholder="titulo o detalle...">
    </label>
    <button class="btn" type="submit">Filtrar</button>
</form>

<div class="panel">
    <?php if ($items === []): ?>
    <p class="muted" style="margin:0;">
        <?php if ($hayFiltro): ?>
        No hay pendientes que cumplan ese filtro. <a href="/admin/pendientes">Ver todo lo que falta</a>.
        <?php else: ?>
        No queda nada pendiente.
        <?php endif; ?>
    </p>
    <?php else: ?>
    <div class="tabla-scroll">
    <table class="backlog">
        <thead>
            <tr>
                <th>#</th><th>Pri</th><th>Area</th><th>Categoria</th><th>Titulo</th><th>Sev</th><th>Estado</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $p): ?>
            <tr>
                <td class="muted"><?= (int) $p['id']; ?></td>
                <td><span class="pri pri--<?= htmlspecialchars((string) $p['prioridad']); ?>"><?= htmlspecialchars((string) $p['prioridad']); ?></span></td>
                <td><span class="chip"><?= htmlspecialchars(Pendientes::AREAS[$p['area']] ?? (string) $p['area']); ?></span></td>
                <td class="muted"><?= htmlspecialchars(Pendientes::CATEGORIAS[$p['categoria']] ?? (string) $p['categoria']); ?></td>
                <td class="backlog__titulo">
                    <a href="/admin/pendientes/<?= (int) $p['id']; ?>"><?= htmlspecialchars((string) $p['titulo']); ?></a>
                </td>
                <td><span class="tag <?= Pendientes::claseSeveridad((string) $p['severidad']); ?>"><?= htmlspecialchars((string) $p['severidad']); ?></span></td>
                <td><span class="tag <?= Pendientes::claseEstado((string) $p['estado']); ?>"><?= htmlspecialchars(Pendientes::ESTADOS[$p['estado']] ?? (string) $p['estado']); ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="muted" style="font-size:.82rem;margin:1rem 0 0;">
        <?= count($items); ?> de <?= $contadores['sin_cerrar'] + $contadores['hechos']; ?> items del backlog.
    </p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/admin/footer.php'; ?>
