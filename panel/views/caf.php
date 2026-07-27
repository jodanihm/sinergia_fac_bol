<?php
/**
 * Configuracion > Folios y CAF (ambiente de CERTIFICACION).
 *
 * Recibe: $error (string|null), $flash (array|null) y $cafs (list<array>), de
 * procesarCafGet/Post(). Cada CAF trae exactamente lo que devuelve
 * listarCafs():
 *   tipo_dte, folio_desde, folio_hasta, estado, proximo_folio,
 *   proximo_folio_inicial
 * mas folios_restantes, que la propia funcion calcula como
 * folio_hasta - proximo_folio + 1. Aqui NO se recalcula nada: los cuatro
 * numeros se muestran tal como llegan.
 *
 * MIGRADO: proximo_folio_inicial guarda el folio con el que ARRANCO el
 * contador. En un CAF normal vale igual que folio_desde; cuando el emisor
 * venia de otro proveedor y declaro un folio inicial distinto, vale mas. Ese
 * es el unico caso en que la fila muestra la nota de migracion, porque es el
 * unico en que el rango del archivo y el rango que Sinergia va a usar no
 * coinciden.
 *
 * ESTADO: la columna de dte_caf es un ENUM cerrado -- 'activo' o 'agotado'
 * (default 'activo'; MySqlFolioRepository lo pasa a 'agotado' al consumirse el
 * rango). Por eso se pueden mapear los dos con semantica; cualquier otro valor
 * caeria en neutro con su texto crudo.
 *
 * "CARGADO / FALTA" del resumen por tipo NO es el estado del CAF: solo dice si
 * existe al menos un CAF de ese tipo en la lista. Un tipo puede figurar como
 * CARGADO y tener su unico CAF agotado. Son dos conceptos distintos y por eso
 * no comparten badge.
 *
 * VARIOS CAF POR TIPO: el UNIQUE de dte_caf es por (rut, tipo, ambiente,
 * folio_desde, folio_hasta), asi que un mismo tipo puede tener varios rangos.
 * La tabla los lista todos, en el orden del repositorio (tipo ASC, desde ASC),
 * sin agrupar ni ocultar ninguno.
 *
 * MENSAJE DE RESULTADO: la carga es de dos pasos (subir -> revisar en
 * caf-revision.php -> confirmar). El paso de confirmacion redirige aqui con un
 * flash, de exito o de error, y por eso esta vista si puede confirmar el
 * resultado. Antes no podia: la carga era de un solo paso y redirigia sin
 * flash.
 */
$titulo = 'CAF de certificacion';
require __DIR__ . '/partials/header.php';

/** Badge del estado del CAF. ENUM cerrado; el default cubre un valor inesperado. */
$badgeEstado = static function (string $estado): array {
    return match ($estado) {
        'activo'  => ['badge--ok', 'Activo'],
        'agotado' => ['badge--neutro', 'Agotado'],
        default   => ['badge--neutro', $estado],
    };
};

/* Separador de miles SOLO para la cantidad de folios restantes. El rango y el
   proximo folio son identificadores, no cantidades, y se muestran crudos igual
   que el folio en el panel de emision. */
$fmtNum = static function ($v): string {
    return number_format((float) $v, 0, ',', '.');
};

$req = '<span class="campo-obligatorio" aria-hidden="true">*</span>'
    . '<span class="visualmente-oculto">(obligatorio)</span>';

// Tipos que ya tienen al menos un CAF en la lista.
$tiposConCaf = [];
foreach ($cafs as $c) {
    $tiposConCaf[(int) $c['tipo_dte']] = true;
}
?>

<div class="dash-header">
    <div>
        <h1>Folios y CAF <span class="badge badge--etiqueta">Certificacion</span></h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <a class="boton-secundario" href="/panel">Volver al panel</a>
    </div>
</div>
<p class="dash-subtitulo">
    Administra los archivos CAF y los rangos de folios disponibles para emitir en el
    ambiente de certificacion.
</p>

<?php if (! empty($flash['mensaje'])): ?>
    <p class="alerta alerta--<?= ($flash['tipo'] ?? '') === 'exito' ? 'exito' : 'error'; ?>" role="status">
        <span class="alerta__icono" aria-hidden="true"><?= ($flash['tipo'] ?? '') === 'exito' ? '&#10003;' : '&#9888;'; ?></span>
        <span><?= htmlspecialchars($flash['mensaje']); ?></span>
    </p>
<?php endif; ?>

