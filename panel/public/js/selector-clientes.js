/*
 * MODAL "BUSCAR EN MIS CLIENTES" (formulario de emision).
 *
 * Complementa a la lista que se abre bajo el campo de RUT
 * (js/buscador-clientes.js): aqui se ve la cartera completa, se busca tambien
 * por giro, direccion, comuna y correo, y se combinan palabras. Al pinchar un
 * cliente se rellena el receptor por el MISMO camino que al elegir de esa lista
 * (window.buscadorClientes.elegir), asi que validar, avisar y vaciar los datos
 * al cambiar de RUT funcionan igual.
 *
 * MARCADO, en la vista:
 *   - [data-abrir-selector-cliente]: el boton. Viene oculto y este archivo lo
 *     muestra: sin JavaScript el modal no funcionaria.
 *   - #selector-cliente: el <dialog>, con data-campo-rut (id del campo de RUT) y
 *     data-avisar-faltantes si el documento exige giro, direccion y comuna.
 *
 * ESPERAS (seccion 14 del design system):
 *   - Menos de 300 ms: nada. De ahi en adelante, skeleton con la forma de las
 *     filas, y si llego a verse dura al menos 400 ms.
 *   - A los 10 s se dice que sigue buscando; a los 30 s se corta y se ofrece
 *     reintentar.
 *   - "Ver mas" lleva el spinner dentro del boton, tambien pasados 300 ms.
 *   - El foco no se mueve mientras se espera y el resultado se anuncia.
 */
