<?php
declare(strict_types=1);
require __DIR__ . '/../src/Sii/EnvioDteParser.php';
use Plantiflex\FacturacionCl\Sii\EnvioDteParser;

$path = $argv[1] ?? 'envio_set_basico_lote.xml';
$p = EnvioDteParser::fromFile($path);
echo "NmbEnvio : {$p->nmbEnvio}\n";
echo "SetDTE ID: {$p->setDteId}\n";
echo "Digest   : " . ($p->digest !== '' ? $p->digest : '(vacio)') . "\n";
echo "Caratula :\n";
foreach ($p->caratula as $k => $v) echo "  $k = $v\n";
echo "Documentos (" . count($p->documentos) . "):\n";
foreach ($p->documentos as $i => $d) {
    echo sprintf("  [%d] T%s F%s %s  emisor=%s recep=%s total=%s\n",
        $i + 1, $d['TipoDTE'], $d['Folio'], $d['FchEmis'], $d['RUTEmisor'], $d['RUTRecep'], $d['MntTotal']);
}
