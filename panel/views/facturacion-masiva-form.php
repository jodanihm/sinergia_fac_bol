<?php
/**
 * Paso 3 del flujo: facturacion masiva.
 *
 * TAREA DE LA PANTALLA, EN UNA FRASE: elegir las notas pendientes y emitirlas
 * al SII de una vez, sabiendo cuantos documentos y folios se van a usar.
 *
 * Recibe (sin cambios): $pendientes, $rutFiltro, $foliosFactura, $foliosExenta,
 * $foliosNc, $resultado, $flash, $subLoteTamano, $navActivo.
 *
 * OJO CON EL LENGUAJE: esta pantalla NO trabaja sobre un lote. Lista las notas
 * en estado 'pendiente' de TODA la cuenta (listarNotasVentaPendientes(), LIMIT
 * 500) y el usuario elige cuales emitir. Por eso aca no se habla de "lote
 * seleccionado" ni de "documentos de este lote": seria prometer algo que el
 * sistema no hace.
 *
 * CONTRATO CON EL JS: los identificadores que usa el script no cambian ->
 * form-facturacion-masiva, btn-facturar, marcar-todas, .nota-checkbox,
 * progreso-container, progreso-texto, progreso-barra, aviso-seleccion,
 * kpi-seleccionadas, kpi-documentos, kpi-monto, y name="notas[]" /
 * name="csrf_token". "marcar-todas" sale del <thead> a una barra sobre la
 * tabla: en movil la tabla pasa a tarjetas y el encabezado se oculta, y con el
 * se habria ido la unica forma de marcar todas.
 *
 * SECCION 14, AQUI MAS QUE EN NINGUNA PANTALLA:
 *   - el progreso es REAL: cada respuesta de /confirmar-sublote trae el conteo
 *     acumulado, asi que la barra es DETERMINADA, con "N de M", el porcentaje
 *     AL LADO de la barra (nunca dentro) y role="progressbar" con
 *     aria-valuenow;
 *   - cada grupo puede tardar varios segundos en el SII, asi que el texto dice
 *     que se esta haciendo ("Enviando al SII el grupo 2 de 5");
 *   - nada aparece bajo 300 ms; el boton se bloquea en el acto y su spinner
 *     llega a los 300 ms, sin cambiar de ancho.
 *
 * LO IMPOSIBLE NO SE OFRECE: con los folios que la vista ya recibe se calcula,
 * al marcar, si alcanzan; si no, se avisa y no se deja emitir. El servidor lo
 * vuelve a comprobar antes de tocar ninguna nota (409).
 *
 * CONFIRMACION: emitir no se deshace y quema folios, asi que pasa por un
 * <dialog> que nombra cuantos documentos y que pasa ("Si, emitir 37
 * documentos"), nunca "Aceptar".
 */
$titulo         = 'Facturacion masiva';
$pasoActual     = 3;
$enlacesStepper = [1 => '/ventas/carga-masiva'];
require __DIR__ . '/partials/header.php';

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

// Cantidades con punto de miles; el monto ademas con "$" (escritura.html).
$fmtNum = static function ($v): string {
    return $v === null ? '-' : number_format((float) $v, 0, ',', '.');
};
$fmtMonto = static function ($v) use ($fmtNum): string {
    return $v === null ? '-' : '$' . $fmtNum($v);
};
// Fecha en formato chileno, dd/mm/aaaa.
$fmtFecha = static function ($v): string {
    $s = trim((string) $v);
    if ($s === '') {
        return '';
    }
    $t = strtotime($s);
    return $t === false ? $s : date('d/m/Y', $t);
};

$estados = [
    'pendiente'  => ['badge--neutro',  'Pendiente',  'sin-cargar'],
    'en_proceso' => ['badge--proceso', 'En proceso', 'reloj'],
    'facturada'  => ['badge--ok',      'Facturada',  'check'],
    'error'      => ['badge--error',   'Con error',  'alerta'],
];

