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
 * REDISENO (2026-11-18): misma familia visual que el panel de emision y los
 * correos. $resumen (puede ser null si su consulta falla) trae cuantas
 * cotizaciones VIGENTES hay por estado y cuanto suman; las tarjetas son ademas
 * el filtro por estado. "Facturar" se ofrece en la fila bajo la misma regla que
 * el detalle: vigente y no facturada por completo. Es un enlace, no emite nada.
 *
 * ESPERA AL FILTRAR (seccion 14, Efectos de carga): mismo patron que
 * pagos-config.php. Ver el script.
 */
$titulo    = 'Cotizaciones';
$bodyClase = 'pagina-cotizaciones';
require __DIR__ . '/partials/header.php';

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

/* Estado de la cotizacion: tono + icono + texto (WCAG 1.4.1). Mismos tonos que
   el resto del panel: en curso azul, a medias ambar, cerrada verde. */
$estados = [
    'sin_facturar' => ['proceso', 'reloj',  'Sin facturar',        'Esperan respuesta o factura.'],
    'parcial'      => ['alerta',  'alerta', 'Facturada en parte',  'Tienen saldo por facturar.'],
    'facturada'    => ['exito',   'check',  'Facturada',           'Cerradas por completo.'],
];
$monto = static fn ($n): string => '$' . number_format((float) $n, 0, ',', '.');
/** Fecha corta chilena dd/mm/aaaa. */
$fecha = static function ($valor) use ($e): string {
    $ts = strtotime((string) $valor);
    return $ts === false ? $e((string) $valor) : date('d/m/Y', $ts);
};
/**
 * Vigencia de una cotizacion abierta: [texto, tono] o null si no aplica (sin
 * fecha de validez, facturada o anulada).
 */
$vigencia = static function (array $c): ?array {
    if (empty($c['valida_hasta']) || empty($c['activo']) || $c['estado_cache'] === 'facturada') {
        return null;
    }
    $hoy  = new DateTimeImmutable('today');
    $hast = DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) $c['valida_hasta'], 0, 10));
    if ($hast === false) {
        return null;
    }
    $dias = (int) $hoy->diff($hast)->format('%r%a');
    return match (true) {
        $dias < 0   => ['Vencida', 'error'],
        $dias === 0 => ['Vence hoy', 'alerta'],
        $dias <= 7  => ['Vence en ' . $dias . ' dia' . ($dias === 1 ? '' : 's'), 'alerta'],
        default     => ['Vigente hasta ' . $hast->format('d/m/Y'), 'neutro'],
    };
};
$avatar = static function (string $razon, string $rut): array {
    $ini = '';
    foreach (array_slice(preg_split('/\s+/', trim($razon)) ?: [], 0, 2) as $p) {
        if ($p !== '' && preg_match('/^[\p{L}\p{N}]/u', $p) === 1) {
            $ini .= mb_strtoupper(mb_substr($p, 0, 1));
        }
    }
    return [$ini, abs(crc32($rut)) % 6];
};

$hayFiltros = trim((string) $q) !== '' || (string) $estado !== '' || ! empty($incluirInactivas);
$flashError = ! empty($flash['mensaje']) && ! in_array($flash['tipo'] ?? '', ['exito', 'advertencia'], true);
$flashClase = ($flash['tipo'] ?? '') === 'advertencia' ? 'advertencia' : ($flashError ? 'error' : 'exito');

/** Url del listado con el estado dado, conservando busqueda y anuladas. */
$urlEstado = static function (string $est) use ($q, $incluirInactivas): string {
    $qs = http_build_query(array_filter([
        'q' => (string) $q, 'estado' => $est, 'inactivas' => $incluirInactivas ? '1' : '',
    ], static fn ($v) => $v !== ''));
    return '/ventas/cotizaciones' . ($qs !== '' ? '?' . $qs : '');
};
$resumen = $resumen ?? null;
$totalVigentes = $resumen !== null ? array_sum(array_column($resumen, 'n')) : 0;
$montoAbierto  = $resumen !== null ? ($resumen['sin_facturar']['monto'] ?? 0) + ($resumen['parcial']['monto'] ?? 0) : 0;
?>

<div class="pe-cabecera">
    <div class="pe-cabecera__titulo">
        <span class="pe-cabecera__emblema" aria-hidden="true"><?= iconoSvg('informe-detalle', 24, ''); ?></span>
        <div>
            <h1>Cotizaciones</h1>
            <p class="pe-cabecera__sub">Documento interno para tus clientes: no pasa por el SII y no consume folios.</p>
        </div>
    </div>
    <div class="pe-cabecera__acciones">
        <a class="boton-principal pe-boton" href="/ventas/cotizaciones/nueva"><?= iconoSvg('agregar', 16, ''); ?><span>Crear cotizacion</span></a>
    </div>
