<?php
/**
 * Revisar un archivo de carga masiva SIN cargarlo.
 *
 * TAREA DE LA PANTALLA, EN UNA FRASE: saber si el Excel pasaria la carga antes
 * de cargarlo, para no dejar un lote con filas malas ni un archivo rechazado.
 *
 * Recibe: $error (no se pudo ni leer el archivo), $revision (null hasta que se
 * sube uno; despues, lo que armo handleCargaMasivaRevisarPost()), $navActivo.
 *
 * LA REVISION ES LA MISMA QUE LA DE LA CARGA (revisarArchivoCargaMasiva()):
 * esta pantalla no tiene reglas propias. Lo que cambia es que muestra TODOS los
 * problemas de archivo juntos -- la carga se detiene en el primero -- y que no
 * escribe nada: ni lote, ni notas, ni clientes nuevos.
 *
 * SIN STEPPER a proposito: el stepper cuenta los tres pasos de lo que SI queda
 * guardado (cargar, revisar la carga, facturar). Esta revision es previa y
 * opcional; meterla como paso 0 diria que es obligatoria.
 *
 * EL RESULTADO VA ARRIBA DEL FORMULARIO: despues de subir, lo que el usuario
 * vino a buscar es el veredicto. El formulario queda debajo para revisar otra
 * version del archivo.
 *
 * ESPERA (seccion 14): el mismo comportamiento que la carga -- nada bajo
 * 300 ms, spinner dentro del boton, y pasados 10 s que se esta haciendo con
 * barra indeterminada (no hay porcentaje real).
 */
$titulo = 'Revisar archivo de carga masiva';
require __DIR__ . '/partials/header.php';

$e      = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$fmtNum = static fn ($v): string => number_format((float) $v, 0, ',', '.');

$hayError = ! empty($error);
$maxFilas = $fmtNum(NOTA_VENTA_MAX_FILAS);
$maxBytes = 32 * 1024 * 1024;

$fraseExentoOn  = 'Se revisa como archivo de facturas exentas (34): todas sus lineas tienen que traer exento = SI, y no pueden anular boletas.';
$fraseExentoOff = 'Se revisa como archivo de facturas afectas (33), igual que siempre. Marcalo igual que lo vas a marcar al cargar.';

// EL VEREDICTO, en tres niveles y en este orden de gravedad:
//   rechazado  -> un problema de archivo: la carga no guardaria NADA.
//   con filas  -> la carga entra, pero algunas filas quedan apartadas con error.
//   listo      -> todo pasa.
$veredicto = null;
if ($revision !== null) {
    if ($revision['problemas'] !== []) {
        $veredicto = 'rechazado';
    } elseif ($revision['filasError'] !== []) {
        $veredicto = 'con-filas';
    } else {
        $veredicto = 'listo';
    }
}
$nErrores = $revision !== null ? count($revision['filasError']) : 0;
?>

<div class="dash-header">
    <div>
        <h1>Revisar archivo</h1>
    </div>
</div>
<p class="dash-subtitulo">
    Sube el Excel de notas de venta para saber si cumple los requisitos. Solo se revisa: no se carga ninguna nota ni se crea ningun cliente.
</p>

<div class="config-ancho">

