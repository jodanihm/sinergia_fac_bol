<?php
/**
 * Configuracion > Produccion > Folios y CAF (ambiente de PRODUCCION).
 *
 * Estructuralmente identica a caf.php: mismas variables ($error, $flash,
 * $cafs), mismo handler parametrizado (procesarCafGet/Post), misma tabla,
 * mismo resumen por tipo y mismo formulario. Cambian la ruta del action, el
 * texto del boton, el color del badge y el panel lateral, que aqui advierte
 * que los folios son reales.
 *
 * A diferencia de certificacion, esta vista NO menciona el proceso aparte de la
 * boleta: esa aclaracion es propia de la certificacion y no estaba aqui.
 *
 * Igual que en certificacion: los numeros se muestran tal como llegan, el ENUM
 * de estado es 'activo'/'agotado', "Cargado/Falta" no es el estado del CAF, la
 * carga es de dos pasos (subir -> revisar -> confirmar) y el resultado llega
 * como flash, y la nota de migracion aparece solo cuando
 * proximo_folio_inicial es mayor que folio_desde.
 */
$titulo = 'CAF de produccion';
require __DIR__ . '/partials/header.php';

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

$tiposConCaf = [];
foreach ($cafs as $c) {
    $tiposConCaf[(int) $c['tipo_dte']] = true;
}
?>

<div class="dash-header">
    <div>
        <h1>Folios y CAF <span class="badge badge--advertencia">Produccion</span></h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <a class="boton-secundario" href="/panel">Volver al panel</a>
    </div>
</div>
<p class="dash-subtitulo">
    Administra los archivos CAF y los rangos de folios con los que emites documentos
    tributarios reales ante el SII.
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
                <?php foreach (catalogoTiposDte() as $tipo => $nombre): ?>
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
                    <h2>Aun no hay CAF de produccion cargados</h2>
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
        <div class="panel-info panel-info--advertencia">
            <p class="panel-info__titulo">
                <span class="panel-info__icono" aria-hidden="true">&#9888;</span>
                Ambiente de produccion
            </p>
            <p>Los folios que autoriza el CAF que subas aqui son <strong>REALES</strong>: cada
            documento timbrado con ellos es un documento tributario real ante el SII, no de
            prueba.</p>
            <p>NO reutilices como dato real un CAF de certificacion; solo hazlo si estas
            probando el mecanismo a proposito.</p>
            <p>Cada CAF autoriza UN tipo de documento y un rango de folios: si necesitas emitir
            varios tipos, debes subir un CAF por cada uno. El tipo y el rango se leen directo
            del archivo, no hace falta escribirlos.</p>
        </div>

        <section class="tarjeta" aria-labelledby="titulo-subir">
            <h2 id="titulo-subir">Subir un CAF de produccion nuevo</h2>
            <form method="post" action="/caf-produccion" enctype="multipart/form-data" class="form-compacto">
                <?= csrfInput(); ?>
                <div class="form-grid form-grid--1">
                    <div class="form-campo">
                        <label for="caf">Archivo CAF (.xml) <?= $req; ?></label>
                        <input type="file" name="caf" id="caf" accept=".xml" required>
                        <small class="form-ayuda">Descargado del SII para este ambiente.</small>
                    </div>
                </div>
                <div class="acciones-grupo">
                    <button type="submit" class="boton-principal">Subir CAF de produccion</button>
                </div>
            </form>
        </section>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
