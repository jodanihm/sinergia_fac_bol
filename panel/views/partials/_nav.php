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
 * Pinta un unico item segun su estado. Closure para no declarar funciones en un
 * partial (evita "cannot redeclare" si el archivo se incluye mas de una vez).
 */
$navPintarItem = static function (array $item) use ($navEnProduccion, $navRutaActual, $navActivoClave): void {
    $label   = htmlspecialchars((string) $item['label']);
    $sub     = ! empty($item['sub']) ? ' nav-item--sub' : '';
    $estado  = navEstadoItem($item, $navEnProduccion);

    if ($estado === 'habilitado') {
        $destino = (string) $item['destino'];
        $activo  = ($navActivoClave !== null && ($item['clave'] ?? null) === $navActivoClave)
            || ($navActivoClave === null && $destino === $navRutaActual);
        $clase   = 'nav-item' . $sub . ($activo ? ' nav-item--activo' : '');
        echo '<a class="' . $clase . '" href="' . htmlspecialchars($destino) . '">' . $label . '</a>';
        return;
    }

    if ($estado === 'bloqueado_cert') {
        echo '<span class="nav-item nav-item--bloqueado' . $sub . '" '
            . 'title="Disponible cuando completes la certificacion en el SII">'
            . $label . '<span class="nav-item__badge">En certificacion</span></span>';
        return;
    }

    // proximamente (construido=false)
    echo '<span class="nav-item nav-item--proximo' . $sub . '" '
        . 'title="Este modulo aun no esta disponible">'
        . $label . '<span class="nav-item__badge">Proximamente</span></span>';
};

/**
 * Render recursivo: un nodo puede ser un item (tiene 'label' y estado) o un
 * subgrupo (tiene 'items' anidados, ej. Ventas > Emision).
 */
$navRender = static function (array $nodos) use (&$navRender, $navPintarItem): void {
    foreach ($nodos as $nodo) {
        if (isset($nodo['items'])) {
            echo '<div class="sidebar__subgrupo">';
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
    <div class="sidebar__marca">Sinergia</div>
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
        <a class="nav-item" href="/logout">Cerrar sesion</a>
    </div>
</nav>
