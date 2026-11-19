<?php
/**
 * Dashboard de GESTION. Lo renderiza handlePanelGet() cuando el tenant tiene
 * completos los 3 pasos obligatorios de produccion (misma condicion que
 * exigirProduccionCompleto()).
 *
 * Dos modos:
 *   - $vacio = true  -> nunca emitio nada (Q7 = 0): estado vacio explicito y
 *     el progreso de certificacion colapsado debajo.
 *   - $vacio = false -> metricas reales del periodo.
 *
 * Ninguna cifra de esta vista es estimada. Lo que hoy no se puede calcular con
 * datos reales (clasificacion de documentos con problemas) NO muestra un
 * numero: muestra el badge "Proximamente" y explica por que.
 *
 * LAS TABLAS VAN ENVUELTAS EN .tabla-scroll. Sin el envoltorio, una tabla mas
 * ancha que la pantalla empuja el ANCHO DE LA PAGINA COMPLETA y aparece scroll
 * horizontal en todo el dashboard. Medido a 420px de viewport (405 utiles):
 * "Facturacion por tipo" necesita 423px y "Clientes con mayor facturacion"
 * 381px, y entre las dos dejaban 50px de scroll de pagina; a 375px eran 95px.
 * Con el envoltorio, el desborde se queda DENTRO de la tabla y la pagina no se
 * mueve.
 *
 * "Estado de los documentos" (262-307px) no desborda hoy en ningun ancho, pero
 * lleva el mismo envoltorio: con mas codigos de estado o textos mas largos
 * desbordaria igual, y dejarla como la unica tabla desnuda de la vista solo
 * invita a olvidarla despues.
 *
 * El patron es el mismo <div class="tabla-scroll"> que ya usaban el detalle del
 * grafico y otras 15 vistas -- se copia tal cual, sin variantes.
 *
 * REDISENO (2026-11-18): cabecera con saludo y el selector de periodo como
 * control segmentado; accesos rapidos ARRIBA como fichas con icono (antes al
 * final, donde casi no se veian); "Estado de los documentos" deja de ser una
 * tabla de codigos crudos: cada codigo sale con el nombre, color y grupo del
 * catalogo datos/estados-sii.php (el mismo del panel de emision), una barra con
 * su proporcion y un enlace al panel filtrado por ese estado y este periodo. Y
 * una tarjeta nueva, "Correos a clientes" ($correos, dashCorreosPeriodo()),
 * con lo que paso con el envio de los documentos del periodo. Ninguna cifra
 * nueva es estimada.
 *
 * REDISENO PREMIUM (2026-11-19): cabecera "hero" en el marino del menu, con el
 * saludo, la empresa, el periodo y el boton de emitir; los cuatro indicadores
 * flotan sobre su borde inferior. Todo lo demas va en un solo tipo de tarjeta
 * (.db-card, cabecera con icono en caja) y en filas: ventas por dia + estados,
 * facturacion por tipo + clientes, folios, problemas + correos. Mismas cifras,
 * mismos textos aclaratorios: solo cambia la presentacion y el orden (que es
 * el del DOM, asi que teclado y lector de pantalla lo siguen igual).
 * Estilos: bloque "Dashboard premium" de css/style.css (prefijo .db).
 */
$titulo    = 'Panel';
// Raiz de la vista: partials/header.php la pone en el <body>. Es el mismo
// mecanismo con el que login.php pide .auth-page, y la unica linea de markup
// que cambia en esta entrega: el resto es CSS.
$bodyClase = 'dash-page pagina-dashboard';
/* Iconos de los encabezados de seccion. require_once porque partials/_nav.php,
   que incluye header.php un par de lineas mas abajo, tambien lo carga: una
   constante o funcion redeclarada es error fatal. */
require_once __DIR__ . '/partials/_iconos.php';
require __DIR__ . '/partials/header.php';

$periodo = $contexto['periodo'];

$fmtMonto = static function ($v): string {
    return '$' . number_format((float) $v, 0, ',', '.');
};
$fmtNum = static function ($v): string {
    return number_format((float) $v, 0, ',', '.');
};

