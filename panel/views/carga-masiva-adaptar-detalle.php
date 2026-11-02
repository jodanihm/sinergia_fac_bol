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
 * TRES SECCIONES EN EL ORDEN DE LA TAREA, y cada una depende de la anterior:
 *   1. Equivalencias  -- un solo formulario: columnas, valores para todas las
 *                        filas, traduccion de valores y opciones.
 *   2. Vista previa   -- solo cuando no queda nada pendiente. Muestra el
 *                        veredicto de la MISMA revision de la carga, los
 *                        cambios por tipo, una muestra de filas y los errores.
 *   3. Autorizar      -- solo si la carga aceptaria el archivo. La casilla es
 *                        obligatoria y el formulario lleva la huella de lo que
 *                        se ve: si algo cambia, el servidor no importa.
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
                    <li>"<?= $e((string) $m['columna_archivo']); ?>" &rarr; <code><?= $e((string) $m['columna_plantilla']); ?></code></li>
                <?php endforeach; ?>
                <?php foreach ((array) ($elecciones['valores_indicados'] ?? []) as $col => $valor): ?>
                    <li><code><?= $e((string) $col); ?></code> = "<?= $e((string) $valor); ?>" en todas las filas (indicado al autorizar)</li>
                <?php endforeach; ?>
                <?php foreach ((array) ($elecciones['equivalencias_de_valor'] ?? []) as $col => $pares): ?>
                    <?php foreach ((array) $pares as $de => $a): ?>
                        <li><code><?= $e((string) $col); ?></code>: "<?= $e((string) $de); ?>" &rarr; <?= $e((string) $a); ?></li>
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
                                    <td data-label="Columna"><code><?= $e((string) $c['columna']); ?></code></td>
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
    /* =============================== BORRADOR =============================== */
    $archivo     = $calculo['archivo'];
    $propuesta   = $calculo['propuesta'];
    $mapeo       = $calculo['mapeo'];
    $constantes  = $calculo['constantes'];
    $equivalencias = $calculo['equivalencias'];
    $mapeadas    = array_values($mapeo);
    $letra = static fn (int $i): string => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);

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
    ?>

    <?php if ($archivo['indice_encabezado'] > 0 || count($archivo['hojas']) > 1): ?>
        <div class="alerta alerta--info" role="status">
            <?= iconoSvg('info', 20, 'alerta__icono'); ?>
            <div class="alerta__cuerpo">
                <p>
                    Los datos se leen de la hoja <strong><?= $e($archivo['hoja']); ?></strong>, con los encabezados en la fila <?= $archivo['indice_encabezado'] + 1; ?>.
                    <?php if (count($archivo['hojas']) > 1): ?>
                        Las otras hojas (<?= $e(implode(', ', array_diff($archivo['hojas'], [$archivo['hoja']]))); ?>) no se usan.
                    <?php endif; ?>
                    <?php if ($archivo['indice_encabezado'] > 0): ?>
                        Lo que hay arriba de los encabezados no se importa.
                    <?php endif; ?>
                </p>
            </div>
        </div>
    <?php endif; ?>

    <form method="post" action="/ventas/carga-masiva/adaptar/<?= $id; ?>/equivalencias" class="form-compacto" data-espera>
        <?= csrfInput(); ?>

        <section class="tarjeta" aria-labelledby="titulo-equivalencias">
            <h2 id="titulo-equivalencias">1. Equivalencias de columnas</h2>
            <p class="tarjeta__intro">
                A que columna de la plantilla corresponde cada columna del archivo. Las marcadas "Revisar" o "Elegir"
                no se eligieron solas: admiten mas de una lectura y decides tu.
            </p>
            <div class="tabla-scroll">
                <table class="tabla-datos tabla-datos--cards">
                    <caption class="visualmente-oculto">Columnas del archivo y su equivalencia en la plantilla.</caption>
                    <thead>
                        <tr>
                            <th scope="col">Columna del archivo</th>
                            <th scope="col">Ejemplo</th>
                            <th scope="col">Columna de la plantilla</th>
                            <th scope="col">Propuesta</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($archivo['encabezados'] as $i => $encabezado): ?>
                            <?php
                                $p = $propuesta[$i];
                                [$claseNivel, $textoNivel] = $niveles[$p['nivel']];
                                $actual = $mapeo[$i] ?? '';
                                $nombre = $encabezado !== '' ? $encabezado : '(sin nombre)';
                            ?>
                            <tr>
                                <th scope="row" data-label="Columna del archivo">
                                    <?= $e($nombre); ?>
                                    <span class="tabla-datos__secundario">Columna <?= $letra($i); ?></span>
                                </th>
                                <td data-label="Ejemplo"><?= $e($ejemplo($i)) ?: '<span class="tabla-datos__secundario">(vacia)</span>'; ?></td>
                                <td data-label="Columna de la plantilla">
                                    <label class="visualmente-oculto" for="mapeo-<?= $i; ?>">Equivalencia de <?= $e($nombre); ?></label>
                                    <select name="mapeo[<?= $i; ?>]" id="mapeo-<?= $i; ?>">
                                        <option value="">No usar</option>
                                        <?php foreach (NOTA_VENTA_ENCABEZADOS as $col): ?>
                                            <option value="<?= $e($col); ?>"<?= $actual === $col ? ' selected' : ''; ?>><?= $e($col); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td data-label="Propuesta" class="tabla-datos__estado">
                                    <span class="badge <?= $claseNivel; ?>"><?= $textoNivel; ?></span>
                                    <?php if ($p['nivel'] === 'ambigua' || $p['nivel'] === 'dudosa'): ?>
                                        <span class="tabla-datos__secundario">
                                            Podria ser: <?= $e(implode(' o ', $p['candidatos'])); ?>.
                                            <?= $p['condicion'] !== null ? $e($p['condicion']) : ''; ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php $completables = array_values(array_diff(AdaptadorPlantilla::COMPLETABLES, $mapeadas)); ?>
        <?php if ($completables !== [] || ! in_array('identificador_externo', $mapeadas, true)): ?>
            <section class="tarjeta" aria-labelledby="titulo-faltantes">
                <h2 id="titulo-faltantes">Datos que el archivo no trae</h2>
                <p class="tarjeta__intro">
                    Solo condiciones del documento, que valen igual para todas las filas. Montos, RUT y productos
                    tienen que venir del archivo.
                </p>
                <div class="config-columnas config-columnas--2">
                    <?php foreach ($completables as $col): ?>
                        <div class="form-campo">
                            <label for="constante-<?= $e($col); ?>"><?= $e($col); ?><?= in_array($col, AdaptadorPlantilla::OBLIGATORIAS, true) ? ' (obligatorio)' : ' (opcional)'; ?></label>
                            <?php if ($col === 'forma_pago' || $col === 'exento'): ?>
                                <select name="constante[<?= $e($col); ?>]" id="constante-<?= $e($col); ?>">
                                    <option value="">Sin indicar</option>
                                    <?php foreach ($col === 'forma_pago' ? AdaptadorPlantilla::VALORES_FORMA_PAGO : AdaptadorPlantilla::VALORES_EXENTO as $v): ?>
                                        <option value="<?= $e($v); ?>"<?= ($constantes[$col] ?? '') === $v ? ' selected' : ''; ?>><?= $e($v); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <?php /* type="date" y no texto con inputmode="numeric": ese
                                   inputmode activa la regla "solo numeros" de
                                   validar-campos.js, que rechazaba las barras de la
                                   fecha. El selector envia AAAA-MM-DD, la misma forma
                                   en que se guarda. */ ?>
                                <input type="date" name="constante[<?= $e($col); ?>]" id="constante-<?= $e($col); ?>"
                                       value="<?= $e((string) ($constantes[$col] ?? '')); ?>"
                                       aria-describedby="ayuda-constante-<?= $e($col); ?>">
                                <small class="form-ayuda" id="ayuda-constante-<?= $e($col); ?>">
                                    <?= $col === 'fecha_vencimiento' ? 'Solo si todas las ventas son a CREDITO con el mismo vencimiento.' : 'La misma fecha para todas las filas.'; ?>
                                </small>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (! in_array('identificador_externo', $mapeadas, true)): ?>
                    <div class="form-campo">
                        <label class="form-check" for="identificador-por-fila">
                            <input type="checkbox" name="identificador_por_fila" id="identificador-por-fila" value="1"<?= (bool) $adaptacion['identificador_por_fila'] ? ' checked' : ''; ?>
                                   aria-describedby="ayuda-identificador">
                            El archivo no trae un identificador por venta: usar el numero de fila
                        </label>
                        <small class="form-ayuda" id="ayuda-identificador">
                            Cada nota queda como "<?= $e(substr((string) $adaptacion['sha256_original'], 0, 8)); ?>-F&lt;numero de fila&gt;". Evita cargar dos veces
                            ESTE MISMO archivo, pero no reconoce la misma venta en otro archivo. Si tu cliente tiene un numero de pedido o de venta, es mejor usar esa columna.
                        </small>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($calculo['porTraducir'] !== []): ?>
            <section class="tarjeta" aria-labelledby="titulo-valores">
                <h2 id="titulo-valores">Valores que la carga no reconoce</h2>
                <p class="tarjeta__intro">
                    Estas columnas solo aceptan ciertos valores. Indica a cual equivale cada uno de los que trae el archivo;
                    si alguno no corresponde a ninguno, corrige el archivo.
                </p>
                <?php foreach ($calculo['porTraducir'] as $col => $valores): ?>
                    <h3><code><?= $e($col); ?></code></h3>
                    <div class="config-columnas config-columnas--3">
                        <?php foreach ($valores as $k => $valor): ?>
                            <div class="form-campo">
                                <label for="equiv-<?= $e($col); ?>-<?= $k; ?>">"<?= $e($valor); ?>" equivale a</label>
                                <input type="hidden" name="equiv_valor[<?= $e($col); ?>][<?= $k; ?>]" value="<?= $e($valor); ?>">
                                <select name="equiv_destino[<?= $e($col); ?>][<?= $k; ?>]" id="equiv-<?= $e($col); ?>-<?= $k; ?>">
                                    <option value="">Sin elegir</option>
                                    <?php foreach ($col === 'forma_pago' ? AdaptadorPlantilla::VALORES_FORMA_PAGO : AdaptadorPlantilla::VALORES_EXENTO as $v): ?>
                                        <option value="<?= $e($v); ?>"<?= ($equivalencias[$col][$valor] ?? '') === $v ? ' selected' : ''; ?>><?= $e($v); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <section class="tarjeta" aria-labelledby="titulo-tipo">
            <h2 id="titulo-tipo">Tipo de documento</h2>
            <div class="carga-masiva__tipo">
                <label class="interruptor" for="tipo_exento">
                    <input type="checkbox" role="switch" name="tipo_exento" id="tipo_exento" value="1"<?= (bool) $adaptacion['es_exento'] ? ' checked' : ''; ?>>
                    <span class="interruptor__texto">
                        <span class="interruptor__titulo">Archivo de facturas exentas</span>
                        <span class="interruptor__detalle">Marcado, todas las notas salen como factura exenta (34) y todas sus lineas tienen que ser exentas. Sin marcar, factura afecta (33).</span>
                    </span>
                </label>
            </div>
            <div class="acciones-formulario">
                <button class="boton-principal" type="submit">Guardar y ver vista previa</button>
            </div>
        </section>
    </form>

    <section class="tarjeta" aria-labelledby="titulo-vista-previa" id="vista-previa" tabindex="-1">
        <h2 id="titulo-vista-previa">2. Vista previa</h2>

        <?php if ($calculo['pendientes'] !== []): ?>
            <div class="alerta alerta--advertencia" role="status">
                <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
                <div class="alerta__cuerpo">
                    <p class="alerta__titulo">Falta resolver antes de ver la vista previa</p>
                    <ul class="alerta__lista">
                        <?php foreach ($calculo['pendientes'] as $p): ?>
                            <li><?= $e($p); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php else: ?>
            <?php
                $aplicado = $calculo['aplicado'];
                $revision = $calculo['revision'];
                $items    = $revision['items'];
                $validas  = count(array_filter($items, static fn (array $it): bool => $it['status'] === 'ok'));
                $conError = count($items) - $validas;
                $porTipo  = array_count_values(array_column($aplicado['cambios'], 'tipo'));
                $notasCredito = count(array_filter($revision['grupos'], static fn (array $g): bool => ! empty($g['datos']['boleta_ref_folio'])));
                $columnasMuestra = array_values(array_filter(
                    NOTA_VENTA_ENCABEZADOS,
                    static fn (string $c): bool => in_array($c, $mapeadas, true) || isset($constantes[$c]) && $constantes[$c] !== '' || $c === 'identificador_externo'
                ));
                // Cambios indexados por fila y columna, para marcarlos en la muestra.
                $cambioDe = [];
                foreach ($aplicado['cambios'] as $c) {
                    $cambioDe[$c['fila']][$c['columna']] = $c;
                }
                $columnasSinUso = array_filter(
                    array_keys($archivo['encabezados']),
                    static fn (int $i): bool => ! isset($mapeo[$i]) && $ejemplo($i) !== ''
                );
            ?>

            <?php if ($revision['problemas'] !== []): ?>
                <div class="alerta alerta--error" role="alert">
                    <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
                    <div class="alerta__cuerpo">
                        <p class="alerta__titulo">La carga rechazaria este archivo completo</p>
                        <p>Corrigelo en el archivo, o ajusta las equivalencias o los valores de arriba.</p>
                        <ul class="alerta__lista">
                            <?php foreach ($revision['problemas'] as $p): ?>
                                <li><?= $e($p); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php elseif ($conError > 0): ?>
                <div class="alerta alerta--advertencia" role="status">
                    <?= iconoSvg('alerta', 20, 'alerta__icono'); ?>
                    <div class="alerta__cuerpo">
                        <p class="alerta__titulo">Se puede importar, pero <?= $conError === 1 ? '1 fila quedaria' : $fmtNum($conError) . ' filas quedarian'; ?> con error</p>
                        <p>Las filas con error se guardan apartadas y no pasan a facturacion. El detalle esta abajo.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="alerta alerta--exito" role="status">
                    <?= iconoSvg('check', 20, 'alerta__icono'); ?>
                    <div class="alerta__cuerpo">
                        <p class="alerta__titulo">Todas las filas cumplen los requisitos</p>
                    </div>
                </div>
            <?php endif; ?>

            <section class="dash-grid" aria-label="Resumen de la vista previa">
                <article class="kpi">
                    <h3 class="kpi__etiqueta">Filas con datos</h3>
                    <p class="kpi__valor"><?= $fmtNum(count($aplicado['filas'])); ?></p>
                    <p class="kpi__formula">
                        <?= $aplicado['vacias'] === [] ? 'Sin filas vacias' : $fmtNum(count($aplicado['vacias'])) . ' vacia(s) quitada(s)'; ?>
                    </p>
                </article>
                <article class="kpi">
                    <h3 class="kpi__etiqueta">Validas</h3>
                    <p class="kpi__valor"><?= $fmtNum($validas); ?></p>
                    <p class="kpi__formula">
                        <?= $fmtNum(count($revision['grupos'])); ?> nota(s) de venta<?= $notasCredito > 0 ? ', ' . $fmtNum($notasCredito) . ' anulan boleta' : ''; ?>
                    </p>
                </article>
                <article class="kpi<?= $conError > 0 ? ' kpi--rojo' : ''; ?>">
                    <h3 class="kpi__etiqueta">Con error</h3>
                    <p class="kpi__valor"><?= $fmtNum($conError); ?></p>
                    <p class="kpi__formula">Quedarian apartadas</p>
                </article>
                <article class="kpi">
                    <h3 class="kpi__etiqueta">Celdas corregidas</h3>
                    <p class="kpi__valor"><?= $fmtNum(count($aplicado['cambios'])); ?></p>
                    <p class="kpi__formula">Solo forma, ver abajo</p>
                </article>
            </section>

            <?php if ($porTipo !== [] || $aplicado['sin_interpretar'] !== [] || $columnasSinUso !== []): ?>
                <div class="config-columnas config-columnas--2">
                    <?php if ($porTipo !== []): ?>
                        <div class="panel-info">
                            <p class="panel-info__titulo"><?= iconoSvg('check', 20, 'panel-info__icono'); ?>Correcciones propuestas</p>
                            <ul class="panel-info__lista">
                                <?php foreach ($porTipo as $tipo => $n): ?>
                                    <li><?= $e($tiposCambio[$tipo] ?? $tipo); ?>: <?= $fmtNum($n); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <?php if ($aplicado['sin_interpretar'] !== [] || $columnasSinUso !== []): ?>
                        <div class="panel-info">
                            <p class="panel-info__titulo"><?= iconoSvg('alerta', 20, 'panel-info__icono'); ?>Advertencias</p>
                            <ul class="panel-info__lista">
                                <?php foreach (array_slice($aplicado['sin_interpretar'], 0, 10) as $s): ?>
                                    <li>Fila <?= (int) $s['fila']; ?>, <code><?= $e($s['columna']); ?></code>: "<?= $e($s['valor']); ?>" se deja como esta porque <?= $e($s['motivo']); ?>.</li>
                                <?php endforeach; ?>
                                <?php if (count($aplicado['sin_interpretar']) > 10): ?>
                                    <li>Y <?= $fmtNum(count($aplicado['sin_interpretar']) - 10); ?> valor(es) mas en la misma situacion.</li>
                                <?php endif; ?>
                                <?php foreach ($columnasSinUso as $i): ?>
                                    <li>La columna "<?= $e($archivo['encabezados'][$i] !== '' ? $archivo['encabezados'][$i] : $letra($i)); ?>" trae datos y no se importa.</li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <h3>Muestra: primeras <?= min(CARGA_ADAPTACION_FILAS_MUESTRA, count($aplicado['filas'])); ?> filas tal como se importarian</h3>
            <p class="nota">Debajo de cada valor corregido va el que traia el archivo.</p>
            <div class="tabla-scroll">
                <table class="tabla-datos">
                    <caption class="visualmente-oculto">Muestra de filas adaptadas a la plantilla, con el valor original de cada celda corregida.</caption>
                    <thead>
                        <tr>
                            <th scope="col">Fila</th>
                            <?php foreach ($columnasMuestra as $col): ?>
                                <th scope="col"><code><?= $e($col); ?></code></th>
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
                                            <span class="tabla-datos__secundario">antes: <?= $cambioDe[$nf][$col]['antes'] === '' ? '(vacio)' : $e($cambioDe[$nf][$col]['antes']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php $filasError = array_filter($items, static fn (array $it): bool => $it['status'] !== 'ok'); ?>
            <?php if ($filasError !== []): ?>
                <h3>Filas con error</h3>
                <p class="nota">El numero de fila es el del archivo original.</p>
                <div class="tabla-scroll">
                    <table class="tabla-datos tabla-datos--cards">
                        <caption class="visualmente-oculto">Filas que no cumplen los requisitos y por que.</caption>
                        <thead><tr><th scope="col">Fila</th><th scope="col">Identificador</th><th scope="col">Que corregir</th></tr></thead>
                        <tbody>
                            <?php foreach (array_slice($filasError, 0, 200, true) as $i => $it): ?>
                                <tr>
                                    <th scope="row" data-label="Fila"><?= filaExcelDeItem($it, $i); ?></th>
                                    <td data-label="Identificador"><?= $e((string) ($it['fila_original']['identificador_externo'] ?? '')) ?: '-'; ?></td>
                                    <td data-label="Que corregir">
                                        <ul class="alerta__lista">
                                            <?php foreach ($it['errores'] as $msg): ?>
                                                <li><?= $e((string) $msg); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($filasError) > 200): ?>
                    <p class="nota">Se muestran las primeras 200.</p>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="tarjeta" aria-labelledby="titulo-autorizar" id="autorizar">
        <h2 id="titulo-autorizar">3. Autorizar e importar</h2>
        <?php if ($calculo['pendientes'] === [] && $calculo['revision']['problemas'] === [] && $validas === 0): ?>
            <p class="tarjeta__intro">Ninguna fila se importaria: todas tienen error. Si es un archivo que ya cargaste, no hace falta importarlo de nuevo.</p>
        <?php elseif ($calculo['pendientes'] === [] && $calculo['revision']['problemas'] === []): ?>
            <form method="post" action="/ventas/carga-masiva/adaptar/<?= $id; ?>/importar" class="form-compacto" data-espera>
                <?= csrfInput(); ?>
                <input type="hidden" name="huella" value="<?= $e((string) $calculo['huella']); ?>">
                <div class="form-campo">
                    <label class="form-check" for="autorizo">
                        <input type="checkbox" name="autorizo" id="autorizo" value="1" required>
                        Autorizo aplicar las correcciones de la vista previa e importar
                        <?= $fmtNum(count($revision['grupos'])); ?> nota(s) de venta<?= $conError > 0 ? ' (y ' . $fmtNum($conError) . ' fila(s) con error, apartadas)' : ''; ?>.
                    </label>
                    <small class="form-ayuda">Queda registrado con tu usuario, la fecha y la hora. El archivo original se conserva.</small>
                </div>
                <div class="acciones-formulario">
                    <button class="boton-principal" type="submit">Importar</button>
                </div>
            </form>
        <?php else: ?>
            <p class="tarjeta__intro">Se habilita cuando no queda nada por resolver y la carga aceptaria el archivo.</p>
        <?php endif; ?>
        <form method="post" action="/ventas/carga-masiva/adaptar/<?= $id; ?>/descartar" class="form-compacto">
            <?= csrfInput(); ?>
            <div class="acciones-formulario">
                <button class="boton-secundario" type="submit">Descartar sin importar</button>
            </div>
        </form>
    </section>

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