$totalPendientes = count($pendientes);

// Resumen de la ultima corrida, cuando se vuelve con ?ids=...
$resFacturadas = 0;
$resErrores    = 0;
foreach ($resultado as $n) {
    if ($n['estado'] === 'facturada') { $resFacturadas++; }
    if ($n['estado'] === 'error')     { $resErrores++; }
}
?>

<div class="dash-header">
    <div>
        <h1>Facturacion masiva</h1>
    </div>
</div>
<p class="dash-subtitulo">
    Marca las notas pendientes que quieres emitir y revisa que te alcancen los folios.
</p>

<?php require __DIR__ . '/partials/_stepper.php'; ?>

<div class="config-ancho">

<?php if (! empty($flash['mensaje'])): ?>
    <p class="alerta alerta--<?= ($flash['tipo'] ?? '') === 'exito' ? 'exito' : 'error'; ?>" role="status">
        <?= iconoSvg(($flash['tipo'] ?? '') === 'exito' ? 'check' : 'alerta', 20, 'alerta__icono'); ?>
        <span><?= $e((string) $flash['mensaje']); ?></span>
    </p>
<?php endif; ?>

<?php if ($resultado !== []): ?>
    <section class="tarjeta" aria-labelledby="titulo-resultado">
        <h2 id="titulo-resultado">Resultado de la ultima facturacion</h2>
        <?php if ($resErrores > 0): ?>
            <div class="alerta alerta--advertencia">
                <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
                <div class="alerta__cuerpo">
                    <p class="alerta__titulo"><?= $fmtNum($resFacturadas); ?> facturadas y <?= $fmtNum($resErrores); ?> con error</p>
                    <p>Las notas con error no se emitieron y no gastaron folio. El motivo esta en cada fila.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="alerta alerta--exito">
                <?= iconoSvg('check', 20, 'alerta__icono'); ?>
                <div class="alerta__cuerpo">
                    <p class="alerta__titulo"><?= $fmtNum($resFacturadas); ?> nota<?= $resFacturadas === 1 ? '' : 's'; ?> facturada<?= $resFacturadas === 1 ? '' : 's'; ?></p>
                    <p>Los documentos ya estan en el Panel de emision.</p>
                </div>
            </div>
        <?php endif; ?>
        <div class="tabla-scroll">
            <table class="tabla-datos tabla-datos--cards">
                <caption class="visualmente-oculto">Notas procesadas en la ultima corrida.</caption>
                <thead>
                    <tr>
                        <th scope="col">Identificador</th>
                        <th scope="col">Receptor</th>
                        <th scope="col" class="tabla-datos__estado">Estado</th>
                        <th scope="col">Detalle</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($resultado as $n): ?>
                        <?php
                            $est = (string) $n['estado'];
                            [$claseBadge, $etiqueta, $icono] = $estados[$est] ?? ['badge--neutro', $est, 'sin-cargar'];
                        ?>
                        <tr>
                            <td data-label="Identificador"><?= $e((string) ($n['identificador_externo'] ?? '')) ?: '&mdash;'; ?></td>
                            <td data-label="Receptor"><?= $e((string) ($n['receptor_rut'] ?? '')) ?: '&mdash;'; ?></td>
                            <td data-label="Estado" class="tabla-datos__estado">
                                <span class="badge <?= $claseBadge; ?>"><?= iconoSvg($icono, 16, 'badge__icono'); ?><?= $e($etiqueta); ?></span>
                            </td>
                            <td data-label="Detalle">
                                <?php if ($est === 'error' && ! empty($n['error_mensaje'])): ?>
                                    <span class="texto-error"><?= $e((string) $n['error_mensaje']); ?></span>
                                <?php elseif ($est === 'facturada' && ! empty($n['resultado_documentos'])): ?>
                                    <span>
                                    <?php foreach ((json_decode((string) $n['resultado_documentos'], true) ?: []) as $doc): ?>
                                        <span class="tabla-datos__secundario">
                                            <?= $e(nombreTipoDte((int) ($doc['tipoDte'] ?? 0))); ?>
                                            folio <?= $e((string) ($doc['folio'] ?? '-')); ?>
                                        </span>
                                    <?php endforeach; ?>
                                    </span>
                                <?php else: ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<section class="dash-grid" aria-label="Resumen de la seleccion">
    <article class="kpi">
        <h2 class="kpi__etiqueta">Notas pendientes</h2>
        <p class="kpi__valor"><?= $fmtNum($totalPendientes); ?></p>
        <p class="kpi__formula">De todos tus archivos<?= $rutFiltro !== '' ? ', con el filtro aplicado' : ''; ?></p>
    </article>
    <article class="kpi">
        <h2 class="kpi__etiqueta">Seleccionadas</h2>
        <p class="kpi__valor" id="kpi-seleccionadas">0</p>
        <p class="kpi__formula">Notas marcadas en la tabla</p>
    </article>
    <article class="kpi">
        <h2 class="kpi__etiqueta">Documentos a emitir</h2>
        <p class="kpi__valor" id="kpi-documentos">0</p>
        <p class="kpi__formula" id="kpi-documentos-detalle">Una factura por nota, mas una nota de credito si anula una boleta</p>
    </article>
    <article class="kpi">
        <h2 class="kpi__etiqueta">Monto estimado</h2>
        <p class="kpi__valor" id="kpi-monto">$0</p>
        <p class="kpi__formula">IVA incluido, calculado al cargar. No es el monto emitido.</p>
    </article>
