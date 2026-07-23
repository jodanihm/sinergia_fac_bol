<?php
// Router para el servidor embebido de PHP (php -S).
// Sin esto, URLs que "parecen archivo" (ej. terminan en .xml) se resuelven
// buscando un archivo fisico en vez de pasar por index.php, dando 404 aunque
// la ruta este bien definida en el router de la aplicacion.
$path = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$archivo = __DIR__ . '/public' . $path;
if ($path !== '/' && is_file($archivo)) {
    return false; // deja que el servidor sirva el archivo estatico tal cual
}
require __DIR__ . '/public/index.php';
