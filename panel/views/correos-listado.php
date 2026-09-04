<?php
/**
 * Cola de envio de correos al receptor (tabla dte_envio_correo, migracion 024).
 *
 * Recibe: $items, $conteos, $totalCuenta, $total, $pagina, $totalPaginas,
 * $estado, $flash, $navActivo. Todo de handleCorreosListadoGet(), que consulta
 * la base del panel DIRECTO -- la cola es suya, a diferencia de dte_emitido.
 *
 * Cada item trae: id, destinatario, estado, intentos, ultimo_error, enviado_at,
 * created_at (de la cola) y tipo_dte, folio, receptor_rut (de dte_emitido, por
 * union numerica). destinatario, ultimo_error y enviado_at pueden ser null.
 *
 * NO MUESTRA LA RAZON SOCIAL DEL RECEPTOR, y es a proposito: vive en el maestro
 * de clientes, que esta en la OTRA familia de collation, y traerla obligaria al
 * rodeo de resolverRazonSocialReceptores(). El destinatario -- la direccion a la
 * que se mando o se mandara -- es ademas el dato que de verdad sirve para
 * diagnosticar un correo.
 *
 * MOLDE: el mismo de documentos-listado.php (.tabla-scroll + .tabla-datos con
 * sus clases auxiliares, .estado-vacio, <p class="paginacion">). CERO CSS nuevo:
 * las cuatro variantes de badge que usa ya existen.
 */
$titulo    = 'Envio de correos';
$bodyClase = 'dash-page';
require __DIR__ . '/partials/header.php';

/**
 * Un badge por estado, sobre las variantes que ya existen en style.css.
 *
 * 'sin_destinatario' va en ambar y no en rojo: el documento se emitio bien y
 * simplemente no habia a quien mandarselo. No es un fallo del envio.
 */
$badgeEstado = static function (?string $estado): array {
    return match (trim((string) $estado)) {
        'pendiente'        => ['badge--proceso', 'Pendiente'],
        'enviado'          => ['badge--ok', 'Enviado'],
        'error'            => ['badge--error', 'Error'],
        'sin_destinatario' => ['badge--advertencia', 'Sin destinatario'],
        default            => ['badge--neutro', (string) $estado],
    };
};


$fmt = static fn ($v): string => ($v === null || $v === '') ? '-' : (string) $v;

// Query string que arrastra el filtro al cambiar de pagina. Sin esto, la
// paginacion navegaria sobre otro conjunto (mismo criterio que el panel de
// emision).
$qs = $estado !== '' ? '&estado=' . urlencode($estado) : '';
?>

<div class="dash-header">
    <div>
        <h1>Envio de correos</h1>
    </div>
</div>
<p class="dash-subtitulo">
    Cada documento emitido se encola para enviarselo por correo a su receptor, con el XML y el PDF
    adjuntos. El envio corre solo cada pocos minutos; aqui puedes ver como va y devolver a la cola
    los que fallaron.
</p>

<?php if (! empty($flash['mensaje'])): ?>
    <p class="alerta alerta--<?= ($flash['tipo'] ?? '') === 'exito' ? 'exito' : 'error'; ?>" role="status">
        <span class="alerta__icono" aria-hidden="true"><?= ($flash['tipo'] ?? '') === 'exito' ? '&#10003;' : '&#9888;'; ?></span>
        <span><?= htmlspecialchars($flash['mensaje']); ?></span>
    </p>
<?php endif; ?>

