<?php
/**
 * Tercera cara de /panel: la BIFURCACION del onboarding.
 *
 * La ve un tenant que todavia no tiene NINGUNA fila de dte_emisor -- recien
 * salido de /registro, que hace auto-login y lo deja aqui. Es, literalmente, la
 * primera pantalla del producto.
 *
 * POR QUE REEMPLAZA AL STEPPER Y NO SE SUMA A EL. Antes esta pantalla mostraba
 * el stepper de 7 estaciones, del que solo la primera fila ("Registrado") decia
 * algo cierto: las otras seis describen el circuito de CERTIFICACION, que un
 * contribuyente ya autorizado por el SII no va a recorrer nunca. Dejar el
 * stepper debajo de la eleccion no habria empatado las dos opciones, las habria
 * desempatado a favor de una: un camino desplegado en 7 pasos con su "Pendiente
 * -- completar" pesa mucho mas que una tarjeta. El "Registrado / Completado" que
 * se pierde se recupera en el encabezado de aqui, que es su primera linea.
 *
 * NO DECIDE NADA NI GUARDA NADA. Las dos tarjetas son dos enlaces; no hay
 * columna, ni sesion, ni cookie donde anotar la respuesta. El camino queda
 * determinado por la primera fila de dte_emisor que el tenant guarde, y de ahi
 * en adelante se infiere de los datos (ver handlePanelGet()). Por eso no hace
 * falta un boton de "cambiar de camino": no hay nada que cambiar, y quien se
 * equivoque de puerta puede entrar por la otra desde el menu lateral, que ya
 * ofrece las dos sin bloquear.
 *
 * ORDEN DE LAS TARJETAS: primero la de produccion. Es el caso que el panel peor
 * servia hasta ahora y el que estas dos ultimas entregas vinieron a habilitar.
 * El orden del markup es tambien el orden de lectura donde la grilla apila.
 *
 * DONDE APILA Y DONDE NO -- MEDIDO, no supuesto. Con $bodyClase = 'dash-page'
 * manda '.dash-page .dash-grid' (style.css:877), que reparte por
 * repeat(auto-fit, minmax(min(231px,100%),1fr)) y le gana por especificidad
 * (0,2,0) contra (0,1,0) tanto a .dash-grid--2 como a los dos @media que
 * gobernaban el colapso. Consecuencia real, medida en navegador:
 *
 *     viewport   contenedor   columnas   ancho de cada tarjeta
 *       1366        995px         2            492px
 *       1024        669px         2            329px
 *        720        656px         2            322px   <- antes apilaba
 *        660        596px         2            292px
 *        600        536px         2            262px
 *        574        510px         2            249px
 *        540        476px         2            232px
 *        538        474px         2            231px   <- ultimo con 2 columnas
 *        537        473px         1            473px   <- primero que apila
 *        420        356px         1            356px
 *        375        311px         1            311px
 *
 * O sea: apila cuando el CONTENEDOR baja de 474px (2x231 + gap de 12), no
 * cuando el viewport baja de 720. El contenedor es siempre el viewport menos
 * 64, asi que el corte cae en 538/537 de viewport, medido al pixel. Entre 538 y
 * 720 quedan dos columnas angostas, y se acepta a proposito: mismo precedente y
 * mismos numeros que la banda que la Ronda 12 acepto para .folios. Debajo de
 * 538 vuelve a apilar y ahi si el orden del markup es el orden vertical.
 *
 * EN EL EXTREMO ANGOSTO DE LA BANDA EL BOTON MAS LARGO ENVUELVE. "Cargar mi
 * Resolucion del SII" mide 217px y la tarjeta le deja ancho menos 32 de
 * padding: desde 573 de viewport hacia abajo pasa a dos lineas (medido: 574 en
 * una, 573 en dos). No se corta ni desborda en ningun ancho.
 *
 * LAS CINCO CLASES QUE USA ESTA VISTA SON TODAS COMPARTIDAS: ninguna es
 * exclusiva de aqui. Medido sobre panel/**.php:
 *
 *     .tarjeta          68 usos en 35 archivos
 *     .boton-principal  36 usos en 24 archivos
 *     .dash-subtitulo   28 usos en 27 archivos
 *     .dash-grid         7 usos en  5 archivos
 *     .dash-grid--2      2 usos en  2 archivos (aqui y facturacion-masiva-form)
 *
 * POR ESO EL CAMBIO DE ASPECTO VA POR EL BODY CLASS Y NO POR CSS. Tocar
 * cualquiera de esas cinco reglas arrastraria entre 2 y 35 vistas ajenas.
 * 'dash-page' ya existe -- lo usa panel-gestion.php -- y sube la tarjeta de
 * plana con borde gris a superficie elevada (sin borde, --radio-lg, fondo
 * blanco y --sombra-tarjeta) sobre un <main> teñido, sin escribir ni una linea
 * de CSS y sin que ninguna otra vista se entere.
 */
$titulo    = 'Panel';
$bodyClase = 'dash-page';
require __DIR__ . '/partials/header.php';
?>

<h1>Tu cuenta ya esta creada.</h1>

<p class="dash-subtitulo">Elige la opcion que describe tu situacion.</p>

<div class="dash-grid dash-grid--2">
    <article class="tarjeta">
        <h2>El SII ya me autorizo a emitir</h2>
        <p>Tienes la Resolucion del SII que te habilita como emisor electronico.
        Carga los datos de tu empresa y empieza a emitir.</p>
        <p><a class="boton-principal" href="/empresa-produccion">Cargar mi Resolucion del SII</a></p>
    </article>

    <article class="tarjeta">
        <h2>Todavia no tengo esa autorizacion</h2>
        <p>Te guiamos en el tramite ante el SII de principio a fin.</p>
        <p><a class="boton-principal" href="/empresa">Iniciar mi certificacion</a></p>
    </article>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
