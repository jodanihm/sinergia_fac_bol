/*
 * VALIDACION DE CAMPOS, COMPARTIDA POR TODOS LOS FORMULARIOS DEL PANEL.
 *
 * Lo carga partials/header.php en todas las pantallas. Sigue el design system
 * (Formularios > Validacion): se valida al SALIR del campo y, desde ahi, se
 * revalida mientras se corrige. Un dato correcto deja el campo en verde con un
 * check (color mas icono, nunca solo color); uno que falta o esta mal, en rojo
 * con el texto de que corregir bajo el campo y aria-invalid. Un opcional vacio
 * queda neutro.
 *
 * QUE FORMULARIOS: los <form method="post">. Los GET son filtros y busquedas.
 *
 * DOS MODOS:
 *   - Por defecto valida completo: pinta errores al salir y al enviar corta el
 *     envio y lleva el foco al primer campo con problema. El corte va en fase
 *     de CAPTURA, antes que el handler de espera (seccion 14) de la vista: un
 *     envio que no va a salir no debe mostrar spinner.
 *   - <form data-validacion-propia>: la vista ya tiene sus reglas y su envio
 *     (emision, cotizacion, cobro en linea...). Aqui no se pinta ningun error:
 *     solo se agrega el verde cuando el campo tiene dato, la vista no lo marco
 *     invalido y pasa estas mismas reglas.
 *
 * QUE CAMPOS: input de texto, correo, fecha, numero, url y telefono, select y
 * textarea, con id. Quedan fuera los de las tablas de lineas (las valida cada
 * vista), los ocultos, los deshabilitados y los de solo lectura. Las
 * contrasenas solo avisan si faltan: nunca se ponen en verde.
 *
 * OBLIGATORIO: atributo required, data-requerido="mensaje" (el de
 * partials/certificacion/_ux-formularios.php), data-obligatorio="mensaje", o
 * un label con la marca .campo-obligatorio.
 *
 * REGLAS POR TIPO: correo (type=email), RUT (name o id con "rut": modulo 11, y
 * al salir queda 12.345.678-9), inputmode=numeric (solo digitos),
 * inputmode=decimal (numero) y lo que el navegador ya sabe (fecha, url,
 * min/max, pattern).
 *
 * GUARDADO AUTOMATICO: un contenedor con data-autoguardar="/ruta" agrupa los
 * campos marcados data-guardar="rut|razonSocial|giro|...". Al salir del
 * ULTIMO de ellos, si el RUT y la razon social estan bien y nada de lo escrito
 * esta mal, se manda a esa ruta como datos[clave]. Lo mismo hace el boton
 * [data-autoguardar-boton]. El resultado se lee en [data-autoguardar-aviso].
 * No se repite para un RUT ya guardado (o marcado por la vista con
 * data-ya-guardado, cuando el autocompletado lo encontro en el maestro).
 */
