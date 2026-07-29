<?php
/**
 * Configuracion > Produccion > Datos de la empresa (ambiente de PRODUCCION).
 *
 * Recibe: $emisor, $errores, $yaConfigurado (bool) y $produccion (array, solo
 * cuando $yaConfigurado es true).
 *
 * DOS MODOS, tal como antes:
 *   - $yaConfigurado === true : ficha de solo lectura con los 8 datos guardados.
 *     El flujo actual NO permite editarlos desde aqui, asi que no se ofrece un
 *     boton de editar que no existe.
 *   - $yaConfigurado === false: formulario. $rutEditable decide si son 7 u 8
 *     campos, y es lo unico que cambia entre los dos caminos de alta:
 *
 *       $rutEditable === false (hay fila de certificacion, el caso de siempre):
 *         el RUT NO es un input. Se hereda de esa fila y el handler lo toma de
 *         ahi, no del POST. Se muestra como dato de lectura, no como input
 *         disabled, porque un disabled sugeriria que es un campo del formulario
 *         y no lo es. Lo que este camino RENDERIZA no cambio: lo unico que se
 *         movio es la sangria que emiten los <?php if ?> nuevos, que el
 *         navegador colapsa.
 *
 *       $rutEditable === true (empresa ya autorizada por el SII, sin fila de
 *         certificacion): el RUT SI es un input, porque no hay ninguna otra
 *         fuente de donde sacarlo. El handler lo valida con Rut::normalizar()
 *         mas Rut::valido(), igual que /empresa.
 *
 * DIFERENCIA NORMATIVA CON CERTIFICACION: aqui la fecha y el numero de
 * resolucion son los de la autorizacion REAL que entrego el SII, no los de la
 * postulacion. Los textos de ayuda no son intercambiables con los de
 * empresa.php.
 *
 * El aviso de ambiente usa .panel-info--advertencia en lugar del bloque
 * .seccion-manual anterior, conservando su contenido.
 */
$titulo = 'Datos de la empresa (PRODUCCION)';
require __DIR__ . '/partials/header.php';

$val = static function (string $campo) use ($emisor): string {
    return htmlspecialchars((string) ($emisor[$campo] ?? ''));
};
$err = static function (string $campo) use ($errores): ?string {
    return $errores[$campo] ?? null;
};
$claseCampo = static function (string $campo, string $extra = '') use ($errores): string {
    $c = 'form-campo' . ($extra !== '' ? ' ' . $extra : '');
    return isset($errores[$campo]) ? $c . ' form-campo--error' : $c;
};

$req = '<span class="campo-obligatorio" aria-hidden="true">*</span>'
    . '<span class="visualmente-oculto">(obligatorio)</span>';

/** Panel de ambiente. Identico en los dos modos: la advertencia no depende de si ya se configuro. */
$panelAmbiente = static function (): void { ?>
    <div class="panel-info panel-info--advertencia">
        <p class="panel-info__titulo">
            <span class="panel-info__icono" aria-hidden="true">&#9888;</span>
            Ambiente de produccion
        </p>
        <p>Esta pagina es del ambiente de PRODUCCION (palena.sii.cl).</p>
        <p>La Resolucion que registres aqui es la autorizacion <strong>REAL</strong> que el SII
        te entrego por correo o portal al certificarte: no la inventes ni copies la de
        certificacion.</p>
    </div>
<?php };
?>

<div class="dash-header">
    <div>
        <h1>Datos de la empresa <span class="badge badge--advertencia">Produccion</span></h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <a class="boton-secundario" href="/panel">Volver al panel</a>
    </div>
</div>

