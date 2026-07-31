<?php
/**
 * Paso 1 del flujo de facturacion masiva: cargar el archivo.
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
 * DESCARGAR PLANTILLA VA ANTES DEL FORMULARIO, NO DENTRO. Antes era el segundo
 * elemento del .acciones-grupo del form, al lado del submit: aparecia en el
 * momento equivocado del flujo. Quien necesita la plantilla la necesita ANTES
 * de tener un archivo que subir, no en el instante de enviarlo. Ahora el orden
 * de la tarjeta sigue el orden real de la tarea: descargar -> llenar -> subir.
 *
 * Se sube ademas de .boton-texto a .boton-secundario. Es el mismo peso visual
 * que .upload__boton ("Seleccionar archivo") en esta misma vista, que tambien
 * es un paso previo al envio; el azul solido de .boton-principal queda
 * reservado para el submit, que sigue siendo la accion principal.
 *
 * El glifo repite el patron de .upload__icono: <span aria-hidden> con entidad
 * numerica, no imagen ni SVG. Va sin clase a proposito -- hereda el tamano del
 * boton y no necesita ninguna regla CSS nueva. &#8615; (U+21A7, flecha hacia
 * abajo desde barra) es el simetrico exacto del &#8613; (U+21A5) que usa el
 * area de subida.
 */
$titulo     = 'Carga masiva de notas de venta';
$pasoActual = 1;
require __DIR__ . '/partials/header.php';

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
    'precio_unitario'       => ['obligatorio', 'Numero mayor o igual a 0, sin IVA.'],
    'exento'                => ['opcional',    'SI, NO, o dejar vacio.'],
    'folio_boleta_a_anular' => ['opcional',    'Numero entero mayor que 0. Solo si esta nota reemplaza una boleta ya emitida.'],
    'fecha_boleta_a_anular' => ['condicional', 'Obligatoria cuando viene folio_boleta_a_anular.'],
];

// Requisito de una columna NO es un estado: no se usan verde/rojo. El peso
// visual va de mas a menos (relleno gris -> ambar -> contorno tenue).
$fmtFechaHora = static function ($v): string {
    $t = strtotime((string) $v);
    return $t === false ? (string) $v : date('Y-m-d H:i', $t);
};

$badgeRequisito = [
    'obligatorio' => ['badge--neutro', 'Obligatorio'],
    'condicional' => ['badge--advertencia', 'Condicional'],
    'opcional'    => ['badge--etiqueta', 'Opcional'],
];
?>

<div class="dash-header">
    <div>
        <h1>Carga masiva de notas de venta</h1>
    </div>
</div>
<p class="dash-subtitulo">
    Sube un Excel con las notas de venta a facturar. Cada fila es una nota.
</p>

<?php require __DIR__ . '/partials/_stepper.php'; ?>

<?php if (! empty($error)): ?>
    <p class="alerta alerta--error" role="alert">
        <span class="alerta__icono" aria-hidden="true">&#9888;</span>
        <span><?= htmlspecialchars($error); ?></span>
    </p>
<?php endif; ?>

