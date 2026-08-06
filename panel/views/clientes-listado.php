<?php
/**
 * Maestros > Clientes: listado.
 *
 * Recibe: $clientes, $q, $incluirInactivos, $soloIncompletos, $totalIncompletos,
 * $pagina, $totalPaginas, $total, $flash, $navActivo. Todo de
 * handleClientesListar(), 25 por pagina.
 *
 * Cada cliente trae los campos de MySqlClienteRepository::mapear():
 *   id, rut_cliente, razon_social, giro, direccion, comuna, email, telefono,
 *   activo, created_at, updated_at.
 * giro, direccion, comuna, email y telefono pueden ser null.
 *
 * FILTROS REALES: tres parametros GET, q, inactivos e incompletos (mas pagina).
 * La busqueda es LIKE sobre rut_cliente Y razon_social -- no busca por email ni
 * por comuna, y el placeholder no promete mas que eso. El orden lo fija el
 * repositorio: razon_social ASC.
 *
 * INCOMPLETO = SIN GIRO, DIRECCION O COMUNA. A ese cliente NO SE LE PUEDE
 * FACTURAR: los tres campos son codigo 1 ("dato obligatorio, debe estar
 * siempre") en factura 33 y factura exenta 34 segun la matriz del Formato DTE
 * v2.5. La marca y el filtro existen porque hasta ahora esos clientes eran
 * indistinguibles: la tabla mostraba un guion igual que para un dato
 * simplemente ausente, y el fallo aparecia recien al emitir.
 *
 * La marca de CADA FILA sale de clienteCamposFaltantes() y el FILTRO de
 * MySqlClienteRepository::SQL_INCOMPLETO. Son dos expresiones de la misma
 * regla en dos lenguajes; si alguna vez dejan de coincidir, el filtro traeria
 * filas sin marca o al reves.
 *
 * ACTIVO/INACTIVO: activar() y desactivar() son un UPDATE de la columna activo
 * (cambiarActivo()), no un borrado. Por eso "Inactivo" es un estado neutro y no
 * un error: el registro sigue existiendo y se puede reactivar.
 *
 * NO SE MUESTRA la direccion: la vista la recibe, pero en una tabla es una
 * columna larga que no ayuda a identificar al cliente. Esta en el formulario de
 * edicion. Tampoco se agrega ninguna consulta.
 */
$titulo = 'Clientes';
require __DIR__ . '/partials/header.php';

// Query string de paginacion: arrastra los filtros activos. Identico al que
// habia; si se pierde, la paginacion navega sobre otro conjunto.
$qs = ($q !== '' ? '&q=' . urlencode($q) : '')
    . ($incluirInactivos ? '&inactivos=1' : '')
    . ($soloIncompletos ? '&incompletos=1' : '');

$hayFiltros = $q !== '' || $incluirInactivos || $soloIncompletos;

/** Valor de texto opcional: si viene vacio se marca como ausente, no en blanco. */
$oVacio = static function (?string $v): string {
    $v = trim((string) $v);
    return $v === '' ? '<span class="dash-vacio-inline">&mdash;</span>' : htmlspecialchars($v);
};

/**
 * Igual que $oVacio pero para los campos que HACEN FALTA PARA FACTURAR: el
 * hueco se pinta en rojo y con una etiqueta que dice que falta, en vez del
 * guion neutro. Un dato ausente que impide emitir no puede verse igual que un
 * telefono en blanco.
 */
$oFalta = static function (?string $v): string {
    $v = trim((string) $v);
    return $v === ''
        ? '<span class="cliente-falta">Falta</span>'
        : htmlspecialchars($v);
};
?>

<div class="dash-header">
    <div>
        <h1>Clientes</h1>
    </div>
    <div class="acciones-grupo acciones-grupo--header">
        <a class="boton-principal" href="/maestros/clientes/nuevo">Nuevo cliente</a>
    </div>
</div>
<p class="dash-subtitulo">
    Administra los datos de tus clientes para reutilizarlos al emitir documentos.
</p>

<?php if (! empty($flash['mensaje'])): ?>
    <p class="alerta alerta--<?= ($flash['tipo'] ?? '') === 'exito' ? 'exito' : 'error'; ?>" role="status">
        <span class="alerta__icono" aria-hidden="true"><?= ($flash['tipo'] ?? '') === 'exito' ? '&#10003;' : '&#9888;'; ?></span>
        <span><?= htmlspecialchars($flash['mensaje']); ?></span>
    </p>
<?php endif; ?>

<?php
// AVISO DE INCOMPLETOS. Va antes de los filtros y solo aparece si los hay, con
// un enlace que aplica el filtro: sin eso, saber que existen 12 clientes rotos
// no sirve de nada porque hay que encontrarlos entre 400.
//
// El conteo NO depende de la busqueda (ver handleClientesListar): dice cuantos
// hay en la cuenta, no cuantos de los que se estan mirando.
if (! $soloIncompletos && $totalIncompletos > 0): ?>
    <p class="alerta alerta--advertencia" role="status">
        <span class="alerta__icono" aria-hidden="true">&#9888;</span>
        <span>
            <strong><?= (int) $totalIncompletos; ?></strong>
            cliente<?= $totalIncompletos === 1 ? '' : 's'; ?>
            <?= $totalIncompletos === 1 ? 'no tiene' : 'no tienen'; ?>
            giro, direccion o comuna, y sin esos datos el SII no acepta la factura.
            <a href="/maestros/clientes?incompletos=1">Ver
                <?= $totalIncompletos === 1 ? 'ese cliente' : 'esos clientes'; ?></a>.
        </span>
    </p>