<?php
// RESUMEN DE CONTEOS. Se calcula sobre TODA la cuenta, sin el filtro aplicado:
// contesta "fallo algo?" de un vistazo aunque estes mirando un solo estado.
//
// SON KPI DE VERDAD, no una .tarjeta con piezas prestadas. Cuatro contadores es
// exactamente lo que el componente .kpi describe, asi que se usa entero:
// article.kpi con su .kpi__etiqueta y su .kpi__valor, igual que el dashboard.
//
// La primera version usaba article.tarjeta con esas dos clases sueltas, y salia
// mal: .tarjeta h2 (0,1,1) le ganaba el font-size a .kpi__etiqueta (0,1,0) y el
// rotulo se pintaba a 20px contra un valor de 25,6px. Dentro de .kpi esa regla
// ya no matchea y el rotulo vuelve a --txt-xs sin escribir CSS nuevo.
//
// SIN MODIFICADOR DE ESTADO a proposito: nada de kpi--rojo ni kpi--ambar. Estos
// contadores no son un semaforo -- "1 con error" no tiene que gritar en rojo
// desde arriba, para eso esta el badge de cada fila. Ademas ahi vivia el defecto
// de cascada de la Ronda 12, donde .dash-page .kpi (0,2,0) pisaba a los
// modificadores (0,1,0) y los dejaba sin efecto.
//
// dash-grid A SECAS, sin --2: bajo .dash-page manda
// '.dash-page .dash-grid' (auto-fit, minimo 231px), que le gana a .dash-grid--2
// por especificidad. El modificador no hacia nada y solo confundia al leer el
// markup.
?>
<div class="dash-grid">
    <?php foreach (['pendiente' => 'Pendientes', 'enviado' => 'Enviados', 'error' => 'Con error', 'sin_destinatario' => 'Sin destinatario'] as $clave => $rotulo): ?>
        <article class="kpi">
            <h2 class="kpi__etiqueta"><?= htmlspecialchars($rotulo); ?></h2>
            <p class="kpi__valor"><?= (int) ($conteos[$clave] ?? 0); ?></p>
            <p class="kpi__formula">
                <?php if ($estado === $clave): ?>
                    filtrando por este estado &mdash; <a href="/ventas/correos">ver todos</a>
                <?php elseif ((int) ($conteos[$clave] ?? 0) > 0): ?>
                    <a href="/ventas/correos?estado=<?= urlencode($clave); ?>">ver solo estos</a>
                <?php else: ?>
                    &nbsp;
                <?php endif; ?>
            </p>
        </article>
    <?php endforeach; ?>
</div>

<?php
// ACCIONES MASIVAS. Cada boton aparece SOLO si su contador es mayor que cero:
// "Reintentar los 0 que fallaron" no es un boton, es ruido.
//
// LLEVAN EL NUMERO EN LA ETIQUETA porque apuntan a TODA la cuenta, no a la
// pagina visible ni al filtro activo. Sin el numero, el boton no diria cuanto va
// a mover, y estos dos mueven muchas filas de una vez.
//
// Los contadores salen de $conteos, que ya viene calculado sobre la cuenta
// entera y SIN el filtro aplicado -- justo lo que estos botones necesitan.
//
// .acciones-grupo es la clase que el panel ya usa para esto ("Fila de acciones:
// la principal primero, las secundarias al lado", 30+ vistas). Cero CSS nuevo, y
// nada de clases prestadas de otro componente.
?>
<?php if ($conteos['error'] > 0 || $conteos['sin_destinatario'] > 0): ?>
    <div class="acciones-grupo">
        <?php if ($conteos['error'] > 0): ?>
            <form method="post" action="/ventas/correos/reintentar-fallidos">
                <?= csrfInput(); ?>
                <button type="submit" class="boton-principal">
                    Reintentar <?= $conteos['error'] === 1 ? 'el que fallo' : 'los ' . (int) $conteos['error'] . ' que fallaron'; ?>
                </button>
            </form>
        <?php endif; ?>
        <?php if ($conteos['sin_destinatario'] > 0): ?>
            <form method="post" action="/ventas/correos/buscar-destinatarios">
                <?= csrfInput(); ?>
                <button type="submit" class="boton-secundario">
                    Buscar correo para <?= $conteos['sin_destinatario'] === 1 ? 'el que no tiene' : 'los ' . (int) $conteos['sin_destinatario'] . ' sin destinatario'; ?>
                </button>
            </form>
        <?php endif; ?>
    </div>
<?php endif; ?>

