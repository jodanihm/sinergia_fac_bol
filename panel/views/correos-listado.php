<?php
/**
 * Ventas > Envio de correos: cola de envio al receptor (tabla dte_envio_correo,
 * migracion 024).
 *
 * TAREA DE LA PANTALLA, EN UNA FRASE: saber si los documentos les llegaron por
 * correo a los clientes y destrabar los que no salieron.
 *
 * Recibe: $items, $conteos, $totalCuenta, $total, $pagina, $totalPaginas,
 * $estado, $flash, $navActivo. Todo de handleCorreosListadoGet(), que consulta
 * la base del panel DIRECTO -- la cola es suya, a diferencia de dte_emitido.
 *
 * Cada item trae: id, destinatario, estado, intentos, ultimo_error, enviado_at,
 * created_at (de la cola), tipo_dte, folio, receptor_rut (de dte_emitido, por
 * union numerica) y pago_estado, pago_desde, pago_error (cobro en linea).
 * destinatario, ultimo_error y enviado_at pueden ser null.
 *
 * NO MUESTRA LA RAZON SOCIAL DEL RECEPTOR, y es a proposito: vive en el maestro
 * de clientes, que esta en la OTRA familia de collation, y traerla obligaria al
 * rodeo de resolverRazonSocialReceptores(). El destinatario -- la direccion a la
 * que se mando o se mandara -- es ademas el dato que de verdad sirve para
 * diagnosticar un correo.
 *
 * SEIS COLUMNAS Y NO NUEVE: el RUT del receptor va bajo el documento, y los
 * intentos, la hora de envio y el ultimo error bajo el estado (el error solo
 * importa cuando el estado es Error, y ahi se lee junto a el). Con nueve, a
 * 1200px la tabla se cortaba detras de un scroll horizontal. Bajo 720px pasa
 * a tarjetas.
 *
 * CONFIRMACIONES (design system, Feedback: solo lo que no se deshace). Las dos
 * acciones masivas mandan correos a clientes de verdad -- un correo enviado no
 * vuelve -- y "Enviar sin link" manda la factura sin boton de pago. Las tres
 * piden confirmacion con un <dialog> que dice cuantos y que pasa, y un boton
 * que nombra la accion. Reintentar o buscar el correo de UNA fila no: es lo
 * mismo que haria el envio automatico, solo que antes.
 *
 * DISENO (2026-11-18): misma familia visual que el panel de emision y la
 * ficha (.pe-cabecera, .pe-estado, .pe-tabla). Los KPI son a la vez el filtro
 * por estado (cada tarjeta es un enlace); los problemas con accion masiva van
 * en un bloque "Requiere atencion" y solo aparece si hay algo que hacer. Cada
 * documento enlaza a su ficha, que muestra el mismo envio en detalle.
 *
 * ESPERA AL ENVIAR (seccion 14, Efectos de carga): el mismo patron de
 * pagos-config.php en todos los formularios de la pantalla. Ver el script.
 */
$titulo    = 'Envio de correos';
$bodyClase = 'dash-page pagina-correos';
require __DIR__ . '/partials/header.php';

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

/**
 * Un badge por estado: color + icono + texto (WCAG 1.4.1).
 *
 * 'sin_destinatario' va en ambar y no en rojo: el documento se emitio bien y
 * simplemente no habia a quien mandarselo. No es un fallo del envio.
 */
$badgeEstado = static function (?string $estado): array {
    return match (trim((string) $estado)) {
        'pendiente'        => ['badge--proceso', 'Pendiente', 'reloj'],
        'enviado'          => ['badge--ok', 'Enviado', 'check'],
        'error'            => ['badge--error', 'Error', 'alerta'],
        'sin_destinatario' => ['badge--advertencia', 'Sin destinatario', 'sin-cargar'],
        default            => ['badge--neutro', (string) $estado, 'sin-cargar'],
    };
};
$nombresEstado = ['pendiente' => 'Pendiente', 'enviado' => 'Enviado', 'error' => 'Con error', 'sin_destinatario' => 'Sin destinatario'];

/** Fecha y hora chilena dd/mm/aaaa hh:mm. */
$fecha = static function ($valor) use ($e): string {
    if ($valor === null || $valor === '') {
        return '';
    }
    $ts = strtotime((string) $valor);
    return $ts === false ? $e((string) $valor) : date('d/m/Y H:i', $ts);
};

