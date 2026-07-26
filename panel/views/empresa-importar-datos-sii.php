<?php
/**
 * Configuracion > Empresa > Importar datos desde el archivo del SII.
 *
 * Recibe: $error (string|null) y $datos (DatosContribuyenteSii|null), ambos de
 * handleEmpresaImportarDatosSiiGet/Post().
 *
 * FLUJO DE DOS PASOS, sin persistencia: se sube el archivo, se PREVISUALIZA lo
 * que trae, y el usuario decide. Nada se guarda aqui. El enlace "Usar estos
 * datos" arma un query string con 6 parametros que handleEmpresaGet() usa para
 * precargar el formulario, y SOLO si todavia no hay una fila guardada; si ya
 * existe, la fila de la BD manda. Esos 6 parametros son un contrato: cambiarlos
 * o reordenarlos rompe la precarga.
 *
 * La fecha y el numero de resolucion NO vienen en el archivo: eso se dice aqui
 * porque es cierto, no para llenar espacio.
 *
 * El formulario de "probar con otro archivo" es el MISMO que el inicial (mismo
 * action, method, enctype, name y required); solo cambia el titulo segun el
 * paso en que estas.
 */
$titulo = 'Importar datos desde el SII';
require __DIR__ . '/partials/header.php';

$req = '<span class="campo-obligatorio" aria-hidden="true">*</span>'
    . '<span class="visualmente-oculto">(obligatorio)</span>';
?>

<div class="dash-header">
    <div>
        <h1>Importar datos desde el SII <span class="badge badge--etiqueta">Certificacion</span></h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <a class="boton-secundario" href="/empresa">Ir a Datos de la empresa</a>
        <a class="boton-secundario" href="/panel">Volver al panel</a>
    </div>
</div>
<p class="dash-subtitulo">
    Sube el archivo de <strong>Datos para Construccion DTE</strong> que descargaste del SII
    para revisar sus datos antes de escribirlos.
</p>

<?php if (! empty($error)): ?>
    <p class="alerta alerta--error" role="alert">
        <span class="alerta__icono" aria-hidden="true">&#9888;</span>
        <span><?= htmlspecialchars($error); ?></span>
    </p>
<?php endif; ?>

<?php if ($datos === null): ?>

    <div class="layout-principal-lateral">
        <div>
            <section class="tarjeta" aria-labelledby="titulo-archivo">
                <h2 id="titulo-archivo">Archivo del SII</h2>
                <form method="post" action="/empresa/importar-datos-sii" enctype="multipart/form-data" class="form-compacto">
                    <?= csrfInput(); ?>
                    <div class="form-grid form-grid--1">
                        <div class="form-campo">
                            <label for="archivo">Archivo de Datos para Construccion DTE <?= $req; ?></label>
                            <input type="file" name="archivo" id="archivo" required>
                            <small class="form-ayuda">Descargalo desde
                            <a href="https://maullin.sii.cl/cvc_cgi/dte/pe_construccion_dte" target="_blank" rel="noopener noreferrer">pe_construccion_dte</a>
                            en el portal del SII.</small>
                        </div>
                    </div>
                    <div class="acciones-grupo">
                        <button type="submit" class="boton-principal">Previsualizar</button>
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
                    <li>Solo se previsualiza el contenido del archivo.</li>
                    <li>No se guarda nada hasta que confirmes en Datos de la empresa.</li>
                </ul>
            </div>
        </div>
    </div>

<?php else: ?>

    <p class="alerta alerta--exito" role="status">
        <span class="alerta__icono" aria-hidden="true">&#10003;</span>
        <span>Archivo leido. Todavia no se guardo nada: revisa los datos y confirma en el paso siguiente.</span>
    </p>

    <div class="layout-principal-lateral">
        <div>
            <section class="tarjeta" aria-labelledby="titulo-previa">
                <h2 id="titulo-previa">Datos del contribuyente</h2>
                <dl class="ficha">
                    <dt>RUT</dt>
                    <dd><?= htmlspecialchars($datos->rut); ?></dd>

                    <dt>Razon social</dt>
                    <dd><?= htmlspecialchars($datos->razonSocial); ?></dd>

                    <dt>Direccion</dt>
                    <dd><?= htmlspecialchars($datos->direccion); ?></dd>

                    <dt>Comuna</dt>
                    <dd><?= htmlspecialchars($datos->comuna); ?></dd>

                    <dt>Giro</dt>
                    <dd><?= htmlspecialchars($datos->giro); ?></dd>

                    <dt>Acteco principal</dt>
                    <dd><?= (int) $datos->actecoPrincipal(); ?></dd>
                </dl>
            </section>

            <section class="tarjeta" aria-labelledby="titulo-actividades">
                <h2 id="titulo-actividades">Actividades economicas</h2>
                <div class="tabla-scroll">
                    <table class="tabla-datos">
                        <thead>
                            <tr>
                                <th class="tabla-datos__num">Codigo</th>
                                <th>Descripcion</th>
                                <th>Afecto a IVA</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($datos->actividades as $act): ?>
                                <tr>
                                    <td class="tabla-datos__num"><?= (int) $act->codigo; ?></td>
                                    <td><?= htmlspecialchars($act->descripcion); ?></td>
                                    <td><?= $act->afectoIva ? 'SI' : 'NO'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <div>
            <div class="panel-info panel-info--advertencia">
                <p class="panel-info__titulo">
                    <span class="panel-info__icono" aria-hidden="true">&#9888;</span>
                    Falta completar a mano
                </p>
                <p>La fecha y el numero de Resolucion NO vienen en este archivo: deberas
                completarlos a mano en el siguiente paso, igual que siempre.</p>
            </div>

            <section class="tarjeta" aria-labelledby="titulo-otro">
                <h2 id="titulo-otro">Probar con otro archivo</h2>
                <form method="post" action="/empresa/importar-datos-sii" enctype="multipart/form-data" class="form-compacto">
                    <?= csrfInput(); ?>
                    <div class="form-grid form-grid--1">
                        <div class="form-campo">
                            <label for="archivo-otro">Archivo de Datos para Construccion DTE <?= $req; ?></label>
                            <input type="file" name="archivo" id="archivo-otro" required>
                            <!-- El enlace a pe_construccion_dte se repite aqui a proposito: en la
                                 vista anterior vivia en el parrafo introductorio, visible en LOS DOS
                                 pasos. Al mover la ayuda al campo, el paso de previsualizacion se
                                 quedaba sin el. -->
                            <small class="form-ayuda">Descargalo desde
                            <a href="https://maullin.sii.cl/cvc_cgi/dte/pe_construccion_dte" target="_blank" rel="noopener noreferrer">pe_construccion_dte</a>
                            en el portal del SII.</small>
                        </div>
                    </div>
                    <div class="acciones-grupo">
                        <button type="submit" class="boton-secundario">Previsualizar</button>
                    </div>
                </form>
            </section>
        </div>
    </div>

    <div class="acciones-grupo">
        <a class="boton-principal" href="/empresa?<?= htmlspecialchars(http_build_query([
            'rut_emisor'   => $datos->rut,
            'razon_social' => $datos->razonSocial,
            'giro'         => $datos->giro,
            'acteco'       => (string) $datos->actecoPrincipal(),
            'dir_origen'   => $datos->direccion,
            'cmna_origen'  => $datos->comuna,
        ])); ?>">Usar estos datos &rarr;</a>
        <a class="boton-texto" href="/empresa">Ir a Datos de la empresa sin importar</a>
    </div>

<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
