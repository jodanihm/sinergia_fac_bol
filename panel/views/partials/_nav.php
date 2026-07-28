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

/* El mapa de iconos vivia aqui hasta la entrega D1, con la nota de que se
   extraeria en cuanto otra vista lo necesitara. El dashboard lo necesito.
   require_once y no require: panel-gestion.php tambien lo carga en la misma
   peticion, y una constante o funcion redeclarada es error fatal. */
require_once __DIR__ . '/_iconos.php';

$navPdo          = Db::conexion();
$navEnProduccion = tenantEnProduccion($navPdo, Auth::cuentaId());
$navRutaActual   = rtrim((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'), '/');
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
