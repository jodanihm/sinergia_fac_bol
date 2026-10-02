<?php
/**
 * Configuracion > Produccion > Folios y CAF (ambiente de PRODUCCION).
 *
 * Gemela de caf.php: mismas variables ($error, $flash, $cafs), mismo handler
 * parametrizado (procesarCafGet/Post), misma tabla, mismo resumen por tipo,
 * mismo formulario y mismo JS. Cambian la ruta del action, el badge, los
 * textos, y el aviso de folios reales, que aqui va ARRIBA de todo: es lo que
 * hay que saber antes de subir nada.
 *
 * A diferencia de certificacion, esta vista NO menciona el proceso aparte de la
 * boleta: esa aclaracion es propia de la certificacion.
 *
 * Igual que en certificacion: los numeros se muestran tal como llegan, el ENUM
 * de estado es 'activo'/'agotado', "Cargado/Falta" no es el estado del CAF, la
 * carga es de dos pasos (subir -> revisar -> confirmar) y el resultado llega
 * como flash, y la nota de migracion aparece solo cuando
 * proximo_folio_inicial es mayor que folio_desde. Ancho, errores y espera:
 * ver el comentario de caf.php.
 */
$titulo = 'CAF de produccion';
require __DIR__ . '/partials/header.php';

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

/** Badge del estado del CAF. ENUM cerrado; el default cubre un valor inesperado. */
$badgeEstado = static function (string $estado): array {
    return match ($estado) {
        'activo'  => ['badge--ok', 'Activo', 'check'],
        'agotado' => ['badge--neutro', 'Agotado', 'sin-cargar'],
        default   => ['badge--neutro', $estado, 'sin-cargar'],
    };
};

/* Separador de miles SOLO para la cantidad de folios restantes. El rango y el
   proximo folio son identificadores, no cantidades, y se muestran crudos igual
   que el folio en el panel de emision. */
$fmtNum = static function ($v): string {
    return number_format((float) $v, 0, ',', '.');
};

// Tipos que ya tienen al menos un CAF en la lista.
$tiposConCaf = [];
foreach ($cafs as $c) {
    $tiposConCaf[(int) $c['tipo_dte']] = true;
}
$hayError = ! empty($error);
?>

<div class="dash-header">
    <div>
        <h1>Folios y CAF <span class="badge badge--advertencia">Produccion</span></h1>
    </div>
</div>
<p class="dash-subtitulo">
    Sube un CAF por cada tipo de documento y revisa cuantos folios reales te quedan para emitir.
</p>

<div class="config-ancho">

<?php if (! empty($flash['mensaje'])): ?>
    <p class="alerta alerta--<?= ($flash['tipo'] ?? '') === 'exito' ? 'exito' : 'error'; ?>" role="status">
        <?= iconoSvg(($flash['tipo'] ?? '') === 'exito' ? 'check' : 'alerta', 20, 'alerta__icono'); ?>
        <span><?= $e((string) $flash['mensaje']); ?></span>
    </p>
<?php endif; ?>

<div class="alerta alerta--advertencia">
    <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
    <div class="alerta__cuerpo">
        <p class="alerta__titulo">Folios reales</p>
        <p>Cada documento timbrado con estos folios es un documento tributario real ante el SII, no de prueba.
        No subas aqui un CAF de certificacion, salvo que estes probando el mecanismo a proposito.</p>
    </div>
</div>

<div class="alerta alerta--error" id="resumen-errores" role="alert" tabindex="-1"<?= $hayError ? '' : ' hidden'; ?>>
    <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
    <div class="alerta__cuerpo">
        <p class="alerta__titulo">No se pudo subir el CAF</p>
        <ul class="alerta__lista">
            <li><a href="#caf">Archivo CAF</a>: <span data-resumen-texto><?= $e((string) ($error ?? '')); ?></span></li>
        </ul>
    </div>
</div>

