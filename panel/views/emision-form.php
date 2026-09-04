<?php
/**
 * Emision unitaria: factura 33, nota de credito 61, nota de debito 56.
 *
 * UNA SOLA VISTA PARA LOS TRES TIPOS. La unica bifurcacion es $esNota, que
 * agrega la card de referencia. Cualquier cambio aqui sale simultaneamente en
 * /ventas/factura, /ventas/nota-credito y /ventas/nota-debito: hay que probar
 * las tres rutas, no solo la primera.
 *
 * Recibe (sin cambios respecto de antes): $tipoDte, $tituloDoc, $accion,
 * $idemKey, $form, $errorCampo, $errorMsg, $flashError, $productos, $navActivo.
 *
 * LO QUE ESTA PANTALLA NO PUEDE PROMETER, y por eso no aparece:
 *   - Totales antes de emitir. Neto/exento/IVA/total se calculan en el motor
 *     (DteXmlBuilder::totales()) al construir el XML, o sea DESPUES de asignar
 *     folio. Aqui no existe ese numero y no se estima.
 *   - Fecha de emision, forma de pago, vencimiento y moneda: no existen en el
 *     payload ni en el motor. La fecha la pone el motor al emitir.
 *   - Descripcion y descuento por linea: el backend los lee, pero no forman
 *     parte de la interfaz autorizada todavia.
 *
 * CONTRATO CON EL BACKEND Y EL JS -- no tocar sin revisar handleEmisionPost():
 *   - name de cada control (incluida la sintaxis de arrays) y el hidden
 *     idem_key, que es lo que impide una doble emision con folio real.
 *   - id/clase que usa el JS: #form-emision, #tabla-detalle, #agregar-linea,
 *     .quitar-linea, .det-nombre, .det-precio, .det-unidad, .det-exento,
 *     #productos-list, #receptor-* y #rut-aviso.
 *   - La fila del detalle esta duplicada: aqui en PHP y en nuevaFilaHTML() del
 *     JS. Las dos deben producir el mismo DOM.
 *
 * CONVERSION DE COTIZACION (segunda entrega): $form puede traer cotizacion_id,
 * cotizacion_numero y, en cada detalle, cotizacion_linea_id y pendiente. Cuando
 * NO vienen -- toda emision normal -- esta vista produce el mismo HTML que antes.
 *
 * Y HAY UNA ASIMETRIA A PROPOSITO CON nuevaFilaHTML() DEL JS: la fila de PHP
 * puede llevar el hidden cotizacion_linea_id y la del JS NUNCA lo lleva. No es un
 * olvido -- es la regla: una linea que el usuario agrega a mano es venta nueva
 * dentro de la misma factura y no descuenta saldo de ninguna cotizacion.
 */
$titulo = $tituloDoc;
require __DIR__ . '/partials/header.php';

// Prellenado desde $form ($_POST en re-render, o [] en GET limpio).
$vr = static function (string $c) use ($form): string {
    return htmlspecialchars((string) ($form['receptor'][$c] ?? ''));
};
$vref = static function (string $c) use ($form): string {
    return htmlspecialchars((string) ($form['referencias'][0][$c] ?? ''));
};
// Resaltado del campo que devolvio el motor en el 422 (un campo a la vez).
$errStyle = static function (string $campo) use ($errorCampo): string {
    return $errorCampo !== null && $errorCampo !== '' && $errorCampo === $campo
        ? ' style="border-color:#b00020;"' : '';
};

// Detalles a renderizar: los enviados (re-render) o una linea vacia.
$detalles = (isset($form['detalles']) && is_array($form['detalles']) && $form['detalles'] !== [])
    ? array_values($form['detalles']) : [[]];

// CONVERSION DE COTIZACION: null en toda emision normal. Cuando es null esta
// vista se comporta y renderiza exactamente igual que antes de la segunda
// entrega de cotizaciones -- ni un atributo de diferencia.
$cotizacionId     = isset($form['cotizacion_id']) ? (int) $form['cotizacion_id'] : null;
$cotizacionNumero = isset($form['cotizacion_numero']) ? (int) $form['cotizacion_numero'] : null;

// Mapa nombre -> datos para el autocompletado de linea desde el maestro.
$mapaProductos = [];
foreach ($productos as $p) {
    $mapaProductos[(string) $p['nombre']] = [
        'precio' => $p['precio_unitario'],
        'unidad' => $p['unidad'] ?? '',
        'exento' => $p['exento'] ? 1 : 0,
    ];
}
$esNota = in_array($tipoDte, [61, 56], true);

// FACTURA EXENTA (34): TODAS las lineas son exentas, sin excepcion. Un 34 con
// una sola linea afecta haria que el builder emitiera MntNeto, TasaIVA e IVA
// dentro de un documento que no puede tenerlos, el SII lo rechazaria y el folio
// quedaria quemado igual (se asigna antes de enviar).
//
// Aqui la casilla se marca y se deshabilita: se sigue VIENDO, para que quede
// claro por que no hay IVA, pero no se puede desmarcar. Una casilla deshabilitada
// NO viaja en el POST, asi que quien garantiza el valor es armarDocumentoEmision(),
// que lo fuerza para el tipo 34; y el motor lo vuelve a validar por su cuenta.
// Tres capas, porque al cliente no se le cree y al usuario no se le hace perder
// un folio por un descuido.
$esExenta = $tipoDte === 34;

