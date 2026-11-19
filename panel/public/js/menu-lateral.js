/*
 * MENU LATERAL (partials/_nav.php). Tres cosas, todas prescindibles: sin
 * JavaScript el menu funciona igual que siempre (grupos <details> nativos,
 * menu apilado arriba en movil).
 *
 * 1. RECUERDA LOS GRUPOS ABIERTOS. Cada <details data-grupo> guarda en el
 *    navegador si la persona lo dejo abierto o cerrado. El grupo de la pagina
 *    actual se abre SIEMPRE, aunque se haya guardado cerrado: nadie aterriza en
 *    una pantalla con su grupo plegado. Se carga sin defer, justo despues del
 *    menu, para aplicar el estado antes de pintar (sin parpadeo).
 *
 * 2. BUSCAR EN EL MENU. Filtra mientras se escribe (sin tildes ni mayusculas;
 *    tambien por el nombre del grupo: "informes" muestra los seis), abre los
 *    grupos con coincidencias y Enter lleva a la primera. Escape limpia. La
 *    tecla "/" enfoca el buscador desde cualquier parte que no sea un campo.
 *
 * 3. MOVIL (<= 720px): el menu pasa a un panel que entra desde la izquierda,
 *    con una barra superior y boton de hamburguesa. Escape, el fondo o el boton
 *    de cerrar lo cierran, y el foco vuelve al boton que lo abrio.
 *
 * localStorage puede fallar (modo privado, cookies bloqueadas): todo acceso va
 * en try/catch y sin el, simplemente no se recuerda nada.
 */
