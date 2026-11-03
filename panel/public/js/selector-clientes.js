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
        var avisoIcono = dlg.querySelector('[data-selector-aviso-icono]');
        var avisoTitulo = dlg.querySelector('[data-selector-aviso-titulo]');
        var avisoTexto = dlg.querySelector('[data-selector-aviso-texto]');
        var limpiar = dlg.querySelector('[data-selector-limpiar]');
        var filtros = dlg.querySelector('[data-selector-filtros]');
        var iconos = dlg.querySelector('[data-selector-iconos]').content;
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
        var filtro = '';

        boton.hidden = false;

        function formatearRut(rut) {
            var p = String(rut).split('-');
            if (p.length !== 2) { return String(rut); }
            return p[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.') + '-' + p[1];
        }

        function icono(nombre) {
            return iconos.querySelector('[data-icono="' + nombre + '"] svg').cloneNode(true);
        }

        // Sin tildes ni mayusculas, caracter por caracter: lo mismo que hace la
        // base al comparar, para que "ferreteria" marque "Ferretería".
        function plegar(texto) {
            var mapa = [], plano = '';
            Array.prototype.forEach.call(String(texto), function (ch, i) {
                var p = ch.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
                for (var n = 0; n < p.length; n++) { mapa.push(i); }
                plano += p;
            });
            return { plano: plano, mapa: mapa };
        }

        // Tramos [desde, hasta) del texto donde aparece alguna palabra buscada.
        function tramos(texto, palabras) {
            var f = plegar(texto), res = [];
            palabras.forEach(function (w) {
                var desde = 0, i;
                while (w && (i = f.plano.indexOf(w, desde)) !== -1) {
                    res.push([f.mapa[i], f.mapa[i + w.length - 1] + 1]);
                    desde = i + w.length;
                }
            });
            res.sort(function (a, b) { return a[0] - b[0]; });
            return res;
        }

        // Con nodos de texto, nunca innerHTML: son datos que escribio alguien.
        function resaltado(padre, texto, palabras) {
            var cursor = 0;
            tramos(texto, palabras).forEach(function (t) {
                if (t[0] < cursor) { return; }
                padre.appendChild(document.createTextNode(String(texto).slice(cursor, t[0])));
                var m = document.createElement('mark');
                m.className = 'selector-clientes__marca';
                m.textContent = String(texto).slice(t[0], t[1]);
                padre.appendChild(m);
                cursor = t[1];
            });
            padre.appendChild(document.createTextNode(String(texto).slice(cursor)));
        }

        function linea(clase, texto, palabras) {
            var s = document.createElement('span');
            s.className = 'selector-clientes__' + clase;
            resaltado(s, texto, palabras || []);
            return s;
        }

        function rutSinFormato(v) { return String(v).replace(/[^0-9kK]/g, '').toUpperCase(); }

        // "Ferreteria El Roble" -> "FE". Solo letras: sin "S.A." ni numeros.
        function iniciales(nombre) {
            var p = String(nombre).split(/\s+/).filter(function (w) { return /^[\p{L}]/u.test(w); });
            return ((p[0] || '?').charAt(0) + (p[1] ? p[1].charAt(0) : '')).toUpperCase();
        }

        function celda(clase) {
            var s = document.createElement('span');
            s.className = 'selector-clientes__' + clase;
            return s;
        }

        function fila(c, i, palabras) {
            var li = document.createElement('li');
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'selector-clientes__fila';
            b.setAttribute('data-indice', String(i));

            var avatar = celda('avatar');
            avatar.setAttribute('aria-hidden', 'true');
            avatar.textContent = iniciales(c.razon_social);
            b.appendChild(avatar);

            var cliente = celda('cliente');
            cliente.appendChild(linea('nombre', c.razon_social, palabras));
            if (c.nombre_fantasia) { cliente.appendChild(linea('secundario', c.nombre_fantasia, palabras)); }
            if (c.email) { cliente.appendChild(linea('secundario', c.email, palabras)); }
            if (avisarFaltantes && c.faltan && c.faltan.length) {
                var falta = linea('marca-estado selector-clientes__marca-estado--falta', 'Falta ' + c.faltan.join(', '));
                falta.insertBefore(icono('alerta'), falta.firstChild);
                cliente.appendChild(falta);
            }
            if (campoRut.value.trim() !== '' && rutSinFormato(campoRut.value) === rutSinFormato(c.rut)) {
                b.setAttribute('aria-current', 'true');
                var actual = linea('marca-estado selector-clientes__marca-estado--actual', 'Receptor actual');
                actual.insertBefore(icono('check'), actual.firstChild);
                cliente.appendChild(actual);
            }
            b.appendChild(cliente);

            b.appendChild(linea('rut', formatearRut(c.rut), palabras));

            var giro = linea('giro', c.giro || '', palabras);
            if (c.giro) { giro.title = c.giro; }
            b.appendChild(giro);

            var lugar = celda('lugar');
            if (c.comuna) { lugar.appendChild(linea('comuna', c.comuna, palabras)); }
            if (c.direccion) { lugar.appendChild(linea('secundario', c.direccion, palabras)); }
            b.appendChild(lugar);

            var ir = celda('ir');
            ir.setAttribute('aria-hidden', 'true');
            ir.appendChild(icono('flecha'));
            b.appendChild(ir);

            li.appendChild(b);
            return li;
        }

        function pintarSkeleton() {
            lista.innerHTML = '';
            for (var i = 0; i < FILAS_SKELETON; i++) {
                var li = document.createElement('li');
                li.className = 'selector-clientes__fila selector-clientes__fila--skeleton';
                li.setAttribute('aria-hidden', 'true');
                li.innerHTML = '<span class="skeleton selector-clientes__avatar"></span>'
                    + '<span class="selector-clientes__cliente"><span class="skeleton skeleton--nombre"></span><span class="skeleton skeleton--datos"></span></span>'
                    + '<span class="skeleton skeleton--rut"></span>'
                    + '<span class="skeleton skeleton--datos selector-clientes__giro"></span>'
                    + '<span class="selector-clientes__lugar"><span class="skeleton skeleton--corto"></span><span class="skeleton skeleton--datos"></span></span>';
                lista.appendChild(li);
            }
        }

        function mostrarAviso(icon, titulo, texto, accion, etiqueta) {
            avisoIcono.innerHTML = '';
            avisoIcono.appendChild(icono('aviso-' + icon));
            avisoTitulo.textContent = titulo;
            avisoTexto.textContent = texto;
            accionAviso = accion || null;
            avisoAccion.hidden = !accion;
            avisoAccion.textContent = etiqueta || '';
            aviso.hidden = false;
        }

        function resumen(texto) {
            if (total === 0) { return ''; }
            var cuantos = total === 1
                ? (texto !== '' ? '1 cliente coincide' : '1 cliente')
                : total.toLocaleString('es-CL') + (texto !== '' ? ' clientes coinciden' : ' clientes');
            return clientes.length < total
                ? cuantos + ', se ven ' + clientes.length.toLocaleString('es-CL')
                : cuantos;
        }

        function pintarFiltros(data) {
            // En boleta no falta nada, y sin faltantes los tres filtros son el mismo.
            filtros.hidden = !avisarFaltantes || (data.incompletos === 0 && filtro === '');
            var cuentas = { '': data.todos, listos: data.todos - data.incompletos, incompletos: data.incompletos };
            Array.prototype.forEach.call(filtros.querySelectorAll('[data-selector-filtro]'), function (b) {
                var f = b.getAttribute('data-selector-filtro');
                b.setAttribute('aria-pressed', f === filtro ? 'true' : 'false');
                b.querySelector('[data-cuenta]').textContent = '(' + cuentas[f].toLocaleString('es-CL') + ')';
            });
        }

        function pintarResultado(texto, desde) {
            if (desde === 0) { lista.innerHTML = ''; zona.scrollTop = 0; }
            var palabras = plegar(texto).plano.split(/\s+/).filter(Boolean);
            for (var i = desde; i < clientes.length; i++) { lista.appendChild(fila(clientes[i], i, palabras)); }
            verMas.hidden = clientes.length >= total;
            aviso.hidden = true;
            estado.textContent = resumen(texto);
            if (total === 0) {
                if (texto === '' && filtro !== '') {
                    mostrarAviso('clientes', 'Ningun cliente en este filtro', 'Prueba viendo todos tus clientes.', function () {
                        filtro = '';
                        cargar(1);
                    }, 'Ver todos');
                } else if (texto !== '') {
                    mostrarAviso('clientes', 'Ningun cliente coincide con "' + texto + '"', 'Prueba con menos palabras o solo parte del RUT.', function () {
                        limpiarBusqueda();
                    }, 'Limpiar busqueda');
                } else {
                    mostrarAviso('clientes', 'Todavia no tienes clientes guardados', 'Escribe los datos del receptor en el formulario: se guarda solo al salir del correo.');
                }
            }
        }

        function error(r, reintentar) {
            lista.innerHTML = '';
            verMas.hidden = true;
            estado.textContent = '';
            if (r && r.status === 403) {
                mostrarAviso('alerta', 'Tu usuario no puede ver los clientes', 'Pide el permiso a quien administra la cuenta, o escribe los datos del receptor a mano.');
            } else {
                mostrarAviso('alerta', 'No se pudieron cargar los clientes', 'Revisa tu conexion y vuelve a intentarlo.', reintentar, 'Reintentar');
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
                    estado.textContent = '';
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

            var url = URL_BUSQUEDA + '?q=' + encodeURIComponent(texto) + '&pagina=' + numero
                + (filtro ? '&estado=' + encodeURIComponent(filtro) : '');
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
                        pintarFiltros(data);
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
            limpiar.hidden = true;
            filtro = '';
            filtros.hidden = true;
            clientes = [];
            total = 0;
            lista.innerHTML = '';
            estado.textContent = '';
            aviso.hidden = true;
            verMas.hidden = true;
            dlg.showModal();
            // En un telefono, el teclado taparia la lista que se viene a mirar:
            // se abre al tocar el buscador, no solo.
            if (window.matchMedia && window.matchMedia('(pointer: coarse)').matches) { dlg.focus(); } else { q.focus(); }
            cargar(1);
        });

        dlg.addEventListener('close', function () {
            // Una respuesta que llegue con el modal cerrado ya no pinta nada.
            pedido++;
            clearTimeout(pausa);
        });

        dlg.addEventListener('click', function (e) {
            if (e.target.closest('[data-selector-cerrar]')) { dlg.close(); return; }
            // El fondo oscuro tambien es el <dialog>: solo cierra el clic FUERA de
            // su caja, no el que cae en su margen interior.
            if (e.target === dlg) {
                var caja = dlg.getBoundingClientRect();
                if (e.clientX < caja.left || e.clientX > caja.right || e.clientY < caja.top || e.clientY > caja.bottom) { dlg.close(); }
                return;
            }
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

        function limpiarBusqueda() {
            q.value = '';
            filtro = '';
            limpiar.hidden = true;
            cargar(1);
            q.focus();
        }
        limpiar.addEventListener('click', limpiarBusqueda);

        q.addEventListener('input', function () {
            limpiar.hidden = q.value === '';
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

        filtros.addEventListener('click', function (e) {
            var b = e.target.closest('[data-selector-filtro]');
            if (!b) { return; }
            filtro = b.getAttribute('data-selector-filtro');
            // Se marca en el acto: la respuesta puede tardar.
            Array.prototype.forEach.call(filtros.querySelectorAll('[data-selector-filtro]'), function (o) {
                o.setAttribute('aria-pressed', o === b ? 'true' : 'false');
            });
            cargar(1);
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
