<?php
/**
 * Paso 3 del flujo: facturacion masiva.
 *
 * Recibe (sin cambios): $pendientes, $rutFiltro, $foliosFactura, $foliosNc,
 * $resultado, $flash, $subLoteTamano, $navActivo.
 *
 * OJO CON EL LENGUAJE: esta pantalla NO trabaja sobre un lote. Lista las notas
 * en estado 'pendiente' de TODA la cuenta (listarNotasVentaPendientes(), LIMIT
 * 500) y el usuario elige cuales emitir. Por eso aca no se habla de "lote
 * seleccionado" ni de "documentos de este lote": seria prometer algo que el
 * sistema no hace.
 *
 * CONTRATO CON EL JS: los identificadores que usa el script no cambian ->
 * form-facturacion-masiva, btn-facturar, marcar-todas, .nota-checkbox,
 * progreso-container, progreso-texto, progreso-barra, y name="notas[]".
 */
$titulo         = 'Facturacion masiva';
$pasoActual     = 3;
$enlacesStepper = [1 => '/ventas/carga-masiva'];
require __DIR__ . '/partials/header.php';

$fmtMonto = static function ($v): string {
    return $v === null ? '-' : number_format((float) $v, 0, ',', '.');
};

$estados = [
    'pendiente'  => ['badge--neutro',  'Pendiente',  '&#9675;'],
    'en_proceso' => ['badge--proceso', 'En proceso', '&#9679;'],
    'facturada'  => ['badge--ok',      'Facturada',  '&#10003;'],
    'error'      => ['badge--error',   'Error',      '&#10007;'],
];

$totalPendientes = count($pendientes);

// Cuantos documentos exigiria emitir TODAS las pendientes listadas. No es una
// regla nueva: es exactamente la cuenta que hace el endpoint antes de emitir
// (una factura por nota, mas una nota de credito si la nota anula una boleta).
$ncPendientes = count(array_filter($pendientes, static fn (array $n): bool => ! empty($n['boleta_ref_folio'])));

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
    Selecciona las notas pendientes que quieres emitir y revisa los folios disponibles antes de comenzar.
</p>

<?php require __DIR__ . '/partials/_stepper.php'; ?>

<?php if (! empty($flash['mensaje'])): ?>
    <p class="alerta alerta--<?= ($flash['tipo'] ?? '') === 'exito' ? 'exito' : 'error'; ?>" role="status">
        <span class="alerta__icono" aria-hidden="true"><?= ($flash['tipo'] ?? '') === 'exito' ? '&#10003;' : '&#9888;'; ?></span>
        <span><?= htmlspecialchars($flash['mensaje']); ?></span>
    </p>
<?php endif; ?>

<?php if ($resultado !== []): ?>
    <section class="tarjeta" aria-labelledby="titulo-resultado">
        <h2 id="titulo-resultado">Resultado de la ultima facturacion</h2>
        <ul class="validacion">
            <li class="validacion__item">
                <span class="badge badge--ok"><span class="badge__icono" aria-hidden="true">&#10003;</span>Facturadas</span>
                <span class="validacion__dato"><?= $fmtMonto($resFacturadas); ?></span>
            </li>
            <?php if ($resErrores > 0): ?>
                <li class="validacion__item">
                    <span class="badge badge--error"><span class="badge__icono" aria-hidden="true">&#10007;</span>Con error</span>
                    <span class="validacion__dato"><?= $fmtMonto($resErrores); ?></span>
                </li>
            <?php endif; ?>
        </ul>
        <div class="tabla-scroll">
            <table class="tabla-datos">
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
                            $e = (string) $n['estado'];
                            [$claseBadge, $etiqueta, $icono] = $estados[$e] ?? ['badge--neutro', $e, '&#9675;'];
                        ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($n['identificador_externo'] ?? '')) ?: '&mdash;'; ?></td>
                            <td><?= htmlspecialchars((string) ($n['receptor_rut'] ?? '')) ?: '&mdash;'; ?></td>
                            <td class="tabla-datos__estado">
                                <span class="badge <?= $claseBadge; ?>">
                                    <span class="badge__icono" aria-hidden="true"><?= $icono; ?></span><?= htmlspecialchars($etiqueta); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($e === 'error' && ! empty($n['error_mensaje'])): ?>
                                    <span class="texto-error"><?= htmlspecialchars((string) $n['error_mensaje']); ?></span>
                                <?php elseif ($e === 'facturada' && ! empty($n['resultado_documentos'])): ?>
                                    <?php foreach ((json_decode((string) $n['resultado_documentos'], true) ?: []) as $doc): ?>
                                        <span class="tabla-datos__secundario">
                                            <?= htmlspecialchars(nombreTipoDte((int) ($doc['tipoDte'] ?? 0))); ?>
                                            folio <?= htmlspecialchars((string) ($doc['folio'] ?? '-')); ?>
                                        </span>
                                    <?php endforeach; ?>
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
        <p class="kpi__valor"><?= $fmtMonto($totalPendientes); ?></p>
        <p class="kpi__formula">De todos tus lotes<?= $rutFiltro !== '' ? ', con el filtro aplicado' : ''; ?></p>
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

