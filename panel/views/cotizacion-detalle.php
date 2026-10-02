<?php
/**
 * Detalle de una cotizacion.
 *
 * TAREA DE LA PANTALLA, EN UNA FRASE: revisar una cotizacion (cliente, lineas,
 * total, lo que ya se facturo) y seguir con ella: mandar el PDF, editarla o
 * facturarla.
 *
 * Recibe: $cotizacion (con 'lineas'), $editable, $facturas, $flash, $navActivo.
 *
 * $editable NO SALE DE estado_cache. Lo calcula el handler con
 * tieneFacturacion(), que suma cantidad_facturada de las lineas. El cache es
 * para filtrar el listado con indice; si se desfasara, usarlo aqui dejaria
 * editar una cotizacion ya facturada y romperia el vinculo por id de linea de
 * las facturas emitidas. Ver la nota de la migracion 032.
 *
 * LA COLUMNA "PENDIENTE" se muestra siempre: la facturacion es 1:N por partes y
 * el saldo de cada linea es lo que dice cuanto queda por facturar.
 *
 * A TODO EL ANCHO, EN COLUMNAS: arriba Cliente y Totales lado a lado (los dos
 * se leen juntos: a quien y cuanto); el detalle a todo el ancho, porque es una
 * tabla de ocho columnas; abajo Facturas y Observaciones, lado a lado si hay
 * las dos. Bajo 720px las tablas pasan a tarjetas.
 *
 * UNA SOLA ACCION PRIMARIA, "Facturar cotizacion", y solo mientras quede algo
 * por facturar. Es un ENLACE: no emite nada, abre el formulario de emision
 * precargado. Por eso no lleva confirmacion ni espera.
 */
$titulo = 'Cotizacion N° ' . (int) $cotizacion['numero'];
require __DIR__ . '/partials/header.php';

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$monto = static fn ($n): string => '$' . number_format((float) $n, 0, ',', '.');
/** Fecha corta chilena dd/mm/aaaa (con hora si se pide). */
$fecha = static function ($valor, bool $conHora = false) use ($e): string {
    $ts = strtotime((string) $valor);
    return $ts === false ? $e((string) $valor) : date($conHora ? 'd/m/Y H:i' : 'd/m/Y', $ts);
};

// Cantidad con decimales solo cuando los tiene. Mismo criterio que el PDF: el
// saldo admite fracciones, asi que "1" no puede verse como "1,0000".
$cant = static function ($n): string {
    $f = (float) $n;

    return abs($f - round($f)) < 0.00005
        ? number_format($f, 0, ',', '.')
        : rtrim(rtrim(number_format($f, 4, ',', '.'), '0'), ',');
};

$neto = 0.0;
$exento = 0.0;
foreach ($cotizacion['lineas'] as $l) {
    $n = (float) $l['cantidad'] * (float) $l['precio_unitario']
        * (1 - ((float) $l['descuento_pct']) / 100);
    if (! empty($l['exento'])) {
        $exento += $n;
    } else {
        $neto += $n;
    }
}
$iva   = round($neto * 0.19);
$total = round($neto) + round($exento) + $iva;

$id      = (int) $cotizacion['id'];
$numero  = (int) $cotizacion['numero'];
$estado  = (string) $cotizacion['estado_cache'];
[$claseEstado, $textoEstado, $iconoEstado] = match ($estado) {
    'parcial'   => ['badge--advertencia', 'Facturada en parte', 'reloj'],
    'facturada' => ['badge--ok', 'Facturada', 'check'],
    default     => ['badge--neutro', 'Sin facturar', 'sin-cargar'],
};

$flashError = ! empty($flash['mensaje']) && ! in_array($flash['tipo'] ?? '', ['exito', 'advertencia'], true);
$flashClase = ($flash['tipo'] ?? '') === 'advertencia' ? 'advertencia' : ($flashError ? 'error' : 'exito');
$hayNotas   = trim((string) $cotizacion['notas']) !== '';
?>

<div class="dash-header">
    <div>
        <h1>
            Cotizacion N&deg; <?= $numero; ?>
            <span class="badge <?= $claseEstado; ?>"><?= iconoSvg($iconoEstado, 16, 'badge__icono'); ?><?= $e($textoEstado); ?></span>
        </h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <a class="boton-secundario" href="/ventas/cotizaciones/<?= $id; ?>/pdf" target="_blank" rel="noopener"
           aria-label="Ver el PDF de la cotizacion (se abre en otra pestana)">Ver PDF</a>
        <?php if ($editable): ?>
            <a class="boton-secundario" href="/ventas/cotizaciones/<?= $id; ?>/editar">Editar cotizacion</a>
        <?php endif; ?>
        <?php
        /* FACTURAR. Se ofrece mientras quede algo pendiente: la facturacion es
           1:N por partes, asi que una cotizacion a medias se sigue facturando.
           Es un ENLACE y no un boton de POST: no emite nada, solo abre el
           formulario de emision precargado -- mismo criterio que "Anular con
           nota de credito". Va el ULTIMO: es la accion primaria. */
        ?>
        <?php if ($estado !== 'facturada'): ?>
            <a class="boton-principal" href="/ventas/cotizaciones/<?= $id; ?>/facturar">Facturar cotizacion</a>
        <?php endif; ?>
    </div>
