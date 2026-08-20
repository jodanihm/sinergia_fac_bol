<?php
/**
 * Configuracion > Datos de la empresa (ambiente de CERTIFICACION).
 *
 * Recibe: $emisor (array<string,mixed>|null) y $errores (array<string,string>).
 * handleEmpresaGet() arma $emisor desde dte_emisor; si no hay fila guardada y
 * llegan los parametros de /empresa/importar-datos-sii ("Usar estos datos"),
 * lo arma desde el query string. Tras un POST invalido, $emisor son los datos
 * enviados y $errores el detalle por campo.
 *
 * LOS 8 CAMPOS SON OBLIGATORIOS y todos llevan required, igual que antes. La
 * marca visual con .campo-obligatorio es solo eso: la validacion sigue siendo
 * la del navegador mas la de handleEmpresaPost().
 *
 * LAS AYUDAS SON NORMATIVAS: describen que espera el SII, no como se ve el
 * formulario. Se conservan textuales; solo cambia el contenedor (.form-ayuda en
 * vez de un <small> con estilo inline). Ojo con resolucion_fecha: en
 * CERTIFICACION es la fecha de POSTULACION, no la de autorizacion real. Esa
 * distincion no puede intercambiarse con la de empresa-produccion.php.
 *
 * AMBIENTE: mismo patron que certificado.php -- badge en el titulo y panel
 * lateral, dos canales para no depender solo del color.
 */
$titulo = 'Datos de la empresa';
require __DIR__ . '/partials/header.php';

$val = static function (string $campo) use ($emisor): string {
    return htmlspecialchars((string) ($emisor[$campo] ?? ''));
};
$err = static function (string $campo) use ($errores): ?string {
    return $errores[$campo] ?? null;
};
/** Agrega .form-campo--error al contenedor cuando ese campo trae error. */
$claseCampo = static function (string $campo, string $extra = '') use ($errores): string {
    $c = 'form-campo' . ($extra !== '' ? ' ' . $extra : '');
    return isset($errores[$campo]) ? $c . ' form-campo--error' : $c;
};

$req = '<span class="campo-obligatorio" aria-hidden="true">*</span>'
    . '<span class="visualmente-oculto">(obligatorio)</span>';
?>

<div class="dash-header">
    <div>
        <h1>Datos de la empresa <span class="badge badge--etiqueta">Certificacion</span></h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <?php /* Primero la consulta por RUT: es la unica que trae el numero y la
                 fecha de Resolucion, que son los dos campos que nadie puede
                 verificar de memoria. El archivo del SII no los trae. */ ?>
        <a class="boton-secundario" href="/empresa/consultar-sii">Consultar al SII por RUT</a>
        <a class="boton-secundario" href="/empresa/importar-datos-sii">Importar datos del SII</a>
        <a class="boton-secundario" href="/panel">Volver al panel</a>
    </div>
</div>
<p class="dash-subtitulo">
    Estos datos se usan para emitir tus documentos tributarios electronicos en el SII
    (ambiente de certificacion). Los campos marcados con
    <span class="campo-obligatorio">*</span> son obligatorios.
</p>

<?php if ($errores !== []): ?>
    <p class="alerta alerta--error" role="alert">
        <span class="alerta__icono" aria-hidden="true">&#9888;</span>
        <span>Revisa los campos marcados; el detalle esta bajo cada uno.</span>
    </p>
<?php endif; ?>

