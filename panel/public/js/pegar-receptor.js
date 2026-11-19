/*
 * PEGAR LOS DATOS DEL CLIENTE (formulario de emision).
 *
 * Muchos clientes mandan sus datos de facturacion como un texto ("RUT: ...,
 * Razon social: ..., Giro: ..."). Aqui se pega ese texto y se reparte en los
 * campos del receptor, sin tener que copiar campo por campo.
 *
 * COMO SE LEE EL TEXTO (analizar()):
 *   1. Por etiquetas: "Etiqueta: valor" en la misma linea, o la etiqueta sola
 *      y el valor en la linea siguiente. Se aceptan las variantes habituales
 *      (Razon social / Nombre / Empresa, Direccion / Domicilio, Correo / Mail /
 *      Email DTE...). Telefono, ciudad, region, contacto, etc. se reconocen
 *      para DESCARTARLOS: que no terminen en otro campo.
 *   2. RUT y correo se buscan ademas en todo el texto por su forma, aunque no
 *      lleven etiqueta.
 *   3. Las lineas sin etiqueta que sobran se reparten con cuidado: la primera
 *      es la razon social, la primera con numeros la direccion, una corta sin
 *      numeros despues de la direccion la comuna, y lo que quede sin numeros el
 *      giro. Es una suposicion: por eso, al terminar, se dice que se lleno y se
 *      marca para revisar.
 *
 * QUE SE TOCA: solo los campos que se reconocieron y que existen en el
 * formulario (la boleta no tiene giro, direccion ni comuna). Si el RUT pegado
 * es OTRO que el que habia, se vacia antes el receptor completo: no quedan
 * datos del cliente anterior mezclados. Si el RUT ya esta en mis clientes, lo
 * que el texto no traia se completa desde ahi; lo pegado manda.
 *
 * MARCADO, en la vista:
 *   - [data-abrir-pegar-receptor]: el boton. Viene oculto; este archivo lo
 *     muestra (sin JavaScript no haria nada).
 *   - #pegar-receptor: <dialog> con [data-pegar-texto] (textarea),
 *     [data-pegar-aplicar], [data-pegar-cerrar] y [data-pegar-estado].
 *   - #pegar-receptor-aviso: region viva donde se dice que se completo.
 */