</div>
<p class="dash-subtitulo">
    <?= $e((string) $cotizacion['receptor_razon_social']); ?> &middot; <?= $fecha($cotizacion['fecha']); ?>.
    Documento interno: no pasa por el SII.
</p>

<?php if (! empty($flash['mensaje'])): ?>
    <p class="alerta alerta--<?= $flashClase; ?>" role="<?= $flashError ? 'alert' : 'status'; ?>">
        <?= iconoSvg($flashClase === 'exito' ? 'check' : 'alerta', 20, 'alerta__icono'); ?>
        <span><?= $e((string) $flash['mensaje']); ?></span>
    </p>
<?php endif; ?>

<?php if (! $editable): ?>
    <div class="alerta alerta--info">
        <?= iconoSvg('info', 20, 'alerta__icono'); ?>
        <div class="alerta__cuerpo">
            <p class="alerta__titulo">Esta cotizacion ya no se puede editar</p>
            <p>Tiene facturas emitidas, y cambiar sus lineas romperia el vinculo con ellas.
                <?= $estado !== 'facturada' ? 'Lo que queda pendiente se sigue facturando.' : ''; ?></p>
        </div>
    </div>
<?php endif; ?>

<div class="config-columnas config-columnas--2">
    <section class="tarjeta" aria-labelledby="titulo-cliente">
        <h2 id="titulo-cliente">Cliente</h2>
        <dl class="ficha">
            <dt>RUT</dt><dd><?= $e((string) $cotizacion['receptor_rut']); ?></dd>
            <dt>Razon social</dt><dd><?= $e((string) $cotizacion['receptor_razon_social']); ?></dd>
            <?php foreach ([
                'Giro'      => $cotizacion['receptor_giro'],
                'Direccion' => $cotizacion['receptor_direccion'],
                'Comuna'    => $cotizacion['receptor_comuna'],
                'Correo'    => $cotizacion['receptor_email'],
            ] as $etiqueta => $valor): ?>
                <?php if (trim((string) $valor) !== ''): ?>
                    <dt><?= $etiqueta; ?></dt><dd><?= $e((string) $valor); ?></dd>
                <?php endif; ?>
            <?php endforeach; ?>
            <dt>Fecha</dt><dd><?= $fecha($cotizacion['fecha']); ?></dd>
            <?php if (! empty($cotizacion['valida_hasta'])): ?>
                <dt>Valida hasta</dt><dd><?= $fecha($cotizacion['valida_hasta']); ?></dd>
            <?php endif; ?>
        </dl>
    </section>

    <section class="tarjeta" aria-labelledby="titulo-totales">
        <h2 id="titulo-totales">Totales</h2>
        <dl class="ficha ficha--montos">
            <?php if ($neto > 0): ?>
                <dt>Neto</dt><dd><?= $monto(round($neto)); ?></dd>
                <dt>IVA 19%</dt><dd><?= $monto($iva); ?></dd>
            <?php endif; ?>
            <?php if ($exento > 0): ?>
                <dt>Exento</dt><dd><?= $monto(round($exento)); ?></dd>
            <?php endif; ?>
            <dt class="ficha__total">Total</dt><dd class="ficha__total"><?= $monto($total); ?></dd>
        </dl>
        <p class="nota">
            El IVA se muestra para que tu cliente vea el mismo numero que le llegara en la factura.
            Una cotizacion no declara impuestos.
        </p>
    </section>
</div>