</section>

<div class="config-columnas config-columnas--2">
    <section class="tarjeta" aria-labelledby="titulo-folios">
        <h2 id="titulo-folios">Folios disponibles</h2>
        <ul class="validacion">
            <li class="validacion__item">
                <span>Factura electronica (33)</span>
                <span class="validacion__dato"><?= $fmtNum($foliosFactura); ?></span>
            </li>
            <li class="validacion__item">
                <span>Factura exenta (34)</span>
                <span class="validacion__dato"><?= $fmtNum($foliosExenta); ?></span>
            </li>
            <li class="validacion__item">
                <span>Nota de credito (61)</span>
                <span class="validacion__dato"><?= $fmtNum($foliosNc); ?></span>
            </li>
        </ul>
        <p class="nota">
            Cada nota consume un folio de su tipo, afecta o exenta. Las que anulan una boleta consumen
            ademas uno de nota de credito. Si no alcanzan, no se emite nada.
        </p>
    </section>

    <div class="panel-info">
        <p class="panel-info__titulo">
            <?= iconoSvg('info', 20, 'panel-info__icono'); ?>Antes de emitir
        </p>
        <ul class="panel-info__lista">
            <li>Revisa las notas marcadas: la emision no se puede deshacer.</li>
            <li>Los documentos se envian al SII en grupos de <?= (int) $subLoteTamano; ?> notas.</li>
            <li>Cada grupo es todo o nada: si uno falla, sus notas quedan con error y se sigue con el siguiente.</li>
            <li>Si cierras la pestana a mitad, las notas que faltaban vuelven solas a quedar pendientes.</li>
        </ul>
    </div>
</div>

