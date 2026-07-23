<?php
require __DIR__ . '/../vendor/autoload.php';

use Plantiflex\FacturacionCl\Pdf\BoletaPdfGenerator;

$gen = new BoletaPdfGenerator();
$dir = __DIR__ . '/../easyagenda/muestras_pdf';
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}

$fuentes = [
    __DIR__ . '/../envio_muestras_boletas_ea_debug.xml' => [116, 117, 118, 119, 120, 121, 122, 124, 125],
    __DIR__ . '/../envio_muestra_reemplazo_ea_debug.xml' => [126],
];

foreach ($fuentes as $archivo => $folios) {
    $xml = file_get_contents($archivo);
    if ($xml === false) {
        fwrite(STDERR, "No se pudo leer $archivo\n");
        exit(1);
    }
    foreach ($folios as $folio) {
        $pdf = $gen->generarDesdeEnvioXml($xml, 39, $folio);
        $out = $dir . '/muestra_boleta_39_' . $folio . '.pdf';
        file_put_contents($out, $pdf);
        echo $out . ' -> ' . strlen($pdf) . " bytes\n";
    }
}
