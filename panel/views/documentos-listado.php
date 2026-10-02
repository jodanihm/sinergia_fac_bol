<?php
/**
 * Panel de emision (M5): listado de documentos emitidos en produccion.
 *
 * TAREA DE LA PANTALLA, EN UNA FRASE: encontrar un documento emitido y ver de
 * un vistazo si el SII ya lo recibio, para abrir su ficha.
 *
 * Recibe: $items, $total, $pagina, $totalPaginas, $filtros, $errorMotor,
 * $navActivo. Todo viene de handleDocumentosListadoGet(), que consulta
 * GET /api/v1/dte del motor -- el panel NUNCA lee dte_emitido directo.
 *
 * Cada item trae EXACTAMENTE los 11 campos de listarPorPeriodo() mas
 * receptorRazonSocial que agrega resolverRazonSocialReceptores():
 *   folio, tipoDte, fechaEmision, receptorRut, neto, iva, total, estado,
 *   trackId, folioRef, tipoDteRef, receptorRazonSocial.
 * trackId, folioRef, tipoDteRef y receptorRazonSocial pueden ser null.
 *
 * PERIODO POR DEFECTO: si no se manda desde/hasta ni folio, el motor acota al
 * MES ACTUAL (public/index.php, listarDte()). No es un listado historico. La
 * vista lo dice explicitamente cuando no hay filtro de fechas, porque si no un
 * tenant con documentos de meses anteriores ve "no hay documentos" y concluye
 * que se perdieron.
 *
 * LO QUE NO SE MUESTRA:
 *   - Monto exento: dte_emitido no lo guarda.
 *   - Track ID: existe en cada item, pero son 11 digitos que ensancharian la
 *     tabla sin que se usen desde aqui. Esta en la ficha del documento.
 *   - Referencia (folioRef/tipoDteRef): idem, vive en la ficha.
 * Ninguno se agrega ni se deriva.
 *
 * FILTROS ACTIVOS A LA VISTA (heuristica 6, reconocer antes que recordar):
 * bajo la barra de filtros, cada filtro aplicado sale como un chip que se
 * quita con un clic. Quitar el rango de fechas quita "desde" y "hasta" juntos,
 * igual que filtrosDocumentosDesdeGet(), que descarta uno suelto.
 *
 * CINCO COLUMNAS Y NO NUEVE: neto e IVA van como linea secundaria bajo el
 * total, y el enlace a la ficha es el propio folio (antes, una columna
 * "Acciones" con un unico "Ver detalle").
 * Con el menu lateral, a 900px de pantalla la tabla tiene ~560px y ocho
 * columnas obligaban a desplazarse hacia el lado; el total, que es lo que se
 * compara de un vistazo, sigue en su columna alineado a la derecha.
 *
 * EN MOVIL LA TABLA PASA A TARJETAS (.tabla-datos--cards): cada documento se
 * lee de arriba abajo con el nombre de la columna como titulo de cada linea.
 *
 * ESPERA AL FILTRAR (seccion 14, Efectos de carga): filtrar recarga la pagina.
 * Si tarda mas de 300 ms el boton "Filtrar documentos" muestra su spinner sin
 * cambiar de ancho; antes de eso no se muestra nada.
 */
$titulo = 'Panel de emision';
require __DIR__ . '/partials/header.php';

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

/**
 * Badge del estado. Identico al de documento-detalle.php. El estado NO tiene
 * catalogo cerrado: el motor escribe 'enviado' al emitir y luego guarda tal cual
 * el <ESTADO> del SII (EPR, RCT, RCH...). Solo se colorean los tres valores que
 * produce el propio codigo; cualquier codigo del SII va en neutro y con su valor
 * crudo, sin traducir. Cada variante lleva ademas su icono: el estado no se
 * comunica solo por color (WCAG 1.4.1).
 *
 * PENDIENTE: $badgeEstado sigue duplicado en las dos vistas. Extraerlo a un
 * partial compartido queda para cuando se toquen ambas juntas con un partial
 * nuevo (fuera del alcance de esta pasada).
 *
 * @return array{0:string,1:string,2:string} clase, texto, icono
 */