<section class="tarjeta" aria-labelledby="titulo-pendientes">
    <h2 id="titulo-pendientes">Notas pendientes de emision</h2>

    <form class="filtros" method="get" action="/ventas/facturacion-masiva" role="search">
        <label class="filtros__campo" for="filtro-rut">RUT del receptor</label>
        <input class="filtros__input" type="text" id="filtro-rut" name="rut" inputmode="text" autocomplete="off"
               value="<?= $e($rutFiltro); ?>" placeholder="77724622-4">
        <button class="boton-secundario" type="submit">Filtrar notas</button>
        <?php if ($rutFiltro !== ''): ?>
            <a class="boton-texto" href="/ventas/facturacion-masiva">Quitar filtro</a>
        <?php endif; ?>
    </form>

    <?php if ($pendientes === []): ?>
        <div class="estado-vacio estado-vacio--centrado">
            <?= iconoSvg('facturacion-masiva', 32, 'estado-vacio__icono'); ?>
            <?php if ($rutFiltro !== ''): ?>
                <h3>Ninguna nota pendiente es de ese RUT</h3>
                <p>Revisa el RUT o quita el filtro para ver todas las notas pendientes.</p>
                <p class="estado-vacio__acciones">
                    <a class="boton-secundario" href="/ventas/facturacion-masiva">Quitar filtro</a>
                </p>
            <?php else: ?>
                <h3>No tienes notas pendientes de facturar</h3>
                <p>Cuando cargues notas de venta y queden validas, apareceran aqui para emitirlas.</p>
                <p class="estado-vacio__acciones">
                    <a class="boton-principal" href="/ventas/carga-masiva">Ir a carga masiva</a>
                </p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <form id="form-facturacion-masiva" novalidate>
            <?= csrfInput(); ?>

            <div class="form-compacto facturacion-masiva__barra">
                <label class="form-check" for="marcar-todas">
                    <input type="checkbox" id="marcar-todas">
                    Marcar todas (<?= $fmtNum($totalPendientes); ?>)
                </label>
            </div>

            <div class="tabla-scroll">
                <table class="tabla-datos tabla-datos--cards tabla-seleccion">
                    <caption class="visualmente-oculto">
                        Notas pendientes de emision. Marca las que quieras facturar.
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col" class="col-check"><span class="visualmente-oculto">Facturar</span></th>
                            <th scope="col">Identificador</th>
                            <th scope="col">Receptor</th>
                            <th scope="col">Fecha nota</th>
                            <th scope="col" class="tabla-datos__num">Monto estimado</th>
                            <th scope="col">Forma de pago</th>
                            <th scope="col">Documentos</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendientes as $n): ?>
                            <?php
                            $anulaBoleta = ! empty($n['boleta_ref_folio']);
                            // EL TIPO SE MUESTRA PORQUE ESTA LISTA MEZCLA. No filtra
                            // por archivo: trae pendientes de toda la cuenta, asi que
                            // aqui conviven notas afectas y exentas y el usuario puede
                            // marcarlas juntas. Sin verlo, no puede saber que va a
                            // emitir. data-tipo lo lleva al calculo de folios.
                            $esExenta = (int) ($n['tipo_dte'] ?? 33) === 34;
                            ?>
                            <tr>
                                <td data-label="Facturar" class="col-check">
                                    <input type="checkbox" class="nota-checkbox" name="notas[]"
                                           value="<?= (int) $n['id']; ?>"
                                           data-monto="<?= (int) $n['monto_estimado']; ?>"
                                           data-nc="<?= $anulaBoleta ? '1' : '0'; ?>"
                                           data-tipo="<?= $esExenta ? '34' : '33'; ?>"
                                           aria-label="Facturar la nota <?= $e((string) $n['identificador_externo']); ?>">
                                </td>
                                <td data-label="Identificador"><?= $e((string) $n['identificador_externo']); ?></td>
                                <td data-label="Receptor">
                                    <span>
                                        <?= $e((string) $n['receptor_rut']); ?>
                                        <span class="tabla-datos__secundario"><?= $e((string) $n['receptor_razon_social']); ?></span>
                                    </span>
                                </td>
                                <td data-label="Fecha nota"><?= $e($fmtFecha($n['fecha_nota'])); ?></td>
                                <td data-label="Monto estimado" class="tabla-datos__num"><?= $fmtMonto($n['monto_estimado']); ?></td>
                                <td data-label="Forma de pago">
                                    <?php
                                    // UNA NOTA VIEJA NO DEJA LA CELDA VACIA. "No informada"
                                    // dice la verdad e insinua la consecuencia: sin FmaPago
                                    // el SII toma el documento como CREDITO. Por eso va en
                                    // ambar y no en gris -- no es un dato ausente, es un dato
                                    // que el SII va a rellenar por su cuenta.
                                    $fp = $n['forma_pago'] !== null ? (int) $n['forma_pago'] : null;
                                    [$claseFp, $textoFp, $iconoFp] = match ($fp) {
                                        1       => ['badge--etiqueta', 'Contado', null],
                                        2       => ['badge--etiqueta', 'Credito', null],
                                        3       => ['badge--etiqueta', 'Sin costo', null],
                                        default => ['badge--advertencia', 'No informada', 'alerta'],
                                    };
                                    ?>
                                    <span>
                                        <span class="badge <?= $claseFp; ?>"><?= $iconoFp !== null ? iconoSvg($iconoFp, 16, 'badge__icono') : ''; ?><?= $textoFp; ?></span>
                                        <?php if ($fp === 2 && ! empty($n['fecha_vencimiento'])): ?>
                                            <span class="tabla-datos__secundario">Vence <?= $e($fmtFecha($n['fecha_vencimiento'])); ?></span>
                                        <?php elseif ($fp === null): ?>
                                            <span class="tabla-datos__secundario">El SII la tomara como credito</span>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td data-label="Documentos">
                                    <span>
                                        <?php if ($esExenta): ?>
                                            <span class="badge badge--etiqueta">Factura exenta</span>
                                        <?php elseif ($anulaBoleta): ?>
                                            <span class="badge badge--etiqueta">Factura + nota de credito</span>
                                            <span class="tabla-datos__secundario">Anula boleta <?= (int) $n['boleta_ref_folio']; ?></span>
                                        <?php else: ?>
                                            <span class="badge badge--etiqueta">Factura</span>
                                        <?php endif; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPendientes >= 500): ?>
                <p class="nota">Se muestran las primeras 500 notas pendientes. Filtra por RUT para acotar la lista.</p>
            <?php endif; ?>

            <!-- Se muestra cuando no hay ninguna casilla marcada al enviar. La clase
                 'oculto' la sigue manejando el script, como antes. -->
            <p class="alerta alerta--error oculto" id="aviso-seleccion" role="alert">
                <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
                <span>Marca al menos una nota para facturar.</span>
            </p>
            <!-- Folios que no alcanzan para lo marcado. Se avisa al marcar, no
                 despues de apretar Facturar. -->
            <div class="alerta alerta--advertencia" id="aviso-folios" role="status" hidden>
                <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
                <div class="alerta__cuerpo">
                    <p class="alerta__titulo">No te alcanzan los folios para lo marcado</p>
                    <p id="aviso-folios-texto"></p>
                </div>
            </div>

            <div class="acciones-formulario">
                <a class="boton-secundario" href="/ventas/carga-masiva">Volver a carga masiva</a>
                <button class="boton-principal" type="submit" id="btn-facturar">Facturar seleccionadas</button>
            </div>

            <div class="progreso-emision" id="progreso-container">
                <p class="progreso-emision__titulo" id="progreso-texto" role="status" aria-live="polite"></p>
                <div class="progreso-fila">
                    <div class="barra-progreso" id="progreso-rol" role="progressbar"
                         aria-label="Avance de la emision" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                        <div class="barra-progreso__relleno" id="progreso-barra"></div>
                    </div>
                    <span class="progreso-fila__pct" id="progreso-pct" aria-hidden="true">0%</span>
                </div>
                <p class="progreso-emision__detalle" id="progreso-detalle"></p>
            </div>
        </form>

        <dialog class="dialogo-confirmar" id="confirmar-emision" aria-labelledby="confirmar-emision-titulo" aria-describedby="confirmar-emision-texto">
            <h2 class="dialogo-confirmar__titulo" id="confirmar-emision-titulo"></h2>
            <p id="confirmar-emision-texto"></p>
            <form method="dialog" class="acciones-formulario">
                <button type="submit" value="no" class="boton-secundario">Volver</button>
                <button type="submit" value="si" class="boton-principal" id="confirmar-emision-si"></button>
            </form>
        </dialog>
    <?php endif; ?>