<?php if ($revision !== null): ?>
    <section aria-labelledby="titulo-resultado" id="resultado" tabindex="-1">
        <h2 id="titulo-resultado" class="visualmente-oculto">Resultado de la revision</h2>

        <?php if ($veredicto === 'rechazado'): ?>
            <div class="alerta alerta--error" role="alert">
                <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
                <div class="alerta__cuerpo">
                    <p class="alerta__titulo">La carga rechazaria este archivo completo</p>
                    <p>Con estos problemas no se guardaria ninguna nota. Corrigelos en el Excel y vuelve a revisarlo.</p>
                    <ul class="alerta__lista">
                        <?php foreach ($revision['problemas'] as $p): ?>
                            <li><?= $e($p); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php elseif ($veredicto === 'con-filas'): ?>
            <div class="alerta alerta--advertencia" role="status">
                <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
                <div class="alerta__cuerpo">
                    <p class="alerta__titulo">
                        El archivo se puede cargar, pero <?= $nErrores === 1 ? '1 fila quedaria' : $fmtNum($nErrores) . ' filas quedarian'; ?> con error
                    </p>
                    <p>
                        Las filas con error no pasan a facturacion. Si las corriges antes, el lote queda limpio.
                        Abajo esta el detalle de cada una.
                    </p>
                </div>
            </div>
        <?php else: ?>
            <div class="alerta alerta--exito" role="status">
                <?= iconoSvg('check', 20, 'alerta__icono'); ?>
                <div class="alerta__cuerpo">
                    <p class="alerta__titulo">El archivo esta listo para cargar</p>
                    <p>Todas las filas cumplen los requisitos. Sube este mismo archivo en la carga de notas de venta.</p>
                </div>
            </div>
        <?php endif; ?>

        <?php
        /* LOS NUMEROS DE "LO QUE SALDRIA" SOLO CUANDO ALGO SALDRIA. Con el
           archivo rechazado la carga no guarda nada, y decir "3 pasarian a
           facturacion" contradiria el veredicto de arriba. */
        ?>
        <?php if ($veredicto !== 'rechazado'): ?>
        <section class="dash-grid" aria-label="Resumen del archivo revisado">
            <article class="kpi">
                <h3 class="kpi__etiqueta">Filas leidas</h3>
                <p class="kpi__valor"><?= $fmtNum($revision['totalFilas']); ?></p>
                <p class="kpi__formula"><?= $e($revision['nombreArchivo']); ?></p>
            </article>
            <article class="kpi">
                <h3 class="kpi__etiqueta">Validas</h3>
                <p class="kpi__valor"><?= $fmtNum($revision['filasValidas']); ?></p>
                <p class="kpi__formula">Pasarian a facturacion</p>
            </article>
            <article class="kpi<?= $nErrores > 0 ? ' kpi--rojo' : ''; ?>">
                <h3 class="kpi__etiqueta">Con error</h3>
                <p class="kpi__valor"><?= $fmtNum($nErrores); ?></p>
                <p class="kpi__formula">Quedarian apartadas</p>
            </article>
            <article class="kpi">
                <h3 class="kpi__etiqueta">Facturas que saldrian</h3>
                <p class="kpi__valor"><?= $fmtNum($revision['facturas']); ?></p>
                <p class="kpi__formula">
                    <?= $revision['esExento'] ? 'Exentas (34)' : 'Afectas (33)'; ?>.
                    <?php if ($revision['notasCredito'] > 0): ?>
                        Mas <?= $fmtNum($revision['notasCredito']); ?> nota(s) de credito por boletas a anular.
                    <?php else: ?>
                        Las filas de un mismo cliente se juntan.
                    <?php endif; ?>
                </p>
            </article>
            <article class="kpi">
                <h3 class="kpi__etiqueta">Clientes nuevos</h3>
                <p class="kpi__valor"><?= $fmtNum($revision['clientesNuevos']); ?></p>
                <p class="kpi__formula">Se crearian al cargar</p>
            </article>
            <article class="kpi">
                <h3 class="kpi__etiqueta">Monto estimado</h3>
                <p class="kpi__valor">$<?= $fmtNum($revision['montoEstimado']); ?></p>
                <p class="kpi__formula">IVA incluido, filas validas. No es el monto emitido.</p>
            </article>
        </section>
        <?php endif; ?>

        <?php if ($revision['filasError'] !== []): ?>
            <section class="tarjeta" aria-labelledby="titulo-filas-error">
                <h2 id="titulo-filas-error">Filas con error</h2>
                <p class="tarjeta__intro">El numero de fila es el del Excel, contando el encabezado como fila 1.</p>
                <div class="tabla-scroll">
                    <table class="tabla-datos tabla-datos--cards">
                        <caption class="visualmente-oculto">Filas del archivo que no cumplen los requisitos y por que.</caption>
                        <thead>
                            <tr>
                                <th scope="col">Fila</th>
                                <th scope="col">Identificador</th>
                                <th scope="col">RUT receptor</th>
                                <th scope="col">Que corregir</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($revision['filasError'] as $f): ?>
                                <tr>
                                    <th scope="row" data-label="Fila"><?= (int) $f['fila']; ?></th>
                                    <td data-label="Identificador"><?= $f['externo'] !== '' ? $e($f['externo']) : '-'; ?></td>
                                    <td data-label="RUT receptor"><?= $f['rut'] !== '' ? $e($f['rut']) : '-'; ?></td>
                                    <td data-label="Que corregir">
                                        <ul class="alerta__lista">
                                            <?php foreach ($f['errores'] as $msg): ?>
                                                <li><?= $e((string) $msg); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($veredicto !== 'rechazado'): ?>
            <div class="acciones-formulario">
                <a class="boton-principal" href="/ventas/carga-masiva">Ir a cargar el archivo</a>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<div class="alerta alerta--error" id="resumen-errores" role="alert" tabindex="-1"<?= $hayError ? '' : ' hidden'; ?>>
    <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
    <div class="alerta__cuerpo">
        <p class="alerta__titulo">No se pudo revisar el archivo</p>
        <ul class="alerta__lista">
            <li><a href="#archivo">Archivo Excel</a>: <span data-resumen-texto><?= $e((string) ($error ?? '')); ?></span></li>
        </ul>
    </div>
