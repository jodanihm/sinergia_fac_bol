/**
 * Visor del diagrama ER: dibuja con mermaid y agrega lupa (zoom anclado al
 * cursor) y arrastre (pan).
 *
 * PORTADO A JAVASCRIPT PLANO desde el componente React del panel hermano. Este
 * proyecto no tiene build ni dependencias de npm, asi que no hay React ni forma
 * de compilar JSX -- y agregarlos por un diagrama seria desproporcionado. El
 * comportamiento es el mismo.
 *
 * EL TEXTO DEL DIAGRAMA NO SE ARMA AQUI. Llega ya terminado desde PHP, dentro
 * de un <script type="application/x-mermaid"> que el navegador no ejecuta ni
 * interpreta: lo trata como texto opaco. Asi el diagrama no necesita que este
 * script sepa nada de la base, y el unico lugar donde se decide que sale a
 * pantalla sigue siendo el servidor.
 */
(function () {
    'use strict';

    var viewport = document.getElementById('er-viewport');
    var canvas = document.getElementById('er-canvas');
    var fuente = document.getElementById('er-fuente');
    if (!viewport || !canvas || !fuente || typeof window.mermaid === 'undefined') {
        return;
    }

    var escala = 1;
    var pos = { x: 20, y: 20 };
    var arrastre = null;
    var etiqueta = document.getElementById('er-zoom');

    /* Limites del zoom. Sin el minimo, dos vueltas de rueda hacia atras dejan el
       diagrama en un punto invisible y parece que se borro. */
    function acotar(valor) {
        return Math.min(6, Math.max(0.05, valor));
    }

    function aplicar() {
        canvas.style.transform = 'translate(' + pos.x + 'px, ' + pos.y + 'px) scale(' + escala + ')';
        if (etiqueta) {
            etiqueta.textContent = Math.round(escala * 100) + '%';
        }
    }

    /* Zoom manteniendo quieto el punto (px, py) del viewport: es lo que hace que
       la rueda acerque HACIA donde esta el cursor y no hacia el origen. */
    function zoomEn(px, py, factor) {
        var anterior = escala;
        var nueva = acotar(Number((anterior * factor).toFixed(3)));
        var k = nueva / anterior;
        pos = { x: px - (px - pos.x) * k, y: py - (py - pos.y) * k };
        escala = nueva;
        aplicar();
    }

    function zoomCentro(factor) {
        zoomEn(viewport.clientWidth / 2, viewport.clientHeight / 2, factor);
    }

    function encuadrar(svg) {
        var vb = (svg.getAttribute('viewBox') || '').split(/\s+/).map(Number);
        var ancho = vb[2] || 1200;
        var alto = vb[3] || 800;

        /* Se le sacan el style y se le fijan medidas reales: mermaid emite un
           svg con width:100% que, dentro de un contenedor transformado, se
           reescala solo y pelea con el zoom. */
        svg.removeAttribute('style');
        svg.setAttribute('width', String(ancho));
        svg.setAttribute('height', String(alto));

        var ajuste = Math.min(
            (viewport.clientWidth - 40) / ancho,
            (viewport.clientHeight - 40) / alto,
            1
        );
        escala = ajuste > 0 ? Number(ajuste.toFixed(3)) : 1;
        pos = {
            x: (viewport.clientWidth - ancho * escala) / 2,
            y: (viewport.clientHeight - alto * escala) / 2
        };
        aplicar();
    }

    window.mermaid.initialize({ startOnLoad: false, theme: 'dark', securityLevel: 'strict' });

    window.mermaid.render('er-svg', fuente.textContent || '')
        .then(function (resultado) {
            canvas.innerHTML = resultado.svg;
            var svg = canvas.querySelector('svg');
            if (svg) {
                encuadrar(svg);
            }
        })
        .catch(function (error) {
            /* Un fallo de mermaid deja el visor en blanco sin decir nada, asi
               que se dice: el mensaje incluye el motivo, que casi siempre apunta
               a un nombre que el saneado de DiagramaEr no cubrio. */
            canvas.innerHTML = '';
            var aviso = document.createElement('p');
            aviso.className = 'error';
            aviso.textContent = 'No se pudo dibujar el diagrama: ' + (error && error.message ? error.message : error);
            viewport.appendChild(aviso);
        });

    /* Rueda: listener nativo y no pasivo, porque hay que preventDefault() para
       que la pagina no haga scroll mientras se hace zoom. */
    viewport.addEventListener('wheel', function (evento) {
        evento.preventDefault();
        var caja = viewport.getBoundingClientRect();
        zoomEn(evento.clientX - caja.left, evento.clientY - caja.top, evento.deltaY < 0 ? 1.12 : 0.893);
    }, { passive: false });

    viewport.addEventListener('mousedown', function (evento) {
        arrastre = { sx: evento.clientX, sy: evento.clientY, ox: pos.x, oy: pos.y };
    });
    viewport.addEventListener('mousemove', function (evento) {
        if (!arrastre) {
            return;
        }
        pos = { x: arrastre.ox + (evento.clientX - arrastre.sx), y: arrastre.oy + (evento.clientY - arrastre.sy) };
        aplicar();
    });
    viewport.addEventListener('mouseup', function () { arrastre = null; });
    viewport.addEventListener('mouseleave', function () { arrastre = null; });

    var controles = document.querySelector('.mermaid-controls');
    if (controles) {
        /* Los controles viven DENTRO del viewport, asi que sin esto arrastrar
           desde un boton movería el diagrama. */
        controles.addEventListener('mousedown', function (evento) { evento.stopPropagation(); });
    }

    var acercar = document.getElementById('er-mas');
    var alejar = document.getElementById('er-menos');
    var reiniciar = document.getElementById('er-reset');
    if (acercar) { acercar.addEventListener('click', function () { zoomCentro(1.25); }); }
    if (alejar) { alejar.addEventListener('click', function () { zoomCentro(0.8); }); }
    if (reiniciar) { reiniciar.addEventListener('click', function () { zoomCentro(1 / escala); }); }
}());