<div class="dash-grid dash-grid--2">
    <article class="tarjeta">
        <h2>Folios disponibles</h2>
        <ul class="validacion">
            <li class="validacion__item">
                <span>Factura electronica (33)</span>
                <span class="validacion__dato"><?= $fmtMonto($foliosFactura); ?></span>
            </li>
            <li class="validacion__item">
                <span>Nota de credito (61)</span>
                <span class="validacion__dato"><?= $fmtMonto($foliosNc); ?></span>
            </li>
        </ul>
        <p class="nota">
            Cada nota consume un folio de factura. Las que anulan una boleta consumen ademas uno
            de nota de credito. Si no alcanzan, la emision se detiene antes de tocar ninguna nota.
        </p>
    </article>

    <div class="panel-info">
        <p class="panel-info__titulo">
            <span class="panel-info__icono" aria-hidden="true">&#9432;</span>Antes de emitir
        </p>
        <ul class="panel-info__lista">
            <li>Revisa las notas seleccionadas: la emision no se puede deshacer.</li>
            <li>Los documentos se envian al SII en grupos de <?= (int) $subLoteTamano; ?>.</li>
            <li>Cada grupo es todo o nada: si uno falla, sus notas quedan con error y el proceso sigue con el siguiente.</li>
            <li>Si cierras la pestana a mitad, las notas se recuperan solas y vuelven a quedar pendientes.</li>
        </ul>
    </div>
</div>

