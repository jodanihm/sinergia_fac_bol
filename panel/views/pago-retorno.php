<?php
/**
 * A donde vuelve el PAGADOR despues de pasar por la pasarela.
 *
 * Recibe: $estadoRetorno (una de las tres constantes de EstadoRetornoPago).
 * Lo pone handleRetornoPagoGet(). Esta vista no consulta nada ni escribe nada.
 *
 *
 * QUIEN LEE ESTA PANTALLA NO ES UN USUARIO DEL PANEL
 * -----------------------------------------------------------------------------
 * Es el cliente de nuestro cliente: acaba de pagar una factura y no tiene cuenta
 * aqui, ni sabe que existimos. Por eso el texto no habla de "documentos",
 * "folios" ni "DTE", no ofrece iniciar sesion, y no muestra NADA de la factura
 * -- ni monto, ni numero, ni el nombre de quien la emitio. Ver el docblock de
 * EstadoRetornoPago: la pagina es publica y el token viaja por el navegador.
 *
 * NO HAY BOTON DE REINTENTAR. Un enlace de vuelta a la pasarela desde aqui es
 * como se cobra dos veces: quien ya pago y ve un boton, lo pulsa. La salida es
 * cerrar la pestana y, si algo va mal, hablar con quien emitio la factura.
 *
 * "VERIFICANDO" ES EL CASO NORMAL, NO EL RARO. El aviso de la pasarela y este
 * regreso del navegador viajan por caminos distintos y a la vez; lo habitual es
 * que el cliente llegue aqui antes que la confirmacion. De ahi que el texto
 * tranquilice en vez de alarmar.
 */

$titulo = match ($estadoRetorno) {
    'confirmado' => 'Pago confirmado',
    'rechazado'  => 'El pago no se completo',
    default      => 'Estamos verificando tu pago',
};

// SIN $bodyClase, igual que activar-cuenta.php: sin sesion no hay menu lateral y
// el max-width global del <body> ya centra la columna. 'auth-page' es del layout
// de dos columnas del login y aqui descuadraria la pagina.
require __DIR__ . '/partials/header.php';
?>

<h1><?= htmlspecialchars($titulo); ?></h1>

<?php if ($estadoRetorno === 'confirmado'): ?>

    <p class="alerta alerta--exito" role="status">
        <span class="alerta__icono" aria-hidden="true">&#10003;</span>
        <span><strong>Recibimos tu pago.</strong> Quien te envio la factura ya
        puede verlo registrado.</span>
    </p>
    <p>No tienes que hacer nada mas. Puedes cerrar esta pagina.</p>

<?php elseif ($estadoRetorno === 'rechazado'): ?>

    <p class="alerta alerta--error" role="status">
        <span class="alerta__icono" aria-hidden="true">&#9888;</span>
        <span><strong>El pago no se completo.</strong> No se te cobro nada.</span>
    </p>
    <p>
        Puede que la operacion se haya cancelado o que el medio de pago la
        rechazara. Para intentarlo de nuevo, vuelve a abrir el link de pago del
        correo con la factura.
    </p>
    <p>Si crees que si se te cobro, escribe a quien te envio la factura antes de pagar otra vez.</p>

<?php else: ?>

    <p class="alerta alerta--advertencia" role="status">
        <span class="alerta__icono" aria-hidden="true">&#8987;</span>
        <span><strong>Estamos verificando tu pago.</strong> La confirmacion puede
        tardar unos momentos en llegar.</span>
    </p>
    <p>
        Esto es normal: la confirmacion viaja por un camino distinto al que te
        trajo hasta aqui, y a veces llega un poco despues. No hace falta que
        esperes en esta pagina ni que pagues de nuevo.
    </p>
    <p>Si pagaste y despues de unos minutos sigues con dudas, escribe a quien te envio la factura.</p>

<?php endif; ?>

<p class="nota">Ya puedes cerrar esta pagina.</p>

<?php require __DIR__ . '/partials/footer.php'; ?>
