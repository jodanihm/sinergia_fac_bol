/*
 * CONTADOR DE CARACTERES PARA LOS CAMPOS QUE VIAJAN AL SII, COMPARTIDO.
 *
 * Cada texto libre del DTE tiene un largo maximo en el esquema del SII
 * (docs/18_Schema_XML_DTE/DTE_v10.xsd) y un solo caracter de mas rechaza el
 * sobre ENTERO con RSC, sin decir que campo fue. Paso con la factura folio 41
 * de EASY AGENDA SPA: el giro del cliente traia 69 caracteres y GiroRecep
 * admite 40. El motor ya recorta al largo del esquema (DteXmlBuilder::LARGO_MAX);
 * este contador es para que quien emite lo VEA antes y decida como abreviar,
 * en vez de que el documento salga con un texto cortado a mitad de palabra.
 *
 * Por eso NO usa maxlength: un dato que llega largo desde el SII o desde el
 * maestro de clientes no se podria completar ni corregir con tranquilidad, y el
 * navegador marcaria el campo invalido (tooLong) y cortaria el envio. Se avisa,
 * no se bloquea.
 *
 * MARCADO:
 *   - data-max-sii="N" en el input o textarea: N es el maxLength del XSD. Si
 *     cambia el esquema, cambia aqui y en DteXmlBuilder::LARGO_MAX.
 *   - data-contador="discreto": el contador solo aparece con el foco o cuando
 *     el texto se acerca al tope (lineas de detalle, donde una cifra fija bajo
 *     cada celda alargaria la tabla sin decir nada).
 *
 * El contador se pone como <small class="form-contador"> justo despues del
 * campo. Pasado el tope, dice con que texto exacto saldra el documento (mismo
 * recorte que el motor: sin espacios a los lados, N caracteres, sin espacio
 * final) y ese aviso se anuncia al lector de pantalla una sola vez, no a cada
 * tecla.
 *
 * Los campos que la vista rellena por codigo (cliente por RUT, catalogo de
 * productos) no disparan 'input'. En vez de pedirle a cada vista que avise, se
 * intercepta el value de esos campos: cualquier asignacion refresca el contador.
 * Las lineas de detalle que se agregan despues se detectan solas.
 */
(function () {
    'use strict';

    var CERCA = 0.85;
    var descriptor = {
        INPUT: Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value'),
        TEXTAREA: Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value')
    };

    function recortar(texto, max) {
        return Array.from(texto.trim()).slice(0, max).join('').replace(/\s+$/, '');
    }

    function refrescar(campo) {
        var c = campo._contador;
        if (!c) { return; }
        var max = c.max;
        // Array.from cuenta por caracter, igual que mb_strlen y el XSD: una
        // tilde o una enie son UN caracter, aunque en bytes sean dos.
        var largo = Array.from(campo.value.trim()).length;
        var excede = largo > max;
        c.cifra.textContent = largo + ' / ' + max;
        c.nodo.classList.toggle('form-contador--cerca', !excede && largo >= max * CERCA);
        c.nodo.classList.toggle('form-contador--excede', excede);
        if (c.discreto) {
            c.nodo.hidden = !excede && largo < max * CERCA && document.activeElement !== campo;
        }
        var aviso = excede
            ? 'El SII acepta ' + max + ' caracteres. Saldra asi: "' + recortar(campo.value, max) + '"'
            : '';
        // Solo se reescribe al cambiar: el role=status anuncia cada cambio de
        // texto, y repetir el mismo aviso a cada tecla seria ruido.
        if (c.aviso.textContent !== aviso) { c.aviso.textContent = aviso; }
    }

    function preparar(campo) {
        if (campo._contador) { return; }
        var max = parseInt(campo.getAttribute('data-max-sii'), 10);
        if (!(max > 0)) { return; }

        var nodo = document.createElement('small');
        nodo.className = 'form-contador';
        nodo.id = (campo.id || ('campo-' + Math.random().toString(36).slice(2))) + '-contador';
        var cifra = document.createElement('span');
        cifra.className = 'form-contador__cifra';
        var aviso = document.createElement('span');
        aviso.className = 'form-contador__aviso';
        aviso.setAttribute('role', 'status');
        nodo.appendChild(cifra);
        nodo.appendChild(aviso);
        campo.insertAdjacentElement('afterend', nodo);

        var desc = campo.getAttribute('aria-describedby');
        campo.setAttribute('aria-describedby', (desc ? desc + ' ' : '') + nodo.id);

        campo._contador = {
            max: max,
            nodo: nodo,
            cifra: cifra,
            aviso: aviso,
            discreto: campo.getAttribute('data-contador') === 'discreto'
        };

        var d = descriptor[campo.tagName];
        if (d && d.set) {
            Object.defineProperty(campo, 'value', {
                configurable: true,
                get: function () { return d.get.call(this); },
                set: function (v) { d.set.call(this, v); refrescar(this); }
            });
        }

        campo.addEventListener('input', function () { refrescar(campo); });
        campo.addEventListener('focus', function () { refrescar(campo); });
        campo.addEventListener('blur', function () { refrescar(campo); });
        refrescar(campo);
    }

    function prepararEn(raiz) {
        if (raiz.matches && raiz.matches('[data-max-sii]')) { preparar(raiz); }
        if (raiz.querySelectorAll) {
            Array.prototype.forEach.call(raiz.querySelectorAll('[data-max-sii]'), preparar);
        }
    }

    function iniciar() {
        prepararEn(document);
        new MutationObserver(function (cambios) {
            cambios.forEach(function (m) {
                Array.prototype.forEach.call(m.addedNodes, function (n) {
                    if (n.nodeType === 1) { prepararEn(n); }
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
