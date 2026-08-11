<?php
/**
 * Detalle de una orden de compra, con el boton de enviar y el historial de
 * envios.
 *
 * Recibe: $orden (con 'lineas'), $envios, $flash, $navActivo.
 *
 * SE PUEDE EDITAR AUNQUE YA SE HAYA ENVIADO, y es deliberado: no hay estados de
 * seguimiento, asi que nada "cierra" una orden. Lo que NO cambia es el historial
 * de envios: editar cambia la orden de hoy, no reescribe el correo que el
 * proveedor ya recibio. Por eso las dos cosas se muestran juntas.
 */
$titulo = 'Orden de compra N° ' . (int) $orden['numero'];
require __DIR__ . '/partials/header.php';

$monto = static fn ($n): string => '$ ' . number_format((float) $n, 0, ',', '.');
$cant  = static function ($n): string {
    $f = (float) $n;

    return abs($f - round($f)) < 0.00005
        ? number_format($f, 0, ',', '.')
        : rtrim(rtrim(number_format($f, 4, ',', '.'), '0'), ',');
};
$badgeEnvio = static function (string $e): array {
    return match ($e) {
        'enviado'          => ['badge--exito', 'Aceptado por Brevo'],
        'pendiente'        => ['badge--neutro', 'En cola'],
        'error'            => ['badge--advertencia', 'Con error'],
        'sin_destinatario' => ['badge--advertencia', 'Sin destinatario'],
        default            => ['badge--neutro', $e],
    };
};
?>

<div class="dash-header">
    <div>
        <h1>Orden de compra N&deg; <?= (int) $orden['numero']; ?></h1>
        <p class="dash-header__sub">
            <?= htmlspecialchars((string) $orden['proveedor_razon_social']); ?>
            &middot; <?= htmlspecialchars(date('d-m-Y', (int) strtotime((string) $orden['fecha']))); ?>
        </p>
    </div>
    <div class="acciones-grupo">
        <a class="boton-secundario" href="/compras/ordenes/<?= (int) $orden['id']; ?>/pdf" target="_blank" rel="noopener">Ver PDF</a>
        <a class="boton-secundario" href="/compras/ordenes/<?= (int) $orden['id']; ?>/editar">Editar</a>
    </div>
</div>

<?php if (! empty($flash['mensaje'])): ?>
    <div class="alerta alerta--<?= htmlspecialchars((string) ($flash['tipo'] ?? 'exito')); ?>" role="status">
        <?= htmlspecialchars((string) $flash['mensaje']); ?>
    </div>
<?php endif; ?>

<section class="tarjeta">
    <h2>Proveedor</h2>
    <dl class="ficha">
        <dt>RUT</dt><dd><?= htmlspecialchars((string) $orden['proveedor_rut']); ?></dd>
        <dt>Razon social</dt><dd><?= htmlspecialchars((string) $orden['proveedor_razon_social']); ?></dd>
        <?php foreach ([
            'Giro'        => $orden['proveedor_giro'],
            'Direccion'   => $orden['proveedor_direccion'],
            'Comuna'      => $orden['proveedor_comuna'],
            'Contacto'    => $orden['proveedor_contacto'],
            'Correo'      => $orden['proveedor_email'],
            'Condiciones' => $orden['condiciones_pago'],
        ] as $etiqueta => $valor): ?>
            <?php if (trim((string) $valor) !== ''): ?>
                <dt><?= $etiqueta; ?></dt><dd><?= htmlspecialchars((string) $valor); ?></dd>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if (! empty($orden['fecha_entrega'])): ?>
            <dt>Entrega</dt>
            <dd>
                <?= htmlspecialchars(date('d-m-Y', (int) strtotime((string) $orden['fecha_entrega']))); ?>
                <?= ! empty($orden['lugar_entrega']) ? ' &mdash; ' . htmlspecialchars((string) $orden['lugar_entrega']) : ''; ?>
            </dd>
        <?php endif; ?>
    </dl>
</section>

