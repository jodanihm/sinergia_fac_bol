/*
 * PRECIO SIN IVA <-> PRECIO FINAL, COMPARTIDO.
 *
 * Quien usa el panel no tiene por que saber que es "neto" o "bruto": escribe
 * el precio que conoce y el otro se calcula solo. Lo usan la cotizacion, la
 * orden de compra y el maestro de productos. La emision tiene su propia version
 * (emision-form.php), porque ahi el documento puede viajar bruto y aca no: estas
 * pantallas guardan SIEMPRE el precio sin IVA, que es el que tiene columna en la
 * base (DECIMAL(14,4)).
 *
 * MARCADO:
 *   - Un contenedor [data-precios]: una <table> (cada <tr> del tbody es una
 *     linea) o cualquier otro elemento (es una sola linea, como el producto).
 *   - En cada linea: [data-precio="neto"] (el que se envia), [data-precio="final"]
 *     (solo se muestra, sin name), y si existen [data-precio="cantidad"],
 *     [data-precio="descuento"] (%) y [data-precio="exento"] (checkbox).
 *   - data-precios-resumen="id" en el contenedor: un <dl> que se llena con
 *     subtotal, IVA, exento y total mientras se escribe.
 *   - data-precios-iva="exacto": el IVA se calcula sobre el afecto sin
 *     redondear (cotizacion-detalle.php y su PDF); si no, sobre el afecto ya
 *     redondeado a pesos (MySqlOrdenCompraRepository::totales). Si cambia la
 *     formula alla, cambia aqui.
 *
 * Escribir el precio final deja el sin IVA con 4 decimales, lo que guarda la
 * base: 1.000 final da 840,3361 sin IVA, y el total vuelve a dar 1.000.
 * Una linea nueva o un producto elegido del catalogo se sincronizan solos: la
 * vista solo tiene que disparar 'input' en el campo que lleno.
 */
(function () {
    'use strict';

    var TASA_IVA = 19;

    // Coma o punto, como lo leen los handlers (teclado chileno).
    function numero(v) {
        v = (v || '').trim().replace(',', '.');
        if (v === '' || !/^\d+(\.\d+)?$/.test(v)) { return null; }
        return parseFloat(v);
    }
    function pesos(n) { return '$' + Math.round(n).toLocaleString('es-CL'); }
    function lineas(cont) { return cont.tagName === 'TABLE' ? cont.querySelectorAll('tbody tr') : [cont]; }
    function campo(fila, k) { return fila.querySelector('[data-precio="' + k + '"]'); }
    function factor(fila) {
        var ex = campo(fila, 'exento');
        return ex && ex.checked ? 1 : 1 + TASA_IVA / 100;
    }

    // 'final' o 'neto': cual escribio la persona. Se recuerda en la linea para
    // que marcar "exento" despues recalcule el otro y no el que se escribio.
    function sincronizar(fila, desde) {
        var neto = campo(fila, 'neto'), fin = campo(fila, 'final');
        if (!neto || !fin) { return; }
        if (desde) { fila.setAttribute('data-precio-origen', desde); }
        if (fila.getAttribute('data-precio-origen') === 'final') {
            var f = numero(fin.value);
            if (fin.value.trim() === '') { neto.value = ''; }
            else if (f !== null) { neto.value = String(Math.round(f / factor(fila) * 1e4) / 1e4); }
        } else {
            var n = numero(neto.value);
            fin.value = n === null ? '' : String(Math.round(n * factor(fila)));
        }
    }

    function pintarResumen(cont) {
        var dl = document.getElementById(cont.getAttribute('data-precios-resumen') || '');
        if (!dl) { return; }
        var afecto = 0, exento = 0;
        Array.prototype.forEach.call(lineas(cont), function (fila) {
            var cant = campo(fila, 'cantidad') ? numero(campo(fila, 'cantidad').value) : 1;
            var precio = numero((campo(fila, 'neto') || {}).value);
            if (cant === null || precio === null) { return; }
            var desc = campo(fila, 'descuento') ? (numero(campo(fila, 'descuento').value) || 0) : 0;
            var monto = cant * precio * (1 - desc / 100);
            if (factor(fila) === 1) { exento += monto; } else { afecto += monto; }
        });
        var neto = Math.round(afecto), exe = Math.round(exento);
        var iva = Math.round((cont.getAttribute('data-precios-iva') === 'exacto' ? afecto : neto) * TASA_IVA / 100);
        var filas = [['Subtotal sin IVA', neto], ['IVA (19%)', iva]];
        if (exe > 0) { filas.push(['Sin IVA (exento)', exe]); }
        var html = '';
        filas.forEach(function (f) { html += '<dt>' + f[0] + '</dt><dd>' + pesos(f[1]) + '</dd>'; });
        html += '<dt class="ficha__total">Total</dt><dd class="ficha__total">' + pesos(neto + exe + iva) + '</dd>';
        dl.innerHTML = html;
    }

    function preparar(cont) {
        cont.addEventListener('input', function (e) {
            var k = e.target.getAttribute('data-precio');
            var fila = cont.tagName === 'TABLE' ? e.target.closest('tr') : cont;
            if (k === 'neto' || k === 'final') { sincronizar(fila, k); }
            pintarResumen(cont);
        });
        cont.addEventListener('change', function (e) {
            if (e.target.getAttribute('data-precio') === 'exento') {
                sincronizar(cont.tagName === 'TABLE' ? e.target.closest('tr') : cont);
            }
            pintarResumen(cont);
        });
        // Lineas que la vista agrega o quita: se resincronizan y se recalcula.
        var cuerpo = cont.tagName === 'TABLE' ? cont.tBodies[0] : null;
        if (cuerpo && window.MutationObserver) {
            new MutationObserver(function () {
                Array.prototype.forEach.call(lineas(cont), function (f) { sincronizar(f); });
                pintarResumen(cont);
            }).observe(cuerpo, { childList: true });
        }
        Array.prototype.forEach.call(lineas(cont), function (f) { sincronizar(f); });
        pintarResumen(cont);
    }

    function iniciar() { Array.prototype.forEach.call(document.querySelectorAll('[data-precios]'), preparar); }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', iniciar); } else { iniciar(); }
})();
