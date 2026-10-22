<?php
/**
 * Paso 1 del flujo de facturacion masiva: cargar el archivo.
 *
 * TAREA DE LA PANTALLA, EN UNA FRASE: subir el Excel con las notas de venta
 * para que el sistema las revise antes de facturarlas.
 *
 * Recibe (sin cambios respecto de antes): $error, $lotes, $navActivo.
 *
 * TODO el contenido informativo de esta pantalla sale de reglas que estan en
 * el codigo, no de supuestos:
 *   - las columnas, de NOTA_VENTA_ENCABEZADOS (la misma constante con que se
 *     genera la plantilla y con la que se comparan los encabezados al leer);
 *   - el tope de filas, de NOTA_VENTA_MAX_FILAS;
 *   - los formatos de fecha, de FechaExcel::FORMATOS;
 *   - el resto, de validarFilaCargaMasiva() y leerFilasExcelCargaMasiva().
 * Si manana cambia una validacion, esta pantalla no queda mintiendo sola:
 * columnas y formatos se leen de la fuente.
 *
 * DESCARGAR PLANTILLA VA ANTES DEL ARCHIVO, NO JUNTO AL SUBMIT. Quien necesita
 * la plantilla la necesita ANTES de tener un archivo que subir. El orden de la
 * tarjeta sigue el orden real de la tarea: descargar -> llenar -> subir.
 *
 * ANCHO: todo el ancho, repartido en columnas, nunca estirando campos. Arriba,
 * el archivo y lo que hay que saber antes de cargar, lado a lado; abajo las
 * columnas del Excel y el historial de lotes, a ancho completo porque son
 * tablas. Bajo 720px las tablas pasan a tarjetas.
 *
 * ARCHIVO (formularios.html): zona de arrastre + boton, y tipo, peso y tope de
 * filas dichos ANTES de elegir. El tipo y el peso se revisan en el navegador
 * antes de subir; el contenido lo sigue validando el servidor. 32 MB es el
 * upload_max_filesize del contenedor (docker/Dockerfile.panel).
 *
 * ESPERA (seccion 14): nada visible bajo 300 ms; despues spinner dentro del
 * boton (mismo ancho), aria-busy, y pageshow para volver con Atras. Leer y
 * validar miles de filas puede pasar de 10 s: ahi aparece que se esta haciendo
 * y una barra INDETERMINADA, porque el servidor no informa un porcentaje real.
 *
 * EL TIPO EXENTO ES UN INTERRUPTOR CON EXPLICACION: encenderlo cambia que se
 * va a emitir con TODO el archivo, asi que la linea de abajo dice que pasa en
 * cada estado y se reescribe al cambiarlo. name/value no cambian.
 */
$titulo     = 'Carga masiva de notas de venta';
$pasoActual = 1;
require __DIR__ . '/partials/header.php';

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

/**
 * Requisito y descripcion por columna. El ORDEN y los NOMBRES no se escriben
 * aqui: se recorren desde NOTA_VENTA_ENCABEZADOS.
 */
