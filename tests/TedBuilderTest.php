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
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Sii\DteXmlBuilder;
use Plantiflex\FacturacionCl\Sii\TedBuilder;

final class TedBuilderTest extends TestCase
{
    private const NS_SII = 'http://www.sii.cl/SiiDte';

    private function emisor(): DatosEmisor
    {
        return new DatosEmisor(
            rutEmisor:        '77724622-4',
            razonSocial:      'Plantiflex SpA',
            giro:             'Venta de plantas',
            acteco:           477310,
            dirOrigen:        'Av Siempre Viva 123',
            cmnaOrigen:       'Santiago',
            resolucionFecha:  '2024-01-01',
            resolucionNumero: 0,
        );
    }

    private function dd(DOMDocument $dteDoc): DOMElement
    {
        // El DD NO esta en el namespace SiiDte (namespace vacio).
        // El DD esta en el namespace SiiDte (elementFormDefault="qualified").
        $dd = $dteDoc->getElementsByTagNameNS(self::NS_SII, 'DD')->item(0);
        self::assertInstanceOf(DOMElement::class, $dd);
        return $dd;
    }

    /** Valor del primer hijo DIRECTO del DD con ese nombre (evita colisiones con el CAF). */
    private function campo(DOMElement $dd, string $tag): ?string
    {
        foreach ($dd->childNodes as $c) {
            if ($c instanceof DOMElement && $c->localName === $tag) {
                return trim((string) $c->textContent);
            }
        }
        return null;
    }

