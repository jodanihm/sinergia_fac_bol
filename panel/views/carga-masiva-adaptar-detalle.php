<?php
/**
 * Una adaptacion: equivalencias, vista previa y autorizacion -- o, si ya se
 * importo, su registro.
 *
 * TAREA DE LA PANTALLA, EN UNA FRASE: decidir como se lee el archivo del
 * cliente, ver exactamente que va a cambiar y autorizar la importacion.
 *
 * Recibe: $adaptacion (fila de carga_adaptacion, sin los BLOB), $calculo (lo de
 * calcularAdaptacion(), solo en borrador), $errorLectura, $flash, $navActivo.
 *
 * UN ASISTENTE DE DOS PASOS, no tres secciones apiladas (asi fue la primera
 * version, y para un usuario no era claro que hacer ni en que orden):
 *   - Responder lo que falta: solo las preguntas necesarias, en palabras.
 *   - Revisar e importar: una frase con lo que va a pasar, la casilla de
 *     autorizacion y el boton. El formulario lleva la huella de lo que se ve:
 *     si algo cambia, el servidor no importa.
 * Las columnas se nombran con AdaptadorPlantilla::ETIQUETAS, nunca con el
 * nombre tecnico de la plantilla.
 *
 * LO QUE CAMBIO SE MUESTRA JUNTO A LO QUE HABIA. En la muestra, cada celda
 * corregida lleva debajo su valor original: "autorizar correcciones" sin ver
 * cuales seria firmar a ciegas.
 */
$titulo = 'Adaptar archivo de cliente';
require __DIR__ . '/partials/header.php';

$e      = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$fmtNum = static fn ($v): string => number_format((float) $v, 0, ',', '.');
$fmtFechaHora = static function ($v): string {
    $t = strtotime((string) $v);
    return $t === false ? (string) $v : date('d/m/Y H:i', $t);
};

$id     = (int) $adaptacion['id'];
$estado = (string) $adaptacion['estado'];

$tiposCambio = [
    'espacios'               => 'Espacios sobrantes quitados',
    'formato_rut'            => 'RUT escrito sin puntos y con guion',
    'formato_fecha'          => 'Fecha escrita como AAAA-MM-DD',
    'formato_numero'         => 'Numero sin "$" ni punto de miles',
    'formato_texto'          => 'Mayusculas y tildes (CONTADO, CREDITO, SI, NO)',
    'equivalencia_valor'     => 'Valor traducido con tu equivalencia',
    'valor_indicado'         => 'Valor que indicaste para todas las filas',
    'identificador_por_fila' => 'Identificador armado con el numero de fila',
];

$niveles = [
    'exacta'   => ['badge--ok', 'Coincide'],
    'sinonimo' => ['badge--ok', 'Propuesta'],
    'dudosa'   => ['badge--advertencia', 'Revisar'],
    'ambigua'  => ['badge--advertencia', 'Elegir'],
    'sin_uso'  => ['badge--etiqueta', 'Sin equivalente'],
];

$flashClase = ['error' => 'alerta--error', 'ok' => 'alerta--exito', 'exito' => 'alerta--exito', 'info' => 'alerta--info', 'advertencia' => 'alerta--advertencia'];
?>

<div class="dash-header">
    <div>
        <h1>Adaptar archivo de cliente</h1>
    </div>
</div>
<p class="dash-subtitulo">
    <strong><?= $e((string) $adaptacion['nombre_archivo']); ?></strong>
    &middot; subido el <?= $e($fmtFechaHora($adaptacion['created_at'])); ?> por <?= $e((string) $adaptacion['subido_por']); ?>
    &middot; <a href="/ventas/carga-masiva/adaptar/<?= $id; ?>/original">Descargar original</a>
</p>

<div class="config-ancho">

<?php if (! empty($flash)): ?>
    <div class="alerta <?= $flashClase[$flash['tipo']] ?? 'alerta--info'; ?>" role="<?= $flash['tipo'] === 'error' ? 'alert' : 'status'; ?>">
        <?= iconoSvg($flash['tipo'] === 'error' ? 'alerta' : 'info', 20, 'alerta__icono'); ?>
        <div class="alerta__cuerpo"><p><?= $e((string) $flash['mensaje']); ?></p></div>
    </div>
<?php endif; ?>

