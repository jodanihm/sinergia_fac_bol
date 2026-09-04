<?php
/**
 * Configuracion > Cobro en linea.
 *
 * Recibe: $config (array|null con proveedor, ambiente_activo, habilitado y
 * url_retorno), $credenciales (el llavero: una entrada por ambiente, con apiKey
 * y si hay secreto -- NUNCA el secreto), $errores (array<string,string>) y $proveedores
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
// El llavero llega SIEMPRE con las dos entradas, existan o no: la pantalla
// tiene que poder decir "sandbox: configurado / produccion: sin cargar" sin
// adivinar. El secreto no viaja hasta aqui, solo si esta puesto.
$llavero = $credenciales ?? [
    'sandbox'    => ['apiKey' => '', 'tieneSecreto' => false],
    'produccion' => ['apiKey' => '', 'tieneSecreto' => false],
];
// Sin fila todavia -> sandbox, igual que el default de la columna. Que la
// pantalla y la base digan lo mismo evita que alguien crea que esta en
// produccion porque el formulario venia en blanco.
$ambiente     = ($config['ambiente_activo'] ?? 'sandbox') === 'produccion' ? 'produccion' : 'sandbox';
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

<?php if (! $llavero['sandbox']['tieneSecreto'] && ! $llavero['produccion']['tieneSecreto']): ?>
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
            <label for="ambiente_activo">Ambiente activo</label>
            <select name="ambiente_activo" id="ambiente_activo">
                <option value="sandbox" <?= $enProduccion ? '' : 'selected'; ?>>Sandbox (pruebas, no cobra)</option>
                <option value="produccion" <?= $enProduccion ? 'selected' : ''; ?>>Produccion (cobra de verdad)</option>
            </select>
            <?= $err('ambiente_activo'); ?>
            <small class="form-ayuda">
                Decide donde se crean las facturas <strong>nuevas</strong>. Las ya enviadas no cambian: cada link
                recuerda donde nacio, asi que un pago de una prueba se sigue registrando aunque ya estes cobrando
                de verdad. Cambiar de ambiente <strong>no borra</strong> las llaves del otro.
            </small>
        </div>

        <?php
            /* LAS DOS PAREJAS, CADA UNA EN SU BLOQUE. Antes habia un solo par de
               campos y el ambiente decidia cual se usaba, asi que pasar a
               produccion sobrescribia las llaves de sandbox -- y dejar el secreto
               en blanco (lo que la propia pantalla recomienda al editar) juntaba
               la API key de un ambiente con la Secret key del otro. Separadas,
               eso no se puede escribir. */
        ?>
        <?php foreach (['sandbox' => 'Sandbox (pruebas)', 'produccion' => 'Produccion (dinero real)'] as $amb => $rotulo): ?>
            <fieldset class="form-campo" style="border:1px solid #d6dee8;border-radius:6px;padding:0.75rem 1rem;">
                <legend style="padding:0 0.4rem;font-weight:600;">
                    Llaves de <?= htmlspecialchars($rotulo, ENT_QUOTES, 'UTF-8'); ?>
                    <?php if ($llavero[$amb]['tieneSecreto']): ?>
                        <span class="badge badge--ok">configuradas</span>
                    <?php else: ?>
                        <span class="badge badge--neutro">sin cargar</span>
                    <?php endif; ?>
                </legend>

                <div class="form-campo">
                    <label for="apikey_<?= $amb; ?>">API key</label>
                    <input type="text" name="apikey_<?= $amb; ?>" id="apikey_<?= $amb; ?>"
                           value="<?= htmlspecialchars($llavero[$amb]['apiKey'], ENT_QUOTES, 'UTF-8'); ?>"
                           autocomplete="off">
                    <?= $err('apikey_' . $amb); ?>
                    <small class="form-ayuda">Identifica tu comercio en <?= $amb; ?>. No es secreta.</small>
                </div>

                <div class="form-campo">
                    <label for="secreto_<?= $amb; ?>">Secret key</label>
                    <input type="password" name="secreto_<?= $amb; ?>" id="secreto_<?= $amb; ?>"
                           value="" autocomplete="new-password">
                    <?= $err('secreto_' . $amb); ?>
                    <small class="form-ayuda">
                        <?php if ($llavero[$amb]['tieneSecreto']): ?>
                            Ya hay una guardada y cifrada para <?= $amb; ?>.
                            <strong>Dejalo en blanco para no cambiarla.</strong>
                            Solo afecta a <?= $amb; ?>: la del otro ambiente no se toca.
                        <?php else: ?>
                            Se guarda cifrada y no se vuelve a mostrar nunca.
                            Va junto con la API key de <?= $amb; ?>: las dos o ninguna.
                        <?php endif; ?>
                    </small>
                </div>
            </fieldset>
        <?php endforeach; ?>

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