</div>

<div class="config-columnas config-columnas--2">
    <section class="tarjeta" aria-labelledby="titulo-archivo">
        <h2 id="titulo-archivo"><?= $revision !== null ? 'Revisar otro archivo' : 'Archivo Excel'; ?></h2>
        <p class="tarjeta__intro">
            Se revisa con las mismas reglas que la carga, contra tus clientes y los lotes ya cargados.
        </p>

        <p class="carga-masiva__plantilla">
            <a class="boton-secundario" href="/ventas/carga-masiva/plantilla">Descargar plantilla Excel</a>
        </p>

        <form method="post" action="/ventas/carga-masiva/revisar" enctype="multipart/form-data"
              class="form-compacto" id="form-carga" novalidate>
            <?= csrfInput(); ?>

            <div class="form-campo<?= $hayError ? ' form-campo--error' : ''; ?>">
                <label for="archivo">Archivo con las notas</label>
                <div class="upload">
                    <div class="upload__area" data-zona-archivo>
                        <?= iconoSvg('carga-masiva', 32, 'upload__icono'); ?>
                        <p class="upload__texto">Arrastra aqui el Excel con las notas de venta, o eligelo desde tu equipo</p>
                        <input class="visualmente-oculto" type="file" id="archivo" name="archivo"
                               accept=".xlsx" required
                               aria-describedby="ayuda-archivo error-archivo"<?= $hayError ? ' aria-invalid="true"' : ''; ?>
                               data-max-bytes="<?= $maxBytes; ?>">
                        <label class="boton-secundario upload__boton" for="archivo">Elegir archivo</label>
                        <p class="upload__meta" id="ayuda-archivo">
                            Solo .xlsx. Peso maximo 32 MB y hasta <?= $maxFilas; ?> filas de datos.
                        </p>
                        <p class="upload__seleccionado" id="archivo-seleccionado" aria-live="polite"></p>
                    </div>
                </div>
                <p class="campo-error" id="error-archivo"<?= $hayError ? '' : ' hidden'; ?>>
                    <?= iconoSvg('alerta', 16, ''); ?><span><?= $e((string) ($error ?? '')); ?></span>
                </p>
            </div>

            <div class="carga-masiva__tipo">
                <label class="interruptor" for="tipo_exento">
                    <input type="checkbox" role="switch" name="tipo_exento" id="tipo_exento" value="1"
                           aria-describedby="ayuda-exento"<?= ($revision['esExento'] ?? false) ? ' checked' : ''; ?>>
                    <span class="interruptor__texto">
                        <span class="interruptor__titulo">Archivo de facturas exentas</span>
                        <span class="interruptor__detalle" id="ayuda-exento"
                              data-on="<?= $e($fraseExentoOn); ?>" data-off="<?= $e($fraseExentoOff); ?>"><?= $e($fraseExentoOff); ?></span>
                    </span>
                </label>
            </div>

            <div class="acciones-formulario">
                <button class="boton-principal" type="submit" id="boton-cargar">Revisar archivo</button>
            </div>
            <div class="espera-larga" id="espera-carga" role="status" hidden>
                <div class="progreso-indeterminado" role="progressbar" aria-label="Revisando el archivo"></div>
                <p></p>
            </div>
        </form>
    </section>

    <div>
        <div class="panel-info">
            <p class="panel-info__titulo">
                <?= iconoSvg('info', 20, 'panel-info__icono'); ?>Que se revisa
            </p>
            <ul class="panel-info__lista">
                <li>Que los encabezados coincidan con la plantilla.</li>
                <li>Cada fila: identificador sin repetir, RUT valido, fecha, producto, cantidad, precio y datos del cliente.</li>
                <li>El archivo completo: forma de pago, vencimiento y, si lo marcas exento, que todas las lineas lo sean.</li>
                <li>Que ninguna nota se haya cargado antes en otro lote.</li>
            </ul>
        </div>

        <div class="panel-info">
            <p class="panel-info__titulo">
                <?= iconoSvg('flecha', 20, 'panel-info__icono'); ?>Despues de revisar
            </p>
            <p>
                Revisar no guarda nada. Cuando el archivo este bien, subelo en la carga de notas de venta,
                que lo vuelve a revisar al guardarlo.
            </p>
            <p><a href="/ventas/carga-masiva">Ir a la carga de notas de venta</a></p>
        </div>
    </div>