// NOTA (61/56) QUE CORRIGE UN DOCUMENTO SIN IVA. Mismo caso que el 34, un
// documento hacia el lado: si el TpoDocRef tecleado es 32, 34, 38 o 41, la nota
// tampoco puede llevar lineas afectas -- el documento que corrige nunca tuvo
// IVA. Se calcula aqui para el RE-RENDER (cuando el motor devuelve un 422 el
// tipo ya viene en $form) y el JS de mas abajo lo recalcula en vivo mientras se
// teclea el campo. Quien garantiza el valor es armarDocumentoEmision(), porque
// una casilla deshabilitada no viaja en el POST; y el motor lo vuelve a validar.
$refTipo    = trim((string) ($form['referencias'][0]['tipoDocumento'] ?? ''));
$refSinIva  = $esNota && is_numeric($refTipo)
    && \Plantiflex\FacturacionCl\Enums\TipoDte::esSinIva((int) $refTipo);

// A partir de aqui las dos situaciones se tratan igual: casilla marcada,
// deshabilitada, y el maestro de productos no puede desmarcarla.
$exentoForzado = $esExenta || $refSinIva;

// NOTA DE DEBITO QUE ANULA ALGO QUE NO ES UNA NOTA DE CREDITO. Con CodRef=1 una
// ND solo puede anular una NC (61); para tocar una factura va con CodRef=3. La
// lista de abajo ya lo explicaba y aun asi se emitieron CINCO ND contra una
// factura 33 con CodRef=1, ninguna aceptada por el SII. Aqui se avisa en la
// pantalla; quien lo impide de verdad es el motor, con un 422 sin quemar folio.
$refCodigo = trim((string) ($form['referencias'][0]['codigo'] ?? ''));
$ndMalAnula = $tipoDte === 56
    && $refCodigo === '1'
    && is_numeric($refTipo)
    && ! \Plantiflex\FacturacionCl\Enums\TipoDte::esNotaCredito((int) $refTipo);

// Forma de pago y vencimiento solo aplican a factura y factura exenta: son los
// dos tipos para los que el Formato DTE exige informar FmaPago (pag. 4, cambio
// del 31/05/2017). NC y ND no lo llevan.
$esFactura = in_array($tipoDte, [33, 34], true);

// BOLETA (39): el receptor puede no identificarse -- es el caso normal de una
// venta de mostrador. Giro, direccion y comuna no existen en el documento, y el
// RUT y la razon social son opcionales: el motor pone Consumidor Final
// (66666666-6) cuando no vienen. Ver emitirBoleta() en public/index.php.
$esBoleta = $tipoDte === 39;

// "Emitir factura electronica" / "Emitir nota de credito" / "Emitir nota de
// debito". strtolower y no mb_strtolower: los titulos de metaTipoEmision() son
// ASCII sin tildes y mbstring no esta garantizada en la imagen.
$textoEmitir = 'Emitir ' . strtolower($tituloDoc);

// Marca de campo obligatorio. Obligatorio SEGUN EL MOTOR
// (validarDocumentoDte()), no segun el navegador: no se agrega el atributo
// required, que introduciria una validacion frontend que hoy no existe.
//
// UNICA EXCEPCION: el <select> del codigo de referencia SI lleva required, y
// no por simetria con el motor sino porque sin el es imposible NO enviar un
// valor. Un <select> sin opcion marcada envia su primera opcion, asi que el
// campo nunca llega vacio al backend y el motor no puede detectar la omision.
// Se quemaron dos folios reales de nota de debito por eso: el formulario
// mandaba CodRef=1 sin que nadie lo hubiera elegido. La opcion vacia mas el
// required son lo que hace que "no elegir" sea un estado representable.
$req = '<span class="campo-obligatorio" aria-hidden="true">*</span>'
    . '<span class="visualmente-oculto">(obligatorio)</span>';
?>

<div class="dash-header">
    <div>
        <h1><?= htmlspecialchars($tituloDoc); ?></h1>
    </div>
    <a class="boton-secundario" href="/ventas/panel-emision">Ver documentos emitidos</a>
</div>
<p class="dash-subtitulo">
    Completa los datos del receptor y el detalle antes de emitir.
    Los campos marcados con <span class="campo-obligatorio">*</span> son obligatorios para el SII.
</p>

<?php if (! empty($flashError)): ?>
    <p class="alerta alerta--error" role="alert">
        <span class="alerta__icono" aria-hidden="true">&#9888;</span>
        <span><?= htmlspecialchars($flashError); ?></span>
    </p>
