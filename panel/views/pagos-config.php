<?php
/**
 * Configuracion > Cobro en linea.
 *
 * Recibe: $config (array|null con proveedor, habilitado, credencial_publica,
 * url_retorno y tieneSecreto), $errores (array<string,string>) y $proveedores
 * (list<string>).
 *
 * EL SECRETO NO SE PINTA NUNCA, NI SIQUIERA ENMASCARADO. El campo va vacio
 * siempre y al lado se dice si hay uno guardado. Un value con asteriscos seria
 * peor que inutil: quien edite otro campo lo enviaria tal cual y sobrescribiria
 * el secreto bueno con asteriscos. Dejarlo vacio significa "no lo cambies", que
 * es el mismo trato que ya reciben las claves en el resto del panel.
 *
 * EL INTERRUPTOR VA ARRIBA Y SOLO, separado de las credenciales, porque son dos
 * decisiones distintas: "quiero cobrar en linea" y "estas son mis llaves". Se
 * configuran las llaves primero, se revisan, y despues se enciende.
 */
$titulo = 'Cobro en linea';
require __DIR__ . '/partials/header.php';

$val = static function (string $campo) use ($config): string {
    return htmlspecialchars((string) ($config[$campo] ?? ''), ENT_QUOTES, 'UTF-8');
};
$err = static function (string $campo) use ($errores): string {
    return isset($errores[$campo])
        ? '<small class="form-error">' . htmlspecialchars($errores[$campo], ENT_QUOTES, 'UTF-8') . '</small>'
        : '';
};
$habilitado   = ! empty($config['habilitado']);
$tieneSecreto = ! empty($config['tieneSecreto']);
// Sin fila todavia -> sandbox, igual que el default de la columna. Que la
// pantalla y la base digan lo mismo evita que alguien crea que esta en
// produccion porque el formulario venia en blanco.
$ambiente     = ($config['ambiente'] ?? 'sandbox') === 'produccion' ? 'produccion' : 'sandbox';
$enProduccion = $ambiente === 'produccion';
?>

<div class="dash-header">
    <div>
        <h1>Cobro en linea</h1>
        <p class="dash-header__sub">
            El correo con el que mandas una factura puede llevar un boton para pagarla.
            El dinero llega a tu cuenta de la pasarela, no a la nuestra.
        </p>
    </div>
</div>

<?php if ($habilitado): ?>
    <?php
    // EL AMBIENTE ACTIVO, ARRIBA Y CON COLOR. Es el dato que decide si un clic
    // en el correo de un cliente mueve dinero de verdad, y tiene que verse antes
    // que ningun campo del formulario.
    ?>
    <div class="panel-info <?= $enProduccion ? '' : 'panel-info--aviso'; ?>">
        <p class="panel-info__titulo">
            <span class="panel-info__icono" aria-hidden="true">&#9432;</span>
            <?= $enProduccion ? 'Cobrando en PRODUCCION: los pagos son reales' : 'En SANDBOX: los pagos NO son reales'; ?>
        </p>
        <p>
            <?php if ($enProduccion): ?>
                Cada boton de pago que sale en un correo cobra dinero de verdad y llega a tu cuenta de Flow.
            <?php else: ?>
                Los botones de pago apuntan al entorno de pruebas de Flow. Sirven para comprobar que todo
                funciona, pero <strong>ningun cliente puede pagarte de verdad</strong> mientras estes aqui.
            <?php endif; ?>
        </p>
    </div>
<?php endif; ?>

<?php if (! $tieneSecreto): ?>
    <div class="panel-info">
        <p class="panel-info__titulo">
            <span class="panel-info__icono" aria-hidden="true">&#9432;</span>
            Todavia no esta configurado
        </p>
        <p>
            Necesitas una cuenta en Flow y sus dos llaves (API key y Secret key), que salen de
            su panel de comercio. Mientras no las cargues y actives el cobro, tus correos salen
            exactamente como hasta ahora.
        </p>
    </div>
<?php endif; ?>