// Query string que arrastra el filtro al cambiar de pagina. Sin esto, la
// paginacion navegaria sobre otro conjunto (mismo criterio que el panel de
// emision).
$qs = $estado !== '' ? '&estado=' . urlencode($estado) : '';

$flashTipo  = (string) ($flash['tipo'] ?? '');
$flashClase = $flashTipo === 'exito' ? 'exito' : ($flashTipo === 'advertencia' ? 'advertencia' : 'error');

$nError = (int) ($conteos['error'] ?? 0);
$nSinDestino = (int) ($conteos['sin_destinatario'] ?? 0);
?>

<?php
/* Tono, icono y texto de cada estado de la cola, para KPI, filtro y badge.
   'sin_destinatario' va en ambar y no en rojo: el documento se emitio bien y
   simplemente no habia a quien mandarselo. No es un fallo del envio. */
$tonosEstado = [
    'enviado'          => ['exito',   'check',      'Enviados',          'Llegaron al correo del cliente.'],
    'pendiente'        => ['proceso', 'reloj',      'En cola',           'Salen en la proxima pasada.'],
    'sin_destinatario' => ['alerta',  'sin-cargar', 'Sin destinatario',  'El cliente no tenia correo.'],
    'error'            => ['error',   'alerta',     'Con error',         'Fallaron al enviarse.'],
];
$totalCuentaInt = (int) $totalCuenta;
$pct = static fn (int $n): float => $totalCuentaInt > 0 ? round($n * 100 / $totalCuentaInt, 1) : 0.0;
$iconoTipo = static fn (int $t): string => match ($t) {
    39, 41  => 'boleta',
    56      => 'nota-debito',
    61      => 'nota-credito',
    default => 'factura',
};
?>

<div class="pe-cabecera">
    <div class="pe-cabecera__titulo">
        <span class="pe-cabecera__emblema" aria-hidden="true"><?= iconoSvg('envio-correo', 24, ''); ?></span>
        <div>
            <h1>Envio de correos</h1>
            <p class="pe-cabecera__sub">Cada documento emitido sale solo por correo a su receptor, con el XML y el PDF. Aqui ves como va.</p>
        </div>
    </div>
    <div class="pe-cabecera__acciones">
        <a class="boton-secundario pe-boton" href="/ventas/panel-emision"><?= iconoSvg('panel-emision', 16, ''); ?><span>Panel de emision</span></a>
    </div>
</div>

<?php if (! empty($flash['mensaje'])): ?>
    <p class="alerta alerta--<?= $flashClase; ?>" role="<?= $flashClase === 'error' ? 'alert' : 'status'; ?>">
        <?= iconoSvg($flashClase === 'exito' ? 'check' : 'alerta', 20, 'alerta__icono'); ?>
        <span><?= $e((string) $flash['mensaje']); ?></span>
    </p>
<?php endif; ?>

<?php
// RESUMEN DE CONTEOS. Se calcula sobre TODA la cuenta, sin el filtro aplicado:
// contesta "fallo algo?" de un vistazo aunque estes mirando un solo estado.
// Cada tarjeta es ademas el filtro de su estado (aria-current en la activa).
// El color va en el icono y en la barra, no en el numero: "1 con error" no
// tiene que gritar desde arriba, para eso esta el bloque de atencion.
?>
<?php if ($totalCuentaInt > 0): ?>
    <section class="co-resumen" aria-label="Resumen de envios">
        <div class="co-resumen__barra" role="img"
             aria-label="<?= (int) ($conteos['enviado'] ?? 0); ?> de <?= $totalCuentaInt; ?> correos enviados">
            <?php foreach ($tonosEstado as $clave => [$tono]): ?>
                <?php $n = (int) ($conteos[$clave] ?? 0); if ($n === 0) { continue; } ?>
                <span class="co-resumen__tramo co-tono--<?= $tono; ?>" style="--ancho: <?= $pct($n); ?>%"></span>
            <?php endforeach; ?>
        </div>
        <p class="co-resumen__leyenda">
            <strong><?= $pct((int) ($conteos['enviado'] ?? 0)); ?>%</strong> de tus <?= $totalCuentaInt; ?> correos ya llego a su cliente.
        </p>
    </section>
<?php endif; ?>

