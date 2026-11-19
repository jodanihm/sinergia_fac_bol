<?php
/**
 * Menu lateral del panel. Solo se incluye desde header.php cuando hay sesion.
 *
 * REDISENO DE NOVIEMBRE 2026: superficie oscura (marino de marca con un brillo
 * azul), secciones con titulo (General / Operacion / Administracion, la clave
 * 'seccion' de definicionMenu()), tarjeta de cuenta al pie y un modo
 * CONTRAIDO -- solo iconos, con tooltip -- que se recuerda por navegador.
 * Estilos: bloque "Menu lateral" de css/style.css (prefijo .sb). Conducta:
 * js/menu-lateral.js. Sin JavaScript el menu funciona completo (grupos
 * <details> nativos, siempre expandido, apilado arriba en movil).
 *
 * Deriva el estado de cada item de DOS ejes independientes (ver definicionMenu()
 * y navEstadoItem() en public/index.php):
 *   - construido / no_construido  -> "proximamente" (gana siempre; nunca culpa al tenant)
 *   - requiereProduccion + estadoEmisionProduccion() -> "sin_produccion"
 *   - resto -> "habilitado" (link)
 *
 * EL SEGUNDO EJE ES EL MISMO CRITERIO QUE EL SERVIDOR. Se pregunta por las 3
 * filas de produccion, exactamente lo que exige exigirProduccionCompleto() en
 * cada ruta operativa.
 *
 * El item activo se marca por $navActivo (clave, opcional, que la vista puede
 * pasar) o, si no viene, comparando el destino con la ruta actual.
 *
 * OPCIONES DE UN GRUPO SIN ICONO: antes heredaban el del grupo y Ventas
 * pintaba el mismo documento doce veces. La pertenencia la dicen la sangria y
 * la linea guia; la etiqueta dice cual.
 */

/* require_once y no require: panel-gestion.php tambien lo carga en la misma
   peticion, y una funcion redeclarada es error fatal. */
require_once __DIR__ . '/_iconos.php';

$navPdo = Db::conexion();
/* 'falta' === null significa que estan las 3 filas de produccion, o sea que el
   guard del servidor dejaria pasar. */