<section aria-labelledby="titulo-pendientes">
    <h2 class="titulo-seccion" id="titulo-pendientes">Notas pendientes de emision</h2>

    <form class="filtros" method="get" action="/ventas/facturacion-masiva">
        <label class="filtros__campo" for="filtro-rut">RUT del receptor</label>
        <input class="filtros__input" type="text" id="filtro-rut" name="rut"
               value="<?= htmlspecialchars($rutFiltro); ?>" placeholder="Ej: 77724622-4">
        <button class="boton-secundario" type="submit">Filtrar</button>
        <?php if ($rutFiltro !== ''): ?>
            <a class="boton-texto" href="/ventas/facturacion-masiva">Limpiar</a>
        <?php endif; ?>
    </form>

    <?php if ($pendientes === []): ?>
        <div class="estado-vacio">
            <h2>No hay notas pendientes de facturacion</h2>
            <p>
                <?= $rutFiltro !== ''
                    ? 'Ninguna nota pendiente coincide con el filtro aplicado.'
                    : 'Cuando cargues notas de venta y queden validas, apareceran aqui para emitirlas.'; ?>
            </p>
            <p class="estado-vacio__acciones">
                <?php if ($rutFiltro !== ''): ?>
                    <a class="boton-principal" href="/ventas/facturacion-masiva">Quitar el filtro</a>
                <?php else: ?>
                    <a class="boton-principal" href="/ventas/carga-masiva">Ir a carga masiva</a>
                <?php endif; ?>
            </p>
        </div>
    <?php else: ?>
        <form id="form-facturacion-masiva">
            <?= csrfInput(); ?>
            <div class="tabla-scroll">
                <table class="tabla-datos tabla-seleccion">
                    <caption class="visualmente-oculto">
                        Notas pendientes de emision. Marca las que quieras facturar.
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col" class="col-check">
                                <input type="checkbox" id="marcar-todas">
                                <label class="visualmente-oculto" for="marcar-todas">Marcar todas las notas</label>
                            </th>
                            <th scope="col">Identificador</th>
                            <th scope="col">Receptor</th>
                            <th scope="col">Fecha nota</th>
                            <th scope="col" class="tabla-datos__num">Monto estimado</th>
                            <th scope="col">Documentos</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendientes as $n): ?>
                            <?php $anulaBoleta = ! empty($n['boleta_ref_folio']); ?>
                            <tr>
                                <td class="col-check">
                                    <input type="checkbox" class="nota-checkbox" name="notas[]"
                                           value="<?= (int) $n['id']; ?>"
                                           data-monto="<?= (int) $n['monto_estimado']; ?>"
                                           data-nc="<?= $anulaBoleta ? '1' : '0'; ?>"
                                           aria-label="Seleccionar la nota <?= htmlspecialchars((string) $n['identificador_externo']); ?>">
                                </td>
                                <td><?= htmlspecialchars((string) $n['identificador_externo']); ?></td>
                                <td>
                                    <?= htmlspecialchars((string) $n['receptor_rut']); ?>
                                    <span class="tabla-datos__secundario"><?= htmlspecialchars((string) $n['receptor_razon_social']); ?></span>
                                </td>
                                <td><?= htmlspecialchars((string) $n['fecha_nota']); ?></td>
                                <td class="tabla-datos__num"><?= $fmtMonto($n['monto_estimado']); ?></td>
                                <td>
                                    <?php if ($anulaBoleta): ?>
                                        <span class="badge badge--etiqueta">Factura + nota de credito</span>
                                        <span class="tabla-datos__secundario">Anula boleta <?= (int) $n['boleta_ref_folio']; ?></span>
                                    <?php else: ?>
                                        <span class="badge badge--etiqueta">Factura</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPendientes >= 500): ?>
                <p class="nota">Se muestran las primeras 500 notas pendientes. Filtra por RUT para acotar la lista.</p>
            <?php endif; ?>

            <!-- Reemplaza al alert() anterior. Misma condicion exacta: se muestra
                 cuando no hay ninguna casilla marcada al enviar. -->
            <p class="alerta alerta--error oculto" id="aviso-seleccion" role="alert">
                <span class="alerta__icono" aria-hidden="true">&#9888;</span>
                <span>Selecciona al menos una nota para facturar.</span>
            </p>

            <div class="acciones-grupo">
                <button class="boton-principal" type="submit" id="btn-facturar">Facturar seleccionadas</button>
                <a class="boton-secundario" href="/ventas/carga-masiva">Volver a carga masiva</a>
            </div>

            <div class="progreso-emision" id="progreso-container">
                <p class="progreso-emision__titulo" id="progreso-texto">Procesando...</p>
                <div class="barra-progreso">
                    <div class="barra-progreso__relleno" id="progreso-barra"></div>
                </div>
            </div>
        </form>
    <?php endif; ?>
</section>