$requisitos = [
    'identificador_externo' => ['obligatorio', 'Identifica la nota. No puede repetirse dentro del archivo ni haberse cargado en un lote anterior.'],
    'rut_receptor'          => ['obligatorio', 'RUT del receptor, con o sin puntos. Se valida el digito verificador.'],
    'razon_social_receptor' => ['condicional', 'Obligatorio solo si el receptor no esta en tu maestro de clientes.'],
    'giro_receptor'         => ['condicional', 'Obligatorio solo si el receptor no esta en tu maestro de clientes.'],
    'direccion_receptor'    => ['condicional', 'Obligatorio solo si el receptor no esta en tu maestro de clientes.'],
    'comuna_receptor'       => ['condicional', 'Obligatorio solo si el receptor no esta en tu maestro de clientes.'],
    'email_receptor'        => ['opcional',    'Correo del receptor.'],
    'fecha_nota'            => ['obligatorio', 'Fecha de la venta original. Formatos: ' . FechaExcel::FORMATOS . '.'],
    'producto_servicio'     => ['obligatorio', 'Descripcion que aparece en el detalle del documento.'],
    'cantidad'              => ['obligatorio', 'Numero mayor que 0.'],
    'precio_unitario'       => ['condicional', 'Precio sin IVA, numero mayor o igual a 0. Puedes dejarlo vacio si escribes precio_final.'],
    'exento'                => ['opcional',    'SI, NO, o dejar vacio.'],
    // SIN ESTAS DOS ENTRADAS la tabla las pintaba "Opcional" por el fallback de
    // abajo, y esta pantalla le habria dicho al usuario justo lo contrario de lo
    // que hace el validador, que rechaza el archivo entero si faltan.
    'forma_pago'            => ['obligatorio', 'CONTADO, CREDITO o SIN COSTO. No puede quedar vacia: un documento que no informa forma de pago el SII lo toma como credito.'],
    'fecha_vencimiento'     => ['condicional', 'Obligatoria cuando forma_pago es CREDITO; vacia con CONTADO o SIN COSTO. Formatos: ' . FechaExcel::FORMATOS . '.'],
    'folio_boleta_a_anular' => ['opcional',    'Numero entero mayor que 0. Solo si esta nota reemplaza una boleta ya emitida.'],
    'fecha_boleta_a_anular' => ['condicional', 'Obligatoria cuando viene folio_boleta_a_anular.'],
    'precio_final'          => ['opcional',    'Lo que paga el cliente, con IVA incluido. Si lo escribes, no hace falta precio_unitario y el documento suma exactamente este precio. Un archivo sin esta columna tambien se acepta.'],
];

// Requisito de una columna NO es un estado: no se usan verde/rojo. El peso
// visual va de mas a menos (relleno gris -> ambar -> contorno tenue).
$badgeRequisito = [
    'obligatorio' => ['badge--neutro', 'Obligatorio'],
    'condicional' => ['badge--advertencia', 'Condicional'],
    'opcional'    => ['badge--etiqueta', 'Opcional'],
];

// Fecha y hora en formato chileno: dd/mm/aaaa hh:mm (escritura.html).
$fmtFechaHora = static function ($v): string {
    $t = strtotime((string) $v);
    return $t === false ? (string) $v : date('d/m/Y H:i', $t);
};
$fmtNum = static fn ($v): string => number_format((float) $v, 0, ',', '.');

$hayError   = ! empty($error);
$maxFilas   = $fmtNum(NOTA_VENTA_MAX_FILAS);
$maxBytes   = 32 * 1024 * 1024;

// Las dos frases del interruptor de tipo exento.
$fraseExentoOn  = 'Todas las notas de este archivo se emiten como factura exenta (34). Todas sus lineas tienen que traer exento = SI, y no pueden anular boletas.';
$fraseExentoOff = 'Las notas salen como factura afecta (33), igual que siempre. Cada linea puede marcarse exenta en su columna.';
?>

<div class="dash-header">
    <div>
        <h1>Carga masiva de notas de venta</h1>
    </div>
</div>
<p class="dash-subtitulo">
    Sube un Excel con tus notas de venta: cada fila es una nota y se revisa antes de facturar.
</p>

<?php require __DIR__ . '/partials/_stepper.php'; ?>

<div class="config-ancho">

<div class="alerta alerta--error" id="resumen-errores" role="alert" tabindex="-1"<?= $hayError ? '' : ' hidden'; ?>>
    <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
    <div class="alerta__cuerpo">
        <p class="alerta__titulo">No se cargo ninguna nota</p>
        <ul class="alerta__lista">
            <li><a href="#archivo">Archivo Excel</a>: <span data-resumen-texto><?= $e((string) ($error ?? '')); ?></span></li>
        </ul>
    </div>
</div>

