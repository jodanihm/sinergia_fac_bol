<?php
/**
 * Chat de consultas: una pregunta en palabras, una respuesta con numeros.
 *
 * Recibe: $pregunta, $resultado, $aviso, $usadas, $limite, $navActivo.
 *
 *   $resultado  null, o ['descripcion' => string, 'meta' => array, 'filas' => list]
 *   $aviso      null, o ['tipo' => 'info'|'advertencia'|'error', 'texto' => string]
 *
 * LOS CUATRO DESENLACES SE VEN DISTINTO A PROPOSITO:
 *   respuesta      -> la tabla, con la descripcion de QUE se consulto encima.
 *   imposible      -> aviso 'info' con el motivo. NO es un error: "que producto
 *                     vendi mas" es una pregunta legitima sin datos detras.
 *   no entendida   -> aviso 'info' pidiendo reformular, con un ejemplo.
 *   fallo tecnico  -> aviso 'error'. Falta la clave o el proveedor no respondio.
 *
 * SIN JAVASCRIPT. Es un formulario que hace POST y repinta. Un chat con
 * streaming es otra entrega; esto tiene que funcionar y verse antes.
 */
$titulo = 'Preguntar en palabras';
require __DIR__ . '/partials/header.php';

$monto = static fn ($n): string => '$ ' . number_format((float) $n, 0, ',', '.');
$esMonto = static fn (string $m): bool => in_array($m, ['monto', 'neto', 'exento', 'impuesto', 'promedio'], true);
?>

<div class="dash-header">
    <div>
        <h1>Preguntar en palabras</h1>
        <p class="dash-header__sub">
            Preguntas sobre tu facturacion y te respondo con los mismos numeros del panel.
        </p>
    </div>
</div>

<section class="tarjeta">
    <form method="post" action="/informes/chat" class="form-compacto">
        <?= csrfInput(); ?>
        <div class="form-campo">
            <label for="pregunta">Tu pregunta</label>
            <input type="text" name="pregunta" id="pregunta" value="<?= htmlspecialchars((string) $pregunta); ?>"
                   placeholder="cuanto le vendi a cada cliente en los ultimos 6 meses" autofocus>
            <small class="form-ayuda">
                Por ejemplo: &laquo;cuantas facturas emiti en julio&raquo;, &laquo;mi mejor cliente
                del año&raquo;, &laquo;que mes vendi mas&raquo;.
            </small>
        </div>
        <div class="acciones-grupo">
            <button type="submit" class="boton-principal">Preguntar</button>
        </div>
    </form>
    <p class="nota">
        <?php
        /* EL CONTADOR SE MUESTRA SIEMPRE, no solo al agotarse: enterarse del tope
           cuando ya no queda es lo que hace que un limite se sienta arbitrario. */
        ?>
        Llevas <?= (int) $usadas; ?> de <?= (int) $limite; ?> consultas de hoy.
        Cada pregunta consulta un servicio externo; los informes del menu no tienen limite.
    </p>
</section>

<?php if ($aviso !== null): ?>
    <?php
    $clase = match ($aviso['tipo']) {
        'error'       => 'alerta--error',
        'advertencia' => 'alerta--advertencia',
        default       => 'alerta--info',
    };
    ?>
    <div class="alerta <?= $clase; ?>" role="status">
        <?= htmlspecialchars((string) $aviso['texto']); ?>
    </div>
<?php endif; ?>

<?php if ($resultado !== null): ?>
    <section class="tarjeta">
        <h2>Respuesta</h2>

        <?php
        /* QUE ENTENDI, EN PALABRAS. Va ARRIBA de los numeros y no debajo: es lo
           que le permite al usuario darse cuenta de que la pregunta se
           interpreto mal ANTES de creerse la cifra. Sin esto, un numero que
           contesta otra pregunta pasa por bueno. */
        ?>
        <p class="nota">
            <strong>Consulte:</strong> <?= htmlspecialchars((string) $resultado['descripcion']); ?>.
        </p>

        <?php if ($resultado['filas'] === []): ?>
            <p class="vacio">
                No hay documentos que cumplan eso en ese periodo.
                <?php if (! empty($resultado['meta']['sinDatos'])): ?>
                    (<?= htmlspecialchars((string) $resultado['meta']['sinDatos']); ?>)
                <?php endif; ?>
            </p>
        <?php else: ?>
            <?php $metrica = (string) $resultado['meta']['metrica']; ?>
            <div class="tabla-scroll">
                <table class="tabla-datos">
                    <thead>
                        <tr>
                            <th><?= $resultado['meta']['agruparPor'] === 'ninguna' ? 'Periodo' : 'Grupo'; ?></th>
                            <th class="tabla-datos__num"><?= htmlspecialchars(ucfirst($metrica)); ?></th>
                            <th class="tabla-datos__num">Documentos</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($resultado['filas'] as $f): ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $f['etiqueta']); ?></td>
                                <td class="tabla-datos__num">
                                    <?= $esMonto($metrica) ? $monto($f['valor']) : number_format((float) $f['valor'], 0, ',', '.'); ?>
                                </td>
                                <td class="tabla-datos__num"><?= (int) $f['documentos']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php
        /* DE DONDE SALIO EL NUMERO. Mismo criterio que la formula bajo la cifra
           del dashboard: el total no es una caja negra. Y es lo que explica por
           que este numero y el del dashboard son el mismo. */
        ?>
        <p class="nota">
            <?= htmlspecialchars((string) $resultado['meta']['filtros']); ?>.
            Las notas de credito restan, y los documentos rechazados por el SII no se cuentan
            &mdash; el mismo criterio del panel.
        </p>
    </section>
<?php endif; ?>

<p class="nota">
    Lo que se envia al servicio externo es <strong>solo tu pregunta</strong>: ni tus datos,
    ni tus cifras, ni el RUT de tu empresa. La consulta la hace este sistema.
</p>

<?php require __DIR__ . '/partials/footer.php'; ?>
