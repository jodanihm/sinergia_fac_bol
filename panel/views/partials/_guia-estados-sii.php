<?php
/**
 * Guia de estados del SII: boton + <dialog> con todos los estados que puede
 * mostrar el listado del panel de emision, agrupados y explicados.
 *
 * Uso: require este partial donde vaya el boton. Pinta el boton en ese punto y
 * el <dialog> justo despues (el <dialog> modal sube a la top layer, asi que no
 * importa donde quede en el DOM).
 *
 * LOS DOS INDICADORES NO SE ESCRIBEN A MANO. "Se puede rehacer" sale de
 * RehacerDocumento::permite y "Fuera de los totales" de EstadoContable::
 * esRechazado: son las mismas reglas que pintan la fila roja y que calculan los
 * totales. Si una de esas listas cambia, la guia cambia con ella. El texto
 * para el usuario vive en datos/estados-sii.php, compartido con el listado.
 *
 * El texto evita tildes a proposito, igual que el resto del panel.
 */

use Plantiflex\FacturacionCl\Sii\EstadoContable;
use Plantiflex\FacturacionCl\Sii\RehacerDocumento;

$guiaE = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$guiaCatalogo = require __DIR__ . '/../../datos/estados-sii.php';
$guiaGrupos   = $guiaCatalogo['grupos'];
$guiaEstados  = array_map(
    static fn (array $x): array => [$x['grupo'], $x['codigo'], $x['nombre'], $x['explica'], $x['hacer']],
    $guiaCatalogo['estados']
);

$guiaPorGrupo = array_fill_keys(array_keys($guiaGrupos), []);
foreach ($guiaEstados as $fila) {
    $guiaPorGrupo[$fila[0]][] = $fila;
}
?>
<button type="button" class="boton-secundario boton-guia-estados" data-guia-estados-abrir aria-haspopup="dialog" aria-controls="guia-estados">
    <?= iconoSvg('info', 18, 'boton-guia-estados__icono'); ?>
    <span>Guia de estados</span>
</button>

<dialog class="guia-estados" id="guia-estados" aria-labelledby="guia-estados-titulo" aria-describedby="guia-estados-sub">
    <header class="guia-estados__cabecera">
        <span class="guia-estados__emblema" aria-hidden="true"><?= iconoSvg('informe-estados', 22, ''); ?></span>
        <div class="guia-estados__encabezado">
            <h2 class="guia-estados__titulo" id="guia-estados-titulo">Estados del SII</h2>
            <p class="guia-estados__sub" id="guia-estados-sub">Que significa cada estado de tus documentos y que hacer en cada caso.</p>
        </div>
        <button type="button" class="guia-estados__cerrar" data-guia-estados-cerrar aria-label="Cerrar la guia de estados">
            <?= iconoSvg('cerrar', 20, ''); ?>
        </button>
    </header>

    <div class="guia-estados__filtros" role="group" aria-label="Mostrar estados">
        <span class="guia-estados__indicador" aria-hidden="true"></span>
        <button type="button" class="guia-estados__filtro" data-guia-filtro="todos" aria-pressed="true">
            Todos <span class="guia-estados__cuenta"><?= count($guiaEstados); ?></span>
        </button>
        <?php foreach ($guiaGrupos as $clave => $g): ?>
            <button type="button" class="guia-estados__filtro" data-guia-filtro="<?= $clave; ?>" aria-pressed="false">
                <span class="guia-estados__punto guia-estados__punto--<?= $g['tono']; ?>" aria-hidden="true"></span>
                <?= $guiaE($g['titulo']); ?> <span class="guia-estados__cuenta"><?= count($guiaPorGrupo[$clave]); ?></span>
            </button>
        <?php endforeach; ?>
    </div>

    <div class="guia-estados__cuerpo" tabindex="-1">
        <?php $i = 0; foreach ($guiaGrupos as $clave => $g): ?>
            <section class="guia-estados__grupo" data-guia-grupo="<?= $clave; ?>" aria-labelledby="guia-grupo-<?= $clave; ?>">
                <div class="guia-estados__grupo-cabecera">
                    <span class="guia-estados__grupo-icono guia-estados__grupo-icono--<?= $g['tono']; ?>" aria-hidden="true"><?= iconoSvg($g['icono'], 16, ''); ?></span>
                    <div>
                        <h3 class="guia-estados__grupo-titulo" id="guia-grupo-<?= $clave; ?>"><?= $guiaE($g['titulo']); ?></h3>
                        <p class="guia-estados__grupo-sub"><?= $guiaE($g['sub']); ?></p>
                    </div>
                </div>
                <ul class="guia-estados__lista">
                    <?php foreach ($guiaPorGrupo[$clave] as [, $codigo, $nombre, $explica, $hacer]): ?>
                        <?php
                            $rehacible = $codigo !== null && RehacerDocumento::permite(33, $codigo);
                            $fueraTotales = $codigo !== null && EstadoContable::esRechazado($codigo);
                        ?>
                        <li class="guia-estados__item" style="--i: <?= $i++; ?>">
                            <span class="guia-estados__codigo guia-estados__codigo--<?= $g['tono']; ?>"><?= $guiaE($codigo ?? '-'); ?></span>
                            <div class="guia-estados__texto">
                                <p class="guia-estados__nombre"><?= $guiaE($nombre); ?></p>
                                <p class="guia-estados__explica"><?= $guiaE($explica); ?></p>
                                <p class="guia-estados__hacer"><span>Que hacer:</span> <?= $guiaE($hacer); ?></p>
                                <?php if ($rehacible || $fueraTotales || $codigo !== null): ?>
                                    <div class="guia-estados__pie">
                                        <?php if ($rehacible): ?>
                                            <span class="guia-estados__etiqueta guia-estados__etiqueta--rehacer">Se puede rehacer</span>
                                        <?php endif; ?>
                                        <?php if ($fueraTotales): ?>
                                            <span class="guia-estados__etiqueta">Fuera de los totales</span>
                                        <?php endif; ?>
                                        <?php if ($codigo !== null): ?>
                                            <a class="guia-estados__ver" href="/ventas/panel-emision?estado=<?= urlencode($codigo); ?>">
                                                Ver documentos <?= iconoSvg('flecha', 14, ''); ?>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>
    </div>

    <footer class="guia-estados__nota">
        <?= iconoSvg('info', 16, ''); ?>
        <p>Los estados vienen del SII. "Ver documentos" filtra este listado por el estado, en el mes actual.</p>
    </footer>