<?php endif; ?>

<form method="get" action="/maestros/clientes" class="filtros">
    <label class="filtros__campo">Buscar
        <input type="text" name="q" class="filtros__input" value="<?= htmlspecialchars($q); ?>"
               placeholder="RUT o razon social">
    </label>
    <label class="filtros__campo form-check">
        <input type="checkbox" name="inactivos" value="1" <?= $incluirInactivos ? 'checked' : ''; ?>>
        Incluir inactivos
    </label>
    <label class="filtros__campo form-check">
        <input type="checkbox" name="incompletos" value="1" <?= $soloIncompletos ? 'checked' : ''; ?>>
        Solo los que no puedo facturar
    </label>
    <button type="submit" class="boton-secundario">Filtrar</button>
    <?php if ($hayFiltros): ?>
        <a class="boton-texto" href="/maestros/clientes">Limpiar filtros</a>
    <?php endif; ?>
</form>

<?php if ($clientes === []): ?>
    <div class="estado-vacio">
        <?php if ($q !== ''): ?>
            <h2>Sin resultados</h2>
            <p>Ningun cliente coincide con "<?= htmlspecialchars($q); ?>". La busqueda mira el RUT y la razon social.</p>
            <p class="estado-vacio__acciones">
                <a class="boton-principal" href="/maestros/clientes">Limpiar filtros</a>
            </p>
        <?php else: ?>
            <h2>Aun no tienes clientes</h2>
            <p>Los clientes que guardes aqui se autocompletan al emitir un documento con su RUT.</p>
            <p class="estado-vacio__acciones">
                <a class="boton-principal" href="/maestros/clientes/nuevo">Nuevo cliente</a>
            </p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="tabla-scroll">
        <table class="tabla-datos tabla-clientes">
            <caption>
                <?= $total; ?> cliente<?= $total === 1 ? '' : 's'; ?><?= $hayFiltros ? ' con los filtros aplicados' : ''; ?><?php
                if ($totalPaginas > 1): ?> &middot; pagina <?= $pagina; ?> de <?= $totalPaginas; ?><?php endif; ?>
            </caption>
            <thead>
                <tr>
                    <th>Cliente</th>
                    <th>Giro</th>
                    <th>Comuna</th>
                    <th>Contacto</th>
                    <th class="tabla-datos__estado">Estado</th>
                    <th class="tabla-datos__acciones">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($clientes as $c): ?>
                    <?php $faltan = clienteCamposFaltantes($c); ?>
                    <tr<?= $c['activo'] ? '' : ' class="tabla-datos__fila--inactiva"'; ?>>
                        <td>
                            <?= htmlspecialchars($c['razon_social']); ?>
                            <?php if ($faltan !== []): ?>
                                <?php /* El title nombra los campos: la columna direccion no esta
                                        en la tabla, asi que sin esto un cliente al que solo le
                                        falta la direccion se veria marcado sin motivo visible. */ ?>
                                <span class="badge badge--advertencia cliente-incompleto"
                                      title="Falta <?= htmlspecialchars(implode(', ', $faltan)); ?>">No facturable</span>
                            <?php endif; ?>
                            <span class="tabla-datos__secundario"><?= htmlspecialchars($c['rut_cliente']); ?></span>
                        </td>
                        <td><?= $oFalta($c['giro'] ?? null); ?></td>
                        <td><?= $oFalta($c['comuna'] ?? null); ?></td>
                        <td>
                            <?= $oVacio($c['email'] ?? null); ?>
                            <?php if (trim((string) ($c['telefono'] ?? '')) !== ''): ?>
                                <span class="tabla-datos__secundario"><?= htmlspecialchars((string) $c['telefono']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="tabla-datos__estado">
                            <?php if ($c['activo']): ?>
                                <span class="badge badge--ok">Activo</span>
                            <?php else: ?>
                                <span class="badge badge--neutro">Inactivo</span>
                            <?php endif; ?>
                        </td>
                        <td class="tabla-datos__acciones">
                            <a href="/maestros/clientes/<?= (int) $c['id']; ?>/editar">Editar</a>
                            <?php if ($c['activo']): ?>
                                <form method="post" action="/maestros/clientes/<?= (int) $c['id']; ?>/desactivar" style="display:inline;">
                                    <?= csrfInput(); ?>
                                    <button type="submit" class="boton-texto">Desactivar</button>
                                </form>
                            <?php else: ?>
                                <form method="post" action="/maestros/clientes/<?= (int) $c['id']; ?>/activar" style="display:inline;">
                                    <?= csrfInput(); ?>
                                    <button type="submit" class="boton-texto">Activar</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPaginas > 1): ?>
        <p class="paginacion">
            <?php if ($pagina > 1): ?>
                <a class="boton-secundario" href="/maestros/clientes?pagina=<?= $pagina - 1; ?><?= $qs; ?>">&larr; Anterior</a>
            <?php endif; ?>
            <span class="nota">Pagina <?= $pagina; ?> de <?= $totalPaginas; ?> (<?= $total; ?> clientes)</span>
            <?php if ($pagina < $totalPaginas): ?>
                <a class="boton-secundario" href="/maestros/clientes?pagina=<?= $pagina + 1; ?><?= $qs; ?>">Siguiente &rarr;</a>
            <?php endif; ?>
        </p>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