(function () {
    'use strict';

    var nav = document.getElementById('menu-lateral');
    if (!nav) { return; }
    document.documentElement.classList.add('menu-js');

    var CLAVE = 'sinergia.menu.grupos';

    // --- 1. Grupos abiertos -------------------------------------------------
    function leer() {
        try { return JSON.parse(window.localStorage.getItem(CLAVE) || '{}') || {}; } catch (e) { return {}; }
    }
    function guardar(estado) {
        try { window.localStorage.setItem(CLAVE, JSON.stringify(estado)); } catch (e) { /* sin memoria */ }
    }
    var grupos = Array.prototype.slice.call(nav.querySelectorAll('details[data-grupo]'));
    var recordado = leer();
    // Abrir o cerrar DESDE EL CODIGO (al cargar, al buscar) no es una
    // eleccion de la persona y no se guarda. El evento 'toggle' llega
    // DESPUES, en otra vuelta del navegador, por eso no sirve una bandera
    // global: cada grupo anota el cambio que se le hizo y su toggle lo
    // reconoce y lo descarta.
    function poner(g, abierto) {
        if (g.open === abierto) { return; }
        g._programado = true;
        g.open = abierto;
    }
    grupos.forEach(function (g) {
        var k = g.getAttribute('data-grupo');
        if (g.classList.contains('sidebar__grupo--actual')) { poner(g, true); }
        else if (recordado.hasOwnProperty(k)) { poner(g, !!recordado[k]); }
        g.addEventListener('toggle', function () {
            if (g._programado) { g._programado = false; return; }
            var e = leer();
            e[k] = g.open;
            guardar(e);
        });
    });

    // El item activo a la vista: con el menu fijo y scroll propio, una pagina
    // del fondo (Configuracion empresa) quedaria escondida bajo el pliegue.
    var activo = nav.querySelector('.nav-item--activo');
    if (activo && activo.scrollIntoView) {
        var r = activo.getBoundingClientRect();
        if (r.bottom > window.innerHeight - 80) { activo.scrollIntoView({ block: 'center' }); }
    }

    // --- 2. Buscar ---------------------------------------------------------
    var caja = nav.querySelector('.sidebar__buscar');
    var q = nav.querySelector('[data-menu-buscar]');
    var vacio = nav.querySelector('[data-menu-vacio]');
    var estado = document.getElementById('menu-buscar-estado');
    var menu = nav.querySelector('[data-menu-items]');

    function normal(s) {
        return String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
    }

    if (caja && q && menu) {
        caja.hidden = false;
        var abiertosAntes = null;

        var filtrar = function () {
            var t = normal(q.value);
            var items = menu.querySelectorAll('.nav-item');
            if (t === '') {
                Array.prototype.forEach.call(items, function (it) { it.hidden = false; });
                Array.prototype.forEach.call(menu.querySelectorAll('.sidebar__subgrupo, .sidebar__grupo'), function (c) { c.hidden = false; });
                if (abiertosAntes) {
                    grupos.forEach(function (g, i) { poner(g, abiertosAntes[i]); });
                    abiertosAntes = null;
                }
                nav.classList.remove('sidebar--buscando');
                vacio.hidden = true;
                estado.textContent = '';
                return;
            }
            if (!abiertosAntes) { abiertosAntes = grupos.map(function (g) { return g.open; }); }
            nav.classList.add('sidebar--buscando');
            var n = 0;
            Array.prototype.forEach.call(items, function (it) {
                // Texto del item + los titulos que lo contienen (grupo y subgrupo).
                var contexto = it.textContent;
                var sub = it.closest('.sidebar__subgrupo');
                if (sub) { contexto += ' ' + (sub.querySelector('.sidebar__subtitulo') || {}).textContent; }
                var grp = it.closest('details[data-grupo]');
                if (grp) { contexto += ' ' + grp.querySelector('summary').textContent; }
                var si = normal(contexto).indexOf(t) !== -1;
                it.hidden = !si;
                if (si) { n++; }
            });
            Array.prototype.forEach.call(menu.querySelectorAll('.sidebar__subgrupo'), function (sub) {
                sub.hidden = !sub.querySelector('.nav-item:not([hidden])');
            });
            Array.prototype.forEach.call(menu.querySelectorAll('.sidebar__grupo'), function (g) {
                var hay = !!g.querySelector('.nav-item:not([hidden])');
                g.hidden = !hay;
                if (g.tagName === 'DETAILS') { poner(g, hay); }
            });
            vacio.hidden = n !== 0;
            estado.textContent = n === 0 ? 'Ninguna pantalla coincide.' : (n === 1 ? '1 pantalla.' : n + ' pantallas.');
            var primero = menu.querySelector('a.nav-item:not([hidden])');
            Array.prototype.forEach.call(menu.querySelectorAll('.nav-item--primero'), function (x) { x.classList.remove('nav-item--primero'); });
            if (primero) { primero.classList.add('nav-item--primero'); }
        };

        q.addEventListener('input', filtrar);
        q.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                var primero = menu.querySelector('a.nav-item:not([hidden])');
                if (primero && q.value.trim() !== '') { e.preventDefault(); window.location.href = primero.href; }
            } else if (e.key === 'Escape') {
                if (q.value !== '') { e.preventDefault(); e.stopPropagation(); q.value = ''; filtrar(); }
                else { q.blur(); }
            } else if (e.key === 'ArrowDown') {
                var p = menu.querySelector('a.nav-item:not([hidden])');
                if (p) { e.preventDefault(); p.focus(); }
            }
        });

        // "/" enfoca el buscador, salvo que se este escribiendo en un campo.
        document.addEventListener('keydown', function (e) {
            if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) { return; }
            var a = document.activeElement;
            if (a && (a.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName))) { return; }
            if (document.querySelector('dialog[open]')) { return; }
            e.preventDefault();
            if (esMovil()) { abrir(); }
            q.focus();
            q.select();
        });
    }

    // --- 3. Movil ----------------------------------------------------------
    var barra = document.querySelector('.barra-movil');
    var fondo = document.querySelector('.sidebar-fondo');
    var botonAbrir = document.querySelector('[data-menu-abrir]');
    var botonCerrar = nav.querySelector('.sidebar__cerrar');
    var mq = window.matchMedia ? window.matchMedia('(max-width: 720px)') : null;

    function esMovil() { return !!(mq && mq.matches); }

    if (barra) { barra.hidden = false; }
    if (botonCerrar) { botonCerrar.hidden = false; }

    function abrir() {
        if (!esMovil()) { return; }
        document.documentElement.classList.add('menu-abierto');
        fondo.hidden = false;
        botonAbrir.setAttribute('aria-expanded', 'true');
        var foco = nav.querySelector('.nav-item--activo') || (q && !q.closest('[hidden]') ? q : null) || nav.querySelector('a');
        if (foco) { foco.focus({ preventScroll: false }); }
    }
    function cerrar(devolverFoco) {
        if (!document.documentElement.classList.contains('menu-abierto')) { return; }
        document.documentElement.classList.remove('menu-abierto');
        fondo.hidden = true;
        botonAbrir.setAttribute('aria-expanded', 'false');
        if (devolverFoco !== false) { botonAbrir.focus(); }
    }

    if (botonAbrir) { botonAbrir.addEventListener('click', abrir); }
    // Al elegir una pantalla se cierra el panel: asi se ve el skeleton de
    // carga (js/arranque-pantalla.js) y no el menu tapando todo.
    nav.addEventListener('click', function (e) {
        var a = e.target.closest ? e.target.closest('a[href]') : null;
        if (a && esMovil()) { cerrar(false); }
    });
    Array.prototype.forEach.call(document.querySelectorAll('[data-menu-cerrar]'), function (b) {
        b.addEventListener('click', function () { cerrar(); });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && esMovil()) { cerrar(); }
    });
    // Al volver a escritorio (girar la tablet) no queda el panel abierto.
    if (mq) {
        var alCambiar = function () { if (!esMovil()) { cerrar(false); } };
        if (mq.addEventListener) { mq.addEventListener('change', alCambiar); } else if (mq.addListener) { mq.addListener(alCambiar); }
    }
})();
