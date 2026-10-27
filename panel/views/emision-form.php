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
 * PRECIO SIN IVA O PRECIO FINAL (octubre 2026). Cada linea tiene los dos y se
 * escribe el que se conozca; el otro lo calcula el JS. Ya no existe la casilla
 * "los precios incluyen IVA (brutos)": quien decide si el documento va bruto o
 * neto es preciosDelDetalle() en public/index.php, mirando .det-origen. En
 * boleta va primero el precio final, que es como piensa quien vende al publico.
 *
 * RESUMEN DE TOTALES EN VIVO. Los totales definitivos los calcula el motor
 * (DteXmlBuilder::totales()) al construir el XML; el resumen de abajo repite
 * esa misma formula en JS (calcularTotales) para que se vea antes de emitir.
 * Si cambia la formula del motor, cambia tambien la de aqui.
 *
 * LO QUE ESTA PANTALLA NO PUEDE PROMETER, y por eso no aparece:
 *   - Fecha de emision, forma de pago, vencimiento y moneda: no existen en el
 *     payload ni en el motor. La fecha la pone el motor al emitir.
 *   - Descripcion y descuento por linea: el backend los lee, pero no forman
 *     parte de la interfaz autorizada todavia.
 *
 * CONTRATO CON EL BACKEND Y EL JS -- no tocar sin revisar handleEmisionPost():
 *   - name de cada control (incluida la sintaxis de arrays) y el hidden
 *     idem_key, que es lo que impide una doble emision con folio real.
 *   - id/clase que usa el JS: #form-emision, #tabla-detalle, #agregar-linea,
 *     .quitar-linea, .det-nombre, .det-precio, .det-precio-final, .det-origen,
 *     .det-unidad, .det-exento, #productos-list, #resumen-totales,
 *     #receptor-* y #rut-aviso.
 *   - La fila del detalle esta duplicada: aqui en PHP y en nuevaFilaHTML() del
 *     JS. Las dos deben producir el mismo DOM.
 *
 * DISENO (octubre 2026, design system + seccion 14 "Efectos de carga"):
 *   - Todo el ancho, en columnas: receptor | condiciones arriba, la referencia
 *     (NC/ND) y el detalle a ancho completo. En movil el detalle se lee como
 *     tarjetas (.tabla-datos--cards + data-label en cada celda, en PHP Y en
 *     nuevaFilaHTML(), que siguen produciendo el mismo DOM).
 *   - El error que devuelve el motor (errorCampo) se pinta BAJO su control,
 *     con icono, texto, aria-invalid y un resumen arriba con enlace; ya no se
 *     muestra el nombre tecnico del campo ("receptor.rut").
 *   - Emitir confirma en un <dialog> que nombra el documento ("Si, emitir la
 *     factura electronica"): asigna un folio real y no se deshace.
 *   - Espera: el doble envio se corta al instante (y ademas lo cubre
 *     idem_key); a los 300 ms spinner dentro del boton; a los 10 s se dice que
 *     se esta enviando al SII, con barra indeterminada.
 *   - Con JavaScript el formulario pasa a noValidate y los mismos required se
 *     validan aqui, con el error bajo el control. SIN JavaScript el required
 *     nativo sigue intacto: es la red de los folios quemados por CodRef y
 *     FchRef (ver sus notas mas abajo).
 *   - Ids nuevos en las celdas del detalle (det-{i}-{campo}) para poder
 *     enlazar y enfocar el error del motor; tambien en nuevaFilaHTML().
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
// EL CAMPO QUE DEVOLVIO EL MOTOR EN EL 422 (un campo a la vez, fail-fast), o
// el que marca el propio handler (RUT). Se traduce a id de control para pintar
// el error bajo el y enlazarlo desde el resumen. Si no se reconoce, el mensaje
// queda en el aviso general de arriba, como antes.
$errorCampo = (string) ($errorCampo ?? '');
$mapaCampos = [
    'receptor.rut'          => 'receptor-rut',
    'receptor.razonSocial'  => 'receptor-razonSocial',
    'receptor.giro'         => 'receptor-giro',
    'receptor.direccion'    => 'receptor-direccion',
    'receptor.comuna'       => 'receptor-comuna',
    'receptor.email'        => 'receptor-email',
    'formaPago'             => 'forma-pago',
    'fechaVencimiento'      => 'fecha-vencimiento',
    'descuentoGlobalPct'    => 'descuento-global',
    'referencias'           => 'ref-tipo',
    'referencias[0].tipoDocumento' => 'ref-tipo',
    'referencias[0].folio'  => 'ref-folio',
    'referencias[0].fecha'  => 'ref-fecha',
    'referencias[0].codigo' => 'ref-codigo',
    'referencias[0].razon'  => 'ref-razon',
];
$idError = $mapaCampos[$errorCampo] ?? null;
if ($idError === null && preg_match('/^detalles\[(\d+)\]\.(nombre|cantidad|precioUnitario|unidad)$/', $errorCampo, $mErr)) {
    $idError = 'det-' . $mErr[1] . '-' . $mErr[2];
}
$errorEnCampo = $idError !== null && ! empty($errorMsg);
/** aria-invalid del control que fallo. */
$inv = static fn (string $id): string => $errorEnCampo && $idError === $id ? ' aria-invalid="true"' : '';
/** Error bajo un control: icono + texto, vacio y oculto si no es el que fallo. */
$campoError = static function (string $id) use ($errorEnCampo, $idError, $errorMsg): string {
    $msg = $errorEnCampo && $idError === $id ? (string) $errorMsg : '';
    return '<p class="campo-error" id="error-' . $id . '"' . ($msg === '' ? ' hidden' : '') . '>'
        . iconoSvg('alerta', 16, '') . '<span>' . htmlspecialchars($msg) . '</span></p>';
};
/** Clase del contenedor del control que fallo. */
$cc = static fn (string $id, string $extra = ''): string
    => trim('form-campo ' . $extra . ($errorEnCampo && $idError === $id ? ' form-campo--error' : ''));
// Etiqueta del campo en el resumen de errores.
$etiquetaError = match (true) {
    $idError === null => '',
    str_starts_with($idError, 'det-') => 'Detalle, linea ' . ((int) explode('-', $idError)[1] + 1),
    default => [
        'receptor-rut' => 'RUT', 'receptor-razonSocial' => 'Razon social', 'receptor-giro' => 'Giro',
        'receptor-direccion' => 'Direccion', 'receptor-comuna' => 'Comuna', 'receptor-email' => 'Email',
        'forma-pago' => 'Forma de pago', 'fecha-vencimiento' => 'Fecha de vencimiento',
        'descuento-global' => 'Descuento global', 'ref-tipo' => 'Tipo de documento referenciado',
        'ref-folio' => 'Folio referenciado', 'ref-fecha' => 'Fecha del documento referenciado',
        'ref-codigo' => 'Codigo de referencia', 'ref-razon' => 'Razon de la referencia',
    ][$idError] ?? $idError,
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
// "la factura electronica" / "la nota de credito": para el dialogo, que nombra
// el documento en vez de decir "Aceptar".
$docConArticulo = 'la ' . strtolower($tituloDoc);

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
    <div class="acciones-grupo acciones-grupo--header">
        <a class="boton-secundario" href="/ventas/panel-emision">Ver documentos emitidos</a>
    </div>
</div>
<p class="dash-subtitulo">
    Completa el receptor y el detalle. Los campos con <span class="campo-obligatorio">*</span> son obligatorios para el SII.
</p>

<div class="config-ancho">

<?php if (! empty($flashError)): ?>
    <div class="alerta alerta--error" role="alert">
        <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
        <div class="alerta__cuerpo"><p><?= htmlspecialchars($flashError); ?></p></div>
    </div>
<?php endif; ?>
<?php
/* RESUMEN DE ERRORES. Existe siempre (oculto si no hay nada): el JavaScript
   reusa este mismo contenedor al validar antes de emitir. Si el error del
   motor no se reconoce como un control, va como mensaje sin enlace. */
?>
<div class="alerta alerta--error" id="resumen-errores" role="alert" tabindex="-1"<?= empty($errorMsg) ? ' hidden' : ''; ?>>
    <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
    <div class="alerta__cuerpo">
        <p class="alerta__titulo" data-resumen-titulo>No se emitio nada. Revisa esto antes de volver a emitir:</p>
        <ul class="alerta__lista" data-resumen-lista>
            <?php if (! empty($errorMsg)): ?>
                <?php if ($errorEnCampo): ?>
                    <li><a href="#<?= htmlspecialchars($idError); ?>"><?= htmlspecialchars($etiquetaError); ?></a>: <?= htmlspecialchars($errorMsg); ?></li>
                <?php else: ?>
                    <li><?= htmlspecialchars($errorMsg); ?></li>
                <?php endif; ?>
            <?php endif; ?>
        </ul>
    </div>
</div>

<form method="post" action="<?= htmlspecialchars($accion); ?>" id="form-emision" class="form-compacto" data-validacion-propia>
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

    <div class="config-columnas config-columnas--2">
            <section class="tarjeta" aria-labelledby="titulo-receptor">
                <h2 id="titulo-receptor">Datos del receptor</h2>
                <div class="form-grid">
                    <div class="<?= $cc('receptor-rut'); ?>">
                        <label for="receptor-rut">RUT <?= $esBoleta ? '(opcional)' : $req; ?></label>
                        <?php // inputmode text: el RUT puede terminar en K. Se ordena al salir (12.345.678-9) y se valida el digito; el servidor lo normaliza igual. ?>
                        <input type="text" name="receptor[rut]" id="receptor-rut" value="<?= $vr('rut'); ?>" placeholder="12.345.678-9" inputmode="text" autocomplete="off" spellcheck="false" aria-describedby="rut-aviso error-receptor-rut<?= $esBoleta ? ' ayuda-rut-boleta' : ''; ?>"<?= $inv('receptor-rut'); ?>>
                        <?= $campoError('receptor-rut'); ?>
                        <small class="form-ayuda" id="rut-aviso" aria-live="polite"></small>
                        <?php if ($esBoleta): ?>
                            <small class="form-ayuda" id="ayuda-rut-boleta">Si lo dejas vacio, la boleta sale a Consumidor Final (66666666-6), lo normal en una venta de mostrador.</small>
                        <?php endif; ?>
                    </div>
                    <div class="<?= $cc('receptor-razonSocial'); ?>">
                        <label for="receptor-razonSocial">Razon social <?= $esBoleta ? '(opcional)' : $req; ?></label>
                        <input type="text" name="receptor[razonSocial]" id="receptor-razonSocial" data-max-sii="100" value="<?= $vr('razonSocial'); ?>" aria-describedby="error-receptor-razonSocial"<?= $inv('receptor-razonSocial'); ?>>
                        <?= $campoError('receptor-razonSocial'); ?>
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
                    <div class="<?= $cc('receptor-giro', 'form-campo--ancho'); ?>">
                        <label for="receptor-giro">Giro <?= $req; ?></label>
                        <input type="text" name="receptor[giro]" id="receptor-giro" data-max-sii="40" value="<?= $vr('giro'); ?>" aria-describedby="error-receptor-giro"<?= $inv('receptor-giro'); ?>>
                        <?= $campoError('receptor-giro'); ?>
                    </div>
                    <div class="<?= $cc('receptor-direccion', 'form-campo--ancho'); ?>">
                        <label for="receptor-direccion">Direccion <?= $req; ?></label>
                        <input type="text" name="receptor[direccion]" id="receptor-direccion" data-max-sii="70" value="<?= $vr('direccion'); ?>" aria-describedby="error-receptor-direccion"<?= $inv('receptor-direccion'); ?>>
                        <?= $campoError('receptor-direccion'); ?>
                    </div>
                    <div class="<?= $cc('receptor-comuna'); ?>">
                        <label for="receptor-comuna">Comuna <?= $req; ?></label>
                        <input type="text" name="receptor[comuna]" id="receptor-comuna" data-max-sii="20" value="<?= $vr('comuna'); ?>" aria-describedby="error-receptor-comuna"<?= $inv('receptor-comuna'); ?>>
                        <?= $campoError('receptor-comuna'); ?>
                    </div>
                    <?php endif; ?>
                    <div class="<?= $cc('receptor-email'); ?>">
                        <label for="receptor-email">Email (opcional)</label>
                        <input type="email" name="receptor[email]" id="receptor-email" value="<?= $vr('email'); ?>" autocomplete="email" spellcheck="false" aria-describedby="error-receptor-email ayuda-email"<?= $inv('receptor-email'); ?>>
                        <?= $campoError('receptor-email'); ?>
                        <small class="form-ayuda" id="ayuda-email">A este correo sale el documento.</small>
                    </div>
                    <div class="form-campo form-campo--ancho">
                        <label class="form-check" for="guardar-cliente">
                            <input type="checkbox" name="guardar_cliente" id="guardar-cliente" value="1" <?= ! empty($form['guardar_cliente']) ? 'checked' : ''; ?>>
                            Guardar en mis clientes
                        </label>
                    </div>
                    <?php /* GUARDADO AUTOMATICO SIN EMITIR: aqui se anuncia el
                       resultado (region viva). Ver el JS del receptor. */ ?>
                    <div class="form-campo form-campo--ancho">
                        <small class="form-ayuda" id="guardar-cliente-aviso" aria-live="polite">Si es un cliente nuevo, se guarda solo en tus clientes al salir del correo, sin emitir el documento.</small>
                    </div>
                </div>
            </section>

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
                        <div class="<?= $cc('forma-pago', 'form-campo--corto'); ?>">
                            <label for="forma-pago">Forma de pago <?= $req; ?></label>
                            <select name="formaPago" id="forma-pago" required data-requerido="Elige la forma de pago: si no se informa, el SII asume Credito." aria-describedby="error-forma-pago ayuda-forma-pago"<?= $inv('forma-pago'); ?>>
                                <option value="" disabled <?= ($form['formaPago'] ?? '') === '' ? 'selected' : ''; ?>>Elige una</option>
                                <?php foreach ([1 => 'Contado', 2 => 'Credito', 3 => 'Sin costo (entrega gratuita)'] as $v => $glosa): ?>
                                    <option value="<?= $v; ?>" <?= (string) ($form['formaPago'] ?? '') === (string) $v ? 'selected' : ''; ?>><?= $glosa; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?= $campoError('forma-pago'); ?>
                            <small class="form-ayuda" id="ayuda-forma-pago">
                                Si no se informa, el SII asume Credito.
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
                        <div class="<?= $cc('fecha-vencimiento', 'form-campo--corto'); ?>" id="campo-vencimiento" hidden>
                            <label for="fecha-vencimiento">Fecha de vencimiento <?= $req; ?></label>
                            <input type="date" name="fechaVencimiento" id="fecha-vencimiento"
                                   value="<?= htmlspecialchars((string) ($form['fechaVencimiento'] ?? '')); ?>"
                                   data-requerido="Elige la fecha de vencimiento: con Credito es obligatoria."
                                   aria-describedby="error-fecha-vencimiento ayuda-vencimiento"<?= $inv('fecha-vencimiento'); ?>>
                            <?= $campoError('fecha-vencimiento'); ?>
                            <small class="form-ayuda" id="ayuda-vencimiento">Obligatoria cuando la forma de pago es Credito.</small>
                        </div>
                    <?php endif; ?>
                    <div class="<?= $cc('descuento-global', 'form-campo--corto'); ?>">
                        <label for="descuento-global">Descuento global % (opcional)</label>
                        <input type="text" inputmode="decimal" name="descuentoGlobalPct" id="descuento-global" value="<?= htmlspecialchars((string) ($form['descuentoGlobalPct'] ?? '')); ?>" aria-describedby="error-descuento-global ayuda-descuento"<?= $inv('descuento-global'); ?>>
                        <?= $campoError('descuento-global'); ?>
                        <small class="form-ayuda" id="ayuda-descuento">Aplica sobre los items afectos. Ejemplo: 10.</small>
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

            <?php if (! $esNota): ?>
                <div class="panel-info">
                    <p class="panel-info__titulo">
                        <?= iconoSvg('info', 20, 'panel-info__icono'); ?>
                        Antes de emitir
                    </p>
                    <ul class="panel-info__lista">
                        <li>El detalle necesita al menos una linea, con cantidad mayor que 0.</li>
                        <li>Si el receptor no esta en tus clientes, se guarda solo al completar sus datos y salir del correo.</li>
                        <li>Cada emision aceptada consume un folio de tu CAF.</li>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>

            <?php if ($esNota): ?>
                <section class="tarjeta" aria-labelledby="titulo-referencia">
                    <h2 id="titulo-referencia">Referencia del documento</h2>
                    <p class="tarjeta__intro">Una nota de credito o debito debe referenciar el documento que corrige o anula.</p>
                    <div class="config-columnas config-columnas--2">
                    <div class="form-grid">
                        <div class="<?= $cc('ref-tipo'); ?>">
                            <label for="ref-tipo">Tipo de documento <?= $req; ?></label>
                            <input type="text" inputmode="numeric" name="referencias[0][tipoDocumento]" id="ref-tipo" value="<?= $vref('tipoDocumento'); ?>" placeholder="33" aria-describedby="error-ref-tipo ayuda-ref-tipo"<?= $inv('ref-tipo'); ?>>
                            <?= $campoError('ref-tipo'); ?>
                            <small class="form-ayuda" id="ayuda-ref-tipo">33 para factura, 34 para factura exenta.</small>
                        </div>
                        <div class="<?= $cc('ref-folio'); ?>">
                            <label for="ref-folio">Folio <?= $req; ?></label>
                            <input type="text" inputmode="numeric" name="referencias[0][folio]" id="ref-folio" value="<?= $vref('folio'); ?>" aria-describedby="error-ref-folio"<?= $inv('ref-folio'); ?>>
                            <?= $campoError('ref-folio'); ?>
                        </div>
                        <div class="form-campo form-campo--ancho" id="aviso-ref-exenta"<?= $refSinIva ? '' : ' hidden'; ?>>
                            <div class="alerta alerta--advertencia">
                                <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
                                <div class="alerta__cuerpo">
                                    <p>El documento que estas corrigiendo <strong>no lleva IVA</strong>, asi que esta
                                    nota tampoco: el detalle queda exento completo y la casilla no se puede
                                    desmarcar. Una nota con IVA sobre un documento exento la rechaza el SII,
                                    y el folio se pierde igual.</p>
                                </div>
                            </div>
                        </div>
                        <div class="<?= $cc('ref-fecha', 'form-campo--ancho form-campo--corto'); ?>">
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
                            <input type="date" name="referencias[0][fecha]" id="ref-fecha" value="<?= $vref('fecha'); ?>" required data-requerido="Elige la fecha de emision del documento que estas corrigiendo." aria-describedby="error-ref-fecha ayuda-ref-fecha"<?= $inv('ref-fecha'); ?>>
                            <?= $campoError('ref-fecha'); ?>
                            <small class="form-ayuda" id="ayuda-ref-fecha">Es la fecha de emision del documento que corriges, no la de hoy.</small>
                        </div>
                        <div class="<?= $cc('ref-codigo', 'form-campo--ancho'); ?>">
                            <label for="ref-codigo">Codigo de referencia <?= $req; ?></label>
                            <?php
                                // La opcion vacia NO es decorativa: sin ella el navegador marca la
                                // primera opcion y el formulario envia CodRef=1 aunque el usuario no
                                // haya elegido nada. Junto con required, obliga a una eleccion
                                // consciente venga el codigo prellenado por query string o vacio.
                                $codSel = (string) ($form['referencias'][0]['codigo'] ?? '');
                            ?>
                            <select name="referencias[0][codigo]" id="ref-codigo" required data-requerido="Elige el codigo de referencia: que hace esta nota con el documento." aria-describedby="error-ref-codigo ayuda-ref-codigo"<?= $inv('ref-codigo'); ?>>
                                <option value="">Selecciona un codigo</option>
                                <option value="1" <?= $codSel === '1' ? 'selected' : ''; ?>>1 - Anula documento</option>
                                <option value="2" <?= $codSel === '2' ? 'selected' : ''; ?>>2 - Corrige texto</option>
                                <option value="3" <?= $codSel === '3' ? 'selected' : ''; ?>>3 - Corrige montos</option>
                            </select>
                            <?= $campoError('ref-codigo'); ?>
                            <div class="alerta alerta--advertencia" id="aviso-nd-anula"<?= $ndMalAnula ? '' : ' hidden'; ?>>
                                <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
                                <div class="alerta__cuerpo">
                                    <p class="alerta__titulo">Esta combinacion la rechaza el SII</p>
                                    <p>Una nota de debito con "1 - Anula documento" solo puede anular una
                                    <strong>nota de credito</strong> (tipo 61). Para corregir el monto de una
                                    factura, usa "3 - Corrige montos". Si la emites asi, el folio se pierde igual.</p>
                                </div>
                            </div>
                        </div>
                        <div class="<?= $cc('ref-razon', 'form-campo--ancho'); ?>">
                            <label for="ref-razon">Razon de la referencia (opcional)</label>
                            <?php // maxlength 90: es el tope de RazonRef en el esquema del SII. Pasarse rompe el sobre entero. ?>
                            <input type="text" name="referencias[0][razon]" id="ref-razon" value="<?= $vref('razon'); ?>" maxlength="90" data-max-sii="90" placeholder="Anula factura N..." aria-describedby="error-ref-razon ayuda-ref-razon"<?= $inv('ref-razon'); ?>>
                            <?= $campoError('ref-razon'); ?>
                            <small class="form-ayuda" id="ayuda-ref-razon">Hasta 90 caracteres.</small>
                        </div>
                    </div>
                    <div class="panel-info">
                            <p class="panel-info__titulo" id="ayuda-ref-codigo">
                                <?= iconoSvg('info', 20, 'panel-info__icono'); ?>
                                Que puede referenciar cada codigo
                            </p>
                            <p>Segun el Formato DTE del SII:</p>
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
                    </div>
                </section>
            <?php endif; ?>

    <section class="tarjeta" aria-labelledby="titulo-detalle">
        <h2 id="titulo-detalle">Detalle del documento</h2>
        <?php if ($cotizacionId !== null): ?>
            <div class="alerta alerta--info" role="status">
                <?= iconoSvg('info', 20, 'alerta__icono'); ?>
                <div class="alerta__cuerpo">
                    <p class="alerta__titulo">Facturando la cotizacion N&deg; <?= (int) $cotizacionNumero; ?></p>
                    <p>Las cantidades vienen con lo que queda pendiente de cada linea y se pueden
                    bajar; los precios se pueden cambiar. Las lineas ya facturadas por completo
                    aparecen en cero. <strong>Una linea que agregues aqui es venta nueva</strong> y no
                    descuenta saldo de la cotizacion.</p>
                </div>
            </div>
        <?php endif; ?>
        <p class="form-ayuda" id="ayuda-precios">Escribe el precio que conozcas: el precio final que paga el cliente o el precio sin IVA. El otro se calcula solo.</p>
        <div class="tabla-scroll">
            <table id="tabla-detalle" class="tabla-datos tabla-editable tabla-datos--cards">
                <thead>
                    <tr>
                        <th class="col-producto">Producto / servicio</th>
                        <th class="col-cantidad">Cantidad</th>
                        <?php if ($esBoleta): ?>
                            <th class="col-precio">Precio final ($)</th>
                            <th class="col-precio">Precio sin IVA ($)</th>
                        <?php else: ?>
                            <th class="col-precio">Precio sin IVA ($)</th>
                            <th class="col-precio">Precio final ($)</th>
                        <?php endif; ?>
                        <th class="col-unidad">Unidad</th>
                        <th class="col-exento">Exento</th>
                        <th class="col-accion"><span class="visualmente-oculto">Accion</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($detalles as $i => $d): ?>
                        <tr>
                            <td class="col-producto" data-label="Producto"><?php if (! empty($d['cotizacion_linea_id'])): ?><input type="hidden" name="detalles[<?= $i; ?>][cotizacion_linea_id]" value="<?= (int) $d['cotizacion_linea_id']; ?>"><?php endif; ?><input type="text" list="productos-list" name="detalles[<?= $i; ?>][nombre]" value="<?= htmlspecialchars((string) ($d['nombre'] ?? '')); ?>" class="det-nombre" id="det-<?= $i; ?>-nombre" data-max-sii="80" data-contador="discreto" aria-label="Producto o servicio"<?= $inv("det-{$i}-nombre"); ?>><?= $campoError("det-{$i}-nombre"); ?><?php if (isset($d['pendiente'])): ?><span class="tabla-datos__secundario"><?= ((float) $d['pendiente']) > 0 ? 'pendiente: ' . rtrim(rtrim(number_format((float) $d['pendiente'], 4, ',', '.'), '0'), ',') : 'ya facturada por completo'; ?></span><?php endif; ?></td>
                            <td class="col-cantidad" data-label="Cantidad"><input type="text" inputmode="decimal" name="detalles[<?= $i; ?>][cantidad]" value="<?= htmlspecialchars((string) ($d['cantidad'] ?? '')); ?>" id="det-<?= $i; ?>-cantidad" aria-label="Cantidad"<?= $inv("det-{$i}-cantidad"); ?>><?= $campoError("det-{$i}-cantidad"); ?></td>
                            <?php
                            $celdaNeto  = '<td class="col-precio" data-label="Precio sin IVA ($)"><input type="hidden" name="detalles[' . $i . '][origen]" value="' . htmlspecialchars((string) ($d['origen'] ?? '')) . '" class="det-origen"><input type="text" inputmode="decimal" name="detalles[' . $i . '][precioUnitario]" value="' . htmlspecialchars((string) ($d['precioUnitario'] ?? '')) . '" class="det-precio" id="det-' . $i . '-precioUnitario" aria-label="Precio unitario sin IVA, en pesos"' . $inv("det-{$i}-precioUnitario") . '>' . $campoError("det-{$i}-precioUnitario") . '</td>';
                            $celdaFinal = '<td class="col-precio" data-label="Precio final, con IVA ($)"><input type="text" inputmode="decimal" name="detalles[' . $i . '][precioFinal]" value="' . htmlspecialchars((string) ($d['precioFinal'] ?? '')) . '" class="det-precio-final" id="det-' . $i . '-precioFinal" aria-label="Precio final unitario que paga el cliente, con IVA, en pesos"></td>';
                            echo $esBoleta ? $celdaFinal . $celdaNeto : $celdaNeto . $celdaFinal;
                            ?>
                            <td class="col-unidad" data-label="Unidad"><input type="text" name="detalles[<?= $i; ?>][unidad]" value="<?= htmlspecialchars((string) ($d['unidad'] ?? '')); ?>" class="det-unidad" id="det-<?= $i; ?>-unidad" data-max-sii="4" data-contador="discreto" aria-label="Unidad"<?= $inv("det-{$i}-unidad"); ?>><?= $campoError("det-{$i}-unidad"); ?></td>
                            <td class="col-exento" data-label="Exento"><input type="checkbox" name="detalles[<?= $i; ?>][exento]" value="1" class="det-exento" aria-label="Exento de IVA" <?= $exentoForzado || ! empty($d['exento']) ? 'checked' : ''; ?><?= $exentoForzado ? ' disabled' : ''; ?>></td>
                            <td class="col-accion" data-label=""><button type="button" class="quitar-linea" title="Quitar linea" aria-label="Quitar linea">&times;</button></td>
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
        <p><button type="button" id="agregar-linea" class="boton-secundario">Agregar linea</button></p>
        <?php /* Misma formula que DteXmlBuilder::totales(): ver calcularTotales(). */ ?>
        <h3 id="titulo-resumen">Resumen</h3>
        <dl class="ficha ficha--montos" id="resumen-totales" aria-labelledby="titulo-resumen">
            <dt>Subtotal sin IVA</dt><dd data-total="neto">$0</dd>
            <dt data-fila="desc" hidden>Descuento</dt><dd data-total="desc" data-fila="desc" hidden>$0</dd>
            <dt>IVA (19%)</dt><dd data-total="iva">$0</dd>
            <dt data-fila="exento" hidden>Sin IVA (exento)</dt><dd data-total="exento" data-fila="exento" hidden>$0</dd>
            <dt class="ficha__total">Total a pagar</dt><dd class="ficha__total" data-total="total">$0</dd>
        </dl>
    </section>

    <?php /* Botonera: lo que sale va a la izquierda, separado; emitir es el
             ultimo, donde termina el recorrido. */ ?>
    <div class="acciones-formulario">
        <a class="boton-texto acciones-formulario__aparte" href="/panel">Volver al panel</a>
        <button type="submit" class="boton-principal" id="boton-emitir"><?= htmlspecialchars($textoEmitir); ?></button>
    </div>
    <?php /* Espera larga (seccion 14): existe desde la carga, oculta, para que
             la region viva se anuncie al aparecer el texto. */ ?>
    <div class="espera-larga" id="espera-emision" role="status" hidden>
        <div class="progreso-indeterminado" role="progressbar" aria-label="Enviando al SII"></div>
        <p></p>
    </div>
</form>

</div>

<dialog class="dialogo-confirmar" id="confirmar-emision" aria-labelledby="confirmar-emision-titulo" aria-describedby="confirmar-emision-texto">
    <h2 class="dialogo-confirmar__titulo" id="confirmar-emision-titulo">Emitir <?= htmlspecialchars($docConArticulo); ?>?</h2>
    <p id="confirmar-emision-texto">Se asigna un folio real y el documento se envia al SII. No se puede deshacer: para corregirlo despues hay que emitir una nota.</p>
    <p data-confirmar-resumen></p>
    <form method="dialog" class="acciones-formulario">
        <button type="submit" value="no" class="boton-secundario">Volver a revisar</button>
        <button type="submit" value="si" class="boton-principal">Si, emitir <?= htmlspecialchars($docConArticulo); ?></button>
    </form>
</dialog>

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
    // La boleta ignora 'exento' (su endpoint no lo lee) y no lleva descuento
    // global: calcularTotales() la trata toda como afecta, igual que el motor.
    var ES_BOLETA = <?= $esBoleta ? 'true' : 'false'; ?>;
    var TASA_IVA = 19;
    var tbody = document.querySelector('#tabla-detalle tbody');
    var idx = <?= count($detalles); ?>;

    function rellenarDesdeProducto(fila) {
        var nombre = fila.querySelector('.det-nombre').value;
        var p = PRODUCTOS[nombre];
        if (!p) { return; }
        var precio = fila.querySelector('.det-precio');
        var unidad = fila.querySelector('.det-unidad');
        var exento = fila.querySelector('.det-exento');
        var final = fila.querySelector('.det-precio-final');
        if (unidad && unidad.value === '') { unidad.value = p.unidad || ''; }
        // En una factura exenta el maestro NO manda: un producto afecto no puede
        // desmarcar la casilla, porque en un 34 no existe la linea afecta.
        if (exento && !exentoForzado) { exento.checked = p.exento === 1; }
        // El precio del maestro es sin IVA (igual que en la carga masiva).
        if (precio && p.precio !== null && precio.value === '' && final.value === '') {
            precio.value = p.precio;
            sincronizarFila(fila, 'neto');
        }
    }

    // --- PRECIO SIN IVA <-> PRECIO FINAL ---
    //
    // .det-origen guarda cual de los dos escribio la persona; ese es el que
    // manda en preciosDelDetalle() del servidor. El otro solo se muestra,
    // redondeado a pesos. En una linea exenta los dos son el mismo numero.
    function numero(v) {
        v = (v || '').trim();
        if (v === '' || !/^\d+(\.\d+)?$/.test(v)) { return null; }
        return parseFloat(v);
    }
    function esExenta(fila) {
        var ex = fila.querySelector('.det-exento');
        return !ES_BOLETA && !!ex && ex.checked;
    }
    function sincronizarFila(fila, desde) {
        var neto = fila.querySelector('.det-precio');
        var final = fila.querySelector('.det-precio-final');
        var origen = fila.querySelector('.det-origen');
        if (desde) { origen.value = desde; }
        if (!origen.value) { origen.value = neto.value.trim() !== '' ? 'neto' : (final.value.trim() !== '' ? 'bruto' : ''); }
        var factor = esExenta(fila) ? 1 : 1 + TASA_IVA / 100;
        if (origen.value === 'neto') {
            var n = numero(neto.value);
            final.value = n === null ? '' : String(Math.round(n * factor));
        } else if (origen.value === 'bruto') {
            var f = numero(final.value);
            neto.value = f === null ? '' : String(Math.round(f / factor));
        }
    }

    // MISMA FORMULA QUE preciosDelDetalle() + DteXmlBuilder::totales(): si
    // todas las lineas afectas se escribieron con precio final, el documento va
    // bruto y el IVA sale por diferencia; si no, va neto. Si cambia alla,
    // cambia aqui.
    function calcularTotales() {
        var lineas = [], hayNeto = false, hayBruto = false;
        Array.prototype.forEach.call(tbody.querySelectorAll('tr'), function (fila) {
            var cant = numero((fila.querySelector('[name$="[cantidad]"]') || {}).value);
            var origen = fila.querySelector('.det-origen').value;
            var ex = esExenta(fila);
            if (cant === null || origen === '') { return; }
            if (!ex) { if (origen === 'neto') { hayNeto = true; } else { hayBruto = true; } }
            lineas.push({ cant: cant, origen: origen, exento: ex,
                neto: numero(fila.querySelector('.det-precio').value),
                final: numero(fila.querySelector('.det-precio-final').value) });
        });
        var brutos = hayBruto && !hayNeto;
        var afecto = 0, exento = 0;
        lineas.forEach(function (l) {
            var precio;
            if (l.exento) { precio = l.origen === 'neto' ? l.neto : l.final; }
            else if (brutos) { precio = l.final; }
            else if (l.origen === 'bruto') { precio = l.final === null ? null : Math.round(l.final / (1 + TASA_IVA / 100) * 1e6) / 1e6; }
            else { precio = l.neto; }
            if (precio === null) { return; }
            var monto = Math.round(l.cant * precio);
            if (l.exento) { exento += monto; } else { afecto += monto; }
        });
        var neto = 0, iva = 0, desc = 0;
        if (afecto > 0) {
            neto = brutos ? Math.round(afecto / (1 + TASA_IVA / 100)) : afecto;
            var campoDesc = document.getElementById('descuento-global');
            var pct = ES_BOLETA || !campoDesc ? null : numero(campoDesc.value);
            if (pct !== null && pct > 0) {
                desc = Math.round(neto * pct / 100);
                neto -= desc;
            }
            iva = brutos && desc === 0 ? afecto - neto : Math.round(neto * TASA_IVA / 100);
        }
        return { neto: neto, desc: desc, iva: iva, exento: exento, total: neto + iva + exento };
    }
    function pesos(n) { return '$' + n.toLocaleString('es-CL'); }
    function pintarTotales() {
        var t = calcularTotales();
        var dl = document.getElementById('resumen-totales');
        ['neto', 'desc', 'iva', 'exento', 'total'].forEach(function (k) {
            // El subtotal se muestra ANTES del descuento, que va restando abajo.
            var valor = k === 'neto' ? t.neto + t.desc : t[k];
            dl.querySelector('[data-total="' + k + '"]').textContent = (k === 'desc' && t.desc ? '-' : '') + pesos(valor);
        });
        Array.prototype.forEach.call(dl.querySelectorAll('[data-fila="desc"]'), function (e) { e.hidden = t.desc === 0; });
        Array.prototype.forEach.call(dl.querySelectorAll('[data-fila="exento"]'), function (e) { e.hidden = t.exento === 0; });
        return t;
    }
    // El dialogo de confirmacion (otro bloque de esta vista) muestra el total.
    window.emisionTotales = pintarTotales;

    // Debe producir el MISMO DOM que la fila que renderiza el PHP de arriba
    // (mismas celdas, clases de columna, clases de control, aria-label y
    // nombres con el indice). Si cambia una, cambia la otra.
    function celdaNeto(n) {
        return '<td class="col-precio" data-label="Precio sin IVA ($)"><input type="hidden" name="detalles[' + n + '][origen]" value="" class="det-origen"><input type="text" inputmode="decimal" name="detalles[' + n + '][precioUnitario]" class="det-precio" id="det-' + n + '-precioUnitario" aria-label="Precio unitario sin IVA, en pesos"></td>';
    }
    function celdaFinal(n) {
        return '<td class="col-precio" data-label="Precio final, con IVA ($)"><input type="text" inputmode="decimal" name="detalles[' + n + '][precioFinal]" class="det-precio-final" id="det-' + n + '-precioFinal" aria-label="Precio final unitario que paga el cliente, con IVA, en pesos"></td>';
    }
    function nuevaFilaHTML(n) {
        // Sin .campo-error en las celdas: una linea recien agregada no puede
        // traer el error de un 422 anterior (el PHP solo lo pinta en la que fallo).
        return '<td class="col-producto" data-label="Producto"><input type="text" list="productos-list" name="detalles[' + n + '][nombre]" class="det-nombre" id="det-' + n + '-nombre" data-max-sii="80" data-contador="discreto" aria-label="Producto o servicio"></td>' +
            '<td class="col-cantidad" data-label="Cantidad"><input type="text" inputmode="decimal" name="detalles[' + n + '][cantidad]" id="det-' + n + '-cantidad" aria-label="Cantidad"></td>' +
            (ES_BOLETA ? celdaFinal(n) + celdaNeto(n) : celdaNeto(n) + celdaFinal(n)) +
            '<td class="col-unidad" data-label="Unidad"><input type="text" name="detalles[' + n + '][unidad]" class="det-unidad" id="det-' + n + '-unidad" data-max-sii="4" data-contador="discreto" aria-label="Unidad"></td>' +
            '<td class="col-exento" data-label="Exento"><input type="checkbox" name="detalles[' + n + '][exento]" value="1" class="det-exento" aria-label="Exento de IVA"' + (exentoForzado ? ' checked disabled' : '') + '></td>' +
            '<td class="col-accion" data-label=""><button type="button" class="quitar-linea" title="Quitar linea" aria-label="Quitar linea">&times;</button></td>';
    }

    document.getElementById('agregar-linea').addEventListener('click', function () {
        var tr = document.createElement('tr');
        tr.innerHTML = nuevaFilaHTML(idx++);
        tbody.appendChild(tr);
    });

    tbody.addEventListener('click', function (e) {
        if (e.target.classList.contains('quitar-linea')) {
            if (tbody.querySelectorAll('tr').length > 1) { e.target.closest('tr').remove(); pintarTotales(); }
        }
    });
    tbody.addEventListener('change', function (e) {
        if (e.target.classList.contains('det-nombre')) { rellenarDesdeProducto(e.target.closest('tr')); }
        if (e.target.classList.contains('det-exento')) { sincronizarFila(e.target.closest('tr')); }
        pintarTotales();
    });
    tbody.addEventListener('input', function (e) {
        var fila = e.target.closest('tr');
        if (e.target.classList.contains('det-precio')) { sincronizarFila(fila, 'neto'); }
        if (e.target.classList.contains('det-precio-final')) { sincronizarFila(fila, 'bruto'); }
        pintarTotales();
    });
    var campoDescuento = document.getElementById('descuento-global');
    if (campoDescuento) { campoDescuento.addEventListener('input', pintarTotales); }
    // Al cargar (y al volver tras un 422): completar el precio que falte.
    Array.prototype.forEach.call(tbody.querySelectorAll('tr'), function (fila) { sincronizarFila(fila); });
    pintarTotales();

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
            Array.prototype.forEach.call(tbody.querySelectorAll('tr'), function (fila) { sincronizarFila(fila); });
            pintarTotales();
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

    // Autocompletado del receptor por RUT. Antes de preguntar al servidor, el
    // RUT se ordena (12.345.678-9) y se valida el digito aqui mismo: un digito
    // mal tecleado se avisa bajo el campo sin ida y vuelta. El servidor lo
    // vuelve a normalizar (Rut::normalizar) y a validar antes del motor.
    var rut = document.getElementById('receptor-rut');
    var aviso = document.getElementById('rut-aviso');
    var rutYaEnClientes = '';
    rut.addEventListener('blur', function () {
        var v = rut.value.trim();
        if (v === '') { aviso.textContent = ''; window.emisionUx && window.emisionUx.pintar('receptor-rut', ''); return; }
        var ord = window.emisionUx ? window.emisionUx.ordenarRut(v) : null;
        if (ord !== null) {
            rut.value = ord.texto;
            window.emisionUx.pintar('receptor-rut', ord.valido ? '' : window.emisionUx.MSG_RUT);
            if (!ord.valido) { aviso.textContent = ''; return; }
        }
        fetch('/ventas/cliente-por-rut?rut=' + encodeURIComponent(rut.value.trim()))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.estado === 'rut_invalido') {
                    aviso.textContent = '';
                    if (window.emisionUx) { window.emisionUx.pintar('receptor-rut', window.emisionUx.MSG_RUT); }
                    return;
                }
                if (data.estado === 'no_encontrado') {
                    aviso.textContent = 'Cliente nuevo: completa sus datos y se guarda solo en tus clientes al salir del correo.';
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
                rutYaEnClientes = rut.value.trim();
                window.emisionUx && window.emisionUx.revisarReceptor(true);
                aviso.textContent = c.activo === false
                    ? 'Cliente encontrado, pero esta inactivo en tus clientes. Datos completados.'
                    : 'Cliente encontrado: datos completados.';
            })
            .catch(function () { aviso.textContent = ''; });
    });

    // GUARDADO AUTOMATICO SIN EMITIR: al salir del correo, que es el ultimo
    // campo del receptor, si todo lo obligatorio esta correcto se da de alta
    // al receptor en el maestro (/ventas/guardar-cliente). No toca el folio ni
    // el formulario. No se repite para un RUT que ya esta en el maestro (lo
    // trajo el autocompletado, o ya se guardo en esta pantalla). Corre de fondo
    // y sin boton: solo se anuncia el resultado en #guardar-cliente-aviso.
    var avisoCliente = document.getElementById('guardar-cliente-aviso');
    var correoReceptor = document.getElementById('receptor-email');
    var guardandoCliente = false;
    if (correoReceptor && avisoCliente) {
        correoReceptor.addEventListener('blur', function () {
            var ux = window.emisionUx;
            if (guardandoCliente || !ux || !ux.receptorListo()) { return; }
            if (rut.value.trim() === rutYaEnClientes) { return; }
            guardarCliente();
        });
    }
    function guardarCliente() {
        var form = document.getElementById('form-emision');
        var datos = new URLSearchParams();
        datos.append('csrf_token', form.querySelector('[name="csrf_token"]').value);
        ['rut', 'razonSocial', 'giro', 'direccion', 'comuna', 'email'].forEach(function (c) {
            var el = document.getElementById('receptor-' + c);
            datos.append('receptor[' + c + ']', el ? el.value.trim() : '');
        });
        guardandoCliente = true;
        avisoCliente.setAttribute('aria-busy', 'true');
        fetch('/ventas/guardar-cliente', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: datos.toString(),
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                avisoCliente.textContent = (data && data.mensaje) || 'No se pudo guardar el cliente.';
                if (data && (data.estado === 'ok' || data.estado === 'existe')) {
                    rutYaEnClientes = datos.get('receptor[rut]');
                    var marca = document.getElementById('guardar-cliente');
                    if (marca) { marca.checked = false; }
                }
            })
            .catch(function () {
                avisoCliente.textContent = 'No se pudo guardar el cliente. Puedes marcar "Guardar en mis clientes" para que se guarde al emitir.';
            })
            .then(function () {
                guardandoCliente = false;
                avisoCliente.removeAttribute('aria-busy');
            });
    }
})();
</script>