</dialog>

<script>
(function () {
    'use strict';
    var dialogo = document.getElementById('guia-estados');
    var abrir = document.querySelector('[data-guia-estados-abrir]');
    if (!dialogo || !abrir) { return; }
    // Sin <dialog> nativo no hay modal: se esconde el boton en vez de dejarlo muerto.
    if (typeof dialogo.showModal !== 'function') { abrir.hidden = true; return; }

    var sinMovimiento = window.matchMedia('(prefers-reduced-motion: reduce)');
    var filtros = dialogo.querySelectorAll('[data-guia-filtro]');
    var grupos = dialogo.querySelectorAll('[data-guia-grupo]');
    var indicador = dialogo.querySelector('.guia-estados__indicador');
    var cuerpo = dialogo.querySelector('.guia-estados__cuerpo');

    function moverIndicador(boton) {
        indicador.style.width = boton.offsetWidth + 'px';
        indicador.style.transform = 'translateX(' + boton.offsetLeft + 'px)';
    }

    function filtrar(clave) {
        filtros.forEach(function (b) {
            var activo = b.getAttribute('data-guia-filtro') === clave;
            b.setAttribute('aria-pressed', activo ? 'true' : 'false');
            if (activo) { moverIndicador(b); b.scrollIntoView({ block: 'nearest', inline: 'nearest' }); }
        });
        var n = 0;
        grupos.forEach(function (g) {
            var ver = clave === 'todos' || g.getAttribute('data-guia-grupo') === clave;
            g.hidden = !ver;
            if (ver) {
                // Reinicia la entrada escalonada solo de lo que queda visible.
                g.querySelectorAll('.guia-estados__item').forEach(function (it) { it.style.setProperty('--i', n++); });
                g.classList.remove('guia-estados__grupo--entra');
                void g.offsetWidth;
                g.classList.add('guia-estados__grupo--entra');
            }
        });
        cuerpo.scrollTop = 0;
    }

    function cerrar() {
        if (!dialogo.open || dialogo.hasAttribute('data-cerrando')) { return; }
        if (sinMovimiento.matches) { dialogo.close(); return; }
        dialogo.setAttribute('data-cerrando', '');
        var listo = false;
        var terminar = function () {
            if (listo) { return; }
            listo = true;
            dialogo.removeAttribute('data-cerrando');
            dialogo.close();
        };
        dialogo.addEventListener('animationend', function fin(ev) {
            if (ev.target !== dialogo) { return; }
            dialogo.removeEventListener('animationend', fin);
            terminar();
        });
        setTimeout(terminar, 260); // por si la animacion no dispara
    }

    abrir.addEventListener('click', function () {
        dialogo.showModal();
        filtrar(dialogo.querySelector('[data-guia-filtro][aria-pressed="true"]').getAttribute('data-guia-filtro'));
        dialogo.querySelector('[data-guia-estados-cerrar]').focus();
    });
    filtros.forEach(function (b) {
        b.addEventListener('click', function () { filtrar(b.getAttribute('data-guia-filtro')); });
    });
    dialogo.querySelector('[data-guia-estados-cerrar]').addEventListener('click', cerrar);
    // Escape: se intercepta para que tambien cierre con animacion.
    dialogo.addEventListener('cancel', function (ev) { ev.preventDefault(); cerrar(); });
    // Clic en el fondo: el target es el propio <dialog> solo fuera de su caja.
    dialogo.addEventListener('click', function (ev) {
        if (ev.target !== dialogo) { return; }
        var r = dialogo.getBoundingClientRect();
        var dentro = ev.clientX >= r.left && ev.clientX <= r.right && ev.clientY >= r.top && ev.clientY <= r.bottom;
        if (!dentro) { cerrar(); }
    });
    window.addEventListener('resize', function () {
        if (!dialogo.open) { return; }
        var activo = dialogo.querySelector('[data-guia-filtro][aria-pressed="true"]');
        if (activo) { moverIndicador(activo); }
    });
})();
</script>