$badgeEstado = static function (?string $estado): array {
    $e = trim((string) $estado);
    if ($e === '') {
        return ['badge--neutro', 'Sin estado', 'sin-cargar'];
    }
    return match ($e) {
        'enviado'     => ['badge--proceso', 'Enviado al SII', 'reloj'],
        'sin_trackid' => ['badge--advertencia', 'Sin track ID', 'alerta'],
        'desconocido' => ['badge--neutro', 'Desconocido', 'sin-cargar'],
        default       => ['badge--neutro', $e, 'info'],
    };
};

// Query string que arrastra los filtros al cambiar de pagina. Se conserva tal
// cual estaba: si se pierde, la paginacion navega sobre otro conjunto.
$qs = '';
foreach ($filtros as $campo => $valor) {
    $qs .= '&' . $campo . '=' . urlencode((string) $valor);
}

/** Monto en pesos chilenos: $25.000, sin decimales ni espacio. */
$fmtMonto = static function ($v): string {
    return $v === null ? '-' : '$' . number_format((float) $v, 0, ',', '.');
};
/** Fecha corta chilena (dd/mm/aaaa). Si no se puede leer, se muestra tal cual. */
$fmtFecha = static function ($v) use ($e): string {
    $s  = (string) $v;
    $ts = $s !== '' ? strtotime($s) : false;
    return $ts === false ? $e($s) : date('d/m/Y', $ts);
};

$hayFiltros     = $filtros !== [];
// desde/hasta viajan siempre juntos (filtrosDocumentosDesdeGet descarta uno
// suelto), asi que basta mirar uno para saber si el rango esta acotado.
$hayRangoFechas = isset($filtros['desde']);
$hayFolio       = isset($filtros['folio']);

/** Url del listado sin los filtros indicados (para el chip que los quita). */
$sinFiltros = static function (array $quitar) use ($filtros): string {
    $resto = array_diff_key($filtros, array_flip($quitar));
    return '/ventas/panel-emision' . ($resto === [] ? '' : '?' . http_build_query($resto));
};

// Los chips de filtros activos: [texto visible, campos que quita].
$chips = [];
if ($hayRangoFechas) {
    $chips[] = ['Fechas: ' . $fmtFecha($filtros['desde']) . ' a ' . $fmtFecha($filtros['hasta'] ?? ''), ['desde', 'hasta']];
}
if (isset($filtros['tipoDte'])) {
    $chips[] = ['Tipo: ' . $e(catalogoTiposDte()[(int) $filtros['tipoDte']] ?? (string) $filtros['tipoDte']), ['tipoDte']];
}
if ($hayFolio) {
    $chips[] = ['Folio: ' . $e((string) $filtros['folio']), ['folio']];
}
if (isset($filtros['receptorRut'])) {
    $chips[] = ['RUT receptor: ' . $e((string) $filtros['receptorRut']), ['receptorRut']];
}
if (isset($filtros['estado'])) {
    $chips[] = ['Estado: ' . $e((string) $filtros['estado']), ['estado']];
}
?>

<div class="dash-header">
    <div>
        <h1>Panel de emision</h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <a class="boton-secundario" href="/ventas/nota-credito">Nota de credito</a>
        <a class="boton-secundario" href="/ventas/nota-debito">Nota de debito</a>
        <a class="boton-principal" href="/ventas/factura">Emitir factura</a>
    </div>
</div>
<p class="dash-subtitulo">
    Busca un documento emitido, revisa si el SII ya lo recibio y abre su ficha para descargarlo.
</p>

<?php if (! empty($errorMotor)): ?>
    <div class="alerta alerta--error" role="alert">
        <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
        <div class="alerta__cuerpo">
            <p class="alerta__titulo">No pudimos traer tus documentos</p>
            <p><?= $e((string) $errorMotor); ?> Vuelve a intentarlo en un momento.</p>
        </div>
    </div>
<?php endif; ?>

