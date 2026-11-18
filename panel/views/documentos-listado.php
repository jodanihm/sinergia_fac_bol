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
 * SEIS COLUMNAS Y NO NUEVE: neto e IVA van como linea secundaria bajo el
 * total y el enlace a la ficha es el propio folio. La sexta, Acciones, son
 * iconos (ficha, PDF, XML) y el "Rehacer" de los rechazados; entra desde que
 * el <main> ocupa todo el ancho.
 *
 * ESTADO: color, nombre e icono salen de datos/estados-sii.php, el mismo
 * catalogo de la Guia de estados (partials/_guia-estados-sii.php). Al pasar el
 * mouse o enfocar el badge se abre un detalle con que significa y que hacer;
 * su contenido va en el HTML (aria-describedby) y el JS solo lo posiciona en
 * una capa flotante para que el scroll de la tabla no lo recorte.
 *
 * EN MOVIL LA TABLA PASA A TARJETAS (.tabla-datos--cards): cada documento se
 * lee de arriba abajo con el nombre de la columna como titulo de cada linea.
 *
 * ESPERA AL FILTRAR (seccion 14, Efectos de carga): filtrar recarga la pagina.
 * Si tarda mas de 300 ms el boton "Filtrar documentos" muestra su spinner sin
 * cambiar de ancho; antes de eso no se muestra nada.
 */
$titulo    = 'Panel de emision';
$bodyClase = 'pagina-panel-emision';
require __DIR__ . '/partials/header.php';

use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Sii\EstadoContable;
use Plantiflex\FacturacionCl\Sii\RehacerDocumento;

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

/*
 * Catalogo de estados (compartido con la Guia). Se indexa por codigo; la clave
 * '' es "sin estado". Un codigo que no esta en el catalogo se muestra crudo y
 * en neutro: el SII puede devolver uno que no conocemos y no se inventa texto.
 */
$catalogoEstados = require __DIR__ . '/../datos/estados-sii.php';
$gruposEstado    = $catalogoEstados['grupos'];
$estadoPorCodigo = [];
foreach ($catalogoEstados['estados'] as $est) {
    $estadoPorCodigo[(string) ($est['codigo'] ?? '')] = $est;
}

/**
 * Todo lo que el listado necesita del estado de un documento.
 *
 * @return array{codigo:string,nombre:string,tono:string,icono:string,explica:string,hacer:string,grupo:string}
 */
$infoEstado = static function (?string $estado) use ($estadoPorCodigo, $gruposEstado): array {
    $c = trim((string) $estado);
    if (isset($estadoPorCodigo[$c])) {
        $est   = $estadoPorCodigo[$c];
        $grupo = $gruposEstado[$est['grupo']];
        return [
            'codigo'  => $c,
            'nombre'  => $est['nombre'],
            'tono'    => $grupo['tono'],
            'icono'   => $grupo['icono'],
            'explica' => $est['explica'],
            'hacer'   => $est['hacer'],
            'grupo'   => $grupo['titulo'],
        ];
    }
    return [
        'codigo'  => $c,
        'nombre'  => $c,
        'tono'    => 'neutro',
        'icono'   => 'info',
        'explica' => 'Codigo que respondio el SII y que todavia no tiene descripcion en la guia.',
        'hacer'   => 'Abre la ficha del documento para ver el detalle de la respuesta.',
        'grupo'   => 'Otro codigo',
    ];
};

/** Icono del tipo de documento. */
$iconoTipo = static fn (int $t): string => match ($t) {
    39, 41  => 'boleta',
    56      => 'nota-debito',
    61      => 'nota-credito',
    default => 'factura',
};

/**
 * Iniciales y tono (0-5) del avatar del receptor. El tono sale del RUT, asi que
 * un mismo cliente tiene siempre el mismo color en todas las paginas.
 *
 * @return array{0:string,1:int}
 */
