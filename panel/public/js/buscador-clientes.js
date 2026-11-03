/*
 * BUSCADOR DE CLIENTES, COMPARTIDO.
 *
 * Convierte un campo de RUT en un buscador: mientras se escribe, propone los
 * clientes ya guardados cuyo RUT, razon social o nombre de fantasia contienen
 * lo escrito (GET /ventas/clientes/buscar). Al elegir uno, el campo queda con
 * su RUT y, si la vista lo pide, se rellenan los demas datos del receptor.
 *
 * Antes habia que saberse el RUT de memoria: el formulario de emision solo
 * completaba los datos DESPUES de teclear el RUT entero, y la cotizacion usaba
 * un <datalist> que solo calza por el comienzo del RUT.
 *
 * MARCADO:
 *   - data-buscar-cliente en el input del RUT. Con valor "normalizado" el RUT
 *     elegido queda sin puntos (77724622-4), como lo guarda la base: es lo que
 *     necesitan los filtros de los listados, que comparan el texto tal cual.
 *   - data-buscar-fuente="proveedores" (opcional): busca proveedores en vez de
 *     clientes (/compras/proveedores/buscar, orden de compra). Mismo
 *     comportamiento; cambia la ruta y los textos.
 *   - data-cliente-campos="razon_social:id-del-campo,giro:otro-id,..." (opcional):
 *     que campo de la pantalla recibe cada dato del cliente elegido. Al ELEGIR
 *     se pisan: elegir un cliente de la lista es pedir exactamente sus datos.
 *     Sin este atributo (filtros de un listado) solo se pone el RUT.
 *
 * Al elegir se disparan 'change' y 'blur' sobre el campo, para que lo que la
 * vista ya hace con un RUT escrito a mano (avisos, marcar "ya esta en tus
 * clientes") corra igual.
 *
 * LOS DATOS DE UN CLIENTE SE VAN CON SU RUT. Si despues de traer un cliente se
 * escribe otro RUT u otro nombre, los campos que se rellenaron con el se
 * vacian, para no emitir a un cliente nuevo con la direccion del anterior. Solo
 * se vacian los que siguen con el valor que trajo el cliente: lo que la persona
 * corrigio a mano se respeta. Las vistas que rellenan por su cuenta (al
 * teclear un RUT completo) avisan con window.buscadorClientes.cargado(), y se
 * enteran del vaciado con el evento 'cliente-descartado' sobre el campo RUT.
 *
 * Teclado: flechas para moverse, Enter elige, Escape cierra. Patron combobox
 * de ARIA (role, aria-expanded, aria-activedescendant). Seccion 14 del design
 * system: el "Buscando..." solo aparece si la respuesta tarda mas de 300 ms.
 */
