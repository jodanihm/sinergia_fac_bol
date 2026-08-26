<?php

declare(strict_types=1);

/**
 * Iconos del sidebar del panel de control.
 *
 * Este archivo SOLO devuelve un array: la clave es la del item en _nav.php y el
 * valor es el INTERIOR de un <svg>. El envoltorio -- viewBox, tamano, grosor de
 * linea, aria-hidden -- lo pone _nav.php una sola vez, para que catorce iconos
 * no puedan terminar con catorce tamanos distintos.
 *
 * SVG EN LINEA, NO UNA FUENTE DE ICONOS NI UN CDN. Una fuente (Font Awesome,
 * Material Icons) son cientos de kB y una peticion mas para dibujar una docena
 * de simbolos, y si se sirve desde un CDN el panel deja de funcionar cuando ese
 * CDN no responde -- este panel corre detras de un tunel y se administra desde
 * Tailscale, o sea justo donde no se quiere depender de un tercero para ver el
 * menu. Vendorizar la fuente arregla eso y sigue pesando cien veces mas que
 * esto.
 *
 * TODO ES stroke="currentColor" Y NUNCA UN COLOR FIJO. Es lo que hace que el
 * icono siga al texto: el mismo gris apagado en reposo, el mismo blanco en
 * hover, el mismo blanco en el item activo. Con colores fijos habria que
 * escribir una regla de color por estado y por icono, y el dia que cambie un
 * token del tema quedarian todos del color viejo.
 *
 * GEOMETRIA SIMPLE A PROPOSITO: rectangulos, circulos, lineas y polilineas.
 * A este tamano -- 17 px -- una curva elaborada se convierte en una mancha, y
 * ademas una forma que se puede leer en el codigo es una forma que se puede
 * corregir sin abrir un editor vectorial.
 *
 * SON DECORATIVOS, NO INFORMACION. Al lado de cada uno esta la palabra que
 * nombra la pantalla; el icono ayuda a encontrarla de reojo cuando ya se sabe
 * cual es. Por eso _nav.php los marca aria-hidden: un lector de pantalla que
 * anuncie "imagen, calendario, Tareas programadas" dice la misma cosa dos
 * veces.
 *
 * QUE NO SE REPITAN LAS METAFORAS. Tareas y Auditoria empezaron los dos con un
 * reloj y a 17 px eran indistinguibles, que es peor que no tener icono: un
 * simbolo ambiguo obliga a leer la palabra igual, pero primero hace dudar.
 * Quedaron calendario (algo que va a pasar) e historial (algo que ya paso).
 */

return [
    // Panel: la retícula del tablero.
    'panel' => '<rect x="3" y="3" width="7" height="8" rx="1"/>'
        . '<rect x="14" y="3" width="7" height="5" rx="1"/>'
        . '<rect x="14" y="11" width="7" height="10" rx="1"/>'
        . '<rect x="3" y="14" width="7" height="7" rx="1"/>',

    // Cuentas: un edificio. Son empresas clientes, no personas -- por eso no es
    // el icono de usuarios, que aqui significaria los usuarios DE una cuenta.
    'cuentas' => '<rect x="5" y="3" width="14" height="18" rx="1.5"/>'
        . '<path d="M9 7h2M13 7h2M9 11h2M13 11h2"/>'
        . '<path d="M10.5 21v-4h3v4"/>',

    // Tareas programadas: calendario con marcas. Lo que va a pasar.
    'tareas' => '<rect x="3" y="5" width="18" height="16" rx="2"/>'
        . '<path d="M3 10h18M8 3v4M16 3v4"/>'
        . '<circle cx="8" cy="15" r="1"/><circle cx="12" cy="15" r="1"/><circle cx="16" cy="15" r="1"/>',

    // Auditoria: reloj con la flecha hacia atras. Lo que ya paso.
    'auditoria' => '<path d="M3 12a9 9 0 1 0 3-6.7"/>'
        . '<path d="M3 4v5h5"/>'
        . '<path d="M12 8v4l3 2"/>',

    // Integraciones: dos eslabones. Lo que conecta con afuera.
    'integraciones' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>'
        . '<path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',

    // Migraciones: escalones que suben hacia una flecha. Es lo que son -- pasos
    // que se aplican uno sobre otro y solo van hacia adelante -- y no se parece
    // al cilindro de Base de datos, que esta justo debajo en el menu.
    'migraciones' => '<path d="M3 20h5v-4h5v-4h5V8"/>'
        . '<path d="M15.5 10.5 18 8l2.5 2.5"/>',

    // Base de datos: el cilindro de siempre.
    'base-datos' => '<ellipse cx="12" cy="5" rx="8" ry="3"/>'
        . '<path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/>'
        . '<path d="M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',

    // Roles y permisos: una llave. Quien puede abrir que.
    'roles-permisos' => '<circle cx="7.5" cy="15.5" r="4.5"/>'
        . '<path d="M10.7 12.3 21 2"/>'
        . '<path d="M17.5 5.5l2.5 2.5"/>'
        . '<path d="M14.5 8.5l2.5 2.5"/>',

    // Flujos: cajas encadenadas, que es lo que dibuja esa pantalla.
    'flujos' => '<rect x="3" y="3" width="7" height="5" rx="1"/>'
        . '<rect x="3" y="16" width="7" height="5" rx="1"/>'
        . '<rect x="14" y="16" width="7" height="5" rx="1"/>'
        . '<path d="M6.5 8v8M10 18.5h4"/>',

    // Documentos: una hoja con su esquina doblada.
    'documentos' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/>'
        . '<path d="M14 3v5h5"/>'
        . '<path d="M9 13h6M9 17h4"/>',

    // Pendientes: una lista con una casilla marcada.
    'pendientes' => '<rect x="3" y="4" width="6" height="6" rx="1"/>'
        . '<path d="M4.8 7l1.4 1.4L8.4 6"/>'
        . '<rect x="3" y="14" width="6" height="6" rx="1"/>'
        . '<path d="M12 7h9M12 17h9"/>',

    // Ideas: la ampolleta. Lo que todavia hay que decidir.
    'ideas' => '<path d="M12 3a6 6 0 0 0-3.6 10.8c.5.4.9 1 1 1.7l.1.5h5l.1-.5c.1-.7.5-1.3 1-1.7A6 6 0 0 0 12 3z"/>'
        . '<path d="M9.5 18.5h5M10.5 21h3"/>',

    // Changelog: una etiqueta de version.
    'changelog' => '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/>'
        . '<circle cx="7" cy="7" r="1.1"/>',
];