<nav class="co-kpis" aria-label="Filtrar envios por estado">
    <a class="co-kpi co-kpi--todos" href="/ventas/correos"<?= $estado === '' ? ' aria-current="true"' : ''; ?>>
        <span class="co-kpi__icono" aria-hidden="true"><?= iconoSvg('envio-correo', 18, ''); ?></span>
        <span class="co-kpi__etiqueta">Todos</span>
        <span class="co-kpi__valor"><?= $totalCuentaInt; ?></span>
        <span class="co-kpi__nota">Todos los envios de tu cuenta.</span>
    </a>
    <?php foreach ($tonosEstado as $clave => [$tono, $icono, $rotulo, $nota]): ?>
        <?php $n = (int) ($conteos[$clave] ?? 0); ?>
        <a class="co-kpi co-tono--<?= $tono; ?>" href="/ventas/correos?estado=<?= urlencode($clave); ?>"<?= $estado === $clave ? ' aria-current="true"' : ''; ?>
           aria-label="<?= $e($rotulo); ?>: <?= $n; ?>. Ver solo estos envios">
            <span class="co-kpi__icono" aria-hidden="true"><?= iconoSvg($icono, 18, ''); ?></span>
            <span class="co-kpi__etiqueta"><?= $e($rotulo); ?></span>
            <span class="co-kpi__valor"><?= $n; ?></span>
            <span class="co-kpi__nota"><?= $e($nota); ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<?php
// ACCIONES MASIVAS. Cada una aparece SOLO si su contador es mayor que cero:
// "Reintentar los 0 que fallaron" no es un boton, es ruido.
//
// LLEVAN EL NUMERO EN LA ETIQUETA y en la confirmacion porque apuntan a TODA la
// cuenta, no a la pagina visible ni al filtro activo. Los contadores salen de
// $conteos, que ya viene calculado sobre la cuenta entera y SIN el filtro.
?>
<?php if ($nError > 0 || $nSinDestino > 0): ?>
    <section class="co-atencion" aria-labelledby="co-atencion-titulo">
        <h2 id="co-atencion-titulo" class="co-atencion__titulo"><?= iconoSvg('alerta', 18, ''); ?>Requiere atencion</h2>
        <ul class="co-atencion__lista">
            <?php if ($nError > 0): ?>
                <li class="co-atencion__item co-tono--error">
                    <span class="co-atencion__icono" aria-hidden="true"><?= iconoSvg('alerta', 18, ''); ?></span>
                    <div class="co-atencion__texto">
                        <strong><?= $nError === 1 ? '1 correo no se pudo enviar' : $nError . ' correos no se pudieron enviar'; ?></strong>
                        <span>Vuelven a la cola y salen en los proximos minutos.</span>
                    </div>
                    <form method="post" action="/ventas/correos/reintentar-fallidos" class="form-en-linea" data-espera
                          data-confirmar-titulo="<?= $nError === 1 ? 'Reintentar el correo que fallo?' : 'Reintentar los ' . $nError . ' correos que fallaron?'; ?>"
                          data-confirmar-texto="<?= $nError === 1 ? 'Vuelve a la cola y sale a su destinatario' : 'Vuelven a la cola y salen a sus destinatarios'; ?> en los proximos minutos. Un correo enviado no se puede retirar."
                          data-confirmar-boton="<?= $nError === 1 ? 'Si, reintentar el correo' : 'Si, reintentar ' . $nError . ' correos'; ?>">
                        <?= csrfInput(); ?>
                        <button type="submit" class="boton-principal pe-boton">
                            <?= iconoSvg('rehacer', 16, ''); ?><span>Reintentar <?= $nError === 1 ? 'el que fallo' : 'los ' . $nError; ?></span>
                        </button>
                    </form>
                </li>
            <?php endif; ?>
            <?php if ($nSinDestino > 0): ?>
                <li class="co-atencion__item co-tono--alerta">
                    <span class="co-atencion__icono" aria-hidden="true"><?= iconoSvg('sin-cargar', 18, ''); ?></span>
                    <div class="co-atencion__texto">
                        <strong><?= $nSinDestino === 1 ? '1 envio sin correo del cliente' : $nSinDestino . ' envios sin correo del cliente'; ?></strong>
                        <span>Agrega el correo en tus <a href="/maestros/clientes">clientes</a> y buscalo de nuevo.</span>
                    </div>
                    <form method="post" action="/ventas/correos/buscar-destinatarios" class="form-en-linea" data-espera
                          data-confirmar-titulo="<?= $nSinDestino === 1 ? 'Buscar el correo del envio sin destinatario?' : 'Buscar el correo de los ' . $nSinDestino . ' envios sin destinatario?'; ?>"
                          data-confirmar-texto="Se busca el correo de cada receptor en tus clientes. Los que lo tengan salen en los proximos minutos; el resto sigue esperando."
                          data-confirmar-boton="Si, buscar correos">
                        <?= csrfInput(); ?>
                        <button type="submit" class="boton-secundario pe-boton">
                            <?= iconoSvg('buscar', 16, ''); ?><span>Buscar <?= $nSinDestino === 1 ? 'su correo' : 'sus correos'; ?></span>
                        </button>
                    </form>
                </li>
            <?php endif; ?>
        </ul>
    </section>
