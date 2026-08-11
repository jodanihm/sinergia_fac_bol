<?php
/**
 * Alta y edicion de un proveedor. De a uno; no hay carga masiva.
 *
 * Recibe: $modo ('nuevo'|'editar'), $accion, $proveedor, $errores, $navActivo.
 *
 * ONCE CAMPOS: los mismos de cliente-form MENOS la marca de "necesario para
 * facturar" -- que es del SII y aqui no aplica -- MAS contacto y
 * condiciones_pago, que son lo que un comprador necesita y un vendedor no.
 *
 * SOLO RUT Y RAZON SOCIAL SON OBLIGATORIOS. Lo demas mejora el impreso de la
 * orden, no lo condiciona.
 */
$titulo = $modo === 'editar' ? 'Editar proveedor' : 'Nuevo proveedor';
require __DIR__ . '/partials/header.php';

$v = static fn (string $c): string => htmlspecialchars((string) ($proveedor[$c] ?? ''));
$err = static fn (string $c): string => isset($errores[$c]) ? ' style="border-color:#b00020;"' : '';
?>

<div class="dash-header">
    <div><h1><?= htmlspecialchars($titulo); ?></h1></div>
</div>

<?php if ($errores !== []): ?>
    <div class="alerta alerta--error" role="alert">
        <p>Revisa los datos marcados:</p>
        <ul>
            <?php foreach ($errores as $mensaje): ?>
                <li><?= htmlspecialchars($mensaje); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="<?= htmlspecialchars($accion); ?>" class="form-compacto">
    <?php
    /* EL TOKEN VA PRIMERO Y SIEMPRE. Su ausencia dejo el formulario de cotizacion
       IMPOSIBLE DE USAR en produccion: el chequeo es CENTRAL (index.php, antes de
       despachar cualquier POST), asi que sin este input el handler no llega a
       ejecutarse y el usuario ve un 403. */
    ?>
    <?= csrfInput(); ?>

    <section class="tarjeta" aria-labelledby="titulo-identificacion">
        <h2 id="titulo-identificacion">Identificacion</h2>
        <div class="form-grid">
            <div class="form-campo">
                <label for="rut_proveedor">RUT</label>
                <input type="text" name="rut_proveedor" id="rut_proveedor" value="<?= $v('rut_proveedor'); ?>"
                       placeholder="76543210-9"<?= $err('rut_proveedor'); ?>>
                <small class="form-ayuda">Con guion y digito verificador.</small>
            </div>
            <div class="form-campo form-campo--ancho">
                <label for="razon_social">Razon social</label>
                <input type="text" name="razon_social" id="razon_social" value="<?= $v('razon_social'); ?>"<?= $err('razon_social'); ?>>
            </div>
            <div class="form-campo form-campo--ancho">
                <label for="giro">Giro</label>
                <input type="text" name="giro" id="giro" value="<?= $v('giro'); ?>">
            </div>
            <div class="form-campo form-campo--ancho">
                <label for="direccion">Direccion</label>
                <input type="text" name="direccion" id="direccion" value="<?= $v('direccion'); ?>">
            </div>
            <div class="form-campo">
                <label for="comuna">Comuna</label>
                <input type="text" name="comuna" id="comuna" value="<?= $v('comuna'); ?>">
            </div>
        </div>
    </section>

    <section class="tarjeta" aria-labelledby="titulo-contacto">
        <h2 id="titulo-contacto">Contacto y condiciones</h2>
        <div class="form-grid">
            <div class="form-campo">
                <label for="contacto">Persona de contacto</label>
                <input type="text" name="contacto" id="contacto" value="<?= $v('contacto'); ?>">
            </div>
            <div class="form-campo">
                <label for="email">Correo</label>
                <input type="email" name="email" id="email" value="<?= $v('email'); ?>"<?= $err('email'); ?>>
                <small class="form-ayuda">A esta direccion se manda la orden de compra.</small>
            </div>
            <div class="form-campo">
                <label for="telefono">Telefono</label>
                <input type="text" name="telefono" id="telefono" value="<?= $v('telefono'); ?>">
            </div>
            <div class="form-campo">
                <label for="condiciones_pago">Condiciones de pago</label>
                <input type="text" name="condiciones_pago" id="condiciones_pago" value="<?= $v('condiciones_pago'); ?>"
                       placeholder="30 dias">
                <small class="form-ayuda">Se copian a cada orden y ahi se pueden cambiar.</small>
            </div>
        </div>
        <p class="nota">
            El plazo de entrega NO va aqui: es de cada orden, y se pide al emitirla.
        </p>
    </section>

    <div class="acciones-grupo">
        <button type="submit" class="boton-principal">Guardar proveedor</button>
        <a class="boton-texto" href="/compras/proveedores">Volver al listado</a>
    </div>
</form>

<?php require __DIR__ . '/partials/footer.php'; ?>