<script>
/* VALIDAR, CONFIRMAR Y ESPERAR AL EMITIR.

   - Con JS el formulario pasa a noValidate: los mismos required se revisan
     aqui y el error sale bajo su control (icono + texto + aria-invalid), con
     resumen arriba y foco en el primero. Sin JS manda el required nativo.
   - Emitir pide confirmacion en el <dialog>: asigna un folio real.
   - Espera (seccion 14): el doble envio se corta en el acto (idem_key lo cubre
     igual en el servidor); a los 300 ms spinner en el boton, mismo ancho,
     aria-busy; a los 10 s, aviso de que se esta enviando al SII con barra
     indeterminada. "Atras" (bfcache) lo restaura todo. */
(function () {
    var form = document.getElementById('form-emision');
    var boton = document.getElementById('boton-emitir');
    var dlg = document.getElementById('confirmar-emision');
    var espera = document.getElementById('espera-emision');
    var resumen = document.getElementById('resumen-errores');
    if (!form || !boton) { return; }
    var ES_BOLETA = <?= $esBoleta ? 'true' : 'false'; ?>;
    var iconoError = <?= json_encode(iconoSvg('alerta', 16, '')); ?>;
    var MSG_RUT = 'El digito verificador no calza con el RUT. Revisalo, por ejemplo 77.724.622-4.';

    form.noValidate = true;

    function ordenarRut(v) {
        var limpio = v.replace(/[^0-9kK]/g, '').toUpperCase();
        if (limpio.length < 2) { return { texto: v, valido: false }; }
        var cuerpo = limpio.slice(0, -1), dv = limpio.slice(-1);
        var suma = 0, mul = 2;
        for (var i = cuerpo.length - 1; i >= 0; i--) { suma += parseInt(cuerpo[i], 10) * mul; mul = mul === 7 ? 2 : mul + 1; }
        var r = 11 - (suma % 11);
        var esperado = r === 11 ? '0' : (r === 10 ? 'K' : String(r));
        var conPuntos = cuerpo.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        return { texto: conPuntos + '-' + dv, valido: /^\d+$/.test(cuerpo) && dv === esperado };
    }

    function errorDe(control) {
        var p = document.getElementById('error-' + control.id);
        if (!p) {
            p = document.createElement('p');
            p.className = 'campo-error';
            p.id = 'error-' + control.id;
            p.hidden = true;
            p.innerHTML = iconoError + '<span></span>';
            control.parentNode.insertBefore(p, control.nextSibling);
            var previo = control.getAttribute('aria-describedby');
            control.setAttribute('aria-describedby', (previo ? previo + ' ' : '') + p.id);
        }
        return p;
    }
    function pintar(id, msg) {
        var control = document.getElementById(id);
        if (!control) { return; }
        var p = errorDe(control);
        p.querySelector('span').textContent = msg;
        p.hidden = msg === '';
        var campo = control.closest('.form-campo');
        if (campo) { campo.classList.toggle('form-campo--error', msg !== ''); }
        if (campo && msg !== '') { campo.classList.remove('form-campo--ok'); }
        if (msg === '') { control.removeAttribute('aria-invalid'); } else { control.setAttribute('aria-invalid', 'true'); }
    }
    /* RECEPTOR: validar al salir del campo y, desde ahi, mientras se corrige
       (design system, Formularios > Validacion). Un dato correcto deja el
       campo en verde con un check (.form-campo--ok): color mas icono, nunca
       solo color. Un opcional vacio queda neutro. Obligatorios segun el motor,
       los mismos que llevan el asterisco: en boleta no hay ninguno. */
    var CAMPOS_RECEPTOR = ['receptor-rut', 'receptor-razonSocial', 'receptor-giro', 'receptor-direccion', 'receptor-comuna', 'receptor-email'];
    var FALTA_RECEPTOR = ES_BOLETA ? {} : {
        'receptor-rut': 'Escribe el RUT del receptor, por ejemplo 77.724.622-4.',
        'receptor-razonSocial': 'Escribe la razon social del receptor.',
        'receptor-giro': 'Escribe el giro del receptor: el SII lo exige.',
        'receptor-direccion': 'Escribe la direccion del receptor.',
        'receptor-comuna': 'Escribe la comuna del receptor.'
    };
    function msgCorreo(v) {
        if (/\s/.test(v)) { return 'El correo no puede tener espacios.'; }
        var at = v.indexOf('@');
        if (at < 0) { return 'Falta la @, por ejemplo contacto@empresa.cl.'; }
        if (at === 0) { return 'Falta la parte antes de la @, por ejemplo contacto@empresa.cl.'; }
        if (v.indexOf('@', at + 1) >= 0) { return 'El correo tiene mas de una @.'; }
        var dominio = v.slice(at + 1);
        if (dominio === '') { return 'Falta el dominio despues de la @, por ejemplo empresa.cl.'; }
        if (!/^[^.]+(\.[^.]+)*\.[A-Za-z]{2,}$/.test(dominio)) { return 'Falta la parte despues del punto, por ejemplo .cl o .com.'; }
        return '';
    }
    // '' = correcto, texto = error, null = opcional vacio (neutro).
    function validarCampoReceptor(id) {
        var c = document.getElementById(id);
        if (!c) { return null; }
        var v = c.value.trim();
        if (v === '') { return FALTA_RECEPTOR[id] || null; }
        if (id === 'receptor-email') { return msgCorreo(v); }
        if (id === 'receptor-rut') { return ordenarRut(v).valido ? '' : MSG_RUT; }
        return '';
    }
    function revisarCampoReceptor(id) {
        var c = document.getElementById(id);
        if (!c) { return null; }
        var r = validarCampoReceptor(id);
        pintar(id, r || '');
        var campo = c.closest('.form-campo');
        if (campo) { campo.classList.toggle('form-campo--ok', r === ''); }
        return r;
    }
    // soloLlenos: tras el autocompletado se pintan los campos que trajeron
    // dato, sin marcar en rojo los vacios que nadie ha tocado todavia.
    function revisarReceptor(soloLlenos) {
        CAMPOS_RECEPTOR.forEach(function (id) {
            var c = document.getElementById(id);
            if (!c || (soloLlenos && c.value.trim() === '')) { return; }
            c.setAttribute('data-tocado', '1');
            revisarCampoReceptor(id);
        });
    }
    // Listo para guardarse como cliente: obligatorios correctos y correo
    // correcto o vacio. En boleta, que no exige nada, al menos RUT y razon
    // social, que es lo minimo que pide el maestro de clientes.
    function receptorListo() {
        var ok = true;
        CAMPOS_RECEPTOR.forEach(function (id) {
            var r = validarCampoReceptor(id);
            if (r !== null && r !== '') { ok = false; }
        });
        if (ES_BOLETA && (validarCampoReceptor('receptor-rut') !== '' || validarCampoReceptor('receptor-razonSocial') !== '')) { ok = false; }
        return ok;
    }
    CAMPOS_RECEPTOR.forEach(function (id) {
        var c = document.getElementById(id);
        if (!c) { return; }
        c.addEventListener('blur', function () { c.setAttribute('data-tocado', '1'); revisarCampoReceptor(id); });
        c.addEventListener('input', function () { if (c.hasAttribute('data-tocado')) { revisarCampoReceptor(id); } });
    });

    window.emisionUx = { ordenarRut: ordenarRut, pintar: pintar, MSG_RUT: MSG_RUT, revisarReceptor: revisarReceptor, receptorListo: receptorListo };

    function visible(el) { return !el.disabled && !el.closest('[hidden]'); }
    function revisar() {
        var malos = [];
        Array.prototype.forEach.call(form.querySelectorAll('[required], [data-requerido]'), function (c) {
            if (!c.id || !visible(c)) { return; }
            if (!c.required) { return; }
            var msg = c.value.trim() === '' ? (c.getAttribute('data-requerido') || 'Completa este campo.') : '';
            pintar(c.id, msg);
            if (msg) { malos.push([c.id, msg]); }
        });
        CAMPOS_RECEPTOR.forEach(function (id) {
            var r = revisarCampoReceptor(id);
            if (r) { malos.push([id, r]); }
        });
        // En el orden de la pantalla.
        malos.sort(function (a, b) {
            return document.getElementById(a[0]).compareDocumentPosition(document.getElementById(b[0])) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1;
        });
        return malos;
    }
    var rut = document.getElementById('receptor-rut');

    // Revalidar mientras se corrige, solo lo que ya esta marcado.
    form.addEventListener('input', function (e) {
        var c = e.target;
        if (c.id && c.getAttribute('aria-invalid') === 'true' && c.required) {
            pintar(c.id, c.value.trim() === '' ? (c.getAttribute('data-requerido') || 'Completa este campo.') : '');
        }
    });
    form.addEventListener('change', function (e) {
        var c = e.target;
        if (c.id && c.getAttribute('aria-invalid') === 'true' && c.required) {
            pintar(c.id, c.value.trim() === '' ? (c.getAttribute('data-requerido') || 'Completa este campo.') : '');
        }
    });

    function etiqueta(id) {
        var l = document.querySelector('label[for="' + id + '"]');
        if (l) { return l.textContent.replace(/\*|\(obligatorio\)|\(opcional\)/g, '').trim(); }
        var c = document.getElementById(id);
        return c ? (c.getAttribute('aria-label') || id) : id;
    }

    var enviando = false, confirmado = false, umbral = null, largo = null;
    function ocupado(si) {
        if (si) {
            boton.setAttribute('data-cargando', 'true');
            boton.setAttribute('aria-busy', 'true');
            form.setAttribute('aria-busy', 'true');
            boton.disabled = true;
        } else {
            clearTimeout(umbral); clearTimeout(largo);
            boton.removeAttribute('data-cargando');
            boton.removeAttribute('aria-busy');
            form.removeAttribute('aria-busy');
            boton.disabled = false;
            espera.hidden = true;
            espera.querySelector('p').textContent = '';
        }
    }

    form.addEventListener('submit', function (ev) {
        if (enviando) { ev.preventDefault(); return; }

        var malos = revisar();
        if (malos.length > 0) {
            ev.preventDefault();
            var lista = resumen.querySelector('[data-resumen-lista]');
            lista.textContent = '';
            malos.forEach(function (m) {
                var li = document.createElement('li');
                var a = document.createElement('a');
                a.href = '#' + m[0];
                a.textContent = etiqueta(m[0]);
                li.appendChild(a);
                li.appendChild(document.createTextNode(': ' + m[1]));
                lista.appendChild(li);
            });
            resumen.querySelector('[data-resumen-titulo]').textContent = malos.length === 1
                ? 'Hay 1 campo por revisar antes de emitir'
                : 'Hay ' + malos.length + ' campos por revisar antes de emitir';
            resumen.hidden = false;
            document.getElementById(malos[0][0]).focus();
            return;
        }
        resumen.hidden = true;

        if (!confirmado && dlg && typeof dlg.showModal === 'function') {
            ev.preventDefault();
            var r = rut.value.trim();
            var rs = document.getElementById('receptor-razonSocial').value.trim();
            var lineas = Array.prototype.filter.call(form.querySelectorAll('.det-nombre'), function (i) { return i.value.trim() !== ''; }).length;
            dlg.querySelector('[data-confirmar-resumen]').textContent =
                'Receptor: ' + (rs || (r ? r : 'Consumidor Final')) + (r && rs ? ' (' + r + ')' : '') + '. ' +
                lineas + (lineas === 1 ? ' linea' : ' lineas') + ' de detalle.' +
                (window.emisionTotales ? ' Total a pagar: ' + window.emisionTotales().total.toLocaleString('es-CL', { style: 'currency', currency: 'CLP' }) + '.' : '');
            dlg.returnValue = '';
            dlg.showModal();
            return;
        }
        confirmado = false;

        enviando = true;
        umbral = setTimeout(function () { ocupado(true); }, 300);
        largo = setTimeout(function () {
            espera.hidden = false;
            espera.querySelector('p').textContent = 'Enviando el documento al SII. Puede tardar hasta un minuto: no cierres esta pagina ni vuelvas a emitir.';
        }, 10000);
    });

    if (dlg) {
        dlg.addEventListener('close', function () {
            if (dlg.returnValue === 'si') {
                confirmado = true;
                if (form.requestSubmit) { form.requestSubmit(boton); } else { form.submit(); }
            } else {
                boton.focus();
            }
        });
    }

    window.addEventListener('pageshow', function (ev) {
        if (ev.persisted) { enviando = false; confirmado = false; ocupado(false); }
    });

    // Tras un 422 del motor, el foco va directo al control que fallo.
    var primero = form.querySelector('[aria-invalid="true"]');
    if (primero) { primero.focus(); }
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