<?php endif; ?>

<?php if ($items === []): ?>
    <div class="estado-vacio estado-vacio--centrado">
        <?= iconoSvg('envio-correo', 32, 'estado-vacio__icono'); ?>
        <?php if ($estado !== ''): ?>
            <h2 class="estado-vacio__titulo">Ningun envio esta "<?= $e($nombresEstado[$estado] ?? $estado); ?>"</h2>
            <p>Con este filtro no hay nada que mostrar. Quitalo para ver todos los envios.</p>
            <p class="estado-vacio__acciones"><a class="boton-secundario" href="/ventas/correos">Ver todos</a></p>
        <?php elseif ($totalCuentaInt === 0): ?>
            <h2 class="estado-vacio__titulo">Todavia no sale ningun correo</h2>
            <p>Cada documento que emitas se encola solo y le llega por correo a tu cliente.</p>
            <p class="estado-vacio__acciones">
                <a class="boton-secundario" href="/ventas/factura">Emitir factura</a>
                <a class="boton-texto" href="/ventas/panel-emision">Ver documentos emitidos</a>
            </p>
        <?php else: ?>
            <h2 class="estado-vacio__titulo">No hay envios en esta pagina</h2>
            <p>Puede que la lista se haya acortado mientras mirabas.</p>
            <p class="estado-vacio__acciones"><a class="boton-secundario" href="/ventas/correos">Ir a la primera pagina</a></p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <section class="tarjeta pe-tabla co-tabla" aria-labelledby="titulo-envios">
        <div class="pe-tabla__cabecera">
            <h2 id="titulo-envios"><?= $estado !== '' ? $e($tonosEstado[$estado][2] ?? ($nombresEstado[$estado] ?? $estado)) : 'Envios'; ?></h2>
            <span class="pe-tabla__total"><?= (int) $total; ?> envio<?= (int) $total === 1 ? '' : 's'; ?><?php
                if ($totalPaginas > 1): ?> &middot; pagina <?= (int) $pagina; ?> de <?= (int) $totalPaginas; ?><?php endif; ?>
                <?php if ($estado !== ''): ?> &middot; <a href="/ventas/correos">Ver todos</a><?php endif; ?></span>
        </div>
        <div class="tabla-scroll">
            <table class="tabla-datos tabla-datos--cards tabla-datos--cards-auto tabla-documentos">
                <caption class="visualmente-oculto">
                    <?= (int) $total; ?> envio<?= (int) $total === 1 ? '' : 's'; ?><?= $estado !== '' ? ' en estado "' . $e($nombresEstado[$estado] ?? $estado) . '"' : ''; ?>
                </caption>
                <thead>
                    <tr>
                        <th scope="col">Documento</th>
                        <th scope="col">Destinatario</th>
                        <th scope="col">Estado del correo</th>
                        <th scope="col">Cobro</th>
                        <th scope="col">Encolado</th>
                        <th scope="col" class="tabla-datos__acciones"><span class="visualmente-oculto">Acciones</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $it): ?>
                        <?php
                            $tipoDte = (int) $it['tipo_dte'];
                            $folioIt = (int) $it['folio'];
                            $nombreDoc = \Plantiflex\FacturacionCl\Enums\TipoDte::nombreDe($tipoDte) . ' N° ' . $folioIt;
                            $intentos = (int) $it['intentos'];
                            [$tonoFila, $iconoFila] = $tonosEstado[$it['estado']] ?? ['neutro', 'info'];
                            [, $textoBadge] = $badgeEstado($it['estado'] ?? null);
                            $urlFicha = '/ventas/panel-emision/' . $tipoDte . '/' . $folioIt;
                        ?>
                        <tr<?= $it['estado'] === 'error' ? ' class="tabla-datos__fila--rechazada"' : ''; ?>>
                            <td data-label="Documento">
                                <div class="pe-doc">
                                    <span class="pe-doc__icono" aria-hidden="true"><?= iconoSvg($iconoTipo($tipoDte), 18, ''); ?></span>
                                    <div class="pe-doc__texto">
                                        <a class="pe-doc__folio" href="<?= $urlFicha; ?>" aria-label="Ver la ficha de <?= $e($nombreDoc); ?>"><?= $e($nombreDoc); ?></a>
                                        <span class="pe-doc__tipo">RUT <?= $e((string) $it['receptor_rut']); ?></span>
                                    </div>
                                </div>
                            </td>
                            <td data-label="Destinatario">
                                <?php if (($it['destinatario'] ?? '') !== '' && $it['destinatario'] !== null): ?>
                                    <span class="co-correo"><?= iconoSvg('envio-correo', 14, ''); ?><span><?= $e((string) $it['destinatario']); ?></span></span>
                                <?php else: ?>
                                    <span class="co-correo co-correo--vacio"><?= iconoSvg('sin-cargar', 14, ''); ?><span>Sin correo</span></span>
                                <?php endif; ?>
                            </td>
                            <?php // Sin .tabla-datos__estado (nowrap): esta celda lleva lineas secundarias, como el ultimo error. ?>
                            <td data-label="Estado del correo">
                                <span class="pe-estado pe-estado--<?= $tonoFila; ?> co-estado"><?= iconoSvg($iconoFila, 14, 'pe-estado__icono'); ?><span><?= $e($textoBadge); ?></span></span>
                                <?php if (! empty($it['enviado_at'])): ?>
                                    <span class="co-sub"><?= $fecha($it['enviado_at']); ?></span>
                                <?php endif; ?>
                                <?php if ($intentos > 0 && $it['estado'] !== 'enviado'): ?>
                                    <span class="co-sub"><?= $intentos; ?> intento<?= $intentos === 1 ? '' : 's'; ?></span>
                                <?php endif; ?>
                                <?php if (! empty($it['ultimo_error'])): ?>
                                    <span class="co-error" title="<?= $e((string) $it['ultimo_error']); ?>">Ultimo error: <?= $e((string) $it['ultimo_error']); ?></span>
                                <?php endif; ?>
                            </td>
                            <?php
                            // LA COLUMNA DE COBRO. Cinco lecturas, y la que importa
                            // es "esperando": ese correo NO se ha mandado y no
                            // aparece como fallido en ninguna otra parte, porque su
                            // fila sigue 'pendiente' con sus intentos intactos. Sin
                            // esta columna, un atasco de pasarela seria invisible.
                            //
                            // Lo que hay que hacer se dice en TEXTO bajo el badge, no
                            // solo en el title: en un celular no hay hover.
                            $pagoEstado = (string) ($it['pago_estado'] ?? '');
                            $esperando  = $pagoEstado === 'pendiente' && $it['estado'] === 'pendiente';
                            $horas      = $esperando && ! empty($it['pago_desde'])
                                ? (int) floor((time() - strtotime((string) $it['pago_desde'])) / 3600)
                                : 0;
                            $pagoError  = (string) ($it['pago_error'] ?? '');
                            ?>
                            <td data-label="Cobro">
                                <?php if ($pagoEstado === ''): ?>
                                    <span class="co-sub">Sin cobro en linea</span>
                                <?php elseif ($pagoEstado === 'creado'): ?>
                                    <span class="pe-estado pe-estado--proceso co-estado"><?= iconoSvg('check', 14, 'pe-estado__icono'); ?><span>Con link</span></span>
                                <?php elseif ($pagoEstado === 'pagado'): ?>
                                    <span class="pe-estado pe-estado--exito co-estado"><?= iconoSvg('check', 14, 'pe-estado__icono'); ?><span>Pagada</span></span>
                                <?php elseif ($pagoEstado === 'omitido'): ?>
                                    <span class="pe-estado pe-estado--neutro co-estado"><?= iconoSvg('sin-cargar', 14, 'pe-estado__icono'); ?><span>Sin link</span></span>
                                <?php elseif ($pagoEstado === 'error'): ?>
                                    <?php
                                    // DESCUADRE DE MONTO: alguien pago un importe
                                    // distinto del que se cobro. No se marca pagada
                                    // -- seria mentir -- y no puede quedarse como un
                                    // guion neutro: es lo mas grave que registra esta
                                    // columna y necesita que una persona lo mire.
                                    ?>
                                    <span class="pe-estado pe-estado--error co-estado" title="<?= $e($pagoError); ?>"><?= iconoSvg('alerta', 14, 'pe-estado__icono'); ?><span>Revisar pago</span></span>
                                    <span class="co-sub">Revisa este pago en tu cuenta de la pasarela.</span>
                                <?php elseif ($esperando): ?>
                                    <span class="pe-estado <?= $horas >= 6 ? 'pe-estado--error' : 'pe-estado--alerta'; ?> co-estado" title="<?= $e($pagoError); ?>">
                                        <?= iconoSvg('reloj', 14, 'pe-estado__icono'); ?><span>Esperando<?= $horas >= 1 ? ' ' . $horas . ' h' : ''; ?></span>
                                    </span>
                                    <span class="co-sub">
                                        <?= $horas >= 6 ? 'La pasarela no responde: puedes enviarla sin link.' : 'Esperando el link de pago.'; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="co-sub">Sin cobro en linea</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Encolado" class="tabla-datos__cifra">
                                <span class="pe-fecha"><?= iconoSvg('calendario', 14, ''); ?><?= $fecha($it['created_at']); ?></span>
                            </td>
                            <td data-label="" class="tabla-datos__acciones">
                                <div class="pe-acciones">
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
                                // reenvio, que esta fuera de alcance. Siempre queda
                                // el enlace a la ficha del documento.
                                ?>
                                <?php if ($esperando): ?>
                                    <?php
                                    // Va ANTES que las demas a proposito: un correo
                                    // que espera al link no se ha mandado, y soltarlo
                                    // es lo unico que lo desatasca cuando la pasarela
                                    // no vuelve. Confirmacion con dialogo: el correo
                                    // sale y no vuelve, y sale sin el boton de pago.
                                    ?>
                                    <form method="post" action="/ventas/correos/<?= (int) $it['id']; ?>/enviar-sin-link" class="form-en-linea" data-espera
                                          data-confirmar-titulo="Enviar la <?= $e($nombreDoc); ?> sin boton de pago?"
                                          data-confirmar-texto="El correo sale en la proxima pasada (cada 5 minutos) sin link de pago. Tu cliente tendra que pagarte por otro medio."
                                          data-confirmar-boton="Si, enviar sin link">
                                        <?= csrfInput(); ?>
                                        <button type="submit" class="co-accion co-accion--alerta"
                                                aria-label="Enviar la <?= $e($nombreDoc); ?> sin link de pago"><?= iconoSvg('enviar', 15, ''); ?><span>Enviar sin link</span></button>
                                    </form>
                                <?php elseif ($it['estado'] === 'error'): ?>
                                    <form method="post" action="/ventas/correos/<?= (int) $it['id']; ?>/reintentar" class="form-en-linea" data-espera>
                                        <?= csrfInput(); ?>
                                        <button type="submit" class="co-accion co-accion--error"
                                                aria-label="Reintentar el envio de la <?= $e($nombreDoc); ?>"><?= iconoSvg('rehacer', 15, 'pe-rehacer__icono'); ?><span>Reintentar</span></button>
                                    </form>
                                <?php elseif ($it['estado'] === 'sin_destinatario'): ?>
                                    <form method="post" action="/ventas/correos/<?= (int) $it['id']; ?>/buscar-destinatario" class="form-en-linea" data-espera>
                                        <?= csrfInput(); ?>
                                        <button type="submit" class="co-accion"
                                                aria-label="Buscar el correo del receptor de la <?= $e($nombreDoc); ?>"><?= iconoSvg('buscar', 15, ''); ?><span>Buscar correo</span></button>
                                    </form>
                                <?php endif; ?>
                                    <a class="pe-icono-accion pe-icono-accion--ver" href="<?= $urlFicha; ?>" title="Ver ficha del documento"
                                       aria-label="Ver la ficha de <?= $e($nombreDoc); ?>"><?= iconoSvg('chevron-der', 18, ''); ?></a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($totalPaginas > 1): ?>
        <nav class="paginacion pe-paginacion" aria-label="Paginas de envios">
            <?php if ($pagina > 1): ?>
                <a class="boton-secundario pe-boton" href="/ventas/correos?pagina=<?= $pagina - 1; ?><?= $qs; ?>"><?= iconoSvg('chevron-izq', 16, ''); ?><span>Anterior</span></a>
            <?php endif; ?>
            <span class="nota" aria-current="page">Pagina <?= (int) $pagina; ?> de <?= (int) $totalPaginas; ?> (<?= (int) $total; ?> envios)</span>
            <?php if ($pagina < $totalPaginas): ?>
                <a class="boton-secundario pe-boton" href="/ventas/correos?pagina=<?= $pagina + 1; ?><?= $qs; ?>"><span>Siguiente</span><?= iconoSvg('chevron-der', 16, ''); ?></a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<dialog class="dialogo-confirmar" id="dialogo-confirmar" aria-labelledby="dialogo-confirmar-titulo" aria-describedby="dialogo-confirmar-texto">
    <h2 class="dialogo-confirmar__titulo" id="dialogo-confirmar-titulo"></h2>
    <p id="dialogo-confirmar-texto"></p>
    <div class="acciones-formulario">
        <button type="button" class="boton-secundario" data-dialogo-volver>Volver</button>
        <button type="button" class="boton-principal" data-dialogo-confirmar></button>
    </div>
