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
 *
 * Accesibilidad: <main> lleva id y aria-label para ser un landmark
 * identificable, y con sesion se antepone un "skip link" que permite saltarse
 * el menu lateral navegando con teclado (WCAG 2.4.1). El skip link solo se ve
 * cuando recibe foco.
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
<?php if ($panelAutenticado): ?>
<a class="skip-link" href="#contenido">Saltar al contenido</a>
<?php require __DIR__ . '/_nav.php'; ?>
<?php endif; ?>
<main id="contenido" aria-label="Contenido principal">