<div class="config-columnas config-columnas--2">
    <section class="tarjeta" aria-labelledby="titulo-subir">
        <h2 id="titulo-subir">Subir un CAF de produccion</h2>
        <p class="tarjeta__intro">
            El tipo de documento y el rango de folios se leen directo del archivo: no hace falta escribirlos.
            Antes de guardarlo vas a poder revisarlo.
        </p>
        <form method="post" action="/caf-produccion" enctype="multipart/form-data" class="form-compacto" id="form-caf" novalidate>
            <?= csrfInput(); ?>
            <div class="form-campo<?= $hayError ? ' form-campo--error' : ''; ?>">
                <label for="caf">Archivo CAF</label>
                <div class="upload">
                    <div class="upload__area" data-zona-archivo>
                        <?= iconoSvg('carga-masiva', 32, 'upload__icono'); ?>
                        <p class="upload__texto">Arrastra aqui el archivo .xml que descargaste del SII, o eligelo desde tu equipo</p>
                        <input class="visualmente-oculto" type="file" name="caf" id="caf" accept=".xml" required
                               aria-describedby="ayuda-caf error-caf"<?= $hayError ? ' aria-invalid="true"' : ''; ?>
                               data-extensiones=".xml" data-max-bytes="33554432">
                        <label class="boton-secundario upload__boton" for="caf">Elegir archivo</label>
                        <p class="upload__meta" id="ayuda-caf">Solo .xml, descargado del SII para produccion. Peso maximo 32 MB.</p>
                        <p class="upload__seleccionado" id="caf-seleccionado" aria-live="polite"></p>
                    </div>
                </div>
                <p class="campo-error" id="error-caf"<?= $hayError ? '' : ' hidden'; ?>>
                    <?= iconoSvg('alerta', 16, ''); ?><span><?= $e((string) ($error ?? '')); ?></span>
                </p>
            </div>
            <ul class="lista-ayuda">
                <li>Cada CAF autoriza un solo tipo de documento y un rango de folios: necesitas uno por tipo.</li>
                <li>Estos folios son independientes de los de certificacion.</li>
            </ul>
            <div class="acciones-formulario">
                <button type="submit" class="boton-principal" id="boton-enviar">Subir CAF</button>
            </div>
        </form>
    </section>

    <section class="tarjeta" aria-labelledby="titulo-tipos">
        <h2 id="titulo-tipos">Tipos de documento</h2>
        <p class="tarjeta__intro">"Cargado" significa que existe al menos un CAF de ese tipo, no que queden folios.</p>
        <ul class="validacion">
            <?php foreach (catalogoTiposDte() as $tipo => $nombre): ?>
                <li class="validacion__item">
                    <span><?= $e($nombre); ?> (<?= (int) $tipo; ?>)</span>
                    <?php if (isset($tiposConCaf[$tipo])): ?>
                        <span class="badge badge--ok"><?= iconoSvg('check', 16, 'badge__icono'); ?>Cargado</span>
                    <?php else: ?>
                        <span class="badge badge--advertencia"><?= iconoSvg('alerta', 16, 'badge__icono'); ?>Falta</span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
</div>

<section class="tarjeta" aria-labelledby="titulo-cafs">
    <h2 id="titulo-cafs">CAF cargados</h2>
    <?php if ($cafs === []): ?>
        <div class="estado-vacio estado-vacio--centrado">
            <?= iconoSvg('caf', 32, 'estado-vacio__icono'); ?>
            <h3>Todavia no tienes CAF de produccion</h3>
            <p>Sin un CAF no se puede emitir: cada uno trae los folios autorizados para un tipo de documento.</p>
            <p class="estado-vacio__acciones">
                <label class="boton-secundario" for="caf">Elegir el primer CAF</label>
            </p>
        </div>
    <?php else: ?>
        <div class="tabla-scroll">
            <table class="tabla-datos tabla-datos--cards">
                <caption><?= count($cafs); ?> CAF cargado<?= count($cafs) === 1 ? '' : 's'; ?></caption>
                <thead>
                    <tr>
                        <th scope="col">Tipo</th>
                        <th scope="col" class="tabla-datos__num">Rango</th>
                        <th scope="col" class="tabla-datos__num">Proximo folio</th>
                        <th scope="col" class="tabla-datos__num">Folios restantes</th>
                        <th scope="col" class="tabla-datos__estado">Estado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cafs as $c): ?>
                        <?php [$claseBadge, $textoBadge, $iconoBadge] = $badgeEstado((string) $c['estado']); ?>
                        <?php $folioInicial = (int) $c['proximo_folio_inicial']; ?>
                        <tr>
                            <td data-label="Tipo"><span class="badge badge--etiqueta"><?= $e(nombreTipoDte((int) $c['tipo_dte'])); ?></span></td>
                            <td data-label="Rango" class="tabla-datos__num">
                                <span>
                                    <?= (int) $c['folio_desde']; ?>&ndash;<?= (int) $c['folio_hasta']; ?>
                                    <?php if ($folioInicial > (int) $c['folio_desde']): ?>
                                        <span class="tabla-datos__secundario">Migrado: Sinergia emite desde el <?= $folioInicial; ?></span>
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td data-label="Proximo folio" class="tabla-datos__num"><?= (int) $c['proximo_folio']; ?></td>
                            <td data-label="Folios restantes" class="tabla-datos__num"><?= $fmtNum($c['folios_restantes']); ?></td>
                            <td data-label="Estado" class="tabla-datos__estado"><span class="badge <?= $claseBadge; ?>"><?= iconoSvg($iconoBadge, 16, 'badge__icono'); ?><?= $e($textoBadge); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