</div>

</div>

<script>
/* Zona de arrastre, tipo y peso revisados antes de subir, interruptor con su
   frase y espera del envio (seccion 14). El servidor sigue validando el
   contenido del archivo: esto solo adelanta lo que se puede saber sin leerlo. */
(function () {
    var form = document.getElementById('form-carga');
    if (!form) { return; }
    var input = document.getElementById('archivo');
    var zona = form.querySelector('[data-zona-archivo]');
    var seleccionado = document.getElementById('archivo-seleccionado');
    var resumen = document.getElementById('resumen-errores');
    var boton = document.getElementById('boton-cargar');
    var espera = document.getElementById('espera-carga');

    /* Interruptor: la frase dice que va a pasar con TODO el archivo. */
    var sw = document.getElementById('tipo_exento');
    var detalle = document.getElementById('ayuda-exento');
    function frase() { detalle.textContent = detalle.getAttribute(sw.checked ? 'data-on' : 'data-off'); }
    sw.addEventListener('change', frase);
    frase();

    function regla() {
        var f = input.files && input.files[0];
        if (!f) { return 'Elige el archivo .xlsx con las notas. Si no lo tienes, parte por la plantilla.'; }
        if (f.name.toLowerCase().slice(-5) !== '.xlsx') { return 'Ese archivo no es .xlsx. Guardalo desde Excel como "Libro de Excel (.xlsx)" y vuelve a elegirlo.'; }
        if (f.size > Number(input.getAttribute('data-max-bytes'))) { return 'El archivo pesa mas de 32 MB. Divide las notas en dos archivos y cargalos por separado.'; }
        return '';
    }
    function pintar(msg) {
        var err = document.getElementById('error-archivo');
        err.querySelector('span').textContent = msg;
        err.hidden = msg === '';
        input.closest('.form-campo').classList.toggle('form-campo--error', msg !== '');
        if (msg === '') { input.removeAttribute('aria-invalid'); } else { input.setAttribute('aria-invalid', 'true'); }
        // Corregido el unico campo del formulario, el resumen de arriba ya no aplica.
        if (msg === '') { resumen.hidden = true; }
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

    /* ESPERA AL ENVIAR -- seccion 14. El doble envio se corta en el acto pero
       sin nada visible; pasados 300 ms, spinner dentro del boton (mismo
       ancho) y aria-busy; pasados 10 s, que se esta haciendo y una barra
       indeterminada (no hay porcentaje real que mostrar). */
    var enviando = false;
    var umbral = null;
    var largo = null;
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
            espera.hidden = true;
            espera.querySelector('p').textContent = '';
        }
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
            espera.querySelector('p').textContent = 'Estamos leyendo y revisando cada fila del archivo. Con muchas filas puede tardar hasta un minuto: no cierres esta pagina. No se guarda nada.';
        }, 10000);
    });

    if (input.getAttribute('aria-invalid') === 'true') { input.focus(); }

    /* Recien revisado, el veredicto esta arriba del formulario: se lleva ahi el
       foco para que el lector de pantalla lo lea primero. */
    var resultado = document.getElementById('resultado');
    if (resultado) { resultado.focus(); }
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
