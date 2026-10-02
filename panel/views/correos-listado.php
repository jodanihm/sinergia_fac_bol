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
 * ESPERA AL ENVIAR (seccion 14, Efectos de carga): el mismo patron de
 * pagos-config.php en todos los formularios de la pantalla. Ver el script.
 */
$titulo    = 'Envio de correos';
$bodyClase = 'dash-page';
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

<div class="dash-header">
    <div>
        <h1>Envio de correos</h1>
    </div>
</div>
<p class="dash-subtitulo">
    Cada documento emitido sale solo por correo a su receptor, con el XML y el PDF. Aqui ves como va.
</p>

<?php if (! empty($flash['mensaje'])): ?>
    <p class="alerta alerta--<?= $flashClase; ?>" role="<?= $flashClase === 'error' ? 'alert' : 'status'; ?>">
        <?= iconoSvg($flashClase === 'exito' ? 'check' : 'alerta', 20, 'alerta__icono'); ?>
        <span><?= $e((string) $flash['mensaje']); ?></span>
    </p>
<?php endif; ?>

<?php
// RESUMEN DE CONTEOS (las "stats" del patron de pagina). Se calcula sobre TODA
// la cuenta, sin el filtro aplicado: contesta "fallo algo?" de un vistazo
// aunque estes mirando un solo estado.
//
// SON KPI DE VERDAD: article.kpi con su .kpi__etiqueta y su .kpi__valor, igual
// que el dashboard. SIN MODIFICADOR DE ESTADO a proposito: estos contadores no
// son un semaforo -- "1 con error" no tiene que gritar en rojo desde arriba,
// para eso esta el badge de cada fila.
//
// .config-columnas--4 Y NO .dash-grid: el auto-fit de '.dash-page .dash-grid'
// (minimo 231px) los partia 3 + 1 en un monitor normal con el menu abierto.
// Cuatro en fila, dos bajo 1024px y uno bajo 720px.
?>
<div class="config-columnas config-columnas--4">
    <?php foreach ($nombresEstado as $clave => $rotulo): ?>
        <article class="kpi">
            <h2 class="kpi__etiqueta"><?= $e($rotulo); ?></h2>
            <p class="kpi__valor"><?= (int) ($conteos[$clave] ?? 0); ?></p>
            <p class="kpi__formula">
                <?php if ($estado === $clave): ?>
                    Filtrando por este estado &middot; <a href="/ventas/correos">Ver todos</a>
                <?php elseif ((int) ($conteos[$clave] ?? 0) > 0): ?>
                    <a href="/ventas/correos?estado=<?= urlencode($clave); ?>"
                       aria-label="Ver solo los envios en estado <?= $e($rotulo); ?>">Ver solo estos</a>
                <?php else: ?>
                    Ninguno
                <?php endif; ?>
            </p>
        </article>
    <?php endforeach; ?>
</div>

<?php
// ACCIONES MASIVAS. Cada boton aparece SOLO si su contador es mayor que cero:
// "Reintentar los 0 que fallaron" no es un boton, es ruido.
//
// LLEVAN EL NUMERO EN LA ETIQUETA y en la confirmacion porque apuntan a TODA la
// cuenta, no a la pagina visible ni al filtro activo. Los contadores salen de
// $conteos, que ya viene calculado sobre la cuenta entera y SIN el filtro.
?>
<?php if ($nError > 0 || $nSinDestino > 0): ?>
    <div class="acciones-grupo">
        <?php if ($nError > 0): ?>
            <form method="post" action="/ventas/correos/reintentar-fallidos" class="form-en-linea" data-espera
                  data-confirmar-titulo="<?= $nError === 1 ? 'Reintentar el correo que fallo?' : 'Reintentar los ' . $nError . ' correos que fallaron?'; ?>"
                  data-confirmar-texto="<?= $nError === 1 ? 'Vuelve a la cola y sale a su destinatario' : 'Vuelven a la cola y salen a sus destinatarios'; ?> en los proximos minutos. Un correo enviado no se puede retirar."
                  data-confirmar-boton="<?= $nError === 1 ? 'Si, reintentar el correo' : 'Si, reintentar ' . $nError . ' correos'; ?>">
                <?= csrfInput(); ?>
                <button type="submit" class="boton-principal">
                    Reintentar <?= $nError === 1 ? 'el que fallo' : 'los ' . $nError . ' que fallaron'; ?>
                </button>
            </form>
        <?php endif; ?>
        <?php if ($nSinDestino > 0): ?>
            <form method="post" action="/ventas/correos/buscar-destinatarios" class="form-en-linea" data-espera
                  data-confirmar-titulo="<?= $nSinDestino === 1 ? 'Buscar el correo del envio sin destinatario?' : 'Buscar el correo de los ' . $nSinDestino . ' envios sin destinatario?'; ?>"
                  data-confirmar-texto="Se busca el correo de cada receptor en tus clientes. Los que lo tengan salen en los proximos minutos; el resto sigue esperando."
                  data-confirmar-boton="Si, buscar correos">
                <?= csrfInput(); ?>
                <button type="submit" class="boton-secundario">
                    Buscar correo para <?= $nSinDestino === 1 ? 'el que no tiene' : 'los ' . $nSinDestino . ' sin destinatario'; ?>
                </button>
            </form>
        <?php endif; ?>
    </div>