<?php endif; ?>
<?php if (! empty($errorMsg)): ?>
    <p class="alerta alerta--error" role="alert">
        <span class="alerta__icono" aria-hidden="true">&#9888;</span>
        <span>
            <?= htmlspecialchars($errorMsg); ?>
            <?php if (! empty($errorCampo)): ?>
                <br><small>Campo: <?= htmlspecialchars($errorCampo); ?></small>
            <?php endif; ?>
        </span>
    </p>
<?php endif; ?>

<form method="post" action="<?= htmlspecialchars($accion); ?>" id="form-emision" class="form-compacto">
    <?= csrfInput(); ?>
    <input type="hidden" name="idem_key" value="<?= htmlspecialchars($idemKey); ?>">
    <?php if ($cotizacionId !== null): ?>
        <?php
        /* CONVERSION DE UNA COTIZACION. Este hidden y los de cada fila SOLO
           existen cuando se llega desde /ventas/cotizaciones/{id}/facturar: en
           una emision normal $cotizacionId es null y esta vista produce
           EXACTAMENTE el mismo HTML que antes de esta entrega. Ese es el
           requisito que manda -- el camino que hoy factura de verdad es el que
           no lleva cotizacion.

           EL ID NO PROTEGE NADA POR SI SOLO: un hidden es texto que el usuario
           edita. La garantia esta en el servidor, donde cada id se valida contra
           la cotizacion Y contra la cuenta (pendientesDeLineas()). */
        ?>
        <input type="hidden" name="cotizacion_id" value="<?= (int) $cotizacionId; ?>">
    <?php endif; ?>

    <div class="layout-principal-lateral">
        <div>
            <section class="tarjeta" aria-labelledby="titulo-receptor">
                <h2 id="titulo-receptor">Datos del receptor</h2>
                <div class="form-grid">
                    <div class="form-campo">
                        <label for="receptor-rut">RUT <?= $esBoleta ? '' : $req; ?></label>
                        <input type="text" name="receptor[rut]" id="receptor-rut" value="<?= $vr('rut'); ?>" placeholder="<?= $esBoleta ? 'Dejar vacio si no se identifica' : '76543210-9'; ?>" aria-describedby="rut-aviso"<?= $errStyle('receptor.rut'); ?>>
                        <small class="form-ayuda" id="rut-aviso" aria-live="polite"></small>
                        <?php if ($esBoleta): ?>
                            <small class="form-ayuda">Opcional. Si lo dejas vacio, la boleta sale a Consumidor Final (66666666-6), que es lo normal en una venta de mostrador.</small>
                        <?php endif; ?>
                    </div>
                    <div class="form-campo">
                        <label for="receptor-razonSocial">Razon social <?= $esBoleta ? '' : $req; ?></label>
                        <input type="text" name="receptor[razonSocial]" id="receptor-razonSocial" value="<?= $vr('razonSocial'); ?>"<?= $errStyle('receptor.razonSocial'); ?>>
                    </div>
                    <?php
                    /* GIRO, DIRECCION Y COMUNA NO EXISTEN EN LA BOLETA. No es que
                       se oculten por comodidad: el esquema del DTE 39 no los lleva
                       y el endpoint del motor (POST /api/v1/boleta) ni siquiera los
                       lee. Mostrarlos deshabilitados haria creer que son datos que
                       el documento podria llevar. Mismo criterio con el que este
                       formulario ya esconde forma de pago fuera de 33/34 y las
                       referencias fuera de 61/56. */
                    ?>
                    <?php if (! $esBoleta): ?>
                    <div class="form-campo form-campo--ancho">
                        <label for="receptor-giro">Giro <?= $req; ?></label>
                        <input type="text" name="receptor[giro]" id="receptor-giro" value="<?= $vr('giro'); ?>"<?= $errStyle('receptor.giro'); ?>>
                    </div>
                    <div class="form-campo form-campo--ancho">
                        <label for="receptor-direccion">Direccion <?= $req; ?></label>
                        <input type="text" name="receptor[direccion]" id="receptor-direccion" value="<?= $vr('direccion'); ?>"<?= $errStyle('receptor.direccion'); ?>>
                    </div>
                    <div class="form-campo">
                        <label for="receptor-comuna">Comuna <?= $req; ?></label>
                        <input type="text" name="receptor[comuna]" id="receptor-comuna" value="<?= $vr('comuna'); ?>"<?= $errStyle('receptor.comuna'); ?>>
                    </div>
                    <?php endif; ?>
                    <div class="form-campo">
                        <label for="receptor-email">Email</label>
                        <input type="email" name="receptor[email]" id="receptor-email" value="<?= $vr('email'); ?>"<?= $errStyle('receptor.email'); ?>>
                    </div>
                    <div class="form-campo form-campo--ancho">
                        <label class="form-check" for="guardar-cliente">
                            <input type="checkbox" name="guardar_cliente" id="guardar-cliente" value="1" <?= ! empty($form['guardar_cliente']) ? 'checked' : ''; ?>>
                            Guardar en mis clientes
                        </label>
                    </div>
                </div>
            </section>
        </div>

        <div>
            <section class="tarjeta" aria-labelledby="titulo-condiciones">
                <h2 id="titulo-condiciones">Condiciones del documento</h2>
                <div class="form-grid form-grid--1">
                    <?php if ($esFactura): ?>
                        <?php
                        /* FORMA DE PAGO. SIN VALOR POR DEFECTO, y no es un descuido:
                           el Formato DTE v2.5 (pag. 14, campo 13) dice que si el
                           campo no viene "se entendera que tiene valor 2 (Credito)".
                           O sea que "no elegir" NO es neutro: es elegir credito en
                           silencio, que es exactamente lo que el sistema viene
                           haciendo con todos los documentos emitidos hasta hoy.
                           Por eso la primera opcion esta vacia y deshabilitada:
                           obliga a decidir, y el required lo hace cumplir.

                           El <select> ya se estila solo dentro de .form-compacto,
                           que este formulario lleva en su <form>. Cero CSS nuevo. */
                        ?>
                        <div class="form-campo form-campo--corto">
                            <label for="forma-pago">Forma de pago</label>
                            <select name="formaPago" id="forma-pago" required<?= $errStyle('formaPago'); ?>>
                                <option value="" disabled <?= ($form['formaPago'] ?? '') === '' ? 'selected' : ''; ?>>Elige una</option>
                                <?php foreach ([1 => 'Contado', 2 => 'Credito', 3 => 'Sin costo (entrega gratuita)'] as $v => $glosa): ?>
                                    <option value="<?= $v; ?>" <?= (string) ($form['formaPago'] ?? '') === (string) $v ? 'selected' : ''; ?>><?= $glosa; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-ayuda">
                                Obligatorio. Si no se informa, el SII asume Credito.
                            </small>
                        </div>
                        <?php
                        /* VENCIMIENTO. Obligatorio SOLO con credito, y esa
                           obligacion es NUESTRA, no del SII: el Formato DTE lo
                           declara condicional (codigo 2, pag. 16) pero no enuncia
                           la condicion. La razon es de negocio: una factura a
                           credito sin vencimiento no sirve para cobrar, que es
                           justo para lo que se captura el dato.

                           El required lo pone y lo saca el JS de abajo segun la
                           opcion elegida; el motor lo valida igual por su cuenta. */
                        ?>
                        <div class="form-campo form-campo--corto" id="campo-vencimiento" hidden>
                            <label for="fecha-vencimiento">Fecha de vencimiento</label>
                            <input type="date" name="fechaVencimiento" id="fecha-vencimiento"
                                   value="<?= htmlspecialchars((string) ($form['fechaVencimiento'] ?? '')); ?>"<?= $errStyle('fechaVencimiento'); ?>>
                            <small class="form-ayuda">Obligatoria cuando la forma de pago es Credito.</small>
                        </div>
                    <?php endif; ?>
                    <div class="form-campo">
                        <label class="form-check" for="montos-brutos">
                            <input type="checkbox" name="montosSonBrutos" id="montos-brutos" value="1" <?= ! empty($form['montosSonBrutos']) ? 'checked' : ''; ?>>
                            Los precios ingresados incluyen IVA (brutos)
                        </label>
                    </div>
                    <div class="form-campo form-campo--corto">
                        <label for="descuento-global">Descuento global (%)</label>
                        <input type="text" inputmode="decimal" name="descuentoGlobalPct" id="descuento-global" value="<?= htmlspecialchars((string) ($form['descuentoGlobalPct'] ?? '')); ?>"<?= $errStyle('descuentoGlobalPct'); ?>>
                        <small class="form-ayuda">Opcional. Aplica sobre los items afectos.</small>
                    </div>
                    <?php
                    /*
                     * AQUI ESTABA EL CAMPO "OBSERVACIONES". SE QUITO, Y NO POR ESTETICA.
                     *
                     * El dato no llegaba a ninguna parte. La pantalla lo capturaba,
                     * armarDocumentoEmision() lo metia en el payload y el motor lo
                     * DESCARTABA EN SILENCIO: validarDocumentoDte() proyectaba el body
                     * sobre una lista de claves conocidas y 'observaciones' no estaba en
                     * ella, asi que se perdia sin error, sin log y sin quedar en el XML ni
                     * en dte_emitido. Cada texto que un usuario escribio aqui se perdio en
                     * el momento del POST y no hay forma de recuperarlo hacia atras.
                     *
                     * Un campo que promete por contexto y no cumple es peor que no tenerlo:
                     * este ni siquiera traia <small class="form-ayuda">, a diferencia de
                     * todos sus vecinos, asi que nada le advertia al usuario.
                     *
                     * QUE HACE FALTA PARA TRAERLO DE VUELTA -- una sola cosa, y es de
                     * norma, no de codigo:
                     *
                     *   LA CITA DEL FORMATO DTE (docs/09_Formato_DTE_Documentos_
                     *   Tributarios_Electronicos.pdf) QUE DIGA EN QUE CAMPO DEL XML VA UNA
                     *   GLOSA LIBRE DE DOCUMENTO, con pagina o seccion.
                     *
                     * Los candidatos que hay que resolver CON esa cita, no antes: una
                     * Referencia con CodRef=3 y RazonRef (que hoy solo se usa para NC/ND,
                     * apuntando a otro documento) o algun campo de texto del Encabezado,
                     * que este builder no emite. NO SE PUEDE DEDUCIR DEL XSD: en este
                     * proyecto ya se midio que el XSD y el Formato DTE no coinciden
                     * (DirRecep y CmnaRecep son minOccurs=0 en el esquema y obligatorios
                     * en el formato). Inventar el campo emite un XML que el SII rechaza
                     * con el folio ya quemado.
                     *
                     * Hasta entonces el campo NO vuelve: desde que la lista de claves de
                     * validarDocumentoDte() es cerrada, mandarlo daria 422 y romperia la
                     * emision manual entera.
                     */
                    ?>
                </div>
            </section>

            <?php if ($esNota): ?>
                <section class="tarjeta" aria-labelledby="titulo-referencia">
                    <h2 id="titulo-referencia">Referencia del documento</h2>
                    <p class="nota">Una nota de credito o debito debe referenciar el documento que corrige o anula.</p>
                    <div class="form-grid">
                        <div class="form-campo">
                            <label for="ref-tipo">Tipo de documento <?= $req; ?></label>
                            <input type="text" inputmode="numeric" name="referencias[0][tipoDocumento]" id="ref-tipo" value="<?= $vref('tipoDocumento'); ?>" placeholder="33"<?= $errStyle('referencias'); ?>>
                            <small class="form-ayuda">TpoDocRef. 33 para factura, 34 para factura exenta.</small>
                            <p class="form-ayuda" id="aviso-ref-exenta"<?= $refSinIva ? '' : ' hidden'; ?>>
                                El documento que estas corrigiendo <strong>no lleva IVA</strong>, asi que esta
                                nota tampoco: el detalle queda exento completo y la casilla no se puede
                                desmarcar. Una nota con IVA sobre un documento exento la rechaza el SII,
                                y el folio se pierde igual.
                            </p>
                        </div>
                        <div class="form-campo">
                            <label for="ref-folio">Folio <?= $req; ?></label>
                            <input type="text" inputmode="numeric" name="referencias[0][folio]" id="ref-folio" value="<?= $vref('folio'); ?>">
                        </div>
                        <div class="form-campo form-campo--ancho form-campo--corto">
                            <label for="ref-fecha">Fecha del documento referenciado <?= $req; ?></label>
                            <?php
                                // required DE VERDAD, y es la segunda excepcion a la regla de
                                // "obligatorio segun el motor, no segun el navegador" (la primera
                                // es el select del codigo, ver su nota). FchRef NO es opcional: el
                                // esquema del SII la declara obligatoria dentro de Referencia
                                // (docs/18_Schema_XML_DTE/DTE_v10.xsd, bloque Referencia). Este
                                // campo se ofrecia sin marca y sin required, asi que dejarlo en
                                // blanco armaba un sobre que el SII rechaza por schema -- RSC -- con
                                // el folio ya gastado. Exactamente como se perdio la NC folio 5 del
                                // 02-09-2026 por el RUT con puntos.
                                //
                                // Quien lo garantiza es el motor (validarReferencias): el required
                                // es para que el usuario lo vea antes de enviar, no la regla.
                            ?>
                            <input type="date" name="referencias[0][fecha]" id="ref-fecha" value="<?= $vref('fecha'); ?>" required<?= $errStyle('referencias[0].fecha'); ?>>
                            <small class="form-ayuda">FchRef. Es la fecha de emision del documento que estas corrigiendo, no la de hoy.</small>
                        </div>
                        <div class="form-campo form-campo--ancho">
                            <label for="ref-codigo">Codigo de referencia <?= $req; ?></label>
                            <?php
                                // La opcion vacia NO es decorativa: sin ella el navegador marca la
                                // primera opcion y el formulario envia CodRef=1 aunque el usuario no
                                // haya elegido nada. Junto con required, obliga a una eleccion
                                // consciente venga el codigo prellenado por query string o vacio.
                                $codSel = (string) ($form['referencias'][0]['codigo'] ?? '');
                            ?>
                            <select name="referencias[0][codigo]" id="ref-codigo" required aria-describedby="ayuda-ref-codigo">
                                <option value="">Selecciona un codigo</option>
                                <option value="1" <?= $codSel === '1' ? 'selected' : ''; ?>>1 - Anula documento</option>
                                <option value="2" <?= $codSel === '2' ? 'selected' : ''; ?>>2 - Corrige texto</option>
                                <option value="3" <?= $codSel === '3' ? 'selected' : ''; ?>>3 - Corrige montos</option>
                            </select>
                            <p class="form-ayuda" id="aviso-nd-anula"<?= $ndMalAnula ? '' : ' hidden'; ?>>
                                <strong>Esta combinacion la rechaza el SII.</strong> Una nota de debito
                                con "1 - Anula documento" solo puede anular una <strong>nota de credito</strong>
                                (tipo 61). Para corregir el monto de una factura, usa
                                "3 - Corrige montos". Si la emites asi, el folio se pierde igual.
                            </p>
                            <small class="form-ayuda" id="ayuda-ref-codigo">
                                CodRef. Que puede referenciar cada codigo, segun el Formato DTE del SII:
                            </small>
                            <ul class="lista-ayuda">
                                <li><strong>1 &mdash; Anula:</strong> una nota de credito anula una factura,
                                una nota de debito o una factura de compra. Una nota de debito
                                <strong>solo puede anular una nota de credito</strong>.</li>
                                <li><strong>2 &mdash; Corrige texto:</strong> solo nota de credito, sobre el
                                documento cuyo texto se corrige.</li>
                                <li><strong>3 &mdash; Corrige montos:</strong> nota de credito o de debito,
                                sobre cualquier documento. Es el unico codigo con el que una nota de
                                debito puede referenciar una factura.</li>
                            </ul>
                        </div>
                        <div class="form-campo form-campo--ancho">
                            <label for="ref-razon">Razon de la referencia</label>
                            <?php // maxlength 90: es el tope de RazonRef en el esquema del SII. Pasarse rompe el sobre entero. ?>
                            <input type="text" name="referencias[0][razon]" id="ref-razon" value="<?= $vref('razon'); ?>" maxlength="90" placeholder="Anula factura N..."<?= $errStyle('referencias[0].razon'); ?>>
                            <small class="form-ayuda">RazonRef. Hasta 90 caracteres.</small>
                        </div>
                    </div>
                </section>
            <?php else: ?>
                <div class="panel-info">
                    <p class="panel-info__titulo">
                        <span class="panel-info__icono" aria-hidden="true">&#9432;</span>
                        Antes de emitir
                    </p>
                    <ul class="panel-info__lista">
                        <li>El detalle necesita al menos una linea, con cantidad mayor que 0.</li>
                        <li>Si el receptor no esta en tus clientes, marca "Guardar en mis clientes" para reutilizarlo despues.</li>
                        <li>Cada emision aceptada consume un folio de tu CAF.</li>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <section class="tarjeta" aria-labelledby="titulo-detalle">
        <h2 id="titulo-detalle">Detalle del documento</h2>
        <?php if ($cotizacionId !== null): ?>
            <div class="alerta alerta--info" role="status">
                Facturando la cotizacion N&deg; <?= (int) $cotizacionNumero; ?>.
                Las cantidades vienen con lo que queda pendiente de cada linea y se pueden
                bajar; los precios se pueden cambiar. Las lineas ya facturadas por completo
                aparecen en cero.
                <strong>Una linea que agregues aqui es venta nueva</strong> y no descuenta
                saldo de la cotizacion.
            </div>
        <?php endif; ?>
        <div class="tabla-scroll">
            <table id="tabla-detalle" class="tabla-datos tabla-editable">
                <thead>
                    <tr>
                        <th class="col-producto">Producto / servicio</th>
                        <th class="col-cantidad">Cantidad</th>
                        <th class="col-precio">Precio unit.</th>
                        <th class="col-unidad">Unidad</th>
                        <th class="col-exento">Exento</th>
                        <th class="col-accion"><span class="visualmente-oculto">Accion</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($detalles as $i => $d): ?>
                        <tr>
                            <td class="col-producto"><?php if (! empty($d['cotizacion_linea_id'])): ?><input type="hidden" name="detalles[<?= $i; ?>][cotizacion_linea_id]" value="<?= (int) $d['cotizacion_linea_id']; ?>"><?php endif; ?><input type="text" list="productos-list" name="detalles[<?= $i; ?>][nombre]" value="<?= htmlspecialchars((string) ($d['nombre'] ?? '')); ?>" class="det-nombre" aria-label="Producto o servicio"<?= $errStyle("detalles[{$i}].nombre"); ?>><?php if (isset($d['pendiente'])): ?><span class="tabla-datos__secundario"><?= ((float) $d['pendiente']) > 0 ? 'pendiente: ' . rtrim(rtrim(number_format((float) $d['pendiente'], 4, ',', '.'), '0'), ',') : 'ya facturada por completo'; ?></span><?php endif; ?></td>
                            <td class="col-cantidad"><input type="text" inputmode="decimal" name="detalles[<?= $i; ?>][cantidad]" value="<?= htmlspecialchars((string) ($d['cantidad'] ?? '')); ?>" aria-label="Cantidad"<?= $errStyle("detalles[{$i}].cantidad"); ?>></td>
                            <td class="col-precio"><input type="text" inputmode="decimal" name="detalles[<?= $i; ?>][precioUnitario]" value="<?= htmlspecialchars((string) ($d['precioUnitario'] ?? '')); ?>" class="det-precio" aria-label="Precio unitario"<?= $errStyle("detalles[{$i}].precioUnitario"); ?>></td>
                            <td class="col-unidad"><input type="text" name="detalles[<?= $i; ?>][unidad]" value="<?= htmlspecialchars((string) ($d['unidad'] ?? '')); ?>" class="det-unidad" aria-label="Unidad"></td>
                            <td class="col-exento"><input type="checkbox" name="detalles[<?= $i; ?>][exento]" value="1" class="det-exento" aria-label="Exento de IVA" <?= $exentoForzado || ! empty($d['exento']) ? 'checked' : ''; ?><?= $exentoForzado ? ' disabled' : ''; ?>></td>
                            <td class="col-accion"><button type="button" class="quitar-linea" title="Quitar linea" aria-label="Quitar linea">&times;</button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <datalist id="productos-list">
            <?php foreach ($productos as $p): ?>
                <option value="<?= htmlspecialchars((string) $p['nombre']); ?>"></option>
            <?php endforeach; ?>
        </datalist>
        <p><button type="button" id="agregar-linea" class="boton-secundario">+ Agregar linea</button></p>
    </section>

    <div class="acciones-grupo">
        <button type="submit" class="boton-principal"><?= htmlspecialchars($textoEmitir); ?></button>
        <a class="boton-texto" href="/panel">Volver al panel</a>
    </div>