/** Pinta el delta con texto explicito; nunca solo una flecha de color. */
$pintarDelta = static function (?array $d): string {
    if ($d === null) {
        return '';
    }
    $icono = ['sube' => '&#9650;', 'baja' => '&#9660;', 'igual' => '=', 'sin_base' => '&#8212;'][$d['tipo']] ?? '';

    return '<span class="kpi__delta kpi__delta--' . htmlspecialchars($d['tipo']) . '">'
        . '<span aria-hidden="true">' . $icono . '</span> '
        . htmlspecialchars($d['texto']) . '</span>';
};
?>

<?php
$hora    = (int) date('G');
$saludo  = $hora < 12 ? 'Buenos dias' : ($hora < 20 ? 'Buenas tardes' : 'Buenas noches');
$correos = $correos ?? null;
?>
<div class="db">

<header class="db-hero">
    <div class="db-hero__texto">
        <p class="db-hero__saludo"><?= $saludo; ?></p>
        <h1 class="db-hero__titulo">Panel de gestion</h1>
        <p class="db-hero__empresa">
            <?= htmlspecialchars($contexto['razonSocial'] !== '' ? $contexto['razonSocial'] : 'Tu empresa'); ?>
            <span class="db-hero__rut">RUT <?= htmlspecialchars($contexto['rut']); ?></span>
        </p>
        <p class="db-hero__periodo">Mostrando <strong><?= htmlspecialchars($periodo['etiqueta']); ?></strong> &middot; comparado con <?= htmlspecialchars($periodo['prevEtiqueta']); ?></p>
    </div>
    <div class="db-hero__acciones">
        <nav class="db-periodo" aria-label="Periodo del dashboard">
            <a class="db-periodo__opcion<?= $periodo['clave'] === 'actual' ? ' db-periodo__opcion--activa' : ''; ?>" href="/panel?periodo=actual"<?= $periodo['clave'] === 'actual' ? ' aria-current="page"' : ''; ?>>Mes actual</a>
            <a class="db-periodo__opcion<?= $periodo['clave'] === 'anterior' ? ' db-periodo__opcion--activa' : ''; ?>" href="/panel?periodo=anterior"<?= $periodo['clave'] === 'anterior' ? ' aria-current="page"' : ''; ?>>Mes anterior</a>
        </nav>
        <a class="db-hero__cta" href="/ventas/factura"><?= iconoSvg('agregar', 18, ''); ?> Emitir factura</a>
    </div>
</header>

<?php if (! $vacio): ?>
    <?php
        $foliosTotales = 0;
        foreach ($folios as $f) {
            $foliosTotales += $f['disponibles'];
        }
        $nivelFolios = 'ok';
        foreach ($folios as $f) {
            if ($f['nivel'] === 'rojo') {
                $nivelFolios = 'rojo';
                break;
            }
            if ($f['nivel'] === 'ambar') {
                $nivelFolios = 'ambar';
            }
        }
    ?>
    <section class="db-kpis" aria-label="Indicadores del periodo">
        <article class="db-kpi">
            <span class="db-kpi__icono db-kpi__icono--azul" aria-hidden="true"><?= iconoSvg('factura', 20, ''); ?></span>
            <h2 class="db-kpi__etiqueta">Documentos emitidos</h2>
            <p class="db-kpi__valor"><?= $fmtNum($resumen['documentos']); ?></p>
            <?= $pintarDelta($deltas['documentos']); ?>
        </article>
        <article class="db-kpi db-kpi--destacado">
            <span class="db-kpi__icono db-kpi__icono--verde" aria-hidden="true"><?= iconoSvg('monto', 20, ''); ?></span>
            <h2 class="db-kpi__etiqueta">Neto del periodo</h2>
            <p class="db-kpi__valor"><?= $fmtMonto($resumen['netoPeriodo']); ?></p>
            <?= $pintarDelta($deltas['neto']); ?>
            <p class="db-kpi__nota"><?= htmlspecialchars($resumen['formula']); ?></p>
        </article>
        <article class="db-kpi">
            <span class="db-kpi__icono db-kpi__icono--violeta" aria-hidden="true"><?= iconoSvg('impuesto', 20, ''); ?></span>
            <h2 class="db-kpi__etiqueta">IVA debito</h2>
            <p class="db-kpi__valor"><?= $fmtMonto($resumen['ivaDebito']); ?></p>
            <?= $pintarDelta($deltas['iva']); ?>
            <p class="db-kpi__nota">Mismo criterio que el neto</p>
        </article>
        <article class="db-kpi db-kpi--<?= htmlspecialchars($nivelFolios); ?>">
            <span class="db-kpi__icono db-kpi__icono--<?= $nivelFolios === 'ok' ? 'turquesa' : ($nivelFolios === 'ambar' ? 'ambar' : 'rojo'); ?>" aria-hidden="true"><?= iconoSvg('informe-folios', 20, ''); ?></span>
            <h2 class="db-kpi__etiqueta">Folios disponibles</h2>
            <p class="db-kpi__valor"><?= $fmtNum($foliosTotales); ?></p>
            <p class="db-kpi__nota">Suma de todos los tipos. <a href="#titulo-folios">Ver detalle</a></p>
        </article>
    </section>