<div class="config-columnas config-columnas--2">
    <section class="tarjeta" aria-labelledby="titulo-archivo">
        <h2 id="titulo-archivo">Archivo Excel</h2>
        <p class="tarjeta__intro">
            Si el receptor ya existe en tus <a href="/maestros/clientes">clientes</a> se usan sus datos.
            Si es nuevo, se crea con los datos del archivo.
        </p>

        <?php
            /* Fuera del <form> a proposito: es una descarga (GET), no tiene
               nada que ver con el envio del archivo y no debe competir con el
               submit. */
        ?>
        <p class="carga-masiva__plantilla">
            <a class="boton-secundario" href="/ventas/carga-masiva/plantilla">Descargar plantilla Excel</a>
        </p>

        <form method="post" action="/ventas/carga-masiva" enctype="multipart/form-data"
              class="form-compacto" id="form-carga" novalidate>
            <?= csrfInput(); ?>

            <div class="form-campo<?= $hayError ? ' form-campo--error' : ''; ?>">
                <label for="archivo">Archivo con las notas</label>
                <div class="upload">
                    <div class="upload__area" data-zona-archivo>
                        <?= iconoSvg('carga-masiva', 32, 'upload__icono'); ?>
                        <p class="upload__texto">Arrastra aqui el Excel con las notas de venta, o eligelo desde tu equipo</p>
                        <!-- El input real NO se elimina: queda oculto a la vista pero
                             sigue en el DOM, enfocable y con su name/accept/required. -->
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

            <?php
            /* EL TIPO VA AQUI Y NO EN UNA COLUMNA DEL EXCEL. La plantilla ya
               tiene una columna "exento" POR LINEA; una columna de tipo POR
               FILA seria un segundo concepto solapado con el primero y capaz
               de contradecirlo. Un interruptor por archivo no puede
               contradecir nada: o el archivo entero es exento, o no lo es. */
            ?>
            <div class="carga-masiva__tipo">
                <label class="interruptor" for="tipo_exento">
                    <input type="checkbox" role="switch" name="tipo_exento" id="tipo_exento" value="1"
                           aria-describedby="ayuda-exento">
                    <span class="interruptor__texto">
                        <span class="interruptor__titulo">Archivo de facturas exentas</span>
                        <span class="interruptor__detalle" id="ayuda-exento"
                              data-on="<?= $e($fraseExentoOn); ?>" data-off="<?= $e($fraseExentoOff); ?>"><?= $e($fraseExentoOff); ?></span>
                    </span>
                </label>
            </div>

            <div class="acciones-formulario">
                <button class="boton-principal" type="submit" id="boton-cargar">Cargar archivo</button>
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
                <?= iconoSvg('info', 20, 'panel-info__icono'); ?>Antes de cargar
            </p>
            <ul class="panel-info__lista">
                <li>Los encabezados deben coincidir exactamente con la plantilla, en el mismo orden.</li>
                <li>Las filas completamente vacias se ignoran, no cuentan como error.</li>
                <li>Una fila con problemas no detiene la carga: se guarda con el motivo para que puedas corregirla.</li>
                <li>El identificador de cada nota evita que la misma venta se cargue dos veces.</li>
                <li>El precio va en una de dos columnas: precio_unitario (sin IVA) o precio_final (lo que paga el cliente). El IVA se calcula solo.</li>
            </ul>
        </div>

        <div class="panel-info">
            <p class="panel-info__titulo">
                <?= iconoSvg('clientes', 20, 'panel-info__icono'); ?>Clientes (receptores)
            </p>
            <ul class="panel-info__lista">
                <li>Si el receptor ya esta en tu maestro, se usan sus datos y los del archivo se ignoran.</li>
                <li>Si es nuevo, se crea solo y el archivo debe traer razon social, giro, direccion y comuna.</li>
            </ul>
        </div>

        <div class="panel-info">
            <p class="panel-info__titulo">
                <?= iconoSvg('flecha', 20, 'panel-info__icono'); ?>Despues de cargar
            </p>
            <p>
                Vas a ver el resumen del lote con las filas validas y las que tienen problemas.
                Desde ahi sigues a la facturacion.
            </p>
            <p><a href="/ventas/facturacion-masiva">Ir a facturacion masiva</a></p>
        </div>
    </div>
</div>

