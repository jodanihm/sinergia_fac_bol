/*
 * SKELETON AL PINCHAR UN ENLACE AL FORMULARIO DE EMISION.
 *
 * Casi toda la espera de abrir una pantalla ocurre ANTES de que llegue su HTML,
 * con la pantalla anterior todavia a la vista: ahi la pagina nueva no puede
 * mostrar nada. Por eso el aviso lo da la pantalla de origen. Si a los 300 ms no
 * ha llegado la respuesta, cambia su contenido por el skeleton de la que se
 * abre, con el menu real al lado (seccion 14 del design system). La navegacion
 * es la normal del navegador: aqui no se intercepta nada.
 *
 * El contenido solo se esconde, y vuelve si se regresa con "Atras" o si la
 * navegacion no prospera (se detuvo la carga, se cayo la red).
 */
(function () {
    'use strict';

    var RUTAS = ['/ventas/factura', '/ventas/factura-exenta', '/ventas/boleta', '/ventas/nota-credito', '/ventas/nota-debito'];
    var UMBRAL_MS = 300;
    var DEVOLVER_MS = 15000;
    var umbral = null;
    var devolver = null;
    var puesto = null;

    function restaurar() {
        clearTimeout(umbral);
        clearTimeout(devolver);
        var main = document.getElementById('contenido');
        if (main) {
            main.classList.remove('main--saliendo');
            main.removeAttribute('aria-busy');
        }
        if (puesto) { puesto.remove(); puesto = null; }
    }

    function mostrar(titulo) {
        var plantilla = document.getElementById('skeleton-emision');
        var main = document.getElementById('contenido');
        if (!plantilla || !main) { return; }
        puesto = plantilla.content.firstElementChild.cloneNode(true);
        puesto.classList.add('arranque--visible');
        puesto.querySelector('[data-arranque-titulo]').textContent = titulo;
        main.insertBefore(puesto, main.firstChild);
        main.classList.add('main--saliendo');
        main.setAttribute('aria-busy', 'true');
        window.scrollTo(0, 0);
        devolver = setTimeout(restaurar, DEVOLVER_MS);
    }

    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
        var a = e.target.closest ? e.target.closest('a[href]') : null;
        if (!a || (a.target && a.target !== '_self') || a.hasAttribute('download')) { return; }
        var destino = new URL(a.href, location.href);
        if (destino.origin !== location.origin || RUTAS.indexOf(destino.pathname) === -1) { return; }
        var titulo = a.textContent.trim();
        restaurar();
        umbral = setTimeout(function () { mostrar(titulo); }, UMBRAL_MS);
    });

    // Volver con "Atras" puede restaurar la pagina tal como quedo (bfcache).
    window.addEventListener('pageshow', function (e) { if (e.persisted) { restaurar(); } });
})();
