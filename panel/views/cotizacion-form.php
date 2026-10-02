<?php
/**
 * Alta y edicion de una cotizacion.
 *
 * TAREA DE LA PANTALLA, EN UNA FRASE: armar el presupuesto para un cliente (o
 * un prospecto) con sus lineas, para mandarle el PDF y facturarlo despues.
 *
 * Recibe: $modo ('nueva'|'editar'), $accion, $cotizacion, $lineas, $errores,
 * $productos, $clientes, $navActivo.
 *
 * UNA SOLA VISTA PARA LOS DOS MODOS. La unica diferencia visible es el titulo y
 * el numero, que en alta todavia no existe -- el correlativo se reserva al
 * guardar, dentro de la transaccion, y NUNCA se muestra "el proximo" antes de
 * tenerlo: dos usuarios mirando la pantalla a la vez verian el mismo numero y
 * solo uno lo recibiria.
 *
 * ESTO NO ES UN DTE. No hay Idempotency-Key, no hay tipo de documento, no hay
 * referencias y no se consume folio: guardar dos veces crea dos cotizaciones y
 * eso es un error del usuario, no una emision duplicada ante el SII. Por eso
 * el boton Guardar se bloquea al enviar (seccion 14): es lo que evita el
 * doble clic.
 *
 * CONTRATO CON EL BACKEND Y EL JS -- no tocar sin revisar validarCotizacion():
 *   - csrfInput() dentro del <form>. SIN ESA LINEA LA PANTALLA NO SE PUEDE USAR:
 *     el chequeo CSRF es central y corre antes de despachar cualquier POST.
 *   - class="form-compacto" en el <form>: de ella cuelga el estilo de los
 *     inputs de la tabla de lineas (".form-compacto .tabla-editable input").
 *   - name de cada control, con la sintaxis de arrays lineas[i][campo].
 *   - id/clase que usa el JS: #form-cotizacion, #tabla-lineas, #agregar-linea,
 *     .quitar-linea, .lin-nombre, .lin-precio, .lin-unidad, #productos-list,
 *     #clientes-list, #receptor_rut y #rut-aviso.
 *   - La fila esta duplicada: aqui en PHP y en nuevaFilaHTML() del JS. Las dos
 *     deben producir el mismo DOM (incluidos los data-label, que son los que
 *     convierten cada fila en tarjeta bajo 720px). Mismo criterio que
 *     emision-form.php.
 *
 * LOS INDICES DE LINEA SE CONSERVAN TAL COMO LLEGARON. validarCotizacion()
 * nombra el error con el indice del POST ("lineas[3].cantidad"); si aqui se
 * reindexaran, el error marcaria otra fila cuando el usuario quito una del
 * medio. Por lo mismo el JS numera las filas nuevas desde el mayor indice + 1,
 * no desde la cantidad de filas.
 *
 * ERRORES (design system, Formularios): resumen arriba con un enlace a cada
 * campo, texto bajo el campo con icono, aria-invalid + aria-describedby, y el
 * foco va al primero. Los errores de lineas se listan bajo la tabla, con el
 * numero de linea, porque una celda de 6rem no tiene sitio para una frase.
 *
 * A TODO EL ANCHO, EN COLUMNAS: arriba Cliente y Fechas lado a lado; el
 * detalle a todo el ancho (es una tabla y la necesita); abajo Observaciones y
 * lo que hay que saber antes de guardar, lado a lado.
 */
$titulo = $modo === 'editar' ? 'Editar cotizacion' : 'Nueva cotizacion';
require __DIR__ . '/partials/header.php';

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$v = static function (string $c) use ($cotizacion, $e): string {
    return $e((string) ($cotizacion[$c] ?? ''));
};

/** Clase del contenedor del campo (marca el error). */
$claseCampo = static fn (string $campo, string $extra = ''): string
    => 'form-campo' . ($extra !== '' ? ' ' . $extra : '') . (isset($errores[$campo]) ? ' form-campo--error' : '');
