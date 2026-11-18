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
<div class="pe-cabecera dg-cabecera">
    <div class="pe-cabecera__titulo">
        <span class="pe-cabecera__emblema" aria-hidden="true"><?= iconoSvg('dashboard', 24, ''); ?></span>
        <div>
            <p class="dg-saludo"><?= $saludo; ?></p>
            <h1>Panel de gestion</h1>
            <p class="dash-header__emisor">
                <?= htmlspecialchars($contexto['razonSocial'] !== '' ? $contexto['razonSocial'] : 'Tu empresa'); ?>
                <span class="dash-header__rut">RUT <?= htmlspecialchars($contexto['rut']); ?></span>
            </p>
        </div>
    </div>
    <nav class="dash-periodo" aria-label="Periodo del dashboard">
        <span class="dash-periodo__etiqueta">Periodo</span>
        <a class="dash-periodo__opcion <?= $periodo['clave'] === 'actual' ? 'dash-periodo__opcion--activa' : ''; ?>"
           href="/panel?periodo=actual"
           <?= $periodo['clave'] === 'actual' ? 'aria-current="page"' : ''; ?>>Mes actual</a>
        <a class="dash-periodo__opcion <?= $periodo['clave'] === 'anterior' ? 'dash-periodo__opcion--activa' : ''; ?>"
           href="/panel?periodo=anterior"
           <?= $periodo['clave'] === 'anterior' ? 'aria-current="page"' : ''; ?>>Mes anterior</a>
    </nav>
</div>

<p class="dash-subtitulo">
    Mostrando <strong><?= htmlspecialchars($periodo['etiqueta']); ?></strong>.
    Comparado con <?= htmlspecialchars($periodo['prevEtiqueta']); ?>.
</p>

<?php /* ACCESOS RAPIDOS, arriba: lo que se viene a hacer al entrar. */ ?>
<nav class="dg-accesos" aria-labelledby="titulo-accesos">
    <h2 class="visualmente-oculto" id="titulo-accesos">Accesos rapidos</h2>
    <ul>
        <li><a class="dg-acceso dg-acceso--principal" href="/ventas/factura"><span class="dg-acceso__icono" aria-hidden="true"><?= iconoSvg('factura', 20, ''); ?></span><span><strong>Emitir factura</strong><small>Documento al SII</small></span></a></li>
        <li><a class="dg-acceso" href="/ventas/cotizaciones/nueva"><span class="dg-acceso__icono" aria-hidden="true"><?= iconoSvg('informe-detalle', 20, ''); ?></span><span><strong>Nueva cotizacion</strong><small>Sin folio ni SII</small></span></a></li>
        <li><a class="dg-acceso" href="/ventas/carga-masiva/adaptar"><span class="dg-acceso__icono" aria-hidden="true"><?= iconoSvg('carga-masiva', 20, ''); ?></span><span><strong>Importar notas</strong><small>Desde un Excel</small></span></a></li>
        <li><a class="dg-acceso" href="/ventas/panel-emision"><span class="dg-acceso__icono" aria-hidden="true"><?= iconoSvg('panel-emision', 20, ''); ?></span><span><strong>Panel de emision</strong><small>Tus documentos</small></span></a></li>
        <li><a class="dg-acceso" href="/ventas/correos"><span class="dg-acceso__icono" aria-hidden="true"><?= iconoSvg('envio-correo', 20, ''); ?></span><span><strong>Envio de correos</strong><small>A tus clientes</small></span></a></li>
        <li><a class="dg-acceso" href="/maestros/clientes"><span class="dg-acceso__icono" aria-hidden="true"><?= iconoSvg('clientes', 20, ''); ?></span><span><strong>Clientes</strong><small>Tu maestro</small></span></a></li>
    </ul>
</nav>

<?php if ($vacio): ?>

    <section class="estado-vacio" aria-labelledby="titulo-vacio">
        <h2 id="titulo-vacio">Aun no has emitido documentos</h2>
        <p>Tu ambiente de produccion esta configurado y listo. Cuando emitas tu
        primer documento tributario, aqui vas a ver la facturacion del periodo,
        el consumo de folios y tus principales clientes.</p>
        <p class="estado-vacio__acciones">
            <a class="boton-principal" href="/ventas/factura">Emitir una factura</a>
            <a href="/ventas/carga-masiva/adaptar">o importar notas de venta desde un Excel</a>
        </p>
    </section>

    <?php if ($folios !== []): ?>
        <section aria-labelledby="titulo-folios-vacio">
            <h2 id="titulo-folios-vacio">Folios disponibles</h2>
            <?php require __DIR__ . '/partials/_folios.php'; ?>
        </section>
    <?php endif; ?>

    <details class="progreso-colapsado">
        <summary>Ver el progreso de configuracion</summary>
        <?php require __DIR__ . '/partials/_estaciones.php'; ?>
    </details>

