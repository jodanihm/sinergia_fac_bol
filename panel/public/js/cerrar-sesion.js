/*
 * DESPEDIDA AL CERRAR SESION.
 *
 * Al pinchar cualquier enlace a /logout (el del menu y el de cambiar-clave) se
 * muestra una capa de despedida: la aplicacion se desenfoca y se aleja, y una
 * tarjeta centrada dice que se esta cerrando la sesion, con una barra que se
 * llena. Al terminar se navega a /logout, que es quien cierra la sesion de
 * verdad (Auth::logout) y vuelve al login con ?sesion=cerrada.
 *
 * ES SOLO LA ANTESALA. El texto nunca afirma que la sesion ya se cerro: eso lo
 * dice el login al llegar, cuando ya es cierto.
 *
 * Sin JavaScript el enlace funciona igual que siempre. Con clic medio, Ctrl o
 * Cmd (abrir en otra pestana) no se intercepta nada. Si la navegacion no
 * prospera, o se vuelve con "Atras" (bfcache), la capa se retira sola.
 *
 * El estilo esta en style.css (.salida-*). Con prefers-reduced-motion la espera
 * baja a un instante y no hay movimiento.
 */
(function () {
    'use strict';

    var DURACION_MS = 1100;
    var DURACION_REDUCIDA_MS = 250;
    var RETIRAR_MS = 12000;
    var saliendo = false;
    var capa = null;
    var retirar = null;

    function quitarCapa() {
        clearTimeout(retirar);
        saliendo = false;
        document.documentElement.classList.remove('saliendo');
        if (capa && capa.parentNode) { capa.parentNode.removeChild(capa); }
        capa = null;
    }

    function crearCapa(reducido) {
        var c = document.createElement('div');
        c.className = 'salida';
        c.setAttribute('role', 'status');
        c.setAttribute('aria-live', 'assertive');
        c.style.setProperty('--salida-ms', (reducido ? DURACION_REDUCIDA_MS : DURACION_MS) + 'ms');
        c.innerHTML =
            '<div class="salida__tarjeta">' +
                '<img class="salida__mascota" src="/img/sinergin-240.png" width="120" height="120" alt="" aria-hidden="true">' +
                '<p class="salida__titulo">Cerrando tu sesion</p>' +
                '<p class="salida__texto">Gracias por usar Sinergia. Hasta pronto.</p>' +
                '<div class="salida__barra" aria-hidden="true"><span></span></div>' +
            '</div>';
        return c;
    }

    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
        var a = e.target.closest ? e.target.closest('a[href]') : null;
        if (!a || (a.target && a.target !== '_self')) { return; }
        var destino = new URL(a.href, location.href);
        if (destino.origin !== location.origin || destino.pathname !== '/logout') { return; }

        e.preventDefault();
        if (saliendo) { return; }
        saliendo = true;

        var reducido = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        capa = crearCapa(reducido);
        document.body.appendChild(capa);
        // Un cuadro despues, para que la transicion de entrada arranque.
        requestAnimationFrame(function () {
            document.documentElement.classList.add('saliendo');
        });

        setTimeout(function () { location.assign(destino.href); }, reducido ? DURACION_REDUCIDA_MS : DURACION_MS);
        retirar = setTimeout(quitarCapa, RETIRAR_MS);
    });

    window.addEventListener('pageshow', function (e) { if (e.persisted) { quitarCapa(); } });
})();
