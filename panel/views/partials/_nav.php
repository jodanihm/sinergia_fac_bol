<?php
/**
 * Menu lateral del panel. Solo se incluye desde header.php cuando hay sesion.
 *
 * Deriva el estado de cada item de DOS ejes independientes (ver definicionMenu()
 * y navEstadoItem() en public/index.php):
 *   - construido / no_construido  -> "proximamente" (gana siempre; nunca culpa al tenant)
 *   - requiereProduccion + tenantEnProduccion() -> "bloqueado_cert"
 *   - resto -> "habilitado" (link)
 *
 * El item activo se marca por $navActivo (clave, opcional, que la vista puede
 * pasar) o, si no viene, comparando el destino con la ruta actual.
 */

$navPdo          = Db::conexion();
$navEnProduccion = tenantEnProduccion($navPdo, Auth::cuentaId());
$navRutaActual   = rtrim((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'), '/');
if ($navRutaActual === '') {
    $navRutaActual = '/';
}
$navActivoClave = isset($navActivo) ? (string) $navActivo : null;

/**
 * Iconos del menu. Clave = el identificador que trae cada item en
 * definicionMenu()['icono']; valor = el CONTENIDO del <svg> (sin la etiqueta,
 * que la pone $navIcono con los atributos comunes).
 *
 * DE DONDE SALEN: Feather Icons (licencia MIT), copiados a mano. El proyecto no
 * usa ninguna libreria de iconos ni CDN y esto no lo cambia: son paths estaticos
 * dentro del PHP, sin dependencia nueva ni peticion de red.
 *
 * POR QUE VIVE AQUI Y NO EN index.php: es markup de presentacion y este partial
 * es su unico consumidor. index.php ya son 9280 lineas de router y datos.
 *
 * VIEWBOX 24x24 en todos, igual que los tres <svg> del login. Los atributos de
 * trazo (fill, stroke, stroke-width, linecap, linejoin) NO se repiten en cada
 * path como en login.php: son atributos de presentacion SVG y se HEREDAN, asi
 * que van una sola vez en el <svg> de $navIcono. El resultado renderizado es el
 * mismo y evita repetirlos unas sesenta veces.
 *
 * CUATRO IDENTIFICADORES SE COMPARTEN entre certificacion y produccion
 * (empresa, certificado, caf, apikeys): un mismo icono, una sola entrada aqui.
 * Que los dos ambientes se distingan es trabajo del encabezado del subgrupo y
 * de su color, no del icono.
 */
const NAV_ICONOS = [
    // -- Dashboard --
    'dashboard' => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>'
        . '<polyline points="9 22 9 12 15 12 15 22"/>',

    // -- Ventas --
    'factura' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>'
        . '<polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/>'
        . '<line x1="16" y1="17" x2="8" y2="17"/>',
    // Direccionales y opuestos entre si: NC y ND van seguidos en el menu y con
    // nombres casi iguales, asi que el icono tiene que separarlos de un vistazo.
    'nota-credito' => '<polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/>',
    'nota-debito'  => '<polyline points="15 14 20 9 15 4"/><path d="M4 20v-7a4 4 0 0 1 4-4h12"/>',
    'carga-masiva' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>'
        . '<polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
    'facturacion-masiva' => '<polygon points="12 2 2 7 12 12 22 7 12 2"/>'
        . '<polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
    'panel-emision' => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>'
        . '<rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',

    // -- Maestros --
    'clientes' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>'
        . '<path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'productos' => '<line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/>'
        . '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>'
        . '<polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',

    // -- Informes --
    'informe-tipos' => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/>'
        . '<line x1="6" y1="20" x2="6" y2="14"/>',
    'informe-dia' => '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>',
    'informe-clientes' => '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>'
        . '<circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/>',
    'informe-estados' => '<polyline points="9 11 12 14 22 4"/>'
        . '<path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
    'informe-detalle' => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/>'
        . '<line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/>'
        . '<line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
    'informe-folios' => '<line x1="4" y1="9" x2="20" y2="9"/><line x1="4" y1="15" x2="20" y2="15"/>'
        . '<line x1="10" y1="3" x2="8" y2="21"/><line x1="16" y1="3" x2="14" y2="21"/>',

    // -- Configuracion empresa (los cuatro primeros, en los DOS ambientes) --
    'empresa' => '<rect x="2" y="7" width="20" height="14" rx="2" ry="2"/>'
        . '<path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
    'certificado' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
    'caf' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>'
        . '<polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/>'
        . '<line x1="9" y1="15" x2="15" y2="15"/>',
    'apikeys' => '<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>',
    'certificacion-sii' => '<circle cx="12" cy="8" r="7"/>'
        . '<polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/>',

    // -- Transversales --
    'usuarios' => '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/>'
        . '<line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/>',
    'auditoria' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>'
        . '<polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
];

/**
 * Devuelve el <svg> de un identificador, o cadena vacia si no existe.
 *
 * El fallback importa: un item sin 'icono', o con un identificador mal escrito,
 * se pinta sin icono en vez de romper el menu completo.
 *
 * width/height van como ATRIBUTO ademas de la regla CSS, mismo criterio que los
 * <svg> del login: un svg con viewBox pero sin dimensiones intrinsecas se
 * estira hasta llenar su contenedor cuando el CSS no llega. Medido en
 * produccion: 1409x1409 px en un viewport de 1440 (commit 4d86f20).
 *
 * aria-hidden + focusable=false porque el icono es decorativo: el texto del
 * enlace ya dice a donde va. Anunciarlo dos veces solo estorba a quien navega
 * con lector de pantalla, y focusable=false evita que entre en el orden de
 * tabulacion.
 */
$navIcono = static function (?string $id): string {
    $id = (string) $id;
    if ($id === '' || ! isset(NAV_ICONOS[$id])) {
        return '';
    }

    return '<svg class="nav-item__icono" width="18" height="18" viewBox="0 0 24 24"'
        . ' fill="none" stroke="currentColor" stroke-width="1.6"'
        . ' stroke-linecap="round" stroke-linejoin="round"'
        . ' aria-hidden="true" focusable="false">' . NAV_ICONOS[$id] . '</svg>';
};

/**
 * Pinta un unico item segun su estado. Closure para no declarar funciones en un
 * partial (evita "cannot redeclare" si el archivo se incluye mas de una vez).
 */
$navPintarItem = static function (array $item) use ($navEnProduccion, $navRutaActual, $navActivoClave, $navIcono): void {
    $label   = htmlspecialchars((string) $item['label']);
    $sub     = ! empty($item['sub']) ? ' nav-item--sub' : '';
    $estado  = navEstadoItem($item, $navEnProduccion);
    $icono   = $navIcono($item['icono'] ?? null);

    if ($estado === 'habilitado') {
        $destino = (string) $item['destino'];
        $activo  = ($navActivoClave !== null && ($item['clave'] ?? null) === $navActivoClave)
            || ($navActivoClave === null && $destino === $navRutaActual);
        $clase   = 'nav-item' . $sub . ($activo ? ' nav-item--activo' : '');
        echo '<a class="' . $clase . '" href="' . htmlspecialchars($destino) . '">' . $icono . $label . '</a>';
        return;
    }

    if ($estado === 'bloqueado_cert') {
        echo '<span class="nav-item nav-item--bloqueado' . $sub . '" '
            . 'title="Disponible cuando completes la certificacion en el SII">'
            . $icono . $label . '<span class="nav-item__badge">En certificacion</span></span>';
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
$navRender = static function (array $nodos) use (&$navRender, $navPintarItem): void {
    foreach ($nodos as $nodo) {
        if (isset($nodo['items'])) {
            $variante = preg_replace('/[^a-z-]/', '', (string) ($nodo['variante'] ?? ''));
            $claseSub = 'sidebar__subgrupo' . ($variante !== '' ? ' sidebar__subgrupo--' . $variante : '');
            echo '<div class="' . $claseSub . '">';
            echo '<div class="sidebar__subtitulo">' . htmlspecialchars((string) $nodo['label']) . '</div>';
            $navRender($nodo['items']);
            echo '</div>';
            continue;
        }
        $navPintarItem($nodo);
    }
};

$navMenu = definicionMenu();
?>
<nav class="sidebar" aria-label="Menu principal">
    <div class="sidebar__marca">
        <img src="/img/logo.png" alt="Sinergia" class="sidebar__logo">
    </div>
    <?php foreach ($navMenu as $seccion): ?>
        <?php if (isset($seccion['items'])): ?>
            <div class="sidebar__grupo">
                <div class="sidebar__grupo-titulo"><?= htmlspecialchars((string) $seccion['label']); ?></div>
                <?php $navRender($seccion['items']); ?>
            </div>
        <?php else: ?>
            <div class="sidebar__grupo"><?php $navPintarItem($seccion); ?></div>
        <?php endif; ?>
    <?php endforeach; ?>
    <div class="sidebar__pie">
        <?php /* Fuera de definicionMenu() porque no es navegacion sino salida de
                 la sesion, pero lleva icono igual que el resto: si fuera el unico
                 enlace sin uno, se leeria como un elemento a medio terminar. */ ?>
        <a class="nav-item" href="/logout"><?= $navIcono('logout'); ?>Cerrar sesion</a>
    </div>
</nav>
