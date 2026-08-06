<?php
/**
 * Configuracion > Empresa > Consultar datos al SII por RUT.
 *
 * Recibe: $error (string|null), $motivo (string|null), $datos (array|null, el
 * cuerpo de GET /api/v1/contribuyente del motor) y $rut (string, lo que el
 * usuario tecleo), todo de handleEmpresaConsultarSiiGet/Post().
 *
 * MISMO FLUJO DE TRES PASOS QUE /empresa/importar-datos-sii, y a proposito: se
 * pide un dato, se PREVISUALIZA lo que devuelve, y el usuario decide con "Usar
 * estos datos". Nada se guarda aqui. El enlace arma el query string que
 * handleEmpresaGet() usa para precargar, y SOLO si todavia no hay fila guardada;
 * si ya existe, la fila de la BD manda.
 *
 * QUE TRAE ESTA CONSULTA Y EL ARCHIVO NO: el numero y la fecha de Resolucion.
 * Son los dos datos que van a la Caratula de cada envio y los que un digito mal
 * tecleado convirtio en 68 documentos rechazados.
 *
 * QUE NO TRAE: direccion y comuna. La fuente no las tiene. Se dice en pantalla
 * porque es cierto y porque el usuario tiene que saber que le falta.
 *
 * LOS TIPOS AUTORIZADOS SE MUESTRAN, NO SE USAN. Esta entrega no valida contra
 * ellos; vienen en la misma respuesta y se enseñan para que el usuario confirme
 * que el SII lo tiene habilitado para lo que va a emitir.
 */
$titulo = 'Consultar datos al SII';
require __DIR__ . '/partials/header.php';

$req = '<span class="campo-obligatorio" aria-hidden="true">*</span>'
    . '<span class="visualmente-oculto">(obligatorio)</span>';

/** Valor de texto que puede faltar: se marca como ausente, no en blanco. */
$oVacio = static function ($v): string {
    $v = trim((string) ($v ?? ''));
    return $v === '' ? '<span class="dash-vacio-inline">&mdash;</span>' : htmlspecialchars($v);
};
?>

<div class="dash-header">
    <div>
        <h1>Consultar datos al SII <span class="badge badge--etiqueta">Certificacion</span></h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <a class="boton-secundario" href="/empresa">Ir a Datos de la empresa</a>
        <a class="boton-secundario" href="/panel">Volver al panel</a>
    </div>
</div>
<p class="dash-subtitulo">
    Escribe el RUT de la empresa y trae su <strong>razon social</strong> y su
    <strong>numero y fecha de Resolucion</strong> directo del SII, para revisarlos antes de escribirlos.
</p>

<?php if (! empty($error)): ?>
    <p class="alerta alerta--<?= $motivo === 'sin_respuesta' ? 'advertencia' : 'error'; ?>" role="alert">
        <span class="alerta__icono" aria-hidden="true">&#9888;</span>
        <span>
            <?= htmlspecialchars($error); ?>
            <a href="/empresa">Completar a mano</a>.
        </span>
    </p>
<?php endif; ?>

<?php if ($datos === null): ?>

    <div class="layout-principal-lateral">
        <div>
            <section class="tarjeta" aria-labelledby="titulo-rut">
                <h2 id="titulo-rut">RUT de la empresa</h2>
                <form method="post" action="/empresa/consultar-sii" class="form-compacto">
                    <?= csrfInput(); ?>
                    <div class="form-grid form-grid--1">
                        <div class="form-campo">
                            <label for="rut_emisor">RUT <?= $req; ?></label>
                            <input type="text" name="rut_emisor" id="rut_emisor" required
                                   value="<?= htmlspecialchars($rut); ?>" placeholder="77724622-4">
                            <small class="form-ayuda">Con guion y digito verificador.</small>
                        </div>
                    </div>
                    <div class="acciones-grupo">
                        <button type="submit" class="boton-principal">Consultar al SII</button>
                        <a class="boton-texto" href="/empresa">Completar a mano</a>
                    </div>
                </form>
            </section>
        </div>

        <div>
            <div class="panel-info">
                <p class="panel-info__titulo">
                    <span class="panel-info__icono" aria-hidden="true">&#9432;</span>
                    Como funciona
                </p>
                <ul class="panel-info__lista">
                    <li>Es una ayuda opcional: puedes completar los datos a mano.</li>
                    <li>Solo se previsualiza lo que responde el SII.</li>
                    <li>No se guarda nada hasta que confirmes en Datos de la empresa.</li>
                    <li>La direccion y la comuna no vienen en esta consulta.</li>
                </ul>
            </div>
        </div>
    </div>