<?php if ($yaConfigurado): ?>

    <p class="dash-subtitulo">
        Configuracion con la que se emiten tus documentos tributarios reales ante el SII.
    </p>

    <div class="layout-principal-lateral">
        <div>
            <section class="tarjeta" aria-labelledby="titulo-produccion">
                <h2 id="titulo-produccion">
                    Datos del emisor
                    <span class="badge badge--ok">Configurado</span>
                </h2>
                <dl class="ficha">
                    <dt>RUT emisor</dt>
                    <dd><?= htmlspecialchars((string) $produccion['rut_emisor']); ?></dd>

                    <dt>Razon social</dt>
                    <dd><?= htmlspecialchars((string) $produccion['razon_social']); ?></dd>

                    <dt>Giro</dt>
                    <dd><?= htmlspecialchars((string) $produccion['giro']); ?></dd>

                    <dt>Codigo de actividad economica</dt>
                    <dd><?= htmlspecialchars((string) $produccion['acteco']); ?></dd>

                    <dt>Direccion</dt>
                    <dd><?= htmlspecialchars((string) $produccion['dir_origen']); ?></dd>

                    <dt>Comuna</dt>
                    <dd><?= htmlspecialchars((string) $produccion['cmna_origen']); ?></dd>

                    <dt>Fecha de resolucion</dt>
                    <dd><?= htmlspecialchars((string) $produccion['resolucion_fecha']); ?></dd>

                    <dt>Numero de resolucion</dt>
                    <dd><?= htmlspecialchars((string) $produccion['resolucion_numero']); ?></dd>
                </dl>
            </section>
        </div>

        <div><?php $panelAmbiente(); ?></div>
    </div>

    <div class="acciones-grupo">
        <a class="boton-principal" href="/certificado-produccion">Siguiente: certificado de produccion &rarr;</a>
        <a class="boton-texto" href="/panel">Volver al panel</a>
    </div>

<?php else: ?>

    <p class="dash-subtitulo">
        Estos datos se usaran para emitir tus documentos tributarios reales.<?php if (! $rutEditable): ?> Vienen precargados
        desde tu configuracion de certificacion; corrigelos si algo cambio para produccion.<?php endif; /* La linea en blanco de abajo NO sobra: PHP se come el salto que sigue a un cierre de etiqueta, y sin ella el caso CON certificacion perderia el salto que tenia aqui. */ ?>

        Los campos marcados con <span class="campo-obligatorio">*</span> son obligatorios.
    </p>

    <?php if ($errores !== []): ?>
        <p class="alerta alerta--error" role="alert">
            <span class="alerta__icono" aria-hidden="true">&#9888;</span>
            <span>Revisa los campos marcados; el detalle esta bajo cada uno.</span>
        </p>
    <?php endif; ?>

    <form method="post" action="/empresa-produccion" class="form-compacto">
        <?= csrfInput(); ?>

        <div class="layout-principal-lateral">
            <div>
                <section class="tarjeta" aria-labelledby="titulo-emisor">
                    <h2 id="titulo-emisor">Datos del emisor</h2>

<?php /* Las 3 etiquetas de ESTE condicional van pegadas al margen a proposito, no
         por descuido: la sangria que las precede es texto y PHP la imprime. Con
         ellas indentadas, el camino CON certificacion emitia 20 espacios de mas
         antes del <dl> y otros 20 antes del <div class="form-grid">, o sea salia
         distinto byte a byte de lo que salia antes de existir este condicional.
         Al margen no emiten nada y ese camino queda intacto. No las re-indentes. */ ?>
<?php if ($rutEditable): ?>
                        <div class="<?= $claseCampo('rut_emisor', 'form-campo--corto'); ?>">
                            <label for="rut_emisor">RUT emisor <?= $req; ?></label>
                            <input type="text" name="rut_emisor" id="rut_emisor" value="<?= $val('rut_emisor'); ?>" placeholder="77724622-4" required>
                            <?php if ($err('rut_emisor')): ?>
                                <p class="error"><?= htmlspecialchars($err('rut_emisor')); ?></p>
                            <?php else: ?>
                                <small class="form-ayuda">El RUT de la empresa que el SII autorizo como emisor electronico, con guion y digito verificador (ej. 77724622-4).</small>
                            <?php endif; ?>
                        </div>
