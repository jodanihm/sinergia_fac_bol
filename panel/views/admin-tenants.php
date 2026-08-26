<?php
/**
 * Cuentas del SaaS (GET /admin/tenants).
 *
 * Los datos que recibe, la barra de 6 etapas y el mapa de campos revertibles
 * vienen de handleAdminTenantsGet(); suspender, reactivar y revertir etapa no
 * se han tocado desde que se paso al layout del panel de control.
 *
 * LA COLUMNA "TIPO" ES LA UNICA QUE NO DESCRIBE EL ESTADO TECNICO DE LA CUENTA
 * sino su relacion comercial: si paga, si esta evaluando, o si es de la casa.
 * Va segunda, pegada al estado, porque las dos juntas son el resumen que se
 * viene a buscar aqui -- "activa" no dice nada sobre si es un cliente. Su tag
 * se pinta rojo cuando dice "Sin definir": eso es trabajo pendiente, no un
 * estado de reposo.
 *
 * EL PLAN VA EN LA MISMA CELDA QUE EL TIPO, debajo y mas chico, no en una
 * columna propia. Son dos datos de la misma pregunta -- que es esta cuenta
 * comercialmente -- y separarlos en dos columnas los aleja justo cuando se leen
 * juntos, ademas de sumar una octava columna a una tabla que ya es ancha. Si la
 * cuenta cobra y no declara plan, el plan se pinta como falta (ver
 * PlanCuenta::incoherente): no es una regla que bloquee nada, es un aviso.
 *
 * LAS ACCIONES VIVEN EN UN MODAL, UNA POR FILA, y la tabla vuelve a ser solo
 * datos. Antes cada fila llevaba los controles a la vista: un <select> con su
 * boton en la columna del tipo y otro boton en la ultima. Con seis cuentas ya
 * competian con lo que se viene a leer, y son cosas de naturaleza distinta --
 * corregir una etiqueta comercial no se parece en nada a cortarle el servicio a
 * un contribuyente que esta emitiendo. Adentro del modal cada una tiene su
 * bloque, su explicacion y su espacio, y la peligrosa se distingue por el
 * color.
 *
 * ES UN <dialog> NATIVO. El navegador da gratis el foco atrapado adentro, Esc
 * para cerrar, el resto de la pagina inerte y el backdrop; una version casera
 * con un div reimplementa todo eso peor. Cuesta las diez lineas de JS del final
 * -- la segunda pantalla del panel que lleva JS, despues del diagrama ER -- y
 * hay salida sin JS: sin la marca html.con-js, los formularios se despliegan en
 * la pagina (ver admin.css). Se pierde el modal, no la funcion.
 *
 * "Cuentas" y no "Tenants" en la interfaz: es como se llama la tabla, y es la
 * palabra que usa quien atiende el telefono.
 */
$titulo      = 'Cuentas';
$adminActivo = 'cuentas';
require __DIR__ . '/partials/admin/header.php';
?>

<?php /* VA AQUI ARRIBA Y NO AL FINAL: marca el documento como "hay JS" ANTES de
         que el navegador pinte la tabla. Al final, los seis dialogos alcanzarian
         a dibujarse desplegados -- que es como se ven sin JS -- y la pantalla
         daria un salto en cada carga. */ ?>
<script>document.documentElement.classList.add('con-js');</script>

<h2 class="page-title">Cuentas</h2>
<p class="muted">
    Todas las cuentas del SaaS, no solo la tuya. Solo lectura: lo que se puede cambiar esta en el
    boton <strong>Acciones</strong> de cada fila &mdash; el tipo de cuenta y suspender o reactivar
    &mdash;, mas revertir una etapa confirmada por error. Los tres cambios quedan en
    <a href="/admin/auditoria">Auditoria</a>.
</p>

<?php if ($flash !== null): ?>
    <?php if ($flash['tipo'] === 'error'): ?>
    <p class="error"><?= htmlspecialchars($flash['mensaje']); ?></p>
    <?php else: ?>
    <p class="msg-ok"><?= htmlspecialchars($flash['mensaje']); ?></p>
    <?php endif; ?>
<?php endif; ?>

