<?php
/**
 * Menu lateral del panel. Solo se incluye desde header.php cuando hay sesion.
 *
 * Deriva el estado de cada item de DOS ejes independientes (ver definicionMenu()
 * y navEstadoItem() en public/index.php):
 *   - construido / no_construido  -> "proximamente" (gana siempre; nunca culpa al tenant)
 *   - requiereProduccion + estadoEmisionProduccion() -> "sin_produccion"
 *   - resto -> "habilitado" (link)
 *
 * EL SEGUNDO EJE ES EL MISMO CRITERIO QUE EL SERVIDOR. Se pregunta por las 3
 * filas de produccion, exactamente lo que exige exigirProduccionCompleto() en
 * cada ruta operativa. Antes se preguntaba por certificacion_confirmada_at, que
 * es otra cosa, y el menu contradecia al guard en los dos sentidos.
 *
 * El item activo se marca por $navActivo (clave, opcional, que la vista puede
 * pasar) o, si no viene, comparando el destino con la ruta actual.
 */

/* El mapa de iconos vivia aqui hasta la entrega D1, con la nota de que se
   extraeria en cuanto otra vista lo necesitara. El dashboard lo necesito.
   require_once y no require: panel-gestion.php tambien lo carga en la misma
   peticion, y una constante o funcion redeclarada es error fatal. */
require_once __DIR__ . '/_iconos.php';

$navPdo = Db::conexion();
/* 'falta' === null significa que estan las 3 filas de produccion, o sea que el
   guard del servidor dejaria pasar. Se descarta el rut: aqui no se usa. */
