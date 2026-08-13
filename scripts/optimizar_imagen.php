<?php

declare(strict_types=1);

/**
 * Genera la copia liviana de una imagen PNG del panel, con gd.
 *
 * POR QUE EXISTE
 * -----------------------------------------------------------------------------
 * panel/public/img/sinergin.png pesa 1,16 MB y se muestra a 112 px. Se descarga
 * ENTERO en cada carga de la pantalla del chat: mas de un megabyte para pintar
 * algo del tamaño de una moneda.
 *
 * NO SE TOCA EL ORIGINAL. Queda en el repositorio como FUENTE -- es de donde sale
 * cualquier tamaño futuro, y perderlo obligaria a pedirle el arte a alguien otra
 * vez. Lo que sirve el HTML es la copia.
 *
 * POR QUE gd Y NO ImageMagick: ImageMagick no esta en la imagen del panel; gd si,
 * la instala docker/Dockerfile.panel. Se usa lo que hay.
 *
 * ES REPETIBLE Y NO DESTRUCTIVO: se puede volver a correr cuando el arte cambie.
 * Solo escribe el archivo de salida.
 *
 * COMO SE CORRE
 *   docker exec sinergia_panel php /app/scripts/optimizar_imagen.php
 *
 * Con argumentos, para otra imagen:
 *   docker exec sinergia_panel php /app/scripts/optimizar_imagen.php <origen> <destino> <ancho>
 */

$RAIZ = dirname(__DIR__);

// Por defecto, el caso que motivo el script. Los tres son sobreescribibles.
$origen  = $argv[1] ?? $RAIZ . '/panel/public/img/sinergin.png';
$destino = $argv[2] ?? $RAIZ . '/panel/public/img/sinergin-240.png';

// 240 = EL DOBLE DE LOS 112 QUE SE MUESTRAN. En una pantalla de densidad 2x --
// cualquier portatil o telefono moderno -- un archivo de 112 px se veria borroso;
// con 240 se ve nitido y sigue siendo una fraccion del original.
//
// EL NOMBRE LLEVA 240 Y NO 112 a proposito: dice el ancho REAL del archivo. Un
// "sinergin-112.png" que midiera 240 px seria una trampa para el que venga
// despues y lo use creyendo que mide lo que dice.
$ancho = (int) ($argv[3] ?? 240);

function fallar(string $m): never
{
    fwrite(STDERR, "ERROR: {$m}\n");
    exit(1);
}

if (! extension_loaded('gd')) {
    fallar('la extension gd no esta cargada. Corre esto DENTRO del contenedor del '
        . 'panel: docker exec sinergia_panel php /app/scripts/optimizar_imagen.php');
}
if (! is_file($origen) || ! is_readable($origen)) {
    fallar("no se puede leer el origen: {$origen}");
}
if ($ancho < 16 || $ancho > 4096) {
    fallar("ancho fuera de rango razonable: {$ancho}");
}

$info = getimagesize($origen);
if ($info === false || ($info[2] ?? null) !== IMAGETYPE_PNG) {
    fallar("el origen no es un PNG: {$origen}");
}
[$anchoOrig, $altoOrig] = $info;

$src = imagecreatefrompng($origen);
if ($src === false) {
    fallar('gd no pudo abrir el PNG de origen.');
}

// EL ALTO SALE DE LA PROPORCION, no de un numero escrito a mano: esta imagen no
// es cuadrada, y forzarla lo seria deformar al mascote.
$alto = (int) round($ancho * $altoOrig / $anchoOrig);

$dst = imagecreatetruecolor($ancho, $alto);
if ($dst === false) {
    fallar('gd no pudo crear el lienzo de destino.');
}

// LOS TRES PASOS DE LA TRANSPARENCIA, Y LOS TRES HACEN FALTA.
//
// Sin ellos, gd rellena el lienzo de NEGRO y el mascote sale sobre un cuadrado
// negro -- que sobre el celeste de la tarjeta se ve como un error de despliegue,
// no como una imagen mal generada.
//
//   alphablending(false)  al copiar, el alfa del origen REEMPLAZA al del destino
//                         en vez de mezclarse con el.
//   savealpha(true)       el canal alfa se ESCRIBE en el archivo.
//   relleno 127           127 es transparencia total en gd (0 es opaco).
imagealphablending($dst, false);
imagesavealpha($dst, true);
$transparente = imagecolorallocatealpha($dst, 0, 0, 0, 127);
if ($transparente === false) {
    fallar('gd no pudo reservar el color transparente.');
}
imagefilledrectangle($dst, 0, 0, $ancho, $alto, $transparente);

// RESAMPLED Y NO RESIZED: interpola en vez de descartar pixeles. En una
// reduccion de 1240 a 240 la diferencia entre uno y otro es evidente en los
// bordes curvos del casco.
if (! imagecopyresampled($dst, $src, 0, 0, 0, 0, $ancho, $alto, $anchoOrig, $altoOrig)) {
    fallar('imagecopyresampled fallo.');
}

// 9 = MAXIMA COMPRESION. En PNG es SIN PERDIDA: comprimir mas no degrada la
// imagen, solo tarda un poco mas en generarla. Y esto se genera una vez.
if (! imagepng($dst, $destino, 9)) {
    fallar("no se pudo escribir el destino: {$destino}");
}

imagedestroy($src);
imagedestroy($dst);

clearstatcache(true, $destino);
$pesoOrig = (int) filesize($origen);
$pesoNuevo = (int) filesize($destino);

printf("origen  : %s\n          %d x %d px, %s\n", $origen, $anchoOrig, $altoOrig, pesar($pesoOrig));
printf("destino : %s\n          %d x %d px, %s\n", $destino, $ancho, $alto, pesar($pesoNuevo));

if ($pesoOrig > 0) {
    printf("\nreduccion: %.1f%% (%s menos)\n",
        100 - ($pesoNuevo * 100 / $pesoOrig), pesar($pesoOrig - $pesoNuevo));
}
if ($pesoNuevo >= $pesoOrig) {
    fwrite(STDERR, "\nAVISO: la copia NO es mas liviana que el original. No la uses.\n");
    exit(1);
}

// LA PROPORCION NO ES CUADRADA, asi que los atributos del <img> tampoco pueden
// serlo: se imprimen ya calculados para el ancho de despliegue. Un width/height
// con la proporcion equivocada hace que el navegador reserve mal el espacio y la
// tarjeta pegue un salto cuando la imagen carga.
$anchoCss = 112;
printf("\nPara el <img> (mostrado a %d px):  width=\"%d\" height=\"%d\"\n",
    $anchoCss, $anchoCss, (int) round($anchoCss * $altoOrig / $anchoOrig));

function pesar(int $bytes): string
{
    return $bytes >= 1048576
        ? sprintf('%.2f MB', $bytes / 1048576)
        : sprintf('%.1f KB', $bytes / 1024);
}
