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

// Clase extra opcional para el <body>. Existe porque la regla global
// body { max-width: 640px; margin: 2rem auto } encierra el contenido en una
// columna estrecha, y una pantalla de acceso a ancho completo necesita
// anularla SIN tocar esa regla, que usan el resto de las vistas sin sesion
// (registro, activar-cuenta). La vista la declara antes del require; si no la
// declara, el <body> sale exactamente igual que antes.
$bodyClaseExtra = trim(($bodyClase ?? '') . ($panelAutenticado ? ' con-sidebar' : ''));

// Version de la hoja de estilos, para invalidar la cache del navegador.
//
// /css/style.css era una URL FIJA: un navegador o un proxy que tuviera guardada
// una version anterior la seguia sirviendo despues de un despliegue. Mientras
// los cambios de CSS fueron cosmeticos eso solo se veia "desactualizado"; con
// el login nuevo, que depende del CSS para su layout, un style.css viejo deja
// la pantalla rota (los <svg> sin dimensiones se estiran a pantalla completa).
//
// filemtime cambia en cada build de la imagen, asi que cada despliegue sirve
// una URL distinta y la cache se invalida sola. Si el archivo no se puede leer
// se cae a la URL sin parametro, que es el comportamiento anterior.
$cssRuta    = __DIR__ . '/../../public/css/style.css';
$cssVersion = @filemtime($cssRuta);
$cssHref    = '/css/style.css' . ($cssVersion ? '?v=' . $cssVersion : '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($titulo ?? 'Panel'); ?></title>
<link rel="stylesheet" href="<?= htmlspecialchars($cssHref); ?>">
</head>
<body class="<?= htmlspecialchars($bodyClaseExtra); ?>">
<?php if ($panelAutenticado): ?>
<a class="skip-link" href="#contenido">Saltar al contenido</a>
<?php require __DIR__ . '/_nav.php'; ?>
<?php endif; ?>
<main id="contenido" aria-label="Contenido principal">
<?php
/* AVISO DE VISTA DE SUPERADMIN.
   Va PRIMERO, antes que el de modo demostracion y antes que el contenido: es
   la unica pista de que lo que se ve abajo no es la cuenta propia. Sin el, un
   superadmin con una vista abierta y olvidada creeria estar mirando su panel y
   sacaria conclusiones sobre la empresa equivocada.
   Permanente y en todas las pantallas del tenant, por el mismo motivo: no
   alcanza con avisarlo al entrar. */
$vistaCuentaId = class_exists('Auth') ? Auth::viendoCuentaId() : null;
if ($panelAutenticado && $vistaCuentaId !== null):
    $vistaStmt = Db::conexion()->prepare('SELECT nombre FROM cuenta WHERE id = :id LIMIT 1');
    $vistaStmt->execute([':id' => $vistaCuentaId]);
    $vistaNombre = (string) ($vistaStmt->fetchColumn() ?: ('cuenta #' . $vistaCuentaId));
?>
<div class="aviso-vista-admin" role="status">
    <div>
        <strong>Vista de superadmin.</strong>
        Estas viendo el panel de <strong><?= htmlspecialchars($vistaNombre); ?></strong>
        (cuenta #<?= (int) $vistaCuentaId; ?>) en <strong>solo lectura</strong>:
        cualquier accion que modifique datos esta bloqueada.
    </div>
    <form method="post" action="/admin/tenants/salir-vista">
        <?= csrfInput(); ?>
        <button type="submit">Salir de la vista</button>
    </form>
</div>
<?php endif; ?>
<?php if ($panelAutenticado && sesionEsDemo()): ?>
<?php /* Aviso permanente de sesion de solo lectura. Va DENTRO de <main> y no
         sobre el <body>: el shell es flex y un hermano mas del <nav> le
         cambiaria el layout a las ~25 vistas. Aqui es simplemente el primer
         bloque del contenido, en todas.

         Existe porque el bloqueo del router aparece recien DESPUES de hacer
         clic (ver cortarPorDemo()), y en una demostracion en vivo enterarse ahi
         llega tarde. Esto lo dice antes, sin apagar ningun boton: la gracia de
         la cuenta es que se vea el sistema entero, botones incluidos. */ ?>
<p class="aviso-demo" role="status">
    <strong>Modo demostracion.</strong>
    Sesion de solo lectura: puedes recorrer todas las pantallas, pero las acciones
    que emiten documentos, cargan credenciales o modifican datos estan desactivadas.
</p>
<?php endif; ?>