</div>

<script>
/* Zona de arrastre, tipo y peso revisados antes de subir, y espera del envio
   (seccion 14). El servidor sigue validando el contenido del CAF. */
(function () {
    var form = document.getElementById('form-caf');
    if (!form) { return; }
    var input = document.getElementById('caf');
    var zona = form.querySelector('[data-zona-archivo]');
    var seleccionado = document.getElementById('caf-seleccionado');
    var resumen = document.getElementById('resumen-errores');
    var boton = document.getElementById('boton-enviar');

    function regla() {
        var f = input.files && input.files[0];
        if (!f) { return 'Elige el archivo .xml del CAF que descargaste del SII.'; }
        if (f.name.toLowerCase().slice(-4) !== '.xml') { return 'Ese archivo no es .xml. El CAF que entrega el SII termina en .xml.'; }
        if (f.size > Number(input.getAttribute('data-max-bytes'))) { return 'El archivo pesa mas de 32 MB. Un CAF pesa unos pocos KB: revisa que sea el correcto.'; }
        return '';
    }
    function pintar(msg) {
        var err = document.getElementById('error-caf');
        err.querySelector('span').textContent = msg;
        err.hidden = msg === '';
        input.closest('.form-campo').classList.toggle('form-campo--error', msg !== '');
        if (msg === '') { input.removeAttribute('aria-invalid'); } else { input.setAttribute('aria-invalid', 'true'); }
    }
    function alElegir() {
        var f = input.files && input.files[0];
        seleccionado.textContent = f ? 'Elegido: ' + f.name : '';
        pintar(f ? regla() : '');
    }
    input.addEventListener('change', alElegir);
    ['dragenter', 'dragover'].forEach(function (t) {
        zona.addEventListener(t, function (ev) { ev.preventDefault(); zona.setAttribute('data-arrastrando', 'true'); });
    });
    ['dragleave', 'drop'].forEach(function (t) {
        zona.addEventListener(t, function () { zona.removeAttribute('data-arrastrando'); });
    });
    zona.addEventListener('drop', function (ev) {
        ev.preventDefault();
        if (ev.dataTransfer && ev.dataTransfer.files.length) { input.files = ev.dataTransfer.files; alElegir(); }
    });

    /* ESPERA AL ENVIAR -- seccion 14. */
    var enviando = false;
    var umbral = null;
    function ocupado(si) {
        if (si) {
            boton.setAttribute('data-cargando', 'true');
            boton.setAttribute('aria-busy', 'true');
            form.setAttribute('aria-busy', 'true');
            boton.disabled = true;
        } else {
            boton.removeAttribute('data-cargando');
            boton.removeAttribute('aria-busy');
            form.removeAttribute('aria-busy');
            boton.disabled = false;
        }
    }
    window.addEventListener('pageshow', function (ev) {
        if (ev.persisted) { clearTimeout(umbral); enviando = false; ocupado(false); }
    });
    form.addEventListener('submit', function (ev) {
        if (enviando) { ev.preventDefault(); return; }
        var msg = regla();
        pintar(msg);
        if (msg !== '') {
            ev.preventDefault();
            resumen.querySelector('[data-resumen-texto]').textContent = msg;
            resumen.hidden = false;
            input.focus();
            return;
        }
        enviando = true;
        umbral = setTimeout(function () { ocupado(true); }, 300);
    });

    if (input.getAttribute('aria-invalid') === 'true') { input.focus(); }
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