$navPuedeEmitir = estadoEmisionProduccion($navPdo, Auth::cuentaId())['falta'] === null;
$navRutaActual  = rtrim((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'), '/');
if ($navRutaActual === '') {
    $navRutaActual = '/';
}
$navActivoClave = isset($navActivo) ? (string) $navActivo : null;

$navIcono = static function (?string $id): string {
    return iconoSvg($id, 18, 'sb-item__icono');
};

/** El item es la pagina actual (por clave si la vista la paso, si no por ruta). */
$navEsActivo = static function (array $item) use ($navActivoClave, $navRutaActual): bool {
    return ($navActivoClave !== null && ($item['clave'] ?? null) === $navActivoClave)
        || ($navActivoClave === null && ($item['destino'] ?? null) === $navRutaActual);
};

/**
 * Item SUELTO de primer nivel (Dashboard, Asistente IA): icono + texto.
 * data-tip es el texto del tooltip en el menu contraido.
 */
$navPintarItem = static function (array $item) use ($navPuedeEmitir, $navIcono, $navEsActivo): void {
    $label  = htmlspecialchars((string) $item['label']);
    $estado = navEstadoItem($item, $navPuedeEmitir);
    $icono  = $navIcono($item['icono'] ?? null);
    $extra  = ($item['clave'] ?? '') === 'chat' ? ' sb-item--ia' : '';

    if ($estado === 'habilitado') {
        $activo = $navEsActivo($item);
        echo '<a class="sb-item' . $extra . ($activo ? ' sb-item--activo' : '') . '" href="' . htmlspecialchars((string) $item['destino']) . '"'
            . ($activo ? ' aria-current="page"' : '') . ' data-hoja data-tip="' . $label . '">'
            . $icono . '<span class="sb-item__texto">' . $label . '</span></a>';
        return;
    }
    $badge = $estado === 'sin_produccion' ? 'Sin configurar' : 'Proximamente';
    echo '<span class="sb-item sb-item--deshabilitado" data-hoja data-tip="' . $label . ' (' . $badge . ')">'
        . $icono . '<span class="sb-item__texto">' . $label . '</span><span class="sb-badge">' . $badge . '</span></span>';
};

/** Opcion DENTRO de un grupo: solo texto. */
$navPintarOpcion = static function (array $item) use ($navPuedeEmitir, $navEsActivo): void {
    $label  = htmlspecialchars((string) $item['label']);
    $estado = navEstadoItem($item, $navPuedeEmitir);

    if ($estado === 'habilitado') {
        $activo = $navEsActivo($item);
        echo '<a class="sb-sub' . ($activo ? ' sb-sub--activo' : '') . '" href="' . htmlspecialchars((string) $item['destino']) . '"'
            . ($activo ? ' aria-current="page"' : '') . ' data-hoja>' . $label . '</a>';
        return;
    }
    /* Sin produccion: la pantalla existe pero falta cargar empresa,
       certificado y CAF de produccion. Proximamente: el modulo no esta hecho. */
    $sinProd = $estado === 'sin_produccion';
    echo '<span class="sb-sub sb-sub--deshabilitado" data-hoja title="'
        . ($sinProd ? 'Disponible cuando cargues empresa, certificado y CAF de produccion' : 'Este modulo aun no esta disponible') . '">'
        . '<span class="sb-sub__texto">' . $label . '</span><span class="sb-badge' . ($sinProd ? ' sb-badge--alerta' : '') . '">'
        . ($sinProd ? 'Sin configurar' : 'Proximamente') . '</span></span>';
};

/** Opciones de un grupo: hojas y subgrupos (Ventas > Emision, Certificacion / Produccion). */
$navRenderOpciones = static function (array $nodos) use (&$navRenderOpciones, $navPintarOpcion): void {
    foreach ($nodos as $nodo) {
        if (isset($nodo['items'])) {
            // 'variante' termina en un class: se sanea a [a-z-].
            $variante = preg_replace('/[^a-z-]/', '', (string) ($nodo['variante'] ?? ''));
            echo '<div class="sb-subgrupo' . ($variante !== '' ? ' sb-subgrupo--' . $variante : '') . '">';
            echo '<p class="sb-subgrupo__titulo">' . htmlspecialchars((string) $nodo['label']) . '</p>';
            $navRenderOpciones($nodo['items']);
            echo '</div>';
            continue;
        }
        $navPintarOpcion($nodo);
    }
};

/**
 * Si la pagina actual pertenece a una seccion plegable: alguno de sus items es
 * el activo o la ruta cae bajo uno de sus 'prefijos' o bajo el destino de un
 * item (/maestros/clientes/7/editar abre Maestros). Decide si el <details> se
 * pinta abierto: nadie aterriza en una pantalla con su grupo cerrado.
 */
$navSeccionContieneActual = static function (array $seccion) use ($navRutaActual, $navActivoClave): bool {
    foreach ((array) ($seccion['prefijos'] ?? []) as $prefijo) {
        if ($navRutaActual === $prefijo
            || str_starts_with($navRutaActual, $prefijo . '/')
            || str_starts_with($navRutaActual, $prefijo . '-')) {
            return true;
        }
    }
    $pendientes = $seccion['items'];
    while ($pendientes !== []) {
        $nodo = array_shift($pendientes);
        if (isset($nodo['items'])) {
            array_push($pendientes, ...$nodo['items']);
            continue;
        }
        if (($navActivoClave !== null && ($nodo['clave'] ?? null) === $navActivoClave)
            || ($navActivoClave === null && ($nodo['destino'] ?? null) === $navRutaActual)) {
            return true;
        }
        $destino = (string) ($nodo['destino'] ?? '');
        if ($destino !== '' && $destino !== '/' && str_starts_with($navRutaActual, $destino . '/')) {
            return true;
        }
    }

    return false;
};

/* Clave estable de cada grupo, para recordar cuales dejo abiertos la persona. */
$navClaveGrupo = static function (string $label): string {
    return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($label)), '-');
};

