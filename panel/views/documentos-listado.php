<?php
/**
 * Panel de emision (M5): listado de documentos emitidos en produccion.
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
 */
$titulo = 'Panel de emision';
require __DIR__ . '/partials/header.php';

// Mismo mapa que documento-detalle.php, con los MISMOS valores: el nombre de un
// documento no puede cambiar entre el listado y su ficha.
// PENDIENTE: $badgeEstado sigue duplicado en las dos vistas. Extraerlo a un
// partial compartido queda para cuando se toquen ambas juntas. El mapa de
// nombres de tipo que estaba aqui ya NO: ahora sale de TipoDte::nombreDe().

/**
 * Badge del estado. Identico al de documento-detalle.php. El estado NO tiene
 * catalogo cerrado: el motor escribe 'enviado' al emitir y luego guarda tal cual
 * el <ESTADO> del SII (EPR, RCT, RCH...). Solo se colorean los tres valores que
 * produce el propio codigo; cualquier codigo del SII va en neutro y con su valor
 * crudo, sin traducir.
 */
$badgeEstado = static function (?string $estado): array {
    $e = trim((string) $estado);
    if ($e === '') {
        return ['badge--neutro', 'Sin estado'];
    }
    return match ($e) {
        'enviado'     => ['badge--proceso', 'Enviado al SII'],
        'sin_trackid' => ['badge--advertencia', 'Sin track ID'],
        'desconocido' => ['badge--neutro', 'Desconocido'],
        default       => ['badge--neutro', $e],
    };
};

// Query string que arrastra los filtros al cambiar de pagina. Se conserva tal
// cual estaba: si se pierde, la paginacion navega sobre otro conjunto.
$qs = '';
foreach ($filtros as $campo => $valor) {
    $qs .= '&' . $campo . '=' . urlencode((string) $valor);
}

$fmtMonto = static function ($v): string {
    return $v === null ? '-' : '$ ' . number_format((float) $v, 0, ',', '.');
};

$hayFiltros     = $filtros !== [];
// desde/hasta viajan siempre juntos (filtrosDocumentosDesdeGet descarta uno
// suelto), asi que basta mirar uno para saber si el rango esta acotado.
$hayRangoFechas = isset($filtros['desde']);
$hayFolio       = isset($filtros['folio']);
?>

<div class="dash-header">
    <div>
        <h1>Panel de emision</h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <a class="boton-principal" href="/ventas/factura">Emitir factura</a>
        <a class="boton-secundario" href="/ventas/nota-credito">Nota de credito</a>
        <a class="boton-secundario" href="/ventas/nota-debito">Nota de debito</a>
    </div>
</div>
<p class="dash-subtitulo">
    Consulta los documentos emitidos, revisa su estado y accede a sus archivos.
</p>

<?php if (! empty($errorMotor)): ?>
    <p class="alerta alerta--error" role="alert">
        <span class="alerta__icono" aria-hidden="true">&#9888;</span>
        <span><?= htmlspecialchars($errorMotor); ?></span>
    </p>
<?php endif; ?>

<form method="get" action="/ventas/panel-emision" class="filtros">
    <label class="filtros__campo">Desde
        <input type="date" name="desde" class="filtros__input filtros__input--medio" value="<?= htmlspecialchars($filtros['desde'] ?? ''); ?>">
    </label>
    <label class="filtros__campo">Hasta
        <input type="date" name="hasta" class="filtros__input filtros__input--medio" value="<?= htmlspecialchars($filtros['hasta'] ?? ''); ?>">
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
                <option value="<?= $t; ?>" <?= (string) ($filtros['tipoDte'] ?? '') === (string) $t ? 'selected' : ''; ?>><?= htmlspecialchars($n); ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="filtros__campo">Folio
        <input type="text" inputmode="numeric" name="folio" class="filtros__input filtros__input--corto" value="<?= htmlspecialchars($filtros['folio'] ?? ''); ?>">
    </label>
    <label class="filtros__campo">RUT receptor
        <input type="text" name="receptorRut" class="filtros__input filtros__input--medio" value="<?= htmlspecialchars($filtros['receptorRut'] ?? ''); ?>">
    </label>
    <label class="filtros__campo">Estado
        <input type="text" name="estado" class="filtros__input filtros__input--medio" value="<?= htmlspecialchars($filtros['estado'] ?? ''); ?>" placeholder="enviado, EPR...">
    </label>
    <button type="submit" class="boton-secundario">Filtrar</button>
    <?php if ($hayFiltros): ?>
        <a class="boton-texto" href="/ventas/panel-emision">Limpiar filtros</a>
    <?php endif; ?>
