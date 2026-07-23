<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use DOMDocument;
use DOMElement;
use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Dto\DatosEmisor;
use Plantiflex\FacturacionCl\Dto\Detalle;
use Plantiflex\FacturacionCl\Dto\DocumentoTributario;
use Plantiflex\FacturacionCl\Dto\Receptor;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Sii\DteXmlBuilder;
use Plantiflex\FacturacionCl\Sii\EnvioDteBuilder;

final class EnvioDteBuilderTest extends TestCase
{
    private const NS_SII = 'http://www.sii.cl/SiiDte';

    private function emisor(int $resolucionNumero = 99): DatosEmisor
    {
        return new DatosEmisor(
            rutEmisor:        '77724622-4',
            razonSocial:      'Plantiflex SpA',
            giro:             'Venta de plantas',
            acteco:           477310,
            dirOrigen:        'Av Siempre Viva 123',
            cmnaOrigen:       'Santiago',
            resolucionFecha:  '2024-01-01',
            resolucionNumero: $resolucionNumero,
        );
    }

    private function dte(TipoDte $tipo, int $folio): DOMDocument
    {
        $doc = new DocumentoTributario(
            tipoDte: $tipo,
            receptor: new Receptor('99999999-9', 'Cliente'),
            detalles: [new Detalle('item', 1, 1000)],
        );
        return (new DteXmlBuilder())->build($doc, $this->emisor(), $folio);
    }

    private function texto(DOMDocument $dom, string $tag): ?string
    {
        $n = $dom->getElementsByTagNameNS(self::NS_SII, $tag)->item(0);
        return $n === null ? null : trim((string) $n->textContent);
    }

    private function childText(DOMElement $parent, string $tag): string
    {
        return trim((string) $parent->getElementsByTagNameNS(self::NS_SII, $tag)->item(0)->textContent);
    }

    public function testAgrupaSubTotDtePorTipoDeDte(): void
    {
        $docs = [
            $this->dte(TipoDte::FacturaElectronica, 1),
            $this->dte(TipoDte::FacturaElectronica, 2),
            $this->dte(TipoDte::NotaCreditoElectronica, 1),
        ];

        $dom = (new EnvioDteBuilder())->build($docs, $this->emisor(), '60803000-K', '11111111-1', Ambiente::Certificacion);

        $subs = $dom->getElementsByTagNameNS(self::NS_SII, 'SubTotDTE');
        self::assertSame(2, $subs->length, 'Dos tipos distintos => dos SubTotDTE');

        $sub0 = $subs->item(0);
        self::assertInstanceOf(DOMElement::class, $sub0);
        self::assertSame('33', $this->childText($sub0, 'TpoDTE'));
        self::assertSame('2', $this->childText($sub0, 'NroDTE'));

        $sub1 = $subs->item(1);
        self::assertInstanceOf(DOMElement::class, $sub1);
        self::assertSame('61', $this->childText($sub1, 'TpoDTE'));
        self::assertSame('1', $this->childText($sub1, 'NroDTE'));

        // Los 3 DTE quedaron importados dentro del SetDTE.
        self::assertSame(3, $dom->getElementsByTagNameNS(self::NS_SII, 'DTE')->length);
    }

    public function testCaratulaTieneDatosCorrectos(): void
    {
        // Emisor con resolucion real de produccion (99).
        $dom = (new EnvioDteBuilder())->build(
            [$this->dte(TipoDte::FacturaElectronica, 1)],
            $this->emisor(99),
            '60803000-K',
            '11111111-1',
            Ambiente::Certificacion,
        );

        self::assertSame('77724622-4', $this->texto($dom, 'RutEmisor'));
        self::assertSame('11111111-1', $this->texto($dom, 'RutEnvia'));
        self::assertSame('60803000-K', $this->texto($dom, 'RutReceptor'));
        self::assertSame('2024-01-01', $this->texto($dom, 'FchResol'));
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/',
            (string) $this->texto($dom, 'TmstFirmaEnv'),
        );
    }

    public function testCertificacionFuerzaNroResolCero(): void
    {
        // Emisor con resolucion 99 (produccion real), pero ambiente certificacion.
        $dom = (new EnvioDteBuilder())->build(
            [$this->dte(TipoDte::FacturaElectronica, 1)],
            $this->emisor(99),
            '60803000-K',
            '11111111-1',
            Ambiente::Certificacion,
        );

        self::assertSame('0', $this->texto($dom, 'NroResol'), 'En certificacion NroResol debe ser 0');
        self::assertSame('2024-01-01', $this->texto($dom, 'FchResol'), 'FchResol no cambia');
    }

    public function testProduccionUsaNroResolReal(): void
    {
        $dom = (new EnvioDteBuilder())->build(
            [$this->dte(TipoDte::FacturaElectronica, 1)],
            $this->emisor(99),
            '60803000-K',
            '11111111-1',
            Ambiente::Produccion,
        );

        self::assertSame('99', $this->texto($dom, 'NroResol'), 'En produccion se usa el NroResol real del emisor');
    }

    public function testEnvioDteTieneNamespaceYSetDocId(): void
    {
        $dom = (new EnvioDteBuilder())->build(
            [$this->dte(TipoDte::FacturaElectronica, 1)],
            $this->emisor(),
            '60803000-K',
            '11111111-1',
            Ambiente::Certificacion,
        );

        self::assertSame(self::NS_SII, $dom->documentElement->namespaceURI);
        self::assertSame('EnvioDTE', $dom->documentElement->localName);
        self::assertSame('1.0', $dom->documentElement->getAttribute('version'));

        // build() NO agrega xmlns:xsi ni schemaLocation: se agregan despues via
        // agregarSchemaLocation(), una vez firmado el Documento, para evitar DTE-3-505.
        $xml = (string) $dom->saveXML();
        self::assertStringNotContainsString('xmlns:xsi', $xml);
        self::assertStringNotContainsString('xsi:schemaLocation', $xml);

        $setDte = $dom->getElementsByTagNameNS(self::NS_SII, 'SetDTE')->item(0);
        self::assertInstanceOf(DOMElement::class, $setDte);
        self::assertSame('SetDoc', $setDte->getAttribute('ID'));
    }

    public function testAgregarSchemaLocationPoneXsiEnRoot(): void
    {
        $builder = new EnvioDteBuilder();
        $dom = $builder->build(
            [$this->dte(TipoDte::FacturaElectronica, 1)],
            $this->emisor(),
            '60803000-K',
            '11111111-1',
            Ambiente::Certificacion,
        );

        // Antes: sin xsi.
        $xmlAntes = (string) $dom->saveXML();
        self::assertStringNotContainsString('xmlns:xsi', $xmlAntes);

        $builder->agregarSchemaLocation($dom);

        // Despues: con xsi y schemaLocation correctos.
        $xmlDespues = (string) $dom->saveXML();
        self::assertStringContainsString('xmlns:xsi', $xmlDespues);
        self::assertStringContainsString('xsi:schemaLocation="http://www.sii.cl/SiiDte EnvioDTE_v10.xsd"', $xmlDespues);

        // Namespace xsi correcto.
        $root = $dom->documentElement;
        self::assertNotNull($root);
        self::assertSame(
            'http://www.w3.org/2001/XMLSchema-instance',
            $root->getAttributeNS('http://www.w3.org/2000/xmlns/', 'xsi'),
        );
    }
}