</div>

<?php if (! empty($flash['mensaje'])): ?>
    <p class="alerta alerta--<?= $flashClase; ?>" role="<?= $flashError ? 'alert' : 'status'; ?>">
        <?= iconoSvg($flashClase === 'exito' ? 'check' : 'alerta', 20, 'alerta__icono'); ?>
        <span><?= $e((string) $flash['mensaje']); ?></span>
    </p>
<?php endif; ?>

<?php if ($resumen !== null && $totalVigentes > 0): ?>
    <?php // Resumen de la cartera vigente: tarjetas que tambien filtran por estado. ?>
    <nav class="co-kpis ct-kpis" aria-label="Filtrar cotizaciones por estado">
        <a class="co-kpi co-kpi--todos" href="<?= $e($urlEstado('')); ?>"<?= (string) $estado === '' ? ' aria-current="true"' : ''; ?>>
            <span class="co-kpi__icono" aria-hidden="true"><?= iconoSvg('informe-detalle', 18, ''); ?></span>
            <span class="co-kpi__etiqueta">Vigentes</span>
            <span class="co-kpi__valor"><?= $totalVigentes; ?></span>
            <span class="co-kpi__nota"><?= $monto($montoAbierto); ?> por facturar</span>
        </a>
        <?php foreach ($estados as $clave => [$tono, $icono, $rotulo, $nota]): ?>
            <?php $n = (int) ($resumen[$clave]['n'] ?? 0); ?>
            <a class="co-kpi co-tono--<?= $tono; ?>" href="<?= $e($urlEstado($clave)); ?>"<?= $estado === $clave ? ' aria-current="true"' : ''; ?>
               aria-label="<?= $e($rotulo); ?>: <?= $n; ?>. Ver solo estas cotizaciones">
                <span class="co-kpi__icono" aria-hidden="true"><?= iconoSvg($icono, 18, ''); ?></span>
                <span class="co-kpi__etiqueta"><?= $e($rotulo); ?></span>
                <span class="co-kpi__valor"><?= $n; ?></span>
                <span class="co-kpi__nota"><?= $n > 0 ? $monto($resumen[$clave]['monto'] ?? 0) : $e($nota); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>

<form method="get" action="/ventas/cotizaciones" class="filtros ct-filtros" id="form-filtros" aria-label="Filtrar cotizaciones" data-espera>
    <label class="filtros__campo ct-filtros__buscar">Buscar
        <span class="ct-buscar">
            <?= iconoSvg('buscar', 18, 'ct-buscar__icono'); ?>
            <input type="search" name="q" class="filtros__input" value="<?= $e((string) $q); ?>"
                   placeholder="Numero, RUT o razon social">
        </span>
    </label>
    <label class="filtros__campo">Estado
        <select name="estado" class="filtros__input filtros__input--medio">
            <option value="">Todos</option>
            <?php foreach ($estados as $k => [, , $et]): ?>
                <option value="<?= $k; ?>"<?= $estado === $k ? ' selected' : ''; ?>><?= $et; ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="filtros__campo ct-filtros__check">
        <input type="checkbox" name="inactivas" value="1"<?= $incluirInactivas ? ' checked' : ''; ?>>
        Incluir anuladas
    </label>
    <div class="ct-filtros__acciones">
        <button type="submit" class="boton-principal pe-boton"><?= iconoSvg('buscar', 16, ''); ?><span>Filtrar</span></button>
        <?php if ($hayFiltros): ?>
            <a class="boton-texto" href="/ventas/cotizaciones">Limpiar filtros</a>
        <?php endif; ?>
    </div>
</form>