<form method="post" action="/empresa" class="form-compacto">
    <?= csrfInput(); ?>

    <div class="layout-principal-lateral">
        <div>
            <section class="tarjeta" aria-labelledby="titulo-emisor">
                <h2 id="titulo-emisor">Datos del emisor</h2>
                <div class="form-grid">
                    <div class="<?= $claseCampo('rut_emisor'); ?>">
                        <label for="rut_emisor">RUT emisor <?= $req; ?></label>
                        <input type="text" name="rut_emisor" id="rut_emisor" value="<?= $val('rut_emisor'); ?>" placeholder="77724622-4" required>
                        <?php if ($err('rut_emisor')): ?>
                            <p class="error"><?= htmlspecialchars($err('rut_emisor')); ?></p>
                        <?php else: ?>
                            <small class="form-ayuda">RUT de la empresa, con guion y digito verificador.</small>
                        <?php endif; ?>
                    </div>

                    <div class="<?= $claseCampo('razon_social'); ?>">
                        <label for="razon_social">Razon social <?= $req; ?></label>
                        <input type="text" name="razon_social" id="razon_social" value="<?= $val('razon_social'); ?>" placeholder="Mi Empresa SpA" required>
                        <?php if ($err('razon_social')): ?>
                            <p class="error"><?= htmlspecialchars($err('razon_social')); ?></p>
                        <?php else: ?>
                            <small class="form-ayuda">Nombre legal de la empresa, EN MAYUSCULAS y exactamente como esta registrado en el SII (ver ayuda al costado).</small>
                        <?php endif; ?>
                    </div>

                    <div class="<?= $claseCampo('giro', 'form-campo--ancho'); ?>">
                        <label for="giro">Giro <?= $req; ?></label>
                        <input type="text" name="giro" id="giro" value="<?= $val('giro'); ?>" placeholder="Venta al por menor de ..." required>
                        <?php if ($err('giro')): ?>
                            <p class="error"><?= htmlspecialchars($err('giro')); ?></p>
                        <?php else: ?>
                            <small class="form-ayuda">Giro comercial registrado en el SII.</small>
                        <?php endif; ?>
                    </div>

                    <div class="<?= $claseCampo('acteco', 'form-campo--corto'); ?>">
                        <label for="acteco">Codigo de actividad economica <?= $req; ?></label>
                        <input type="text" inputmode="numeric" name="acteco" id="acteco" value="<?= $val('acteco'); ?>" placeholder="620100" required>
                        <?php if ($err('acteco')): ?>
                            <p class="error"><?= htmlspecialchars($err('acteco')); ?></p>
                        <?php else: ?>
                            <small class="form-ayuda">Codigo numerico del SII (acteco), solo numeros y sin texto.</small>
                        <?php endif; ?>
                    </div>

                    <div class="<?= $claseCampo('dir_origen', 'form-campo--ancho'); ?>">
                        <label for="dir_origen">Direccion <?= $req; ?></label>
                        <input type="text" name="dir_origen" id="dir_origen" value="<?= $val('dir_origen'); ?>" placeholder="Calle Ejemplo 123" required>
                        <?php if ($err('dir_origen')): ?>
                            <p class="error"><?= htmlspecialchars($err('dir_origen')); ?></p>
                        <?php else: ?>
                            <small class="form-ayuda">Direccion de origen del emisor, EN MAYUSCULAS y exactamente como esta registrada en el SII (ver ayuda al costado).</small>
                        <?php endif; ?>
                    </div>

                    <div class="<?= $claseCampo('cmna_origen'); ?>">
                        <label for="cmna_origen">Comuna <?= $req; ?></label>
                        <input type="text" name="cmna_origen" id="cmna_origen" value="<?= $val('cmna_origen'); ?>" placeholder="Valdivia" required>
                        <?php if ($err('cmna_origen')): ?>
                            <p class="error"><?= htmlspecialchars($err('cmna_origen')); ?></p>
                        <?php else: ?>
                            <small class="form-ayuda">Comuna de la direccion de origen, EN MAYUSCULAS y exactamente como esta registrada en el SII (ver ayuda al costado).</small>
                        <?php endif; ?>
                    </div>

                    <div class="<?= $claseCampo('resolucion_fecha'); ?>">
                        <label for="resolucion_fecha">Fecha de resolucion SII <?= $req; ?></label>
                        <input type="date" name="resolucion_fecha" id="resolucion_fecha" value="<?= $val('resolucion_fecha'); ?>" required>
                        <?php if ($err('resolucion_fecha')): ?>
                            <p class="error"><?= htmlspecialchars($err('resolucion_fecha')); ?></p>
                        <?php else: ?>
                            <small class="form-ayuda">En AMBIENTE DE CERTIFICACION es la FECHA EN QUE TU EMPRESA POSTULO a la certificacion, no una fecha generica ni la de produccion (ej. NO uses 2014-08-22/Res. 80 salvo que sea realmente tu caso).</small>
                        <?php endif; ?>
                    </div>

                    <?php
                    /* EL NUMERO DE RESOLUCION YA NO SE PIDE ACA, Y NO ES UN OLVIDO.
                       En certificacion SIEMPRE va 0: es una propiedad del ambiente,
                       no de la empresa (ver EnvioDteBuilder::buildCaratula, que lo
                       fuerza y documenta la medicion). Pedirlo era una trampa: el
                       campo sugeria 80, que es el numero de PRODUCCION que el portal
                       del SII publica para cualquier RUT, y escribirlo aca hizo que
                       el SII devolviera RCT "Rechazado por Error en Caratula".
                       El numero real se pide en /empresa/produccion, que es donde
                       manda. */
                    ?>
                    <div class="form-campo form-campo--ancho">
                        <p class="form-ayuda">
                            <strong>El numero de resolucion no se pide en certificacion:</strong>
                            el SII espera 0 en este ambiente y el sistema lo pone solo.
                            El numero real de tu empresa (normalmente 80, Res. Ex. N&deg;80
                            de 2014) se carga al configurar produccion.
                        </p>
                    </div>
                </div>
            </section>
        </div>

        <div>
            <div class="panel-info">
                <p class="panel-info__titulo">
                    <span class="panel-info__icono" aria-hidden="true">&#9432;</span>
                    Ambiente de certificacion
                </p>
                <ul class="panel-info__lista">
                    <li>Con estos datos se arma la caratula de los documentos de prueba que envias al SII.</li>
                    <li>Razon social, direccion y comuna deben calzar exactamente con el registro del SII.</li>
                    <li>La configuracion de produccion se guarda por separado.</li>
                </ul>
            </div>

            <section class="tarjeta">
                <details>
                    <summary>Ayuda: el SII rechaza mis envios con "Rechazado por Error en Caratula"</summary>
                    <p>La razon social, direccion y comuna deben calzar <strong>EXACTAMENTE</strong>
                    con los datos que el SII tiene registrados: en <strong>MAYUSCULAS</strong> y sin
                    abreviar ni truncar por cuenta propia. Descarga los datos oficiales de tu empresa
                    desde
                    <a href="https://maullin.sii.cl/cvc_cgi/dte/pe_construccion_dte" target="_blank" rel="noopener noreferrer">Datos para Construccion DTE (pe_construccion_dte)</a>
                    y copialos tal cual.</p>
                </details>
            </section>
        </div>
    </div>

    <div class="acciones-grupo">
        <button type="submit" class="boton-principal">Guardar</button>
        <a class="boton-texto" href="/panel">Cancelar</a>
    </div>
