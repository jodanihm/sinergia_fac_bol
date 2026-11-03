<?php
/**
 * Skeleton de arranque de TODAS las pantallas con sesion: lo incluyen las dos
 * cabeceras (partials/header.php y partials/admin/header.php). El aspecto esta en
 * css/arranque.css y el del clic en el menu en js/arranque-pantalla.js.
 *
 * SE VE SIEMPRE Y AL MENOS 1 SEGUNDO, aunque todo haya cargado antes. ES UNA
 * EXCEPCION PEDIDA A LA SECCION 14 del design system (no mostrar nada bajo 300 ms,
 * 400 ms de permanencia): el dueno del producto lo pidio el 03-11-2026, primero en
 * la factura y despues en todo el software. No "corregirla" sin preguntarle.
 *
 * Sin JavaScript no pasa nada. Con POST (el 422 que vuelve con errores) tampoco: lo
 * primero que hay que ver ahi es el error, y ya se esta en la pantalla.
 *
 * El formulario esta en la pagina y funciona durante el skeleton: el foco que pone
 * una vista al cargar (autofocus, el campo con error) llega igual.
 */
$arranqueCss = @filemtime(__DIR__ . '/../../public/css/arranque.css');
$arranqueJs  = @filemtime(__DIR__ . '/../../public/js/arranque-pantalla.js');
?>
<link rel="stylesheet" href="/css/arranque.css<?= $arranqueCss ? '?v=' . $arranqueCss : ''; ?>">
<script src="/js/arranque-pantalla.js<?= $arranqueJs ? '?v=' . $arranqueJs : ''; ?>" defer></script>
<?php if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'): ?>
<script>
(function () {
    var raiz = document.documentElement, inicio = null, terminado = false;
    raiz.classList.add('pantalla-arrancando');
    function contenido() { return document.getElementById('contenido'); }
    // El segundo se cuenta desde que el skeleton se pinta. Este script va en el
    // <head>: contar desde aqui regalaria el tiempo en que aun no hay nada.
    function esperarPintado() {
        var m = contenido();
        if (!m || !m.firstElementChild) { requestAnimationFrame(esperarPintado); return; }
        m.setAttribute('aria-busy', 'true');
        requestAnimationFrame(function () { inicio = performance.now(); });
    }
    requestAnimationFrame(esperarPintado);
    function fin() {
        if (terminado) { return; }
        terminado = true;
        raiz.classList.remove('pantalla-arrancando');
        raiz.classList.add('pantalla-revelada');
        var m = contenido();
        if (m) { m.removeAttribute('aria-busy'); }
        setTimeout(function () { raiz.classList.remove('pantalla-revelada'); }, 300);
    }
    function listo() {
        if (inicio === null) { requestAnimationFrame(listo); return; }
        setTimeout(fin, Math.max(0, 1000 - (performance.now() - inicio)));
    }
    document.addEventListener('DOMContentLoaded', listo);
    // Pase lo que pase, la pantalla no se queda en skeleton.
    setTimeout(fin, 8000);
})();
</script>
<?php endif; ?>
