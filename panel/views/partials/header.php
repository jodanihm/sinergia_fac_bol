<?php
/**
 * Layout base del panel.
 *
 * Con sesion activa envuelve el contenido en un shell con menu lateral
 * (partials/_nav.php); el <body> lleva la clase "con-sidebar" que activa el
 * layout flex. Sin sesion (login/registro) usa el layout simple centrado de
 * siempre, sin menu.
 *
 * El nav se hereda automaticamente en las ~25 vistas que hacen
 * require de este partial: no hay que tocar cada vista.
 */
$panelAutenticado = class_exists('Auth') && Auth::autenticado();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($titulo ?? 'Panel'); ?></title>
<link rel="stylesheet" href="/css/style.css">
</head>
<body class="<?= $panelAutenticado ? 'con-sidebar' : ''; ?>">
<?php if ($panelAutenticado) { require __DIR__ . '/_nav.php'; } ?>
<main>
