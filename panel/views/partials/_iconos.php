<?php
/**
 * Mapa de iconos compartido del panel.
 *
 * SE EXTRAJO DE _nav.php EN LA ENTREGA D1. Vivia ahi porque el sidebar era su
 * unico consumidor, con la nota de que se sacaria en cuanto otra vista lo
 * necesitara. Esa vista es el dashboard: cinco de sus siete encabezados de
 * seccion corresponden uno a uno con un informe que ya tenia icono aqui
 * (informe-tipos, informe-dia, informe-clientes, informe-estados,
 * informe-folios). Reusarlos no es solo ahorrar codigo: es lo que garantiza que
 * un informe y su seccion del dashboard se dibujen igual. Los otros dos,
 * 'alerta' y 'accesos-rapidos', se agregaron aqui abajo.
 *
 * SE INCLUYE SIEMPRE CON require_once. Una constante o una funcion redeclarada
 * es error fatal en PHP, y este archivo lo cargan dos consumidores distintos
 * (_nav.php desde header.php, y panel-gestion.php) en la misma peticion.
 *
 * DE DONDE SALEN: Feather Icons (licencia MIT), copiados a mano. El proyecto no
 * usa ninguna libreria de iconos ni CDN y esto no lo cambia: son paths estaticos
 * dentro del PHP, sin dependencia nueva ni peticion de red.
 *
 * VIEWBOX 24x24 en todos, igual que los tres <svg> del login. Los atributos de
 * trazo (fill, stroke, stroke-width, linecap, linejoin) NO se repiten en cada
 * path como en login.php: son atributos de presentacion SVG y se HEREDAN, asi
 * que van una sola vez en el <svg> de iconoSvg(). El resultado renderizado es el
 * mismo y evita repetirlos unas sesenta veces.
 *
 * CUATRO IDENTIFICADORES SE COMPARTEN entre certificacion y produccion
 * (empresa, certificado, caf, apikeys): un mismo icono, una sola entrada aqui.
 * Que los dos ambientes se distingan es trabajo del encabezado del subgrupo y
 * de su color, no del icono.
 *
 * Clave = el identificador que trae cada item en definicionMenu()['icono'], o el
 * que pide la vista; valor = el CONTENIDO del <svg> (sin la etiqueta, que la
 * pone iconoSvg() con los atributos comunes).
 */
const ICONOS = [
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
    // Sobre cerrado. Clave de dominio, como el resto: nombra la COLA de envio de
    // documentos al receptor (tabla dte_envio_correo), no la forma del dibujo.
    // Entrada nueva y no reuso de otra: ninguna de las 27 que ya habia significa
    // "correo", y prestar un icono que quiere decir otra cosa confunde el menu.
    'envio-correo' => '<rect x="2" y="4" width="20" height="16" rx="2"/>'
        . '<polyline points="22 6 12 13 2 6"/>',

    // -- Maestros --
    'clientes' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>'
        . '<path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'productos' => '<line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/>'
        . '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>'
        . '<polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',

    // -- Informes. Los cinco los comparte el dashboard, ver la cabecera. --
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

    // -- Solo dashboard --
    // "Documentos con problemas". Es el unico encabezado de seccion que no tiene
    // un informe equivalente, porque la clasificacion de codigos del SII todavia
    // no existe: por eso la tarjeta lleva el badge "Proximamente".
    'alerta' => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>'
        . '<line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
    // "Accesos rapidos". Ninguno de los iconos que ya estaban sirve: son todos de
    // un destino concreto y esta seccion no lleva a un sitio sino a cuatro. El
    // rayo de Feather es el gesto habitual para "atajo". Clave de dominio, como
    // el resto del mapa, y no el nombre del icono en Feather ('zap').
    'accesos-rapidos' => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
    // Los dos KPI de plata. 'factura' e 'informe-folios' cubren los otros dos, que
    // ya existian. Claves de dominio y no los nombres de Feather ('dollar-sign' y
    // 'percent'): lo que importa aqui es que uno es un monto y el otro un impuesto.
    'monto' => '<line x1="12" y1="1" x2="12" y2="23"/>'
        . '<path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
    'impuesto' => '<line x1="19" y1="5" x2="5" y2="19"/>'
        . '<circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
];

/**
 * Devuelve el <svg> de un identificador, o cadena vacia si no existe.
 *
 * El fallback importa: un item sin icono, o con un identificador mal escrito, se
 * pinta sin icono en vez de romper la pagina completa.
 *
 * width/height van como ATRIBUTO ademas de la regla CSS, mismo criterio que los
 * <svg> del login: un svg con viewBox pero sin dimensiones intrinsecas se
 * estira hasta llenar su contenedor cuando el CSS no llega. Medido en
 * produccion: 1409x1409 px en un viewport de 1440 (commit 4d86f20).
 *
 * aria-hidden + focusable=false porque el icono es decorativo. En el menu, el
 * texto del enlace ya dice a donde va. En un encabezado importa todavia mas:
 * varios <h2> del dashboard son la fuente del nombre accesible de su <section>
 * via aria-labelledby, y sin aria-hidden el contenido del <svg> se cuela dentro
 * de ese nombre. Medido con el motor de Chrome via CDP: con un <title> dentro
 * del svg y SIN aria-hidden, el nombre pasaba a ser "Icono de tabla Facturacion
 * por tipo de documento"; con aria-hidden queda intacto aunque el <title> siga
 * ahi. focusable=false ademas lo saca del orden de tabulacion.
 *
 * EL ORDEN DE LOS ATRIBUTOS NO ES CAPRICHO. Reproduce exactamente el que emitia
 * $navIcono en _nav.php antes de esta extraccion, para que el HTML del sidebar
 * salga byte a byte igual que antes. Cambiarlo no rompe nada visible, pero
 * invalida esa comprobacion.
 *
 * @param string|null $id      clave en ICONOS
 * @param int         $tamano  px del width/height explicitos
 * @param string      $clase   clase CSS del <svg>
 */
function iconoSvg(?string $id, int $tamano, string $clase): string
{
    $id = (string) $id;
    if ($id === '' || ! isset(ICONOS[$id])) {
        return '';
    }

    return '<svg class="' . htmlspecialchars($clase) . '" width="' . $tamano . '" height="' . $tamano . '"'
        . ' viewBox="0 0 24 24"'
        . ' fill="none" stroke="currentColor" stroke-width="1.6"'
        . ' stroke-linecap="round" stroke-linejoin="round"'
        . ' aria-hidden="true" focusable="false">' . ICONOS[$id] . '</svg>';
}
