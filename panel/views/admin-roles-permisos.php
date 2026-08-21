<?php
/**
 * Roles y permisos (GET /admin/roles-permisos). Solo lectura.
 *
 * Cuatro bloques que mezclan dos fuentes a proposito: el concepto y el catalogo
 * salen del CODIGO, los roles salen de la BASE, y la cobertura vuelve al
 * codigo. Un permiso no es lo que dice una fila: es lo que exigen las rutas.
 *
 * Los textos del bloque 1 son los de la migracion 042_rol_permiso.sql y los del
 * bloque de PERMISOS_RUTA en el front controller, resumidos pero no
 * reescritos: ahi ya estaban explicados y mejor.
 */
$titulo      = 'Roles y permisos';
$adminActivo = 'roles-permisos';
require __DIR__ . '/partials/admin/header.php';
?>

<h2 class="page-title">Roles y permisos</h2>

<!-- ================= BLOQUE 1: EL CONCEPTO ================= -->
<div class="panel">
    <h3>Como se decide si alguien puede entrar a una pantalla</h3>
    <div class="flow-diagram">
        <div class="flow-node">Usuario</div>
        <span class="flow-arrow">&rarr;</span>
        <div class="flow-node">usuario.rol<br><small>que ES</small></div>
        <span class="flow-arrow">&rarr;</span>
        <div class="flow-node">usuario.rol_id<br><small>que PUEDE HACER</small></div>
        <span class="flow-arrow">&rarr;</span>
        <div class="flow-node">permisos<br><small>modulo:accion</small></div>
        <span class="flow-arrow">&rarr;</span>
        <div class="flow-node">rutas</div>
    </div>

    <div class="rp-cols">
        <div class="rp-note panel" style="margin:0;">
            <h4>Dos columnas de rol, y conviven a proposito</h4>
            <p><code>usuario.rol</code> &mdash; <strong>QUE ES</strong> este usuario en la cuenta.
            <code>owner</code> y <code>superadmin</code> <strong>bypasean el gate entero</strong>.
            Es una propiedad estructural.</p>
            <p><code>usuario.rol_id</code> &mdash; <strong>QUE PUEDE HACER</strong> un colaborador.
            Configurable, es dato. Es <code>NULL</code> justamente por eso: un owner o un
            superadmin no necesitan rol asignado, y tenerlo vacio no es un dato faltante,
            es lo correcto.</p>
        </div>

        <div class="rp-note panel" style="margin:0;">
            <h4>Administrar roles NO es un permiso del catalogo</h4>
            <p>Y no es un olvido. Un permiso configurable que permitiera editar roles seria
            una escalada de privilegios en un paso: el colaborador se edita su propio rol,
            se marca <code>certificacion:emitir</code>, y ya esta.</p>
            <p>Por eso <code>/configuracion/roles</code> y <code>/configuracion/usuarios</code>
            usan <code>exigirOwner()</code>, y <code>/admin/*</code> usa
            <code>exigirSuperadmin()</code>. Los tres exigen <strong>mas</strong> que cualquier
            permiso configurable, no menos.</p>
        </div>

        <div class="rp-note panel" style="margin:0;">
            <h4>El olvido cierra, no abre</h4>
            <p>La relacion ruta &rarr; permiso vive en una tabla y el despachador la consulta
            por ruta: <strong>una ruta que no este listada no pasa</strong>.</p>
            <p>Es la diferencia deliberada con el panel hermano, donde el permiso se declara
            con un decorador sobre el handler y el guard dice literalmente
            <code>if (!requerido) return true</code>: alli un endpoint sin decorador pasa
            igual. Aca el olvido cierra, que es la unica direccion segura del error.</p>
        </div>

        <div class="rp-note panel" style="margin:0;">
            <h4>El aislamiento va en <code>rol</code>, no en <code>permiso</code></h4>
            <p>Si <code>cuenta_id</code> estuviera en las dos tablas podrian contradecirse.
            La cuenta se resuelve siempre por el JOIN
            <code>usuario &rarr; rol &rarr; permiso</code>, con el filtro puesto en
            <code>rol</code>, que es el unico dueno del dato.</p>
        </div>
    </div>

    <p class="muted" style="margin-bottom:0;">
        Criterio de la accion: <strong>ver</strong> = GET que solo muestra &middot;
        <strong>gestionar</strong> = POST que toca datos nuestros &middot;
        <strong>emitir</strong> = POST que manda al SII y <strong>quema folios</strong>.
    </p>
</div>

<!-- ================= BLOQUE 2: EL CATALOGO REAL ================= -->
<div class="panel">
    <h3>El catalogo, y que habilita de verdad cada permiso</h3>
    <p class="muted" style="margin-top:-.5rem;">
        Sale del <strong>codigo</strong>, no de la base: se cuentan las rutas que exigen cada
        par en <code>PERMISOS_RUTA</code> y <code>PERMISOS_RUTA_PATRON</code>. Es la respuesta
        a "que habilita de verdad <code>ventas:emitir</code>". Un par con cero rutas se puede
        conceder, pero hoy no abre ninguna pantalla.
    </p>
    <div class="tabla-scroll">
    <table class="rp-matrix">
        <thead>
            <tr>
                <th>Modulo</th>
                <?php foreach (CATALOGO_ACCIONES as $accion): ?>
                <th><?= htmlspecialchars($accion); ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach (CATALOGO_MODULOS as $modulo): ?>
            <tr>
                <td><code><?= htmlspecialchars($modulo); ?></code></td>
                <?php foreach (CATALOGO_ACCIONES as $accion): ?>
                <?php $rutas = $catalogo[$modulo][$accion] ?? []; ?>
                <td>
                    <?php if ($rutas === []): ?>
                    <span class="rp-cell none">&middot;</span>
                    <?php else: ?>
                    <details>
                        <summary class="rp-cell full"><?= count($rutas); ?></summary>
                        <div class="rp-rutas">
                            <?php foreach ($rutas as $r): ?>
                            <div><code><?= htmlspecialchars($r); ?></code></div>
                            <?php endforeach; ?>
                        </div>
                    </details>
                    <?php endif; ?>
                </td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- ================= BLOQUE 3: LOS ROLES REALES ================= -->
<div class="panel">
    <h3>Los roles configurados, en todas las cuentas</h3>

    <div class="chips" style="margin-bottom:1rem;">
        <?php foreach ($usuariosPorTipo as $t): ?>
        <span class="chip"><?= (int) $t['n']; ?> <?= htmlspecialchars((string) $t['rol']); ?></span>
        <?php endforeach; ?>
        <span class="chip"><?= (int) $totalRoles; ?> roles definidos</span>
    </div>

    <p class="muted" style="margin-top:-.5rem;">
        Esta matriz gobierna <strong>solo a los colaboradores</strong>. Los owner y los
        superadmin se saltan el gate entero por su <code>usuario.rol</code>, asi que no
        aparecen aqui y ningun permiso de esta tabla los limita.
        <?php if ($colaboradoresSinRol > 0): ?>
        <br><span style="color:var(--pk);"><?= (int) $colaboradoresSinRol; ?>
        colaborador<?= $colaboradoresSinRol === 1 ? '' : 'es'; ?> sin rol asignado</span>:
        sin <code>rol_id</code> no tienen ningun permiso, asi que hoy no pueden entrar a
        ninguna pantalla del gate.
        <?php endif; ?>
    </p>

    <?php if ($rolesPorCuenta === []): ?>
    <p class="muted" style="margin:0;">Ninguna cuenta definio roles todavia. Todos sus usuarios son owner.</p>
    <?php else: ?>
        <?php foreach ($rolesPorCuenta as $cuentaId => $datos): ?>
        <details class="rp-cuenta">
            <summary>
                <?= htmlspecialchars($datos['nombre']); ?>
                <span class="muted">&middot; <?= count($datos['roles']); ?>
                    rol<?= count($datos['roles']) === 1 ? '' : 'es'; ?></span>
                <a href="/admin/tenants/<?= (int) $cuentaId; ?>" class="muted" style="font-size:.8rem;">ficha &rarr;</a>
            </summary>
            <div class="tabla-scroll">
            <table class="rp-matrix">
                <thead>
                    <tr>
                        <th>Modulo</th>
                        <?php foreach ($datos['roles'] as $rol): ?>
                        <th>
                            <?= htmlspecialchars((string) $rol['nombre']); ?><br>
                            <span class="muted" style="font-weight:400;text-transform:none;">
                                <?= (int) $rol['usuarios']; ?> usuario<?= ((int) $rol['usuarios']) === 1 ? '' : 's'; ?>
                            </span>
                        </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach (CATALOGO_MODULOS as $modulo): ?>
                    <?php $usadas = $accionesUsadas[$modulo] ?? []; ?>
                    <tr>
                        <td>
                            <code><?= htmlspecialchars($modulo); ?></code>
                            <?php if ($usadas === []): ?>
                            <span class="muted" style="font-size:.7rem;">(sin rutas)</span>
                            <?php endif; ?>
                        </td>
                        <?php foreach ($datos['roles'] as $rol): ?>
                        <?php
                            $concedidas = $rol['permisos'][$modulo] ?? [];
                            // "Todo" se mide contra las acciones que alguna ruta
                            // exige de verdad para este modulo, no contra las 3
                            // del catalogo: un modulo donde ninguna ruta pide
                            // 'emitir' no puede exigir ese permiso para estar
                            // completo.
                            $faltan = array_diff($usadas, $concedidas);
                            if ($concedidas === []) {
                                $celda = ['none', '&middot;', 'Ningun permiso de este modulo'];
                            } elseif ($usadas !== [] && $faltan === []) {
                                $celda = ['full', '&#9679; Todo', 'Todas las acciones que alguna ruta exige: ' . implode(', ', $usadas)];
                            } else {
                                $celda = ['ver', '&#9680; Parcial', 'Tiene: ' . implode(', ', $concedidas)
                                    . ($faltan !== [] ? '. Le falta: ' . implode(', ', $faltan) : '')];
                            }
                        ?>
                        <td>
                            <span class="rp-cell <?= $celda[0]; ?>" title="<?= htmlspecialchars($celda[2]); ?>">
                                <?= $celda[1]; ?>
                            </span>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </details>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- ================= BLOQUE 4: COBERTURA DEL GATE ================= -->
<div class="panel">
    <h3>Cobertura del gate</h3>
    <p class="muted" style="margin-top:-.5rem;">
        Cruce entre las <?= (int) $cobertura['total']; ?> rutas que el router despacha de
        verdad &mdash; leidas de su propio codigo fuente &mdash; y lo que esta declarado.
    </p>

    <div class="chips" style="margin-bottom:1rem;">
        <span class="chip"><?= (int) ($cobertura['conteo']['permiso'] ?? 0); ?> con permiso declarado</span>
        <span class="chip"><?= (int) ($cobertura['conteo']['gate_propio'] ?? 0); ?> con gate propio</span>
        <span class="chip"><?= (int) ($cobertura['conteo']['publica'] ?? 0); ?> publicas</span>
        <?php $sinDeclarar = (int) ($cobertura['conteo']['sin_declarar'] ?? 0); ?>
        <span class="tag <?= $sinDeclarar > 0 ? 'err' : 'ok'; ?>"><?= $sinDeclarar; ?> sin declarar</span>
    </div>

    <div class="rp-note panel" style="margin-bottom:1rem;">
        <h4>Esto no es una lista de agujeros de seguridad</h4>
        <p>Se leyo <code>exigirPermisoDeRuta()</code> para escribir esto. Su paso 4 hace
        <code>error_log()</code>, <code>http_response_code(404)</code> y <code>exit</code>:
        una ruta no declarada <strong>no pasa</strong>, la peticion termina ahi y ningun
        handler llega a ejecutarse. El gate falla cerrado.</p>
        <p>Lo que esta lista muestra es el efecto secundario incomodo de esa decision
        correcta: la ruta no declarada devuelve un <strong>404 comun</strong>,
        indistinguible de un enlace viejo o de un typo. No se rompe avisando que falta
        declararla &mdash; se rompe <strong>en silencio</strong>, y solo deja rastro en el
        <code>error_log</code> del servidor. El riesgo que se reporta aqui es de
        funcionalidad rota sin que nadie se entere, no de acceso indebido.</p>
    </div>

    <div class="tabla-scroll">
    <table>
        <thead><tr><th>Metodo</th><th>Ruta</th><th>Cobertura</th><th>Declarada en</th></tr></thead>
        <tbody>
        <?php foreach ($cobertura['rutas'] as $r): ?>
            <?php
                $tag = match ($r['estado']) {
                    'permiso'      => 'tag ok',
                    'gate_propio'  => 'tag',
                    'publica'      => 'tag',
                    'indeterminado' => 'tag warn',
                    default        => 'tag err',
                };
                $etiqueta = match ($r['estado']) {
                    'permiso'      => 'permiso',
                    'gate_propio'  => 'gate propio',
                    'publica'      => 'publica',
                    'indeterminado' => 'indeterminado',
                    default        => 'SIN DECLARAR',
                };
            ?>
            <tr>
                <td><?= htmlspecialchars($r['metodo']); ?></td>
                <td>
                    <code style="font-size:.82em;"><?= htmlspecialchars($r['ruta']); ?></code>
                    <?php if ($r['esPatron']): ?><span class="badge fk">regex</span><?php endif; ?>
                </td>
                <td><span class="<?= $tag; ?>"><?= htmlspecialchars($etiqueta); ?></span></td>
                <td class="muted" style="font-size:.82em;"><?= htmlspecialchars($r['detalle']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php require __DIR__ . '/partials/admin/footer.php'; ?>