$avatarReceptor = static function (?string $razon, string $rut): array {
    $palabras = preg_split('/\s+/', trim((string) $razon)) ?: [];
    $palabras = array_values(array_filter($palabras, static fn ($p) => preg_match('/^[\p{L}\p{N}]/u', $p) === 1));
    $ini = '';
    foreach (array_slice($palabras, 0, 2) as $p) {
        $ini .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return [$ini, abs(crc32($rut)) % 6];
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

<div class="pe-cabecera">
    <div class="pe-cabecera__titulo">
        <span class="pe-cabecera__emblema" aria-hidden="true"><?= iconoSvg('panel-emision', 24, ''); ?></span>
        <div>
            <h1>Panel de emision</h1>
            <p class="pe-cabecera__sub">Busca un documento emitido, revisa si el SII ya lo recibio y abre su ficha para descargarlo.</p>
        </div>
    </div>
    <div class="pe-cabecera__acciones">
        <?php require __DIR__ . '/partials/_guia-estados-sii.php'; ?>
        <a class="boton-secundario pe-boton" href="/ventas/nota-credito"><?= iconoSvg('nota-credito', 16, ''); ?><span>Nota de credito</span></a>
        <a class="boton-secundario pe-boton" href="/ventas/nota-debito"><?= iconoSvg('nota-debito', 16, ''); ?><span>Nota de debito</span></a>
        <a class="boton-principal pe-boton" href="/ventas/factura"><?= iconoSvg('factura', 16, ''); ?><span>Emitir factura</span></a>
    </div>
</div>

<?php if (! empty($errorMotor)): ?>
    <div class="alerta alerta--error" role="alert">
        <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
        <div class="alerta__cuerpo">
            <p class="alerta__titulo">No pudimos traer tus documentos</p>
            <p><?= $e((string) $errorMotor); ?> Vuelve a intentarlo en un momento.</p>
        </div>
    </div>
<?php endif; ?>

<form method="get" action="/ventas/panel-emision" class="filtros pe-filtros" id="form-filtros" aria-label="Filtrar documentos">
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
    <label class="filtros__campo pe-filtros__ancho">RUT receptor
        <input type="text" name="receptorRut" class="filtros__input filtros__input--medio" value="<?= $e((string) ($filtros['receptorRut'] ?? '')); ?>"
               placeholder="RUT o nombre del cliente" spellcheck="false" data-buscar-cliente="normalizado">
    </label>
    <label class="filtros__campo">Estado
        <input type="text" name="estado" class="filtros__input filtros__input--medio" value="<?= $e((string) ($filtros['estado'] ?? '')); ?>"
               placeholder="enviado, EPR" spellcheck="false" aria-describedby="ayuda-filtro-estado">
    </label>
    <span class="visualmente-oculto" id="ayuda-filtro-estado">El estado tal como lo guarda el sistema: enviado, o el codigo que respondio el SII.</span>
    <div class="pe-filtros__acciones">
        <button type="submit" class="boton-principal pe-boton" id="filtrar"><?= iconoSvg('buscar', 16, ''); ?><span>Filtrar</span></button>
        <?php if ($hayFiltros): ?>
            <a class="boton-texto" href="/ventas/panel-emision">Limpiar filtros</a>
        <?php endif; ?>
    </div>
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

<?php
// Con al menos un rehacible en la pagina, las filas sin "Rehacer" reservan su
// hueco: asi los iconos quedan alineados en columna y "Rehacer" siempre al
// borde derecho.
$hayRehacibles = false;
foreach ($items as $it) {
    if (RehacerDocumento::permite((int) $it['tipoDte'], $it['estado'] ?? null)) {
        $hayRehacibles = true;
        break;
    }
}
?>
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
    <section class="tarjeta pe-tabla" aria-labelledby="titulo-documentos">
        <div class="pe-tabla__cabecera">
            <h2 id="titulo-documentos">Documentos</h2>
            <span class="pe-tabla__total"><?= $total; ?> documento<?= $total === 1 ? '' : 's'; ?><?= $hayFiltros ? ' con los filtros aplicados' : ''; ?><?php
                if ($totalPaginas > 1): ?> &middot; pagina <?= $pagina; ?> de <?= $totalPaginas; ?><?php endif; ?></span>
        </div>
        <div class="tabla-scroll">
            <table class="tabla-datos tabla-datos--cards tabla-documentos">
                <caption class="visualmente-oculto">
                    <?= $total; ?> documento<?= $total === 1 ? '' : 's'; ?><?= $hayFiltros ? ' con los filtros aplicados' : ''; ?>
                </caption>
                <thead>
                    <tr>
                        <th scope="col">Documento</th>
                        <th scope="col">Fecha</th>
                        <th scope="col">Receptor</th>
                        <th scope="col" class="tabla-datos__num">Total</th>
                        <th scope="col" class="tabla-datos__estado">Estado SII</th>
                        <th scope="col" class="tabla-datos__acciones"><span class="visualmente-oculto">Acciones</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $n => $it): ?>
                        <?php
                            $tipoDte    = (int) $it['tipoDte'];
                            $folio      = (int) $it['folio'];
                            $nombreTipo = TipoDte::nombreDe($tipoDte);
                            $est        = $infoEstado($it['estado'] ?? null);
                            $razon      = $it['receptorRazonSocial'] ?? null;
                            $rut        = (string) $it['receptorRut'];
                            [$iniciales, $tonoAvatar] = $avatarReceptor($razon, $rut);
                            $urlFicha   = '/ventas/panel-emision/' . $tipoDte . '/' . $folio;
                            // Rechazado por el SII y rehacible (mismo criterio que el boton
                            // Rehacer): la fila se marca en rojo suave.
                            $rechazado  = RehacerDocumento::permite($tipoDte, $it['estado'] ?? null);
                            $fueraTotal = $est['codigo'] !== '' && EstadoContable::esRechazado($est['codigo']);
                            $tipId      = 'estado-tip-' . $n;
                        ?>
                        <tr<?= $rechazado ? ' class="tabla-datos__fila--rechazada"' : ''; ?>>
                            <td data-label="Documento">
                                <div class="pe-doc">
                                    <span class="pe-doc__icono" aria-hidden="true"><?= iconoSvg($iconoTipo($tipoDte), 18, ''); ?></span>
                                    <div class="pe-doc__texto">
                                        <a class="pe-doc__folio" href="<?= $urlFicha; ?>"
                                           aria-label="Ver la ficha de <?= $e($nombreTipo); ?> folio <?= $folio; ?>">Folio <?= $folio; ?></a>
                                        <span class="pe-doc__tipo"><?= $e($nombreTipo); ?></span>
                                    </div>
                                </div>
                            </td>
                            <td data-label="Fecha" class="tabla-datos__cifra">
                                <span class="pe-fecha"><?= iconoSvg('calendario', 14, ''); ?><?= $fmtFecha($it['fechaEmision'] ?? ''); ?></span>
                            </td>
                            <td data-label="Receptor">
                                <div class="pe-receptor">
                                    <span class="pe-avatar pe-avatar--<?= $tonoAvatar; ?>" aria-hidden="true"><?= $iniciales !== '' ? $e($iniciales) : iconoSvg('clientes', 16, ''); ?></span>
                                    <div class="pe-receptor__texto">
                                        <?php if (! empty($razon)): ?>
                                            <span class="pe-receptor__nombre"><?= $e((string) $razon); ?></span>
                                            <span class="pe-receptor__rut"><?= $e($rut); ?></span>
                                        <?php else: ?>
                                            <span class="pe-receptor__nombre"><?= $e($rut); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td data-label="Total" class="tabla-datos__num">
                                <span class="tabla-datos__num--destacado"><?= $fmtMonto($it['total'] ?? null); ?></span>
                                <span class="tabla-datos__secundario">Neto <?= $fmtMonto($it['neto'] ?? null); ?> &middot; IVA <?= $fmtMonto($it['iva'] ?? null); ?></span>
                            </td>
                            <td data-label="Estado SII" class="tabla-datos__estado">
                                <span class="pe-estado pe-estado--<?= $est['tono']; ?>" tabindex="0" data-estado-tip aria-describedby="<?= $tipId; ?>">
                                    <?= iconoSvg($est['icono'], 14, 'pe-estado__icono'); ?>
                                    <span class="pe-estado__nombre"><?= $e($est['nombre'] !== '' ? $est['nombre'] : 'Sin estado'); ?></span>
                                    <?php if ($est['codigo'] !== '' && $est['codigo'] !== $est['nombre']): ?>
                                        <span class="pe-estado__codigo"><?= $e($est['codigo']); ?></span>
                                    <?php endif; ?>
                                </span>
                                <div class="pe-tip__fuente" id="<?= $tipId; ?>" role="tooltip" hidden>
                                    <div class="pe-tip__cabecera">
                                        <span class="pe-tip__codigo pe-tip__codigo--<?= $est['tono']; ?>"><?= $e($est['codigo'] !== '' ? $est['codigo'] : '-'); ?></span>
                                        <div>
                                            <p class="pe-tip__nombre"><?= $e($est['nombre'] !== '' ? $est['nombre'] : 'Sin estado'); ?></p>
                                            <p class="pe-tip__grupo"><?= $e($est['grupo']); ?></p>
                                        </div>
                                    </div>
                                    <p class="pe-tip__explica"><?= $e($est['explica']); ?></p>
                                    <p class="pe-tip__hacer"><strong>Que hacer:</strong> <?= $e($est['hacer']); ?></p>
                                    <?php if ($rechazado || $fueraTotal): ?>
                                        <p class="pe-tip__etiquetas">
                                            <?php if ($rechazado): ?><span class="pe-tip__etiqueta pe-tip__etiqueta--rehacer">Se puede rehacer</span><?php endif; ?>
                                            <?php if ($fueraTotal): ?><span class="pe-tip__etiqueta">Fuera de los totales</span><?php endif; ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td data-label="" class="tabla-datos__acciones">
                                <div class="pe-acciones">
                                    <a class="pe-icono-accion" href="<?= $urlFicha; ?>/pdf" data-tip-texto="Descargar PDF"
                                       aria-label="Descargar PDF de <?= $e($nombreTipo); ?> folio <?= $folio; ?>"><?= iconoSvg('descargar', 17, ''); ?></a>
                                    <a class="pe-icono-accion" href="<?= $urlFicha; ?>/xml" data-tip-texto="Descargar XML"
                                       aria-label="Descargar XML de <?= $e($nombreTipo); ?> folio <?= $folio; ?>"><?= iconoSvg('codigo', 17, ''); ?></a>
                                    <a class="pe-icono-accion pe-icono-accion--ver" href="<?= $urlFicha; ?>" data-tip-texto="Ver ficha"
                                       aria-label="Ver la ficha de <?= $e($nombreTipo); ?> folio <?= $folio; ?>"><?= iconoSvg('chevron-der', 18, ''); ?></a>
                                    <?php if ($rechazado): ?>
                                        <?php /* Rechazado por el SII: abre el formulario con sus datos. No emite nada (ver handleDocumentoRehacerGet). */ ?>
                                        <a class="pe-rehacer" href="<?= $urlFicha; ?>/rehacer"
                                           data-tip-texto="Abre el formulario con los mismos datos. No se emite nada hasta que confirmes."
                                           aria-label="Rehacer <?= $e($nombreTipo); ?> folio <?= $folio; ?> con los mismos datos">
                                            <?= iconoSvg('rehacer', 15, 'pe-rehacer__icono'); ?><span>Rehacer</span>
                                        </a>
                                    <?php elseif ($hayRehacibles): ?>
                                        <span class="pe-rehacer-hueco" aria-hidden="true"></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($totalPaginas > 1): ?>
        <nav class="paginacion pe-paginacion" aria-label="Paginas de documentos">
            <?php if ($pagina > 1): ?>
                <a class="boton-secundario pe-boton" href="/ventas/panel-emision?pagina=<?= $pagina - 1; ?><?= $e($qs); ?>"><?= iconoSvg('chevron-izq', 16, ''); ?><span>Anterior</span></a>
            <?php endif; ?>
            <span class="nota" aria-current="page">Pagina <?= $pagina; ?> de <?= $totalPaginas; ?> (<?= $total; ?> documentos)</span>
            <?php if ($pagina < $totalPaginas): ?>
                <a class="boton-secundario pe-boton" href="/ventas/panel-emision?pagina=<?= $pagina + 1; ?><?= $e($qs); ?>"><span>Siguiente</span><?= iconoSvg('chevron-der', 16, ''); ?></a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<script>
(function () {
    'use strict';
    /* DETALLE DEL ESTADO AL PASAR EL MOUSE (o al enfocar con teclado / tocar).
       El contenido ya esta en el HTML de cada fila (.pe-tip__fuente, enlazado
       por aria-describedby, asi que el lector de pantalla lo lee aunque este
       JS no corra). Aqui solo se copia a UNA capa flotante en <body> con
       position: fixed, para que el overflow de .tabla-scroll no lo recorte. */
    var disparadores = document.querySelectorAll('[data-estado-tip], [data-tip-texto]');
    if (!disparadores.length) { return; }
    var capa = document.createElement('div');
    capa.className = 'pe-tip';
    capa.setAttribute('aria-hidden', 'true');
    document.body.appendChild(capa);
    var actual = null;
    var tAbrir = null;
    var tCerrar = null;

    function posicionar(el) {
        var r = el.getBoundingClientRect();
        var w = capa.offsetWidth;
        var h = capa.offsetHeight;
        var m = 10;
        var arriba = r.top - h - m >= 8;
        var top = arriba ? r.top - h - m : r.bottom + m;
        var left = r.left + r.width / 2 - w / 2;
        left = Math.max(8, Math.min(left, window.innerWidth - w - 8));
        capa.style.top = top + 'px';
        capa.style.left = left + 'px';
        capa.setAttribute('data-lado', arriba ? 'arriba' : 'abajo');
        capa.style.setProperty('--flecha-x', (r.left + r.width / 2 - left) + 'px');
    }
    function abrir(el) {
        clearTimeout(tCerrar);
        if (actual === el && capa.classList.contains('pe-tip--visible')) { return; }
        var texto = el.getAttribute('data-tip-texto');
        var fuente = texto === null ? document.getElementById(el.getAttribute('aria-describedby')) : null;
        if (texto === null && !fuente) { return; }
        actual = el;
        // Dos formatos: el detalle del estado (HTML de la fila) o una etiqueta
        // corta de texto (iconos y Rehacer), que se inserta como texto plano.
        capa.classList.toggle('pe-tip--corto', texto !== null);
        if (texto !== null) { capa.textContent = texto; } else { capa.innerHTML = fuente.innerHTML; }
        capa.classList.remove('pe-tip--visible');
        capa.style.display = 'block';
        posicionar(el);
        void capa.offsetWidth;
        capa.classList.add('pe-tip--visible');
    }
    function cerrar() {
        clearTimeout(tAbrir);
        actual = null;
        capa.classList.remove('pe-tip--visible');
        tCerrar = setTimeout(function () { if (!actual) { capa.style.display = 'none'; } }, 180);
    }
    disparadores.forEach(function (el) {
        el.addEventListener('mouseenter', function () { clearTimeout(tAbrir); tAbrir = setTimeout(function () { abrir(el); }, 140); });
        el.addEventListener('mouseleave', cerrar);
        el.addEventListener('focus', function () { abrir(el); });
        el.addEventListener('blur', cerrar);
    });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && actual) { cerrar(); } });
    window.addEventListener('scroll', function () { if (actual) { cerrar(); } }, true);
    window.addEventListener('resize', function () { if (actual) { cerrar(); } });
})();
</script>

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