</form>

<?php if (! $hayRangoFechas && ! $hayFolio): ?>
    <p class="nota">Se muestran los documentos del mes actual. Usa "Desde" y "Hasta" para consultar otro periodo, o busca por folio.</p>
<?php endif; ?>

<?php if ($items === []): ?>
    <div class="estado-vacio">
        <?php if ($hayFiltros): ?>
            <h2>Sin resultados</h2>
            <p>Ningun documento coincide con los filtros aplicados.</p>
            <p class="estado-vacio__acciones">
                <a class="boton-principal" href="/ventas/panel-emision">Limpiar filtros</a>
            </p>
        <?php else: ?>
            <h2>No hay documentos este mes</h2>
            <p>Todavia no se emitieron documentos en el mes actual. Si buscas uno anterior, filtra por rango de fechas o por folio.</p>
            <p class="estado-vacio__acciones">
                <a class="boton-principal" href="/ventas/factura">Emitir factura</a>
            </p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="tabla-scroll">
        <table class="tabla-datos tabla-documentos">
            <caption>
                <?= $total; ?> documento<?= $total === 1 ? '' : 's'; ?><?= $hayFiltros ? ' con los filtros aplicados' : ''; ?><?php
                if ($totalPaginas > 1): ?> &middot; pagina <?= $pagina; ?> de <?= $totalPaginas; ?><?php endif; ?>
            </caption>
            <thead>
                <tr>
                    <th>Tipo</th>
                    <th class="tabla-datos__num">Folio</th>
                    <th>Fecha</th>
                    <th>Receptor</th>
                    <th class="tabla-datos__num">Neto</th>
                    <th class="tabla-datos__num">IVA</th>
                    <th class="tabla-datos__num">Total</th>
                    <th class="tabla-datos__estado">Estado</th>
                    <th class="tabla-datos__acciones">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $it): ?>
                    <?php
                        $tipoDte = (int) $it['tipoDte'];
                        $folio   = (int) $it['folio'];
                        [$claseBadge, $textoBadge] = $badgeEstado($it['estado'] ?? null);
                        $razon = $it['receptorRazonSocial'] ?? null;
                    ?>
                    <tr>
                        <td><span class="badge badge--etiqueta"><?= htmlspecialchars(\Plantiflex\FacturacionCl\Enums\TipoDte::nombreDe($tipoDte)); ?></span></td>
                        <td class="tabla-datos__num"><?= $folio; ?></td>
                        <td><?= htmlspecialchars((string) $it['fechaEmision']); ?></td>
                        <td>
                            <?php if (! empty($razon)): ?>
                                <?= htmlspecialchars((string) $razon); ?>
                                <span class="tabla-datos__secundario"><?= htmlspecialchars((string) $it['receptorRut']); ?></span>
                            <?php else: ?>
                                <?= htmlspecialchars((string) $it['receptorRut']); ?>
                            <?php endif; ?>
                        </td>
                        <td class="tabla-datos__num"><?= $fmtMonto($it['neto'] ?? null); ?></td>
                        <td class="tabla-datos__num"><?= $fmtMonto($it['iva'] ?? null); ?></td>
                        <td class="tabla-datos__num"><?= $fmtMonto($it['total'] ?? null); ?></td>
                        <td class="tabla-datos__estado"><span class="badge <?= $claseBadge; ?>"><?= htmlspecialchars($textoBadge); ?></span></td>
                        <td class="tabla-datos__acciones">
                            <a href="/ventas/panel-emision/<?= $tipoDte; ?>/<?= $folio; ?>">Ver detalle</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPaginas > 1): ?>
        <p class="paginacion">
            <?php if ($pagina > 1): ?>
                <a class="boton-secundario" href="/ventas/panel-emision?pagina=<?= $pagina - 1; ?><?= $qs; ?>">&larr; Anterior</a>
            <?php endif; ?>
            <span class="nota">Pagina <?= $pagina; ?> de <?= $totalPaginas; ?> (<?= $total; ?> documentos)</span>
            <?php if ($pagina < $totalPaginas): ?>
                <a class="boton-secundario" href="/ventas/panel-emision?pagina=<?= $pagina + 1; ?><?= $qs; ?>">Siguiente &rarr;</a>
            <?php endif; ?>
        </p>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