<?php if ($estado === 'importada'): ?>
    <?php
    /* ===================== REGISTRO DE UNA ADAPTACION IMPORTADA ===================== */
    $registro   = (array) ($adaptacion['cambios'] ?? []);
    $resumen    = (array) ($registro['resumen'] ?? []);
    $elecciones = (array) ($registro['elecciones'] ?? []);
    $cambios    = (array) ($registro['cambios'] ?? []);
    $porTipo    = array_count_values(array_column($cambios, 'tipo'));
    ?>
    <div class="alerta alerta--exito" role="status">
        <?= iconoSvg('check', 20, 'alerta__icono'); ?>
        <div class="alerta__cuerpo">
            <p class="alerta__titulo">Importado</p>
            <p>
                Autorizado por <strong><?= $e((string) $adaptacion['autorizado_por_email']); ?></strong>
                el <?= $e($fmtFechaHora($adaptacion['autorizado_en'])); ?>
                <?= $adaptacion['autorizado_ip'] !== null ? 'desde ' . $e((string) $adaptacion['autorizado_ip']) : ''; ?>.
            </p>
            <p><a href="/ventas/carga-masiva/<?= (int) $adaptacion['lote_carga_id']; ?>">Ver el lote importado</a></p>
        </div>
    </div>

    <section class="dash-grid" aria-label="Resumen de la importacion">
        <article class="kpi">
            <h2 class="kpi__etiqueta">Filas del archivo</h2>
            <p class="kpi__valor"><?= $fmtNum($resumen['filas_datos'] ?? 0); ?></p>
            <p class="kpi__formula"><?= $fmtNum(count((array) ($resumen['filas_vacias'] ?? []))); ?> vacias descartadas</p>
        </article>
        <article class="kpi">
            <h2 class="kpi__etiqueta">Validas</h2>
            <p class="kpi__valor"><?= $fmtNum($resumen['filas_validas'] ?? 0); ?></p>
            <p class="kpi__formula"><?= $fmtNum($resumen['notas_creadas'] ?? 0); ?> nota(s) de venta</p>
        </article>
        <article class="kpi<?= ($resumen['filas_con_error'] ?? 0) > 0 ? ' kpi--rojo' : ''; ?>">
            <h2 class="kpi__etiqueta">Con error</h2>
            <p class="kpi__valor"><?= $fmtNum($resumen['filas_con_error'] ?? 0); ?></p>
            <p class="kpi__formula">Quedaron apartadas en el lote</p>
        </article>
        <article class="kpi">
            <h2 class="kpi__etiqueta">Celdas corregidas</h2>
            <p class="kpi__valor"><?= $fmtNum($resumen['celdas_cambiadas'] ?? 0); ?></p>
            <p class="kpi__formula">Detalle abajo</p>
        </article>
    </section>

    <div class="config-columnas config-columnas--2">
        <section class="tarjeta" aria-labelledby="titulo-archivos">
            <h2 id="titulo-archivos">Archivos</h2>
            <dl class="ficha">
                <dt>Original</dt>
                <dd><a href="/ventas/carga-masiva/adaptar/<?= $id; ?>/original"><?= $e((string) $adaptacion['nombre_archivo']); ?></a> <span class="tabla-datos__secundario">sha256 <?= $e(substr((string) $adaptacion['sha256_original'], 0, 16)); ?>...</span></dd>
                <dt>Corregido (plantilla)</dt>
                <dd><a href="/ventas/carga-masiva/adaptar/<?= $id; ?>/corregido">Descargar el que se importo</a></dd>
                <dt>Hoja y encabezado</dt>
                <dd><?= $e((string) ($elecciones['hoja'] ?? '')); ?>, fila <?= (int) ($elecciones['fila_encabezado'] ?? 1); ?></dd>
                <dt>Huella autorizada</dt>
                <dd><code><?= $e(substr((string) $adaptacion['huella_autorizada'], 0, 16)); ?>...</code></dd>
            </dl>
        </section>

        <section class="tarjeta" aria-labelledby="titulo-elecciones">
            <h2 id="titulo-elecciones">Equivalencias usadas</h2>
            <ul class="panel-info__lista">
                <?php foreach ((array) ($elecciones['mapeo'] ?? []) as $m): ?>
                    <li>"<?= $e((string) $m['columna_archivo']); ?>" &rarr; <?= $e(AdaptadorPlantilla::etiqueta((string) $m['columna_plantilla'])); ?></li>
                <?php endforeach; ?>
                <?php foreach ((array) ($elecciones['valores_indicados'] ?? []) as $col => $valor): ?>
                    <li><?= $e(AdaptadorPlantilla::etiqueta((string) $col)); ?> = "<?= $e((string) $valor); ?>" en todas las filas (indicado a mano)</li>
                <?php endforeach; ?>
                <?php foreach ((array) ($elecciones['equivalencias_de_valor'] ?? []) as $col => $pares): ?>
                    <?php foreach ((array) $pares as $de => $a): ?>
                        <li><?= $e(AdaptadorPlantilla::etiqueta((string) $col)); ?>: "<?= $e((string) $de); ?>" &rarr; <?= $e((string) $a); ?></li>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <?php if (! empty($elecciones['identificador_por_fila'])): ?>
                    <li>Identificador armado con el numero de fila del archivo (autorizado).</li>
                <?php endif; ?>
            </ul>
        </section>
    </div>

    <section class="tarjeta" aria-labelledby="titulo-cambios">
        <h2 id="titulo-cambios">Correcciones aplicadas</h2>
        <?php if ($cambios === []): ?>
            <p class="tarjeta__intro">No hubo que corregir ninguna celda: solo se reordenaron columnas.</p>
        <?php else: ?>
            <ul class="panel-info__lista">
                <?php foreach ($porTipo as $tipo => $n): ?>
                    <li><?= $e($tiposCambio[$tipo] ?? $tipo); ?>: <?= $fmtNum($n); ?></li>
                <?php endforeach; ?>
            </ul>
            <details>
                <summary>Ver cada celda (<?= $fmtNum(count($cambios)); ?>)</summary>
                <div class="tabla-scroll">
                    <table class="tabla-datos tabla-datos--cards">
                        <caption class="visualmente-oculto">Cada celda corregida con su valor original y el importado.</caption>
                        <thead><tr><th scope="col">Fila</th><th scope="col">Columna</th><th scope="col">Antes</th><th scope="col">Despues</th><th scope="col">Por que</th></tr></thead>
                        <tbody>
                            <?php foreach (array_slice($cambios, 0, 2000) as $c): ?>
                                <tr>
                                    <th scope="row" data-label="Fila"><?= (int) $c['fila']; ?></th>
                                    <td data-label="Columna"><?= $e(AdaptadorPlantilla::etiqueta((string) $c['columna'])); ?></td>
                                    <td data-label="Antes"><?= $c['antes'] === '' ? '<span class="tabla-datos__secundario">(vacio)</span>' : $e((string) $c['antes']); ?></td>
                                    <td data-label="Despues"><?= $e((string) $c['despues']); ?></td>
                                    <td data-label="Por que"><?= $e($tiposCambio[$c['tipo']] ?? (string) $c['tipo']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($cambios) > 2000): ?>
                    <p class="nota">Se muestran las primeras 2.000. El registro completo esta guardado.</p>
                <?php endif; ?>
            </details>
        <?php endif; ?>
    </section>