<?php else: ?>
                    <dl class="ficha ficha--compacta">
                        <dt>RUT emisor</dt>
                        <dd><?= htmlspecialchars((string) ($emisor['rut_emisor'] ?? '')); ?></dd>
                    </dl>
                    <?php if ($err('rut_emisor')): ?>
                        <p class="error"><?= htmlspecialchars($err('rut_emisor')); ?></p>
                    <?php else: ?>
                        <small class="form-ayuda">Es el mismo RUT de tu configuracion de certificacion y no se cambia aqui.</small>
                    <?php endif; ?>
<?php endif; ?>

                    <div class="form-grid">
                        <div class="<?= $claseCampo('razon_social', 'form-campo--ancho'); ?>">
                            <label for="razon_social">Razon social <?= $req; ?></label>
                            <input type="text" name="razon_social" id="razon_social" value="<?= $val('razon_social'); ?>" required>
                            <?php if ($err('razon_social')): ?>
                                <p class="error"><?= htmlspecialchars($err('razon_social')); ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="<?= $claseCampo('giro', 'form-campo--ancho'); ?>">
                            <label for="giro">Giro <?= $req; ?></label>
                            <input type="text" name="giro" id="giro" value="<?= $val('giro'); ?>" required>
                            <?php if ($err('giro')): ?>
                                <p class="error"><?= htmlspecialchars($err('giro')); ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="<?= $claseCampo('acteco', 'form-campo--corto'); ?>">
                            <label for="acteco">Codigo de actividad economica <?= $req; ?></label>
                            <input type="text" inputmode="numeric" name="acteco" id="acteco" value="<?= $val('acteco'); ?>" required>
                            <?php if ($err('acteco')): ?>
                                <p class="error"><?= htmlspecialchars($err('acteco')); ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="<?= $claseCampo('dir_origen', 'form-campo--ancho'); ?>">
                            <label for="dir_origen">Direccion <?= $req; ?></label>
                            <input type="text" name="dir_origen" id="dir_origen" value="<?= $val('dir_origen'); ?>" required>
                            <?php if ($err('dir_origen')): ?>
                                <p class="error"><?= htmlspecialchars($err('dir_origen')); ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="<?= $claseCampo('cmna_origen'); ?>">
                            <label for="cmna_origen">Comuna <?= $req; ?></label>
                            <input type="text" name="cmna_origen" id="cmna_origen" value="<?= $val('cmna_origen'); ?>" required>
                            <?php if ($err('cmna_origen')): ?>
                                <p class="error"><?= htmlspecialchars($err('cmna_origen')); ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="<?= $claseCampo('resolucion_fecha'); ?>">
                            <label for="resolucion_fecha">Fecha de la Resolucion de autorizacion <?= $req; ?></label>
                            <input type="date" name="resolucion_fecha" id="resolucion_fecha" value="<?= $val('resolucion_fecha'); ?>" required>
                            <?php if ($err('resolucion_fecha')): ?>
                                <p class="error"><?= htmlspecialchars($err('resolucion_fecha')); ?></p>
                            <?php else: ?>
                                <small class="form-ayuda">La fecha de la Resolucion con que el SII te AUTORIZO como emisor electronico (correo o portal de autorizacion). No es una fecha de postulacion ni se inventa.</small>
                            <?php endif; ?>
                        </div>

                        <div class="<?= $claseCampo('resolucion_numero', 'form-campo--corto'); ?>">
                            <label for="resolucion_numero">Numero de la Resolucion de autorizacion <?= $req; ?></label>
                            <input type="text" inputmode="numeric" name="resolucion_numero" id="resolucion_numero" value="<?= $val('resolucion_numero'); ?>" placeholder="80" required>
                            <?php if ($err('resolucion_numero')): ?>
                                <p class="error"><?= htmlspecialchars($err('resolucion_numero')); ?></p>
                            <?php else: ?>
                                <small class="form-ayuda">El numero de Resolucion Exenta que el SII te entrego al autorizarte (ej. Res. Ex. SII N&deg;80 de 2014). Debe ser un numero entero positivo.</small>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            </div>

            <div><?php $panelAmbiente(); ?></div>
        </div>

        <div class="acciones-grupo">
            <button type="submit" class="boton-principal">Guardar</button>
            <a class="boton-texto" href="/panel">Cancelar</a>
        </div>
    </form>

<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