<section class="tarjeta">
    <h2>Detalle</h2>
    <div class="tabla-scroll">
        <table class="tabla-datos">
            <thead>
                <tr>
                    <th>Item</th>
                    <th class="tabla-datos__num">Cantidad</th>
                    <th>Unidad</th>
                    <th class="tabla-datos__num">P. unitario</th>
                    <th class="tabla-datos__num">Desc.</th>
                    <th>IVA</th>
                    <th class="tabla-datos__num">Neto</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orden['lineas'] as $l): ?>
                    <?php $netoLinea = (float) $l['cantidad'] * (float) $l['precio_unitario']
                        * (1 - ((float) $l['descuento_pct']) / 100); ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars((string) $l['nombre']); ?>
                            <?php if (trim((string) $l['descripcion']) !== ''): ?>
                                <span class="tabla-datos__secundario"><?= htmlspecialchars((string) $l['descripcion']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="tabla-datos__num"><?= $cant($l['cantidad']); ?></td>
                        <td><?= htmlspecialchars((string) ($l['unidad'] ?? '')); ?></td>
                        <td class="tabla-datos__num"><?= $monto($l['precio_unitario']); ?></td>
                        <td class="tabla-datos__num"><?= ((float) $l['descuento_pct']) > 0 ? $cant($l['descuento_pct']) . '%' : '&mdash;'; ?></td>
                        <td><?= ! empty($l['exento']) ? 'Exento' : 'Afecto'; ?></td>
                        <td class="tabla-datos__num"><?= $monto($netoLinea); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php
    /* LOS TOTALES SALEN DE LAS COLUMNAS, no de una suma aqui. Se calcularon una
       vez al guardar y quedaron congelados: recalcularlos al mostrar crearia una
       segunda version de la misma cifra, y si el redondeo difiriera en un peso,
       la pantalla y el papel del proveedor dirian cosas distintas. */
    ?>
    <dl class="ficha ficha--totales">
        <?php if ((int) $orden['neto'] > 0): ?>
            <dt>Neto</dt><dd><?= $monto($orden['neto']); ?></dd>
            <dt>IVA 19%</dt><dd><?= $monto($orden['iva']); ?></dd>
        <?php endif; ?>
        <?php if ((int) $orden['exento'] > 0): ?>
            <dt>Exento</dt><dd><?= $monto($orden['exento']); ?></dd>
        <?php endif; ?>
        <dt><strong>Total</strong></dt><dd><strong><?= $monto($orden['total']); ?></strong></dd>
    </dl>
</section>

<section class="tarjeta">
    <h2>Enviar al proveedor</h2>
    <?php
    /* ENCOLA, NO ENVIA. Brevo puede tardar o fallar, y el usuario quedaria con
       la pantalla colgada por algo que no es su culpa. El boton responde al
       instante y el reintento es del runner. Mismo criterio que el correo del
       DTE. */
    ?>
    <form method="post" action="/compras/ordenes/<?= (int) $orden['id']; ?>/enviar" class="form-compacto">
        <?= csrfInput(); ?>
        <div class="form-campo">
            <label for="destinatario">Correo del proveedor</label>
            <input type="email" name="destinatario" id="destinatario"
                   value="<?= htmlspecialchars((string) ($orden['proveedor_email'] ?? '')); ?>">
            <small class="form-ayuda">
                Viene del proveedor; puedes cambiarlo para este envio sin tocar el maestro.
            </small>
        </div>
        <div class="acciones-grupo">
            <button type="submit" class="boton-principal">Enviar por correo</button>
        </div>
    </form>

    <?php
    /* LA ADVERTENCIA SOBRE BREVO SOLO APLICA SI YA SE INTENTO ENVIAR.
     *
     * Antes se pintaba en cuanto habia UNA fila en la cola, o sea justo despues
     * de apretar el boton -- cuando el correo todavia no salio, porque el runner
     * corre aparte. Decirle ahi que "pudo no llegar" da a entender que ya se
     * intento y fallo, cuando ni siquiera se intento.
     *
     * Ahora depende de que haya al menos un envio en estado 'enviado': ese es el
     * unico momento en que Brevo dijo que lo acepto, y por lo tanto el unico en
     * que tiene sentido aclarar que aceptado no es recibido. */
    $hayEnviados = false;
    foreach ($envios as $e) {
        if ((string) $e['estado'] === 'enviado') {
            $hayEnviados = true;
            break;
        }
    }
    ?>
    <?php if ($envios !== []): ?>
        <h3 class="titulo-sub">Envios</h3>
        <div class="tabla-scroll">
            <table class="tabla-datos">
                <thead>
                    <tr>
                        <th>N&deg;</th>
                        <th>Destinatario</th>
                        <th>Estado</th>
                        <th class="tabla-datos__num">Intentos</th>
                        <th>Cuando</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($envios as $e): ?>
                        <?php [$clase, $texto] = $badgeEnvio((string) $e['estado']); ?>
                        <tr>
                            <td><?= (int) $e['intento_de']; ?></td>
                            <td><?= htmlspecialchars((string) ($e['destinatario'] ?? '')) ?: '&mdash;'; ?></td>
                            <td>
                                <span class="badge <?= $clase; ?>"><?= $texto; ?></span>
                                <?php if (! empty($e['error_mensaje'])): ?>
                                    <span class="tabla-datos__secundario"><?= htmlspecialchars((string) $e['error_mensaje']); ?></span>
                                <?php endif; ?>
                                <?php
                                /* EL MATIZ VA PEGADO A LA FILA QUE LO NECESITA, no
                                   suelto al pie: es de ESTE envio, el que Brevo
                                   acepto, y no de los que siguen en cola. */
                                ?>
                                <?php if ((string) $e['estado'] === 'enviado'): ?>
                                    <span class="tabla-datos__secundario">
                                        Aceptado no es lo mismo que recibido
                                        <?php if (! empty($e['message_id'])): ?>
                                            &middot; id <?= htmlspecialchars((string) $e['message_id']); ?>
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="tabla-datos__num"><?= (int) $e['intentos']; ?></td>
                            <td><?= htmlspecialchars(date('d-m-Y H:i', (int) strtotime((string) $e['updated_at']))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($hayEnviados): ?>
            <p class="nota">
                <strong>Aceptado no es lo mismo que recibido.</strong> Si el correo del proveedor
                esta bloqueado en el servicio de envio, la respuesta es igual de exitosa y el
                mensaje no se entrega. Ante un &laquo;no me llego&raquo;, soporte puede rastrearlo
                con el identificador que queda guardado en cada envio.
            </p>
        <?php else: ?>
            <?php
            /* SOLO HAY FILAS EN COLA. Se dice lo que de verdad esta pasando --
               nada ha salido todavia -- en vez de la advertencia de Brevo, que
               aqui no viene al caso. */
            ?>
            <p class="nota">
                Todavia no ha salido ningun correo: las ordenes se envian en tandas, cada pocos
                minutos. Cuando salga, esta tabla lo va a mostrar.
            </p>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php if (trim((string) $orden['notas']) !== ''): ?>
    <section class="tarjeta">
        <h2>Observaciones</h2>
        <p><?= nl2br(htmlspecialchars((string) $orden['notas'])); ?></p>
    </section>
<?php endif; ?>

<p><a class="boton-texto" href="/compras/ordenes">Volver al listado</a></p>

<?php require __DIR__ . '/partials/footer.php'; ?>