<?php endif; ?>

<?php /* ACCESOS RAPIDOS: lo que se viene a hacer al entrar. Emitir factura ya
         esta en la cabecera. */ ?>
<nav class="db-accesos<?= $vacio ? ' db-accesos--sin-kpis' : ''; ?>" aria-labelledby="titulo-accesos">
    <h2 class="visualmente-oculto" id="titulo-accesos">Accesos rapidos</h2>
    <ul>
        <li><a class="db-acceso" href="/ventas/cotizaciones/nueva"><span class="db-acceso__icono" aria-hidden="true"><?= iconoSvg('informe-detalle', 18, ''); ?></span><span><strong>Nueva cotizacion</strong><small>Sin folio ni SII</small></span></a></li>
        <li><a class="db-acceso" href="/ventas/carga-masiva/adaptar"><span class="db-acceso__icono" aria-hidden="true"><?= iconoSvg('carga-masiva', 18, ''); ?></span><span><strong>Importar notas</strong><small>Desde un Excel</small></span></a></li>
        <li><a class="db-acceso" href="/ventas/panel-emision"><span class="db-acceso__icono" aria-hidden="true"><?= iconoSvg('panel-emision', 18, ''); ?></span><span><strong>Panel de emision</strong><small>Tus documentos</small></span></a></li>
        <li><a class="db-acceso" href="/ventas/correos"><span class="db-acceso__icono" aria-hidden="true"><?= iconoSvg('envio-correo', 18, ''); ?></span><span><strong>Envio de correos</strong><small>A tus clientes</small></span></a></li>
        <li><a class="db-acceso" href="/maestros/clientes"><span class="db-acceso__icono" aria-hidden="true"><?= iconoSvg('clientes', 18, ''); ?></span><span><strong>Clientes</strong><small>Tu maestro</small></span></a></li>
        <li><a class="db-acceso" href="/configuracion/vista-factura"><span class="db-acceso__icono" aria-hidden="true"><?= iconoSvg('factura', 18, ''); ?></span><span><strong>Vista previa</strong><small>Como salen tus facturas</small></span></a></li>
    </ul>
</nav>

<?php if ($vacio): ?>

    <section class="db-card db-vacio" aria-labelledby="titulo-vacio">
        <span class="db-vacio__icono" aria-hidden="true"><?= iconoSvg('factura', 28, ''); ?></span>
        <h2 id="titulo-vacio">Aun no has emitido documentos</h2>
        <p>Tu ambiente de produccion esta configurado y listo. Cuando emitas tu
        primer documento tributario, aqui vas a ver la facturacion del periodo,
        el consumo de folios y tus principales clientes.</p>
        <p class="db-vacio__acciones">
            <a class="boton-principal" href="/ventas/factura">Emitir una factura</a>
            <a href="/ventas/carga-masiva/adaptar">o importar notas de venta desde un Excel</a>
        </p>
    </section>

    <?php if ($folios !== []): ?>
        <section class="db-card" aria-labelledby="titulo-folios-vacio">
            <div class="db-card__cabecera">
                <h2 class="db-card__titulo" id="titulo-folios-vacio"><span class="db-card__icono" aria-hidden="true"><?= iconoSvg('informe-folios', 18, ''); ?></span>Folios disponibles</h2>
            </div>
            <?php require __DIR__ . '/partials/_folios.php'; ?>
        </section>
    <?php endif; ?>

    <details class="progreso-colapsado">
        <summary>Ver el progreso de configuracion</summary>
        <?php require __DIR__ . '/partials/_estaciones.php'; ?>
    </details>

