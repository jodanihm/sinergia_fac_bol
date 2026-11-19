/*
 * MENU LATERAL (partials/_nav.php). Todo es prescindible: sin JavaScript el
 * menu funciona completo (grupos <details> nativos, siempre expandido,
 * apilado arriba en movil).
 *
 * 1. GRUPOS RECORDADOS. Cada <details data-grupo> guarda si la persona lo dejo
 *    abierto o cerrado. El de la pagina actual se abre SIEMPRE.
 *
 * 2. MENU CONTRAIDO (escritorio). El boton del borde lo reduce a una columna
 *    de iconos; el nombre de cada opcion sale en un tooltip al pasar o
 *    enfocar. Pinchar un grupo con el menu contraido lo vuelve a expandir y
 *    abre ese grupo. Se recuerda (la clase se pone antes de pintar, en
 *    _nav.php, para que no salte).
 *
 * 3. BUSCAR. Filtra mientras se escribe (sin tildes ni mayusculas, tambien por
 *    el nombre del grupo y del subgrupo), abre los grupos con coincidencias y
 *    Enter lleva a la primera. Escape limpia. "/" enfoca el buscador.
 *
 * 4. MOVIL (<= 720px): panel que entra desde la izquierda con barra superior y
 *    hamburguesa. Escape, el fondo, la X o elegir una opcion lo cierran.
 *
 * localStorage puede fallar (modo privado): todo acceso va en try/catch.
 */