</form>

<script>
(function () {
    var PRODUCTOS = <?= json_encode($mapaProductos, JSON_UNESCAPED_UNICODE); ?>;
    // Los mismos tipos que consulta el PHP: no hay lista duplicada en el JS.
    var REF_SIN_IVA = <?= json_encode(\Plantiflex\FacturacionCl\Enums\TipoDte::SIN_IVA); ?>;
    // UNA SOLA BANDERA PARA LOS DOS CASOS que obligan a exento: el tipo 34 (fijo,
    // lo decide el PHP y no cambia) y la nota que corrige un documento sin IVA
    // (movil: depende de lo que se teclee en #ref-tipo). Antes esto era ES_EXENTA
    // y solo cubria el primero.
    var exentoForzado = <?= $exentoForzado ? 'true' : 'false'; ?>;
    var tbody = document.querySelector('#tabla-detalle tbody');
    var idx = <?= count($detalles); ?>;

    function rellenarDesdeProducto(fila) {
        var nombre = fila.querySelector('.det-nombre').value;
        var p = PRODUCTOS[nombre];
        if (!p) { return; }
        var precio = fila.querySelector('.det-precio');
        var unidad = fila.querySelector('.det-unidad');
        var exento = fila.querySelector('.det-exento');
        if (precio && p.precio !== null && precio.value === '') { precio.value = p.precio; }
        if (unidad && unidad.value === '') { unidad.value = p.unidad || ''; }
        // En una factura exenta el maestro NO manda: un producto afecto no puede
        // desmarcar la casilla, porque en un 34 no existe la linea afecta.
        if (exento && !exentoForzado) { exento.checked = p.exento === 1; }
    }

    // Debe producir el MISMO DOM que la fila que renderiza el PHP de arriba
    // (mismas celdas, clases de columna, clases de control, aria-label y
    // nombres con el indice). Si cambia una, cambia la otra.
    function nuevaFilaHTML(n) {
        return '<td class="col-producto"><input type="text" list="productos-list" name="detalles[' + n + '][nombre]" class="det-nombre" aria-label="Producto o servicio"></td>' +
            '<td class="col-cantidad"><input type="text" inputmode="decimal" name="detalles[' + n + '][cantidad]" aria-label="Cantidad"></td>' +
            '<td class="col-precio"><input type="text" inputmode="decimal" name="detalles[' + n + '][precioUnitario]" class="det-precio" aria-label="Precio unitario"></td>' +
            '<td class="col-unidad"><input type="text" name="detalles[' + n + '][unidad]" class="det-unidad" aria-label="Unidad"></td>' +
            '<td class="col-exento"><input type="checkbox" name="detalles[' + n + '][exento]" value="1" class="det-exento" aria-label="Exento de IVA"' + (exentoForzado ? ' checked disabled' : '') + '></td>' +
            '<td class="col-accion"><button type="button" class="quitar-linea" title="Quitar linea" aria-label="Quitar linea">&times;</button></td>';
    }

    document.getElementById('agregar-linea').addEventListener('click', function () {
        var tr = document.createElement('tr');
        tr.innerHTML = nuevaFilaHTML(idx++);
        tbody.appendChild(tr);
    });

    tbody.addEventListener('click', function (e) {
        if (e.target.classList.contains('quitar-linea')) {
            if (tbody.querySelectorAll('tr').length > 1) { e.target.closest('tr').remove(); }
        }
    });
    tbody.addEventListener('change', function (e) {
        if (e.target.classList.contains('det-nombre')) { rellenarDesdeProducto(e.target.closest('tr')); }
    });

    // EXENTO FORZADO EN VIVO SEGUN EL TIPO DE DOCUMENTO REFERENCIADO.
    //
    // #ref-tipo solo existe en nota de credito y de debito; en factura y factura
    // exenta este bloque no encuentra nada y no hace nada. Se dispara con 'input'
    // y no con 'change' para que el aviso aparezca mientras se teclea, no al
    // salir del campo.
    //
    // ESTO ES COMODIDAD Y AVISO, NO LA GARANTIA: una casilla deshabilitada NO
    // viaja en el POST, asi que quien pone el exento=true es
    // armarDocumentoEmision(), y el motor lo valida otra vez por su cuenta.
    var refTipo = document.getElementById('ref-tipo');
    if (refTipo) {
        var aplicarExento = function () {
            var v = refTipo.value.trim();
            exentoForzado = v !== '' && REF_SIN_IVA.indexOf(parseInt(v, 10)) !== -1;
            var casillas = tbody.querySelectorAll('.det-exento');
            for (var i = 0; i < casillas.length; i++) {
                // Al FORZAR se marca y se bloquea. Al soltar el forzado solo se
                // desbloquea: lo que el usuario haya marcado a mano se respeta.
                if (exentoForzado) { casillas[i].checked = true; }
                casillas[i].disabled = exentoForzado;
            }
            var aviso = document.getElementById('aviso-ref-exenta');
            if (aviso) { aviso.hidden = !exentoForzado; }
        };
        refTipo.addEventListener('input', aplicarExento);
    }

    // AVISO DE LA NOTA DE DEBITO QUE ANULA LO QUE NO DEBE. Solo en el 56: en la
    // nota de credito CodRef=1 sobre una factura es justamente el caso normal.
    // Mismo reparto de responsabilidades que arriba -- esto avisa, el motor es
    // quien rechaza --, y por eso el boton de emitir NO se deshabilita: la regla
    // que manda vive en el servidor y aqui no se simula.
    var ES_NOTA_DEBITO = <?= $tipoDte === 56 ? 'true' : 'false'; ?>;
    var NOTAS_CREDITO  = <?= json_encode(\Plantiflex\FacturacionCl\Enums\TipoDte::NOTAS_CREDITO); ?>;
    var refCodigo = document.getElementById('ref-codigo');
    var avisoNd   = document.getElementById('aviso-nd-anula');
    if (ES_NOTA_DEBITO && refTipo && refCodigo && avisoNd) {
        var revisarNd = function () {
            var t = parseInt(refTipo.value.trim(), 10);
            avisoNd.hidden = !(refCodigo.value === '1' && !isNaN(t) && NOTAS_CREDITO.indexOf(t) === -1);
        };
        refTipo.addEventListener('input', revisarNd);
        refCodigo.addEventListener('change', revisarNd);
    }

    // Autocompletado del receptor por RUT.
    var rut = document.getElementById('receptor-rut');
    var aviso = document.getElementById('rut-aviso');
    rut.addEventListener('blur', function () {
        var v = rut.value.trim();
        if (v === '') { aviso.textContent = ''; return; }
        fetch('/ventas/cliente-por-rut?rut=' + encodeURIComponent(v))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.estado === 'rut_invalido') { aviso.textContent = 'RUT invalido.'; return; }
                if (data.estado === 'no_encontrado') {
                    aviso.textContent = 'Cliente nuevo (puedes completar sus datos y marcar "Guardar en mis clientes").';
                    return;
                }
                var c = data.cliente || {};
                // POR ID Y CON GUARDA: en boleta los campos giro/direccion/comuna
                // NO EXISTEN en el DOM. Sin el chequeo, el primer getElementById
                // que devuelve null lanza y aborta el resto del autocompletado --
                // el usuario veria el aviso a medias y la razon social sin llenar.
                var poner = function (id, valor) {
                    var el = document.getElementById(id);
                    if (el) { el.value = valor || ''; }
                };
                poner('receptor-razonSocial', c.razon_social);
                poner('receptor-giro', c.giro);
                poner('receptor-direccion', c.direccion);
                poner('receptor-comuna', c.comuna);
                poner('receptor-email', c.email);
                aviso.textContent = c.activo === false
                    ? 'Cliente encontrado (INACTIVO en tus maestros).'
                    : 'Cliente encontrado: datos autocompletados.';
            })
            .catch(function () { aviso.textContent = ''; });
    });
})();
</script>

<?php if ($esFactura): ?>
<script>
// VENCIMIENTO ATADO A LA FORMA DE PAGO. Solo se muestra y solo se exige con
// credito (valor 2). Con contado o sin costo el campo se oculta Y SE VACIA: si
// quedara con una fecha tecleada antes de cambiar de opcion, el navegador la
// enviaria igual y el motor rechazaria la combinacion.
//
// Esto es comodidad, no la garantia: armarDocumentoEmision() descarta la fecha
// si la forma de pago no es 2, y el motor rechaza tanto "credito sin fecha" como
// "fecha sin credito". Tres capas, y la que manda es la del motor.
(function () {
    var sel   = document.getElementById('forma-pago');
    var campo = document.getElementById('campo-vencimiento');
    var fecha = document.getElementById('fecha-vencimiento');
    if (!sel || !campo || !fecha) { return; }

    function sincronizar() {
        var esCredito = sel.value === '2';
        campo.hidden = !esCredito;
        fecha.required = esCredito;
        if (!esCredito) { fecha.value = ''; }
    }

    sel.addEventListener('change', sincronizar);
    sincronizar(); // al cargar, y tambien al re-renderizar tras un 422
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
