<?php
/**
 * Importar notas de venta: paso 1, subir el archivo. Es la UNICA entrada del
 * flujo masivo (hubo tambien "Revisar archivo" y la carga con plantilla exacta;
 * esta pantalla hace lo de las dos: un archivo en la plantilla se reconoce
 * entero y pasa directo a revisar e importar).
 *
 * TAREA DE LA PANTALLA, EN UNA FRASE: subir el Excel o CSV de ventas, en la
 * plantilla o en el formato que sea, para importarlo como notas de venta.
 *
 * Recibe: $error, $adaptaciones (archivos subidos), $lotes (importaciones,
 * incluidas las de la carga vieja), $navActivo.
 *
 * SUBIR NO IMPORTA NADA. Guarda el original y propone las equivalencias; lo
 * demas pasa en la pantalla de la adaptacion, y la importacion exige una
 * autorizacion explicita. Esta pantalla lo dice antes de que el usuario elija
 * el archivo, para que no tema subirlo.
 *
 * EL HISTORIAL ES PARTE DE LA AUDITORIA: quien subio cada archivo, si se
 * importo, quien lo autorizo y cuando. Ahi se vuelve a descargar el original.
 *
 * ESPERA (seccion 14): la misma de la carga -- nada bajo 300 ms, spinner dentro
 * del boton, y pasados 10 s que se esta haciendo con barra indeterminada.
 */
$titulo = 'Importar notas de venta';
require __DIR__ . '/partials/header.php';

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$fmtFechaHora = static function ($v): string {
    $t = strtotime((string) $v);
    return $t === false ? (string) $v : date('d/m/Y H:i', $t);
};

$hayError = ! empty($error);
$maxBytes = CARGA_ADAPTACION_MAX_BYTES;

?>

<div class="dash-header">
    <div>
        <h1>Importar notas de venta</h1>
    </div>
</div>
<p class="dash-subtitulo">
    Sube el Excel o CSV con tus ventas, en la plantilla o tal como te lo mando tu cliente. El sistema lo ordena, te pregunta lo que no sepa y lo importa cuando tu lo apruebas.
</p>

<div class="config-ancho">

<div class="alerta alerta--error" id="resumen-errores" role="alert" tabindex="-1"<?= $hayError ? '' : ' hidden'; ?>>
    <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
    <div class="alerta__cuerpo">
        <p class="alerta__titulo">No se pudo leer el archivo</p>
        <ul class="alerta__lista">
            <li><a href="#archivo">Archivo</a>: <span data-resumen-texto><?= $e((string) ($error ?? '')); ?></span></li>
        </ul>
    </div>
</div>