/** Mensaje bajo el campo: icono + texto; vacio y oculto si no hay. */
$campoError = static function (string $campo) use ($errores, $e): string {
    $msg = (string) ($errores[$campo] ?? '');
    return '<p class="campo-error" id="error-' . $e($campo) . '"' . ($msg === '' ? ' hidden' : '') . '>'
        . iconoSvg('alerta', 16, '') . '<span>' . $e($msg) . '</span></p>';
};
/** aria-describedby (ayuda + error) y aria-invalid de un control. */
$aria = static function (string $campo, string $ayudaId = '') use ($errores): string {
    $ids = trim($ayudaId . ' error-' . $campo);
    return ' aria-describedby="' . $ids . '"' . (isset($errores[$campo]) ? ' aria-invalid="true"' : '');
};
/** Atributos de un input de linea: aria-invalid si su celda trae error. */
$ariaLinea = static function (string $clave) use ($errores): string {
    return isset($errores[$clave]) ? ' aria-invalid="true" aria-describedby="error-lineas"' : '';
};

// Lineas a pintar: las que vengan (re-render o edicion) o una vacia. CON SUS
// INDICES ORIGINALES: ver la nota de la cabecera.
$filas = ($lineas !== []) ? $lineas : [0 => []];
$proximoIndice = max(array_map('intval', array_keys($filas))) + 1;

// Mapa nombre -> [precio, unidad, exento] para autocompletar al elegir del
// datalist. Mismo mecanismo que el formulario de emision.
$mapaProductos = [];
foreach ($productos as $p) {
    $mapaProductos[(string) $p['nombre']] = [
        'precio' => $p['precio_unitario'] !== null ? (float) $p['precio_unitario'] : '',
        'unidad' => (string) ($p['unidad'] ?? ''),
        'exento' => ! empty($p['exento']),
    ];
}

// Resumen de errores, en el orden de la pantalla. Los de lineas dicen el
// numero de linea como lo ve el usuario (1, 2, 3...), no el indice del POST.
$posicionFila = [];
$n = 0;
foreach (array_keys($filas) as $k) {
    $posicionFila[(string) $k] = ++$n;
}
$etiquetas = [
    'receptor_rut'          => ['receptor_rut', 'RUT del cliente'],
    'receptor_razon_social' => ['receptor_razon_social', 'Razon social'],
    'fecha'                 => ['fecha', 'Fecha'],
    'valida_hasta'          => ['valida_hasta', 'Valida hasta'],
    'lineas'                => ['tabla-lineas', 'Detalle'],
];
$nombresCampoLinea = ['nombre' => 'producto', 'cantidad' => 'cantidad', 'precio_unitario' => 'precio', 'descuento_pct' => 'descuento'];
$resumen = [];
$erroresLineas = [];
foreach ($errores as $clave => $mensaje) {
    if (preg_match('/^lineas\[(\d+)\]\.([a-z_]+)$/', (string) $clave, $m)) {
        $pos = $posicionFila[$m[1]] ?? ((int) $m[1] + 1);
        $texto = 'Linea ' . $pos . ', ' . ($nombresCampoLinea[$m[2]] ?? $m[2]);
        $resumen[] = ['lin-' . $m[1] . '-' . $m[2], $texto, (string) $mensaje];
        $erroresLineas[] = $texto . ': ' . $mensaje;
        continue;
    }
    [$destino, $etiqueta] = $etiquetas[$clave] ?? [$clave, $clave];
    $resumen[] = [$destino, $etiqueta, (string) $mensaje];
}
if (isset($errores['lineas'])) {
    $erroresLineas[] = (string) $errores['lineas'];
}
?>

<div class="dash-header">
    <div>
        <h1>
            <?= $e($titulo); ?>
            <?php if (! empty($cotizacion['numero'])): ?>
                <span class="badge badge--etiqueta">N&deg; <?= (int) $cotizacion['numero']; ?></span>
            <?php endif; ?>
        </h1>
    </div>
</div>
<p class="dash-subtitulo">Documento interno para tu cliente: no pasa por el SII y no consume folios.</p>

<?php
/* RESUMEN DE ERRORES. Existe siempre en el DOM (oculto si no hay nada): el
   JavaScript reusa este mismo contenedor al validar antes de enviar. */