<?php if ($cotizaciones === []): ?>
    <div class="estado-vacio estado-vacio--centrado">
        <?= iconoSvg('informe-detalle', 32, 'estado-vacio__icono'); ?>
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
    <section class="tarjeta pe-tabla" aria-labelledby="titulo-cotizaciones">
        <div class="pe-tabla__cabecera">
            <h2 id="titulo-cotizaciones"><?= $estado !== null && $estado !== '' ? $e($estados[$estado][2] ?? 'Cotizaciones') : 'Cotizaciones'; ?></h2>
            <span class="pe-tabla__total"><?= (int) $total; ?> cotizacion<?= (int) $total === 1 ? '' : 'es'; ?><?= $hayFiltros ? ' con los filtros aplicados' : ''; ?><?php
                if ($totalPaginas > 1): ?> &middot; pagina <?= (int) $pagina; ?> de <?= (int) $totalPaginas; ?><?php endif; ?></span>
        </div>
        <div class="tabla-scroll">
            <table class="tabla-datos tabla-datos--cards tabla-datos--cards-auto tabla-documentos">
                <caption class="visualmente-oculto">
                    <?= (int) $total; ?> cotizacion<?= (int) $total === 1 ? '' : 'es'; ?><?= $hayFiltros ? ' con los filtros aplicados' : ''; ?>
                </caption>
                <thead>
                    <tr>
                        <th scope="col">Cotizacion</th>
                        <th scope="col">Fecha</th>
                        <th scope="col">Cliente</th>
                        <th scope="col" class="tabla-datos__num">Total</th>
                        <th scope="col" class="tabla-datos__estado">Estado</th>
                        <th scope="col" class="tabla-datos__acciones"><span class="visualmente-oculto">Acciones</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cotizaciones as $c): ?>
                        <?php
                            $est     = (string) $c['estado_cache'];
                            [$tono, $icono, $texto] = $estados[$est] ?? ['neutro', 'sin-cargar', $est];
                            $numero  = (int) $c['numero'];
                            $id      = (int) $c['id'];
                            $anulada = empty($c['activo']);
                            $vig     = $vigencia($c);
                            $razon   = (string) $c['receptor_razon_social'];
                            [$ini, $tonoAv] = $avatar($razon, (string) $c['receptor_rut']);
                            $facturable = ! $anulada && $est !== 'facturada';
                        ?>
                        <tr<?= $anulada ? ' class="tabla-datos__fila--inactiva"' : ''; ?>>
                            <td data-label="Cotizacion">
                                <div class="pe-doc">
                                    <span class="pe-doc__icono" aria-hidden="true"><?= iconoSvg('informe-detalle', 18, ''); ?></span>
                                    <div class="pe-doc__texto">
                                        <a class="pe-doc__folio" href="/ventas/cotizaciones/<?= $id; ?>" aria-label="Ver la cotizacion N <?= $numero; ?>">N&deg; <?= $numero; ?></a>
                                        <?php // Anulada se dice en texto, no solo con la fila atenuada. ?>
                                        <span class="pe-doc__tipo"><?= $anulada ? 'Anulada' : 'Cotizacion'; ?></span>
                                    </div>
                                </div>
                            </td>
                            <td data-label="Fecha" class="tabla-datos__cifra">
                                <span class="pe-fecha"><?= iconoSvg('calendario', 14, ''); ?><?= $fecha($c['fecha']); ?></span>
                                <?php if ($vig !== null): ?>
                                    <span class="ct-vigencia ct-vigencia--<?= $vig[1]; ?>"><?= $e($vig[0]); ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Cliente">
                                <div class="pe-receptor">
                                    <span class="pe-avatar pe-avatar--<?= $tonoAv; ?>" aria-hidden="true"><?= $ini !== '' ? $e($ini) : iconoSvg('clientes', 16, ''); ?></span>
                                    <div class="pe-receptor__texto">
                                        <span class="pe-receptor__nombre"><?= $e($razon); ?></span>
                                        <span class="pe-receptor__rut"><?= $e((string) $c['receptor_rut']); ?></span>
                                    </div>
                                </div>
                            </td>
                            <td data-label="Total" class="tabla-datos__num"><span class="tabla-datos__num--destacado"><?= $monto($c['total_estimado']); ?></span></td>
                            <td data-label="Estado" class="tabla-datos__estado">
                                <span class="pe-estado pe-estado--<?= $tono; ?> co-estado"><?= iconoSvg($icono, 14, 'pe-estado__icono'); ?><span><?= $e($texto); ?></span></span>
                            </td>
                            <td data-label="" class="tabla-datos__acciones">
                                <div class="pe-acciones">
                                    <?php if ($facturable): ?>
                                        <?php /* Enlace, no POST: abre el formulario de emision precargado. */ ?>
                                        <a class="co-accion ct-facturar" href="/ventas/cotizaciones/<?= $id; ?>/facturar"
                                           aria-label="Facturar la cotizacion N <?= $numero; ?>"><?= iconoSvg('factura', 15, ''); ?><span>Facturar</span></a>
                                    <?php endif; ?>
                                    <a class="pe-icono-accion" href="/ventas/cotizaciones/<?= $id; ?>/pdf" target="_blank" rel="noopener" title="Abrir PDF"
                                       aria-label="Abrir el PDF de la cotizacion N <?= $numero; ?> (se abre en otra pestana)"><?= iconoSvg('descargar', 17, ''); ?></a>
                                    <a class="pe-icono-accion pe-icono-accion--ver" href="/ventas/cotizaciones/<?= $id; ?>" title="Ver cotizacion"
                                       aria-label="Ver la cotizacion N <?= $numero; ?>"><?= iconoSvg('chevron-der', 18, ''); ?></a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($totalPaginas > 1): ?>
        <nav class="paginacion pe-paginacion ct-paginacion" aria-label="Paginas de cotizaciones">
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