<div class="layout-principal-lateral">
    <div>
        <section class="tarjeta" aria-labelledby="titulo-archivo">
            <h2 id="titulo-archivo">Archivo Excel (.xlsx)</h2>
            <p class="carga-masiva__intro">
                Si el receptor ya existe en tus <a href="/maestros/clientes">clientes</a> se usan
                sus datos del maestro. Si es nuevo, se crea con los datos del archivo.
            </p>

            <?php
                /* Fuera del <form> a proposito: es una descarga (GET), no tiene
                   nada que ver con el envio del archivo y no debe competir con
                   el submit. .acciones-grupo ya aporta el margen superior; el
                   .upload que sigue trae el suyo. Sin CSS nuevo. */
            ?>
            <div class="acciones-grupo">
                <a class="boton-secundario" href="/ventas/carga-masiva/plantilla">
                    <span aria-hidden="true">&#8615;</span> Descargar plantilla Excel
                </a>
            </div>

            <form method="post" action="/ventas/carga-masiva" enctype="multipart/form-data">
                <?= csrfInput(); ?>

                <div class="upload">
                    <div class="upload__area">
                        <span class="upload__icono" aria-hidden="true">&#8613;</span>
                        <p class="upload__texto">Selecciona el archivo Excel con las notas de venta</p>
                        <!-- El input real NO se elimina ni se reemplaza: queda oculto a la
                             vista pero sigue en el DOM, enfocable y con su name/accept/required
                             intactos. Se usa .visualmente-oculto (clip) y no display:none, para
                             que el navegador pueda enfocarlo al mostrar su mensaje de validacion
                             nativo si se envia sin archivo. -->
                        <input class="visualmente-oculto" type="file" id="archivo" name="archivo"
                               accept=".xlsx" required
                               aria-describedby="ayuda-archivo">
                        <label class="boton-secundario upload__boton" for="archivo">Seleccionar archivo</label>
                        <p class="upload__meta" id="ayuda-archivo">
                            Solo archivos .xlsx. Hasta <?= number_format(NOTA_VENTA_MAX_FILAS, 0, ',', '.'); ?> filas de datos.
                        </p>
                        <p class="upload__seleccionado" id="archivo-seleccionado" aria-live="polite"></p>
                    </div>
                </div>

                <?php
                /* EL TIPO VA AQUI Y NO EN UNA COLUMNA DEL EXCEL. La plantilla ya
                   tiene una columna "exento" POR LINEA; una columna de tipo POR
                   FILA seria un segundo concepto solapado con el primero y capaz
                   de contradecirlo. Un check por archivo no puede contradecir
                   nada: o el archivo entero es exento, o no lo es.

                   .form-check + .form-ayuda es el par que el panel ya usa para
                   una casilla con su explicacion (ver producto-form.php, la
                   casilla "Exento de IVA"). Cero CSS nuevo.

                   EL ENVOLTORIO .form-compacto ES NECESARIO, no decorativo: la
                   hoja tiene un `input { display: block; width: 100% }` global, y
                   sin este contexto la casilla sale estirada a todo el ancho (362
                   x 13 px medidos) en vez de ser una casilla. El reset vive en
                   `.form-compacto input[type="checkbox"]`, y producto-form.php lo
                   consigue porque su <form> entero lleva esa clase. Aqui NO se le
                   pone al <form>, que tiene un input de archivo y otra anatomia:
                   se abre el contexto solo alrededor de este campo.
                   .form-compacto no tiene regla propia -- solo prefija
                   descendientes --, asi que como envoltorio no aporta layout. */
                ?>
                <div class="form-compacto">
                    <div class="form-campo form-campo--ancho">
                        <label class="form-check" for="tipo_exento">
                            <input type="checkbox" name="tipo_exento" id="tipo_exento" value="1"
                                   aria-describedby="ayuda-exento">
                            Este archivo es de facturas exentas (tipo 34)
                        </label>
                        <small class="form-ayuda" id="ayuda-exento">
                            Marcado, TODAS las notas de este archivo se emiten como factura exenta y todas sus
                            lineas tienen que venir con "exento" = SI. Sin marcar, salen como factura afecta,
                            igual que siempre. Por ahora un archivo exento no puede traer folio de boleta a anular.
                        </small>
                    </div>
                </div>

                <div class="acciones-grupo">
                    <button class="boton-principal" type="submit">Cargar archivo</button>
                </div>
            </form>
        </section>

        <section class="tarjeta" aria-labelledby="titulo-campos">
            <h2 id="titulo-campos">Campos requeridos</h2>
            <p class="carga-masiva__intro">
                Las columnas deben ir en este orden y con estos nombres exactos. La plantilla ya
                los trae.
            </p>
            <div class="tabla-scroll">
                <table class="tabla-datos">
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
                                <th scope="row"><code><?= htmlspecialchars($columna); ?></code></th>
                                <td class="tabla-datos__estado">
                                    <span class="badge <?= $claseBadge; ?>"><?= $textoBadge; ?></span>
                                </td>
                                <td><?= htmlspecialchars($descripcion); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <aside>
        <div class="panel-info">
            <p class="panel-info__titulo">
                <span class="panel-info__icono" aria-hidden="true">&#9432;</span>Antes de cargar
            </p>
            <ul class="panel-info__lista">
                <li>Los encabezados deben coincidir exactamente con la plantilla, en el mismo orden.</li>
                <li>Las filas completamente vacias se ignoran, no cuentan como error.</li>
                <li>Una fila con problemas no detiene la carga: se guarda con el motivo del error para que puedas corregirla.</li>
                <li>El identificador de cada nota evita que la misma venta se cargue dos veces.</li>
                <li>Los montos van sin IVA; el impuesto se calcula al emitir.</li>
            </ul>
        </div>

        <div class="panel-info">
            <p class="panel-info__titulo">
                <span class="panel-info__icono" aria-hidden="true">&#9679;</span>Clientes (receptores)
            </p>
            <ul class="panel-info__lista">
                <li>Si el receptor ya esta en tu maestro, se usan sus datos y los del archivo se ignoran.</li>
                <li>Si es nuevo, se crea automaticamente y el archivo debe traer razon social, giro, direccion y comuna.</li>
            </ul>
        </div>

        <div class="panel-info">
            <p class="panel-info__titulo">
                <span class="panel-info__icono" aria-hidden="true">&#8594;</span>Despues de cargar
            </p>
            <p>
                Vas a ver el resumen del lote con las filas validas y las que tienen problemas.
                Desde ahi continuas a la facturacion.
            </p>
            <p><a class="boton-texto" href="/ventas/facturacion-masiva">Ir a facturacion masiva</a></p>
        </div>
    </aside>
