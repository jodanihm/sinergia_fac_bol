<?php
/**
 * Layout base del PANEL DE CONTROL (superadmin).
 *
 * NO reutiliza partials/header.php, y el motivo es estructural, no de estilo:
 * aquel incluye partials/_nav.php, que se construye desde definicionMenu() y
 * pregunta por estadoEmisionProduccion(Auth::cuentaId()) -- el estado de LA
 * cuenta de la sesion. En este panel esa pregunta no tiene respuesta util: el
 * superadmin no esta mirando su cuenta, esta mirando todas. El sidebar de alla
 * pintaria "Sin configurar" sobre modulos que aqui no significan nada.
 *
 * Tampoco muestra el aviso de modo demostracion: un usuario demo nunca llega
 * hasta aca (exigirSuperadmin() corta antes con 403), asi que el bloque seria
 * codigo muerto que igual habria que mantener.
 *
 * Variables que espera la vista:
 *   $titulo      texto del <title>.
 *   $adminActivo clave del item del sidebar a marcar como activo (ver _nav.php).
 *
 * Cache-busting del CSS por filemtime, mismo patron que partials/header.php:
 * /css/admin.css seria una URL fija y un navegador o un proxy con la version
 * anterior guardada la seguiria sirviendo despues de un despliegue.
 */

$adminCssRuta    = __DIR__ . '/../../../public/css/admin.css';
$adminCssVersion = @filemtime($adminCssRuta);
$adminCssHref    = '/css/admin.css' . ($adminCssVersion ? '?v=' . $adminCssVersion : '');

// Email del superadmin de la sesion, para la topbar. Se lee aqui y no se le
// pide a cada handler porque es identico en las 11 pantallas: pasarlo por
// parametro seria repetir la misma linea once veces y que la duodecima se
// olvide. Es una consulta por clave primaria del PROPIO usuario -- no cruza el
// limite de tenant y no necesita el privilegio de superadmin para ser correcta.
$adminStmtEmail = Db::conexion()->prepare('SELECT email FROM usuario WHERE id = :id LIMIT 1');
$adminStmtEmail->execute([':id' => Auth::usuarioId()]);
$adminEmail = (string) ($adminStmtEmail->fetchColumn() ?: '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($titulo ?? 'Panel de control'); ?></title>
<link rel="stylesheet" href="<?= htmlspecialchars($adminCssHref); ?>">
</head>
<body>
<a class="skip-link" href="#contenido">Saltar al contenido</a>
<div class="shell">
<?php require __DIR__ . '/_nav.php'; ?>
    <div class="main">
        <header class="topbar">
            <div class="who">
                <?= htmlspecialchars($adminEmail); ?>
                <span class="tag warn">superadmin</span>
            </div>
            <div class="acciones">
                <a class="btn ghost sm" href="/panel">Volver al panel</a>
                <a class="btn ghost sm" href="/logout">Cerrar sesion</a>
            </div>
        </header>
        <main class="content" id="contenido" aria-label="Contenido principal">