<?php else: ?>

    <?php
    $autorizado = ! empty($datos['autorizado']);
    $documentos = is_array($datos['documentos'] ?? null) ? $datos['documentos'] : [];
    ?>

    <?php if ($autorizado): ?>
        <p class="alerta alerta--exito" role="status">
            <span class="alerta__icono" aria-hidden="true">&#10003;</span>
            <span>Datos obtenidos del SII. Todavia no se guardo nada: revisalos y confirma en el paso siguiente.</span>
        </p>
    <?php else: ?>
        <?php
        /* NO AUTORIZADO NO ES UN ERROR DE LA CONSULTA: el SII respondio y esto es
           lo que dijo. Se muestra en ambar y no en rojo, y NO se ofrece "usar
           estos datos": precargar la resolucion de un RUT que el SII no tiene
           habilitado seria darle por bueno un dato que no sirve para emitir. */
        ?>
        <p class="alerta alerta--advertencia" role="status">
            <span class="alerta__icono" aria-hidden="true">&#9888;</span>
            <span>El SII <strong>no tiene este RUT habilitado</strong> como emisor de documentos
            electronicos. Revisa el RUT, o continua a mano si sabes que la habilitacion esta en tramite.</span>
        </p>
    <?php endif; ?>

    <div class="layout-principal-lateral">
        <div>
            <section class="tarjeta" aria-labelledby="titulo-previa">
                <h2 id="titulo-previa">Datos del contribuyente</h2>
                <dl class="ficha">
                    <dt>RUT</dt>
                    <dd><?= $oVacio($datos['rut'] ?? ''); ?></dd>

                    <dt>Razon social</dt>
                    <dd><?= $oVacio($datos['razonSocial'] ?? ''); ?></dd>

                    <dt>Numero de Resolucion</dt>
                    <dd><?= $datos['resolucionNumero'] === null ? $oVacio('') : (int) $datos['resolucionNumero']; ?></dd>

                    <dt>Fecha de Resolucion</dt>
                    <dd><?= $oVacio($datos['resolucionFecha'] ?? ''); ?></dd>

                    <dt>Direccion regional</dt>
                    <dd><?= $oVacio($datos['direccionRegional'] ?? ''); ?></dd>

                    <dt>Software</dt>
                    <dd><?= $oVacio($datos['software'] ?? ''); ?></dd>
                </dl>
            </section>

            <?php if ($documentos !== []): ?>
                <section class="tarjeta" aria-labelledby="titulo-documentos">
                    <h2 id="titulo-documentos">Documentos autorizados</h2>
                    <p class="nota">Informativo: esta pantalla no valida contra esta lista.</p>
                    <div class="tabla-scroll">
                        <table class="tabla-datos">
                            <thead>
                                <tr>
                                    <th class="tabla-datos__num">Codigo</th>
                                    <th>Descripcion</th>
                                    <th>Autorizado</th>
                                    <th class="tabla-datos__estado">Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($documentos as $doc): ?>
                                    <tr>
                                        <td class="tabla-datos__num"><?= (int) ($doc['codigo'] ?? 0); ?></td>
                                        <td><?= htmlspecialchars((string) ($doc['descripcion'] ?? '')); ?></td>
                                        <td><?= $oVacio($doc['autorizado'] ?? ''); ?></td>
                                        <td class="tabla-datos__estado">
                                            <?php if (! empty($doc['vigente'])): ?>
                                                <span class="badge badge--ok">Vigente</span>
                                            <?php else: ?>
                                                <span class="badge badge--neutro">Desautorizado</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>
        </div>

        <div>
            <div class="panel-info panel-info--advertencia">
                <p class="panel-info__titulo">
                    <span class="panel-info__icono" aria-hidden="true">&#9888;</span>
                    Falta completar a mano
                </p>
                <p>La <strong>direccion</strong>, la <strong>comuna</strong> y el <strong>giro</strong>
                no vienen en esta consulta: deberas completarlos en el siguiente paso.</p>
            </div>

            <section class="tarjeta" aria-labelledby="titulo-otro">
                <h2 id="titulo-otro">Consultar otro RUT</h2>
                <form method="post" action="/empresa/consultar-sii" class="form-compacto">
                    <?= csrfInput(); ?>
                    <div class="form-grid form-grid--1">
                        <div class="form-campo">
                            <label for="rut-otro">RUT <?= $req; ?></label>
                            <input type="text" name="rut_emisor" id="rut-otro" required placeholder="77724622-4">
                            <small class="form-ayuda">Cada consulta se hace en el momento contra el SII.</small>
                        </div>
                    </div>
                    <div class="acciones-grupo">
                        <button type="submit" class="boton-secundario">Consultar</button>
                    </div>
                </form>
            </section>
        </div>
    </div>

    <div class="acciones-grupo">
        <?php if ($autorizado): ?>
            <?php
            /* Los seis parametros del flujo del archivo son un contrato
               (ver empresa-importar-datos-sii.php). Aqui se mandan solo los que
               esta consulta SI resuelve, mas los dos nuevos de resolucion.
               razon_social va siempre porque es la llave que activa la precarga
               en handleEmpresaGet(). */
            $qs = http_build_query([
                'rut_emisor'        => (string) ($datos['rut'] ?? ''),
                'razon_social'      => (string) ($datos['razonSocial'] ?? ''),
                'resolucion_numero' => $datos['resolucionNumero'] === null ? '' : (string) (int) $datos['resolucionNumero'],
                'resolucion_fecha'  => (string) ($datos['resolucionFecha'] ?? ''),
            ]);
            ?>
            <a class="boton-principal" href="/empresa?<?= htmlspecialchars($qs); ?>">Usar estos datos &rarr;</a>
        <?php endif; ?>
        <a class="boton-texto" href="/empresa">Ir a Datos de la empresa sin usar esta consulta</a>
    </div>

<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
