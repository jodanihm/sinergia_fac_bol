<?php
declare(strict_types=1);
/**
 * Genera muestras impresas (PDF con timbre PDF417) de los DTE de un EnvioDTE
 * usando el generador SII-compliant de LibreDTE (Sii\PDF\Dte) + TCPDF.
 * USO: php scripts/generar_muestras_pdf.php <envio.xml> <dir_salida> [todos|porTipo]
 *   todos   -> un PDF por cada documento (set de pruebas)
 *   porTipo -> un PDF por cada TIPO (set de simulacion)
 * Para facturas (33/34/46/52/43) genera ademas la copia cedible.
 */
date_default_timezone_set('America/Santiago');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

require_once __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';
$base = __DIR__ . '/../oracle/LibreDTE-master/lib';
spl_autoload_register(function ($class) use ($base) {
    $p = 'sasco\\LibreDTE\\';
    if (strncmp($class, $p, strlen($p)) !== 0) return;
    $f = $base . '/' . str_replace('\\', '/', substr($class, strlen($p))) . '.php';
    if (is_file($f)) require $f;
});

use sasco\LibreDTE\Sii\EnvioDte;
use sasco\LibreDTE\Sii\PDF\Dte as PDFDte;

function fail(string $m): never { fwrite(STDERR, "ERROR: $m\n"); exit(1); }

$archivo = $argv[1] ?? fail('Uso: generar_muestras_pdf.php <envio.xml> <dir_salida> [todos|porTipo]');
$dir     = $argv[2] ?? 'muestras';
$modo    = $argv[3] ?? 'todos';
if (! is_file($archivo)) fail("no existe $archivo");
if (! is_dir($dir)) mkdir($dir, 0777, true);

$E = new EnvioDte();
$E->loadXML(file_get_contents($archivo));
$C = $E->getCaratula();
$D = $E->getDocumentos();
if (! $D) fail('no se pudieron leer documentos del envio');

$resol = ['FchResol' => $C['FchResol'], 'NroResol' => $C['NroResol']];
$conCedible = [33, 34, 46, 52, 43];
$prefijo = pathinfo($archivo, PATHINFO_FILENAME);

$vistos = [];
$total = 0;
foreach ($D as $dte) {
    $tipo = (int) $dte->getTipo();
    if ($modo === 'porTipo') {
        if (isset($vistos[$tipo])) continue;
        $vistos[$tipo] = true;
    }
    $datos = $dte->getDatos();
    if (! $datos) { fwrite(STDERR, "sin datos en un DTE, salto\n"); continue; }
    $folio = $datos['Encabezado']['IdDoc']['Folio'];
    $ted = $dte->getTED();

    $pdf = new PDFDte();
    $pdf->setResolucion($resol);
    $pdf->agregar($datos, $ted);
    $out = sprintf('%s/%s_T%d_F%s.pdf', $dir, $prefijo, $tipo, $folio);
    $pdf->Output($out, 'F');
    echo 'OK ' . $out . ' (' . filesize($out) . " B)\n";
    $total++;

    if (in_array($tipo, $conCedible, true)) {
        $pc = new PDFDte();
        $pc->setResolucion($resol);
        $pc->setCedible(true);
        $pc->agregar($datos, $ted);
        $outc = sprintf('%s/%s_T%d_F%s_cedible.pdf', $dir, $prefijo, $tipo, $folio);
        $pc->Output($outc, 'F');
        echo 'OK ' . $outc . ' (' . filesize($outc) . " B)\n";
        $total++;
    }
}
echo "Total: $total PDFs en $dir/\n";