<?php else: ?>

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

    <section class="dash-grid" aria-label="Indicadores del periodo">
        <article class="kpi">
            <h2 class="kpi__etiqueta"><?= iconoSvg('factura', 18, 'kpi__icono'); ?>Documentos emitidos</h2>
            <p class="kpi__valor"><?= $fmtNum($resumen['documentos']); ?></p>
            <?= $pintarDelta($deltas['documentos']); ?>
        </article>

        <article class="kpi">
            <h2 class="kpi__etiqueta"><?= iconoSvg('monto', 18, 'kpi__icono'); ?>Neto del periodo</h2>
            <p class="kpi__valor"><?= $fmtMonto($resumen['netoPeriodo']); ?></p>
            <?= $pintarDelta($deltas['neto']); ?>
            <p class="kpi__formula"><?= htmlspecialchars($resumen['formula']); ?></p>
        </article>

        <article class="kpi">
            <h2 class="kpi__etiqueta"><?= iconoSvg('impuesto', 18, 'kpi__icono'); ?>IVA debito</h2>
            <p class="kpi__valor"><?= $fmtMonto($resumen['ivaDebito']); ?></p>
            <?= $pintarDelta($deltas['iva']); ?>
            <p class="kpi__formula">Mismo criterio que el neto</p>
        </article>

        <article class="kpi kpi--<?= htmlspecialchars($nivelFolios); ?>">
            <h2 class="kpi__etiqueta"><?= iconoSvg('informe-folios', 18, 'kpi__icono'); ?>Folios disponibles</h2>
            <p class="kpi__valor"><?= $fmtNum($foliosTotales); ?></p>
            <p class="kpi__formula">Suma de todos los tipos. Detalle mas abajo.</p>
        </article>
    </section>

    <?php
        /* FILA 1. Las dos tablas del periodo, en mitades parejas. El
           contenedor SOLO ENVUELVE. "Clientes con mayor facturacion" si
           cambio de sitio: subio desde el final de la vista hasta aqui,
           moviendo su markup completo. No se uso order ni grid-area, que
           habrian cambiado el orden visual dejando el del DOM atras: el
           foco por teclado y los lectores de pantalla siguen el DOM. */
    ?>
    <div class="dash-fila dash-fila--tablas">
    <section aria-labelledby="titulo-por-tipo">
        <h2 class="dash-titulo" id="titulo-por-tipo"><?= iconoSvg('informe-tipos', 18, 'dash-titulo__icono'); ?>Facturacion por tipo de documento</h2>
        <div class="tabla-scroll">
            <table>
                <caption>Documentos y montos del periodo, separados por tipo. Las notas
                de credito se muestran aparte porque rebajan lo facturado.</caption>
                <thead>
                    <tr>
                        <th scope="col">Tipo</th>
                        <th scope="col" class="num">Documentos</th>
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
                                    <span class="subpaso__aclaracion">&mdash; resta</span>
                                <?php endif; ?>
                            </th>
                            <td class="num"><?= $fmtNum($d['documentos']); ?></td>
                            <td class="num"><?= $fmtMonto($d['neto']); ?></td>
                            <td class="num"><?= $fmtMonto($d['iva']); ?></td>
                            <td class="num"><?= $fmtMonto($d['total']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section aria-labelledby="titulo-clientes">
        <h2 class="dash-titulo" id="titulo-clientes"><?= iconoSvg('informe-clientes', 18, 'dash-titulo__icono'); ?>Clientes con mayor facturacion</h2>
        <?php if ($topClientes === []): ?>
            <p class="dash-vacio-inline">Sin documentos en el periodo.</p>
        <?php else: ?>
            <div class="tabla-scroll">
                <table>
                    <caption>Top 5 receptores del periodo, agrupados por RUT normalizado
                    (un mismo RUT con y sin puntos cuenta como uno solo). El monto es el
                    total con IVA, restando las notas de credito &mdash; no es el mismo
                    calculo que el "Neto del periodo" de arriba, que va sobre el neto sin
                    IVA.</caption>
                    <thead>
                        <tr>
                            <th scope="col">Cliente</th>
                            <th scope="col" class="num">Documentos</th>
                            <th scope="col" class="num">Monto con IVA</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topClientes as $c): ?>
                            <tr>
                                <th scope="row">
                                    <?php if ($c['razonSocial'] !== null): ?>
                                        <?= htmlspecialchars($c['razonSocial']); ?>
                                        <span class="subpaso__aclaracion"><?= htmlspecialchars($c['rut']); ?></span>
                                    <?php else: ?>
                                        <?= htmlspecialchars($c['rut']); ?>
                                        <span class="subpaso__aclaracion">&mdash; no esta en tu maestro de clientes</span>
                                    <?php endif; ?>
                                </th>
                                <td class="num"><?= $fmtNum($c['documentos']); ?></td>
                                <td class="num"><?= $fmtMonto($c['neto']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    </div>

    <section aria-labelledby="titulo-folios">
        <h2 class="dash-titulo" id="titulo-folios"><?= iconoSvg('informe-folios', 18, 'dash-titulo__icono'); ?>Folios por tipo de documento</h2>
        <?php require __DIR__ . '/partials/_folios.php'; ?>
    </section>

    <?php
        /* FILA 2. Reparto desigual a favor del grafico. Las dos secciones
           ya eran adyacentes en el DOM y no se movieron; lo que se quito
           fue la seccion de grilla de dos columnas que envolvia las dos
           tarjetas con un aria-label que solo nombraba a una de ellas.
           Cada tarjeta quedo como su propia region, con su propio nombre. */
    ?>
    <div class="dash-fila dash-fila--grafico">
    <section aria-labelledby="titulo-grafico">
        <h2 class="dash-titulo" id="titulo-grafico"><?= iconoSvg('informe-dia', 18, 'dash-titulo__icono'); ?>Ventas por dia</h2>
        <?php require __DIR__ . '/partials/_grafico-ventas.php'; ?>
    </section>

    <section class="tarjeta" aria-labelledby="titulo-estados">
        <h2 class="dash-titulo" id="titulo-estados"><?= iconoSvg('informe-estados', 18, 'dash-titulo__icono'); ?>Estado de los documentos</h2>
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
        <p class="nota">El estado solo avanza cuando se consulta al SII desde el
        panel de emision: no hay un proceso automatico que lo actualice.</p>
    </section>
    </div>

    <?php
    // DOCUMENTOS CON PROBLEMAS. Esta tarjeta decia "Proximamente" y explicaba
    // que no clasificabamos los codigos del SII porque era una regla tributaria
    // sin definir. Ya esta definida y verificada (EstadoContable), asi que
    // ahora muestra el numero.
    //
    // Y ES LA CONTRAPARTIDA OBLIGATORIA DE HABER EXCLUIDO ESOS DOCUMENTOS DE LOS
    // KPI. Sacarlos del total sin enseñarlos en ninguna parte seria recrear el
    // punto ciego en otra forma: el cliente veria bajar su facturacion sin
    // ninguna explicacion a la vista. Lo que aparece aqui es EXACTAMENTE lo que
    // se resto -- misma consulta, misma funcion de resumen, filtro invertido.
    $hayRechazados = $rechazados !== null && (int) $rechazados['documentos'] > 0;
    ?>
    <section class="tarjeta<?= $hayRechazados ? '' : ' tarjeta--proximamente'; ?>" aria-labelledby="titulo-problemas">
        <h2 class="dash-titulo" id="titulo-problemas">
            <?= iconoSvg('alerta', 18, 'dash-titulo__icono'); ?>Documentos con problemas
        </h2>
        <?php if (! $hayRechazados): ?>
            <p>Ningun documento de este periodo fue rechazado por el SII.</p>
            <p class="nota">Si el SII rechaza un envio, aqui vas a ver cuantos
            documentos son y por cuanto monto, y no se suman a tu facturacion.</p>
        <?php else: ?>
            <p>El SII rechazo <strong><?= $fmtNum($rechazados['documentos']); ?></strong>
            documento<?= (int) $rechazados['documentos'] === 1 ? '' : 's'; ?> de este periodo,
            por <strong><?= $fmtMonto($rechazados['netoPeriodo']); ?></strong> netos
            (<?= $fmtMonto($rechazados['ivaDebito']); ?> de IVA).</p>
            <p class="nota">Esos documentos <strong>no</strong> estan sumados en las
            cifras de arriba: un envio rechazado se corrige y se reemite, asi que
            contarlos duplicaria las mismas ventas. Sus folios ya se consumieron y
            los documentos siguen visibles en el
            <a href="/ventas/panel-emision">Panel de emision</a>.</p>
        <?php endif; ?>
    </section>

    <?php if ($correos !== null): ?>
        <?php
        // CORREOS A CLIENTES del periodo (dashCorreosPeriodo()). Lo que pide
        // accion -- error y sin destinatario -- va con su enlace.
        $dgPct = $correos['total'] > 0 ? (int) round($correos['enviado'] * 100 / $correos['total']) : 0;
        ?>
        <section class="tarjeta dg-correos" aria-labelledby="titulo-correos">
            <h2 class="dash-titulo" id="titulo-correos"><?= iconoSvg('envio-correo', 18, 'dash-titulo__icono'); ?>Correos a clientes</h2>
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
                        <a class="dg-enlace" href="/ventas/correos">Ver envio de correos <?= iconoSvg('flecha', 14, ''); ?></a>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

<?php endif; ?>

<?php /* Los accesos rapidos subieron arriba; aqui queda el enlace a los planes.
         Misma pestana nueva que en el login: planes.html no tiene vuelta. */ ?>
<p class="dg-planes">
    <a class="boton-secundario pe-boton" href="/planes.html" target="_blank" rel="noopener">Ver planes<span class="visualmente-oculto"> (se abre en otra pestana)</span></a>
</p>

<?php require __DIR__ . '/partials/footer.php'; ?>