?>
<div class="alerta alerta--error" id="resumen-errores" role="alert" tabindex="-1"<?= $resumen === [] ? ' hidden' : ''; ?>>
    <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
    <div class="alerta__cuerpo">
        <p class="alerta__titulo" data-resumen-titulo>
            <?= count($resumen) === 1 ? 'Hay 1 dato por revisar antes de guardar' : 'Hay ' . count($resumen) . ' datos por revisar antes de guardar'; ?>
        </p>
        <ul class="alerta__lista" data-resumen-lista>
            <?php foreach ($resumen as [$destino, $etiqueta, $mensaje]): ?>
                <li><a href="#<?= $e($destino); ?>"><?= $e($etiqueta); ?></a>: <?= $e($mensaje); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>

<?php
/* class="form-compacto" NO ES DECORACION. El estilo de los inputs que van
   DENTRO de la tabla de lineas cuelga de esa clase. Sin ella los campos salen
   a tamaño completo dentro de celdas pensadas para el compacto. */
?>
<form id="form-cotizacion" method="post" action="<?= $e((string) $accion); ?>" class="form-compacto" novalidate>
    <?php
    /* EL TOKEN CSRF VA AQUI, PRIMERO Y SIEMPRE. Su ausencia dejo esta pantalla
       IMPOSIBLE DE USAR en produccion: el chequeo es CENTRAL, antes de
       despachar cualquier POST, asi que sin este input el handler no llega a
       ejecutarse nunca y el usuario ve un 403. Hay un arnes que lo comprueba
       sobre TODAS las vistas. */
    ?>
    <?= csrfInput(); ?>

    <div class="config-columnas config-columnas--2">
        <section class="tarjeta" aria-labelledby="titulo-cliente">
            <h2 id="titulo-cliente">Cliente</h2>
            <div class="form-grid">
                <div class="<?= $claseCampo('receptor_rut'); ?>">
                    <label for="receptor_rut">RUT</label>
                    <input type="text" list="clientes-list" name="receptor_rut" id="receptor_rut" value="<?= $v('receptor_rut'); ?>"
                           placeholder="77.724.622-4" inputmode="text" autocomplete="off" spellcheck="false"
                           <?= $aria('receptor_rut', 'rut-aviso'); ?>>
                    <?= $campoError('receptor_rut'); ?>
                    <?php // #rut-aviso es una region viva: el JS escribe aqui si el cliente existe o es nuevo. ?>
                    <small class="form-ayuda" id="rut-aviso" aria-live="polite"<?= isset($errores['receptor_rut']) ? ' hidden' : ''; ?>>Elige uno de tus clientes o escribe un RUT nuevo.</small>
                </div>
                <div class="<?= $claseCampo('receptor_razon_social'); ?>">
                    <label for="receptor_razon_social">Razon social</label>
                    <input type="text" name="receptor_razon_social" id="receptor_razon_social" value="<?= $v('receptor_razon_social'); ?>"
                           <?= $aria('receptor_razon_social'); ?>>
                    <?= $campoError('receptor_razon_social'); ?>
                </div>
                <div class="form-campo">
                    <label for="receptor_giro">Giro (opcional)</label>
                    <input type="text" name="receptor_giro" id="receptor_giro" value="<?= $v('receptor_giro'); ?>" aria-describedby="ayuda-giro">
                    <small class="form-ayuda" id="ayuda-giro">El SII lo exige recien al facturar.</small>
                </div>
                <div class="<?= $claseCampo('receptor_email'); ?>">
                    <label for="receptor_email">Correo (opcional)</label>
                    <input type="email" name="receptor_email" id="receptor_email" value="<?= $v('receptor_email'); ?>"
                           inputmode="email" autocomplete="off" autocapitalize="off" spellcheck="false"
                           placeholder="nombre@empresa.cl" <?= $aria('receptor_email'); ?>>
                    <?= $campoError('receptor_email'); ?>
                </div>
                <div class="form-campo">
                    <label for="receptor_direccion">Direccion (opcional)</label>
                    <input type="text" name="receptor_direccion" id="receptor_direccion" value="<?= $v('receptor_direccion'); ?>">
                </div>
                <div class="form-campo">
                    <label for="receptor_comuna">Comuna (opcional)</label>
                    <input type="text" name="receptor_comuna" id="receptor_comuna" value="<?= $v('receptor_comuna'); ?>">
                </div>
            </div>
            <?php
            /* EL DATALIST PROPONE, NO OBLIGA. Un RUT que no este en el maestro se
               escribe a mano y se guarda igual: cotizacion.cliente_id queda NULL y
               los datos van sueltos en las columnas receptor_*. Ese es el caso
               normal de una cotizacion a un prospecto, no un error.

               POR QUE ADEMAS DEL fetch DE emision-form Y NO EN VEZ DE EL: ese fetch
               autocompleta DESPUES de teclear el RUT completo. El datalist resuelve
               elegir; el fetch resuelve rellenar. Las dos. */
            ?>
            <datalist id="clientes-list">
                <?php foreach ($clientes as $c): ?>
                    <option value="<?= $e((string) $c['rut_cliente']); ?>"><?= $e((string) $c['razon_social']); ?></option>
                <?php endforeach; ?>
            </datalist>
        </section>

        <section class="tarjeta" aria-labelledby="titulo-fechas">
            <h2 id="titulo-fechas">Fechas</h2>
            <div class="form-grid">
                <div class="<?= $claseCampo('fecha'); ?>">
                    <label for="fecha">Fecha</label>
                    <input type="date" name="fecha" id="fecha" value="<?= $v('fecha'); ?>" <?= $aria('fecha'); ?>>
                    <?= $campoError('fecha'); ?>
                </div>
                <div class="<?= $claseCampo('valida_hasta'); ?>">
                    <label for="valida_hasta">Valida hasta (opcional)</label>
                    <input type="date" name="valida_hasta" id="valida_hasta" value="<?= $v('valida_hasta'); ?>"
                           <?= $aria('valida_hasta', 'ayuda-valida_hasta'); ?>>
                    <?= $campoError('valida_hasta'); ?>
                    <small class="form-ayuda" id="ayuda-valida_hasta"<?= isset($errores['valida_hasta']) ? ' hidden' : ''; ?>>
                        Se imprime en el PDF. No bloquea nada si vence.
                    </small>
                </div>
            </div>
        </section>
    </div>

    <section class="tarjeta" aria-labelledby="titulo-lineas">
        <div class="tarjeta__cabecera">
            <h2 id="titulo-lineas">Detalle</h2>
            <button type="button" id="agregar-linea" class="boton-secundario">Agregar linea</button>
        </div>
        <p class="tarjeta__intro">
            Elige un producto de tu catalogo para traer su precio y unidad, o escribe uno nuevo.
            La cantidad admite decimales: media hora de servicio es 0,5.
        </p>
        <div class="tabla-scroll">
            <table id="tabla-lineas" class="tabla-datos tabla-editable tabla-datos--cards tabla-datos--cards-auto" tabindex="-1">
                <thead>
                    <tr>
                        <th scope="col" class="col-producto">Producto / servicio</th>
                        <th scope="col" class="col-cantidad">Cantidad</th>
                        <th scope="col" class="col-precio">Precio unit.</th>
                        <th scope="col" class="col-unidad">Unidad</th>
                        <th scope="col" class="col-descuento">Desc. %</th>
                        <th scope="col" class="col-exento">Exento</th>
                        <th scope="col" class="col-accion"><span class="visualmente-oculto">Accion</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($filas as $i => $l): ?>
                        <?php $i = (int) $i; ?>
                        <tr>
                            <td class="col-producto" data-label="Producto"><input type="text" list="productos-list" name="lineas[<?= $i; ?>][nombre]" id="lin-<?= $i; ?>-nombre" value="<?= $e((string) ($l['nombre'] ?? '')); ?>" class="lin-nombre" aria-label="Producto o servicio"<?= $ariaLinea("lineas[{$i}].nombre"); ?>></td>
                            <td class="col-cantidad" data-label="Cantidad"><input type="text" inputmode="decimal" name="lineas[<?= $i; ?>][cantidad]" id="lin-<?= $i; ?>-cantidad" value="<?= $e((string) ($l['cantidad'] ?? '')); ?>" aria-label="Cantidad"<?= $ariaLinea("lineas[{$i}].cantidad"); ?>></td>
                            <td class="col-precio" data-label="Precio unit."><input type="text" inputmode="decimal" name="lineas[<?= $i; ?>][precio_unitario]" id="lin-<?= $i; ?>-precio_unitario" value="<?= $e((string) ($l['precio_unitario'] ?? '')); ?>" class="lin-precio" aria-label="Precio unitario"<?= $ariaLinea("lineas[{$i}].precio_unitario"); ?>></td>
                            <td class="col-unidad" data-label="Unidad"><input type="text" name="lineas[<?= $i; ?>][unidad]" id="lin-<?= $i; ?>-unidad" value="<?= $e((string) ($l['unidad'] ?? '')); ?>" class="lin-unidad" aria-label="Unidad"></td>
                            <td class="col-descuento" data-label="Desc. %"><input type="text" inputmode="decimal" name="lineas[<?= $i; ?>][descuento_pct]" id="lin-<?= $i; ?>-descuento_pct" value="<?= $e((string) ($l['descuento_pct'] ?? '')); ?>" aria-label="Descuento por linea"<?= $ariaLinea("lineas[{$i}].descuento_pct"); ?>></td>
                            <td class="col-exento" data-label="Exento"><input type="checkbox" name="lineas[<?= $i; ?>][exento]" value="1" aria-label="Exento de IVA" <?= ! empty($l['exento']) ? 'checked' : ''; ?>></td>
                            <td class="col-accion" data-label=""><button type="button" class="quitar-linea" title="Quitar linea" aria-label="Quitar linea">&times;</button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php // Los errores de lineas, juntos y con su numero de linea: una celda no tiene sitio para la frase. ?>
        <div class="campo-error" id="error-lineas"<?= $erroresLineas === [] ? ' hidden' : ''; ?>>
            <?= iconoSvg('alerta', 16, ''); ?>
            <span><?= $e(implode(' ', $erroresLineas)); ?></span>
        </div>
        <datalist id="productos-list">
            <?php foreach ($productos as $p): ?>
                <option value="<?= $e((string) $p['nombre']); ?>"></option>
            <?php endforeach; ?>
        </datalist>
    </section>

    <div class="config-columnas config-columnas--2">
        <section class="tarjeta" aria-labelledby="titulo-notas">
            <h2 id="titulo-notas">Observaciones</h2>
            <div class="form-campo">
                <label for="notas">Texto libre (opcional)</label>
                <textarea name="notas" id="notas" rows="4" aria-describedby="ayuda-notas"><?= $v('notas'); ?></textarea>
                <small class="form-ayuda" id="ayuda-notas">Se imprime al final de la cotizacion: condiciones, plazos, forma de pago.</small>
            </div>
        </section>

        <div class="panel-info">
            <p class="panel-info__titulo">
                <?= iconoSvg('info', 20, 'panel-info__icono'); ?>
                Antes de guardar
            </p>
            <ul class="panel-info__lista">
                <li>El numero de la cotizacion se asigna al guardar.</li>
                <li>Los totales con IVA se ven en el detalle y en el PDF.</li>
                <li>Mientras no se facture, la puedes editar cuantas veces quieras.</li>
            </ul>
        </div>
    </div>

    <div class="acciones-formulario">
        <a class="boton-texto" href="/ventas/cotizaciones">Cancelar</a>
        <button type="submit" class="boton-principal" id="guardar-cotizacion">Guardar cotizacion</button>
    </div>
