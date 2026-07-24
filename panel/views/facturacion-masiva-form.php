<?php
$titulo = 'Facturacion masiva';
require __DIR__ . '/partials/header.php';

$fmtMonto = static function ($v): string {
    return $v === null ? '-' : number_format((float) $v, 0, ',', '.');
};
?>

<h1>Facturacion masiva</h1>

<?php if (! empty($flash['mensaje'])): ?>
    <p style="padding:0.5rem 0.75rem;border-radius:4px;<?= ($flash['tipo'] ?? '') === 'exito'
        ? 'background:#f1f8f1;color:#2e7d32;border:1px solid #2e7d32;'
        : 'background:#fdecea;color:#b00020;border:1px solid #b00020;'; ?>">
        <?= htmlspecialchars($flash['mensaje']); ?>
    </p>
<?php endif; ?>

<?php if ($resultado !== []): ?>
    <h2>Resultado de la ultima facturacion</h2>
    <div class="tabla-scroll">
        <table class="tabla-notas-venta">
            <thead>
                <tr><th>Identificador</th><th>Receptor</th><th>Estado</th><th>Detalle</th></tr>
            </thead>
            <tbody>
                <?php foreach ($resultado as $n): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($n['identificador_externo'] ?? '-')); ?></td>
                        <td><?= htmlspecialchars((string) ($n['receptor_rut'] ?? '-')); ?></td>
                        <td><?= htmlspecialchars((string) $n['estado']); ?></td>
                        <td>
                            <?php if ($n['estado'] === 'error' && ! empty($n['error_mensaje'])): ?>
                                <span style="color:#b00020;"><?= htmlspecialchars((string) $n['error_mensaje']); ?></span>
                            <?php elseif ($n['estado'] === 'facturada' && ! empty($n['resultado_documentos'])): ?>
                                <?php foreach ((json_decode((string) $n['resultado_documentos'], true) ?: []) as $doc): ?>
                                    <?= htmlspecialchars(nombreTipoDte((int) ($doc['tipoDte'] ?? 0))); ?>
                                    folio <?= htmlspecialchars((string) ($doc['folio'] ?? '-')); ?><br>
                                <?php endforeach; ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<h2>Notas pendientes de facturar</h2>

<form method="get" action="/ventas/facturacion-masiva" style="display:flex;gap:0.5rem;align-items:center;margin:0.75rem 0;">
    <input type="text" name="rut" value="<?= htmlspecialchars($rutFiltro); ?>" placeholder="Filtrar por RUT del receptor">
    <button type="submit" style="margin:0;">Filtrar</button>
</form>

<p>
    Folios disponibles: <strong><?= $foliosFactura; ?></strong> factura(s),
    <strong><?= $foliosNc; ?></strong> nota(s) de credito.
    El envio se hace en sub-lotes de <?= $subLoteTamano; ?> documentos.
</p>

<?php if ($pendientes === []): ?>
    <p>No hay notas pendientes<?= $rutFiltro !== '' ? ' que coincidan con el filtro' : ''; ?>.</p>
<?php else: ?>
    <form id="form-facturacion-masiva">
        <?= csrfInput(); ?>
        <div class="tabla-scroll">
            <table class="tabla-notas-venta">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="marcar-todas"></th>
                        <th>Identificador</th><th>Receptor</th><th>Fecha nota</th>
                        <th>Monto est.</th><th>Anula boleta</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendientes as $n): ?>
                        <tr>
                            <td><input type="checkbox" class="nota-checkbox" name="notas[]" value="<?= (int) $n['id']; ?>"></td>
                            <td><?= htmlspecialchars((string) $n['identificador_externo']); ?></td>
                            <td>
                                <?= htmlspecialchars((string) $n['receptor_rut']); ?>
                                <br><small><?= htmlspecialchars((string) $n['receptor_razon_social']); ?></small>
                            </td>
                            <td><?= htmlspecialchars((string) $n['fecha_nota']); ?></td>
                            <td><?= $fmtMonto($n['monto_estimado']); ?></td>
                            <td><?= ! empty($n['boleta_ref_folio']) ? 'Folio ' . (int) $n['boleta_ref_folio'] : '-'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p style="margin-top:1rem;">
            <button type="submit" id="btn-facturar">Facturar seleccionadas</button>
        </p>

        <div id="progreso-container" style="display:none;margin-top:1rem;">
            <p id="progreso-texto">Procesando...</p>
            <div style="background:#eee;border-radius:4px;height:0.75rem;overflow:hidden;">
                <div id="progreso-barra" style="background:#1a56db;height:100%;width:0%;"></div>
            </div>
        </div>
    </form>
<?php endif; ?>

<p style="margin-top:1.25rem;"><a href="/ventas/carga-masiva">Volver a carga masiva</a></p>

<script>
(function () {
    var form = document.getElementById('form-facturacion-masiva');
    if (!form) { return; }

    var marcarTodas = document.getElementById('marcar-todas');
    marcarTodas.addEventListener('change', function () {
        document.querySelectorAll('.nota-checkbox').forEach(function (cb) { cb.checked = marcarTodas.checked; });
    });

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
            alert('Selecciona al menos una nota.');
            return;
        }

        enCurso = true;
        btnFacturar.disabled = true;
        progresoContainer.style.display = 'block';
        progresoTexto.textContent = 'Procesando: 0 de ' + ids.length;
        progresoBarra.style.width = '0%';

        pedirSubLote(ids, form.querySelector('[name=csrf_token]').value);
    });
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
