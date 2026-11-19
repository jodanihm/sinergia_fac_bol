<?php
/**
 * Configuracion > Vista previa de facturas.
 *
 * TAREA DE LA PANTALLA: ver como salen hoy las facturas de la empresa -- logo,
 * razon social, giro, direccion, resolucion -- sin emitir nada, y saber donde
 * se cambia cada cosa.
 *
 * El PDF se pide aparte (/configuracion/vista-factura/pdf) y se incrusta: lo
 * dibuja el MISMO generador de las facturas reales (DtePdfEjemplo), con los
 * datos reales del emisor y un receptor, detalle y montos ficticios. Lleva
 * marca de agua "EJEMPLO", folio 0 y no consume folio.
 *
 * Recibe: $emisor (datosEmisorParaImpreso()), $resolucion, $tieneLogo, $navActivo.
 */
$titulo    = 'Vista previa de facturas';
$bodyClase = 'pagina-vista-factura';
require __DIR__ . '/partials/header.php';

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$emisor     = $emisor ?? [];
$resolucion = $resolucion ?? ['FchResol' => '', 'NroResol' => '0'];
$sinEmpresa = $emisor === [];
$urlPdf     = '/configuracion/vista-factura/pdf';

/* 76123456-7 -> 76.123.456-7, como se lee en el impreso. */
$rutConPuntos = static function (string $rut): string {
    $l = strtoupper((string) preg_replace('/[^0-9kK]/', '', $rut));
    if (strlen($l) < 2) {
        return $rut;
    }
    return number_format((int) substr($l, 0, -1), 0, ',', '.') . '-' . substr($l, -1);
};

/* Lo que sale en el impreso y de donde viene. [etiqueta, valor, ok]. */
$filas = [
    ['Razon social', (string) ($emisor['RznSoc'] ?? ''), ($emisor['RznSoc'] ?? '') !== ''],
    ['RUT', $rutConPuntos((string) ($emisor['RUTEmisor'] ?? '')), ($emisor['RUTEmisor'] ?? '') !== ''],
    ['Giro', (string) ($emisor['GiroEmis'] ?? ''), ($emisor['GiroEmis'] ?? '') !== ''],
    ['Direccion', trim((string) ($emisor['DirOrigen'] ?? '') . (($emisor['CmnaOrigen'] ?? '') !== '' ? ', ' . $emisor['CmnaOrigen'] : ''), ', '), ($emisor['DirOrigen'] ?? '') !== ''],
    ['Resolucion SII', $resolucion['NroResol'] !== '0' || $resolucion['FchResol'] !== '' ? 'N° ' . $resolucion['NroResol'] . ' de ' . substr($resolucion['FchResol'], 0, 4) : '', $resolucion['FchResol'] !== '' && $resolucion['NroResol'] !== ''],
];
?>

<div class="dash-header">
    <div>
        <h1>Vista previa de facturas</h1>
    </div>
</div>
<p class="dash-subtitulo">
    Asi se ven hoy las facturas que emites: con tu logo y los datos de tu empresa. El cliente, los productos y los montos son de ejemplo.
</p>

<?php if ($sinEmpresa): ?>
    <div class="alerta alerta--advertencia" role="status">
        <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
        <div class="alerta__cuerpo">
            <p class="alerta__titulo">Todavia no cargas los datos de tu empresa</p>
            <p>La vista previa usa datos genericos. Completa la ficha en <a href="/empresa">Configuracion empresa &rsaquo; Empresa</a> para ver tu factura real.</p>
        </div>
    </div>
<?php endif; ?>

<div class="vf">
    <section class="vf__papel" aria-labelledby="vf-titulo-pdf">
        <div class="vf__barra">
            <h2 id="vf-titulo-pdf" class="vf__barra-titulo">
                <?= iconoSvg('factura', 18, ''); ?>
                Factura electronica &middot; ejemplo
            </h2>
            <div class="vf__acciones">
                <a class="boton-secundario" href="<?= $urlPdf; ?>" target="_blank" rel="noopener"><?= iconoSvg('flecha', 16, ''); ?> Abrir en otra pestana</a>
                <a class="boton-principal" href="<?= $urlPdf; ?>" download="factura_ejemplo.pdf"><?= iconoSvg('descargar', 16, ''); ?> Descargar PDF</a>
            </div>
        </div>
        <div class="vf__marco">
            <?php /* <object> y no <iframe>: si el navegador no muestra PDF
                     incrustado (algunos moviles), cae al contenido de adentro,
                     un enlace para abrirlo. */ ?>
            <object class="vf__pdf" data="<?= $urlPdf; ?>#toolbar=0&amp;navpanes=0&amp;view=FitH" type="application/pdf" aria-label="Factura de ejemplo en PDF">
                <div class="vf__sin-visor">
                    <?= iconoSvg('factura', 32, ''); ?>
                    <p>Tu navegador no muestra el PDF aqui.</p>
                    <a class="boton-principal" href="<?= $urlPdf; ?>" target="_blank" rel="noopener">Abrir la factura de ejemplo</a>
                </div>
            </object>
        </div>
    </section>

    <aside class="vf__lado">
        <section class="tarjeta vf__tarjeta" aria-labelledby="vf-titulo-datos">
            <h2 id="vf-titulo-datos">Datos que salen en tus facturas</h2>
            <ul class="vf__datos">
                <li class="vf__dato">
                    <span class="vf__estado vf__estado--<?= $tieneLogo ? 'ok' : 'falta'; ?>" aria-hidden="true"><?= iconoSvg($tieneLogo ? 'check' : 'alerta', 14, ''); ?></span>
                    <span class="vf__dato-texto">
                        <span class="vf__dato-etiqueta">Logo</span>
                        <span class="vf__dato-valor"><?= $tieneLogo ? 'Cargado' : 'Sin logo: sale solo el nombre'; ?></span>
                    </span>
                </li>
                <?php foreach ($filas as [$etiqueta, $valor, $ok]): ?>
                    <li class="vf__dato">
                        <span class="vf__estado vf__estado--<?= $ok ? 'ok' : 'falta'; ?>" aria-hidden="true"><?= iconoSvg($ok ? 'check' : 'alerta', 14, ''); ?></span>
                        <span class="vf__dato-texto">
                            <span class="vf__dato-etiqueta"><?= $e($etiqueta); ?></span>
                            <span class="vf__dato-valor"><?= $valor !== '' ? $e($valor) : 'Sin completar'; ?></span>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="vf__cambiar">
                <a class="boton-secundario" href="/empresa"><?= iconoSvg('empresa', 16, ''); ?> Cambiar logo y datos</a>
            </p>
            <p class="form-ayuda">Los cambios se ven aqui de inmediato y en las facturas que emitas desde ese momento. Las ya emitidas no cambian.</p>
        </section>

        <section class="vf__nota" aria-labelledby="vf-titulo-nota">
            <h2 id="vf-titulo-nota" class="vf__nota-titulo"><?= iconoSvg('info', 18, ''); ?> Es solo un ejemplo</h2>
            <p>Lleva la marca &laquo;EJEMPLO&raquo;, folio 0 y un timbre que no es valido ante el SII. No se envia, no se guarda y no gasta folios.</p>
        </section>
    </aside>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
