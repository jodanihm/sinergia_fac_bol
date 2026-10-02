<?php
/**
 * Confirmacion y espera de los formularios de la certificacion. Se incluye UNA
 * vez al final de cada vista que tenga formularios con data-espera.
 *
 * QUE HACE, por atributo del <form>:
 *
 *   data-espera                Patron de espera de la seccion 14 del design
 *                              system (Efectos de carga), el mismo de Cobro en
 *                              linea: el doble envio se corta en el acto pero
 *                              sin nada visible; pasados 300 ms el boton entra
 *                              en carga (spinner dentro, mismo ancho,
 *                              data-cargando + aria-busy + disabled) y el
 *                              formulario queda aria-busy. Volver con "Atras"
 *                              (bfcache) lo restaura.
 *   data-espera-texto="..."    Para lo que habla con el SII y puede pasar de
 *                              10 s: a los 10 s aparece, en una region
 *                              role="status", que se esta haciendo y una barra
 *                              INDETERMINADA (no hay porcentaje real que
 *                              mostrar, y uno inventado se delata solo).
 *   data-confirmar-titulo      Confirmacion en un <dialog> nativo (Escape
 *   data-confirmar-texto       cierra, el foco entra y vuelve solo), con la
 *   data-confirmar-boton       consecuencia en el titulo y un boton que NOMBRA
 *                              la accion. Solo para lo que envia al SII, quema
 *                              folios o declara algo que no se deshace: nunca
 *                              "Aceptar".
 *
 *   Y en un control: data-requerido="mensaje". Se valida al enviar; el error
 *   sale bajo el control (icono + texto + aria-invalid) y el foco va al primero.
 *   Se revalida al cambiar, para que el error se vaya apenas se corrige.
 */
?>
<dialog class="dialogo-confirmar" id="cert-confirmar" aria-labelledby="cert-confirmar-titulo" aria-describedby="cert-confirmar-texto">
    <h2 class="dialogo-confirmar__titulo" id="cert-confirmar-titulo"></h2>
    <p id="cert-confirmar-texto"></p>
    <form method="dialog" class="acciones-formulario">
        <button type="submit" value="no" class="boton-secundario">Volver</button>
        <button type="submit" value="si" class="boton-principal" id="cert-confirmar-si"></button>
    </form>
</dialog>

<script>
(function () {
    var iconoError = <?= json_encode(iconoSvg('alerta', 16, '')); ?>;
    var dlg = document.getElementById('cert-confirmar');
    var pendiente = null;

    function errorDe(control) {
        var id = 'error-' + control.id;
        var p = document.getElementById(id);
        if (!p) {
            p = document.createElement('p');
            p.className = 'campo-error';
            p.id = id;
            p.hidden = true;
            p.innerHTML = iconoError + '<span></span>';
            var ancla = control.closest('label') || control;
            ancla.parentNode.insertBefore(p, ancla.nextSibling);
            var previo = control.getAttribute('aria-describedby');
            control.setAttribute('aria-describedby', (previo ? previo + ' ' : '') + id);
        }
        return p;
    }
    function falta(control) {
        return control.type === 'checkbox' ? !control.checked : control.value.trim() === '';
    }
    function pintar(control) {
        var mal = falta(control);
        var p = errorDe(control);
        p.querySelector('span').textContent = mal ? control.getAttribute('data-requerido') : '';
        p.hidden = !mal;
        if (mal) { control.setAttribute('aria-invalid', 'true'); } else { control.removeAttribute('aria-invalid'); }
        return mal;
    }

    var formularios = Array.prototype.slice.call(document.querySelectorAll('form[data-espera]'));

    formularios.forEach(function (form) {
        var boton = form.querySelector('[type="submit"]');
        var umbral = null;
        var largo = null;
        var aviso = null;

        if (form.hasAttribute('data-espera-texto')) {
            // La region existe desde la carga: una region viva que nace con
            // el texto adentro no siempre se anuncia.
            aviso = document.createElement('div');
            aviso.className = 'espera-larga';
            aviso.setAttribute('role', 'status');
            aviso.hidden = true;
            aviso.innerHTML = '<div class="progreso-indeterminado" role="progressbar" aria-label="Esperando al SII"></div><p></p>';
            form.appendChild(aviso);
        }

        Array.prototype.forEach.call(form.querySelectorAll('[data-requerido]'), function (c) {
            c.addEventListener('change', function () { if (c.hasAttribute('aria-invalid')) { pintar(c); } });
            c.addEventListener('input', function () { if (c.hasAttribute('aria-invalid')) { pintar(c); } });
        });

        form._restaurar = function () {
            clearTimeout(umbral);
            clearTimeout(largo);
            form._enviando = false;
            if (boton) {
                boton.removeAttribute('data-cargando');
                boton.removeAttribute('aria-busy');
                boton.disabled = false;
            }
            form.removeAttribute('aria-busy');
            if (aviso) { aviso.hidden = true; aviso.querySelector('p').textContent = ''; }
        };

        form.addEventListener('submit', function (ev) {
            if (form._enviando) { ev.preventDefault(); return; }

            var malos = Array.prototype.filter.call(form.querySelectorAll('[data-requerido]'), pintar);
            if (malos.length > 0) {
                ev.preventDefault();
                malos[0].focus();
                return;
            }

            if (form.hasAttribute('data-confirmar-titulo') && !form._confirmado && dlg && dlg.showModal) {
                ev.preventDefault();
                pendiente = form;
                document.getElementById('cert-confirmar-titulo').textContent = form.getAttribute('data-confirmar-titulo');
                document.getElementById('cert-confirmar-texto').textContent = form.getAttribute('data-confirmar-texto');
                document.getElementById('cert-confirmar-si').textContent = form.getAttribute('data-confirmar-boton');
                dlg.returnValue = '';
                dlg.showModal();
                return;
            }
            form._confirmado = false;

            form._enviando = true;
            umbral = setTimeout(function () {
                if (boton) {
                    boton.setAttribute('data-cargando', 'true');
                    boton.setAttribute('aria-busy', 'true');
                    boton.disabled = true;
                }
                form.setAttribute('aria-busy', 'true');
            }, 300);
            if (aviso) {
                largo = setTimeout(function () {
                    aviso.hidden = false;
                    aviso.querySelector('p').textContent = form.getAttribute('data-espera-texto');
                }, 10000);
            }
        });
    });

    if (dlg) {
        dlg.addEventListener('close', function () {
            var form = pendiente;
            pendiente = null;
            if (form && dlg.returnValue === 'si') {
                form._confirmado = true;
                if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
            }
        });
    }

    window.addEventListener('pageshow', function (ev) {
        if (ev.persisted) { formularios.forEach(function (f) { f._restaurar(); }); }
    });
})();
</script>
