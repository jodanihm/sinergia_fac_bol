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
 * En movil la grilla colapsa a una columna, asi que el orden del markup es
 * tambien el orden vertical: la primera es la que se ve sin desplazar.
 *
 * CERO CSS NUEVO: .dash-grid--2 (dos columnas que colapsan a una en <=720px),
 * .tarjeta y .boton-principal ya existen y se usan tal cual.
 */
$titulo = 'Panel';
require __DIR__ . '/partials/header.php';
?>

<h1>Tu cuenta ya esta creada.</h1>

<p class="dash-subtitulo">Elige la opcion que describe tu situacion.</p>

<div class="dash-grid dash-grid--2">
    <article class="tarjeta">
        <h2>El SII ya me autorizo a emitir</h2>
        <p>Tienes la Resolucion del SII que te habilita como emisor electronico.
        Carga los datos de tu empresa y empieza a emitir.</p>
        <p><a class="boton-principal" href="/empresa-produccion">Empezar</a></p>
    </article>

    <article class="tarjeta">
        <h2>Todavia no tengo esa autorizacion</h2>
        <p>Te guiamos en el tramite ante el SII de principio a fin.</p>
        <p><a class="boton-principal" href="/empresa">Empezar</a></p>
    </article>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