</form>

<script>
(function () {
    var PRODUCTOS = <?= json_encode($mapaProductos, JSON_UNESCAPED_UNICODE); ?>;
    var form = document.getElementById('form-cotizacion');
    var tbody = document.querySelector('#tabla-lineas tbody');
    // Desde el mayor indice + 1 y no desde la cantidad de filas: los indices
    // del POST se conservan (ver la cabecera) y podrian tener huecos.
    var idx = <?= (int) $proximoIndice; ?>;

    // MISMA FILA QUE LA DE PHP. Si cambia una, cambia la otra.
    function nuevaFilaHTML(i) {
        return '<tr>'
            + '<td class="col-producto" data-label="Producto"><input type="text" list="productos-list" name="lineas[' + i + '][nombre]" id="lin-' + i + '-nombre" class="lin-nombre" aria-label="Producto o servicio"></td>'
            + '<td class="col-cantidad" data-label="Cantidad"><input type="text" inputmode="decimal" name="lineas[' + i + '][cantidad]" id="lin-' + i + '-cantidad" aria-label="Cantidad"></td>'
            + '<td class="col-precio" data-label="Precio unit."><input type="text" inputmode="decimal" name="lineas[' + i + '][precio_unitario]" id="lin-' + i + '-precio_unitario" class="lin-precio" aria-label="Precio unitario"></td>'
            + '<td class="col-unidad" data-label="Unidad"><input type="text" name="lineas[' + i + '][unidad]" id="lin-' + i + '-unidad" class="lin-unidad" aria-label="Unidad"></td>'
            + '<td class="col-descuento" data-label="Desc. %"><input type="text" inputmode="decimal" name="lineas[' + i + '][descuento_pct]" id="lin-' + i + '-descuento_pct" aria-label="Descuento por linea"></td>'
            + '<td class="col-exento" data-label="Exento"><input type="checkbox" name="lineas[' + i + '][exento]" value="1" aria-label="Exento de IVA"></td>'
            + '<td class="col-accion" data-label=""><button type="button" class="quitar-linea" title="Quitar linea" aria-label="Quitar linea">&times;</button></td>'
            + '</tr>';
    }

    document.getElementById('agregar-linea').addEventListener('click', function () {
        tbody.insertAdjacentHTML('beforeend', nuevaFilaHTML(idx));
        // El foco va a la fila nueva: el usuario la pidio para escribir en ella.
        var nuevo = document.getElementById('lin-' + idx + '-nombre');
        idx++;
        if (nuevo) { nuevo.focus(); }
    });

    tbody.addEventListener('click', function (e) {
        if (!e.target.classList.contains('quitar-linea')) { return; }
        // NUNCA se deja la tabla sin filas: el backend rechaza una cotizacion
        // sin lineas y quedarse con la tabla vacia obliga a recargar.
        if (tbody.rows.length > 1) { e.target.closest('tr').remove(); }
    });

    /* --- RUT: formularios.html del design system. Se acepta con o sin
       puntos, con k mayuscula o minuscula; al salir se limpia y se valida el
       digito verificador (modulo 11). No se marca en rojo mientras se
       escribe: recien al salir, y desde ahi se revalida al corregir. */
    var rut = document.getElementById('receptor_rut');
    var aviso = document.getElementById('rut-aviso');
    var AVISO_INICIAL = 'Elige uno de tus clientes o escribe un RUT nuevo.';

    function rutLimpio(v) { return v.replace(/[^0-9kK]/g, '').toUpperCase(); }
    function rutValido(v) {
        var l = rutLimpio(v);
        if (l.length < 2) { return false; }
        var cuerpo = l.slice(0, -1), dv = l.slice(-1), suma = 0, m = 2;
        if (!/^\d+$/.test(cuerpo)) { return false; }
        for (var i = cuerpo.length - 1; i >= 0; i--) {
            suma += parseInt(cuerpo.charAt(i), 10) * m;
            m = m === 7 ? 2 : m + 1;
        }
        var r = 11 - (suma % 11);
        var esperado = r === 11 ? '0' : (r === 10 ? 'K' : String(r));
        return dv === esperado;
    }

    /* Al salir queda como 12.345.678-9: el servidor exige el guion antes del
       DV (Rut::valido) y quita los puntos solo (Rut::normalizar). */
    function formatearRut(v) {
        var l = rutLimpio(v), cuerpo = l.slice(0, -1), dv = l.slice(-1);
        return cuerpo.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + '-' + dv;
    }

    var reglas = {
        receptor_rut: function () {
            var v = rut.value.trim();
            if (v === '') { return 'Escribe el RUT del cliente, por ejemplo 77.724.622-4.'; }
            return rutValido(v) ? '' : 'El digito verificador no calza con el RUT. Revisalo, por ejemplo 77.724.622-4.';
        },
        receptor_razon_social: function () {
            return document.getElementById('receptor_razon_social').value.trim() === ''
                ? 'Escribe la razon social del cliente.' : '';
        },
        receptor_email: function () {
            var v = document.getElementById('receptor_email').value.trim();
            return v !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)
                ? 'Escribe un correo completo, por ejemplo nombre@empresa.cl, o dejalo vacio.' : '';
        },
        fecha: function () {
            return document.getElementById('fecha').value === '' ? 'Elige la fecha de la cotizacion.' : '';
        },
        valida_hasta: function () {
            var desde = document.getElementById('fecha').value;
            var hasta = document.getElementById('valida_hasta').value;
            return hasta !== '' && desde !== '' && hasta < desde
                ? 'La vigencia tiene que ser igual o posterior a la fecha de la cotizacion.' : '';
        }
    };
    var etiquetas = {
        receptor_rut: 'RUT del cliente', receptor_razon_social: 'Razon social',
        receptor_email: 'Correo', fecha: 'Fecha', valida_hasta: 'Valida hasta'
    };
    var tocados = {};

    function pintar(campo, msg) {
        var el = document.getElementById(campo);
        var err = document.getElementById('error-' + campo);
        if (!el || !err) { return; }
        err.querySelector('span').textContent = msg;
        err.hidden = msg === '';
        // Ayuda O error, no los dos. La del RUT es #rut-aviso (region viva).
        var ayuda = document.getElementById(campo === 'receptor_rut' ? 'rut-aviso' : 'ayuda-' + campo);
        if (ayuda) { ayuda.hidden = msg !== ''; }
        el.closest('.form-campo').classList.toggle('form-campo--error', msg !== '');
        if (msg === '') { el.removeAttribute('aria-invalid'); } else { el.setAttribute('aria-invalid', 'true'); }
    }

    Object.keys(reglas).forEach(function (campo) {
        var el = document.getElementById(campo);
        if (!el) { return; }
        el.addEventListener('blur', function () {
            if (campo === 'receptor_email') { el.value = el.value.trim().toLowerCase(); }
            if (campo === 'receptor_rut' && rutValido(el.value)) { el.value = formatearRut(el.value); }
            if (el.value.trim() === '' && !tocados[campo]) { return; }
            tocados[campo] = true;
            pintar(campo, reglas[campo]());
        });
        el.addEventListener('input', function () { if (tocados[campo]) { pintar(campo, reglas[campo]()); } });
    });

    // AUTOCOMPLETADO DEL CLIENTE POR RUT. Reusa el endpoint que ya existe para
    // el formulario de emision (/ventas/cliente-por-rut): no se agrega ninguna
    // via nueva ni se duplica la consulta al maestro. Se dispara en 'change'
    // ademas de en 'blur' porque elegir del datalist con el raton no siempre
    // produce blur. Solo se pregunta si el RUT es valido: con un DV malo el
    // error ya lo dice el campo, y la respuesta seria la misma.
    function buscarCliente() {
        var vRut = rut.value.trim();
        if (vRut === '') { aviso.textContent = AVISO_INICIAL; return; }
        if (!rutValido(vRut)) { aviso.textContent = ''; return; }
        fetch('/ventas/cliente-por-rut?rut=' + encodeURIComponent(vRut))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.estado === 'rut_invalido') { aviso.textContent = ''; return; }
                if (data.estado === 'no_encontrado') {
                    // NO ES UN ERROR: se cotiza a prospectos que todavia no son
                    // clientes. Se guarda igual, con cliente_id en null.
                    aviso.textContent = 'Cliente nuevo: completa sus datos y se guardan con la cotizacion.';
                    return;
                }
                var c = data.cliente || {};
                // Solo se rellena lo que este VACIO: nunca se pisa lo que el
                // usuario ya escribio.
                [['receptor_razon_social', c.razon_social], ['receptor_giro', c.giro],
                 ['receptor_direccion', c.direccion], ['receptor_comuna', c.comuna],
                 ['receptor_email', c.email]].forEach(function (par) {
                    var el = document.getElementById(par[0]);
                    if (el && el.value === '' && par[1]) { el.value = par[1]; }
                });
                if (tocados.receptor_razon_social) { pintar('receptor_razon_social', reglas.receptor_razon_social()); }
                aviso.textContent = c.activo === false
                    ? 'Cliente encontrado, pero esta inactivo en tus clientes.'
                    : 'Cliente encontrado: completamos sus datos.';
            })
            .catch(function () {
                aviso.textContent = 'No pudimos buscar el cliente. Completa sus datos a mano.';
            });
    }
    rut.addEventListener('blur', buscarCliente);
    rut.addEventListener('change', buscarCliente);

    // Al elegir un producto del datalist se rellenan precio, unidad y exento
    // SOLO si estan vacios: nunca se pisa lo que el usuario ya escribio.
    tbody.addEventListener('change', function (e) {
        if (!e.target.classList.contains('lin-nombre')) { return; }
        var p = PRODUCTOS[e.target.value];
        if (!p) { return; }
        var fila = e.target.closest('tr');
        var precio = fila.querySelector('.lin-precio');
        var unidad = fila.querySelector('.lin-unidad');
        var exento = fila.querySelector('input[type=checkbox]');
        if (precio && precio.value === '' && p.precio !== '') { precio.value = p.precio; }
        if (unidad && unidad.value === '') { unidad.value = p.unidad; }
        if (exento && p.exento) { exento.checked = true; }
    });

    /* --- ENVIO: resumen + foco al primero (design system, Formularios) y la
       espera de la seccion 14 (Efectos de carga): el doble envio se corta en
       el acto sin nada visible; a los 300 ms el boton muestra su spinner sin
       cambiar de ancho, con aria-busy; "Atras" lo restaura. */
    var boton = document.getElementById('guardar-cotizacion');
    var resumen = document.getElementById('resumen-errores');
    var umbral = null;
    function ocupado(si) {
        if (si) {
            boton.setAttribute('data-cargando', 'true');
            boton.setAttribute('aria-busy', 'true');
            form.setAttribute('aria-busy', 'true');
            boton.disabled = true;
        } else {
            boton.removeAttribute('data-cargando');
            boton.removeAttribute('aria-busy');
            form.removeAttribute('aria-busy');
            boton.disabled = false;
        }
    }
    window.addEventListener('pageshow', function (ev) {
        if (ev.persisted) { clearTimeout(umbral); delete form.dataset.enviando; ocupado(false); }
    });

    form.addEventListener('submit', function (ev) {
        if (form.dataset.enviando) { ev.preventDefault(); return; }
        if (rutValido(rut.value)) { rut.value = formatearRut(rut.value); }
        var malos = [];
        Object.keys(reglas).forEach(function (campo) {
            if (!document.getElementById(campo)) { return; }
            var msg = reglas[campo]();
            tocados[campo] = true;
            pintar(campo, msg);
            if (msg !== '') { malos.push([campo, msg]); }
        });
        if (malos.length > 0) {
            ev.preventDefault();
            var lista = resumen.querySelector('[data-resumen-lista]');
            lista.textContent = '';
            malos.forEach(function (m) {
                var li = document.createElement('li');
                var a = document.createElement('a');
                a.href = '#' + m[0];
                a.textContent = etiquetas[m[0]];
                li.appendChild(a);
                li.appendChild(document.createTextNode(': ' + m[1]));
                lista.appendChild(li);
            });
            resumen.querySelector('[data-resumen-titulo]').textContent = malos.length === 1
                ? 'Hay 1 dato por revisar antes de guardar'
                : 'Hay ' + malos.length + ' datos por revisar antes de guardar';
            resumen.hidden = false;
            document.getElementById(malos[0][0]).focus();
            return;
        }
        form.dataset.enviando = '1';
        umbral = setTimeout(function () { ocupado(true); }, 300);
    });

    /* Tras un POST rechazado, el foco va directo al primer campo con problema. */
    var primero = form.querySelector('[aria-invalid="true"]');
    if (primero) {
        primero.focus();
    } else if (!resumen.hidden) {
        // Error sin campo propio ("la cotizacion necesita al menos una linea").
        resumen.focus();
    }
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