<form method="post" action="/configuracion/pagos" class="form-panel">
    <?= csrfInput(); ?>

    <section class="form-seccion">
        <h2>Activacion</h2>

        <div class="form-campo">
            <label class="form-check">
                <input type="checkbox" name="habilitado" value="1" <?= $habilitado ? 'checked' : ''; ?>>
                Incluir link de pago en los correos de mis facturas
            </label>
            <small class="form-ayuda">
                Solo en factura y factura exenta. En una nota de credito no aparece nunca:
                esa devuelve dinero. Puedes dejar fuera a clientes concretos desde su ficha
                en Maestros &gt; Clientes.
            </small>
        </div>

        <?php if ($habilitado): ?>
            <div class="panel-info panel-info--aviso">
                <p>
                    <strong>Si la pasarela no responde, el correo espera</strong> en vez de salir sin
                    el boton, y se reintenta solo. Si un atasco pasa de 6 horas lo veras en
                    Ventas &gt; Correos, donde puedes soltar una factura concreta para que salga
                    sin link. Desactivar esta casilla tambien desatasca la cola entera.
                </p>
            </div>
        <?php endif; ?>
    </section>

    <section class="form-seccion">
        <h2>Llaves de Flow</h2>

        <div class="form-campo">
            <label for="proveedor">Pasarela</label>
            <select name="proveedor" id="proveedor">
                <?php foreach ($proveedores as $p): ?>
                    <option value="<?= htmlspecialchars($p, ENT_QUOTES, 'UTF-8'); ?>"
                        <?= ($config['proveedor'] ?? 'flow') === $p ? 'selected' : ''; ?>>
                        <?= htmlspecialchars(ucfirst($p), ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small class="form-ayuda">Por ahora solo Flow. El sistema admite otras sin rehacer esta pantalla.</small>
        </div>

        <div class="form-campo">
            <label for="ambiente">Ambiente</label>
            <select name="ambiente" id="ambiente">
                <option value="sandbox" <?= $enProduccion ? '' : 'selected'; ?>>Sandbox (pruebas, no cobra)</option>
                <option value="produccion" <?= $enProduccion ? 'selected' : ''; ?>>Produccion (cobra de verdad)</option>
            </select>
            <small class="form-ayuda">
                Empieza siempre en <strong>Sandbox</strong> con las llaves de prueba de Flow: los links funcionan
                igual pero no mueven dinero. Cambia a Produccion solo cuando hayas comprobado el circuito completo,
                y acuerdate de poner entonces las llaves reales: las de sandbox no sirven en produccion.
            </small>
        </div>

        <div class="form-campo">
            <label for="credencial_publica">API key</label>
            <input type="text" name="credencial_publica" id="credencial_publica"
                   value="<?= $val('credencial_publica'); ?>" autocomplete="off">
            <?= $err('credencial_publica'); ?>
            <small class="form-ayuda">Identifica tu comercio. No es secreta.</small>
        </div>

        <div class="form-campo">
            <label for="secreto">Secret key</label>
            <input type="password" name="secreto" id="secreto" value="" autocomplete="new-password">
            <?= $err('secreto'); ?>
            <small class="form-ayuda">
                <?php if ($tieneSecreto): ?>
                    Ya hay una guardada y cifrada. <strong>Dejalo en blanco para no cambiarla.</strong>
                <?php else: ?>
                    Se guarda cifrada y no se vuelve a mostrar nunca.
                <?php endif; ?>
            </small>
        </div>

        <div class="form-campo">
            <label for="url_retorno">A donde vuelve el cliente despues de pagar</label>
            <input type="text" name="url_retorno" id="url_retorno" value="<?= $val('url_retorno'); ?>"
                   placeholder="https://tuempresa.cl/gracias">
            <?= $err('url_retorno'); ?>
            <small class="form-ayuda">Opcional. Si lo dejas vacio, Flow muestra su propia pagina.</small>
        </div>
    </section>

    <div class="form-acciones">
        <button type="submit" class="btn btn--primario">Guardar</button>
    </div>
</form>

<?php require __DIR__ . '/partials/footer.php'; ?>
