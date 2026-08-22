<?php
/**
 * Sidebar del panel de control.
 *
 * Lista FIJA, no derivada de definicionMenu(). Aquel menu tiene dos ejes de
 * estado (construido / requiereProduccion) que existen porque el tenant puede
 * tener modulos que todavia no le sirven. Aqui no hay tal cosa: las 11
 * pantallas estan disponibles siempre para quien ya paso exigirSuperadmin(),
 * asi que un item es un enlace y nada mas.
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
        'panel'     => ['/admin',           'Panel'],
        'cuentas'   => ['/admin/tenants',   'Cuentas'],
        'auditoria' => ['/admin/auditoria', 'Auditoria'],
    ],
    'Sistema' => [
        'base-datos'     => ['/admin/base-datos',     'Base de datos'],
        'roles-permisos' => ['/admin/roles-permisos', 'Roles y permisos'],
        'flujos'         => ['/admin/flujos',         'Flujos'],
        'documentos'     => ['/admin/documentos',     'Documentos'],
        'pendientes'     => ['/admin/pendientes',     'Pendientes e ideas'],
        'changelog'      => ['/admin/changelog',      'Changelog'],
    ],
];

$adminNavActivo = isset($adminActivo) ? (string) $adminActivo : '';
?>
<aside class="sidebar">
    <div class="brand"><span class="dot"></span> Sinergia &middot; Control</div>
    <nav class="nav" aria-label="Menu del panel de control">
        <?php foreach ($adminNavGrupos as $adminNavTitulo => $adminNavItems): ?>
        <div class="nav-group"><?= htmlspecialchars($adminNavTitulo); ?></div>
            <?php foreach ($adminNavItems as $adminNavClave => [$adminNavDestino, $adminNavLabel]): ?>
        <a href="<?= htmlspecialchars($adminNavDestino); ?>"
           class="<?= $adminNavClave === $adminNavActivo ? 'active' : ''; ?>"
           <?= $adminNavClave === $adminNavActivo ? 'aria-current="page"' : ''; ?>><?= htmlspecialchars($adminNavLabel); ?></a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>
</aside>