<?php
    // Filtros. Formulario GET y no POST: asi el resultado queda en la URL y se
    // puede guardar, compartir o recargar. Un POST aqui obligaria ademas a
    // pasar por el CSRF central, que existe para las MUTACIONES; buscar no
    // muta nada.
    $hayFiltro = $busqueda !== '' || $estado !== '' || $tipo !== '' || $plan !== '';

    // Cuantas cuentas hay de cada tipo. Se dibuja siempre que haya alguna
    // cuenta, incluso con el filtro puesto, porque son las cifras de la cartera
    // ENTERA: el handler las cuenta sin filtros justamente para eso.
    $comerciales = 0;
    foreach (TipoCuenta::comerciales() as $claveComercial) {
        $comerciales += $porTipo[$claveComercial] ?? 0;
    }
?>

<?php if ($totalCuentas > 0): ?>
<div class="chips" style="margin-bottom:1rem;">
    <?php foreach (TipoCuenta::catalogo() as $claveTipo => [$etiquetaTipo, $claseTipo, $ayudaTipo]): ?>
    <?php if (($porTipo[$claveTipo] ?? 0) === 0) { continue; } ?>
    <a class="<?= htmlspecialchars($claseTipo); ?>" title="<?= htmlspecialchars($ayudaTipo); ?>"
       style="text-decoration:none;"
       href="/admin/tenants?tipo=<?= urlencode($claveTipo); ?>"><?= (int) $porTipo[$claveTipo]; ?> <?= htmlspecialchars($etiquetaTipo); ?></a>
    <?php endforeach; ?>
    <span class="muted" style="font-size:.85rem;">
        <?php /* La cifra que antes no se podia sacar de ninguna pantalla. */ ?>
        <?= $comerciales; ?> de <?= (int) $totalCuentas; ?> son cuentas comerciales (de pago o en trial).
    </span>
</div>

<?php /* Los planes van en su propia linea y solo los CONTRATADOS: "sin plan" ya
         se entiende por el tipo, y repetirlo aqui duplicaria el conteo de
         arriba con otra etiqueta. */ ?>
<?php $hayPlanes = false; foreach (PlanCuenta::contratados() as $p) { $hayPlanes = $hayPlanes || ($porPlan[$p] ?? 0) > 0; } ?>
<?php if ($hayPlanes || ($porPlan['sin_definir'] ?? 0) > 0): ?>
<div class="chips" style="margin-bottom:1rem;">
    <?php foreach (PlanCuenta::catalogo() as $clavePlan => [$etiquetaPlan, $clasePlan, $ayudaPlan]): ?>
    <?php if ($clavePlan === 'ninguno' || ($porPlan[$clavePlan] ?? 0) === 0) { continue; } ?>
    <a class="<?= htmlspecialchars($clasePlan); ?>" title="<?= htmlspecialchars($ayudaPlan); ?>"
       style="text-decoration:none;"
       href="/admin/tenants?plan=<?= urlencode($clavePlan); ?>"><?= (int) $porPlan[$clavePlan]; ?> <?php
        echo $clavePlan === 'sin_definir' ? 'sin plan definido' : 'plan ' . htmlspecialchars($etiquetaPlan); ?></a>
    <?php endforeach; ?>
    <span class="muted" style="font-size:.85rem;">
        Los planes son una referencia de la pagina de venta: el sistema no cobra ni controla sus topes.
    </span>
</div>
<?php endif; ?>
<?php endif; ?>
<div class="toolbar">
    <a class="btn" href="/admin/tenants/nueva">Nueva cuenta</a>
    <span class="muted">Crea la cuenta y su propietario en un paso, con clave temporal.</span>
</div>

<form class="toolbar" method="get" action="/admin/tenants">
    <input type="search" name="q" value="<?= htmlspecialchars($busqueda); ?>"
           placeholder="Nombre, email o RUT" aria-label="Buscar cuenta" style="max-width:280px;">
    <select name="estado" aria-label="Filtrar por estado" style="max-width:170px;">
        <option value="">Todos los estados</option>
        <option value="activa" <?= $estado === 'activa' ? 'selected' : ''; ?>>Activas</option>
        <option value="suspendida" <?= $estado === 'suspendida' ? 'selected' : ''; ?>>Suspendidas</option>
    </select>
    <select name="tipo" aria-label="Filtrar por tipo de cuenta" style="max-width:190px;">
        <option value="">Todos los tipos</option>
        <?php foreach (TipoCuenta::catalogo() as $claveTipo => [$etiquetaTipo, , ]): ?>
        <option value="<?= htmlspecialchars($claveTipo); ?>" <?= $tipo === $claveTipo ? 'selected' : ''; ?>>
            <?= htmlspecialchars($etiquetaTipo); ?>
        </option>
        <?php endforeach; ?>
    </select>
    <select name="plan" aria-label="Filtrar por plan" style="max-width:170px;">
        <option value="">Todos los planes</option>
        <?php foreach (PlanCuenta::catalogo() as $clavePlan => [$etiquetaPlan, , ]): ?>
        <option value="<?= htmlspecialchars($clavePlan); ?>" <?= $plan === $clavePlan ? 'selected' : ''; ?>>
            <?= htmlspecialchars($etiquetaPlan); ?>
        </option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn sm">Buscar</button>
    <?php if ($hayFiltro): ?>
    <a class="btn ghost sm" href="/admin/tenants">Limpiar</a>
    <span class="muted"><?= count($resumen); ?> de <?= (int) $totalCuentas; ?> cuentas</span>
    <?php endif; ?>