    public function testTedTieneDdCompletoEnNamespaceSiiYFrmtValida(): void
    {
        $caf = CafDePrueba::generar('77724622-4', 33, 1, 100);
        if ($caf === null) {
            self::markTestSkipped('No se pudo generar par RSA (falta openssl.cnf)');
        }

        $doc = new DocumentoTributario(
            tipoDte: TipoDte::FacturaElectronica,
            receptor: new Receptor('99999999-9', 'Empresa Cliente'),
            detalles: [new Detalle('Servicio profesional', 1, 50000)],
            montosSonBrutos: false,
        );

        $dteDoc = (new DteXmlBuilder())->build($doc, $this->emisor(), 5);
        (new TedBuilder())->build($dteDoc, $doc, $this->emisor(), 5, $caf['xml']);

        // El TED esta dentro del Documento.
        $documento = $dteDoc->getElementsByTagNameNS(self::NS_SII, 'Documento')->item(0);
        self::assertInstanceOf(DOMElement::class, $documento);

        // El TED y el DD estan en el namespace SiiDte (elementFormDefault="qualified").
        self::assertSame(1, $dteDoc->getElementsByTagNameNS(self::NS_SII, 'TED')->length);
        self::assertSame(1, $dteDoc->getElementsByTagNameNS(self::NS_SII, 'DD')->length);

        // NO debe haber xmlns="" en el TED.
        $xml = (string) $dteDoc->saveXML();
        self::assertDoesNotMatchRegularExpression('/<TED[^>]*xmlns=""/', $xml);
        self::assertStringNotContainsString('default:', $xml);

        // TmstFirma presente dentro del Documento, en SiiDte, despues del TED.
        self::assertSame(1, $documento->getElementsByTagNameNS(self::NS_SII, 'TmstFirma')->length);
        $hijos = [];
        foreach ($documento->childNodes as $c) {
            if ($c instanceof DOMElement) {
                $hijos[] = $c->localName;
            }
        }
        $posTed  = array_search('TED', $hijos, true);
        $posTmst = array_search('TmstFirma', $hijos, true);
        self::assertIsInt($posTed);
        self::assertIsInt($posTmst);
        self::assertGreaterThan($posTed, $posTmst, 'TmstFirma debe ir despues del TED');

        // DD completo.
        $dd = $this->dd($dteDoc);
        self::assertSame('77724622-4', $this->campo($dd, 'RE'));
        self::assertSame('33', $this->campo($dd, 'TD'));
        self::assertSame('5', $this->campo($dd, 'F'));
        self::assertNotNull($this->campo($dd, 'FE'));
        self::assertSame('99999999-9', $this->campo($dd, 'RR'));
        self::assertSame('Empresa Cliente', $this->campo($dd, 'RSR'));
        self::assertSame('59500', $this->campo($dd, 'MNT')); // 50000 + 19%
        self::assertSame('Servicio profesional', $this->campo($dd, 'IT1'));
        self::assertNotNull($this->campo($dd, 'TSTED'));

        // CAF dentro del DD.
        self::assertGreaterThan(0, $dd->getElementsByTagName('CAF')->length);

        // FRMT presente, base64, algoritmo correcto.
        $frmt = $dteDoc->getElementsByTagNameNS(self::NS_SII, 'FRMT')->item(0);
        self::assertInstanceOf(DOMElement::class, $frmt);
        self::assertSame('SHA1withRSA', $frmt->getAttribute('algoritmo'));
        $frmtBin = base64_decode(trim((string) $frmt->textContent), true);
        self::assertIsString($frmtBin);
        self::assertNotSame('', $frmtBin);

        $pub = openssl_pkey_get_public($caf['rsapubk']);
        self::assertNotFalse($pub);

        // El FRMT NO se firma sobre C14N: el SII valida el timbre sobre el DD
        // serializado SIN el xmlns SiiDte heredado, sin xsi, aplanado (sin
        // espacios entre tags) y en ISO-8859-1. Replicamos esa forma exacta.
        $ddXml = (string) $dteDoc->saveXML($dd);
        $ddXml = (string) preg_replace('/ xmlns="http:\/\/www\.sii\.cl\/SiiDte"/', '', $ddXml, 1);
        $ddXml = (string) preg_replace('/ xmlns:xsi="[^"]*"/', '', $ddXml, 1);
        $ddXml = (string) preg_replace('/ xsi:schemaLocation="[^"]*"/', '', $ddXml, 1);
        $ddXml = (string) preg_replace('/>\s+</', '><', $ddXml);
        $canonical = mb_convert_encoding($ddXml, 'ISO-8859-1', 'UTF-8');

        self::assertSame(
            1,
            openssl_verify($canonical, $frmtBin, $pub, OPENSSL_ALGO_SHA1),
            'El FRMT debe ser firma valida del DD (forma timbre: sin xmlns, aplanado, ISO-8859-1) con la llave del CAF',
        );

        // El C14N del DD ahora SI esta en el namespace SiiDte.
        self::assertStringContainsString(self::NS_SII, $dd->C14N());
    }

    public function testRsrEIt1SeTruncanA40Chars(): void
    {
        $caf = CafDePrueba::generar('77724622-4', 33, 1, 100);
        if ($caf === null) {
            self::markTestSkipped('No se pudo generar par RSA (falta openssl.cnf)');
        }

        $razonLarga = str_repeat('A', 60);
        $itemLargo  = str_repeat('B', 55);

        $doc = new DocumentoTributario(
            tipoDte: TipoDte::FacturaElectronica,
            receptor: new Receptor('99999999-9', $razonLarga),
            detalles: [new Detalle($itemLargo, 1, 1000)],
            montosSonBrutos: false,
        );

        $dteDoc = (new DteXmlBuilder())->build($doc, $this->emisor(), 7);
        (new TedBuilder())->build($dteDoc, $doc, $this->emisor(), 7, $caf['xml']);

        $dd  = $this->dd($dteDoc);
        $rsr = (string) $this->campo($dd, 'RSR');
        $it1 = (string) $this->campo($dd, 'IT1');

        self::assertSame(40, mb_strlen($rsr), 'RSR debe truncarse a 40 chars');
        self::assertSame(40, mb_strlen($it1), 'IT1 debe truncarse a 40 chars');
        self::assertSame(str_repeat('A', 40), $rsr);
        self::assertSame(str_repeat('B', 40), $it1);
    }
}
