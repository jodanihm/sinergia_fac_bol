<?php
/**
 * Maestros > Productos y servicios: alta y edicion.
 *
 * Recibe: $modo ('nuevo'|'editar'), $accion, $producto, $errores, $navActivo.
 * En alta $producto llega vacio; en edicion trae la fila del repositorio; tras
 * un POST invalido trae los datos de validarProducto().
 *
 * OBLIGATORIO REAL (validarProducto): solo el nombre. El precio se valida como
 * numero SOLO si viene, y vacio se guarda como NULL. El codigo es opcional, pero
 * si se usa no puede repetirse dentro de la cuenta: UNIQUE(cuenta_id, codigo)
 * admite varios NULL, asi que muchos items sin codigo conviven sin problema.
 *
 * El atributo required del nombre se conserva tal cual estaba.
 *
 * NO SE MUESTRA activo/inactivo: se cambia desde el listado y validarProducto()
 * no lo devuelve.
 */
$titulo = $modo === 'nuevo' ? 'Nuevo producto' : 'Editar producto';
require __DIR__ . '/partials/header.php';

$val = static function (string $campo) use ($producto): string {
    return htmlspecialchars((string) ($producto[$campo] ?? ''));
};
$err = static function (string $campo) use ($errores): ?string {
    return $errores[$campo] ?? null;
};
$claseCampo = static function (string $campo, string $extra = '') use ($errores): string {
    $c = 'form-campo' . ($extra !== '' ? ' ' . $extra : '');
    return isset($errores[$campo]) ? $c . ' form-campo--error' : $c;
};

$req = '<span class="campo-obligatorio" aria-hidden="true">*</span>'
    . '<span class="visualmente-oculto">(obligatorio)</span>';
?>

<div class="dash-header">
    <div>
        <h1><?= $modo === 'nuevo' ? 'Nuevo producto' : 'Editar producto'; ?></h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <a class="boton-secundario" href="/maestros/productos">Volver al listado</a>
    </div>
</div>
<p class="dash-subtitulo">
    Define un item del catalogo para completar el detalle de los documentos.
    Los campos marcados con <span class="campo-obligatorio">*</span> son obligatorios para guardar.
</p>

<?php if ($errores !== []): ?>
    <p class="alerta alerta--error" role="alert">
        <span class="alerta__icono" aria-hidden="true">&#9888;</span>
        <span>Revisa los campos marcados; el detalle esta bajo cada uno.</span>
    </p>
<?php endif; ?>

<form method="post" action="<?= htmlspecialchars($accion); ?>" class="form-compacto">
    <?= csrfInput(); ?>

    <div class="layout-principal-lateral">
        <div>
            <section class="tarjeta" aria-labelledby="titulo-producto">
                <h2 id="titulo-producto">Datos del producto o servicio</h2>
                <div class="form-grid">
                    <div class="<?= $claseCampo('nombre', 'form-campo--ancho'); ?>">
                        <label for="nombre">Nombre <?= $req; ?></label>
                        <input type="text" name="nombre" id="nombre" value="<?= $val('nombre'); ?>" placeholder="Servicio de ejemplo" required>
                        <?php if ($err('nombre')): ?>
                            <p class="error"><?= htmlspecialchars($err('nombre')); ?></p>
                        <?php else: ?>
                            <small class="form-ayuda">Es el texto que aparece en el detalle del documento.</small>
                        <?php endif; ?>
                    </div>

                    <div class="<?= $claseCampo('codigo'); ?>">
                        <label for="codigo">Codigo (SKU)</label>
                        <input type="text" name="codigo" id="codigo" value="<?= $val('codigo'); ?>">
                        <?php if ($err('codigo')): ?>
                            <p class="error"><?= htmlspecialchars($err('codigo')); ?></p>
                        <?php else: ?>
                            <small class="form-ayuda">Opcional. Si lo usas, debe ser unico en tu empresa.</small>
                        <?php endif; ?>
                    </div>

                    <div class="<?= $claseCampo('unidad'); ?>">
                        <label for="unidad">Unidad</label>
                        <input type="text" name="unidad" id="unidad" value="<?= $val('unidad'); ?>" placeholder="UN, KG, HH...">
                        <small class="form-ayuda">Opcional. Unidad de medida.</small>
                    </div>

                    <div class="<?= $claseCampo('descripcion', 'form-campo--ancho'); ?>">
                        <label for="descripcion">Descripcion</label>
                        <input type="text" name="descripcion" id="descripcion" value="<?= $val('descripcion'); ?>">
                        <small class="form-ayuda">Opcional. Solo para identificarlo en tu catalogo.</small>
                    </div>

                    <div class="<?= $claseCampo('precio_unitario', 'form-campo--corto'); ?>">
                        <label for="precio_unitario">Precio unitario</label>
                        <input type="text" inputmode="decimal" name="precio_unitario" id="precio_unitario" value="<?= $val('precio_unitario'); ?>" placeholder="1990 o 1990.50">
                        <?php if ($err('precio_unitario')): ?>
                            <p class="error"><?= htmlspecialchars($err('precio_unitario')); ?></p>
                        <?php else: ?>
                            <small class="form-ayuda">Opcional. Se sugiere al agregar el item.</small>
                        <?php endif; ?>
                    </div>

                    <div class="form-campo form-campo--ancho">
                        <label class="form-check" for="exento">
                            <input type="checkbox" name="exento" id="exento" value="1" <?= ! empty($producto['exento']) ? 'checked' : ''; ?>>
                            Exento de IVA
                        </label>
                        <small class="form-ayuda">Marca esta casilla solo si el item es exento de IVA.</small>
                    </div>
                </div>
            </section>
        </div>

        <div>
            <div class="panel-info">
                <p class="panel-info__titulo">
                    <span class="panel-info__icono" aria-hidden="true">&#9432;</span>
                    Como se usa este item
                </p>
                <ul class="panel-info__lista">
                    <li>Al escribir su nombre en el detalle de un documento, se sugiere y completa su precio y unidad.</li>
                    <li>Si esta marcado como exento, la linea del documento se marca exenta.</li>
                    <li>El precio se guarda tal como lo escribes: no se le calcula ni se le quita IVA.</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="acciones-grupo">
        <button type="submit" class="boton-principal"><?= $modo === 'nuevo' ? 'Crear producto' : 'Guardar cambios'; ?></button>
        <a class="boton-texto" href="/maestros/productos">Cancelar</a>
    </div>
</form>

<?php require __DIR__ . '/partials/footer.php'; ?>