(function () {
    'use strict';

    var URL_BUSQUEDA = '/ventas/clientes/explorar';
    var PAUSA_MS = 250;
    var UMBRAL_MS = 300;
    var MINIMO_SKELETON_MS = 400;
    var AVISO_LARGO_MS = 10000;
    var CORTE_MS = 30000;
    var FILAS_SKELETON = 5;

    function iniciar() {
        var dlg = document.getElementById('selector-cliente');
        var boton = document.querySelector('[data-abrir-selector-cliente]');
        if (!dlg || !boton || typeof dlg.showModal !== 'function') { return; }

        var campoRut = document.getElementById(dlg.getAttribute('data-campo-rut'));
        var q = dlg.querySelector('[data-selector-q]');
        var estado = dlg.querySelector('[data-selector-estado]');
        var zona = dlg.querySelector('[data-selector-zona]');
        var lista = dlg.querySelector('[data-selector-lista]');
        var aviso = dlg.querySelector('[data-selector-aviso]');
        var avisoTexto = dlg.querySelector('[data-selector-aviso-texto]');
        var avisoAccion = dlg.querySelector('[data-selector-aviso-accion]');
        var verMas = dlg.querySelector('[data-selector-ver-mas]');

        // La boleta no pide giro, direccion ni comuna: ahi no falta nada.
        var avisarFaltantes = dlg.hasAttribute('data-avisar-faltantes');
        var clientes = [];
        var pagina = 1;
        var total = 0;
        var pedido = 0;
        var pausa = null;
        var accionAviso = null;

        boton.hidden = false;

        function formatearRut(rut) {
            var p = String(rut).split('-');
            if (p.length !== 2) { return String(rut); }
            return p[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.') + '-' + p[1];
        }

        function linea(clase, texto) {
            var s = document.createElement('span');
            s.className = 'selector-clientes__' + clase;
            s.textContent = texto;
            return s;
        }

        function fila(c, i) {
            var li = document.createElement('li');
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'selector-clientes__fila';
            b.setAttribute('data-indice', String(i));
            var cabeza = document.createElement('span');
            cabeza.className = 'selector-clientes__cabeza';
            cabeza.appendChild(linea('nombre', c.razon_social));
            cabeza.appendChild(linea('rut', formatearRut(c.rut)));
            b.appendChild(cabeza);
            if (c.nombre_fantasia) { b.appendChild(linea('fantasia', c.nombre_fantasia)); }
            var lugar = [c.direccion, c.comuna].filter(Boolean).join(', ');
            var datos = [c.giro, lugar, c.email].filter(Boolean).join(' · ');
            if (datos) { b.appendChild(linea('datos', datos)); }
            if (avisarFaltantes && c.faltan && c.faltan.length) {
                b.appendChild(linea('falta', 'Le falta para facturar: ' + c.faltan.join(', ')));
            }
            li.appendChild(b);
            return li;
        }

        function pintarSkeleton() {
            lista.innerHTML = '';
            for (var i = 0; i < FILAS_SKELETON; i++) {
                var li = document.createElement('li');
                li.className = 'selector-clientes__fila selector-clientes__fila--skeleton';
                li.setAttribute('aria-hidden', 'true');
                li.innerHTML = '<span class="selector-clientes__cabeza"><span class="skeleton skeleton--nombre"></span>'
                    + '<span class="skeleton skeleton--rut"></span></span><span class="skeleton skeleton--datos"></span>';
                lista.appendChild(li);
            }
        }

        function mostrarAviso(texto, accion, etiqueta) {
            avisoTexto.textContent = texto;
            accionAviso = accion || null;
            avisoAccion.hidden = !accion;
            avisoAccion.textContent = etiqueta || '';
            aviso.hidden = false;
        }

        function resumen(texto) {
            var hay = texto !== '';
            if (total === 0) { return ''; }
            var cuantos = total === 1
                ? (hay ? '1 cliente coincide' : '1 cliente')
                : total.toLocaleString('es-CL') + (hay ? ' clientes coinciden' : ' clientes');
            return clientes.length < total
                ? cuantos + '. Se ven los primeros ' + clientes.length.toLocaleString('es-CL') + '.'
                : cuantos + '.';
        }

        function pintarResultado(texto, desde) {
            if (desde === 0) { lista.innerHTML = ''; zona.scrollTop = 0; }
            for (var i = desde; i < clientes.length; i++) { lista.appendChild(fila(clientes[i], i)); }
            verMas.hidden = clientes.length >= total;
            aviso.hidden = true;
            estado.textContent = resumen(texto);
            if (total === 0) {
                if (texto !== '') {
                    mostrarAviso('Ningun cliente coincide con "' + texto + '". Prueba con menos palabras o solo parte del RUT.', function () {
                        q.value = '';
                        cargar(1);
                        q.focus();
                    }, 'Limpiar busqueda');
                } else {
                    mostrarAviso('Todavia no tienes clientes guardados. Escribe los datos del receptor en el formulario: se guarda solo al salir del correo.');
                }
            }
        }

        function error(r, reintentar) {
            lista.innerHTML = '';
            verMas.hidden = true;
            estado.textContent = '';
            if (r && r.status === 403) {
                mostrarAviso('Tu usuario no tiene permiso para ver los clientes. Pidelo a quien administra la cuenta, o escribe los datos del receptor a mano.');
            } else {
                mostrarAviso('No se pudieron cargar los clientes. Revisa tu conexion y vuelve a intentarlo.', reintentar, 'Reintentar');
            }
        }

        /*
         * Pide una pagina. La pagina 1 reemplaza la lista (con skeleton si
         * tarda); las siguientes se agregan al final (con spinner en "Ver mas").
         */
        function cargar(numero) {
            clearTimeout(pausa);
            var mio = ++pedido;
            var texto = q.value.trim();
            var agregando = numero > 1;
            var inicioSkeleton = 0;
            var ctrl = typeof AbortController === 'function' ? new AbortController() : null;
            var focoEnVerMas = agregando && document.activeElement === verMas;

            zona.setAttribute('aria-busy', 'true');
            var umbral = setTimeout(function () {
                if (mio !== pedido) { return; }
                if (agregando) {
                    verMas.setAttribute('data-cargando', 'true');
                    verMas.setAttribute('aria-busy', 'true');
                } else {
                    aviso.hidden = true;
                    verMas.hidden = true;
                    pintarSkeleton();
                    inicioSkeleton = Date.now();
                }
            }, UMBRAL_MS);
            var largo = setTimeout(function () {
                if (mio === pedido) { estado.textContent = 'Sigue buscando en tus clientes. Esta tardando mas de lo normal.'; }
            }, AVISO_LARGO_MS);
            var corte = setTimeout(function () { if (ctrl) { ctrl.abort(); } }, CORTE_MS);

            function terminar() {
                clearTimeout(umbral);
                clearTimeout(largo);
                clearTimeout(corte);
                zona.removeAttribute('aria-busy');
                verMas.removeAttribute('data-cargando');
                verMas.removeAttribute('aria-busy');
            }

            // Si el skeleton llego a verse, espera a cumplir su permanencia minima.
            function trasSkeleton(fn) {
                var falta = inicioSkeleton ? MINIMO_SKELETON_MS - (Date.now() - inicioSkeleton) : 0;
                if (falta > 0) { setTimeout(fn, falta); } else { fn(); }
            }

            var url = URL_BUSQUEDA + '?q=' + encodeURIComponent(texto) + '&pagina=' + numero;
            fetch(url, { headers: { Accept: 'application/json' }, signal: ctrl ? ctrl.signal : undefined })
                .then(function (r) {
                    if (!r.ok) { throw r; }
                    return r.json();
                })
                .then(function (data) {
                    trasSkeleton(function () {
                        if (mio !== pedido) { return; }
                        terminar();
                        var desde = agregando ? clientes.length : 0;
                        clientes = agregando ? clientes.concat(data.clientes) : data.clientes;
                        pagina = numero;
                        total = data.total;
                        pintarResultado(texto, desde);
                        if (focoEnVerMas) {
                            var nueva = lista.querySelector('[data-indice="' + desde + '"]');
                            if (nueva) { nueva.focus(); }
                        }
                    });
                })
                .catch(function (e) {
                    trasSkeleton(function () {
                        if (mio !== pedido) { return; }
                        terminar();
                        // Fallar la pagina siguiente no borra lo que ya se ve.
                        if (agregando && !(e && e.status === 403)) {
                            estado.textContent = 'No se pudieron cargar mas clientes. Vuelve a intentarlo.';
                            return;
                        }
                        error(e, function () { cargar(numero); });
                    });
                });
        }

        boton.addEventListener('click', function () {
            q.value = '';
            clientes = [];
            total = 0;
            lista.innerHTML = '';
            estado.textContent = '';
            aviso.hidden = true;
            verMas.hidden = true;
            dlg.showModal();
            q.focus();
            cargar(1);
        });

        dlg.addEventListener('close', function () {
            // Una respuesta que llegue con el modal cerrado ya no pinta nada.
            pedido++;
            clearTimeout(pausa);
        });

        dlg.addEventListener('click', function (e) {
            if (e.target === dlg || e.target.closest('[data-selector-cerrar]')) { dlg.close(); return; }
            var opcion = e.target.closest('[data-indice]');
            if (opcion) { elegirCliente(clientes[parseInt(opcion.getAttribute('data-indice'), 10)]); }
        });

        function elegirCliente(c) {
            if (!c || !window.buscadorClientes) { return; }
            dlg.close();
            window.buscadorClientes.elegir(campoRut, c);
            // Lo que le falte a este cliente queda marcado ahora, no al emitir, y
            // no sobrevive el check verde del cliente anterior en un campo vacio.
            if (window.emisionUx) { window.emisionUx.revisarReceptor(false); }
        }

        q.addEventListener('input', function () {
            clearTimeout(pausa);
            pausa = setTimeout(function () { cargar(1); }, PAUSA_MS);
        });

        q.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                // Busca ya, sin esperar la pausa. No elige: elegir es pinchar.
                e.preventDefault();
                cargar(1);
            } else if (e.key === 'ArrowDown') {
                var primera = lista.querySelector('[data-indice]');
                if (primera) { e.preventDefault(); primera.focus(); }
            }
        });

        lista.addEventListener('keydown', function (e) {
            if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') { return; }
            var filas = Array.prototype.slice.call(lista.querySelectorAll('[data-indice]'));
            var i = filas.indexOf(document.activeElement);
            if (i < 0) { return; }
            e.preventDefault();
            if (e.key === 'ArrowUp' && i === 0) { q.focus(); return; }
            var destino = filas[i + (e.key === 'ArrowDown' ? 1 : -1)];
            if (destino) { destino.focus(); }
        });

        avisoAccion.addEventListener('click', function () { if (accionAviso) { accionAviso(); } });
        verMas.addEventListener('click', function () { cargar(pagina + 1); });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
