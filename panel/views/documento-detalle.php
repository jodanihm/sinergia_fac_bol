<?php
/**
 * Ficha permanente de un documento emitido (M5).
 *
 * Recibe: $tipoDte, $folio, $documento (array|null), $errorMotor (string|null),
 * $flash (array|null), $navActivo.
 *
 * $documento son los campos que devuelve GET /api/v1/dte del motor
 * (MySqlDteEmitidoRepository::listarPorPeriodo), mas receptorRazonSocial que
 * agrega el panel resolviendo el RUT contra el maestro de clientes:
 *   folio, tipoDte, fechaEmision, receptorRut, neto, iva, total, estado,
 *   trackId, folioRef, tipoDteRef, receptorRazonSocial.
 * trackId, folioRef, tipoDteRef y receptorRazonSocial pueden ser null.
 *
 * LO QUE NO SE MUESTRA PORQUE EL MOTOR NO LO ENTREGA:
 *   - Detalle de lineas. dte_emitido guarda el XML completo, pero el listado no
 *     lo devuelve y no existe endpoint que lo desarme. Para verlo hay que
 *     descargar el PDF o el XML.
 *   - Monto exento. dte_emitido solo tiene neto, iva y total.
 *   - Fecha, codigo y razon de la referencia. Solo se persisten folio_ref y
 *     tipo_dte_ref, asi que la referencia se muestra como tipo + folio.
 *   - Datos del emisor.
 * Nada de esto se deriva ni se inventa; queda documentado como pendiente.
 *
 * TIPO 39 (boleta): esta ficha es la unica pantalla del panel que muestra
 * boletas emitidas. Se soporta igual que el resto -- mismos campos, mismos
 * archivos, mismo estado -- y las acciones relacionadas se muestran bajo la
 * MISMA condicion que antes (documento encontrado), sin agregar ni quitar nada
 * especifico para el 39. El flujo de emision de boleta no se toca.
 */
$titulo = 'Documento ' . $tipoDte . '/' . $folio;
require __DIR__ . '/partials/header.php';

$nombresTipo = [33 => 'Factura', 61 => 'Nota de credito', 56 => 'Nota de debito', 39 => 'Boleta'];
$tipoNombre  = $nombresTipo[$tipoDte] ?? ('Documento tipo ' . $tipoDte);

$fmt = static function ($v): string {
    return $v === null || $v === '' ? '-' : htmlspecialchars((string) $v);
};
$fmtMonto = static function ($v): string {
    return '$ ' . number_format((float) $v, 0, ',', '.');
};

