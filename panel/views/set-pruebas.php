<?php
/**
 * Certificacion: archivo SIISetDePruebas del SII -- subir, revisar y emitir el
 * Set Basico.
 *
 * TAREA: subir el archivo, comprobar que el panel lo entendio y emitir. Por
 * eso arriba, en dos columnas, el archivo (subir / cual hay) y lo que sale de
 * el (numeros de atencion); despues el preview a todo el ancho, con los casos
 * del Set Basico en columnas; al final, emitir.
 *
 * UNA SOLA ACCION PRIMARIA: mientras no hay archivo, la primaria es subirlo;
 * con un archivo ya leido, la primaria pasa a ser "Emitir Set Basico" y subir
 * otro queda como secundaria.
 */
$titulo = 'Archivo de pruebas del SII';
require __DIR__ . '/partials/header.php';
$hayPreview = $parseado !== null;
?>

<div class="dash-header">
    <div>
        <h1>Archivo de pruebas del SII</h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <a class="boton-secundario" href="/certificacion">Volver a certificacion</a>
    </div>
</div>
<p class="dash-subtitulo">
    Sube el SIISetDePruebas que te entrego el SII, revisa el preview y emite el Set Basico.
</p>

<?php require __DIR__ . '/partials/certificacion/_flash.php'; ?>

<?php if (isset($flash['erroresConstruccion'])): ?>
<div class="alerta alerta--error" role="alert">
    <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
    <div class="alerta__cuerpo">
        <p class="alerta__titulo">No se emitio nada: estos casos no se pueden armar sin adivinar</p>
        <ul class="alerta__lista">
            <?php foreach ($flash['erroresConstruccion'] as $errorConstruccion): ?>
            <li><?= htmlspecialchars($errorConstruccion); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php endif; ?>