(function () {
    'use strict';

    var nav = document.getElementById('menu-lateral');
    if (!nav) { return; }
    var raiz = document.documentElement;
    raiz.classList.add('menu-js');

    var CLAVE_GRUPOS = 'sinergia.menu.grupos';
    var CLAVE_CONTRAIDO = 'sinergia.menu.contraido';
    var mq = window.matchMedia ? window.matchMedia('(max-width: 720px)') : null;
    function esMovil() { return !!(mq && mq.matches); }
    function contraido() { return raiz.classList.contains('menu-contraido') && !esMovil(); }

    function leer(clave, porDefecto) {
        try { var v = window.localStorage.getItem(clave); return v === null ? porDefecto : v; } catch (e) { return porDefecto; }
    }
    function guardar(clave, valor) {
        try { window.localStorage.setItem(clave, valor); } catch (e) { /* sin memoria */ }
    }
    function todos(sel, base) { return Array.prototype.slice.call((base || nav).querySelectorAll(sel)); }

    // --- 1. Grupos recordados -------------------------------------------------
    // Abrir o cerrar DESDE EL CODIGO no es una eleccion de la persona y no se
    // guarda. 'toggle' llega despues, en otra vuelta del navegador: cada grupo
    // anota el cambio programado y su toggle lo descarta.
    var grupos = todos('details[data-grupo]');
    var recordado = {};
    try { recordado = JSON.parse(leer(CLAVE_GRUPOS, '{}')) || {}; } catch (e) { recordado = {}; }
    function poner(g, abierto) {
        if (g.open === abierto) { return; }
        g._programado = true;
        g.open = abierto;
    }
    grupos.forEach(function (g) {
        var k = g.getAttribute('data-grupo');
        if (g.classList.contains('sb-grupo--actual')) { poner(g, true); }
        else if (recordado.hasOwnProperty(k)) { poner(g, !!recordado[k]); }
        g.addEventListener('toggle', function () {
            if (g._programado) { g._programado = false; return; }
            var e = {};
            try { e = JSON.parse(leer(CLAVE_GRUPOS, '{}')) || {}; } catch (x) { e = {}; }
            e[k] = g.open;
            guardar(CLAVE_GRUPOS, JSON.stringify(e));
        });
    });

    // La opcion activa a la vista si quedo bajo el pliegue del menu.
    var menu = nav.querySelector('[data-menu-items]');
    var activo = nav.querySelector('.sb-sub--activo, .sb-item--activo');
    if (activo && menu) {
        var rm = menu.getBoundingClientRect(), ra = activo.getBoundingClientRect();
        if (ra.bottom > rm.bottom - 24) { menu.scrollTop += ra.top - rm.top - rm.height / 2; }
    }

    // --- 2. Menu contraido ----------------------------------------------------
    var botonContraer = nav.querySelector('[data-menu-contraer]');
    var textoContraer = nav.querySelector('[data-menu-contraer-texto]');
    var tip = null;
    function ocultarTip() { if (tip) { tip.hidden = true; } }
    function pintarBotonContraer() {
        if (!botonContraer) { return; }
        var c = raiz.classList.contains('menu-contraido');
        botonContraer.setAttribute('aria-pressed', c ? 'true' : 'false');
        botonContraer.title = c ? 'Expandir menu' : 'Contraer menu';
        if (textoContraer) { textoContraer.textContent = c ? 'Expandir menu' : 'Contraer menu'; }
    }
    function fijarContraido(c) {
        raiz.classList.toggle('menu-contraido', c);
        guardar(CLAVE_CONTRAIDO, c ? '1' : '0');
        pintarBotonContraer();
        ocultarTip();
    }
    if (botonContraer) {
        botonContraer.hidden = false;
        pintarBotonContraer();
        botonContraer.addEventListener('click', function () { fijarContraido(!raiz.classList.contains('menu-contraido')); });
    }
    // Contraido, un grupo no puede mostrar sus opciones: pincharlo expande el
    // menu y abre ese grupo.
    grupos.forEach(function (g) {
        g.querySelector('summary').addEventListener('click', function (e) {
            if (!contraido()) { return; }
            e.preventDefault();
            fijarContraido(false);
            g.open = true;
        });
    });

    // Tooltip del menu contraido: una capa fija en el <body> (el menu tiene
    // scroll propio y recortaria un tooltip dentro de el).
    tip = document.createElement('div');
    tip.className = 'sb-tip';
    tip.setAttribute('role', 'tooltip');
    tip.hidden = true;
    document.body.appendChild(tip);
    function mostrarTip(el) {
        var texto = el.getAttribute('data-tip');
        if (!texto || !contraido()) { ocultarTip(); return; }
        tip.textContent = texto;
        tip.hidden = false;
        var r = el.getBoundingClientRect();
        tip.style.top = (r.top + r.height / 2) + 'px';
        tip.style.left = (r.right + 12) + 'px';
    }
    nav.addEventListener('mouseover', function (e) {
        var el = e.target.closest ? e.target.closest('[data-tip]') : null;
        if (el) { mostrarTip(el); } else { ocultarTip(); }
    });
    nav.addEventListener('mouseleave', ocultarTip);
    nav.addEventListener('focusin', function (e) {
        var el = e.target.closest ? e.target.closest('[data-tip]') : null;
        if (el) { mostrarTip(el); }
    });
    nav.addEventListener('focusout', ocultarTip);
    if (menu) { menu.addEventListener('scroll', ocultarTip, { passive: true }); }

    // --- 3. Buscar ------------------------------------------------------------
    var caja = nav.querySelector('.sb__buscar');
    var q = nav.querySelector('[data-menu-buscar]');
    var vacio = nav.querySelector('[data-menu-vacio]');
    var estado = document.getElementById('menu-buscar-estado');

    function normal(s) {
        return String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
    }
    function primeraVisible() { return menu.querySelector('a[data-hoja]:not([hidden])'); }

    // Se declaran antes del atajo "/" que los usa.
    var barra = document.querySelector('.barra-movil');
    var fondo = document.querySelector('.sidebar-fondo');
    var botonAbrir = document.querySelector('[data-menu-abrir]');
    var botonCerrar = nav.querySelector('.sb__cerrar');

    function abrir() {
        if (!esMovil() || !botonAbrir) { return; }
        raiz.classList.add('menu-abierto');
        fondo.hidden = false;
        botonAbrir.setAttribute('aria-expanded', 'true');
        // El foco entra al panel (no a un enlace: pintaria su anillo como si
        // se hubiera llegado con teclado). Tab sigue desde ahi.
        if (!nav.hasAttribute('tabindex')) { nav.setAttribute('tabindex', '-1'); }
        nav.focus({ preventScroll: true });
    }
    function cerrar(devolverFoco) {
        if (!raiz.classList.contains('menu-abierto')) { return; }
        raiz.classList.remove('menu-abierto');
        fondo.hidden = true;
        botonAbrir.setAttribute('aria-expanded', 'false');
        if (devolverFoco !== false) { botonAbrir.focus(); }
    }

    if (caja && q && menu) {
        caja.hidden = false;
        var abiertosAntes = null;

        var filtrar = function () {
            var t = normal(q.value);
            var hojas = todos('[data-hoja]', menu);
            todos('.sb-hoja--primera', menu).forEach(function (x) { x.classList.remove('sb-hoja--primera'); });
            if (t === '') {
                hojas.forEach(function (h) { h.hidden = false; });
                todos('.sb-subgrupo, .sb-grupo, .sb__bloque', menu).forEach(function (c) { c.hidden = false; });
                if (abiertosAntes) {
                    grupos.forEach(function (g, i) { poner(g, abiertosAntes[i]); });
                    abiertosAntes = null;
                }
                nav.classList.remove('sb--buscando');
                vacio.hidden = true;
                estado.textContent = '';
                return;
            }
            if (!abiertosAntes) { abiertosAntes = grupos.map(function (g) { return g.open; }); }
            nav.classList.add('sb--buscando');
            var n = 0;
            hojas.forEach(function (h) {
                var contexto = h.textContent;
                var sub = h.closest('.sb-subgrupo');
                if (sub) { contexto += ' ' + sub.querySelector('.sb-subgrupo__titulo').textContent; }
                var grp = h.closest('details[data-grupo]');
                if (grp) { contexto += ' ' + grp.querySelector('summary').textContent; }
                var si = normal(contexto).indexOf(t) !== -1;
                h.hidden = !si;
                if (si) { n++; }
            });
            todos('.sb-subgrupo', menu).forEach(function (s) { s.hidden = !s.querySelector('[data-hoja]:not([hidden])'); });
            grupos.forEach(function (g) {
                var hay = !!g.querySelector('[data-hoja]:not([hidden])');
                g.hidden = !hay;
                poner(g, hay);
            });
            todos('.sb__bloque', menu).forEach(function (b) {
                b.hidden = !b.querySelector('[data-hoja]:not([hidden])');
            });
            vacio.hidden = n !== 0;
            estado.textContent = n === 0 ? 'Ninguna pantalla coincide.' : (n === 1 ? '1 pantalla.' : n + ' pantallas.');
            var p = primeraVisible();
            if (p) { p.classList.add('sb-hoja--primera'); }
        };

        q.addEventListener('input', filtrar);
        q.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                var p = primeraVisible();
                if (p && q.value.trim() !== '') { e.preventDefault(); window.location.href = p.href; }
            } else if (e.key === 'Escape') {
                if (q.value !== '') { e.preventDefault(); e.stopPropagation(); q.value = ''; filtrar(); }
                else { q.blur(); }
            } else if (e.key === 'ArrowDown') {
                var p2 = primeraVisible();
                if (p2) { e.preventDefault(); p2.focus(); }
            }
        });
        // Contraido, la lupa expande el menu para poder escribir.
        caja.addEventListener('mousedown', function (e) {
            if (contraido()) { e.preventDefault(); fijarContraido(false); q.focus(); }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) { return; }
            var a = document.activeElement;
            if (a && (a.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName))) { return; }
            if (document.querySelector('dialog[open]')) { return; }
            e.preventDefault();
            if (esMovil()) { abrir(); }
            if (contraido()) { fijarContraido(false); }
            q.focus();
            q.select();
        });
    }

    // --- 4. Movil -------------------------------------------------------------
    if (barra) { barra.hidden = false; }
    if (botonCerrar) { botonCerrar.hidden = false; }
    if (botonAbrir) { botonAbrir.addEventListener('click', abrir); }
    todos('[data-menu-cerrar]', document).forEach(function (b) {
        b.addEventListener('click', function () { cerrar(); });
    });
    // Elegir una pantalla cierra el panel: se ve el skeleton de carga.
    nav.addEventListener('click', function (e) {
        var a = e.target.closest ? e.target.closest('a[href]') : null;
        if (a && esMovil()) { cerrar(false); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && esMovil()) { cerrar(); }
    });
    if (mq) {
        var alCambiar = function () { if (!esMovil()) { cerrar(false); } ocultarTip(); };
        if (mq.addEventListener) { mq.addEventListener('change', alCambiar); } else if (mq.addListener) { mq.addListener(alCambiar); }
    }
})();