</section>

</div>

<script>
(function () {
    var form = document.getElementById('form-facturacion-masiva');
    if (!form) { return; }

    var marcarTodas = document.getElementById('marcar-todas');
    var casillas = document.querySelectorAll('.nota-checkbox');
    var avisoSeleccion = document.getElementById('aviso-seleccion');
    var avisoFolios = document.getElementById('aviso-folios');
    var avisoFoliosTexto = document.getElementById('aviso-folios-texto');
    var tamanoGrupo = <?= (int) $subLoteTamano; ?>;

    // Folios que la vista ya recibe del servidor. Solo para avisar antes: el
    // endpoint vuelve a contar y responde 409 si no alcanzan.
    var folios = { 33: <?= (int) $foliosFactura; ?>, 34: <?= (int) $foliosExenta; ?>, 61: <?= (int) $foliosNc; ?> };
    var nombres = { 33: 'facturas', 34: 'facturas exentas', 61: 'notas de credito' };

    // --- Resumen de la seleccion: SOLO presentacion. Montos, marcador de nota
    // de credito y tipo vienen en data-* de cada casilla, calculados en PHP con
    // los mismos datos que ya recibe la vista. No consulta nada.
    var kpiSeleccionadas = document.getElementById('kpi-seleccionadas');
    var kpiDocumentos = document.getElementById('kpi-documentos');
    var kpiMonto = document.getElementById('kpi-monto');
    var seleccion = { notas: 0, documentos: 0, necesita: { 33: 0, 34: 0, 61: 0 } };

    function formatearNumero(n) { return n.toLocaleString('es-CL'); }
    function formatearMonto(n) { return '$' + formatearNumero(n); }

    function faltantes() {
        var falta = [];
        [33, 34, 61].forEach(function (t) {
            if (seleccion.necesita[t] > folios[t]) {
                falta.push(formatearNumero(seleccion.necesita[t]) + ' ' + nombres[t] + ' y quedan ' + formatearNumero(folios[t]));
            }
        });
        return falta;
    }

    function actualizarResumen() {
        var notas = 0, notasCredito = 0, monto = 0;
        var necesita = { 33: 0, 34: 0, 61: 0 };
        casillas.forEach(function (cb) {
            cb.closest('tr').classList.toggle('fila-seleccionada', cb.checked);
            if (!cb.checked) { return; }
            notas++;
            monto += parseInt(cb.getAttribute('data-monto'), 10) || 0;
            necesita[cb.getAttribute('data-tipo') === '34' ? 34 : 33]++;
            if (cb.getAttribute('data-nc') === '1') { notasCredito++; necesita[61]++; }
        });
        seleccion = { notas: notas, documentos: notas + notasCredito, necesita: necesita };
        kpiSeleccionadas.textContent = formatearNumero(notas);
        kpiDocumentos.textContent = formatearNumero(notas + notasCredito);
        kpiMonto.textContent = formatearMonto(monto);
        if (notas > 0) { avisoSeleccion.classList.add('oculto'); }

        // "Marcar todas" refleja la seleccion: marcada, vacia o a medias.
        marcarTodas.checked = notas > 0 && notas === casillas.length;
        marcarTodas.indeterminate = notas > 0 && notas < casillas.length;

        var falta = faltantes();
        avisoFolios.hidden = falta.length === 0;
        avisoFoliosTexto.textContent = falta.length === 0 ? ''
            : 'Marcaste ' + falta.join('; ') + '. Desmarca notas o carga un CAF nuevo en Folios y CAF de produccion.';
    }

    marcarTodas.addEventListener('change', function () {
        casillas.forEach(function (cb) { cb.checked = marcarTodas.checked; });
        actualizarResumen();
    });
    casillas.forEach(function (cb) { cb.addEventListener('change', actualizarResumen); });
    actualizarResumen();

    var progresoContainer = document.getElementById('progreso-container');
    var progresoTexto = document.getElementById('progreso-texto');
    var progresoBarra = document.getElementById('progreso-barra');
    var progresoRol = document.getElementById('progreso-rol');
    var progresoPct = document.getElementById('progreso-pct');
    var progresoDetalle = document.getElementById('progreso-detalle');
    var btnFacturar = document.getElementById('btn-facturar');
    var enCurso = false;
    var umbral = null;

    /* El boton que trabaja lo dice (seccion 14): bloqueado en el acto, con
       spinner y aria-busy recien a los 300 ms, y sin cambiar de ancho. El
       avance real va en la barra de abajo. */
    function ocupado(si) {
        clearTimeout(umbral);
        if (si) {
            btnFacturar.disabled = true;
            umbral = setTimeout(function () {
                btnFacturar.setAttribute('data-cargando', 'true');
                btnFacturar.setAttribute('aria-busy', 'true');
                form.setAttribute('aria-busy', 'true');
                progresoContainer.classList.add('progreso-emision--activo');
            }, 300);
        } else {
            btnFacturar.disabled = false;
            btnFacturar.removeAttribute('data-cargando');
            btnFacturar.removeAttribute('aria-busy');
            form.removeAttribute('aria-busy');
        }
    }

    function pintarAvance(terminadas, total, facturadas, conError) {
        var pct = total > 0 ? Math.round((terminadas / total) * 100) : 100;
        progresoBarra.style.width = pct + '%';
        progresoRol.setAttribute('aria-valuenow', String(pct));
        progresoPct.textContent = pct + '%';
        var grupos = Math.max(1, Math.ceil(total / tamanoGrupo));
        var grupo = Math.min(grupos, Math.floor(terminadas / tamanoGrupo) + 1);
        progresoTexto.textContent = terminadas >= total
            ? 'Listo: ' + formatearNumero(terminadas) + ' de ' + formatearNumero(total) + ' notas procesadas.'
            : 'Enviando al SII el grupo ' + grupo + ' de ' + grupos + ': ' + formatearNumero(terminadas) + ' de ' + formatearNumero(total) + ' notas listas.';
        progresoDetalle.textContent = 'Facturadas: ' + formatearNumero(facturadas) + '. Con error: ' + formatearNumero(conError)
            + '. Cada grupo puede tardar hasta un minuto; no cierres esta pagina.';
    }

    function terminarConError(mensaje) {
        progresoContainer.classList.add('progreso-emision--activo', 'progreso-emision--error');
        progresoTexto.textContent = mensaje;
        progresoDetalle.textContent = 'Lo ya facturado se conserva y no se duplica: puedes volver a intentarlo.';
        enCurso = false;
        ocupado(false);
    }

    // El servidor del panel atiende UNA request a la vez para este flujo: se
    // pide UN sub-lote por request, y cada respuesta de /confirmar-sublote ES
    // el progreso (trae el conteo acumulado). Recien cuando esa request
    // termina se pide la siguiente -- nunca hay 2 requests activas a la vez.
    function pedirSubLote(ids, csrfToken) {
        var datos = new URLSearchParams();
        datos.append('csrf_token', csrfToken);
        ids.forEach(function (id) { datos.append('notas[]', id); });

        fetch('/ventas/facturacion-masiva/confirmar-sublote', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: datos.toString(),
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.status === 'error') {
                    // Error detectado ANTES de tocar ninguna nota de ESTE
                    // sub-lote: el loop se detiene. Lo ya facturado en
                    // pasadas anteriores queda como esta.
                    terminarConError((data && (data.mensaje || data.error))
                        ? 'No se pudo seguir: ' + (data.mensaje || 'falta configurar la credencial de emision de la cuenta.')
                        : 'No se pudo seguir: el servidor respondio algo que no entendemos.');
                    return;
                }

                var c = data.conteo;
                pintarAvance(c.facturada + c.error, c.total, c.facturada, c.error);

                if (data.terminado) {
                    enCurso = false;
                    window.location.href = '/ventas/facturacion-masiva?ids=' + ids.join(',');
                    return;
                }

                pedirSubLote(ids, csrfToken); // sub-lote siguiente, recien ahora que este termino
            })
            .catch(function () {
                terminarConError('No pudimos contactar al servidor. Revisa tu conexion y vuelve a intentarlo.');
            });
    }

    function empezar(ids) {
        enCurso = true;
        ocupado(true);
        progresoContainer.classList.remove('progreso-emision--error');
        pintarAvance(0, ids.length, 0, 0);
        pedirSubLote(ids, form.querySelector('[name=csrf_token]').value);
    }

    /* Confirmacion de lo irreversible: dice cuantos documentos y que pasa, y
       el boton nombra la accion. Sin <dialog> (navegador muy viejo) cae a
       confirm() con el mismo texto. */
    var dlg = document.getElementById('confirmar-emision');
    var idsPendientes = null;
    function confirmar(ids) {
        var n = seleccion.documentos;
        var titulo = 'Emitir ' + formatearNumero(n) + ' documento' + (n === 1 ? '' : 's') + ' al SII?';
        var partes = [];
        if (seleccion.necesita[33]) { partes.push(formatearNumero(seleccion.necesita[33]) + ' factura' + (seleccion.necesita[33] === 1 ? '' : 's')); }
        if (seleccion.necesita[34]) { partes.push(formatearNumero(seleccion.necesita[34]) + ' factura' + (seleccion.necesita[34] === 1 ? '' : 's') + ' exenta' + (seleccion.necesita[34] === 1 ? '' : 's')); }
        if (seleccion.necesita[61]) { partes.push(formatearNumero(seleccion.necesita[61]) + ' nota' + (seleccion.necesita[61] === 1 ? '' : 's') + ' de credito'); }
        var texto = 'Se emiten ' + partes.join(', ') + ' para ' + formatearNumero(ids.length) + ' nota' + (ids.length === 1 ? '' : 's')
            + ', y cada uno gasta un folio. Una vez enviados al SII no se pueden deshacer.';
        var boton = 'Si, emitir ' + formatearNumero(n) + ' documento' + (n === 1 ? '' : 's');
        if (!dlg || !dlg.showModal) {
            if (window.confirm(titulo + '\n\n' + texto)) { empezar(ids); }
            return;
        }
        document.getElementById('confirmar-emision-titulo').textContent = titulo;
        document.getElementById('confirmar-emision-texto').textContent = texto;
        document.getElementById('confirmar-emision-si').textContent = boton;
        idsPendientes = ids;
        dlg.returnValue = '';
        dlg.showModal();
    }
    if (dlg) {
        dlg.addEventListener('close', function () {
            var ids = idsPendientes;
            idsPendientes = null;
            if (ids && dlg.returnValue === 'si') { empezar(ids); } else { btnFacturar.focus(); }
        });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (enCurso) { return; } // el boton ya esta disabled: doble resguardo

        var ids = Array.prototype.slice.call(document.querySelectorAll('.nota-checkbox:checked')).map(function (cb) { return cb.value; });
        if (ids.length === 0) {
            avisoSeleccion.classList.remove('oculto');
            avisoSeleccion.scrollIntoView({ block: 'nearest' });
            marcarTodas.focus();
            return;
        }
        if (faltantes().length > 0) {
            avisoFolios.hidden = false;
            avisoFolios.scrollIntoView({ block: 'nearest' });
            return;
        }
        confirmar(ids);
    });

    // Volver con "Atras" restaura la pagina de la cache tal como quedo.
    window.addEventListener('pageshow', function (ev) {
        if (ev.persisted) { enCurso = false; ocupado(false); }
    });
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
