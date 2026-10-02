<?php
/**
 * Ventas > Cotizaciones (listado).
 *
 * TAREA DE LA PANTALLA, EN UNA FRASE: encontrar una cotizacion (por numero,
 * RUT o cliente) para verla, mandar su PDF o facturarla -- o crear una nueva.
 *
 * Recibe: $cotizaciones, $q, $estado, $incluirInactivas, $pagina,
 * $totalPaginas, $total, $flash, $navActivo.
 *
 * EL FILTRO POR ESTADO USA estado_cache, y es el unico sitio donde ese cache se
 * usa: existe justamente para poder filtrar con indice (ix_cotizacion_estado).
 * Para decidir si una cotizacion SE PUEDE EDITAR no se mira este badge sino las
 * cantidades -- eso lo resuelve el detalle, con tieneFacturacion().
 *
 * DOS VACIOS DISTINTOS (design system, Feedback): sin cotizaciones todavia
 * (invita a crear la primera) y sin resultados para el filtro (invita a
 * limpiarlo). Se distinguen por si hay algun filtro aplicado.
 *
 * A TODO EL ANCHO: la tabla ocupa el <main> entero y bajo 720px pasa a
 * tarjetas (.tabla-datos--cards con data-label en cada celda).
 *
 * ESPERA AL FILTRAR (seccion 14, Efectos de carga): mismo patron que
 * pagos-config.php. Ver el script.
 */
$titulo = 'Cotizaciones';
require __DIR__ . '/partials/header.php';

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

/** Estado de la cotizacion: color + icono + texto (WCAG 1.4.1). */
$badge = static function (string $estado): array {
    return match ($estado) {
        'sin_facturar' => ['badge--neutro', 'Sin facturar', 'sin-cargar'],
        'parcial'      => ['badge--advertencia', 'Facturada en parte', 'reloj'],
        'facturada'    => ['badge--ok', 'Facturada', 'check'],
        default        => ['badge--neutro', $estado, 'sin-cargar'],
    };
};
$monto = static fn ($n): string => '$' . number_format((float) $n, 0, ',', '.');
/** Fecha corta chilena dd/mm/aaaa. */
$fecha = static function ($valor) use ($e): string {
    $ts = strtotime((string) $valor);
    return $ts === false ? $e((string) $valor) : date('d/m/Y', $ts);
};

$hayFiltros = trim((string) $q) !== '' || (string) $estado !== '' || ! empty($incluirInactivas);
$flashError = ! empty($flash['mensaje']) && ! in_array($flash['tipo'] ?? '', ['exito', 'advertencia'], true);
$flashClase = ($flash['tipo'] ?? '') === 'advertencia' ? 'advertencia' : ($flashError ? 'error' : 'exito');
?>

<div class="dash-header">
    <div>
        <h1>Cotizaciones</h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <a class="boton-principal" href="/ventas/cotizaciones/nueva">Crear cotizacion</a>
    </div>
</div>
<p class="dash-subtitulo">Documento interno para tus clientes: no pasa por el SII y no consume folios.</p>

<?php if (! empty($flash['mensaje'])): ?>
    <p class="alerta alerta--<?= $flashClase; ?>" role="<?= $flashError ? 'alert' : 'status'; ?>">
        <?= iconoSvg($flashClase === 'exito' ? 'check' : 'alerta', 20, 'alerta__icono'); ?>
        <span><?= $e((string) $flash['mensaje']); ?></span>
    </p>
<?php endif; ?>

<form method="get" action="/ventas/cotizaciones" class="filtros" id="form-filtros" aria-label="Filtrar cotizaciones" data-espera>
    <label class="filtros__campo">Buscar
        <input type="search" name="q" class="filtros__input" value="<?= $e((string) $q); ?>"
               placeholder="Numero, RUT o razon social">
    </label>
    <label class="filtros__campo">Estado
        <select name="estado" class="filtros__input filtros__input--medio">
            <option value="">Todos</option>
            <?php foreach (['sin_facturar' => 'Sin facturar', 'parcial' => 'Facturada en parte', 'facturada' => 'Facturada'] as $k => $et): ?>
                <option value="<?= $k; ?>"<?= $estado === $k ? ' selected' : ''; ?>><?= $et; ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="filtros__campo">
        <input type="checkbox" name="inactivas" value="1"<?= $incluirInactivas ? ' checked' : ''; ?>>
        Incluir anuladas
    </label>
    <button type="submit" class="boton-secundario">Filtrar</button>
    <?php if ($hayFiltros): ?>
        <a class="boton-texto" href="/ventas/cotizaciones">Limpiar filtros</a>
    <?php endif; ?>
</form>