<?php if (isset($flash['resultadoEmision'])): ?>
<section class="tarjeta" aria-labelledby="titulo-emitidos">
    <h2 id="titulo-emitidos">Documentos emitidos</h2>
    <table class="tabla-datos tabla-datos--cards">
        <thead>
            <tr>
                <th scope="col">Tipo DTE</th>
                <th scope="col" class="tabla-datos__num">Folio</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($flash['resultadoEmision']['documentos'] as $doc): ?>
            <tr>
                <td data-label="Tipo DTE"><?= htmlspecialchars((string) $doc['tipoDte']); ?></td>
                <td data-label="Folio" class="tabla-datos__num"><?= htmlspecialchars((string) $doc['folio']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>

<?php foreach ([$error, $errorParseo] as $mensajeError): ?>
    <?php if ($mensajeError !== null): ?>
    <div class="alerta alerta--error" role="alert">
        <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
        <div class="alerta__cuerpo"><p><?= htmlspecialchars($mensajeError); ?></p></div>
    </div>
    <?php endif; ?>
<?php endforeach; ?>

<div class="config-columnas config-columnas--2">
    <section class="tarjeta" aria-labelledby="titulo-subir">
        <h2 id="titulo-subir"><?= $archivo !== null ? 'Reemplazar el archivo' : 'Subir el archivo'; ?></h2>
        <?php if ($archivo !== null): ?>
            <p class="tarjeta__intro">
                Archivo actual: <strong><?= htmlspecialchars($archivo['nombre_archivo']); ?></strong>,
                subido el <?= htmlspecialchars((string) $archivo['created_at']); ?>. Subir otro lo reemplaza.
            </p>
        <?php endif; ?>
        <form method="post" action="/certificacion/set-pruebas" enctype="multipart/form-data" class="form-compacto" data-espera novalidate>
            <?= csrfInput(); ?>
            <div class="form-campo">
                <label for="archivo">Archivo SIISetDePruebas (.txt)</label>
                <input type="file" name="archivo" id="archivo" accept=".txt" aria-describedby="ayuda-archivo"
                       data-requerido="Elige el archivo SIISetDePruebas que te entrego el SII.">
                <small class="form-ayuda" id="ayuda-archivo">El nombre es SIISetDePruebas seguido de tu RUT, en formato .txt.</small>
            </div>
            <div class="acciones-formulario">
                <button type="submit" class="<?= $hayPreview ? 'boton-secundario' : 'boton-principal'; ?>">Subir y revisar</button>
            </div>
        </form>
    </section>

    <?php if ($hayPreview): ?>
    <section class="tarjeta" aria-labelledby="titulo-atencion">
        <h2 id="titulo-atencion">Numeros de atencion</h2>
        <table class="tabla-datos">
            <tbody>
                <tr>
                    <th scope="row">Set Basico</th>
                    <td><?= htmlspecialchars((string) $parseado->numeroAtencionSetBasico); ?></td>
                </tr>
                <tr>
                    <th scope="row">Libro de Ventas</th>
                    <td><?= htmlspecialchars((string) ($parseado->numeroAtencionLibroVentas ?? 'No consta')); ?></td>
                </tr>
                <tr>
                    <th scope="row">Libro de Compras</th>
                    <td><?= htmlspecialchars((string) ($parseado->numeroAtencionLibroCompras ?? 'No consta')); ?></td>
                </tr>
            </tbody>
        </table>
    </section>
    <?php else: ?>
    <div class="panel-info">
        <p class="panel-info__titulo"><?= iconoSvg('info', 20, 'panel-info__icono'); ?>Que vas a ver despues</p>
        <p>Al subir el archivo, el panel muestra los numeros de atencion, cada caso del Set Basico con
        sus items y el Libro de Compras. Revisalo antes de emitir: emitir usa folios reales.</p>
    </div>
    <?php endif; ?>
</div>

<?php if ($hayPreview): ?>

<?php if ($parseado->advertencias !== []): ?>
<div class="alerta alerta--advertencia" role="alert">
    <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
    <div class="alerta__cuerpo">
        <p class="alerta__titulo"><?= count($parseado->advertencias); ?> linea(s) del archivo no se pudieron leer</p>
        <p>El resto del preview si refleja el archivo. Al emitir, el Set Basico se arma solo con lo que si se leyo.</p>
        <ul class="alerta__lista">
            <?php foreach ($parseado->advertencias as $advertencia): ?>
            <li><?= htmlspecialchars($advertencia); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php endif; ?>

<h2 class="titulo-seccion">Set Basico: <?= count($parseado->casos); ?> caso(s)</h2>
<div class="config-columnas config-columnas--2">
<?php foreach ($parseado->casos as $caso): ?>
    <section class="tarjeta" aria-labelledby="caso-<?= htmlspecialchars((string) $caso->numeroCaso); ?>">
        <h3 class="titulo-seccion" id="caso-<?= htmlspecialchars((string) $caso->numeroCaso); ?>">
            Caso <?= htmlspecialchars((string) $caso->numeroCaso); ?>: <?= htmlspecialchars($caso->tipoDocumento); ?>
        </h3>

        <?php if ($caso->referenciaCaso !== null): ?>
        <p class="tarjeta__intro">Referencia al caso <?= htmlspecialchars((string) $caso->referenciaCaso); ?>.
        Razon: <?= htmlspecialchars((string) ($caso->razonReferencia ?? 'no consta')); ?>.</p>
        <?php endif; ?>

        <?php if ($caso->items !== []): ?>
        <table class="tabla-datos tabla-datos--cards">
            <thead>
                <tr>
                    <th scope="col">Item</th>
                    <th scope="col" class="tabla-datos__num">Cantidad</th>
                    <th scope="col" class="tabla-datos__num">Precio unitario</th>
                    <th scope="col" class="tabla-datos__num">Descuento</th>
                    <th scope="col">Exento</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($caso->items as $item): ?>
                <tr>
                    <td data-label="Item"><?= htmlspecialchars($item->nombre); ?></td>
                    <td data-label="Cantidad" class="tabla-datos__num"><?= htmlspecialchars((string) $item->cantidad); ?></td>
                    <td data-label="Precio unitario" class="tabla-datos__num"><?= $item->precioUnitario !== null ? htmlspecialchars((string) $item->precioUnitario) : '<span class="tabla-datos__secundario">Sin precio</span>'; ?></td>
                    <td data-label="Descuento" class="tabla-datos__num"><?= $item->descuentoPorcentaje !== null ? htmlspecialchars((string) $item->descuentoPorcentaje) . '%' : '<span class="tabla-datos__secundario">Sin descuento</span>'; ?></td>
                    <td data-label="Exento"><?= $item->exento ? 'Si' : 'No'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p class="tarjeta__intro">Este caso no trae tabla de items.</p>
        <?php endif; ?>

        <?php if ($caso->descuentoGlobalPct !== null): ?>
        <p class="nota">Descuento global sobre items afectos: <strong><?= htmlspecialchars((string) $caso->descuentoGlobalPct); ?>%</strong></p>
        <?php endif; ?>
    </section>
<?php endforeach; ?>
</div>

<section class="tarjeta" aria-labelledby="titulo-libro-compras">
    <h2 id="titulo-libro-compras">Libro de Compras: <?= count($parseado->casosLibroCompras); ?> documento(s)</h2>
    <table class="tabla-datos tabla-datos--cards">
        <thead>
            <tr>
                <th scope="col">Tipo documento</th>
                <th scope="col" class="tabla-datos__num">Folio</th>
                <th scope="col">Observacion</th>
                <th scope="col" class="tabla-datos__num">Monto exento</th>
                <th scope="col" class="tabla-datos__num">Monto afecto</th>
                <th scope="col">Caso especial</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($parseado->casosLibroCompras as $lc): ?>
            <?php
                $flags = [];
                if ($lc->ivaUsoComun) {
                    $flags[] = 'IVA uso comun';
                }
                if ($lc->ivaNoRecuperable) {
                    $flags[] = 'IVA no recuperable (entrega gratuita)';
                }
                if ($lc->retencionTotalIva) {
                    $flags[] = 'Retencion total del IVA';
                }
                if ($lc->folioReferenciado !== null) {
                    $flags[] = 'Descuento a folio ' . $lc->folioReferenciado;
                }
            ?>
            <tr>
                <td data-label="Tipo documento"><?= htmlspecialchars($lc->tipoDocumentoTexto); ?></td>
                <td data-label="Folio" class="tabla-datos__num"><?= htmlspecialchars((string) $lc->folio); ?></td>
                <td data-label="Observacion"><?= htmlspecialchars($lc->observacion); ?></td>
                <td data-label="Monto exento" class="tabla-datos__num"><?= htmlspecialchars((string) $lc->montoExento); ?></td>
                <td data-label="Monto afecto" class="tabla-datos__num"><?= htmlspecialchars((string) $lc->montoAfecto); ?></td>
                <td data-label="Caso especial">
                <?php if ($flags !== []): ?>
                    <?php foreach ($flags as $flag): ?>
                        <span class="badge badge--advertencia"><?= iconoSvg('alerta', 16, 'badge__icono'); ?><?= htmlspecialchars($flag); ?></span>
                    <?php endforeach; ?>
                <?php else: ?>
                    <span class="tabla-datos__secundario">Ninguno</span>
                <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($parseado->factorProporcionalidadIvaUsoComun !== null): ?>
    <p class="nota">Factor de proporcionalidad del IVA uso comun: <strong><?= htmlspecialchars((string) $parseado->factorProporcionalidadIvaUsoComun); ?></strong></p>
    <?php endif; ?>
</section>

<section class="tarjeta" aria-labelledby="titulo-emision">
    <h2 id="titulo-emision">Emitir el Set Basico</h2>
    <p class="tarjeta__intro">
        Se envian los <?= count($parseado->casos); ?> documentos al SII en un solo envio, con folios
        reales del CAF. Si algun caso no se puede armar sin adivinar, no se emite nada y se te dice cual
        y por que.
        <?php if ($parseado->advertencias !== []): ?>
            Revisa antes las lineas que no se pudieron leer, arriba.
        <?php endif; ?>
    </p>
    <form method="post" action="/certificacion/set-pruebas/emitir" class="form-compacto" data-espera
          data-espera-texto="Enviando <?= count($parseado->casos); ?> documentos al SII. Puede tardar varios minutos: no cierres esta pagina."
          data-confirmar-titulo="Emitir el Set Basico al SII?"
          data-confirmar-texto="Se envian <?= count($parseado->casos); ?> documentos y se usan <?= count($parseado->casos); ?> folios reales del CAF de certificacion. Los folios usados no vuelven."
          data-confirmar-boton="Si, emitir Set Basico">
        <?= csrfInput(); ?>
        <div class="acciones-formulario">
            <button type="submit" class="boton-principal">Emitir Set Basico</button>
        </div>
    </form>
</section>

<?php endif; ?>

<?php require __DIR__ . '/partials/certificacion/_ux-formularios.php'; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