<form method="get" action="/ventas/panel-emision" class="filtros" id="form-filtros" aria-label="Filtrar documentos">
    <label class="filtros__campo">Desde
        <input type="date" name="desde" class="filtros__input filtros__input--medio" value="<?= $e((string) ($filtros['desde'] ?? '')); ?>">
    </label>
    <label class="filtros__campo">Hasta
        <input type="date" name="hasta" class="filtros__input filtros__input--medio" value="<?= $e((string) ($filtros['hasta'] ?? '')); ?>">
    </label>
    <label class="filtros__campo">Tipo
        <select name="tipoDte" class="filtros__input filtros__input--medio">
            <option value="">Todos</option>
            <?php /* QUINTO CATALOGO, que no estaba en el inventario: este filtro
                     tambien RECORRE la lista en vez de consultarla, asi que va
                     por catalogoTiposDte() y no por TipoDte::cases(). Ademas
                     calza exactamente con lo que el motor acepta en su filtro
                     ?tipoDte= (TIPOS_PERMITIDOS_LISTADO = 33, 34, 61, 56, 39):
                     ofrecer aqui una guia de despacho daria un 422 del motor. */ ?>
            <?php foreach (catalogoTiposDte() as $t => $n): ?>
                <option value="<?= $t; ?>" <?= (string) ($filtros['tipoDte'] ?? '') === (string) $t ? 'selected' : ''; ?>><?= $e((string) $n); ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="filtros__campo">Folio
        <input type="text" inputmode="numeric" name="folio" class="filtros__input filtros__input--corto" value="<?= $e((string) ($filtros['folio'] ?? '')); ?>">
    </label>
    <label class="filtros__campo">RUT receptor
        <input type="text" name="receptorRut" class="filtros__input filtros__input--medio" value="<?= $e((string) ($filtros['receptorRut'] ?? '')); ?>"
               placeholder="77724622-4" spellcheck="false">
    </label>
    <label class="filtros__campo">Estado
        <input type="text" name="estado" class="filtros__input filtros__input--medio" value="<?= $e((string) ($filtros['estado'] ?? '')); ?>"
               placeholder="enviado, EPR" spellcheck="false" aria-describedby="ayuda-filtro-estado">
    </label>
    <span class="visualmente-oculto" id="ayuda-filtro-estado">El estado tal como lo guarda el sistema: enviado, o el codigo que respondio el SII.</span>
    <button type="submit" class="boton-secundario" id="filtrar">Filtrar documentos</button>
    <?php if ($hayFiltros): ?>
        <a class="boton-texto" href="/ventas/panel-emision">Limpiar filtros</a>
    <?php endif; ?>
</form>

