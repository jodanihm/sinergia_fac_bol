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
 */
$titulo    = 'Panel';
// Raiz de la vista: partials/header.php la pone en el <body>. Es el mismo
// mecanismo con el que login.php pide .auth-page, y la unica linea de markup
// que cambia en esta entrega: el resto es CSS.
$bodyClase = 'dash-page';
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

<div class="dash-header">
    <div>
        <h1>Panel de gestion</h1>
        <p class="dash-header__emisor">
            <?= htmlspecialchars($contexto['razonSocial'] !== '' ? $contexto['razonSocial'] : 'Tu empresa'); ?>
            <span class="dash-header__rut">RUT <?= htmlspecialchars($contexto['rut']); ?></span>
        </p>
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

<?php if ($vacio): ?>

    <section class="estado-vacio" aria-labelledby="titulo-vacio">
        <h2 id="titulo-vacio">Aun no has emitido documentos</h2>
        <p>Tu ambiente de produccion esta configurado y listo. Cuando emitas tu
        primer documento tributario, aqui vas a ver la facturacion del periodo,
        el consumo de folios y tus principales clientes.</p>
        <p class="estado-vacio__acciones">
            <a class="boton-principal" href="/ventas/factura">Emitir una factura</a>
            <a href="/ventas/carga-masiva">o cargar notas de venta masivamente</a>
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
            <h2 class="kpi__etiqueta">Documentos emitidos</h2>
            <p class="kpi__valor"><?= $fmtNum($resumen['documentos']); ?></p>
            <?= $pintarDelta($deltas['documentos']); ?>
        </article>

        <article class="kpi">
            <h2 class="kpi__etiqueta">Neto del periodo</h2>
            <p class="kpi__valor"><?= $fmtMonto($resumen['netoPeriodo']); ?></p>
            <?= $pintarDelta($deltas['neto']); ?>
            <p class="kpi__formula"><?= htmlspecialchars($resumen['formula']); ?></p>
        </article>

        <article class="kpi">
            <h2 class="kpi__etiqueta">IVA debito</h2>
            <p class="kpi__valor"><?= $fmtMonto($resumen['ivaDebito']); ?></p>
            <?= $pintarDelta($deltas['iva']); ?>
            <p class="kpi__formula">Mismo criterio que el neto</p>
        </article>

        <article class="kpi kpi--<?= htmlspecialchars($nivelFolios); ?>">
            <h2 class="kpi__etiqueta">Folios disponibles</h2>
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
        <h2 id="titulo-por-tipo">Facturacion por tipo de documento</h2>
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
        <h2 id="titulo-clientes">Clientes con mayor facturacion</h2>
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
        <h2 id="titulo-folios">Folios por tipo de documento</h2>
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
        <h2 id="titulo-grafico">Ventas por dia</h2>
        <?php require __DIR__ . '/partials/_grafico-ventas.php'; ?>
    </section>

    <section class="tarjeta" aria-labelledby="titulo-estados">
        <h2 id="titulo-estados">Estado de los documentos</h2>
        <?php if ($estados === []): ?>
            <p class="dash-vacio-inline">Sin documentos en el periodo.</p>
        <?php else: ?>
            <div class="tabla-scroll">
                <table>
                    <caption>Codigo de estado tal como lo devolvio el SII, sin interpretar.</caption>
                    <thead>
                        <tr><th scope="col">Estado</th><th scope="col" class="num">Documentos</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($estados as $e): ?>
                            <tr>
                                <th scope="row"><code><?= htmlspecialchars($e['estado']); ?></code></th>
                                <td class="num"><?= $fmtNum($e['documentos']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <p class="nota">El estado solo avanza cuando se consulta al SII desde el
        panel de emision: no hay un proceso automatico que lo actualice.</p>
    </section>
    </div>

    <section class="tarjeta tarjeta--proximamente" aria-labelledby="titulo-problemas">
        <h2 id="titulo-problemas">
            Documentos con problemas
            <span class="nav-item__badge badge--proximo">Proximamente</span>
        </h2>
        <p>Todavia no clasificamos los codigos del SII en aceptado, rechazado o
        pendiente. Decidir que codigo significa "rechazado" es una regla
        tributaria, y preferimos no mostrarte un numero antes de tenerla
        definida y verificada.</p>
        <p class="nota">Mientras tanto, la distribucion de estados de la izquierda
        muestra los codigos crudos, que si son un dato verificable.</p>
    </section>

<?php endif; ?>

<section class="accesos" aria-labelledby="titulo-accesos">
    <h2 id="titulo-accesos">Accesos rapidos</h2>
    <ul class="accesos__lista">
        <li><a href="/ventas/factura">Emitir factura</a></li>
        <li><a href="/ventas/carga-masiva">Carga masiva</a></li>
        <li><a href="/ventas/panel-emision">Panel de emision</a></li>
        <li><a href="/maestros/clientes">Clientes</a></li>
    </ul>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
