<?php
/**
 * Auditoria del tenant.
 *
 * TAREA DE LA PANTALLA, EN UNA FRASE: el dueno revisa quien hizo que cambio
 * administrativo en su cuenta, y cuando.
 *
 * Recibe: $filas, $desde, $hasta, $accionFiltro, $pagina, $totalPaginas, $total.
 * Cada fila trae id, usuario_id, usuario_email (del LEFT JOIN, puede ser null),
 * accion, entidad_tipo, entidad_id, valor_anterior, valor_nuevo y created_at,
 * ordenadas por created_at DESC. 25 por pagina.
 *
 * NO HAY IP en admin_auditoria: la tabla no la guarda, asi que no se muestra.
 *
 * valor_anterior y valor_nuevo son columnas JSON de MySQL que registrarAuditoria()
 * serializa desde un array. Llegan como STRING JSON o null. Esta vista NO los
 * decodifica ni los reformatea: mostrar json_decode/JSON_PRETTY_PRINT cambiaria
 * la salida de cualquier valor que no fuera JSON valido, y el contenido crudo es
 * justamente lo que sirve para soporte. Solo se mejora el envoltorio -- wrapping
 * y tipografia -- con .bloque-codigo.
 *
 * "Antes" y "Despues" son organizacion visual, no interpretacion: no se compara,
 * no se colorea por diferencia y no se resalta ningun campo.
 *
 * El detalle sigue en un <details> nativo, sin JavaScript. El contenido tecnico
 * aparece UNICAMENTE ahi: no se duplica en title, data-*, aria-label ni en un
 * preview dentro de la tabla.
 *
 * LA FECHA SE MUESTRA EN FORMATO CHILENO (dd/mm/aaaa hh:mm), no la cadena
 * cruda de MySQL: 2026-04-09 leido rapido es ambiguo, 09/04/2026 no lo es aqui.
 *
 * EN MOVIL LA TABLA PASA A TARJETAS (.tabla-datos--cards): cada evento se lee
 * de arriba abajo, con el nombre de la columna como titulo de cada linea.
 *
 * ESPERA AL FILTRAR (seccion 14, Efectos de carga): filtrar recarga la pagina.
 * Si tarda mas de 300 ms, el boton "Filtrar eventos" muestra su spinner sin
 * cambiar de ancho; antes de eso no se muestra nada.
 */
$titulo = 'Auditoria';
require __DIR__ . '/partials/header.php';

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

/** Fecha y hora en formato chileno. Si no se puede leer, se muestra tal cual. */
$fechaHora = static function (?string $valor) use ($e): string {
    $ts = $valor !== null && $valor !== '' ? strtotime($valor) : false;
    return $ts === false ? $e((string) $valor) : date('d/m/Y H:i', $ts);
};

// Query string de paginacion: arrastra los tres filtros. Identico al anterior.
$qs = '';
if ($desde !== '') { $qs .= '&desde=' . urlencode($desde); }
if ($hasta !== '') { $qs .= '&hasta=' . urlencode($hasta); }
if ($accionFiltro !== '') { $qs .= '&accion=' . urlencode($accionFiltro); }

$hayFiltros = $desde !== '' || $hasta !== '' || $accionFiltro !== '';
?>

<div class="dash-header">
    <div>
        <h1>Auditoria</h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <a class="boton-secundario" href="/panel">Volver al panel</a>
    </div>
</div>
<p class="dash-subtitulo">
    Quien cambio que en tu cuenta, incluido lo que hace el equipo de soporte. Nunca se mezclan otras cuentas.
</p>

<form method="get" action="/auditoria" class="filtros" id="form-filtros" aria-label="Filtrar eventos">
    <label class="filtros__campo">Desde
        <input type="date" name="desde" class="filtros__input filtros__input--medio" value="<?= $e($desde); ?>">
    </label>
    <label class="filtros__campo">Hasta
        <input type="date" name="hasta" class="filtros__input filtros__input--medio" value="<?= $e($hasta); ?>">
    </label>
    <label class="filtros__campo">Accion
        <input type="text" name="accion" class="filtros__input filtros__input--medio" value="<?= $e($accionFiltro); ?>"
               placeholder="usuario, apikey" aria-describedby="ayuda-filtro-accion">
    </label>
    <span class="visualmente-oculto" id="ayuda-filtro-accion">Busca eventos cuyo nombre contenga esta palabra.</span>
    <button type="submit" class="boton-secundario" id="filtrar">Filtrar eventos</button>
    <?php if ($hayFiltros): ?>
        <a class="boton-texto" href="/auditoria">Limpiar filtros</a>
    <?php endif; ?>