<?php if ($chips !== [] || (! $hayRangoFechas && ! $hayFolio)): ?>
    <div class="filtros-activos">
        <?php if (! $hayRangoFechas && ! $hayFolio): ?>
            <span class="filtros-activos__periodo">
                <?= iconoSvg('info', 16, ''); ?>
                Mostrando el mes actual (<?= date('m/Y'); ?>). Para otro periodo usa Desde y Hasta, o busca por folio.
            </span>
        <?php endif; ?>
        <?php if ($chips !== []): ?>
            <span class="filtros-activos__rotulo">Filtrando por:</span>
            <ul class="filtros-activos__lista">
                <?php foreach ($chips as [$texto, $quitar]): ?>
                    <li>
                        <a class="chip" href="<?= $e($sinFiltros($quitar)); ?>" aria-label="Quitar filtro <?= $texto; ?>">
                            <span><?= $texto; ?></span><?= iconoSvg('cerrar', 16, 'chip__icono'); ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($items === []): ?>
    <div class="estado-vacio estado-vacio--centrado">
        <?= iconoSvg('panel-emision', 32, 'estado-vacio__icono'); ?>
        <?php if ($hayFiltros): ?>
            <h2 class="estado-vacio__titulo">Ningun documento coincide con estos filtros</h2>
            <p>Prueba con otro rango de fechas, otro tipo o quita alguno de los filtros de arriba.</p>
            <p class="estado-vacio__acciones">
                <a class="boton-secundario" href="/ventas/panel-emision">Limpiar filtros</a>
            </p>
        <?php else: ?>
            <h2 class="estado-vacio__titulo">Todavia no emites documentos este mes</h2>
            <p>Cuando emitas una factura, boleta o nota, aparecera aqui con su estado en el SII. Si buscas uno anterior, filtra por fechas o por folio.</p>
            <p class="estado-vacio__acciones">
                <a class="boton-secundario" href="/ventas/factura">Emitir una factura</a>
            </p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <section class="tarjeta" aria-labelledby="titulo-documentos">
        <h2 id="titulo-documentos">Documentos</h2>
        <div class="tabla-scroll">
            <table class="tabla-datos tabla-datos--cards tabla-documentos">
                <caption>
                    <?= $total; ?> documento<?= $total === 1 ? '' : 's'; ?><?= $hayFiltros ? ' con los filtros aplicados' : ''; ?><?php
                    if ($totalPaginas > 1): ?> &middot; pagina <?= $pagina; ?> de <?= $totalPaginas; ?><?php endif; ?>
                </caption>
                <thead>
                    <tr>
                        <th scope="col">Documento</th>
                        <th scope="col">Fecha</th>
                        <th scope="col">Receptor</th>
                        <th scope="col" class="tabla-datos__num">Total</th>
                        <th scope="col" class="tabla-datos__estado">Estado SII</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $it): ?>
                        <?php
                            $tipoDte    = (int) $it['tipoDte'];
                            $folio      = (int) $it['folio'];
                            $nombreTipo = \Plantiflex\FacturacionCl\Enums\TipoDte::nombreDe($tipoDte);
                            [$claseBadge, $textoBadge, $iconoBadge] = $badgeEstado($it['estado'] ?? null);
                            $razon = $it['receptorRazonSocial'] ?? null;
                        ?>
                        <tr>
                            <td data-label="Documento">
                                <span class="badge badge--etiqueta"><?= $e($nombreTipo); ?></span>
                                <a class="tabla-datos__secundario tabla-datos__cifra" href="/ventas/panel-emision/<?= $tipoDte; ?>/<?= $folio; ?>"
                                   aria-label="Ver la ficha de <?= $e($nombreTipo); ?> folio <?= $folio; ?>">Folio <?= $folio; ?></a>
                            </td>
                            <td data-label="Fecha" class="tabla-datos__cifra"><?= $fmtFecha($it['fechaEmision'] ?? ''); ?></td>
                            <td data-label="Receptor">
                                <?php if (! empty($razon)): ?>
                                    <?= $e((string) $razon); ?>
                                    <span class="tabla-datos__secundario"><?= $e((string) $it['receptorRut']); ?></span>
                                <?php else: ?>
                                    <?= $e((string) $it['receptorRut']); ?>
                                <?php endif; ?>
                            </td>
                            <td data-label="Total" class="tabla-datos__num">
                                <span class="tabla-datos__num--destacado"><?= $fmtMonto($it['total'] ?? null); ?></span>
                                <span class="tabla-datos__secundario">Neto <?= $fmtMonto($it['neto'] ?? null); ?> &middot; IVA <?= $fmtMonto($it['iva'] ?? null); ?></span>
                            </td>
                            <td data-label="Estado SII" class="tabla-datos__estado">
                                <span class="badge <?= $claseBadge; ?>"><?= iconoSvg($iconoBadge, 16, 'badge__icono'); ?><?= $e($textoBadge); ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($totalPaginas > 1): ?>
        <nav class="paginacion" aria-label="Paginas de documentos">
            <?php if ($pagina > 1): ?>
                <a class="boton-secundario" href="/ventas/panel-emision?pagina=<?= $pagina - 1; ?><?= $e($qs); ?>">Pagina anterior</a>
            <?php endif; ?>
            <span class="nota" aria-current="page">Pagina <?= $pagina; ?> de <?= $totalPaginas; ?> (<?= $total; ?> documentos)</span>
            <?php if ($pagina < $totalPaginas): ?>
                <a class="boton-secundario" href="/ventas/panel-emision?pagina=<?= $pagina + 1; ?><?= $e($qs); ?>">Pagina siguiente</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<script>
(function () {
    /* ESPERA AL FILTRAR -- seccion 14 del design system, Efectos de carga.
       Mismo patron que pagos-config.php: el doble envio se corta en el acto
       sin nada visible; a los 300 ms el boton muestra su spinner sin cambiar
       de ancho y la zona se marca aria-busy; el foco no se mueve. */
    var form = document.getElementById('form-filtros');
    var boton = document.getElementById('filtrar');
    if (!form || !boton) { return; }
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
        enviando = true;
        umbral = setTimeout(function () { ocupado(true); }, 300);
    });
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