$navPuedeEmitir = estadoEmisionProduccion($navPdo, Auth::cuentaId())['falta'] === null;
$navRutaActual  = rtrim((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'), '/');
if ($navRutaActual === '') {
    $navRutaActual = '/';
}
$navActivoClave = isset($navActivo) ? (string) $navActivo : null;

/**
 * Envoltorio del pintor generico con los valores del sidebar: 18px y la clase
 * .nav-item__icono. Se conserva como closure porque lo capturan por 'use' los
 * dos closures de abajo, y para que el cuerpo de $navPintarItem no cambie.
 *
 * El fallback (identificador vacio o inexistente -> cadena vacia) y el porque de
 * width/height explicitos, aria-hidden y focusable=false estan documentados en
 * iconoSvg(), en partials/_iconos.php.
 */
$navIcono = static function (?string $id): string {
    return iconoSvg($id, 18, 'nav-item__icono');
};

/**
 * Pinta un unico item segun su estado. Closure para no declarar funciones en un
 * partial (evita "cannot redeclare" si el archivo se incluye mas de una vez).
 */
$navPintarItem = static function (array $item, ?string $iconoGrupo = null) use ($navPuedeEmitir, $navRutaActual, $navActivoClave, $navIcono): void {
    $label   = htmlspecialchars((string) $item['label']);
    $sub     = ! empty($item['sub']) ? ' nav-item--sub' : '';
    $estado  = navEstadoItem($item, $navPuedeEmitir);
    // OPCIONES DE UN GRUPO SIN ICONO (noviembre 2026). Antes heredaban el del
    // grupo: Ventas pintaba el mismo documento doce veces seguidas, y un
    // dibujo repetido no distingue nada -- solo agrega ruido. La pertenencia
    // al grupo la dicen la sangria y la linea vertical; la etiqueta dice cual.
    // Los items sueltos (Dashboard, Asistente IA) conservan el suyo.
    $icono   = $iconoGrupo !== null ? '' : $navIcono($item['icono'] ?? null);

    if ($estado === 'habilitado') {
        $destino = (string) $item['destino'];
        $activo  = ($navActivoClave !== null && ($item['clave'] ?? null) === $navActivoClave)
            || ($navActivoClave === null && $destino === $navRutaActual);
        $clase   = 'nav-item' . $sub . ($activo ? ' nav-item--activo' : '');
        echo '<a class="' . $clase . '" href="' . htmlspecialchars($destino) . '">' . $icono . $label . '</a>';
        return;
    }

    /* La clase CSS conserva su nombre historico (--bloqueado) aunque el estado
       se llame ahora sin_produccion: renombrarla cambiaria el HTML del sidebar
       y costaria la verificacion byte a byte, sin ganancia funcional. */
    if ($estado === 'sin_produccion') {
        echo '<span class="nav-item nav-item--bloqueado' . $sub . '" '
            . 'title="Disponible cuando cargues empresa, certificado y CAF de produccion">'
            . $icono . $label . '<span class="nav-item__badge">Sin configurar</span></span>';
        return;
    }

    // proximamente (construido=false)
    echo '<span class="nav-item nav-item--proximo' . $sub . '" '
        . 'title="Este modulo aun no esta disponible">'
        . $icono . $label . '<span class="nav-item__badge">Proximamente</span></span>';
};

/**
 * Render recursivo: un nodo puede ser un item (tiene 'label' y estado) o un
 * subgrupo (tiene 'items' anidados, ej. Ventas > Emision).
 *
 * 'variante' es OPCIONAL y solo la usan hoy los dos subgrupos de ambiente de
 * "Configuracion empresa": agrega una clase modificadora al contenedor para que
 * el CSS pueda marcar el bloque de produccion. Un subgrupo sin ella se pinta
 * exactamente como antes. El valor se sanea contra [a-z-] porque termina dentro
 * de un atributo class.
 */
$navRender = static function (array $nodos, ?string $iconoGrupo = null) use (&$navRender, $navPintarItem): void {
    foreach ($nodos as $nodo) {
        if (isset($nodo['items'])) {
            $variante = preg_replace('/[^a-z-]/', '', (string) ($nodo['variante'] ?? ''));
            $claseSub = 'sidebar__subgrupo' . ($variante !== '' ? ' sidebar__subgrupo--' . $variante : '');
            echo '<div class="' . $claseSub . '">';
            echo '<div class="sidebar__subtitulo">' . htmlspecialchars((string) $nodo['label']) . '</div>';
            $navRender($nodo['items'], $iconoGrupo);
            echo '</div>';
            continue;
        }
        $navPintarItem($nodo, $iconoGrupo);
    }
};

/**
 * Si la pagina actual pertenece a una seccion plegable: alguno de sus items es
 * el activo (mismo criterio que $navPintarItem) o la ruta cae bajo uno de sus
 * 'prefijos'. Decide si el <details> se pinta abierto, para que nadie aterrice
 * en una pantalla de configuracion con su grupo cerrado.
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
        // Una pantalla hija del destino (/maestros/clientes/7/editar) tambien
        // abre el grupo: sin esto, editar un cliente lo dejaba cerrado.
        $destino = (string) ($nodo['destino'] ?? '');
        if ($destino !== '' && $destino !== '/' && str_starts_with($navRutaActual, $destino . '/')) {
            return true;
        }
    }

    return false;
};

$navMenu = definicionMenu();

/* QUIEN ESTA CONECTADO, al pie del menu: la empresa (la que se esta viendo, si
   un superadmin abrio otra cuenta) y el correo del usuario. Si la consulta
   falla el pie queda solo con "Cerrar sesion", como antes. */
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

/* Clave estable de cada grupo plegable, para recordar en el navegador cuales
   dejo abiertos la persona (js/menu-lateral.js). Sale del label: es unico. */
$navClaveGrupo = static function (string $label): string {
    return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($label)), '-');
};
?>
<?php /* BARRA SUPERIOR EN MOVIL (js/menu-lateral.js). Oculta en escritorio y
         sin JavaScript: sin JS el menu sigue apilado arriba, como siempre. */ ?>
<div class="barra-movil" hidden>
    <button type="button" class="barra-movil__menu" data-menu-abrir aria-controls="menu-lateral" aria-expanded="false">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        <span class="visualmente-oculto">Abrir menu</span>
    </button>
    <img src="/img/logo.png" alt="Sinergia" class="barra-movil__logo">
