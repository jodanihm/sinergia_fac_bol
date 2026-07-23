<?php
require __DIR__ . '/../vendor/autoload.php';

use Plantiflex\FacturacionCl\Pdf\BoletaPdfGenerator;

$xml = file_get_contents(__DIR__ . '/../envio_set_debug_ea.xml');
if ($xml === false) {
    fwrite(STDERR, "No se pudo leer envio_set_debug_ea.xml\n");
    exit(1);
}

$gen = new BoletaPdfGenerator();
$dir = __DIR__ . '/../easyagenda/muestras_pdf';
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}

foreach ([111, 112, 113, 114, 115] as $folio) {
    $pdf = $gen->generarDesdeEnvioXml($xml, 39, $folio);
    $out = $dir . '/boleta_39_' . $folio . '.pdf';
    file_put_contents($out, $pdf);
    echo $out . ' -> ' . strlen($pdf) . " bytes\n";
}