<?php if (! empty($error)): ?>
    <p class="alerta alerta--error" role="alert">
        <span class="alerta__icono" aria-hidden="true">&#9888;</span>
        <span><?= htmlspecialchars($error); ?></span>
    </p>
<?php endif; ?>

<div class="layout-principal-lateral">
    <div>
        <section class="tarjeta" aria-labelledby="titulo-tipos">
            <h2 id="titulo-tipos">Tipos de documento</h2>
            <ul class="validacion">
                <?php foreach (NOMBRES_TIPO_DTE as $tipo => $nombre): ?>
                    <?php $cargado = isset($tiposConCaf[$tipo]); ?>
                    <li class="validacion__item">
                        <span><?= htmlspecialchars($nombre); ?> (<?= (int) $tipo; ?>)</span>
                        <?php if ($cargado): ?>
                            <span class="badge badge--ok">Cargado</span>
                        <?php else: ?>
                            <span class="badge badge--advertencia">Falta</span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="nota">"Cargado" significa que existe al menos un CAF de ese tipo, no que
            queden folios disponibles.</p>
        </section>

        <section class="tarjeta" aria-labelledby="titulo-cafs">
            <h2 id="titulo-cafs">CAF cargados</h2>
            <?php if ($cafs === []): ?>
                <div class="estado-vacio">
                    <h2>Aun no hay CAF cargados</h2>
                    <p>Sube tu primer archivo CAF para habilitar la emision en este ambiente.</p>
                </div>
            <?php else: ?>
                <div class="tabla-scroll">
                    <table class="tabla-datos">
                        <caption><?= count($cafs); ?> CAF cargado<?= count($cafs) === 1 ? '' : 's'; ?></caption>
                        <thead>
                            <tr>
                                <th>Tipo</th>
                                <th class="tabla-datos__num">Rango</th>
                                <th class="tabla-datos__num">Proximo folio</th>
                                <th class="tabla-datos__num">Folios restantes</th>
                                <th class="tabla-datos__estado">Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cafs as $c): ?>
                                <?php [$claseBadge, $textoBadge] = $badgeEstado((string) $c['estado']); ?>
                                <?php $folioInicial = (int) $c['proximo_folio_inicial']; ?>
                                <tr>
                                    <td><span class="badge badge--etiqueta"><?= htmlspecialchars(nombreTipoDte((int) $c['tipo_dte'])); ?></span></td>
                                    <td class="tabla-datos__num">
                                        <?= (int) $c['folio_desde']; ?>&ndash;<?= (int) $c['folio_hasta']; ?>
                                        <?php if ($folioInicial > (int) $c['folio_desde']): ?>
                                            <div class="nota">Migrado: Sinergia emite desde el <?= $folioInicial; ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="tabla-datos__num"><?= (int) $c['proximo_folio']; ?></td>
                                    <td class="tabla-datos__num"><?= $fmtNum($c['folios_restantes']); ?></td>
                                    <td class="tabla-datos__estado"><span class="badge <?= $claseBadge; ?>"><?= htmlspecialchars($textoBadge); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <div>
        <div class="panel-info">
            <p class="panel-info__titulo">
                <span class="panel-info__icono" aria-hidden="true">&#9432;</span>
                Ambiente de certificacion
            </p>
            <ul class="panel-info__lista">
                <li>Cada CAF autoriza UN tipo de documento y un rango de folios: para emitir varios tipos necesitas un CAF por cada uno.</li>
                <li>El tipo y el rango se leen directo del archivo; no hace falta escribirlos.</li>
                <li>La boleta (39) suele certificarse en un proceso aparte del resto (factura, NC y ND) ante el SII.</li>
                <li>Estos folios son independientes de los de produccion.</li>
            </ul>
        </div>

        <section class="tarjeta" aria-labelledby="titulo-subir">
            <h2 id="titulo-subir">Subir un CAF nuevo</h2>
            <form method="post" action="/caf" enctype="multipart/form-data" class="form-compacto">
                <?= csrfInput(); ?>
                <div class="form-grid form-grid--1">
                    <div class="form-campo">
                        <label for="caf">Archivo CAF (.xml) <?= $req; ?></label>
                        <input type="file" name="caf" id="caf" accept=".xml" required>
                        <small class="form-ayuda">Descargado del SII para este ambiente.</small>
                    </div>
                </div>
                <div class="acciones-grupo">
                    <button type="submit" class="boton-principal">Subir CAF</button>
                </div>
            </form>
        </section>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
