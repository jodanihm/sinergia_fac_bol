<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Sii;

use DOMDocument;
use DOMElement;
use Plantiflex\FacturacionCl\Dto\DatosEmisor;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use RuntimeException;

/**
 * Construye el sobre <EnvioDTE> que agrupa uno o mas DTE individuales para
 * enviar al SII.
 *
 * Salida:
 *   <EnvioDTE xmlns="http://www.sii.cl/SiiDte" version="1.0">
 *     <SetDTE ID="SetDoc">
 *       <Caratula version="1.0">
 *         <RutEmisor/> <RutEnvia/> <RutReceptor/> <FchResol/> <NroResol/>
 *         <TmstFirmaEnv/> <SubTotDTE>+
 *       </Caratula>
 *       <DTE/>+   (importados, en orden)
 *     </SetDTE>
 *   </EnvioDTE>
 *
 * La firma del SetDTE se agrega despues con XmlSigner::firmarNodo().
 */
final class EnvioDteBuilder
{
    private const NS_SII = 'http://www.sii.cl/SiiDte';
    private const NS_XSI = 'http://www.w3.org/2001/XMLSchema-instance';

    /**
     * @param list<DOMDocument> $documentosDte DTE ya construidos por DteXmlBuilder.
     */
    public function build(
        array $documentosDte,
        DatosEmisor $emisor,
        string $rutReceptor,
        string $rutEnvia,
        Ambiente $ambiente,
    ): DOMDocument {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        $envio = $dom->createElementNS(self::NS_SII, 'EnvioDTE');
        $envio->setAttribute('version', '1.0');
        // xmlns:xsi / xsi:schemaLocation se agregan DESPUES via agregarSchemaLocation(),
        // una vez firmado el <Documento>. Si estuvieran aqui, C14N los heredaria en el
        // <Documento> canonicalizado, produciendo un digest distinto al del SII (DTE-3-505).
        $dom->appendChild($envio);

        $setDte = $dom->createElementNS(self::NS_SII, 'SetDTE');
        $setDte->setAttribute('ID', 'SetDoc'); // ID estandar SII.
        $envio->appendChild($setDte);

        $setDte->appendChild(
            $this->buildCaratula($dom, $documentosDte, $emisor, $rutReceptor, $rutEnvia, $ambiente),
        );

        // Importar cada DTE en orden, despues de la Caratula.
        foreach ($documentosDte as $dteDoc) {
            if (! $dteDoc->documentElement instanceof DOMElement) {
                throw new RuntimeException('EnvioDteBuilder: un DTE provisto no tiene documentElement');
            }
            $setDte->appendChild($dom->importNode($dteDoc->documentElement, true));
        }

        return $dom;
    }

    /**
     * Agrega xmlns:xsi y xsi:schemaLocation al root <EnvioDTE>.
     *
     * Debe llamarse DESPUES de firmar el/los <Documento> y ANTES de firmar el
     * <SetDTE>. Si se agregaran antes de la firma del Documento, C14N inclusivo
     * los heredaria en el subtree canonicalizado del Documento, alterando su
     * DigestValue respecto al que calcula el SII (DTE-3-505). El SII exige la
     * declaracion en el root para validar el schema (SCH-00001 sin ella).
     */
    public function agregarSchemaLocation(DOMDocument $envioDoc): void
    {
        $root = $envioDoc->documentElement;
        if ($root === null) {
            throw new \RuntimeException('EnvioDteBuilder::agregarSchemaLocation: documento sin root');
        }
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', self::NS_XSI);
        $root->setAttributeNS(self::NS_XSI, 'xsi:schemaLocation', 'http://www.sii.cl/SiiDte EnvioDTE_v10.xsd');
    }

    /**
     * @param list<DOMDocument> $documentosDte
     */
    private function buildCaratula(
        DOMDocument $dom,
        array $documentosDte,
        DatosEmisor $emisor,
        string $rutReceptor,
        string $rutEnvia,
        Ambiente $ambiente,
    ): DOMElement {
        $car = $dom->createElementNS(self::NS_SII, 'Caratula');
        $car->setAttribute('version', '1.0');

        // En CERTIFICACION el SII exige NroResol=0 (no el numero real de
        // produccion); de lo contrario rechaza con CRT-3-19. En produccion se
        // usa el numero real del emisor. La FchResol va igual en ambos casos.
        $nroResol = $ambiente === Ambiente::Certificacion ? 0 : $emisor->resolucionNumero;

        $car->appendChild($this->el($dom, 'RutEmisor', $emisor->rutEmisor));
        $car->appendChild($this->el($dom, 'RutEnvia', $rutEnvia));
        $car->appendChild($this->el($dom, 'RutReceptor', $rutReceptor));
        $car->appendChild($this->el($dom, 'FchResol', $emisor->resolucionFecha));
        $car->appendChild($this->el($dom, 'NroResol', (string) $nroResol));
        // TmstFirmaEnv: ISO 8601 local sin zona (YYYY-MM-DDTHH:MM:SS).
        $car->appendChild($this->el($dom, 'TmstFirmaEnv', date('Y-m-d\TH:i:s')));

        // Un SubTotDTE por cada tipo de DTE presente, en orden de aparicion.
        foreach ($this->contarPorTipo($documentosDte) as $tipo => $cantidad) {
            $sub = $dom->createElementNS(self::NS_SII, 'SubTotDTE');
            $sub->appendChild($this->el($dom, 'TpoDTE', (string) $tipo));
            $sub->appendChild($this->el($dom, 'NroDTE', (string) $cantidad));
            $car->appendChild($sub);
        }

        return $car;
    }

    /**
     * @param list<DOMDocument> $documentosDte
     * @return array<int,int> tipoDte => cantidad, en orden de primera aparicion
     */
    private function contarPorTipo(array $documentosDte): array
    {
        $conteo = [];
        foreach ($documentosDte as $dteDoc) {
            $tipo = $this->extraerTipoDte($dteDoc);
            $conteo[$tipo] = ($conteo[$tipo] ?? 0) + 1;
        }
        return $conteo;
    }

    private function extraerTipoDte(DOMDocument $dteDoc): int
    {
        $nodos = $dteDoc->getElementsByTagNameNS(self::NS_SII, 'TipoDTE');
        if ($nodos->length === 0 || $nodos->item(0) === null) {
            throw new RuntimeException('EnvioDteBuilder: DTE sin <TipoDTE>');
        }
        return (int) $nodos->item(0)->textContent;
    }

    private function el(DOMDocument $dom, string $name, string $value): DOMElement
    {
        $el = $dom->createElementNS(self::NS_SII, $name);
        $el->appendChild($dom->createTextNode($value));
        return $el;
    }
}
