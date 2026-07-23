<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Plantiflex\FacturacionCl\Dto\DatosEmisor;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Sii\BoletaEnvioBuilder;

const NS_SII = 'http://www.sii.cl/SiiDte';
const NS_XSI = 'http://www.w3.org/2001/XMLSchema-instance';

/** Boleta DTE falsa minima: solo lo que BoletaEnvioBuilder lee (TipoDTE en NS SiiDte). */
function fakeBoleta(int $tipo, int $folio): DOMDocument
{
    $d = new DOMDocument('1.0', 'UTF-8');
    $dte = $d->createElementNS(NS_SII, 'DTE');
    $d->appendChild($dte);
    $doc = $d->createElementNS(NS_SII, 'Documento');
    $doc->setAttribute('ID', "F{$folio}T{$tipo}");
    $dte->appendChild($doc);
    $enc = $d->createElementNS(NS_SII, 'Encabezado');
    $doc->appendChild($enc);
    $idDoc = $d->createElementNS(NS_SII, 'IdDoc');
    $enc->appendChild($idDoc);
    $idDoc->appendChild($d->createElementNS(NS_SII, 'TipoDTE', (string) $tipo));
    $idDoc->appendChild($d->createElementNS(NS_SII, 'Folio', (string) $folio));
    return $d;
}

$fallos = 0;
function check(string $label, bool $cond): void
{
    global $fallos;
    echo $cond ? "  [OK]   {$label}\n" : "  [FAIL] {$label}\n";
    if (! $cond) {
        $fallos++;
    }
}

$emisor = new DatosEmisor(
    rutEmisor: '77724622-4',
    razonSocial: 'PLANTILLAS ORTOPEDICAS SPA',
    giro: 'Fabricacion de plantillas ortopedicas',
    acteco: 477202,
    dirOrigen: 'Calle Falsa 123',
    cmnaOrigen: 'Valdivia',
    resolucionFecha: '2024-01-01',
    resolucionNumero: 1234,
);

$rutEnvia = '13520634-2';

// 3 boletas: 39, 41, 39 -> espera SubTotDTE [39=>2, 41=>1] (orden de 1a aparicion).
$boletas = [fakeBoleta(39, 11), fakeBoleta(41, 12), fakeBoleta(39, 13)];

$builder = new BoletaEnvioBuilder();
$dom = $builder->build($boletas, $emisor, $rutEnvia, Ambiente::Certificacion);
$root = $dom->documentElement;

echo "== Estructura del sobre ==\n";
check('Raiz es EnvioBOLETA', $root->localName === 'EnvioBOLETA');
check('Raiz en namespace SiiDte', $root->namespaceURI === NS_SII);
check('version=1.0 en raiz', $root->getAttribute('version') === '1.0');

$setDte = $dom->getElementsByTagNameNS(NS_SII, 'SetDTE')->item(0);
check('Existe SetDTE', $setDte !== null);
check('SetDTE ID=SetDoc', $setDte?->getAttribute('ID') === 'SetDoc');

$car = $dom->getElementsByTagNameNS(NS_SII, 'Caratula')->item(0);
check('Existe Caratula', $car !== null);
check('Caratula version=1.0', $car?->getAttribute('version') === '1.0');

$ordenCaratula = [];
foreach ($car->childNodes as $n) {
    if ($n->nodeType === XML_ELEMENT_NODE) {
        $ordenCaratula[] = $n->localName;
    }
}
$esperadoIni = ['RutEmisor', 'RutEnvia', 'RutReceptor', 'FchResol', 'NroResol', 'TmstFirmaEnv'];
check('Orden Caratula (6 campos fijos)', array_slice($ordenCaratula, 0, 6) === $esperadoIni);

echo "== Valores Caratula ==\n";
$val = function (string $tag) use ($dom): string {
    $n = $dom->getElementsByTagNameNS(NS_SII, $tag)->item(0);
    return $n === null ? '' : trim($n->textContent);
};
check('RutEmisor = emisor', $val('RutEmisor') === '77724622-4');
check('RutEnvia = firmante', $val('RutEnvia') === '13520634-2');
check('RutReceptor = SII 60803000-K', $val('RutReceptor') === '60803000-K');
check('NroResol = 0 en certificacion', $val('NroResol') === '0');
check('FchResol = emisor', $val('FchResol') === '2024-01-01');
check('TmstFirmaEnv ISO local', (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $val('TmstFirmaEnv')));

echo "== SubTotDTE (conteo y orden) ==\n";
$subs = [];
foreach ($dom->getElementsByTagNameNS(NS_SII, 'SubTotDTE') as $s) {
    $tpo = $nro = null;
    foreach ($s->childNodes as $c) {
        if ($c->nodeType !== XML_ELEMENT_NODE) {
            continue;
        }
        if ($c->localName === 'TpoDTE') {
            $tpo = trim($c->textContent);
        }
        if ($c->localName === 'NroDTE') {
            $nro = trim($c->textContent);
        }
    }
    $subs[] = [$tpo, $nro];
}
check('2 bloques SubTotDTE', count($subs) === 2);
check('1er SubTotDTE = 39 x2', ($subs[0] ?? null) === ['39', '2']);
check('2do SubTotDTE = 41 x1', ($subs[1] ?? null) === ['41', '1']);

echo "== Documentos importados ==\n";
check('3 DTE importados', $dom->getElementsByTagNameNS(NS_SII, 'DTE')->length === 3);
$primerHijoEl = null;
foreach ($setDte->childNodes as $n) {
    if ($n->nodeType === XML_ELEMENT_NODE) {
        $primerHijoEl = $n->localName;
        break;
    }
}
check('Caratula va antes que los DTE', $primerHijoEl === 'Caratula');

echo "== schemaLocation diferido ==\n";
check('Antes: SIN xsi:schemaLocation', $root->getAttributeNS(NS_XSI, 'schemaLocation') === '');
$builder->agregarSchemaLocation($dom);
check(
    'Despues: schemaLocation -> EnvioBOLETA_v11.xsd',
    $root->getAttributeNS(NS_XSI, 'schemaLocation') === 'http://www.sii.cl/SiiDte EnvioBOLETA_v11.xsd'
);

echo "\n--- XML resultante ---\n";
echo $dom->saveXML() . "\n\n";

echo $fallos === 0
    ? "RESULTADO: TODOS LOS CHECKS OK\n"
    : "RESULTADO: {$fallos} CHECK(S) FALLARON\n";
exit($fallos === 0 ? 0 : 1);