<?php else: ?>

    <div class="db-fila db-fila--2-1">
        <section class="db-card" aria-labelledby="titulo-grafico">
            <div class="db-card__cabecera">
                <h2 class="db-card__titulo" id="titulo-grafico"><span class="db-card__icono" aria-hidden="true"><?= iconoSvg('informe-dia', 18, ''); ?></span>Ventas por dia</h2>
                <a class="db-card__enlace" href="/informes/ventas-dia">Ver informe <?= iconoSvg('flecha', 14, ''); ?></a>
            </div>
            <?php require __DIR__ . '/partials/_grafico-ventas.php'; ?>
        </section>

        <section class="db-card" aria-labelledby="titulo-estados">
            <div class="db-card__cabecera">
                <h2 class="db-card__titulo" id="titulo-estados"><span class="db-card__icono" aria-hidden="true"><?= iconoSvg('informe-estados', 18, ''); ?></span>Estado de los documentos</h2>
            </div>
            <?php if ($estados === []): ?>
                <p class="dash-vacio-inline">Sin documentos en el periodo.</p>
            <?php else: ?>
                <?php
                // Cada codigo con su nombre, tono y grupo del catalogo compartido.
                // Un codigo que el catalogo no conoce sale crudo y en neutro.
                $dgCatalogo = require __DIR__ . '/../datos/estados-sii.php';
                $dgPorCodigo = [];
                foreach ($dgCatalogo['estados'] as $x) {
                    $dgPorCodigo[(string) ($x['codigo'] ?? '')] = $x + ['tono' => $dgCatalogo['grupos'][$x['grupo']]['tono']];
                }
                $dgTotal = array_sum(array_column($estados, 'documentos'));
                $dgPorTono = [];
                foreach ($estados as $fila) {
                    $t = $dgPorCodigo[$fila['estado']]['tono'] ?? 'neutro';
                    $dgPorTono[$t] = ($dgPorTono[$t] ?? 0) + $fila['documentos'];
                }
                ?>
                <div class="dg-estados__barra" role="img" aria-label="Reparto de los <?= $dgTotal; ?> documentos del periodo por estado">
                    <?php foreach (['exito', 'proceso', 'alerta', 'error', 'neutro'] as $t): ?>
                        <?php if (! empty($dgPorTono[$t])): ?>
                            <span class="dg-tono--<?= $t; ?>" style="--ancho: <?= round($dgPorTono[$t] * 100 / max(1, $dgTotal), 1); ?>%"></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <ul class="dg-estados">
                    <?php foreach ($estados as $fila): ?>
                        <?php
                        $info = $dgPorCodigo[$fila['estado']] ?? null;
                        $tono = $info['tono'] ?? 'neutro';
                        $pct  = round($fila['documentos'] * 100 / max(1, $dgTotal));
                        $url  = '/ventas/panel-emision?' . http_build_query(['desde' => $periodo['desde'], 'hasta' => $periodo['hasta'], 'estado' => $fila['estado']]);
                        ?>
                        <li>
                            <a class="dg-estado" href="<?= htmlspecialchars($url); ?>"
                               aria-label="<?= htmlspecialchars(($info['nombre'] ?? $fila['estado']) . ': ' . $fila['documentos'] . ' documentos. Ver en el panel de emision'); ?>">
                                <span class="dg-estado__codigo dg-tono--<?= $tono; ?>"><?= htmlspecialchars($fila['estado'] !== '' ? $fila['estado'] : '-'); ?></span>
                                <span class="dg-estado__nombre"><?= htmlspecialchars($info['nombre'] ?? 'Codigo sin descripcion'); ?></span>
                                <span class="dg-estado__cifra"><?= $fmtNum($fila['documentos']); ?> <small><?= $pct; ?>%</small></span>
                                <span class="dg-estado__pista" aria-hidden="true"><span class="dg-tono--<?= $tono; ?>" style="--ancho: <?= max(2, $pct); ?>%"></span></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <p class="db-nota">El estado solo avanza cuando se consulta al SII desde el
            panel de emision: no hay un proceso automatico que lo actualice.</p>
        </section>
    </div>

    <div class="db-fila db-fila--1-1">
        <section class="db-card" aria-labelledby="titulo-por-tipo">
            <div class="db-card__cabecera">
                <h2 class="db-card__titulo" id="titulo-por-tipo"><span class="db-card__icono" aria-hidden="true"><?= iconoSvg('informe-tipos', 18, ''); ?></span>Facturacion por tipo</h2>
                <a class="db-card__enlace" href="/informes/facturacion">Ver informe <?= iconoSvg('flecha', 14, ''); ?></a>
            </div>
            <div class="tabla-scroll">
                <table class="db-tabla">
                    <caption>Documentos y montos del periodo, separados por tipo. Las notas
                    de credito se muestran aparte porque rebajan lo facturado.</caption>
                    <thead>
                        <tr>
                            <th scope="col">Tipo</th>
                            <th scope="col" class="num">Docs.</th>
                            <th scope="col" class="num">Neto</th>
                            <th scope="col" class="num">IVA</th>
                            <th scope="col" class="num">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($resumen['porTipo'] as $tipo => $d): ?>
                            <tr<?= $tipo === 61 ? ' class="fila-resta"' : ''; ?>>
                                <th scope="row">
                                    <?= htmlspecialchars(nombreTipoDte((int) $tipo)); ?>
                                    <?php if ($tipo === 61): ?>
                                        <span class="db-tabla__resta">resta</span>
                                    <?php endif; ?>
                                </th>
                                <td class="num"><?= $fmtNum($d['documentos']); ?></td>
                                <td class="num"><?= $fmtMonto($d['neto']); ?></td>
                                <td class="num"><?= $fmtMonto($d['iva']); ?></td>
                                <td class="num db-tabla__fuerte"><?= $fmtMonto($d['total']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="db-card" aria-labelledby="titulo-clientes">
            <div class="db-card__cabecera">
                <h2 class="db-card__titulo" id="titulo-clientes"><span class="db-card__icono" aria-hidden="true"><?= iconoSvg('informe-clientes', 18, ''); ?></span>Clientes con mayor facturacion</h2>
                <a class="db-card__enlace" href="/informes/clientes">Ver informe <?= iconoSvg('flecha', 14, ''); ?></a>
            </div>
            <?php if ($topClientes === []): ?>
                <p class="dash-vacio-inline">Sin documentos en el periodo.</p>
            <?php else: ?>
                <?php $dbMaxCliente = max(1, ...array_map(static fn ($c) => (float) $c['neto'], $topClientes)); ?>
                <ol class="db-ranking" aria-describedby="nota-clientes">
                    <?php foreach ($topClientes as $i => $c): ?>
                        <li class="db-ranking__fila">
                            <span class="db-ranking__puesto" aria-hidden="true"><?= $i + 1; ?></span>
                            <span class="db-ranking__cliente">
                                <?php if ($c['razonSocial'] !== null): ?>
                                    <strong><?= htmlspecialchars($c['razonSocial']); ?></strong>
                                    <small><?= htmlspecialchars($c['rut']); ?> &middot; <?= $fmtNum($c['documentos']); ?> doc<?= (int) $c['documentos'] === 1 ? '' : 's'; ?>.</small>
                                <?php else: ?>
                                    <strong><?= htmlspecialchars($c['rut']); ?></strong>
                                    <small>No esta en tu maestro de clientes &middot; <?= $fmtNum($c['documentos']); ?> doc<?= (int) $c['documentos'] === 1 ? '' : 's'; ?>.</small>
                                <?php endif; ?>
                                <span class="db-ranking__barra" aria-hidden="true"><span style="width: <?= max(3, round((float) $c['neto'] * 100 / $dbMaxCliente)); ?>%"></span></span>
                            </span>
                            <span class="db-ranking__monto"><?= $fmtMonto($c['neto']); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <p class="db-nota" id="nota-clientes">Top 5 del periodo por RUT (con y sin puntos cuenta como uno). Monto con IVA, restando notas de credito: no es el mismo calculo que el neto de arriba.</p>
            <?php endif; ?>
        </section>
    </div>

    <section class="db-card" aria-labelledby="titulo-folios">
        <div class="db-card__cabecera">
            <h2 class="db-card__titulo" id="titulo-folios"><span class="db-card__icono" aria-hidden="true"><?= iconoSvg('informe-folios', 18, ''); ?></span>Folios por tipo de documento</h2>
            <a class="db-card__enlace" href="/informes/folios">Ver informe <?= iconoSvg('flecha', 14, ''); ?></a>
        </div>
        <?php require __DIR__ . '/partials/_folios.php'; ?>
    </section>

    <?php
    // DOCUMENTOS CON PROBLEMAS: la contrapartida obligatoria de haber excluido
    // los rechazados de los KPI. Sacarlos del total sin ensenarlos seria un
    // punto ciego: lo que aparece aqui es EXACTAMENTE lo que se resto.
    $hayRechazados = $rechazados !== null && (int) $rechazados['documentos'] > 0;
    ?>
    <div class="db-fila db-fila--1-1">
        <section class="db-card<?= $hayRechazados ? ' db-card--alerta' : ''; ?>" aria-labelledby="titulo-problemas">
            <div class="db-card__cabecera">
                <h2 class="db-card__titulo" id="titulo-problemas"><span class="db-card__icono db-card__icono--<?= $hayRechazados ? 'rojo' : 'verde'; ?>" aria-hidden="true"><?= iconoSvg($hayRechazados ? 'alerta' : 'check', 18, ''); ?></span>Documentos con problemas</h2>
            </div>
            <?php if (! $hayRechazados): ?>
                <p class="db-destacado">Ningun documento de este periodo fue rechazado por el SII.</p>
                <p class="db-nota">Si el SII rechaza un envio, aqui vas a ver cuantos
                documentos son y por cuanto monto, y no se suman a tu facturacion.</p>
            <?php else: ?>
                <p class="db-destacado">El SII rechazo <strong><?= $fmtNum($rechazados['documentos']); ?></strong>
                documento<?= (int) $rechazados['documentos'] === 1 ? '' : 's'; ?> de este periodo,
                por <strong><?= $fmtMonto($rechazados['netoPeriodo']); ?></strong> netos
                (<?= $fmtMonto($rechazados['ivaDebito']); ?> de IVA).</p>
                <p class="db-nota">Esos documentos <strong>no</strong> estan sumados en las
                cifras de arriba: un envio rechazado se corrige y se reemite, asi que
                contarlos duplicaria las mismas ventas. Sus folios ya se consumieron y
                los documentos siguen visibles en el
                <a href="/ventas/panel-emision">Panel de emision</a>.</p>
            <?php endif; ?>
        </section>

        <?php if ($correos !== null): ?>
            <?php $dgPct = $correos['total'] > 0 ? (int) round($correos['enviado'] * 100 / $correos['total']) : 0; ?>
            <section class="db-card dg-correos" aria-labelledby="titulo-correos">
                <div class="db-card__cabecera">
                    <h2 class="db-card__titulo" id="titulo-correos"><span class="db-card__icono" aria-hidden="true"><?= iconoSvg('envio-correo', 18, ''); ?></span>Correos a clientes</h2>
                    <a class="db-card__enlace" href="/ventas/correos">Ver envios <?= iconoSvg('flecha', 14, ''); ?></a>
                </div>
                <?php if ($correos['total'] === 0): ?>
                    <p class="dash-vacio-inline">Ningun documento del periodo paso por el envio de correos.</p>
                <?php else: ?>
                    <div class="dg-correos__cuerpo">
                        <div class="dg-correos__anillo" role="img" aria-label="<?= $dgPct; ?> por ciento de los correos enviados" style="--pct: <?= $dgPct; ?>">
                            <span><?= $dgPct; ?><small>%</small></span>
                        </div>
                        <div>
                            <p class="dg-correos__titulo"><strong><?= $fmtNum($correos['enviado']); ?></strong> de <?= $fmtNum($correos['total']); ?> documentos llegaron por correo a su cliente.</p>
                            <ul class="dg-correos__lista">
                                <?php if ($correos['pendiente'] > 0): ?><li class="dg-tono--proceso"><?= $fmtNum($correos['pendiente']); ?> en cola</li><?php endif; ?>
                                <?php if ($correos['sin_destinatario'] > 0): ?><li class="dg-tono--alerta"><a href="/ventas/correos?estado=sin_destinatario"><?= $fmtNum($correos['sin_destinatario']); ?> sin correo del cliente</a></li><?php endif; ?>
                                <?php if ($correos['error'] > 0): ?><li class="dg-tono--error"><a href="/ventas/correos?estado=error"><?= $fmtNum($correos['error']); ?> con error</a></li><?php endif; ?>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>

<?php endif; ?>

<?php /* Misma pestana nueva que en el login: planes.html no tiene vuelta. */ ?>
<p class="db-planes">
    <a class="boton-secundario pe-boton" href="/planes.html" target="_blank" rel="noopener">Ver planes<span class="visualmente-oculto"> (se abre en otra pestana)</span></a>
</p>

</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
