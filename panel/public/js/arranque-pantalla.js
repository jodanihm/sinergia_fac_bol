/*
 * SKELETON AL PINCHAR UNA OPCION DEL MENU.
 *
 * Mientras llega la pagina nueva sigue a la vista la anterior. En el mismo clic se
 * pasa a skeleton (css/arranque.css), con el titulo de la opcion pinchada y el menu
 * real al lado, y la pagina nueva lo continua al llegar (partials/_arranque.php). La
 * navegacion es la normal del navegador: aqui no se intercepta nada.
 *
 * SOLO EL MENU, A PROPOSITO. Un enlace cualquiera del contenido puede ser una
 * descarga (PDF, Excel): la pagina no cambia y quedaria en skeleton sin motivo. Por
 * lo mismo vuelve sola si la navegacion no prospera, o al regresar con "Atras".
 */
(function () {
    'use strict';

    var DEVOLVER_MS = 15000;
    var devolver = null;
    var titulo = null;
    var tituloOriginal = '';

    function restaurar() {
        clearTimeout(devolver);
        document.documentElement.classList.remove('pantalla-arrancando');
        var main = document.getElementById('contenido');
        if (main) { main.removeAttribute('aria-busy'); }
        if (titulo) { titulo.textContent = tituloOriginal; titulo = null; }
    }

    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
        var a = e.target.closest ? e.target.closest('nav a[href]') : null;
        if (!a || (a.target && a.target !== '_self') || a.hasAttribute('download')) { return; }
        var destino = new URL(a.href, location.href);
        if (destino.origin !== location.origin || destino.pathname === '/logout') { return; }
        if (destino.pathname === location.pathname && destino.search === location.search && destino.hash) { return; }

        var main = document.getElementById('contenido');
        if (!main) { return; }
        restaurar();
        titulo = main.querySelector('h1');
        if (titulo) {
            tituloOriginal = titulo.textContent;
            titulo.textContent = a.textContent.trim();
        }
        document.documentElement.classList.add('pantalla-arrancando');
        main.setAttribute('aria-busy', 'true');
        window.scrollTo(0, 0);
        devolver = setTimeout(restaurar, DEVOLVER_MS);
    });

    // Volver con "Atras" puede restaurar la pagina tal como quedo (bfcache).
    window.addEventListener('pageshow', function (e) { if (e.persisted) { restaurar(); } });
})();
