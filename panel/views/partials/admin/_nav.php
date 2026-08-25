<?php
/**
 * Sidebar del panel de control.
 *
 * Lista FIJA, no derivada de definicionMenu(). Aquel menu tiene dos ejes de
 * estado (construido / requiereProduccion) que existen porque el tenant puede
 * tener modulos que todavia no le sirven. Aqui no hay tal cosa: TODAS las
 * pantallas estan disponibles siempre para quien ya paso exigirSuperadmin(),
 * asi que un item es un enlace y nada mas.
 *
 * (Aqui decia "las N pantallas" y el numero se equivoco tres veces en una
 * tarde. Una cuenta escrita a mano al lado de una lista que crece envejece
 * sola, y lo que la frase quiere decir no depende de cuantas sean.)
 *
 * Los dos grupos son los del admin-web de Brewer Manager: PLATAFORMA es lo que
 * mira a los clientes, SISTEMA es lo que mira al producto.
 *
 * El item activo se marca por $adminActivo (clave que declara cada vista). Se
 * usa una clave explicita en vez de comparar contra la URL porque la ficha de
 * cuenta (/admin/tenants/{id}) tiene que iluminar "Cuentas", y una comparacion
 * por ruta no la reconoceria.
 */

$adminNavGrupos = [
    'Plataforma' => [
        'panel'         => ['/admin',              'Panel'],
        'cuentas'       => ['/admin/tenants',      'Cuentas'],
        'tareas'        => ['/admin/tareas',       'Tareas programadas'],
        'auditoria'     => ['/admin/auditoria',    'Auditoria'],
        'integraciones' => ['/admin/integraciones', 'Integraciones'],
    ],
    'Sistema' => [
        'base-datos'     => ['/admin/base-datos',     'Base de datos'],
        'roles-permisos' => ['/admin/roles-permisos', 'Roles y permisos'],
        'flujos'         => ['/admin/flujos',         'Flujos'],
        'documentos'     => ['/admin/documentos',     'Documentos'],
        'pendientes'     => ['/admin/pendientes',     'Pendientes'],
        'ideas'          => ['/admin/ideas',          'Ideas'],
        'changelog'      => ['/admin/changelog',      'Changelog'],
    ],
];

$adminNavActivo = isset($adminActivo) ? (string) $adminActivo : '';

// El interior de cada <svg>, por clave de item. El envoltorio va aqui abajo,
// una sola vez: asi el tamano y el grosor de linea son iguales para todos por
// construccion, no por acordarse.
$adminNavIconos = require __DIR__ . '/_iconos.php';
?>
<aside class="sidebar">
    <div class="brand"><span class="dot"></span> Sinergia &middot; Control</div>
    <nav class="nav" aria-label="Menu del panel de control">
        <?php foreach ($adminNavGrupos as $adminNavTitulo => $adminNavItems): ?>
        <div class="nav-group"><?= htmlspecialchars($adminNavTitulo); ?></div>
            <?php foreach ($adminNavItems as $adminNavClave => [$adminNavDestino, $adminNavLabel]): ?>
        <a href="<?= htmlspecialchars($adminNavDestino); ?>"
           class="<?= $adminNavClave === $adminNavActivo ? 'active' : ''; ?>"
           <?= $adminNavClave === $adminNavActivo ? 'aria-current="page"' : ''; ?>><?php
            if (isset($adminNavIconos[$adminNavClave])): ?><svg class="ico" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"
             aria-hidden="true" focusable="false"><?= $adminNavIconos[$adminNavClave]; ?></svg><?php
            endif; ?><span><?= htmlspecialchars($adminNavLabel); ?></span></a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>
</aside>