<div class="config-columnas config-columnas--2">
    <section class="tarjeta" aria-labelledby="titulo-archivo">
        <h2 id="titulo-archivo">Archivo del cliente</h2>
        <p class="tarjeta__intro">
            Excel (.xlsx o .xls) o .csv, con las columnas en cualquier orden y con los nombres que tenga.
        </p>

        <form method="post" action="/ventas/carga-masiva/adaptar" enctype="multipart/form-data"
              class="form-compacto" id="form-carga" novalidate>
            <?= csrfInput(); ?>

            <div class="form-campo<?= $hayError ? ' form-campo--error' : ''; ?>">
                <label for="archivo">Archivo</label>
                <div class="upload">
                    <div class="upload__area" data-zona-archivo>
                        <?= iconoSvg('carga-masiva', 32, 'upload__icono'); ?>
                        <p class="upload__texto">Arrastra aqui el archivo con las ventas, o eligelo desde tu equipo</p>
                        <input class="visualmente-oculto" type="file" id="archivo" name="archivo"
                               accept=".xlsx,.xls,.csv" required
                               aria-describedby="ayuda-archivo error-archivo"<?= $hayError ? ' aria-invalid="true"' : ''; ?>
                               data-max-bytes="<?= $maxBytes; ?>">
                        <label class="boton-secundario upload__boton" for="archivo">Elegir archivo</label>
                        <p class="upload__meta" id="ayuda-archivo">
                            .xlsx, .xls o .csv. Peso maximo 16 MB y hasta <?= number_format(NOTA_VENTA_MAX_FILAS, 0, ',', '.'); ?> filas de datos.
                        </p>
                        <p class="upload__seleccionado" id="archivo-seleccionado" aria-live="polite"></p>
                    </div>
                </div>
                <p class="campo-error" id="error-archivo"<?= $hayError ? '' : ' hidden'; ?>>
                    <?= iconoSvg('alerta', 16, ''); ?><span><?= $e((string) ($error ?? '')); ?></span>
                </p>
            </div>

            <div class="acciones-formulario">
                <button class="boton-principal" type="submit" id="boton-cargar">Analizar archivo</button>
            </div>
            <div class="espera-larga" id="espera-carga" role="status" hidden>
                <div class="progreso-indeterminado" role="progressbar" aria-label="Analizando el archivo"></div>
                <p></p>
            </div>
        </form>
    </section>

    <div>
        <div class="panel-info">
            <p class="panel-info__titulo">
                <?= iconoSvg('info', 20, 'panel-info__icono'); ?>Como funciona
            </p>
            <ol class="panel-info__lista">
                <li><strong>Subes el archivo</strong>, con las columnas que tenga y en el orden que venga.</li>
                <li><strong>Respondes lo que falte</strong>: por ejemplo, la fecha de las ventas o que significa una columna "Precio".</li>
                <li><strong>Revisas y apruebas.</strong> Recien ahi se crean las notas de venta.</li>
            </ol>
            <p class="nota">Nunca se cambian montos, RUT ni productos: solo se ordena el formato. Subir el archivo no crea nada.</p>
        </div>
        <div class="panel-info">
            <p class="panel-info__titulo">
                <?= iconoSvg('flecha', 20, 'panel-info__icono'); ?>¿Armas tu el archivo?
            </p>
            <p>Parte por la plantilla: con ella el sistema reconoce todas las columnas y no tiene nada que preguntarte.</p>
            <p><a class="boton-secundario" href="/ventas/carga-masiva/plantilla">Descargar plantilla Excel</a></p>
        </div>
    </div>
</div>

<?php
    /* EL HISTORIAL, EN EL ORDEN EN QUE SE NECESITA: lo que quedo a medias
       arriba (es lo unico que pide accion), despues todas las importaciones
       -- cada una es un lote, incluidas las de la carga vieja --, y lo
       descartado plegado: no pide nada, pero el original sigue ahi. */
    $sinTerminar = array_values(array_filter($adaptaciones, static fn (array $a): bool => $a['estado'] === 'borrador'));
    $descartadas = array_values(array_filter($adaptaciones, static fn (array $a): bool => $a['estado'] === 'descartada'));
    $fmtNum = static fn ($v): string => number_format((float) $v, 0, ',', '.');
?>