<?php elseif ($estado === 'descartada'): ?>
    <div class="alerta alerta--info" role="status">
        <?= iconoSvg('info', 20, 'alerta__icono'); ?>
        <div class="alerta__cuerpo">
            <p class="alerta__titulo">Adaptacion descartada</p>
            <p>No se importo nada. El archivo original sigue guardado y se puede descargar.</p>
        </div>
    </div>

<?php elseif ($errorLectura !== null): ?>
    <div class="alerta alerta--error" role="alert">
        <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
        <div class="alerta__cuerpo">
            <p class="alerta__titulo">No se pudo volver a leer el archivo</p>
            <p><?= $e($errorLectura); ?></p>
        </div>
    </div>

<?php else: ?>
    <?php
    /* =============================== BORRADOR ===============================
       DOS PASOS, NO TRES SECCIONES APILADAS. El usuario ve UNO a la vez:
         - "Responder lo que falta": solo las preguntas que hacen falta, en
           palabras ("¿Que fecha tienen estas ventas?"). Lo ya reconocido queda
           plegado, para quien quiera revisarlo.
         - "Revisar e importar": una frase con lo que va a pasar, la casilla y
           el boton. El detalle (muestra, correcciones, errores) va plegado.
       El formulario de respuestas es UNO SOLO en los dos pasos (cada columna
       del archivo tiene exactamente un campo, sea pregunta o fila de la tabla):
       en el paso 2 queda plegado bajo "Cambiar como se lee el archivo". */
    $archivo       = $calculo['archivo'];
    $propuesta     = $calculo['propuesta'];
    $mapeo         = $calculo['mapeo'];
    $constantes    = $calculo['constantes'];
    $equivalencias = $calculo['equivalencias'];
    $mapeadas      = array_values($mapeo);
    $listo         = $calculo['pendientes'] === [];
    $et    = static fn (string $c): string => AdaptadorPlantilla::etiqueta($c);
    // Los errores de fila vienen de validarFilaCargaMasiva(), compartida con la
    // carga normal, y nombran las columnas de la plantilla. Aqui se leen con
    // las etiquetas: el usuario de esta pantalla nunca vio esos nombres.
    $porLargo = AdaptadorPlantilla::ETIQUETAS;
    uksort($porLargo, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
    $legible = static fn (string $msg): string => strtr($msg, $porLargo);
    $letra = static fn (int $i): string => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
    $nombreCol = static fn (int $i): string => $archivo['encabezados'][$i] !== '' ? $archivo['encabezados'][$i] : 'Columna ' . $letra($i);

    // Un ejemplo por columna: el primer valor no vacio, para reconocerla.
    $ejemplo = static function (int $i) use ($archivo): string {
        foreach ($archivo['datos'] as $fila) {
            $v = $fila[$i] ?? '';
            $v = is_int($v) || is_float($v) ? AdaptadorPlantilla::numeroCanonico($v) : trim((string) $v);
            if ($v !== '') {
                return mb_strimwidth($v, 0, 40, '...');
            }
        }
        return '';
    };

    // Columnas que se preguntan en palabras: las que admiten mas de una lectura.
    $columnasVacias = $calculo['columnasVacias'];
    $columnasPregunta = array_values(array_diff(
        array_keys(array_filter($propuesta, static fn (array $p): bool => in_array($p['nivel'], ['ambigua', 'dudosa'], true))),
        $columnasVacias
    ));

    // Lo obligatorio que no tiene columna y solo se arregla eligiendo una en la
    // tabla (RUT, producto, cantidad, precio): si hay algo asi, la tabla se abre.
    // No cuenta lo que ya ofrece una pregunta de arriba ("Total" puede ser el
    // precio): mandar a la tabla algo que se responde con un clic confunde.
    $ofrecidas = [];
    foreach ($columnasPregunta as $i) {
        $ofrecidas = array_merge($ofrecidas, $propuesta[$i]['candidatos']);
    }
    $faltaEnTabla = [];
    foreach (['rut_receptor', 'producto_servicio', 'cantidad'] as $c) {
        if (! in_array($c, $mapeadas, true) && ! in_array($c, $ofrecidas, true)) {
            $faltaEnTabla[] = $et($c);
        }
    }
    $precios = ['precio_unitario', 'precio_final'];
    if (array_intersect($precios, $mapeadas) === [] && array_intersect($precios, $ofrecidas) === []) {
        $faltaEnTabla[] = 'el precio';
    }
    $hayRepetidas = count($mapeadas) !== count(array_unique($mapeadas));

    $formaPagoIndicada = $constantes['forma_pago'] ?? '';
    $preguntarVencimiento = ! in_array('fecha_vencimiento', $mapeadas, true) && $formaPagoIndicada === 'CREDITO';

    $reconocidas = count(array_filter($propuesta, static fn (array $p): bool => in_array($p['nivel'], ['exacta', 'sinonimo'], true)));

    $opcionesPlantilla = static function (string $actual) use ($e, $et): string {
        $html = '<option value="">No usar esta columna</option>';
        foreach (NOTA_VENTA_ENCABEZADOS as $col) {
            $html .= '<option value="' . $e($col) . '"' . ($actual === $col ? ' selected' : '') . '>' . $e($et($col)) . '</option>';
        }
        return $html;
    };

    // Numeros del paso 2, calculados una vez.
    if ($listo) {
        $aplicado  = $calculo['aplicado'];
        $revision  = $calculo['revision'];
        $items     = $revision['items'];
        $validas   = count(array_filter($items, static fn (array $it): bool => $it['status'] === 'ok'));
        $conError  = count($items) - $validas;
        $notas     = count($revision['grupos']);
        $rutsNuevos = [];
        $monto     = 0;
        foreach ($items as $it) {
            if ($it['status'] === 'ok') {
                $monto += (int) $it['datos']['monto_estimado'];
                if ($it['datos']['cliente_resolucion']['estado'] === 'no_encontrado') {
                    $rutsNuevos[$it['datos']['cliente_resolucion']['rut']] = true;
                }
            }
        }
        $porTipo = array_count_values(array_column($aplicado['cambios'], 'tipo'));
        $columnasMuestra = array_values(array_filter(
            NOTA_VENTA_ENCABEZADOS,
            static fn (string $c): bool => in_array($c, $mapeadas, true) || ($constantes[$c] ?? '') !== '' || $c === 'identificador_externo'
        ));
        $cambioDe = [];
        foreach ($aplicado['cambios'] as $c) {
            $cambioDe[$c['fila']][$c['columna']] = $c;
        }
        $columnasSinUso = array_filter(
            array_keys($archivo['encabezados']),
            static fn (int $i): bool => ! isset($mapeo[$i]) && $ejemplo($i) !== ''
        );
        $filasError = array_filter($items, static fn (array $it): bool => $it['status'] !== 'ok');

        // LOS MOTIVOS, AGRUPADOS: "16 filas: falta el N° de venta" se entiende;
        // una tabla de 16 filas con el mismo error obliga a deducirlo.
        $motivos = [];
        foreach ($filasError as $it) {
            foreach (array_unique(array_map(static fn ($m): string => $legible((string) $m), $it['errores'])) as $m) {
                $motivos[$m] = ($motivos[$m] ?? 0) + 1;
            }
        }
        arsort($motivos);
    }

    $pasos = [
        1 => ['Subir archivo', 'completado'],
        2 => ['Responder lo que falta', $listo ? 'completado' : 'activo'],
        3 => ['Revisar e importar', $listo ? 'activo' : 'pendiente'],
    ];
    ?>

    <nav class="stepper" aria-label="Pasos para adaptar el archivo">
        <p class="stepper__resumen">
            <strong><?= $listo ? 'Paso 3 de 3: revisa e importa.' : 'Paso 2 de 3: responde lo que falta.'; ?></strong>
        </p>
        <ol class="stepper__lista">
            <?php foreach ($pasos as $n => [$etiqueta, $estadoPaso]): ?>
                <li class="stepper__paso stepper__paso--<?= $estadoPaso; ?>">
                    <span class="stepper__contenido"<?= $estadoPaso === 'activo' ? ' aria-current="step"' : ''; ?>>
                        <span class="stepper__marca" aria-hidden="true"><?= $estadoPaso === 'completado' ? iconoSvg('check', 16, '') : $n; ?></span>
                        <span class="stepper__texto">
                            <span class="stepper__etiqueta"><?= $n; ?>. <?= $e($etiqueta); ?></span>
                            <span class="stepper__estado"><?= ['completado' => 'Listo', 'activo' => 'Estas aqui', 'pendiente' => 'Pendiente'][$estadoPaso]; ?></span>
                        </span>
                    </span>
                </li>
            <?php endforeach; ?>
        </ol>
    </nav>

    <?php ob_start(); /* ======== EL FORMULARIO DE RESPUESTAS (se ubica abajo segun el paso) ======== */ ?>
    <form method="post" action="/ventas/carga-masiva/adaptar/<?= $id; ?>/equivalencias" class="form-compacto" data-espera>
        <?= csrfInput(); ?>

        <?php if (! $listo): ?>
            <div class="alerta alerta--advertencia" role="status">
                <?= iconoSvg('info', 20, 'alerta__icono'); ?>
                <div class="alerta__cuerpo">
                    <p class="alerta__titulo">
                        Reconocimos <?= $reconocidas; ?> de <?= count($propuesta); ?> columnas. Para continuar falta:
                    </p>
                    <ul class="alerta__lista">
                        <?php foreach ($calculo['pendientes'] as $p): ?>
                            <li><?= $e($p); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <?php foreach ($columnasPregunta as $i): ?>
            <?php
                $p = $propuesta[$i];
                $actual = $mapeo[$i] ?? '';
                $opciones = $p['candidatos'];
                if ($actual !== '' && ! in_array($actual, $opciones, true)) {
                    $opciones[] = $actual;
                }
            ?>
            <fieldset class="tarjeta pregunta">
                <legend><h2>¿Que trae la columna "<?= $e($nombreCol($i)); ?>"?</h2></legend>
                <p class="tarjeta__intro">
                    <?php if ($ejemplo($i) !== ''): ?>Por ejemplo: <strong><?= $e($ejemplo($i)); ?></strong>. <?php endif; ?>
                    <?= $p['condicion'] !== null ? $e($p['condicion']) : 'El nombre no alcanza para saberlo con seguridad.'; ?>
                </p>
                <?php foreach ($opciones as $col): ?>
                    <label class="form-check">
                        <input type="radio" name="mapeo[<?= $i; ?>]" value="<?= $e($col); ?>"<?= $actual === $col ? ' checked' : ''; ?>>
                        <?= $e($et($col)); ?>
                    </label>
                <?php endforeach; ?>
                <label class="form-check">
                    <input type="radio" name="mapeo[<?= $i; ?>]" value=""<?= $actual === '' ? ' checked' : ''; ?>>
                    Ninguna: no usar esta columna
                </label>
            </fieldset>
        <?php endforeach; ?>

        <?php if (! in_array('fecha_nota', $mapeadas, true)): ?>
            <section class="tarjeta pregunta">
                <h2><label for="constante-fecha_nota">¿Que fecha tienen estas ventas?</label></h2>
                <p class="tarjeta__intro">El archivo no trae la fecha de cada venta. La que indiques se usa para todas.</p>
                <input type="date" name="constante[fecha_nota]" id="constante-fecha_nota" value="<?= $e((string) ($constantes['fecha_nota'] ?? '')); ?>">
            </section>
        <?php endif; ?>

        <?php if (! in_array('forma_pago', $mapeadas, true)): ?>
            <section class="tarjeta pregunta">
                <h2><label for="constante-forma_pago">¿Como se pagaron estas ventas?</label></h2>
                <p class="tarjeta__intro">El archivo no trae la forma de pago. La que indiques se usa para todas.</p>
                <select name="constante[forma_pago]" id="constante-forma_pago">
                    <option value="">Elige una opcion</option>
                    <?php foreach (['CONTADO' => 'Contado', 'CREDITO' => 'Credito', 'SIN COSTO' => 'Sin costo'] as $v => $texto): ?>
                        <option value="<?= $e($v); ?>"<?= $formaPagoIndicada === $v ? ' selected' : ''; ?>><?= $texto; ?></option>
                    <?php endforeach; ?>
                </select>
            </section>
        <?php endif; ?>

        <?php if ($preguntarVencimiento): ?>
            <section class="tarjeta pregunta">
                <h2><label for="constante-fecha_vencimiento">¿Cuando vencen estas ventas a credito?</label></h2>
                <p class="tarjeta__intro">Una venta a credito necesita fecha de vencimiento. La que indiques se usa para todas.</p>
                <input type="date" name="constante[fecha_vencimiento]" id="constante-fecha_vencimiento" value="<?= $e((string) ($constantes['fecha_vencimiento'] ?? '')); ?>">
            </section>
        <?php endif; ?>

        <?php if (! in_array('identificador_externo', $mapeadas, true)): ?>
            <section class="tarjeta pregunta">
                <h2>El archivo no trae un N° de venta</h2>
                <p class="tarjeta__intro">
                    Ese numero es el que impide cargar dos veces la misma venta. Si el archivo trae un numero de pedido
                    u orden, eligelo en la lista de columnas de abajo. Si no trae ninguno:
                </p>
                <label class="form-check" for="identificador-por-fila">
                    <input type="checkbox" name="identificador_por_fila" id="identificador-por-fila" value="1"<?= (bool) $adaptacion['identificador_por_fila'] ? ' checked' : ''; ?>>
                    Usar el numero de fila del archivo
                </label>
                <small class="form-ayuda">Protege contra subir dos veces este mismo archivo, pero no reconoce la misma venta si llega en otro archivo.</small>
            </section>
        <?php endif; ?>

        <?php foreach ($calculo['porTraducir'] as $col => $valores): ?>
            <section class="tarjeta pregunta">
                <h2><?= $e($et($col)); ?>: ¿a que corresponde cada valor?</h2>
                <p class="tarjeta__intro">El archivo usa palabras que el sistema no conoce. Indica a cual corresponde cada una.</p>
                <div class="config-columnas config-columnas--3">
                    <?php foreach ($valores as $k => $valor): ?>
                        <div class="form-campo">
                            <label for="equiv-<?= $e($col); ?>-<?= $k; ?>">"<?= $e($valor); ?>" es</label>
                            <input type="hidden" name="equiv_valor[<?= $e($col); ?>][<?= $k; ?>]" value="<?= $e($valor); ?>">
                            <select name="equiv_destino[<?= $e($col); ?>][<?= $k; ?>]" id="equiv-<?= $e($col); ?>-<?= $k; ?>">
                                <option value="">Elige una opcion</option>
                                <?php foreach ($col === 'forma_pago' ? ['CONTADO' => 'Contado', 'CREDITO' => 'Credito', 'SIN COSTO' => 'Sin costo'] : ['SI' => 'Si, exento', 'NO' => 'No, afecto'] as $v => $texto): ?>
                                    <option value="<?= $e($v); ?>"<?= ($equivalencias[$col][$valor] ?? '') === $v ? ' selected' : ''; ?>><?= $texto; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>

        <details class="tarjeta"<?= ($faltaEnTabla !== [] || $hayRepetidas) && ! $listo ? ' open' : ''; ?>>
            <summary>
                <strong>Columnas del archivo</strong>
                (<?= count($archivo['encabezados']); ?>, se leen de la hoja "<?= $e($archivo['hoja']); ?>", encabezados en la fila <?= $archivo['indice_encabezado'] + 1; ?>)
            </summary>
            <?php if ($faltaEnTabla !== [] && ! $listo): ?>
                <p class="tarjeta__intro"><strong>Elige aqui que columna trae <?= $e(implode(', ', $faltaEnTabla)); ?>.</strong></p>
            <?php else: ?>
                <p class="tarjeta__intro">Asi se va a leer cada columna. Cambialo solo si algo no corresponde.</p>
            <?php endif; ?>
            <div class="tabla-scroll">
                <table class="tabla-datos tabla-datos--cards">
                    <caption class="visualmente-oculto">Columnas del archivo y como se leen.</caption>
                    <thead>
                        <tr><th scope="col">Columna del archivo</th><th scope="col">Ejemplo</th><th scope="col">Se lee como</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($archivo['encabezados'] as $i => $encabezado): ?>
                            <tr>
                                <th scope="row" data-label="Columna del archivo"><?= $e($nombreCol($i)); ?></th>
                                <td data-label="Ejemplo"><?= $ejemplo($i) !== '' ? $e($ejemplo($i)) : '<span class="tabla-datos__secundario">(vacia)</span>'; ?></td>
                                <td data-label="Se lee como">
                                    <?php if (in_array($i, $columnasVacias, true)): ?>
                                        <span class="tabla-datos__secundario">Vacia en todo el archivo: no se usa</span>
                                    <?php elseif (in_array($i, $columnasPregunta, true)): ?>
                                        <span class="tabla-datos__secundario">Se responde arriba</span>
                                    <?php else: ?>
                                        <label class="visualmente-oculto" for="mapeo-<?= $i; ?>">Como se lee <?= $e($nombreCol($i)); ?></label>
                                        <select name="mapeo[<?= $i; ?>]" id="mapeo-<?= $i; ?>"><?= $opcionesPlantilla($mapeo[$i] ?? ''); ?></select>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>

        <details class="tarjeta">
            <summary><strong>Mas opciones</strong> (facturas exentas, vencimiento)</summary>
            <div class="carga-masiva__tipo">
                <label class="interruptor" for="tipo_exento">
                    <input type="checkbox" role="switch" name="tipo_exento" id="tipo_exento" value="1"<?= (bool) $adaptacion['es_exento'] ? ' checked' : ''; ?>>
                    <span class="interruptor__texto">
                        <span class="interruptor__titulo">Son facturas exentas</span>
                        <span class="interruptor__detalle">Todas las notas salen como factura exenta (34). Sin marcar, factura normal con IVA (33).</span>
                    </span>
                </label>
            </div>
            <?php if (! in_array('fecha_vencimiento', $mapeadas, true) && ! $preguntarVencimiento): ?>
                <div class="form-campo">
                    <label for="constante-fecha_vencimiento">Fecha de vencimiento para todas las ventas (solo si son a credito)</label>
                    <input type="date" name="constante[fecha_vencimiento]" id="constante-fecha_vencimiento" value="<?= $e((string) ($constantes['fecha_vencimiento'] ?? '')); ?>">
                </div>
            <?php endif; ?>
            <?php if (! in_array('exento', $mapeadas, true)): ?>
                <div class="form-campo">
                    <label for="constante-exento">Exento de IVA para todas las lineas</label>
                    <select name="constante[exento]" id="constante-exento">
                        <option value="">Segun el archivo (no indicar)</option>
                        <option value="SI"<?= ($constantes['exento'] ?? '') === 'SI' ? ' selected' : ''; ?>>Si, todas exentas</option>
                        <option value="NO"<?= ($constantes['exento'] ?? '') === 'NO' ? ' selected' : ''; ?>>No, todas afectas</option>
                    </select>
                </div>
            <?php endif; ?>
        </details>

        <div class="acciones-formulario">
            <button class="boton-principal" type="submit"><?= $listo ? 'Guardar cambios' : 'Continuar'; ?></button>
        </div>
    </form>
    <?php $formulario = (string) ob_get_clean(); ?>

    <?php if (! $listo): ?>
        <?= $formulario; ?>
    <?php else: ?>
        <section class="tarjeta" aria-labelledby="titulo-resultado" id="vista-previa" tabindex="-1">
            <h2 id="titulo-resultado">Revisa e importa</h2>

            <?php if ($revision['problemas'] !== []): ?>
                <div class="alerta alerta--error" role="alert">
                    <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
                    <div class="alerta__cuerpo">
                        <p class="alerta__titulo">Este archivo todavia no se puede importar</p>
                        <ul class="alerta__lista">
                            <?php foreach ($revision['problemas'] as $p): ?>
                                <li><?= $e($p); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <p>Corrigelo en el archivo y subelo de nuevo, o cambia como se lee mas abajo.</p>
                    </div>
                </div>
            <?php elseif ($validas === 0): ?>
                <div class="alerta alerta--advertencia" role="status">
                    <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
                    <div class="alerta__cuerpo">
                        <p class="alerta__titulo">Ninguna fila se puede importar todavia</p>
                        <p>Esto es lo que hay que corregir:</p>
                    <?php if ($motivos !== []): ?>
                        <ul class="alerta__lista">
                            <?php foreach (array_slice($motivos, 0, 4, true) as $m => $n): ?>
                                <li><strong><?= $fmtNum($n); ?> fila<?= $n === 1 ? '' : 's'; ?>:</strong> <?= $e($m); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                        <p>
                            Si falta algo que es igual para todas las ventas (fecha, forma de pago, N° de venta), indicalo en
                            <a href="#cambiar">Cambiar como se lee el archivo</a>. Si falta un dato de cada venta o de un cliente nuevo
                            (giro, direccion), corrigelo en el archivo y subelo de nuevo. Si dice que la venta ya se cargo, este archivo ya entro antes.
                        </p>
                    </div>
                </div>
            <?php else: ?>
                <p class="resumen-importacion">
                    <?= $notas === 1 ? 'Se va a crear' : 'Se van a crear'; ?> <strong><?= $fmtNum($notas); ?> nota<?= $notas === 1 ? '' : 's'; ?> de venta</strong>
                    a partir de <?= $fmtNum($validas); ?> fila<?= $validas === 1 ? '' : 's'; ?> del archivo,
                    por un total estimado de <strong>$<?= $fmtNum($monto); ?></strong> con IVA.
                    <?php if ($rutsNuevos !== []): ?>
                        <?= count($rutsNuevos) === 1 ? 'Se agrega 1 cliente nuevo' : 'Se agregan ' . count($rutsNuevos) . ' clientes nuevos'; ?> a tus clientes.
                    <?php endif; ?>
                </p>
                <ul class="panel-info__lista">
                    <?php if ($conError > 0): ?>
                        <li>
                            <strong><?= $fmtNum($conError); ?> fila<?= $conError === 1 ? '' : 's'; ?> con error</strong> quedan apartadas y no se facturan. <a href="#filas-error">Ver cuales</a>.
                            <?php foreach (array_slice($motivos, 0, 3, true) as $m => $n): ?>
                                <span class="tabla-datos__secundario"><?= $fmtNum($n); ?>: <?= $e($m); ?></span>
                            <?php endforeach; ?>
                        </li>
                    <?php endif; ?>
                    <?php if ($aplicado['cambios'] !== []): ?>
                        <li>Se ordenaron <?= $fmtNum(count($aplicado['cambios'])); ?> datos (formato de RUT, fechas, montos) sin cambiar su contenido. <a href="#correcciones">Ver cuales</a>.</li>
                    <?php endif; ?>
                    <?php if ($aplicado['vacias'] !== []): ?>
                        <li>Se saltan <?= $fmtNum(count($aplicado['vacias'])); ?> fila<?= count($aplicado['vacias']) === 1 ? '' : 's'; ?> vacia<?= count($aplicado['vacias']) === 1 ? '' : 's'; ?>.</li>
                    <?php endif; ?>
                    <?php foreach ($columnasSinUso as $i): ?>
                        <li>La columna "<?= $e($nombreCol($i)); ?>" no se importa.</li>
                    <?php endforeach; ?>
                    <?php if ($aplicado['sin_interpretar'] !== []): ?>
                        <li><?= $fmtNum(count($aplicado['sin_interpretar'])); ?> valor(es) se dejaron como venian porque se pueden leer de dos formas. <a href="#correcciones">Ver cuales</a>.</li>
                    <?php endif; ?>
                </ul>

                <form method="post" action="/ventas/carga-masiva/adaptar/<?= $id; ?>/importar" class="form-compacto" id="autorizar" data-espera>
                    <?= csrfInput(); ?>
                    <input type="hidden" name="huella" value="<?= $e((string) $calculo['huella']); ?>">
                    <div class="form-campo">
                        <label class="form-check" for="autorizo">
                            <input type="checkbox" name="autorizo" id="autorizo" value="1" required>
                            Revise lo anterior y autorizo importar estas notas de venta
                        </label>
                        <small class="form-ayuda">Queda registrado con tu usuario y la hora. El archivo original se guarda.</small>
                    </div>
                    <div class="acciones-formulario">
                        <button class="boton-principal" type="submit">Importar <?= $fmtNum($notas); ?> nota<?= $notas === 1 ? '' : 's'; ?> de venta</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <?php if ($filasError !== []): ?>
            <details class="tarjeta" id="filas-error" open>
                <summary><strong>Filas con error (<?= $fmtNum(count($filasError)); ?>)</strong></summary>
                <p class="nota">El numero de fila es el de tu archivo.</p>
                <div class="tabla-scroll">
                    <table class="tabla-datos tabla-datos--cards">
                        <caption class="visualmente-oculto">Filas que no se pueden importar y por que.</caption>
                        <thead><tr><th scope="col">Fila</th><th scope="col">N° de venta</th><th scope="col">Que corregir</th></tr></thead>
                        <tbody>
                            <?php foreach (array_slice($filasError, 0, 200, true) as $i => $it): ?>
                                <tr>
                                    <th scope="row" data-label="Fila"><?= filaExcelDeItem($it, $i); ?></th>
                                    <td data-label="N° de venta"><?= $e((string) ($it['fila_original']['identificador_externo'] ?? '')) ?: '-'; ?></td>
                                    <td data-label="Que corregir">
                                        <ul class="alerta__lista">
                                            <?php foreach ($it['errores'] as $msg): ?>
                                                <li><?= $e($legible((string) $msg)); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        <?php endif; ?>

        <details class="tarjeta" id="correcciones">
            <summary><strong>Ver como quedan los datos</strong> (primeras <?= min(CARGA_ADAPTACION_FILAS_MUESTRA, count($aplicado['filas'])); ?> filas)</summary>
            <?php if ($porTipo !== []): ?>
                <ul class="panel-info__lista">
                    <?php foreach ($porTipo as $tipo => $n): ?>
                        <li><?= $e($tiposCambio[$tipo] ?? $tipo); ?>: <?= $fmtNum($n); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($aplicado['sin_interpretar'] !== []): ?>
                <ul class="panel-info__lista">
                    <?php foreach (array_slice($aplicado['sin_interpretar'], 0, 10) as $s): ?>
                        <li>Fila <?= (int) $s['fila']; ?>, <?= $e($et($s['columna'])); ?>: "<?= $e($s['valor']); ?>" se deja como esta porque <?= $e($s['motivo']); ?>.</li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <p class="nota">Debajo de cada dato ordenado va como venia en tu archivo.</p>
            <div class="tabla-scroll">
                <table class="tabla-datos">
                    <caption class="visualmente-oculto">Muestra de filas tal como se importarian, con el valor original de cada dato ordenado.</caption>
                    <thead>
                        <tr>
                            <th scope="col">Fila</th>
                            <?php foreach ($columnasMuestra as $col): ?>
                                <th scope="col"><?= $e($et($col)); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($aplicado['filas'], 0, CARGA_ADAPTACION_FILAS_MUESTRA) as $f): ?>
                            <?php $nf = (int) $f['fila_origen']; ?>
                            <tr>
                                <th scope="row"><?= $nf; ?></th>
                                <?php foreach ($columnasMuestra as $col): ?>
                                    <td>
                                        <?= $f[$col] === '' ? '<span class="tabla-datos__secundario">-</span>' : $e($f[$col]); ?>
                                        <?php if (isset($cambioDe[$nf][$col])): ?>
                                            <span class="tabla-datos__secundario">venia: <?= $cambioDe[$nf][$col]['antes'] === '' ? '(vacio)' : $e($cambioDe[$nf][$col]['antes']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>

        <details class="tarjeta" id="cambiar"<?= $validas === 0 && $revision['problemas'] === [] ? ' open' : ''; ?>>
            <summary><strong>Cambiar como se lee el archivo</strong></summary>
            <?= $formulario; ?>
        </details>
    <?php endif; ?>

    <form method="post" action="/ventas/carga-masiva/adaptar/<?= $id; ?>/descartar" class="form-compacto">
        <?= csrfInput(); ?>
        <div class="acciones-formulario">
            <button class="boton-secundario" type="submit">Descartar este archivo</button>
        </div>
    </form>
<?php endif; ?>

<p><a href="/ventas/carga-masiva/adaptar">Volver a los archivos adaptados</a></p>

</div>

<script>
/* ESPERA DE LOS ENVIOS -- seccion 14. Doble envio cortado en el acto; pasados
   300 ms, spinner dentro del boton (mismo ancho) y aria-busy. Volver con Atras
   lo deja como estaba. */
(function () {
    document.querySelectorAll('form[data-espera]').forEach(function (form) {
        var boton = form.querySelector('button[type="submit"]');
        var enviando = false, umbral = null;
        form.addEventListener('submit', function (ev) {
            if (enviando) { ev.preventDefault(); return; }
            enviando = true;
            umbral = setTimeout(function () {
                boton.setAttribute('data-cargando', 'true');
                boton.setAttribute('aria-busy', 'true');
                form.setAttribute('aria-busy', 'true');
                boton.disabled = true;
            }, 300);
        });
        window.addEventListener('pageshow', function (ev) {
            if (!ev.persisted) { return; }
            clearTimeout(umbral);
            enviando = false;
            boton.removeAttribute('data-cargando');
            boton.removeAttribute('aria-busy');
            form.removeAttribute('aria-busy');
            boton.disabled = false;
        });
    });
    if (location.hash === '#vista-previa') {
        var vp = document.getElementById('vista-previa');
        if (vp) { vp.focus(); }
    }
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