/**
 * Badge del estado. El estado NO tiene catalogo cerrado: al emitir el motor
 * escribe 'enviado', y al consultar guarda TAL CUAL el <ESTADO> que devuelve el
 * SII (EPR, RCT, RCH, SOK...), que puede ser cualquier codigo. Por eso solo se
 * colorean los tres valores que produce el propio codigo y cuyo significado
 * esta fuera de discusion; cualquier codigo del SII se muestra en neutro y con
 * su valor crudo, sin traducir. Pintar de verde un codigo que no entendemos
 * seria afirmar una aprobacion que nadie verifico.
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

// Query string comun para prellenar la referencia en el form de NC/ND (M3).
$qsRef = static function (string $codigo, string $razon) use ($tipoDte, $folio, $documento): string {
    return '?' . http_build_query([
        'ref_tipo'  => $tipoDte,
        'ref_folio' => $folio,
        'ref_fecha' => $documento['fechaEmision'] ?? '',
        'ref_codigo' => $codigo,
        'ref_razon'  => $razon,
    ]);
};

$rutaBase = '/ventas/panel-emision/' . $tipoDte . '/' . $folio;
?>

<div class="dash-header">
    <div>
        <h1><?= htmlspecialchars($tipoNombre); ?> N&deg; <?= $folio; ?></h1>
    </div>
    <a class="boton-secundario" href="/ventas/panel-emision">Volver al listado</a>
</div>
<p class="dash-subtitulo">Documento registrado en el panel de emision.</p>

<?php if (! empty($flash['mensaje'])): ?>
    <p class="alerta alerta--<?= ($flash['tipo'] ?? '') === 'exito' ? 'exito' : 'error'; ?>" role="status">
        <span class="alerta__icono" aria-hidden="true"><?= ($flash['tipo'] ?? '') === 'exito' ? '&#10003;' : '&#9888;'; ?></span>
        <span><?= htmlspecialchars($flash['mensaje']); ?></span>
    </p>
<?php endif; ?>

<?php if (! empty($errorMotor)): ?>
    <p class="alerta alerta--error" role="alert">
        <span class="alerta__icono" aria-hidden="true">&#9888;</span>
        <span><?= htmlspecialchars($errorMotor); ?></span>
    </p>
<?php endif; ?>

<?php if ($documento === null): ?>
    <div class="estado-vacio">
        <h2>No se encontro el documento</h2>
        <p>No hay un documento de tipo <?= $tipoDte; ?> con folio <?= $folio; ?> en tu cuenta.</p>
        <p class="estado-vacio__acciones">
            <a class="boton-principal" href="/ventas/panel-emision">Ir al listado de documentos</a>
        </p>
    </div>
<?php else: ?>
    <?php [$claseBadge, $textoBadge] = $badgeEstado($documento['estado'] ?? null); ?>

    <div class="layout-principal-lateral">
        <div>
            <section class="tarjeta" aria-labelledby="titulo-info">
                <h2 id="titulo-info">Informacion del documento</h2>
                <dl class="ficha">
                    <dt>Tipo</dt>
                    <dd><?= htmlspecialchars($tipoNombre); ?> (<?= $tipoDte; ?>)</dd>

                    <dt>Folio</dt>
                    <dd><?= $folio; ?></dd>

                    <dt>Fecha de emision</dt>
                    <dd><?= $fmt($documento['fechaEmision'] ?? null); ?></dd>

                    <dt>Receptor</dt>
                    <dd>
                        <?= $fmt($documento['receptorRut'] ?? null); ?>
                        <?php if (! empty($documento['receptorRazonSocial'])): ?>
                            <span class="tabla-datos__secundario"><?= htmlspecialchars((string) $documento['receptorRazonSocial']); ?></span>
                        <?php endif; ?>
                    </dd>

                    <?php if (! empty($documento['folioRef'])): ?>
                        <dt>Referencia</dt>
                        <dd>
                            <?= htmlspecialchars($nombresTipo[(int) $documento['tipoDteRef']] ?? ('Tipo ' . (int) $documento['tipoDteRef'])); ?>
                            N&deg; <?= (int) $documento['folioRef']; ?>
                        </dd>
                    <?php endif; ?>
                </dl>
                <?php if (empty($documento['receptorRazonSocial'])): ?>
                    <p class="nota">La razon social se muestra cuando el receptor esta en tus <a href="/maestros/clientes">clientes</a>.</p>
                <?php endif; ?>
            </section>

            <section class="tarjeta" aria-labelledby="titulo-archivos">
                <h2 id="titulo-archivos">Archivos y acciones</h2>
                <p class="nota">El detalle de las lineas del documento esta en el PDF y en el XML.</p>
                <div class="acciones-grupo">
                    <a class="boton-secundario" href="<?= htmlspecialchars($rutaBase); ?>/pdf" target="_blank" rel="noopener">Descargar PDF</a>
                    <a class="boton-secundario" href="<?= htmlspecialchars($rutaBase); ?>/xml">Descargar XML</a>
                </div>

                <h3 class="titulo-sub">Acciones relacionadas</h3>
                <p class="nota">Abren el formulario de emision con la referencia a este documento ya cargada.</p>
                <div class="acciones-grupo">
                    <a class="boton-secundario" href="/ventas/nota-credito<?= htmlspecialchars($qsRef('1', 'Anula documento N ' . $folio)); ?>">Anular con nota de credito</a>
                    <a class="boton-secundario" href="/ventas/nota-credito<?= htmlspecialchars($qsRef('3', '')); ?>">Corregir con nota de credito</a>
                    <a class="boton-secundario" href="/ventas/nota-debito<?= htmlspecialchars($qsRef('', '')); ?>">Nota de debito de referencia</a>
                </div>
            </section>
        </div>

        <div>
            <section class="tarjeta" aria-labelledby="titulo-estado">
                <h2 id="titulo-estado">Estado en el SII</h2>
                <p><span class="badge <?= $claseBadge; ?>"><?= htmlspecialchars($textoBadge); ?></span></p>
                <dl class="ficha ficha--compacta">
                    <dt>Track ID</dt>
                    <dd><?= $fmt($documento['trackId'] ?? null); ?></dd>
                </dl>
                <form method="post" action="<?= htmlspecialchars($rutaBase); ?>/estado-sii">
                    <?= csrfInput(); ?>
                    <button type="submit" class="boton-secundario">Consultar estado SII</button>
                </form>
                <p class="nota">Consulta el envio en el SII con el Track ID y guarda el estado que responda, tal cual.</p>
            </section>

            <section class="tarjeta" aria-labelledby="titulo-totales">
                <h2 id="titulo-totales">Totales del documento</h2>
                <dl class="ficha ficha--montos">
                    <dt>Neto</dt>
                    <dd><?= $fmtMonto($documento['neto'] ?? 0); ?></dd>
                    <dt>IVA</dt>
                    <dd><?= $fmtMonto($documento['iva'] ?? 0); ?></dd>
                    <dt class="ficha__total">Total</dt>
                    <dd class="ficha__total"><?= $fmtMonto($documento['total'] ?? 0); ?></dd>
                </dl>
            </section>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