</form>

<?php
    // Etapas con boton "Revertir": indice en la barra de 6 -> columna
    // dte_emisor asociada (whitelist identica a la del handler). Indice 0
    // (Set Basico, se calcula de datos reales, no de una confirmacion) e
    // indice 5 (Autorizacion, comparte certificacion_confirmada_at con la
    // 5) NO llevan boton.
    $camposRevertiblesPorIndice = [
        1 => 'simulacion_confirmada_at',
        2 => 'intercambio_confirmado_at',
        3 => 'muestras_impresas_confirmadas_at',
        4 => 'certificacion_confirmada_at',
    ];
?>

<div class="panel">
<?php if ($resumen === []): ?>
<p class="muted" style="margin:0;">
    <?= $hayFiltro ? 'Ninguna cuenta coincide con la busqueda.' : 'No hay cuentas registradas.'; ?>
</p>
<?php else: ?>
<div class="tabla-scroll">
<table>
    <thead>
        <tr>
            <th>Cuenta</th>
            <th>Estado</th>
            <th>Tipo</th>
            <th>RUT(s) emisor</th>
            <th>Etapas de certificacion (factura)</th>
            <th>Produccion</th>
            <th>Acciones</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($resumen as $fila): ?>
        <?php $c = $fila['cuenta']; ?>
        <tr>
            <td>
                <a href="/admin/tenants/<?= (int) $c['id']; ?>"><?= htmlspecialchars((string) $c['nombre']); ?></a><br>
                <span class="muted" style="font-size:.85em;"><?= htmlspecialchars((string) $c['email']); ?></span>
            </td>
            <td>
                <span class="tag <?= $c['estado'] === 'activa' ? 'ok' : 'err'; ?>">
                    <?= htmlspecialchars(strtoupper((string) $c['estado'])); ?>
                </span>
            </td>
            <td>
                <?php
                    $tipoActual = (string) $c['tipo'];
                    $planActual = (string) $c['plan'];
                    $faltaPlan  = PlanCuenta::incoherente($tipoActual, $planActual);
                ?>
                <span class="<?= htmlspecialchars(TipoCuenta::clase($tipoActual)); ?>"
                      title="<?= htmlspecialchars(TipoCuenta::ayuda($tipoActual)); ?>">
                    <?= htmlspecialchars(TipoCuenta::etiqueta($tipoActual)); ?>
                </span>
                <div style="margin-top:.3rem;font-size:.78rem;<?= $faltaPlan ? 'color:var(--danger);' : 'color:var(--muted);'; ?>"
                     title="<?= htmlspecialchars($faltaPlan
                        ? 'Esta cuenta es comercial y no declara plan.'
                        : PlanCuenta::ayuda($planActual)); ?>">
                    <?php if ($faltaPlan): ?>
                    Sin plan declarado
                    <?php elseif ($planActual === 'ninguno'): ?>
                    <?php /* Una interna o la demo: no tener plan es lo correcto y no
                             merece tinta. Se deja el guion para que la celda no quede
                             desalineada respecto de las otras filas. */ ?>
                    &mdash;
                    <?php else: ?>
                    Plan <?= htmlspecialchars(PlanCuenta::etiqueta($planActual)); ?>
                    <?php endif; ?>
                </div>
            </td>
            <td>
                <?php if ($fila['emisores'] === []): ?>
                <span class="muted">(sin emisor)</span>
                <?php else: ?>
                    <?php foreach ($fila['emisores'] as $e): ?>
                    <div><?= htmlspecialchars($e['rutEmisor']); ?></div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($fila['emisores'] === []): ?>
                &mdash;
                <?php else: ?>
                    <?php foreach ($fila['emisores'] as $e): ?>
                    <div class="progreso-etapas--admin">
                        <?php foreach ($e['barra'] as $i => $etapa): ?>
                        <?php $campoRevertible = $camposRevertiblesPorIndice[$i] ?? null; ?>
                        <div class="etapa-circulo <?= $etapa['clase']; ?>" title="<?= htmlspecialchars($etapa['nombre']); ?>">
                            <?= $i + 1; ?>
                            <?php if ($campoRevertible !== null && $etapa['completada']): ?>
                            <form method="post" action="/admin/tenants/revertir-etapa" class="etapa-circulo__revertir-form"
                                  onsubmit="return confirm('Revertir la etapa &quot;<?= htmlspecialchars($etapa['nombre'], ENT_QUOTES); ?>&quot; para el RUT <?= htmlspecialchars($e['rutEmisor'], ENT_QUOTES); ?>? Esto es una correccion administrativa, NO algo rutinario.');">
                                <?= csrfInput(); ?>
                                <input type="hidden" name="rut_emisor" value="<?= htmlspecialchars($e['rutEmisor']); ?>">
                                <input type="hidden" name="campo" value="<?= htmlspecialchars($campoRevertible); ?>">
                                <button type="submit" class="etapa-circulo__revertir" title="Revertir <?= htmlspecialchars($etapa['nombre']); ?>">&times;</button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </td>
            <td style="font-size:.85em;">
                <?php if ($fila['emisores'] === []): ?>
                &mdash;
                <?php else: ?>
                    <?php foreach ($fila['emisores'] as $e): ?>
                    <div>
                        Cert: <?= $e['tieneCertProduccion'] ? '&#10003;' : '&mdash;'; ?>
                        &nbsp;CAF: <?= $e['tieneCafProduccion'] ? '&#10003;' : '&mdash;'; ?>
                        &nbsp;API key: <?= $fila['tieneApiKeyProduccion'] ? '&#10003;' : '&mdash;'; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </td>
            <td>
                <?php $idModal = 'acciones-cuenta-' . (int) $c['id']; ?>
                <button type="button" class="btn ghost sm abre-modal" data-modal="<?= htmlspecialchars($idModal); ?>">
                    Acciones
                </button>

                <dialog id="<?= htmlspecialchars($idModal); ?>" class="modal"
                        aria-labelledby="<?= htmlspecialchars($idModal); ?>-titulo">
                    <div class="modal__cab">
                        <div>
                            <h3 id="<?= htmlspecialchars($idModal); ?>-titulo"><?= htmlspecialchars((string) $c['nombre']); ?></h3>
                            <p>
                                <?= htmlspecialchars((string) $c['email']); ?>
                                &middot; cuenta #<?= (int) $c['id']; ?>
                                &middot; alta <?= htmlspecialchars(date('d-m-Y', strtotime((string) $c['created_at']))); ?>
                            </p>
                        </div>
                        <?php /* method="dialog" cierra sin una linea de JS: es el propio
                                 navegador el que lo hace. */ ?>
                        <form method="dialog" style="margin:0;">
                            <button class="modal__cerrar" aria-label="Cerrar">&times;</button>
                        </form>
                    </div>

                    <div class="modal__accion">
                        <h4>Clasificacion comercial</h4>
                        <p>
                            <strong>Tipo de cuenta:</strong> <?= htmlspecialchars(TipoCuenta::ayuda($tipoActual)); ?><br>
                            <strong>Plan contratado:</strong> <?= htmlspecialchars(PlanCuenta::ayuda($planActual)); ?>
                        </p>
                        <?php /* LOS DOS EJES EN UN SOLO SUBMIT: se deciden en el mismo
                                 momento y mirando lo mismo, y asi dejan una sola fila de
                                 auditoria para un unico acto. */ ?>
                        <?php /* CADA SELECTOR CON SU TITULO A LA VISTA, y no solo un
                                 aria-label: los dos empiezan diciendo "Sin definir" y sin
                                 el rotulo encima no hay forma de saber cual es cual. Un
                                 nombre accesible que solo escucha un lector de pantalla no
                                 resuelve el problema de quien esta mirando. El <label for>
                                 los deja ademas asociados de verdad, asi que apretar el
                                 texto abre su selector. */ ?>
                        <form method="post" action="/admin/tenants/clasificacion">
                            <?= csrfInput(); ?>
                            <input type="hidden" name="cuenta_id" value="<?= (int) $c['id']; ?>">
                            <label class="field" for="tipo-<?= (int) $c['id']; ?>">
                                <span>Tipo de cuenta</span>
                                <select name="tipo" id="tipo-<?= (int) $c['id']; ?>">
                                    <?php foreach (TipoCuenta::catalogo() as $claveTipo => [$etiquetaTipo, , $ayudaTipo]): ?>
                                    <option value="<?= htmlspecialchars($claveTipo); ?>"
                                            title="<?= htmlspecialchars($ayudaTipo); ?>"
                                        <?= $tipoActual === $claveTipo ? 'selected' : ''; ?>><?= htmlspecialchars($etiquetaTipo); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="field" for="plan-<?= (int) $c['id']; ?>">
                                <span>Plan contratado</span>
                                <select name="plan" id="plan-<?= (int) $c['id']; ?>">
                                    <?php foreach (PlanCuenta::catalogo() as $clavePlan => [$etiquetaPlan, , $ayudaPlan]): ?>
                                    <option value="<?= htmlspecialchars($clavePlan); ?>"
                                            title="<?= htmlspecialchars($ayudaPlan); ?>"
                                        <?= $planActual === $clavePlan ? 'selected' : ''; ?>><?= htmlspecialchars($etiquetaPlan); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <button type="submit" class="btn sm">Guardar</button>
                        </form>
                        <?php if ($faltaPlan): ?>
                        <p style="color:var(--danger);margin:.6rem 0 0;">
                            Esta cuenta cobra o esta evaluando y no declara plan.
                        </p>
                        <?php endif; ?>
                    </div>

                    <div class="modal__accion modal__accion--riesgo">
                        <h4>Estado del servicio</h4>
                        <?php if ($c['estado'] === 'activa'): ?>
                        <p>
                            Suspender corta el acceso de esta cuenta. Si esta emitiendo documentos
                            tributarios, deja de poder hacerlo.
                        </p>
                        <form method="post" action="/admin/tenants/suspender"
                              onsubmit="return confirm('Suspender <?= htmlspecialchars((string) $c['nombre'], ENT_QUOTES); ?>? Le corta el acceso al sistema.');">
                            <?= csrfInput(); ?>
                            <input type="hidden" name="cuenta_id" value="<?= (int) $c['id']; ?>">
                            <button type="submit" class="btn ghost sm">Suspender cuenta</button>
                        </form>
                        <?php else: ?>
                        <p>Esta cuenta esta suspendida: nadie puede entrar con ella.</p>
                        <form method="post" action="/admin/tenants/reactivar"
                              onsubmit="return confirm('Reactivar <?= htmlspecialchars((string) $c['nombre'], ENT_QUOTES); ?>?');">
                            <?= csrfInput(); ?>
                            <input type="hidden" name="cuenta_id" value="<?= (int) $c['id']; ?>">
                            <button type="submit" class="btn sm">Reactivar cuenta</button>
                        </form>
                        <?php endif; ?>
                    </div>

                    <div class="modal__accion">
                        <h4>Ir a</h4>
                        <p style="margin-bottom:0;">
                            <a href="/admin/tenants/<?= (int) $c['id']; ?>">Ficha completa de la cuenta</a>
                            &mdash; usuarios, emisores, certificados, folios y documentos emitidos.
                        </p>
                    </div>
                </dialog>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
</div>

<script>
// Abrir el modal de una fila. Todo lo demas -- Esc, el foco atrapado adentro, el
// backdrop, cerrar con la X -- lo hace el navegador: la X es un <form
// method="dialog"> y no necesita una linea de esto.
document.querySelectorAll('.abre-modal').forEach(function (boton) {
    var modal = document.getElementById(boton.dataset.modal);
    if (!modal) { return; }

    boton.addEventListener('click', function () { modal.showModal(); });

    // Clic en el fondo. El evento llega al propio <dialog> solo cuando se
    // apreto FUERA del contenido, asi que comparar el target alcanza para
    // distinguirlo de un clic adentro.
    modal.addEventListener('click', function (evento) {
        if (evento.target === modal) { modal.close(); }
    });
});
</script>

<?php require __DIR__ . '/partials/admin/footer.php'; ?>