(function () {
    'use strict';

    var ICONO_ERROR = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
    var MSG_RUT = 'El digito verificador no calza con el RUT. Revisalo, por ejemplo 77.724.622-4.';
    var TIPOS = ['text', 'email', 'date', 'number', 'url', 'tel', 'search', 'password', 'month', 'time'];

    function rutLimpio(v) { return v.replace(/[^0-9kK]/g, '').toUpperCase(); }
    function rutValido(v) {
        var l = rutLimpio(v);
        if (l.length < 2) { return false; }
        var cuerpo = l.slice(0, -1), dv = l.slice(-1), suma = 0, m = 2;
        if (!/^\d+$/.test(cuerpo)) { return false; }
        for (var i = cuerpo.length - 1; i >= 0; i--) {
            suma += parseInt(cuerpo.charAt(i), 10) * m;
            m = m === 7 ? 2 : m + 1;
        }
        var r = 11 - (suma % 11);
        return dv === (r === 11 ? '0' : (r === 10 ? 'K' : String(r)));
    }
    function rutFormateado(v) {
        var l = rutLimpio(v);
        return l.slice(0, -1).replace(/\B(?=(\d{3})+(?!\d))/g, '.') + '-' + l.slice(-1);
    }

    // '' si el correo esta bien; si no, que le falta.
    function msgCorreo(v) {
        if (/\s/.test(v)) { return 'El correo no puede tener espacios.'; }
        var at = v.indexOf('@');
        if (at < 0) { return 'Falta la @, por ejemplo contacto@empresa.cl.'; }
        if (at === 0) { return 'Falta la parte antes de la @, por ejemplo contacto@empresa.cl.'; }
        if (v.indexOf('@', at + 1) >= 0) { return 'El correo tiene mas de una @.'; }
        var dominio = v.slice(at + 1);
        if (dominio === '') { return 'Falta el dominio despues de la @, por ejemplo empresa.cl.'; }
        if (!/^[^.]+(\.[^.]+)*\.[A-Za-z]{2,}$/.test(dominio)) { return 'Falta la parte despues del punto, por ejemplo .cl o .com.'; }
        return '';
    }

    function formDe(c) { return c.form || null; }
    function formGestionado(f) {
        return !!f && (f.getAttribute('method') || '').toLowerCase() === 'post' && !f.hasAttribute('data-sin-validacion');
    }
    function esPropio(f) { return f.hasAttribute('data-validacion-propia'); }

    function gestionado(c) {
        if (!c || !c.id || c.disabled || c.readOnly) { return false; }
        var tag = c.tagName;
        if (tag === 'INPUT') {
            if (TIPOS.indexOf((c.getAttribute('type') || 'text').toLowerCase()) < 0) { return false; }
        } else if (tag !== 'SELECT' && tag !== 'TEXTAREA') {
            return false;
        }
        if (c.closest('table') || c.closest('[hidden]')) { return false; }
        return formGestionado(formDe(c));
    }

    function etiquetaDe(c) {
        return c.closest('label') || (c.id ? document.querySelector('label[for="' + c.id + '"]') : null);
    }
    function msgObligatorio(c) {
        var m = c.getAttribute('data-requerido') || c.getAttribute('data-obligatorio');
        if (m) { return m; }
        if (c.required) { return c.tagName === 'SELECT' ? 'Elige una opcion: es obligatorio.' : 'Completa este campo: es obligatorio.'; }
        var l = etiquetaDe(c);
        if (l && l.querySelector('.campo-obligatorio')) { return 'Completa este campo: es obligatorio.'; }
        return null;
    }

    /* null = neutro (opcional vacio, o contrasena con dato); '' = correcto;
       texto = lo que hay que corregir. */
    function regla(c) {
        var v = (c.value || '').trim();
        var tipo = (c.getAttribute('type') || '').toLowerCase();
        if (v === '') {
            if (c.validity && c.validity.badInput) { return 'Esa fecha no existe: eligela en el calendario.'; }
            return msgObligatorio(c);
        }
        if (tipo === 'password') { return null; }
        if (tipo === 'email') { return msgCorreo(v); }
        if (/(^|[_\-\[])rut([_\-\]]|$)/i.test(c.name || '') || /(^|[_\-])rut([_\-]|$)/i.test(c.id)) {
            return rutValido(v) ? '' : MSG_RUT;
        }
        var modo = c.getAttribute('inputmode');
        if (modo === 'numeric' && !/^\d+$/.test(v)) { return 'Escribe solo numeros, sin puntos ni espacios.'; }
        if (modo === 'decimal' && !/^\d+(\.\d+)?$/.test(v)) { return 'Escribe un numero, por ejemplo 1990 o 1990.50.'; }
        if (c.validity && !c.validity.valid) {
            if (c.validity.typeMismatch && tipo === 'url') { return 'Escribe la direccion completa, por ejemplo https://tusitio.cl.'; }
            if (c.validity.patternMismatch) { return c.getAttribute('data-formato') || c.title || 'Revisa el formato de este dato.'; }
            return c.validationMessage || 'Revisa este dato.';
        }
        return '';
    }

    function errorDe(c) {
        var id = 'error-' + c.id;
        var p = document.getElementById(id);
        if (!p) {
            p = document.createElement('p');
            p.className = 'campo-error';
            p.id = id;
            p.hidden = true;
            p.innerHTML = ICONO_ERROR + '<span></span>';
            var ancla = c.closest('label') || c;
            ancla.parentNode.insertBefore(p, ancla.nextSibling);
            var previo = c.getAttribute('aria-describedby');
            c.setAttribute('aria-describedby', (previo ? previo + ' ' : '') + id);
        }
        if (!p.querySelector('span')) { p.innerHTML = ICONO_ERROR + '<span></span>'; }
        return p;
    }

    function marcarOk(c, si) {
        var campo = c.closest('.form-campo');
        if (campo) { campo.classList.toggle('form-campo--ok', si); }
        c.classList.toggle('campo-ok', si);
    }

    /* Pinta el resultado de la regla. Devuelve el mensaje de error o ''. */
    function revisar(c) {
        var r = regla(c);
        var f = formDe(c);
        if (esPropio(f)) {
            marcarOk(c, r === '' && c.getAttribute('aria-invalid') !== 'true');
            return '';
        }
        var msg = r || '';
        var p = errorDe(c);
        p.querySelector('span').textContent = msg;
        p.hidden = msg === '';
        var campo = c.closest('.form-campo');
        if (campo) {
            campo.classList.toggle('form-campo--error', msg !== '');
            // El error que pinto el servidor (<p class="error">) ya no aplica.
            Array.prototype.forEach.call(campo.querySelectorAll('p.error'), function (e) { e.hidden = true; });
        }
        // Borde rojo que algunas vistas ponen en linea tras un POST rechazado.
        c.style.borderColor = '';
        if (msg === '') { c.removeAttribute('aria-invalid'); } else { c.setAttribute('aria-invalid', 'true'); }
        marcarOk(c, r === '');
        return msg;
    }

    // Tras el handler de la vista (que pudo formatear o marcar el campo).
    function despues(fn) { setTimeout(fn, 0); }

    document.addEventListener('focusout', function (ev) {
        var c = ev.target;
        if (!gestionado(c)) { return; }
        despues(function () {
            if (!esPropio(formDe(c))) {
                if (c.type === 'email') { c.value = c.value.trim(); }
                if (/(^|[_\-\[])rut([_\-\]]|$)/i.test(c.name || '') && rutValido(c.value)) { c.value = rutFormateado(c.value); }
            }
            c.setAttribute('data-tocado', '1');
            revisar(c);
            autoguardarSiUltimo(c);
        });
    });
    ['input', 'change'].forEach(function (tipo) {
        document.addEventListener(tipo, function (ev) {
            var c = ev.target;
            if (!gestionado(c)) { return; }
            if (c.hasAttribute('data-tocado') || tipo === 'change' && c.tagName === 'SELECT') {
                despues(function () { revisar(c); });
            }
        });
    });

    function controlesDe(form) {
        return Array.prototype.filter.call(form.elements, gestionado);
    }

    document.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (!formGestionado(form) || esPropio(form)) { return; }
        var malos = controlesDe(form).filter(function (c) {
            c.setAttribute('data-tocado', '1');
            return revisar(c) !== '';
        });
        if (malos.length > 0) {
            ev.preventDefault();
            ev.stopImmediatePropagation();
            malos[0].focus();
        }
    }, true);

    // --- Guardado automatico (cliente / proveedor) -------------------------
    function campoDe(grupo, clave) { return grupo.querySelector('[data-guardar="' + clave + '"]'); }
    function grupoListo(grupo) {
        var rut = campoDe(grupo, 'rut');
        var razon = campoDe(grupo, 'razonSocial');
        if (!rut || !razon || regla(rut) !== '' || razon.value.trim() === '') { return false; }
        return Array.prototype.every.call(grupo.querySelectorAll('[data-guardar]'), function (c) {
            var r = regla(c);
            return r === null || r === '';
        });
    }
    function guardarGrupo(grupo, automatico) {
        if (grupo._guardando) { return; }
        var form = grupo.closest('form');
        var boton = grupo.querySelector('[data-autoguardar-boton]');
        var aviso = grupo.querySelector('[data-autoguardar-aviso]');
        var campos = Array.prototype.slice.call(grupo.querySelectorAll('[data-guardar]'));
        if (!grupoListo(grupo)) {
            if (automatico) { return; }
            var primero = null;
            campos.forEach(function (c) {
                c.setAttribute('data-tocado', '1');
                var malo = esPropio(form)
                    ? (c.getAttribute('aria-invalid') === 'true' || regla(c))
                    : revisar(c);
                if (malo && !primero) { primero = c; }
            });
            if (aviso) { aviso.textContent = 'Para guardarlo faltan datos: revisa los campos marcados (al menos RUT y razon social).'; }
            if (primero) { primero.focus(); }
            return;
        }
        var datos = new URLSearchParams();
        var token = form && form.querySelector('[name="csrf_token"]');
        datos.append('csrf_token', token ? token.value : '');
        campos.forEach(function (c) { datos.append('datos[' + c.getAttribute('data-guardar') + ']', c.value.trim()); });
        var rutEnviado = rutLimpio(campoDe(grupo, 'rut').value);

        grupo._guardando = true;
        var umbral = null;
        if (boton) {
            boton.disabled = true;
            umbral = setTimeout(function () {
                boton.setAttribute('data-cargando', 'true');
                boton.setAttribute('aria-busy', 'true');
            }, 300);
        }
        fetch(grupo.getAttribute('data-autoguardar'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: datos.toString(),
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (aviso) { aviso.textContent = (data && data.mensaje) || 'No se pudo guardar.'; }
                if (data && (data.estado === 'ok' || data.estado === 'existe')) {
                    grupo.setAttribute('data-ya-guardado', rutEnviado);
                }
            })
            .catch(function () {
                if (aviso) { aviso.textContent = 'No se pudo guardar. Revisa tu conexion o tus permisos y vuelve a intentarlo.'; }
            })
            .then(function () {
                clearTimeout(umbral);
                grupo._guardando = false;
                if (boton) {
                    boton.disabled = false;
                    boton.removeAttribute('data-cargando');
                    boton.removeAttribute('aria-busy');
                }
            });
    }
    function autoguardarSiUltimo(c) {
        var grupo = c.closest('[data-autoguardar]');
        if (!grupo || !c.hasAttribute('data-guardar')) { return; }
        var campos = grupo.querySelectorAll('[data-guardar]');
        if (campos[campos.length - 1] !== c) { return; }
        var rut = campoDe(grupo, 'rut');
        if (rut && rutLimpio(rut.value) === grupo.getAttribute('data-ya-guardado')) { return; }
        guardarGrupo(grupo, true);
    }
    document.addEventListener('click', function (ev) {
        var boton = ev.target.closest && ev.target.closest('[data-autoguardar-boton]');
        if (!boton) { return; }
        var grupo = boton.closest('[data-autoguardar]');
        if (grupo) { guardarGrupo(grupo, false); }
    });

    // Al cargar: un formulario que llega con datos (editar, o un POST
    // rechazado) muestra en verde lo que ya esta bien. No se pintan errores:
    // los que haya los trae el servidor.
    function inicial() {
        Array.prototype.forEach.call(document.querySelectorAll('form'), function (form) {
            if (!formGestionado(form)) { return; }
            if (!esPropio(form)) { form.noValidate = true; }
            controlesDe(form).forEach(function (c) {
                var campo = c.closest('.form-campo');
                var conErrorServidor = c.style.borderColor !== '' || (campo && (campo.classList.contains('form-campo--error') || campo.querySelector('p.error')));
                if (c.value.trim() !== '' && !conErrorServidor && c.getAttribute('aria-invalid') !== 'true' && regla(c) === '') { marcarOk(c, true); }
            });
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', inicial);
    } else {
        inicial();
    }

    window.validarCampos = { msgCorreo: msgCorreo, rutValido: rutValido, revisar: revisar };
})();