<script>
(function () {
    var form = document.getElementById('form-facturacion-masiva');
    if (!form) { return; }

    var marcarTodas = document.getElementById('marcar-todas');
    var casillas = document.querySelectorAll('.nota-checkbox');
    var avisoSeleccion = document.getElementById('aviso-seleccion');

    // --- Resumen de la seleccion: SOLO presentacion. Los montos y el marcador
    // de nota de credito vienen en data-* de cada casilla, calculados en PHP
    // con los mismos datos que ya recibe la vista. No consulta nada.
    var kpiSeleccionadas = document.getElementById('kpi-seleccionadas');
    var kpiDocumentos = document.getElementById('kpi-documentos');
    var kpiMonto = document.getElementById('kpi-monto');

    function formatearMonto(n) {
        return '$' + n.toLocaleString('es-CL');
    }

    function actualizarResumen() {
        var seleccionadas = 0, notasCredito = 0, monto = 0;
        casillas.forEach(function (cb) {
            if (!cb.checked) { return; }
            seleccionadas++;
            monto += parseInt(cb.getAttribute('data-monto'), 10) || 0;
            if (cb.getAttribute('data-nc') === '1') { notasCredito++; }
            cb.closest('tr').classList.add('fila-seleccionada');
        });
        casillas.forEach(function (cb) {
            if (!cb.checked) { cb.closest('tr').classList.remove('fila-seleccionada'); }
        });
        kpiSeleccionadas.textContent = seleccionadas.toLocaleString('es-CL');
        kpiDocumentos.textContent = (seleccionadas + notasCredito).toLocaleString('es-CL');
        kpiMonto.textContent = formatearMonto(monto);
        if (seleccionadas > 0) { avisoSeleccion.classList.add('oculto'); }
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
    var btnFacturar = document.getElementById('btn-facturar');
    var enCurso = false;

    // PASO 3 de M4: el servidor del panel (php -S) es de un solo hilo -- un
    // POST largo bloquea cualquier otra request concurrente, asi que un
    // polling de progreso aparte nunca podria responder mientras tanto (se
    // confirmo empiricamente). En vez de eso, se pide UN sub-lote por
    // request: cada respuesta de /confirmar-sublote ES el progreso (trae el
    // conteo acumulado), y recien cuando esa request termina se pide la
    // siguiente -- nunca hay 2 requests activas al mismo tiempo, asi que el
    // servidor de un solo hilo deja de ser un problema para este flujo.
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
                    // sub-lote (ids vacio, o folios insuficientes para lo que
                    // falta): se muestra aqui mismo, el loop se detiene. Lo
                    // ya facturado en pasadas anteriores queda como esta.
                    progresoContainer.classList.add('progreso-emision--error');
                    progresoTexto.textContent = 'Error: ' + (data ? data.mensaje : 'respuesta invalida del servidor.');
                    enCurso = false;
                    btnFacturar.disabled = false;
                    return;
                }

                var c = data.conteo;
                var terminadas = c.facturada + c.error;
                var pct = c.total > 0 ? Math.round((terminadas / c.total) * 100) : 100;
                progresoBarra.style.width = pct + '%';
                progresoTexto.textContent = 'Procesando: ' + terminadas + ' de ' + c.total +
                    ' (facturadas: ' + c.facturada + ', con error: ' + c.error + ')';

                if (data.terminado) {
                    enCurso = false;
                    window.location.href = '/ventas/facturacion-masiva?ids=' + ids.join(',');
                    return;
                }

                pedirSubLote(ids, csrfToken); // sub-lote siguiente, recien ahora que este termino
            })
            .catch(function () {
                progresoContainer.classList.add('progreso-emision--error');
                progresoTexto.textContent = 'No se pudo contactar el servidor. Puedes reintentar: lo ya facturado no se pierde ni se duplica.';
                enCurso = false;
                btnFacturar.disabled = false;
            });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (enCurso) { return; } // el boton ya deberia estar disabled, doble resguardo

        var ids = Array.prototype.slice.call(document.querySelectorAll('.nota-checkbox:checked')).map(function (cb) { return cb.value; });
        if (ids.length === 0) {
            // Antes era un alert(). Misma condicion, mensaje en linea.
            avisoSeleccion.classList.remove('oculto');
            avisoSeleccion.scrollIntoView({ block: 'nearest' });
            return;
        }

        enCurso = true;
        btnFacturar.disabled = true;
        progresoContainer.classList.add('progreso-emision--activo');
        progresoTexto.textContent = 'Procesando: 0 de ' + ids.length;
        progresoBarra.style.width = '0%';

        pedirSubLote(ids, form.querySelector('[name=csrf_token]').value);
    });
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
