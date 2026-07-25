<?php
/**
 * Maestros > Clientes: alta y edicion.
 *
 * Recibe: $modo ('nuevo'|'editar'), $accion, $cliente, $errores, $navActivo.
 * En alta $cliente llega vacio; en edicion trae la fila del repositorio; tras un
 * POST invalido trae los datos NORMALIZADOS de validarCliente() (el RUT vuelve
 * ya pasado por Rut::normalizar).
 *
 * OBLIGATORIOS REALES (validarCliente): rut_cliente, con DV valido y sin
 * repetirse dentro de la cuenta, y razon_social. El email solo se valida si
 * viene. El resto es opcional para GUARDAR el cliente.
 *
 * DISTINTO DE "OBLIGATORIO PARA EMITIR": el motor exige rut, razonSocial, giro,
 * direccion y comuna en el receptor (validarDocumentoDte). La vista anterior
 * marcaba tambien email y telefono como "necesario para emitir", y no lo son:
 * el email viaja solo si esta presente y el telefono no viaja nunca. El texto de
 * ayuda de cada campo ahora dice lo que el codigo hace.
 *
 * El atributo required de RUT y razon social se conserva tal cual estaba: es el
 * comportamiento actual del formulario en el navegador y quitarlo lo cambiaria.
 *
 * NO SE MUESTRA activo/inactivo: no es parte de este formulario (se cambia desde
 * el listado) y validarCliente() no lo devuelve, asi que tras un POST invalido
 * el dato ni siquiera esta disponible.
 */
$titulo = $modo === 'nuevo' ? 'Nuevo cliente' : 'Editar cliente';
require __DIR__ . '/partials/header.php';

$val = static function (string $campo) use ($cliente): string {
    return htmlspecialchars((string) ($cliente[$campo] ?? ''));
};
$err = static function (string $campo) use ($errores): ?string {
    return $errores[$campo] ?? null;
};
/** Clase del contenedor cuando ese campo trae error del backend. */
$claseCampo = static function (string $campo, string $extra = '') use ($errores): string {
    $c = 'form-campo' . ($extra !== '' ? ' ' . $extra : '');
    return isset($errores[$campo]) ? $c . ' form-campo--error' : $c;
};

$req = '<span class="campo-obligatorio" aria-hidden="true">*</span>'
    . '<span class="visualmente-oculto">(obligatorio)</span>';
?>

<div class="dash-header">
    <div>
        <h1><?= $modo === 'nuevo' ? 'Nuevo cliente' : 'Editar cliente'; ?></h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <a class="boton-secundario" href="/maestros/clientes">Volver al listado</a>
    </div>
</div>
<p class="dash-subtitulo">
    Completa los datos que se usaran al emitir documentos a este cliente.
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
            <section class="tarjeta" aria-labelledby="titulo-cliente">
                <h2 id="titulo-cliente">Datos del cliente</h2>
                <div class="form-grid">
                    <div class="<?= $claseCampo('rut_cliente'); ?>">
                        <label for="rut_cliente">RUT <?= $req; ?></label>
                        <input type="text" name="rut_cliente" id="rut_cliente" value="<?= $val('rut_cliente'); ?>" placeholder="77724622-4" required>
                        <?php if ($err('rut_cliente')): ?>
                            <p class="error"><?= htmlspecialchars($err('rut_cliente')); ?></p>
                        <?php else: ?>
                            <small class="form-ayuda">Con o sin puntos. Se valida el digito verificador.</small>
                        <?php endif; ?>
                    </div>

                    <div class="<?= $claseCampo('razon_social'); ?>">
                        <label for="razon_social">Razon social <?= $req; ?></label>
                        <input type="text" name="razon_social" id="razon_social" value="<?= $val('razon_social'); ?>" placeholder="Cliente SpA" required>
                        <?php if ($err('razon_social')): ?>
                            <p class="error"><?= htmlspecialchars($err('razon_social')); ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="<?= $claseCampo('giro', 'form-campo--ancho'); ?>">
                        <label for="giro">Giro</label>
                        <input type="text" name="giro" id="giro" value="<?= $val('giro'); ?>">
                        <small class="form-ayuda">Necesario para emitir documentos a este cliente.</small>
                    </div>

                    <div class="<?= $claseCampo('direccion', 'form-campo--ancho'); ?>">
                        <label for="direccion">Direccion</label>
                        <input type="text" name="direccion" id="direccion" value="<?= $val('direccion'); ?>">
                        <small class="form-ayuda">Necesario para emitir documentos a este cliente.</small>
                    </div>

                    <div class="<?= $claseCampo('comuna'); ?>">
                        <label for="comuna">Comuna</label>
                        <input type="text" name="comuna" id="comuna" value="<?= $val('comuna'); ?>">
                        <small class="form-ayuda">Necesario para emitir documentos a este cliente.</small>
                    </div>

                    <div class="<?= $claseCampo('email'); ?>">
                        <label for="email">Email</label>
                        <input type="email" name="email" id="email" value="<?= $val('email'); ?>">
                        <?php if ($err('email')): ?>
                            <p class="error"><?= htmlspecialchars($err('email')); ?></p>
                        <?php else: ?>
                            <small class="form-ayuda">Opcional. Se incluye en el documento si esta presente.</small>
                        <?php endif; ?>
                    </div>

                    <div class="<?= $claseCampo('telefono', 'form-campo--corto'); ?>">
                        <label for="telefono">Telefono</label>
                        <input type="text" name="telefono" id="telefono" value="<?= $val('telefono'); ?>">
                        <small class="form-ayuda">Opcional. Solo para tu registro.</small>
                    </div>
                </div>
            </section>
        </div>

        <div>
            <div class="panel-info">
                <p class="panel-info__titulo">
                    <span class="panel-info__icono" aria-hidden="true">&#9432;</span>
                    Como se usa este cliente
                </p>
                <ul class="panel-info__lista">
                    <li>Al escribir su RUT en un documento, sus datos se completan solos.</li>
                    <li>Para emitirle un documento, el SII exige razon social, giro, direccion y comuna.</li>
                    <li>El RUT no puede repetirse dentro de tu empresa.</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="acciones-grupo">
        <button type="submit" class="boton-principal"><?= $modo === 'nuevo' ? 'Crear cliente' : 'Guardar cambios'; ?></button>
        <a class="boton-texto" href="/maestros/clientes">Cancelar</a>
    </div>
</form>

<?php require __DIR__ . '/partials/footer.php'; ?>