</form>

<?php
// EL LOGO VA FUERA DEL <form> DE ARRIBA, y no por gusto: HTML no permite
// formularios anidados, y este necesita enctype multipart mientras que el de los
// datos de la empresa no. Son dos envios distintos a dos rutas distintas.
//
// $logo puede no estar definido: handleEmpresaPost() re-renderiza esta vista
// cuando la validacion falla y no pasa esa clave. Se resuelve aqui en vez de
// tocar ese handler, que no tiene nada que ver con el logo.
$logo = $logo ?? null;
?>
<section class="tarjeta" aria-labelledby="titulo-logo">
    <h2 id="titulo-logo">Logo de la empresa</h2>
    <p class="dash-subtitulo">
        Se imprime arriba a la izquierda de tus facturas, notas de credito y notas
        de debito, al lado de la razon social. Es opcional: sin logo el documento
        sale exactamente como hasta ahora.
    </p>

    <?php if ($logo !== null): ?>
        <p class="alerta alerta--ok" role="status">
            <span class="alerta__icono" aria-hidden="true">&#10003;</span>
            <span>
                Tienes un logo cargado:
                <strong><?= (int) $logo['ancho_px']; ?>&times;<?= (int) $logo['alto_px']; ?></strong> pixeles,
                <strong><?= number_format((int) $logo['bytes'] / 1024, 0, ',', '.'); ?> KB</strong>.
                Actualizado el <?= htmlspecialchars(date('d-m-Y', strtotime((string) $logo['updated_at']))); ?>.
            </span>
        </p>
    <?php endif; ?>

    <form method="post" action="/empresa/logo" enctype="multipart/form-data" class="form-compacto">
        <?= csrfInput(); ?>
        <div class="form-campo form-campo--ancho">
            <label for="logo">Archivo PNG</label>
            <input type="file" name="logo" id="logo" accept="image/png" required>
            <small class="form-ayuda">
                Solo PNG, hasta 512 KB. Se dibuja a 30 mm de ancho, asi que no necesita
                mas resolucion: unos 400 pixeles de ancho alcanzan y sobran. Si subes
                uno nuevo, reemplaza al anterior.
            </small>
        </div>
        <div class="acciones-grupo">
            <button type="submit" class="boton-principal">
                <?= $logo !== null ? 'Reemplazar logo' : 'Subir logo'; ?>
            </button>
        </div>
    </form>

    <?php if ($logo !== null): ?>
        <form method="post" action="/empresa/logo/quitar" class="form-compacto">
            <?= csrfInput(); ?>
            <div class="acciones-grupo">
                <button type="submit" class="boton-texto">Quitar el logo</button>
            </div>
        </form>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