</dialog>

<script>
(function () {
    /* ESPERA AL ENVIAR -- seccion 14 del design system, Efectos de carga. El
       mismo patron de pagos-config.php para cada formulario con data-espera:
       el doble envio se corta en el acto pero sin nada visible; pasados 300 ms
       el boton entra en carga (spinner dentro, mismo ancho, aria-busy en el
       boton y en el formulario); el resultado lo anuncia la pantalla de
       llegada (flash con role="status"); el foco no se mueve durante la
       espera. */
    function ocupado(form, si) {
        var boton = form.querySelector('[type="submit"]');
        if (!boton) { return; }
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
    function esperar(form) {
        form.dataset.enviando = '1';
        form._umbral = setTimeout(function () { ocupado(form, true); }, 300);
    }
    /* Volver con "Atras" restaura la pagina tal como quedo: sin esto, con el
       boton girando para siempre. */
    window.addEventListener('pageshow', function (ev) {
        if (!ev.persisted) { return; }
        document.querySelectorAll('form[data-espera]').forEach(function (f) {
            clearTimeout(f._umbral);
            delete f.dataset.enviando;
            delete f.dataset.confirmado;
            ocupado(f, false);
        });
    });

    /* Confirmacion: <dialog> con titulo, consecuencia y un boton que nombra
       la accion. Escape cierra y el foco vuelve solo. Sin soporte de
       <dialog>, cae al confirm() nativo con el mismo texto. */
    var dialogo = document.getElementById('dialogo-confirmar');
    var pendiente = null;
    function confirmar(form) {
        var titulo = form.getAttribute('data-confirmar-titulo');
        var texto = form.getAttribute('data-confirmar-texto');
        if (!dialogo || typeof dialogo.showModal !== 'function') {
            return window.confirm(titulo + '\n\n' + texto);
        }
        pendiente = form;
        document.getElementById('dialogo-confirmar-titulo').textContent = titulo;
        document.getElementById('dialogo-confirmar-texto').textContent = texto;
        dialogo.querySelector('[data-dialogo-confirmar]').textContent = form.getAttribute('data-confirmar-boton');
        dialogo.showModal();
        // El foco entra en "Volver": un Enter apurado no manda nada.
        dialogo.querySelector('[data-dialogo-volver]').focus();
        return false;
    }
    if (dialogo) {
        dialogo.querySelector('[data-dialogo-volver]').addEventListener('click', function () { dialogo.close(); });
        dialogo.querySelector('[data-dialogo-confirmar]').addEventListener('click', function () {
            var form = pendiente;
            dialogo.close();
            if (form) {
                form.dataset.confirmado = '1';
                esperar(form);
                form.submit();
            }
        });
    }

    document.querySelectorAll('form[data-espera]').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            if (form.dataset.enviando) { ev.preventDefault(); return; }
            if (form.hasAttribute('data-confirmar-titulo') && !form.dataset.confirmado) {
                if (!confirmar(form)) { ev.preventDefault(); return; }
            }
            esperar(form);
        });
    });
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