<section class="tarjeta" aria-labelledby="titulo-campos">
    <h2 id="titulo-campos">Columnas del archivo</h2>
    <p class="tarjeta__intro">
        Deben ir en este orden y con estos nombres exactos. La plantilla ya los trae.
    </p>
    <div class="tabla-scroll">
        <table class="tabla-datos tabla-datos--cards">
            <caption class="visualmente-oculto">Columnas del archivo Excel, su requisito y descripcion.</caption>
            <thead>
                <tr>
                    <th scope="col">Columna</th>
                    <th scope="col">Requisito</th>
                    <th scope="col">Descripcion</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (NOTA_VENTA_ENCABEZADOS as $columna): ?>
                    <?php
                        [$nivel, $descripcion] = $requisitos[$columna] ?? ['opcional', ''];
                        [$claseBadge, $textoBadge] = $badgeRequisito[$nivel];
                    ?>
                    <tr>
                        <th scope="row" data-label="Columna"><code><?= $e($columna); ?></code></th>
                        <td data-label="Requisito" class="tabla-datos__estado">
                            <span class="badge <?= $claseBadge; ?>"><?= $textoBadge; ?></span>
                        </td>
                        <td data-label="Descripcion"><?= $e($descripcion); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="tarjeta" aria-labelledby="titulo-lotes">
    <h2 id="titulo-lotes">Lotes cargados</h2>

    <?php if ($lotes === []): ?>
        <div class="estado-vacio estado-vacio--centrado">
            <?= iconoSvg('carga-masiva', 32, 'estado-vacio__icono'); ?>
            <h3>Todavia no has cargado ningun archivo</h3>
            <p>Cuando subas tu primer Excel vas a ver aqui el historial de cargas, con las filas
            validas y las que quedaron con problemas.</p>
            <p class="estado-vacio__acciones">
                <label class="boton-secundario" for="archivo">Elegir el primer archivo</label>
            </p>
        </div>
    <?php else: ?>
        <div class="tabla-scroll">
            <table class="tabla-datos tabla-datos--cards tabla-lotes-carga">
                <caption><?= $fmtNum(count($lotes)); ?> archivo<?= count($lotes) === 1 ? '' : 's'; ?> cargado<?= count($lotes) === 1 ? '' : 's'; ?>, del mas nuevo al mas antiguo</caption>
                <thead>
                    <tr>
                        <th scope="col">Fecha</th>
                        <th scope="col">Archivo</th>
                        <th scope="col" class="tabla-datos__num">Total filas</th>
                        <th scope="col" class="tabla-datos__num">Validas</th>
                        <th scope="col" class="tabla-datos__num">Con error</th>
                        <th scope="col" class="tabla-datos__estado">Estado</th>
                        <th scope="col" class="tabla-datos__acciones">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lotes as $l): ?>
                        <?php
                            // El estado NO existe como columna en lote_carga: se deriva de los
                            // conteos que la consulta ya trae. No hay dato inventado.
                            $conError = (int) $l['filas_error'] > 0;
                        ?>
                        <tr>
                            <td data-label="Fecha"><?= $e($fmtFechaHora($l['created_at'])); ?></td>
                            <td data-label="Archivo"><?= $e((string) $l['nombre_archivo']); ?></td>
                            <td data-label="Total filas" class="tabla-datos__num"><?= $fmtNum($l['total_filas']); ?></td>
                            <td data-label="Validas" class="tabla-datos__num"><?= $fmtNum($l['filas_validas']); ?></td>
                            <td data-label="Con error" class="tabla-datos__num"><?= $fmtNum($l['filas_error']); ?></td>
                            <td data-label="Estado" class="tabla-datos__estado">
                                <?php if ($conError): ?>
                                    <span class="badge badge--advertencia"><?= iconoSvg('alerta', 16, 'badge__icono'); ?>Con errores</span>
                                <?php else: ?>
                                    <span class="badge badge--ok"><?= iconoSvg('check', 16, 'badge__icono'); ?>Validado</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="" class="tabla-datos__acciones">
                                <a href="/ventas/carga-masiva/<?= (int) $l['id']; ?>"
                                   aria-label="Ver detalle del archivo <?= $e((string) $l['nombre_archivo']); ?>">Ver detalle</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

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
            espera.querySelector('p').textContent = 'Estamos leyendo y revisando cada fila del archivo. Con muchas filas puede tardar hasta un minuto: no cierres esta pagina.';
        }, 10000);
    });

    if (input.getAttribute('aria-invalid') === 'true') { input.focus(); }
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