<?php endif; ?>

<form method="get" action="/ventas/correos" class="filtros" aria-label="Filtrar envios" data-espera>
    <label class="filtros__campo">Estado
        <select name="estado" class="filtros__input filtros__input--medio">
            <option value="">Todos</option>
            <?php foreach (['pendiente' => 'Pendiente', 'enviado' => 'Enviado', 'error' => 'Error', 'sin_destinatario' => 'Sin destinatario'] as $v => $n): ?>
                <option value="<?= $v; ?>" <?= $estado === $v ? 'selected' : ''; ?>><?= $e($n); ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button type="submit" class="boton-secundario">Filtrar</button>
    <?php if ($estado !== ''): ?>
        <a class="boton-texto" href="/ventas/correos">Limpiar filtro</a>
    <?php endif; ?>
</form>

<?php if ($items === []): ?>
    <div class="estado-vacio estado-vacio--centrado">
        <?= iconoSvg('envio-correo', 32, 'estado-vacio__icono'); ?>
        <?php if ($estado !== ''): ?>
            <h2 class="estado-vacio__titulo">Ningun envio esta "<?= $e($nombresEstado[$estado] ?? $estado); ?>"</h2>
            <p>Con este filtro no hay nada que mostrar. Quitalo para ver todos los envios.</p>
            <p class="estado-vacio__acciones"><a class="boton-secundario" href="/ventas/correos">Ver todos</a></p>
        <?php elseif ((int) $totalCuenta === 0): ?>
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
    <div class="tabla-scroll">
        <table class="tabla-datos tabla-datos--cards tabla-datos--cards-auto">
            <caption>
                <?= (int) $total; ?> envio<?= (int) $total === 1 ? '' : 's'; ?><?= $estado !== '' ? ' en estado "' . $e($nombresEstado[$estado] ?? $estado) . '"' : ''; ?><?php
                if ($totalPaginas > 1): ?> &middot; pagina <?= (int) $pagina; ?> de <?= (int) $totalPaginas; ?><?php endif; ?>
            </caption>
            <thead>
                <tr>
                    <th scope="col">Encolado</th>
                    <th scope="col">Documento</th>
                    <th scope="col">Destinatario</th>
                    <th scope="col">Estado</th>
                    <th scope="col">Cobro</th>
                    <th scope="col" class="tabla-datos__acciones">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $it): ?>
                    <?php
                        $tipoDte = (int) $it['tipo_dte'];
                        $nombreDoc = \Plantiflex\FacturacionCl\Enums\TipoDte::nombreDe($tipoDte) . ' N° ' . (int) $it['folio'];
                        [$claseBadge, $textoBadge, $iconoBadge] = $badgeEstado($it['estado'] ?? null);
                        $intentos = (int) $it['intentos'];
                    ?>
                    <tr>
                        <td data-label="Encolado"><?= $fecha($it['created_at']); ?></td>
                        <td data-label="Documento">
                            <?= $e($nombreDoc); ?>
                            <span class="tabla-datos__secundario">RUT <?= $e((string) $it['receptor_rut']); ?></span>
                        </td>
                        <td data-label="Destinatario">
                            <?php if (($it['destinatario'] ?? '') !== '' && $it['destinatario'] !== null): ?>
                                <?= $e((string) $it['destinatario']); ?>
                            <?php else: ?>
                                <span class="tabla-datos__secundario">Sin correo</span>
                            <?php endif; ?>
                        </td>
                        <?php // Sin .tabla-datos__estado (nowrap): esta celda lleva lineas secundarias, como el ultimo error. El badge no se parte solo. ?>
                        <td data-label="Estado">
                            <span class="badge <?= $claseBadge; ?>"><?= iconoSvg($iconoBadge, 16, 'badge__icono'); ?><?= $e($textoBadge); ?></span>
                            <?php if (! empty($it['enviado_at'])): ?>
                                <span class="tabla-datos__secundario"><?= $fecha($it['enviado_at']); ?></span>
                            <?php endif; ?>
                            <?php if ($intentos > 0): ?>
                                <span class="tabla-datos__secundario"><?= $intentos; ?> intento<?= $intentos === 1 ? '' : 's'; ?></span>
                            <?php endif; ?>
                            <?php if (! empty($it['ultimo_error'])): ?>
                                <span class="tabla-datos__secundario">Ultimo error: <?= $e((string) $it['ultimo_error']); ?></span>
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
                                <span class="tabla-datos__secundario">Sin cobro en linea</span>
                            <?php elseif ($pagoEstado === 'creado'): ?>
                                <span class="badge badge--ok"><?= iconoSvg('check', 16, 'badge__icono'); ?>Con link</span>
                            <?php elseif ($pagoEstado === 'pagado'): ?>
                                <span class="badge badge--ok"><?= iconoSvg('check', 16, 'badge__icono'); ?>Pagada</span>
                            <?php elseif ($pagoEstado === 'omitido'): ?>
                                <span class="badge badge--neutro"><?= iconoSvg('sin-cargar', 16, 'badge__icono'); ?>Sin link</span>
                            <?php elseif ($pagoEstado === 'error'): ?>
                                <?php
                                // DESCUADRE DE MONTO: alguien pago un importe
                                // distinto del que se cobro. No se marca pagada
                                // -- seria mentir -- y no puede quedarse como un
                                // guion neutro: es lo mas grave que registra esta
                                // columna y necesita que una persona lo mire.
                                ?>
                                <span class="badge badge--error" title="<?= $e($pagoError); ?>"><?= iconoSvg('alerta', 16, 'badge__icono'); ?>Revisar pago</span>
                                <span class="tabla-datos__secundario">Revisa este pago en tu cuenta de la pasarela.</span>
                            <?php elseif ($esperando): ?>
                                <span class="badge <?= $horas >= 6 ? 'badge--error' : 'badge--advertencia'; ?>" title="<?= $e($pagoError); ?>">
                                    <?= iconoSvg('reloj', 16, 'badge__icono'); ?>Esperando<?= $horas >= 1 ? ' ' . $horas . ' h' : ''; ?>
                                </span>
                                <span class="tabla-datos__secundario">
                                    <?= $horas >= 6 ? 'La pasarela no responde: puedes enviarla sin link.' : 'Esperando el link de pago.'; ?>
                                </span>
                            <?php else: ?>
                                <span class="tabla-datos__secundario">Sin cobro en linea</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="" class="tabla-datos__acciones">
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
                                // Confirmacion con dialogo: el correo sale y no
                                // vuelve, y sale sin el boton de pago.
                                ?>
                                <form method="post" action="/ventas/correos/<?= (int) $it['id']; ?>/enviar-sin-link" class="form-en-linea" data-espera
                                      data-confirmar-titulo="Enviar la <?= $e($nombreDoc); ?> sin boton de pago?"
                                      data-confirmar-texto="El correo sale en la proxima pasada (cada 5 minutos) sin link de pago. Tu cliente tendra que pagarte por otro medio."
                                      data-confirmar-boton="Si, enviar sin link">
                                    <?= csrfInput(); ?>
                                    <button type="submit" class="boton-secundario"
                                            aria-label="Enviar la <?= $e($nombreDoc); ?> sin link de pago">Enviar sin link</button>
                                </form>
                            <?php elseif ($it['estado'] === 'error'): ?>
                                <form method="post" action="/ventas/correos/<?= (int) $it['id']; ?>/reintentar" class="form-en-linea" data-espera>
                                    <?= csrfInput(); ?>
                                    <button type="submit" class="boton-secundario"
                                            aria-label="Reintentar el envio de la <?= $e($nombreDoc); ?>">Reintentar</button>
                                </form>
                            <?php elseif ($it['estado'] === 'sin_destinatario'): ?>
                                <form method="post" action="/ventas/correos/<?= (int) $it['id']; ?>/buscar-destinatario" class="form-en-linea" data-espera>
                                    <?= csrfInput(); ?>
                                    <button type="submit" class="boton-secundario"
                                            aria-label="Buscar el correo del receptor de la <?= $e($nombreDoc); ?>">Buscar correo</button>
                                </form>
                            <?php else: ?>
                                <span class="tabla-datos__secundario">Sin accion</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPaginas > 1): ?>
        <nav class="paginacion" aria-label="Paginas de envios">
            <?php if ($pagina > 1): ?>
                <a class="boton-secundario" href="/ventas/correos?pagina=<?= $pagina - 1; ?><?= $qs; ?>">Pagina anterior</a>
            <?php endif; ?>
            <span class="nota" aria-current="page">Pagina <?= (int) $pagina; ?> de <?= (int) $totalPaginas; ?> (<?= (int) $total; ?> envios)</span>
            <?php if ($pagina < $totalPaginas): ?>
                <a class="boton-secundario" href="/ventas/correos?pagina=<?= $pagina + 1; ?><?= $qs; ?>">Pagina siguiente</a>
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