<?php if ($cotizaciones === []): ?>
    <div class="estado-vacio estado-vacio--centrado">
        <?= iconoSvg('factura', 32, 'estado-vacio__icono'); ?>
        <?php if ($hayFiltros): ?>
            <h2 class="estado-vacio__titulo">Ninguna cotizacion coincide con estos filtros</h2>
            <p>Prueba con otro numero, RUT o estado, o quita los filtros para ver todas.</p>
            <p class="estado-vacio__acciones"><a class="boton-secundario" href="/ventas/cotizaciones">Limpiar filtros</a></p>
        <?php else: ?>
            <h2 class="estado-vacio__titulo">Todavia no tienes cotizaciones</h2>
            <p>Arma una con los productos de tu catalogo y mandale el PDF a tu cliente. Despues la puedes facturar.</p>
            <p class="estado-vacio__acciones"><a class="boton-secundario" href="/ventas/cotizaciones/nueva">Crear cotizacion</a></p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="tabla-scroll">
        <table class="tabla-datos tabla-datos--cards tabla-datos--cards-auto">
            <caption>
                <?= (int) $total; ?> cotizacion<?= (int) $total === 1 ? '' : 'es'; ?><?= $hayFiltros ? ' con los filtros aplicados' : ''; ?><?php
                if ($totalPaginas > 1): ?> &middot; pagina <?= (int) $pagina; ?> de <?= (int) $totalPaginas; ?><?php endif; ?>
            </caption>
            <thead>
                <tr>
                    <th scope="col">N&deg;</th>
                    <th scope="col">Fecha</th>
                    <th scope="col">Cliente</th>
                    <th scope="col" class="tabla-datos__num">Total</th>
                    <th scope="col" class="tabla-datos__estado">Estado</th>
                    <th scope="col" class="tabla-datos__acciones">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cotizaciones as $c): ?>
                    <?php
                        [$clase, $texto, $icono] = $badge((string) $c['estado_cache']);
                        $numero  = (int) $c['numero'];
                        $id      = (int) $c['id'];
                        $anulada = empty($c['activo']);
                    ?>
                    <tr<?= $anulada ? ' class="tabla-datos__fila--inactiva"' : ''; ?>>
                        <td data-label="N&deg;">
                            <a href="/ventas/cotizaciones/<?= $id; ?>"><?= $numero; ?></a>
                            <?php // Anulada se dice en texto, no solo con la fila atenuada. ?>
                            <?php if ($anulada): ?><span class="tabla-datos__secundario">Anulada</span><?php endif; ?>
                        </td>
                        <td data-label="Fecha"><?= $fecha($c['fecha']); ?></td>
                        <td data-label="Cliente">
                            <?= $e((string) $c['receptor_razon_social']); ?>
                            <span class="tabla-datos__secundario"><?= $e((string) $c['receptor_rut']); ?></span>
                        </td>
                        <td data-label="Total" class="tabla-datos__num"><?= $monto($c['total_estimado']); ?></td>
                        <td data-label="Estado" class="tabla-datos__estado">
                            <span class="badge <?= $clase; ?>"><?= iconoSvg($icono, 16, 'badge__icono'); ?><?= $e($texto); ?></span>
                        </td>
                        <td data-label="" class="tabla-datos__acciones">
                            <a class="boton-texto" href="/ventas/cotizaciones/<?= $id; ?>"
                               aria-label="Ver la cotizacion N <?= $numero; ?>">Ver</a>
                            <a class="boton-texto" href="/ventas/cotizaciones/<?= $id; ?>/pdf" target="_blank" rel="noopener"
                               aria-label="Abrir el PDF de la cotizacion N <?= $numero; ?> (se abre en otra pestana)">PDF</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPaginas > 1): ?>
        <nav class="paginacion" aria-label="Paginas de cotizaciones">
            <?php for ($p = 1; $p <= $totalPaginas; $p++): ?>
                <?php
                $qs = http_build_query(array_filter([
                    'q' => $q, 'estado' => $estado,
                    'inactivas' => $incluirInactivas ? '1' : '', 'pagina' => $p,
                ], static fn ($v) => $v !== '' && $v !== null));
                ?>
                <a class="paginacion__item<?= $p === $pagina ? ' paginacion__item--actual' : ''; ?>"
                   href="/ventas/cotizaciones?<?= $e($qs); ?>"
                   <?= $p === $pagina ? 'aria-current="page"' : ''; ?>
                   aria-label="Pagina <?= $p; ?>"><?= $p; ?></a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<script>
(function () {
    /* ESPERA AL FILTRAR -- seccion 14 del design system, Efectos de carga.
       Mismo patron que pagos-config.php: el doble envio se corta en el acto
       sin nada visible; a los 300 ms el boton muestra su spinner sin cambiar
       de ancho, con aria-busy en el boton y en el formulario. Volver con
       "Atras" lo restaura. */
    var form = document.getElementById('form-filtros');
    if (!form) { return; }
    var boton = form.querySelector('[type="submit"]');
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
        if (ev.persisted) { clearTimeout(umbral); delete form.dataset.enviando; ocupado(false); }
    });
    form.addEventListener('submit', function (ev) {
        if (form.dataset.enviando) { ev.preventDefault(); return; }
        form.dataset.enviando = '1';
        umbral = setTimeout(function () { ocupado(true); }, 300);
    });
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