</div>

<section aria-labelledby="titulo-lotes">
    <h2 class="titulo-seccion" id="titulo-lotes">Lotes cargados</h2>

    <?php if ($lotes === []): ?>
        <div class="estado-vacio">
            <h2>Todavia no has cargado ningun archivo</h2>
            <p>Cuando subas tu primer Excel vas a ver aqui el historial de cargas, con las filas
            validas y las que quedaron con problemas.</p>
        </div>
    <?php else: ?>
        <div class="tabla-scroll">
            <table class="tabla-datos tabla-lotes-carga">
                <caption class="visualmente-oculto">Archivos cargados, con el resultado de su validacion.</caption>
                <thead>
                    <tr>
                        <th scope="col">Fecha</th>
                        <th scope="col">Archivo</th>
                        <th scope="col" class="tabla-datos__num">Total filas</th>
                        <th scope="col" class="tabla-datos__num">Validas</th>
                        <th scope="col" class="tabla-datos__num">Con error</th>
                        <th scope="col" class="tabla-datos__estado">Estado</th>
                        <th scope="col" class="tabla-datos__acciones">Accion</th>
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
                            <td><?= htmlspecialchars($fmtFechaHora($l['created_at'])); ?></td>
                            <td><?= htmlspecialchars((string) $l['nombre_archivo']); ?></td>
                            <td class="tabla-datos__num"><?= (int) $l['total_filas']; ?></td>
                            <td class="tabla-datos__num"><?= (int) $l['filas_validas']; ?></td>
                            <td class="tabla-datos__num"><?= (int) $l['filas_error']; ?></td>
                            <td class="tabla-datos__estado">
                                <?php if ($conError): ?>
                                    <span class="badge badge--advertencia">
                                        <span class="badge__icono" aria-hidden="true">&#9888;</span>Con errores
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge--ok">
                                        <span class="badge__icono" aria-hidden="true">&#10003;</span>Validado
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="tabla-datos__acciones">
                                <a href="/ventas/carga-masiva/<?= (int) $l['id']; ?>">Ver detalle</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<script>
// Presentacion unicamente: muestra el nombre del archivo elegido, porque el
// input nativo esta oculto a la vista y con el se pierde su texto por defecto.
// Si el JS no corre, el formulario sigue funcionando igual.
(function () {
    var input = document.getElementById('archivo');
    var salida = document.getElementById('archivo-seleccionado');
    if (!input || !salida) { return; }
    input.addEventListener('change', function () {
        salida.textContent = input.files && input.files.length
            ? 'Archivo seleccionado: ' + input.files[0].name
            : '';
    });
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