</div>
<div class="sidebar-fondo" data-menu-cerrar hidden></div>
<nav class="sidebar" id="menu-lateral" aria-label="Menu principal">
    <div class="sidebar__marca">
        <img src="/img/logo.png" alt="Sinergia" class="sidebar__logo">
        <button type="button" class="sidebar__cerrar" data-menu-cerrar hidden>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg>
            <span class="visualmente-oculto">Cerrar menu</span>
        </button>
    </div>
    <?php /* BUSCAR EN EL MENU (js/menu-lateral.js): filtra mientras se escribe,
             abre los grupos con coincidencias y Enter lleva a la primera. Atajo:
             la tecla "/". Oculto sin JavaScript, donde no haria nada. */ ?>
    <div class="sidebar__buscar" role="search" hidden>
        <label for="menu-buscar" class="visualmente-oculto">Buscar en el menu</label>
        <?= iconoSvg('buscar', 16, 'sidebar__buscar-icono'); ?>
        <input type="search" id="menu-buscar" data-menu-buscar placeholder="Buscar en el menu" autocomplete="off" spellcheck="false" aria-describedby="menu-buscar-estado">
        <kbd class="sidebar__atajo" aria-hidden="true">/</kbd>
    </div>
    <p class="visualmente-oculto" id="menu-buscar-estado" role="status" aria-live="polite"></p>
    <p class="sidebar__sin-resultados" data-menu-vacio hidden>Ninguna pantalla se llama asi.</p>
    <div class="sidebar__menu" data-menu-items>
    <?php foreach ($navMenu as $seccion): ?>
        <?php if (isset($seccion['items']) && ! empty($seccion['plegable'])): ?>
            <?php /* GRUPO DESPLEGABLE. <details> nativo: se abre y cierra con
                     teclado y lector de pantalla sin una linea de JS. El
                     summary lleva el icono del grupo, y las opciones de
                     adentro heredan ESE icono. Abierto si la pagina actual es
                     suya; --activo marca el summary cuando esta cerrado pero
                     contiene la pagina (no pasa al cargar, si al plegarlo). */ ?>
            <?php $navContiene = $navSeccionContieneActual($seccion); ?>
            <details class="sidebar__grupo sidebar__grupo--plegable<?= $navContiene ? ' sidebar__grupo--actual' : ''; ?>" data-grupo="<?= htmlspecialchars($navClaveGrupo((string) $seccion['label'])); ?>"<?= $navContiene ? ' open' : ''; ?>>
                <summary class="nav-grupo"><?= $navIcono($seccion['icono'] ?? null); ?><span class="nav-grupo__texto"><?= htmlspecialchars((string) $seccion['label']); ?></span></summary>
                <div class="nav-grupo__opciones">
                    <?php $navRender($seccion['items'], isset($seccion['icono']) ? (string) $seccion['icono'] : null); ?>
                </div>
            </details>
        <?php elseif (isset($seccion['items'])): ?>
            <div class="sidebar__grupo">
                <div class="sidebar__grupo-titulo"><?= htmlspecialchars((string) $seccion['label']); ?></div>
                <?php $navRender($seccion['items']); ?>
            </div>
        <?php else: ?>
            <div class="sidebar__grupo"><?php $navPintarItem($seccion); ?></div>
        <?php endif; ?>
    <?php endforeach; ?>
    </div>
    <div class="sidebar__pie">
        <?php if ($navEmpresa !== '' || $navCorreo !== ''): ?>
            <div class="sidebar__cuenta">
                <span class="sidebar__avatar" aria-hidden="true"><?= htmlspecialchars($navInicial); ?></span>
                <span class="sidebar__cuenta-texto">
                    <?php if ($navEmpresa !== ''): ?><span class="sidebar__empresa" title="<?= htmlspecialchars($navEmpresa); ?>"><?= htmlspecialchars($navEmpresa); ?></span><?php endif; ?>
                    <?php if ($navCorreo !== ''): ?><span class="sidebar__correo" title="<?= htmlspecialchars($navCorreo); ?>"><?= htmlspecialchars($navCorreo); ?></span><?php endif; ?>
                </span>
            </div>
        <?php endif; ?>
        <?php /* Fuera de definicionMenu() porque no es navegacion sino salida de
                 la sesion, pero lleva icono igual que el resto: si fuera el unico
                 enlace sin uno, se leeria como un elemento a medio terminar. */ ?>
        <a class="nav-item" href="/logout"><?= $navIcono('logout'); ?>Cerrar sesion</a>
    </div>
</nav>
<?php /* SIN defer, a proposito: corre apenas existe el menu y ANTES de pintar,
         asi los grupos que la persona dejo abiertos aparecen abiertos de una,
         sin parpadeo. Es chico y no espera nada de la red. */ ?>
<?php $jsMenuVersion = @filemtime(__DIR__ . '/../../public/js/menu-lateral.js'); ?>
<script src="/js/menu-lateral.js<?= $jsMenuVersion ? '?v=' . $jsMenuVersion : ''; ?>"></script>