/* QUIEN ESTA CONECTADO, para la tarjeta del pie: la empresa (la que se esta
   viendo, si un superadmin abrio otra cuenta) y el correo. Si la consulta
   falla, la tarjeta queda solo con la salida. */
$navEmpresa = '';
$navCorreo  = '';
if ($navPdo instanceof PDO) {
    try {
        $navStmt = $navPdo->prepare('SELECT nombre FROM cuenta WHERE id = :id LIMIT 1');
        $navStmt->execute([':id' => Auth::cuentaId()]);
        $navEmpresa = (string) ($navStmt->fetchColumn() ?: '');
        $navStmt = $navPdo->prepare('SELECT email FROM usuario WHERE id = :id LIMIT 1');
        $navStmt->execute([':id' => Auth::usuarioId()]);
        $navCorreo = (string) ($navStmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        error_log('sidebar: no se pudo leer empresa/usuario - ' . $e->getMessage());
    }
}
$navInicial = mb_strtoupper(mb_substr($navEmpresa !== '' ? $navEmpresa : ($navCorreo !== '' ? $navCorreo : 'S'), 0, 1));

/* Las secciones: cada item con 'seccion' abre un bloque nuevo con ese titulo. */
$navBloques = [];
foreach (definicionMenu() as $navNodo) {
    if (isset($navNodo['seccion']) || $navBloques === []) {
        $navBloques[] = ['titulo' => (string) ($navNodo['seccion'] ?? ''), 'nodos' => []];
    }
    $navBloques[count($navBloques) - 1]['nodos'][] = $navNodo;
}

$navChevron = '<svg class="sb-item__chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 6l6 6-6 6"/></svg>';
?>
<?php /* MENU CONTRAIDO: se aplica ANTES de que exista el <nav>, para que no
         aparezca ancho y se encoja delante de la persona. Solo escritorio: en
         movil el CSS lo ignora. try/catch: localStorage puede estar bloqueado. */ ?>
<script>try { if (window.localStorage.getItem('sinergia.menu.contraido') === '1') { document.documentElement.classList.add('menu-contraido'); } } catch (e) {}</script>
<?php /* BARRA SUPERIOR EN MOVIL (js/menu-lateral.js). Oculta en escritorio y
         sin JavaScript: sin JS el menu sigue apilado arriba. */ ?>
<div class="barra-movil" hidden>
    <button type="button" class="barra-movil__menu" data-menu-abrir aria-controls="menu-lateral" aria-expanded="false">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M4 7h16M4 12h16M4 17h10"/></svg>
        <span class="visualmente-oculto">Abrir menu</span>
    </button>
    <span class="barra-movil__marca"><span class="sb__simbolo"><img src="/img/logo.png" alt=""></span><span class="sb__nombre">Sinerg<span>IA</span></span></span>
</div>
<div class="sidebar-fondo" data-menu-cerrar hidden></div>
<nav class="sidebar sb" id="menu-lateral" aria-label="Menu principal">
    <div class="sb__cabecera">
        <a class="sb__marca" href="/panel">
            <span class="sb__simbolo"><img src="/img/logo.png" alt=""></span>
            <span class="sb__marca-texto">
                <span class="sb__nombre">Sinerg<span>IA</span></span>
                <span class="sb__lema">Facturacion electronica</span>
            </span>
            <span class="visualmente-oculto"> (ir al dashboard)</span>
        </a>
        <button type="button" class="sb__cerrar" data-menu-cerrar hidden>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg>
            <span class="visualmente-oculto">Cerrar menu</span>
        </button>
    </div>
    <?php /* Contraer / expandir (solo escritorio). Va sobre el borde del menu. */ ?>
    <button type="button" class="sb__contraer" data-menu-contraer aria-controls="menu-lateral" aria-pressed="false" hidden>
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M15 6l-6 6 6 6"/></svg>
        <span class="visualmente-oculto" data-menu-contraer-texto>Contraer menu</span>
    </button>

    <?php /* BUSCAR EN EL MENU (js/menu-lateral.js). Atajo: la tecla "/". */ ?>
    <div class="sb__buscar" role="search" hidden>
        <label for="menu-buscar" class="visualmente-oculto">Buscar en el menu</label>
        <?= iconoSvg('buscar', 16, 'sb__buscar-icono'); ?>
        <input type="search" id="menu-buscar" data-menu-buscar placeholder="Buscar..." autocomplete="off" spellcheck="false" aria-describedby="menu-buscar-estado">
        <kbd class="sb__atajo" aria-hidden="true">/</kbd>
    </div>
    <p class="visualmente-oculto" id="menu-buscar-estado" role="status" aria-live="polite"></p>

    <div class="sb__menu" data-menu-items>
        <p class="sb__vacio" data-menu-vacio hidden>Ninguna pantalla se llama asi.</p>
        <?php foreach ($navBloques as $navBloque): ?>
            <div class="sb__bloque">
                <?php if ($navBloque['titulo'] !== ''): ?>
                    <p class="sb__seccion"><span><?= htmlspecialchars($navBloque['titulo']); ?></span></p>
                <?php endif; ?>
                <?php foreach ($navBloque['nodos'] as $seccion): ?>
                    <?php if (isset($seccion['items'])): ?>
                        <?php $navContiene = $navSeccionContieneActual($seccion); $navLabel = htmlspecialchars((string) $seccion['label']); ?>
                        <details class="sb-grupo<?= $navContiene ? ' sb-grupo--actual' : ''; ?>" data-grupo="<?= htmlspecialchars($navClaveGrupo((string) $seccion['label'])); ?>"<?= $navContiene ? ' open' : ''; ?>>
                            <summary class="sb-item sb-item--grupo" data-tip="<?= $navLabel; ?>"><?= $navIcono($seccion['icono'] ?? null); ?><span class="sb-item__texto"><?= $navLabel; ?></span><?= $navChevron; ?></summary>
                            <div class="sb-grupo__opciones">
                                <?php $navRenderOpciones($seccion['items']); ?>
                            </div>
                        </details>
                    <?php else: ?>
                        <?php $navPintarItem($seccion); ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="sb__pie">
        <div class="sb__cuenta">
            <span class="sb__avatar" aria-hidden="true"><?= htmlspecialchars($navInicial); ?></span>
            <span class="sb__cuenta-texto">
                <?php if ($navEmpresa !== ''): ?><span class="sb__empresa" title="<?= htmlspecialchars($navEmpresa); ?>"><?= htmlspecialchars($navEmpresa); ?></span><?php endif; ?>
                <?php if ($navCorreo !== ''): ?><span class="sb__correo" title="<?= htmlspecialchars($navCorreo); ?>"><?= htmlspecialchars($navCorreo); ?></span><?php endif; ?>
            </span>
            <?php /* href="/logout": js/cerrar-sesion.js lo intercepta para la despedida. */ ?>
            <a class="sb__salir" href="/logout" data-tip="Cerrar sesion"><?= iconoSvg('logout', 18, ''); ?><span class="visualmente-oculto">Cerrar sesion</span></a>
        </div>
    </div>
</nav>
<?php /* SIN defer: corre apenas existe el menu y antes de pintar, asi los
         grupos recordados aparecen abiertos de una, sin parpadeo. */ ?>
<?php $jsMenuVersion = @filemtime(__DIR__ . '/../../public/js/menu-lateral.js'); ?>
<script src="/js/menu-lateral.js<?= $jsMenuVersion ? '?v=' . $jsMenuVersion : ''; ?>"></script>