(function () {
    'use strict';

    var CAMPOS = {
        rut: 'receptor-rut',
        razonSocial: 'receptor-razonSocial',
        giro: 'receptor-giro',
        direccion: 'receptor-direccion',
        comuna: 'receptor-comuna',
        email: 'receptor-email'
    };
    var NOMBRES = {
        rut: 'RUT', razonSocial: 'razon social', giro: 'giro',
        direccion: 'direccion', comuna: 'comuna', email: 'correo'
    };

    // Etiqueta normalizada (sin tildes, minusculas, sin puntos) -> campo.
    // null = se reconoce y se descarta. Se prueba la etiqueta MAS LARGA primero
    // ("nombre de fantasia" antes que "nombre").
    var ETIQUETAS = [
        ['rol unico tributario', 'rut'], ['rut empresa', 'rut'], ['rut cliente', 'rut'], ['rut', 'rut'],
        ['razon social', 'razonSocial'], ['nombre o razon social', 'razonSocial'], ['nombre empresa', 'razonSocial'],
        ['nombre de fantasia', null], ['fantasia', null], ['nombre contacto', null], ['nombre de contacto', null],
        ['nombre', 'razonSocial'], ['empresa', 'razonSocial'], ['cliente', 'razonSocial'], ['senores', 'razonSocial'], ['sres', 'razonSocial'],
        ['actividad economica', 'giro'], ['giro comercial', 'giro'], ['actividad', 'giro'], ['giro', 'giro'],
        ['direccion comercial', 'direccion'], ['direccion de facturacion', 'direccion'], ['direccion', 'direccion'],
        ['domicilio', 'direccion'], ['dir', 'direccion'], ['calle', 'direccion'],
        ['comuna', 'comuna'],
        ['correo electronico', 'email'], ['correo de intercambio', 'email'], ['correo dte', 'email'], ['email dte', 'email'],
        ['correo', 'email'], ['e-mail', 'email'], ['email', 'email'], ['mail', 'email'],
        ['telefono', null], ['fono de contacto', null], ['telefono de contacto', null], ['correo de contacto', 'email'], ['fono', null], ['celular', null], ['movil', null], ['whatsapp', null], ['tel', null],
        ['ciudad', null], ['region', null], ['pais', null], ['contacto', null], ['atencion', null], ['att', null],
        ['banco', null], ['cuenta', null], ['tipo de cuenta', null], ['web', null], ['sitio web', null], ['cargo', null]
    ];
    ETIQUETAS.sort(function (a, b) { return b[0].length - a[0].length; });

    var RE_RUT = /\b(\d{1,2}(?:\.?\d{3}){2}\s*-\s*[\dkK])\b/;
    var RE_EMAIL = /[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/;
    var RE_TELEFONO = /^\+?[\d\s()-]{7,}$/;

    function normalizar(s) {
        return s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
            .replace(/\./g, '').replace(/\s+/g, ' ').trim();
    }

    // Si la linea empieza por una etiqueta conocida devuelve {campo, valor};
    // si no, null. "campo" puede ser null (etiqueta que se descarta).
    // Etiquetas que se aceptan SIN dos puntos, con el valor en la misma linea
    // ("Giro Centro de Kinesiologia", "Direccion El Alcantara 467"). Solo las
    // inequivocas: "Calle 1 123" es una direccion, no la etiqueta "calle", y
    // "Banco de Chile" o "Empresa Lopez" pueden ser una razon social.
    var SIN_DOS_PUNTOS = ['rol unico tributario', 'rut', 'razon social', 'nombre o razon social', 'giro', 'giro comercial',
        'actividad economica', 'direccion', 'direccion comercial', 'direccion de facturacion', 'domicilio', 'comuna',
        'correo', 'correo electronico', 'correo dte', 'correo de intercambio', 'email', 'email dte', 'e-mail', 'mail',
        'telefono', 'fono', 'fono de contacto', 'telefono de contacto', 'correo de contacto', 'celular', 'whatsapp', 'ciudad', 'region', 'contacto', 'nombre de fantasia'];

    function leerEtiqueta(linea) {
        // Viñetas y adornos delante de la etiqueta: "- RUT:", "* Giro:".
        linea = linea.replace(/^[-*•·>\s]+/, '');
        var m = linea.match(/^\s*([^:=]{1,40}?)\s*[:=]\s*(.*)$/);
        var i, e;
        if (m) {
            var etiqueta = normalizar(m[1]);
            for (i = 0; i < ETIQUETAS.length; i++) {
                e = ETIQUETAS[i][0];
                if (etiqueta === e || (etiqueta.indexOf(e + ' ') === 0 && etiqueta.length <= e.length + 15)) {
                    return { campo: ETIQUETAS[i][1], valor: m[2].trim() };
                }
            }
        }
        // Sin dos puntos: la etiqueta sola en su linea (el valor viene debajo)
        // o seguida del valor. Se compara palabra por palabra para poder
        // recortar la etiqueta del texto ORIGINAL (con tildes y mayusculas).
        var palabras = linea.trim().split(/\s+/);
        var norm = palabras.map(normalizar);
        for (i = 0; i < ETIQUETAS.length; i++) {
            e = ETIQUETAS[i][0];
            var pe = e.split(' ');
            if (pe.length > norm.length) { continue; }
            var calza = pe.every(function (p, k) { return norm[k] === p; });
            if (!calza) { continue; }
            var resto = palabras.slice(pe.length).join(' ').replace(/^[\s.:=-]+/, '');
            if (resto === '') { return { campo: ETIQUETAS[i][1], valor: '' }; }
            // Etiqueta que se descarta seguida de palabras ("Fono de contacto"):
            // la linea entera se descarta; si no trae el dato, viene debajo.
            if (ETIQUETAS[i][1] === null && SIN_DOS_PUNTOS.indexOf(e) !== -1) {
                return { campo: null, valor: /\d/.test(resto) ? resto : '' };
            }
            if (SIN_DOS_PUNTOS.indexOf(e) !== -1) { return { campo: ETIQUETAS[i][1], valor: resto }; }
        }
        return null;
    }

    function limpiarValor(v) {
        return v.replace(/^[\s"'“”«»]+|[\s"'“”«»,;]+$/g, '').replace(/\s+/g, ' ');
    }

    /*
     * Texto -> {rut, razonSocial, giro, direccion, comuna, email} con los que se
     * reconocieron. "supuestos" lista los campos llenados SIN etiqueta.
     */
    function analizar(texto) {
        var res = {};
        var supuestos = [];
        var lineas = String(texto || '')
            .split(/\r?\n|\t|\s\|\s|;/)
            .map(function (l) { return l.trim(); })
            .filter(function (l) { return l !== ''; });

        var sueltas = [];
        var pendiente; // campo cuya etiqueta vino sola; su valor es la linea siguiente
        lineas.forEach(function (linea) {
            var et = leerEtiqueta(linea);
            if (et) {
                pendiente = undefined;
                if (et.valor === '') { pendiente = et.campo; return; }
                if (et.campo && !res[et.campo]) { res[et.campo] = limpiarValor(et.valor); }
                return;
            }
            if (pendiente !== undefined) {
                if (pendiente && !res[pendiente]) { res[pendiente] = limpiarValor(linea); }
                pendiente = undefined;
                return;
            }
            sueltas.push(linea);
        });

        // RUT y correo por su forma, en cualquier parte.
        var plano = lineas.join('\n');
        if (!res.rut) { var mr = plano.match(RE_RUT); if (mr) { res.rut = mr[1]; } }
        if (res.rut) { res.rut = res.rut.replace(/\s+/g, ''); }
        if (!res.email) { var me = plano.match(RE_EMAIL); if (me) { res.email = me[0]; } }
        if (res.email) { var me2 = res.email.match(RE_EMAIL); res.email = me2 ? me2[0] : res.email; }

        // Lineas sin etiqueta: se quitan las que son RUT, correo o telefono.
        sueltas = sueltas.map(limpiarValor).filter(function (l) {
            if (l === '') { return false; }
            if (RE_RUT.test(l) && l.replace(RE_RUT, '').replace(/[\s,:-]/g, '').length < 4) { return false; }
            if (RE_EMAIL.test(l)) { return false; }
            if (RE_TELEFONO.test(l)) { return false; }
            if (/^(datos|datos de facturacion|datos para factura(r|cion)?)\b/i.test(normalizar(l))) { return false; }
            return true;
        });

        var tieneNumero = function (l) { return /\d/.test(l); };
        var poner = function (campo, valor) { res[campo] = valor; supuestos.push(campo); };
        var usadas = {};

        if (!res.razonSocial && sueltas.length) { poner('razonSocial', sueltas[0]); usadas[0] = true; }
        var iDir = -1;
        if (!res.direccion) {
            for (var i = 0; i < sueltas.length; i++) {
                if (!usadas[i] && tieneNumero(sueltas[i])) { poner('direccion', sueltas[i]); usadas[i] = true; iDir = i; break; }
            }
        }
        if (!res.comuna && iDir >= 0 && sueltas[iDir + 1] && !usadas[iDir + 1]
            && !tieneNumero(sueltas[iDir + 1]) && sueltas[iDir + 1].length <= 25) {
            poner('comuna', sueltas[iDir + 1]); usadas[iDir + 1] = true;
        }
        // La region y el pais no van en la direccion del DTE (70 caracteres):
        // "El Alcantara 467, Valdivia. Region de Los Rios" -> sin la region.
        if (res.direccion) {
            res.direccion = res.direccion
                .replace(/[\s,.-]*\bregi[oó]n\b.*$/i, '')
                .replace(/[\s,.-]*\bchile\s*$/i, '')
                .replace(/[\s,.;-]+$/, '');
        }
        // "Av. Providencia 1234, Providencia": la comuna al final de la direccion,
        // tras la ULTIMA coma (o un punto seguido de palabra con mayuscula,
        // "467. Valdivia"). Solo esa: "Av. Alemania" no se corta.
        if (!res.comuna && res.direccion) {
            var re = /,\s*|\.\s+(?=[A-ZÁÉÍÓÚÑ])/g, mm, corte = null;
            while ((mm = re.exec(res.direccion)) !== null) { corte = mm; }
            var ultima = corte ? res.direccion.slice(corte.index + corte[0].length).trim() : '';
            if (ultima && !tieneNumero(ultima) && ultima.length <= 20
                && !/^(of|oficina|depto|dpto|departamento|local|piso|casa|block|torre|region|rm|chile)\b/i.test(normalizar(ultima))) {
                poner('comuna', ultima);
                res.direccion = res.direccion.slice(0, corte.index).trim();
            }
        }
        if (!res.giro) {
            for (var j = 0; j < sueltas.length; j++) {
                if (!usadas[j] && !tieneNumero(sueltas[j])) { poner('giro', sueltas[j]); usadas[j] = true; break; }
            }
        }

        Object.keys(res).forEach(function (k) { if (!res[k]) { delete res[k]; } });
        return { datos: res, supuestos: supuestos.filter(function (c) { return res[c]; }) };
    }

    function iniciar() {
        var dlg = document.getElementById('pegar-receptor');
        var boton = document.querySelector('[data-abrir-pegar-receptor]');
        if (!dlg || !boton || typeof dlg.showModal !== 'function') { return; }
        var texto = dlg.querySelector('[data-pegar-texto]');
        var estado = dlg.querySelector('[data-pegar-estado]');
        var aplicar = dlg.querySelector('[data-pegar-aplicar]');
        var aviso = document.getElementById('pegar-receptor-aviso');
        boton.hidden = false;

        boton.addEventListener('click', function () {
            texto.value = '';
            estado.textContent = '';
            dlg.showModal();
            texto.focus();
        });
        dlg.querySelectorAll('[data-pegar-cerrar]').forEach(function (b) {
            b.addEventListener('click', function () { dlg.close(); });
        });
        // Ctrl/Cmd+Enter aplica sin ir al boton.
        texto.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); aplicar.click(); }
        });

        aplicar.addEventListener('click', function () {
            var r = analizar(texto.value);
            var datos = r.datos;
            var presentes = Object.keys(CAMPOS).filter(function (c) {
                return datos[c] && document.getElementById(CAMPOS[c]);
            });
            if (!presentes.length) {
                estado.textContent = 'No reconoci ningun dato en el texto. Revisa que tenga el RUT, la razon social o el correo.';
                texto.focus();
                return;
            }
            dlg.close();
            rellenar(datos, presentes, r.supuestos, aviso);
        });
    }

    function campo(c) { return document.getElementById(CAMPOS[c]); }

    function rellenar(datos, presentes, supuestos, aviso) {
        var ux = window.emisionUx;
        var rut = campo('rut');

        if (datos.rut && ux) {
            var ord = ux.ordenarRut(datos.rut);
            if (ord && ord.texto) { datos.rut = ord.texto; }
        }
        // Otro cliente: se vacia el receptor entero antes de llenar.
        if (rut && datos.rut && rut.value.trim() !== '' && rut.value.trim() !== datos.rut) {
            Object.keys(CAMPOS).forEach(function (c) {
                var el = campo(c);
                if (el) { el.value = ''; el.removeAttribute('data-tocado'); }
            });
        }
        if (rut) { rut._clienteCargado = null; }

        presentes.forEach(function (c) {
            var el = campo(c);
            el.value = datos[c];
            // input en todos menos el RUT: en el RUT abriria la lista de busqueda.
            if (c !== 'rut') { el.dispatchEvent(new Event('input', { bubbles: true })); }
        });
        if (ux) { ux.revisarReceptor(true); }

        // Si el RUT ya esta en mis clientes, lo que el texto no traia se
        // completa desde ahi. Lo pegado no se pisa. El aviso sale al final,
        // para no decir "falta el giro" y llenarlo un instante despues.
        var rutValido = rut && datos.rut && (!ux || ux.ordenarRut(datos.rut).valido);
        if (!rutValido) { avisar(presentes, supuestos, null, aviso); return; }
        fetch('/ventas/cliente-por-rut?rut=' + encodeURIComponent(rut.value.trim()))
            .then(function (resp) { return resp.json(); })
            .then(function (data) {
                if (!data || !data.cliente || rut.value.trim() !== datos.rut) { avisar(presentes, supuestos, null, aviso); return; }
                var c = data.cliente;
                var desdeMaestro = { razonSocial: c.razon_social, giro: c.giro, direccion: c.direccion, comuna: c.comuna, email: c.email };
                var completados = [];
                Object.keys(desdeMaestro).forEach(function (k) {
                    var el = campo(k);
                    if (el && el.value.trim() === '' && desdeMaestro[k]) {
                        el.value = desdeMaestro[k];
                        el.dispatchEvent(new Event('input', { bubbles: true }));
                        completados.push(NOMBRES[k]);
                    }
                });
                if (ux) { ux.revisarReceptor(true); }
                avisar(presentes, supuestos, completados, aviso);
            })
            .catch(function () { avisar(presentes, supuestos, null, aviso); });
    }

    // completados: null si el RUT no esta en mis clientes (o no se pudo ver).
    function avisar(presentes, supuestos, completados, aviso) {
        if (!aviso) { return; }
        var msg = 'Datos pegados: ' + presentes.map(function (c) { return NOMBRES[c]; }).join(', ') + '.';
        var dudosos = supuestos.filter(function (c) { return presentes.indexOf(c) !== -1; })
            .map(function (c) { return NOMBRES[c]; });
        if (dudosos.length) { msg += ' Revisa ' + dudosos.join(', ') + ': venia sin etiqueta y lo deduje.'; }
        if (completados !== null) {
            msg += completados.length
                ? ' Ya estaba en tus clientes: ' + completados.join(', ') + ' se completo desde ahi.'
                : ' Ya estaba en tus clientes.';
        }
        var faltan = Object.keys(CAMPOS).filter(function (c) {
            var el = campo(c);
            return el && c !== 'email' && el.value.trim() === '';
        }).map(function (c) { return NOMBRES[c]; });
        if (faltan.length) { msg += ' Falta: ' + faltan.join(', ') + '.'; }
        aviso.textContent = msg;
    }

    window.pegarReceptor = { analizar: analizar };

    if (typeof document === 'undefined') { return; }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