(function () {
    'use strict';

    var MINIMO = 2;
    var PAUSA_MS = 250;
    var AVISO_ESPERA_MS = 300;
    var contador = 0;
    var FUENTES = {
        clientes: {
            url: '/ventas/clientes/buscar',
            etiqueta: 'Clientes encontrados',
            ninguno: 'Ningun cliente coincide. Si es nuevo, escribe su RUT y completa sus datos.'
        },
        proveedores: {
            url: '/compras/proveedores/buscar',
            etiqueta: 'Proveedores encontrados',
            ninguno: 'Ningun proveedor coincide. Si es nuevo, escribe su RUT y completa sus datos.'
        }
    };

    function formatearRut(rut) {
        var p = String(rut).split('-');
        if (p.length !== 2) { return rut; }
        return p[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.') + '-' + p[1];
    }

    function rutLimpio(v) { return String(v).replace(/[^0-9kK]/g, '').toUpperCase(); }

    /*
     * Registra que los campos {id: valor} se rellenaron con el cliente del RUT
     * que el campo tiene AHORA. Con el mismo RUT se SUMA a lo ya registrado
     * (al elegir de la lista rellena el buscador y despues la vista); con otro
     * RUT lo reemplaza.
     */
    function cargado(input, valores) {
        var rut = rutLimpio(input.value);
        var reg = input._clienteCargado;
        if (!reg || reg.rut !== rut) { reg = input._clienteCargado = { rut: rut, valores: {} }; }
        Object.keys(valores || {}).forEach(function (id) { reg.valores[id] = valores[id]; });
    }

    /*
     * Si el RUT ya no es el del cliente cargado, vacia lo que ese cliente
     * relleno y que nadie toco despues. Devuelve true si descarto algo.
     */
    function descartarSiCambio(input) {
        var reg = input._clienteCargado;
        if (!reg || rutLimpio(input.value) === reg.rut) { return false; }
        input._clienteCargado = null;
        Object.keys(reg.valores).forEach(function (id) {
            var el = document.getElementById(id);
            if (el && el.value === reg.valores[id]) {
                el.value = '';
                el.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });
        input.dispatchEvent(new CustomEvent('cliente-descartado'));
        return true;
    }

    function iniciar(input) {
        if (input._buscadorCliente) { return; }
        input._buscadorCliente = true;
        contador++;

        var normalizado = input.getAttribute('data-buscar-cliente') === 'normalizado';
        var fuente = FUENTES[input.getAttribute('data-buscar-fuente')] || FUENTES.clientes;
        var campos = {};
        (input.getAttribute('data-cliente-campos') || '').split(',').forEach(function (par) {
            var kv = par.split(':');
            if (kv.length === 2 && kv[0].trim() && kv[1].trim()) { campos[kv[0].trim()] = kv[1].trim(); }
        });

        // El contenedor posiciona la lista bajo el campo sin mover el resto
        // del formulario.
        var envoltorio = document.createElement('div');
        envoltorio.className = 'buscador-clientes';
        input.parentNode.insertBefore(envoltorio, input);
        envoltorio.appendChild(input);

        var lista = document.createElement('ul');
        lista.className = 'buscador-clientes__lista';
        lista.id = 'buscador-clientes-' + contador;
        lista.setAttribute('role', 'listbox');
        lista.setAttribute('aria-label', fuente.etiqueta);
        lista.hidden = true;
        envoltorio.appendChild(lista);

        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-controls', lista.id);
        input.setAttribute('autocomplete', 'off');
        // Un <datalist> heredado competiria con esta lista.
        input.removeAttribute('list');

        var resultados = [];
        var activo = -1;
        var temporizador = null;
        var pedido = 0;

        function cerrar() {
            lista.hidden = true;
            lista.innerHTML = '';
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
            resultados = [];
            activo = -1;
        }

        function aviso(texto) {
            lista.innerHTML = '';
            var li = document.createElement('li');
            li.className = 'buscador-clientes__aviso';
            li.setAttribute('role', 'option');
            li.setAttribute('aria-disabled', 'true');
            li.textContent = texto;
            lista.appendChild(li);
            lista.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        }

        function marcar(i) {
            var opciones = lista.querySelectorAll('[data-indice]');
            opciones.forEach(function (o) { o.setAttribute('aria-selected', 'false'); });
            activo = i;
            if (i >= 0 && opciones[i]) {
                opciones[i].setAttribute('aria-selected', 'true');
                input.setAttribute('aria-activedescendant', opciones[i].id);
                opciones[i].scrollIntoView({ block: 'nearest' });
            } else {
                input.removeAttribute('aria-activedescendant');
            }
        }

        function pintar(clientes) {
            resultados = clientes;
            activo = -1;
            if (clientes.length === 0) {
                aviso(fuente.ninguno);
                return;
            }
            lista.innerHTML = '';
            clientes.forEach(function (c, i) {
                var li = document.createElement('li');
                li.id = lista.id + '-op-' + i;
                li.className = 'buscador-clientes__opcion';
                li.setAttribute('role', 'option');
                li.setAttribute('aria-selected', 'false');
                li.setAttribute('data-indice', String(i));
                var nombre = document.createElement('span');
                nombre.className = 'buscador-clientes__nombre';
                nombre.textContent = c.razon_social;
                var detalle = document.createElement('span');
                detalle.className = 'buscador-clientes__detalle';
                detalle.textContent = (c.nombre_fantasia ? c.nombre_fantasia + ' · ' : '') + formatearRut(c.rut);
                li.appendChild(nombre);
                li.appendChild(detalle);
                lista.appendChild(li);
            });
            lista.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        }

        function elegir(i) {
            var c = resultados[i];
            if (!c) { return; }
            input.value = normalizado ? c.rut : formatearRut(c.rut);
            var valores = {};
            Object.keys(campos).forEach(function (dato) {
                var el = document.getElementById(campos[dato]);
                if (el && c[dato] !== undefined) {
                    el.value = c[dato];
                    valores[campos[dato]] = el.value;
                    if (window.validarCampos && el.value !== '') { window.validarCampos.revisar(el); }
                }
            });
            cargado(input, valores);
            cerrar();
            input.dispatchEvent(new Event('change', { bubbles: true }));
            input.dispatchEvent(new Event('blur'));
        }

        function buscar() {
            var q = input.value.trim();
            if (q.length < MINIMO) { cerrar(); return; }
            var mio = ++pedido;
            var espera = setTimeout(function () { if (mio === pedido) { aviso('Buscando...'); } }, AVISO_ESPERA_MS);
            fetch(fuente.url + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    clearTimeout(espera);
                    // Solo cuenta la respuesta a lo ULTIMO escrito, y solo si el
                    // campo sigue con el foco.
                    if (mio !== pedido || document.activeElement !== input) { return; }
                    // Sin respuesta valida (sesion vencida, sin permiso para ver
                    // clientes) no se dice "ningun cliente coincide": seria falso.
                    if (data === null) { cerrar(); return; }
                    pintar(data.resultados || data.clientes || []);
                })
                .catch(function () { clearTimeout(espera); if (mio === pedido) { cerrar(); } });
        }

        input.addEventListener('input', function () {
            descartarSiCambio(input);
            clearTimeout(temporizador);
            temporizador = setTimeout(buscar, PAUSA_MS);
        });

        input.addEventListener('keydown', function (e) {
            var n = lista.querySelectorAll('[data-indice]').length;
            if (e.key === 'ArrowDown') {
                if (lista.hidden) { buscar(); return; }
                if (n) { e.preventDefault(); marcar(activo + 1 >= n ? 0 : activo + 1); }
            } else if (e.key === 'ArrowUp') {
                if (n) { e.preventDefault(); marcar(activo - 1 < 0 ? n - 1 : activo - 1); }
            } else if (e.key === 'Enter') {
                // Enter con una opcion marcada elige; sin opcion marcada no
                // interfiere (el formulario se comporta como siempre).
                if (!lista.hidden && activo >= 0) { e.preventDefault(); elegir(activo); }
            } else if (e.key === 'Escape') {
                if (!lista.hidden) { e.preventDefault(); cerrar(); }
            }
        });

        // mousedown y no click: con click el campo pierde el foco antes, y el
        // blur de la vista leeria lo escrito a medias como si fuera un RUT.
        lista.addEventListener('mousedown', function (e) {
            e.preventDefault();
            var op = e.target.closest('[data-indice]');
            if (op) { elegir(parseInt(op.getAttribute('data-indice'), 10)); }
        });

        input.addEventListener('blur', function () {
            // Diferido: deja terminar un mousedown sobre la lista.
            setTimeout(function () { if (document.activeElement !== input) { cerrar(); } }, 0);
        });
    }

    function iniciarTodos(raiz) {
        (raiz || document).querySelectorAll('input[data-buscar-cliente]').forEach(iniciar);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { iniciarTodos(); });
    } else {
        iniciarTodos();
    }
    window.buscadorClientes = { iniciar: iniciarTodos, cargado: cargado };
})();