</form>

<?php if ($filas === []): ?>
    <div class="estado-vacio">
        <?= iconoSvg('auditoria', 32, 'estado-vacio__icono'); ?>
        <?php if ($hayFiltros): ?>
            <h2 class="estado-vacio__titulo">Ningun evento coincide con estos filtros</h2>
            <p>Prueba con otras fechas o con otra palabra en Accion.</p>
            <p><a class="boton-secundario" href="/auditoria">Limpiar filtros</a></p>
        <?php else: ?>
            <h2 class="estado-vacio__titulo">Todavia no hay cambios registrados</h2>
            <p>Cada vez que alguien invite a un usuario, cambie un rol o toque la configuracion de la empresa, quedara anotado aqui con su nombre y la hora.</p>
            <p><a class="boton-secundario" href="/configuracion/usuarios">Ir a Usuarios y permisos</a></p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <section class="tarjeta" aria-labelledby="titulo-eventos">
        <h2 id="titulo-eventos">Eventos</h2>
        <div class="tabla-scroll">
            <table class="tabla-datos tabla-datos--cards">
                <caption>
                    <?= $total; ?> evento<?= $total === 1 ? '' : 's'; ?><?= $hayFiltros ? ' con los filtros aplicados' : ''; ?><?php
                    if ($totalPaginas > 1): ?> &middot; pagina <?= $pagina; ?> de <?= $totalPaginas; ?><?php endif; ?>
                </caption>
                <thead>
                    <tr>
                        <th scope="col">Fecha</th>
                        <th scope="col">Quien</th>
                        <th scope="col">Accion</th>
                        <th scope="col">Sobre que</th>
                        <th scope="col">Detalle</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($filas as $f): ?>
                        <?php $tieneDetalle = ! empty($f['valor_anterior']) || ! empty($f['valor_nuevo']); ?>
                        <tr>
                            <td data-label="Fecha"><?= $fechaHora((string) $f['created_at']); ?></td>
                            <td data-label="Quien"><?= $e((string) ($f['usuario_email'] ?? 'Sistema')); ?></td>
                            <td data-label="Accion"><code><?= $e((string) $f['accion']); ?></code></td>
                            <td data-label="Sobre que">
                                <?= $e((string) $f['entidad_tipo']); ?>
                                <span class="tabla-datos__secundario">#<?= (int) $f['entidad_id']; ?></span>
                            </td>
                            <td data-label="Detalle">
                                <?php if ($tieneDetalle): ?>
                                    <details>
                                        <summary>Ver detalle</summary>
                                        <?php if (! empty($f['valor_anterior'])): ?>
                                            <p class="nota">Antes</p>
                                            <pre class="bloque-codigo"><?= $e((string) $f['valor_anterior']); ?></pre>
                                        <?php endif; ?>
                                        <?php if (! empty($f['valor_nuevo'])): ?>
                                            <p class="nota">Despues</p>
                                            <pre class="bloque-codigo"><?= $e((string) $f['valor_nuevo']); ?></pre>
                                        <?php endif; ?>
                                    </details>
                                <?php else: ?>
                                    <span class="dash-vacio-inline">Sin detalle</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($totalPaginas > 1): ?>
        <nav class="paginacion" aria-label="Paginas de eventos">
            <?php if ($pagina > 1): ?>
                <a class="boton-secundario" href="/auditoria?pagina=<?= $pagina - 1; ?><?= $qs; ?>">Pagina anterior</a>
            <?php endif; ?>
            <span class="nota" aria-current="page">Pagina <?= $pagina; ?> de <?= $totalPaginas; ?> (<?= $total; ?> eventos)</span>
            <?php if ($pagina < $totalPaginas): ?>
                <a class="boton-secundario" href="/auditoria?pagina=<?= $pagina + 1; ?><?= $qs; ?>">Pagina siguiente</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<script>
(function () {
    /* ESPERA AL FILTRAR -- seccion 14 del design system, Efectos de carga.
       Mismo patron que pagos-config.php: el doble envio se corta en el acto
       sin nada visible; a los 300 ms el boton muestra su spinner sin cambiar
       de ancho y la zona se marca aria-busy; el foco no se mueve. */
    var form = document.getElementById('form-filtros');
    var boton = document.getElementById('filtrar');
    if (!form || !boton) { return; }
    var enviando = false;
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
        if (ev.persisted) { clearTimeout(umbral); enviando = false; ocupado(false); }
    });
    form.addEventListener('submit', function (ev) {
        if (enviando) { ev.preventDefault(); return; }
        enviando = true;
        umbral = setTimeout(function () { ocupado(true); }, 300);
    });
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