<section class="tarjeta" aria-labelledby="titulo-detalle">
    <h2 id="titulo-detalle">Detalle</h2>
    <div class="tabla-scroll">
        <table class="tabla-datos tabla-datos--cards tabla-datos--cards-auto">
            <caption class="visualmente-oculto">Lineas de la cotizacion, con lo facturado y lo pendiente de cada una</caption>
            <thead>
                <tr>
                    <th scope="col">Item</th>
                    <th scope="col" class="tabla-datos__num">Cantidad</th>
                    <th scope="col">Unidad</th>
                    <th scope="col" class="tabla-datos__num">P. unitario</th>
                    <th scope="col" class="tabla-datos__num">Desc.</th>
                    <th scope="col" class="tabla-datos__num">Facturado</th>
                    <th scope="col" class="tabla-datos__num">Pendiente</th>
                    <th scope="col" class="tabla-datos__num">Neto</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cotizacion['lineas'] as $l): ?>
                    <?php
                    $pendiente = (float) $l['cantidad'] - (float) $l['cantidad_facturada'];
                    $netoLinea = (float) $l['cantidad'] * (float) $l['precio_unitario']
                        * (1 - ((float) $l['descuento_pct']) / 100);
                    ?>
                    <tr>
                        <td data-label="Item">
                            <?= $e((string) $l['nombre']); ?>
                            <?php if (! empty($l['exento'])): ?>
                                <span class="tabla-datos__secundario">Exento de IVA</span>
                            <?php endif; ?>
                            <?php if (trim((string) $l['descripcion']) !== ''): ?>
                                <span class="tabla-datos__secundario"><?= $e((string) $l['descripcion']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Cantidad" class="tabla-datos__num"><?= $cant($l['cantidad']); ?></td>
                        <td data-label="Unidad"><?= $e((string) ($l['unidad'] ?? '')); ?></td>
                        <td data-label="P. unitario" class="tabla-datos__num"><?= $monto($l['precio_unitario']); ?></td>
                        <td data-label="Desc." class="tabla-datos__num"><?= ((float) $l['descuento_pct']) > 0 ? $cant($l['descuento_pct']) . '%' : 'Sin descuento'; ?></td>
                        <td data-label="Facturado" class="tabla-datos__num"><?= $cant($l['cantidad_facturada']); ?></td>
                        <td data-label="Pendiente" class="tabla-datos__num"><?= $cant($pendiente); ?></td>
                        <td data-label="Neto" class="tabla-datos__num"><?= $monto($netoLinea); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($facturas !== [] || $hayNotas): ?>
    <div class="config-columnas<?= $facturas !== [] && $hayNotas ? ' config-columnas--2' : ''; ?>">
        <?php if ($facturas !== []): ?>
            <section class="tarjeta" aria-labelledby="titulo-facturas">
                <h2 id="titulo-facturas">Facturas emitidas desde esta cotizacion</h2>
                <?php
                /* EL VINCULO VIVE EN cotizacion_factura, del lado del panel, y NO en
                   dte_emitido: esa es tabla del motor, sin cuenta_id y en otra familia de
                   collation, y la escribe el motor. Ver migracion 033. */
                ?>
                <div class="tabla-scroll">
                    <table class="tabla-datos tabla-datos--cards tabla-datos--cards-auto">
                        <caption class="visualmente-oculto"><?= count($facturas); ?> documento<?= count($facturas) === 1 ? '' : 's'; ?> emitido<?= count($facturas) === 1 ? '' : 's'; ?></caption>
                        <thead>
                            <tr>
                                <th scope="col">Documento</th>
                                <th scope="col">Emitido</th>
                                <th scope="col" class="tabla-datos__num">Cantidad</th>
                                <th scope="col" class="tabla-datos__acciones"><span class="visualmente-oculto">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($facturas as $f): ?>
                                <?php
                                    $tipoDte = (int) $f['tipo_dte'];
                                    $nombreDoc = \Plantiflex\FacturacionCl\Enums\TipoDte::nombreDe($tipoDte) . ' N° ' . (int) $f['folio'];
                                ?>
                                <tr>
                                    <td data-label="Documento"><?= $e($nombreDoc); ?></td>
                                    <td data-label="Emitido"><?= $fecha($f['created_at'], true); ?></td>
                                    <td data-label="Cantidad" class="tabla-datos__num"><?= $cant($f['cantidad_total']); ?></td>
                                    <td data-label="" class="tabla-datos__acciones">
                                        <a class="boton-texto" href="/ventas/panel-emision/<?= $tipoDte; ?>/<?= (int) $f['folio']; ?>"
                                           aria-label="Ver <?= $e($nombreDoc); ?>">Ver documento</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($hayNotas): ?>
            <section class="tarjeta" aria-labelledby="titulo-notas">
                <h2 id="titulo-notas">Observaciones</h2>
                <p><?= nl2br($e((string) $cotizacion['notas'])); ?></p>
            </section>
        <?php endif; ?>
    </div>
<?php endif; ?>

<p><a class="boton-texto" href="/ventas/cotizaciones">Volver a cotizaciones</a></p>

<?php require __DIR__ . '/partials/footer.php'; ?>
