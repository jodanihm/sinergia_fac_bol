<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Sii;

use DOMDocument;
use DOMElement;
use Plantiflex\FacturacionCl\Dto\DatosEmisor;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use RuntimeException;

/**
 * Construye el sobre <ConsumoFolios> (RVD / Registro de Ventas Diario, ex-RCOF)
 * para boletas: resumen diario obligatorio de folios por tipo de documento.
 *
 * Salida:
 *   <ConsumoFolios xmlns="http://www.sii.cl/SiiDte" version="1.0">
 *     <DocumentoConsumoFolios ID="RVD_AAAAMMDD_sec">
 *       <Caratula version="1.0">
 *         RutEmisor, RutEnvia, FchResol, NroResol, FchInicio, FchFinal,
 *         SecEnvio, TmstFirmaEnv
 *       </Caratula>
 *       <Resumen>+   (1..3, uno por tipo de documento)
 *     </DocumentoConsumoFolios>
 *   </ConsumoFolios>
 *
 * La firma del DocumentoConsumoFolios se agrega con XmlSigner (mismo patron que
 * el SetDTE). El xsi:schemaLocation se difiere (agregarSchemaLocation) para no
 * contaminar el C14N del nodo firmado. Se sube por el MISMO endpoint que la
 * boleta (BoletaUploader): el SII enruta por el elemento raiz.
 */
final class RvdBuilder
{
    private const NS_SII = 'http://www.sii.cl/SiiDte';
    private const NS_XSI = 'http://www.w3.org/2001/XMLSchema-instance';

    /**
     * @param list<array{
     *   tipoDocumento:int, mntNeto?:int, mntIva?:int, tasaIva?:int|float,
     *   mntExento?:int, mntTotal:int, foliosEmitidos:int, foliosAnulados:int,
     *   foliosUtilizados:int, rangos?:list<array{0:int,1:int}>
     * }> $resumenes  Un Resumen por tipo (39, 41, 61). Maximo 3.
     */
    public function build(
        DatosEmisor $emisor,
        string $rutEnvia,
        string $fecha,      // YYYY-MM-DD del dia (FchInicio = FchFinal)
        int $secEnvio,
        array $resumenes,
        Ambiente $ambiente,
    ): DOMDocument {
        if ($resumenes === []) {
            throw new RuntimeException('RvdBuilder: se requiere al menos un Resumen');
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        $root = $dom->createElementNS(self::NS_SII, 'ConsumoFolios');
        $root->setAttribute('version', '1.0');
        // xsi:schemaLocation se agrega DESPUES (agregarSchemaLocation), tras el
        // esqueleto de firma y antes del digest, para no contaminar el C14N del
        // DocumentoConsumoFolios (mismo motivo que en EnvioBOLETA).
        $dom->appendChild($root);

        $docCF = $dom->createElementNS(self::NS_SII, 'DocumentoConsumoFolios');
        $docCF->setAttribute('ID', 'RVD_' . str_replace('-', '', $fecha) . '_' . $secEnvio);
        $root->appendChild($docCF);

        $docCF->appendChild($this->buildCaratula($dom, $emisor, $rutEnvia, $fecha, $secEnvio, $ambiente));

        foreach ($resumenes as $r) {
            $docCF->appendChild($this->buildResumen($dom, $r));
        }

        return $dom;
    }

    public function agregarSchemaLocation(DOMDocument $doc): void
    {
        $root = $doc->documentElement;
        if ($root === null) {
            throw new RuntimeException('RvdBuilder::agregarSchemaLocation: documento sin root');
        }
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', self::NS_XSI);
        $root->setAttributeNS(self::NS_XSI, 'xsi:schemaLocation', 'http://www.sii.cl/SiiDte ConsumoFolio_v10.xsd');
    }

    private function buildCaratula(
        DOMDocument $dom,
        DatosEmisor $emisor,
        string $rutEnvia,
        string $fecha,
        int $secEnvio,
        Ambiente $ambiente,
    ): DOMElement {
        $car = $dom->createElementNS(self::NS_SII, 'Caratula');
        $car->setAttribute('version', '1.0');

        // En certificacion NroResol=0 (igual que en el sobre de boleta). La
        // evidencia esta en EnvioDteBuilder::buildCaratula().
        $nroResol = $ambiente === Ambiente::Certificacion ? 0 : $emisor->resolucionNumero;

        $car->appendChild($this->el($dom, 'RutEmisor', $emisor->rutEmisor));
        $car->appendChild($this->el($dom, 'RutEnvia', $rutEnvia));
        $car->appendChild($this->el($dom, 'FchResol', $emisor->resolucionFecha));
        $car->appendChild($this->el($dom, 'NroResol', (string) $nroResol));
        $car->appendChild($this->el($dom, 'FchInicio', $fecha));
        $car->appendChild($this->el($dom, 'FchFinal', $fecha));
        $car->appendChild($this->el($dom, 'SecEnvio', (string) $secEnvio));
        $car->appendChild($this->el($dom, 'TmstFirmaEnv', date('Y-m-d\TH:i:s')));

        return $car;
    }

    /**
     * @param array<string,mixed> $r
     */
    private function buildResumen(DOMDocument $dom, array $r): DOMElement
    {
        $res = $dom->createElementNS(self::NS_SII, 'Resumen');
        // Orden del esquema: TipoDocumento, MntNeto?, MntIva?, TasaIVA?,
        // MntExento?, MntTotal, FoliosEmitidos, FoliosAnulados, FoliosUtilizados,
        // RangoUtilizados*.
        $res->appendChild($this->el($dom, 'TipoDocumento', (string) $r['tipoDocumento']));
        if (isset($r['mntNeto'])) {
            $res->appendChild($this->el($dom, 'MntNeto', (string) $r['mntNeto']));
        }
        if (isset($r['mntIva'])) {
            $res->appendChild($this->el($dom, 'MntIva', (string) $r['mntIva']));
        }
        if (isset($r['tasaIva'])) {
            $res->appendChild($this->el($dom, 'TasaIVA', (string) $r['tasaIva']));
        }
        if (isset($r['mntExento'])) {
            $res->appendChild($this->el($dom, 'MntExento', (string) $r['mntExento']));
        }
        $res->appendChild($this->el($dom, 'MntTotal', (string) $r['mntTotal']));
        $res->appendChild($this->el($dom, 'FoliosEmitidos', (string) $r['foliosEmitidos']));
        $res->appendChild($this->el($dom, 'FoliosAnulados', (string) $r['foliosAnulados']));
        $res->appendChild($this->el($dom, 'FoliosUtilizados', (string) $r['foliosUtilizados']));

        foreach (($r['rangos'] ?? []) as $rango) {
            $ru = $dom->createElementNS(self::NS_SII, 'RangoUtilizados');
            $ru->appendChild($this->el($dom, 'Inicial', (string) $rango[0]));
            $ru->appendChild($this->el($dom, 'Final', (string) $rango[1]));
            $res->appendChild($ru);
        }

        return $res;
    }

    private function el(DOMDocument $dom, string $name, string $value): DOMElement
    {
        $el = $dom->createElementNS(self::NS_SII, $name);
        $el->appendChild($dom->createTextNode($value));
        return $el;
    }
}
