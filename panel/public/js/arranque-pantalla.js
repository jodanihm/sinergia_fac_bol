/*
 * SKELETON AL PINCHAR UN ENLACE AL FORMULARIO DE EMISION.
 *
 * Mientras llega el HTML de la pagina nueva sigue a la vista la pantalla anterior.
 * Por eso el skeleton lo pone la pantalla de origen, EN EL MISMO CLIC y con el menu
 * real al lado, y la vista lo continua al llegar hasta completar 1 segundo (ver
 * views/emision-form.php: es una excepcion pedida a la seccion 14). La navegacion es
 * la normal del navegador: aqui no se intercepta nada.
 *
 * El contenido solo se esconde, y vuelve si se regresa con "Atras" o si la
 * navegacion no prospera (se detuvo la carga, se cayo la red).
 */
(function () {
    'use strict';

    var VARIANTES = {
        '/ventas/factura': 'factura',
        '/ventas/factura-exenta': 'factura',
        '/ventas/boleta': 'boleta',
        '/ventas/nota-credito': 'nota',
        '/ventas/nota-debito': 'nota'
    };
    var DEVOLVER_MS = 15000;
    var devolver = null;
    var puesto = null;

    function restaurar() {
        clearTimeout(devolver);
        var main = document.getElementById('contenido');
        if (main) {
            main.classList.remove('main--saliendo');
            main.removeAttribute('aria-busy');
        }
        if (puesto) { puesto.remove(); puesto = null; }
    }

    function mostrar(titulo, variante) {
        var plantilla = document.getElementById('skeleton-emision');
        var main = document.getElementById('contenido');
        if (!plantilla || !main) { return; }
        puesto = plantilla.content.firstElementChild.cloneNode(true);
        puesto.classList.remove('arranque--factura');
        puesto.classList.add('arranque--visible', 'arranque--' + variante);
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
        if (destino.origin !== location.origin || !VARIANTES[destino.pathname]) { return; }
        restaurar();
        mostrar(a.textContent.trim(), VARIANTES[destino.pathname]);
    });

    // Volver con "Atras" puede restaurar la pagina tal como quedo (bfcache).
    window.addEventListener('pageshow', function (e) { if (e.persisted) { restaurar(); } });
})();