<form method="get" action="/ventas/correos" class="filtros">
    <label class="filtros__campo">Estado
        <select name="estado" class="filtros__input filtros__input--medio">
            <option value="">Todos</option>
            <?php foreach (['pendiente' => 'Pendiente', 'enviado' => 'Enviado', 'error' => 'Error', 'sin_destinatario' => 'Sin destinatario'] as $v => $n): ?>
                <option value="<?= $v; ?>" <?= $estado === $v ? 'selected' : ''; ?>><?= htmlspecialchars($n); ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button type="submit" class="boton-secundario">Filtrar</button>
    <?php if ($estado !== ''): ?>
        <a class="boton-texto" href="/ventas/correos">Limpiar filtro</a>
    <?php endif; ?>
</form>

<?php if ($items === []): ?>
    <div class="estado-vacio">
        <h2>No hay correos que mostrar</h2>
        <?php if ($estado !== ''): ?>
            <p>Ningun envio esta en estado "<?= htmlspecialchars($estado); ?>".</p>
            <p class="estado-vacio__acciones">
                <a class="boton-secundario" href="/ventas/correos">Ver todos</a>
            </p>
        <?php elseif ($totalCuenta === 0): ?>
            <p>Todavia no se ha encolado ningun correo. Cada documento que emitas se encola solo.</p>
            <p class="estado-vacio__acciones">
                <a class="boton-principal" href="/ventas/factura">Emitir factura</a>
                <a class="boton-secundario" href="/ventas/panel-emision">Ver documentos emitidos</a>
            </p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="tabla-scroll">
        <table class="tabla-datos">
            <caption>
                <?= $total; ?> envio<?= $total === 1 ? '' : 's'; ?><?= $estado !== '' ? ' en estado "' . htmlspecialchars($estado) . '"' : ''; ?><?php
                if ($totalPaginas > 1): ?> &middot; pagina <?= $pagina; ?> de <?= $totalPaginas; ?><?php endif; ?>
            </caption>
            <thead>
                <tr>
                    <th>Encolado</th>
                    <th>Documento</th>
                    <th>Receptor</th>
                    <th>Destinatario</th>
                    <th class="tabla-datos__estado">Estado</th>
                    <th class="tabla-datos__num">Intentos</th>
                    <th>Ultimo error</th>
                    <th>Cobro</th>
                    <th class="tabla-datos__acciones">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $it): ?>
                    <?php
                        $tipoDte = (int) $it['tipo_dte'];
                        [$claseBadge, $textoBadge] = $badgeEstado($it['estado'] ?? null);
                    ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $it['created_at']); ?></td>
                        <td>
                            <span class="badge badge--etiqueta"><?= htmlspecialchars(\Plantiflex\FacturacionCl\Enums\TipoDte::nombreDe($tipoDte)); ?></span>
                            <span class="tabla-datos__secundario">N <?= (int) $it['folio']; ?></span>
                        </td>
                        <td><?= htmlspecialchars((string) $it['receptor_rut']); ?></td>
                        <td><?= htmlspecialchars($fmt($it['destinatario'])); ?></td>
                        <td class="tabla-datos__estado">
                            <span class="badge <?= $claseBadge; ?>"><?= htmlspecialchars($textoBadge); ?></span>
                            <?php if (! empty($it['enviado_at'])): ?>
                                <span class="tabla-datos__secundario"><?= htmlspecialchars((string) $it['enviado_at']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="tabla-datos__num"><?= (int) $it['intentos']; ?></td>
                        <?php
                        // LA COLUMNA DE COBRO. Cinco lecturas, y la que importa
                        // es "esperando": ese correo NO se ha mandado y no
                        // aparece como fallido en ninguna otra parte, porque su
                        // fila sigue 'pendiente' con sus intentos intactos. Sin
                        // esta columna, un atasco de pasarela seria invisible.
                        $pagoEstado = (string) ($it['pago_estado'] ?? '');
                        $esperando  = $pagoEstado === 'pendiente' && $it['estado'] === 'pendiente';
                        $horas      = $esperando && ! empty($it['pago_desde'])
                            ? (int) floor((time() - strtotime((string) $it['pago_desde'])) / 3600)
                            : 0;
                        ?>
                        <td>
                            <?php if ($pagoEstado === ''): ?>
                                &mdash;
                            <?php elseif ($pagoEstado === 'creado'): ?>
                                <span class="badge badge--ok">Con link</span>
                            <?php elseif ($pagoEstado === 'pagado'): ?>
                                <span class="badge badge--ok">Pagada</span>
                            <?php elseif ($pagoEstado === 'omitido'): ?>
                                <span class="badge badge--neutro">Sin link</span>
                            <?php elseif ($pagoEstado === 'error'): ?>
                                <?php
                                // DESCUADRE DE MONTO: alguien pago un importe
                                // distinto del que se cobro. No se marca pagada
                                // -- seria mentir -- y no puede quedarse como un
                                // guion neutro: es lo mas grave que registra esta
                                // columna y necesita que una persona lo mire.
                                ?>
                                <span class="badge badge--error"
                                      title="<?= htmlspecialchars($fmt($it['pago_error'])); ?>">
                                    Revisar pago
                                </span>
                            <?php elseif ($esperando): ?>
                                <span class="badge <?= $horas >= 6 ? 'badge--error' : 'badge--aviso'; ?>"
                                      title="<?= htmlspecialchars($fmt($it['pago_error'])); ?>">
                                    Esperando<?= $horas >= 1 ? ' ' . $horas . ' h' : ''; ?>
                                </span>
                            <?php else: ?>
                                <span class="badge badge--neutro">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td class="tabla-datos__acciones">
                            <?php
                            // UNA ACCION POR ESTADO, y solo donde hace algo:
                            //
                            //   'error'            -> Reintentar. Vuelve a la cola.
                            //   'sin_destinatario' -> Buscar correo. Va al maestro
                            //                         de clientes a ver si el
                            //                         receptor tiene uno ahora.
                            //
                            // En los otros dos no hay nada que ofrecer:
                            // 'pendiente' ya esta en cola y 'enviado' seria un
                            // reenvio, que esta fuera de alcance.
                            ?>
                            <?php if ($esperando): ?>
                                <?php
                                // Va ANTES que las demas a proposito: un correo
                                // que espera al link no se ha mandado, y soltarlo
                                // es lo unico que lo desatasca cuando la pasarela
                                // no vuelve. Las otras acciones no aplican
                                // todavia porque la fila sigue 'pendiente'.
                                ?>
                                <form method="post" action="/ventas/correos/<?= (int) $it['id']; ?>/enviar-sin-link"
                                      onsubmit="return confirm('Se enviara esta factura SIN el boton de pago. Seguro?');">
                                    <?= csrfInput(); ?>
                                    <button type="submit" class="boton-secundario">Enviar sin link</button>
                                </form>
                            <?php elseif ($it['estado'] === 'error'): ?>
                                <form method="post" action="/ventas/correos/<?= (int) $it['id']; ?>/reintentar">
                                    <?= csrfInput(); ?>
                                    <button type="submit" class="boton-secundario">Reintentar</button>
                                </form>
                            <?php elseif ($it['estado'] === 'sin_destinatario'): ?>
                                <form method="post" action="/ventas/correos/<?= (int) $it['id']; ?>/buscar-destinatario">
                                    <?= csrfInput(); ?>
                                    <button type="submit" class="boton-secundario">Buscar correo</button>
                                </form>
                            <?php else: ?>
                                &mdash;
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPaginas > 1): ?>
        <p class="paginacion">
            <?php if ($pagina > 1): ?>
                <a class="boton-secundario" href="/ventas/correos?pagina=<?= $pagina - 1; ?><?= $qs; ?>">&larr; Anterior</a>
            <?php endif; ?>
            <span class="nota">Pagina <?= $pagina; ?> de <?= $totalPaginas; ?> (<?= $total; ?> envios)</span>
            <?php if ($pagina < $totalPaginas): ?>
                <a class="boton-secundario" href="/ventas/correos?pagina=<?= $pagina + 1; ?><?= $qs; ?>">Siguiente &rarr;</a>
            <?php endif; ?>
        </p>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