<?php if ($sinTerminar !== []): ?>
    <section class="tarjeta" aria-labelledby="titulo-sin-terminar">
        <h2 id="titulo-sin-terminar">Archivos sin terminar</h2>
        <ul class="panel-info__lista">
            <?php foreach ($sinTerminar as $a): ?>
                <li>
                    <a href="/ventas/carga-masiva/adaptar/<?= (int) $a['id']; ?>"><?= $e((string) $a['nombre_archivo']); ?></a>
                    <span class="tabla-datos__secundario">Subido el <?= $e($fmtFechaHora($a['created_at'])); ?> por <?= $e((string) $a['subido_por']); ?>. Falta revisarlo e importarlo.</span>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<section class="tarjeta" aria-labelledby="titulo-lotes">
    <h2 id="titulo-lotes">Importaciones</h2>
    <?php if ($lotes === []): ?>
        <p class="tarjeta__intro">Todavia no has importado ningun archivo.</p>
    <?php else: ?>
        <div class="tabla-scroll">
            <table class="tabla-datos tabla-datos--cards">
                <caption class="visualmente-oculto">Archivos importados, del mas nuevo al mas antiguo, con sus filas validas y con error.</caption>
                <thead>
                    <tr>
                        <th scope="col">Fecha</th>
                        <th scope="col">Archivo</th>
                        <th scope="col" class="tabla-datos__num">Validas</th>
                        <th scope="col" class="tabla-datos__num">Con error</th>
                        <th scope="col" class="tabla-datos__acciones"><span class="visualmente-oculto">Acciones</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lotes as $l): ?>
                        <tr>
                            <td data-label="Fecha"><?= $e($fmtFechaHora($l['created_at'])); ?></td>
                            <th scope="row" data-label="Archivo"><?= $e((string) $l['nombre_archivo']); ?></th>
                            <td data-label="Validas" class="tabla-datos__num"><?= $fmtNum($l['filas_validas']); ?></td>
                            <td data-label="Con error" class="tabla-datos__num">
                                <?php if ((int) $l['filas_error'] > 0): ?>
                                    <span class="badge badge--advertencia"><?= iconoSvg('alerta', 16, 'badge__icono'); ?><?= $fmtNum($l['filas_error']); ?></span>
                                <?php else: ?>
                                    0
                                <?php endif; ?>
                            </td>
                            <td class="tabla-datos__acciones">
                                <a href="/ventas/carga-masiva/<?= (int) $l['id']; ?>"
                                   aria-label="Ver detalle de <?= $e((string) $l['nombre_archivo']); ?>">Ver detalle</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if ($descartadas !== []): ?>
    <details class="tarjeta">
        <summary>Archivos descartados (<?= count($descartadas); ?>)</summary>
        <p class="nota">No se importaron. El archivo original sigue guardado.</p>
        <ul class="panel-info__lista">
            <?php foreach ($descartadas as $a): ?>
                <li>
                    <a href="/ventas/carga-masiva/adaptar/<?= (int) $a['id']; ?>"><?= $e((string) $a['nombre_archivo']); ?></a>
                    <span class="tabla-datos__secundario">Subido el <?= $e($fmtFechaHora($a['created_at'])); ?> por <?= $e((string) $a['subido_por']); ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </details>
<?php endif; ?>

</div>

<script>
/* Archivo: tipo y peso revisados antes de subir, y espera del envio (seccion
   14). Mismo comportamiento que la carga masiva. */
(function () {
    var form = document.getElementById('form-carga');
    if (!form) { return; }
    var input = document.getElementById('archivo');
    var zona = form.querySelector('[data-zona-archivo]');
    var seleccionado = document.getElementById('archivo-seleccionado');
    var resumen = document.getElementById('resumen-errores');
    var boton = document.getElementById('boton-cargar');
    var espera = document.getElementById('espera-carga');

    function regla() {
        var f = input.files && input.files[0];
        if (!f) { return 'Elige el archivo con las ventas: Excel (.xlsx o .xls) o .csv.'; }
        var n = f.name.toLowerCase();
        if (!/\.(xlsx|xls|csv)$/.test(n)) { return 'Ese tipo de archivo no se puede adaptar. Usa Excel (.xlsx o .xls) o .csv.'; }
        if (f.size > Number(input.getAttribute('data-max-bytes'))) { return 'El archivo pesa mas de 16 MB. Dividelo en archivos mas chicos.'; }
        return '';
    }
    function pintar(msg) {
        var err = document.getElementById('error-archivo');
        err.querySelector('span').textContent = msg;
        err.hidden = msg === '';
        input.closest('.form-campo').classList.toggle('form-campo--error', msg !== '');
        if (msg === '') { input.removeAttribute('aria-invalid'); resumen.hidden = true; } else { input.setAttribute('aria-invalid', 'true'); }
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

    var enviando = false, umbral = null, largo = null;
    function ocupado(si) {
        if (si) {
            boton.setAttribute('data-cargando', 'true');
            boton.setAttribute('aria-busy', 'true');
            form.setAttribute('aria-busy', 'true');
        } else {
            boton.removeAttribute('data-cargando');
            boton.removeAttribute('aria-busy');
            form.removeAttribute('aria-busy');
            espera.hidden = true;
            espera.querySelector('p').textContent = '';
        }
        boton.disabled = si;
    }
    window.addEventListener('pageshow', function (ev) {
        if (ev.persisted) { clearTimeout(umbral); clearTimeout(largo); enviando = false; ocupado(false); }
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
        largo = setTimeout(function () {
            espera.hidden = false;
            espera.querySelector('p').textContent = 'Estamos buscando los encabezados y leyendo las filas del archivo. Con muchas filas puede tardar hasta un minuto: no cierres esta pagina.';
        }, 10000);
    });

    if (input.getAttribute('aria-invalid') === 'true') { input.focus(); }
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
