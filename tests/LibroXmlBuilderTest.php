<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Dto\Certificado;
use Plantiflex\FacturacionCl\Dto\DatosEmisor;
use Plantiflex\FacturacionCl\Dto\Libro;
use Plantiflex\FacturacionCl\Dto\LineaLibro;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Enums\TipoEnvioLibro;
use Plantiflex\FacturacionCl\Enums\TipoLibro;
use Plantiflex\FacturacionCl\Enums\TipoOperacionLibro;
use Plantiflex\FacturacionCl\Exceptions\DocumentoInvalidoException;
use Plantiflex\FacturacionCl\Sii\LibroXmlBuilder;
use Plantiflex\FacturacionCl\Sii\XmlSigner;

final class LibroXmlBuilderTest extends TestCase
{
    private const NS_SII  = 'http://www.sii.cl/SiiDte';
    private const NS_DSIG = 'http://www.w3.org/2000/09/xmldsig#';

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
            resolucionNumero: 99,
        );
    }

    private function texto(DOMDocument $dom, string $tag): ?string
    {
        $n = $dom->getElementsByTagNameNS(self::NS_SII, $tag)->item(0);
        return $n === null ? null : trim((string) $n->textContent);
    }

    private function hijo(DOMElement $padre, string $tag): string
    {
        $n = $padre->getElementsByTagNameNS(self::NS_SII, $tag)->item(0);
        return $n === null ? '' : trim((string) $n->textContent);
    }

    /** @return list<string> nombres locales de los hijos directos. */
    private function ordenHijos(DOMElement $el): array
    {
        $out = [];
        foreach ($el->childNodes as $c) {
            if ($c instanceof DOMElement) {
                $out[] = $c->localName;
            }
        }
        return $out;
    }

    private function libroVenta(): Libro
    {
        return new Libro(
            tipoOperacion: TipoOperacionLibro::Venta,
            periodoTributario: '2026-05',
            tipoLibro: TipoLibro::Especial,
            tipoEnvio: TipoEnvioLibro::Total,
            folioNotificacion: 1,
            lineas: [
                new LineaLibro(33, 1, new DateTimeImmutable('2026-05-10'), '99999999-9', 'Cliente A', 0, 10000, 1900, 11900),
                new LineaLibro(33, 2, new DateTimeImmutable('2026-05-12'), '88888888-8', 'Cliente B', 0, 20000, 3800, 23800),
                new LineaLibro(61, 5, new DateTimeImmutable('2026-05-15'), '99999999-9', 'Cliente A', 0, 5000, 950, 5950),
            ],
        );
    }

    public function testVentaResumenYDetalleConOrdenDelManual(): void
    {
        $dom = (new LibroXmlBuilder())->build($this->libroVenta(), $this->emisor(), '13520634-2', Ambiente::Certificacion);

        // Caratula (certif -> NroResol=0).
        self::assertSame('VENTA', $this->texto($dom, 'TipoOperacion'));
        self::assertSame('0', $this->texto($dom, 'NroResol'));

        // ResumenPeriodo: 2 TotalesPeriodo (33 y 61).
        $totales = $dom->getElementsByTagNameNS(self::NS_SII, 'TotalesPeriodo');
        self::assertSame(2, $totales->length);

        $tp33 = $totales->item(0);
        self::assertInstanceOf(DOMElement::class, $tp33);
        // Orden VENTA: TpoDoc, TotDoc, TotMntExe, TotMntNeto, TotMntIVA, TotMntTotal.
        self::assertSame(
            ['TpoDoc', 'TotDoc', 'TotMntExe', 'TotMntNeto', 'TotMntIVA', 'TotMntTotal'],
            $this->ordenHijos($tp33),
        );
        self::assertSame('33', $this->hijo($tp33, 'TpoDoc'));
        self::assertSame('2', $this->hijo($tp33, 'TotDoc'));
        self::assertSame('30000', $this->hijo($tp33, 'TotMntNeto'));
        self::assertSame('5700', $this->hijo($tp33, 'TotMntIVA'));
        self::assertSame('35700', $this->hijo($tp33, 'TotMntTotal'));

        // Venta NO lleva TpoImp en el resumen.
        self::assertSame(0, $dom->getElementsByTagNameNS(self::NS_SII, 'TpoImp')->length);

        // Detalle VENTA: orden del manual.
        $detalle = $dom->getElementsByTagNameNS(self::NS_SII, 'Detalle')->item(0);
        self::assertInstanceOf(DOMElement::class, $detalle);
        self::assertSame(
            ['TpoDoc', 'NroDoc', 'TasaImp', 'FchDoc', 'RUTDoc', 'RznSoc', 'MntExe', 'MntNeto', 'MntIVA', 'MntTotal'],
            $this->ordenHijos($detalle),
        );
        self::assertSame(3, $dom->getElementsByTagNameNS(self::NS_SII, 'Detalle')->length);
    }

    public function testCompraConIvaUsoComunPoneFctPropEnResumenNoEnDetalle(): void
    {
        $libro = new Libro(
            tipoOperacion: TipoOperacionLibro::Compra,
            periodoTributario: '2026-05',
            tipoLibro: TipoLibro::Especial,
            tipoEnvio: TipoEnvioLibro::Total,
            folioNotificacion: 1,
            lineas: [
                new LineaLibro(
                    tpoDoc: 33,
                    nroDoc: 100,
                    fecha: new DateTimeImmutable('2026-05-08'),
                    rutContraparte: '55555555-5',
                    razonSocial: 'Proveedor X',
                    mntExe: 0,
                    mntNeto: 100000,
                    mntIva: 19000,
                    mntTotal: 119000,
                    ivaUsoComun: 19000,
                ),
            ],
            factorProporcionalidad: 0.6,
        );

        $dom = (new LibroXmlBuilder())->build($libro, $this->emisor(), '13520634-2', Ambiente::Certificacion);

        self::assertSame('COMPRA', $this->texto($dom, 'TipoOperacion'));

        // ResumenPeriodo COMPRA: TpoImp=1, TotIVAUsoComun, FctProp y TotCredIVAUsoComun.
        $tp = $dom->getElementsByTagNameNS(self::NS_SII, 'TotalesPeriodo')->item(0);
        self::assertInstanceOf(DOMElement::class, $tp);
        self::assertSame('1', $this->hijo($tp, 'TpoImp'));
        self::assertSame('19000', $this->hijo($tp, 'TotIVAUsoComun'));
        self::assertSame('0.6', $this->hijo($tp, 'FctProp'));
        // TotCredIVAUsoComun = round(0.6 * 19000) = 11400.
        self::assertSame('11400', $this->hijo($tp, 'TotCredIVAUsoComun'));

        // Orden del ResumenPeriodo COMPRA.
        self::assertSame(
            ['TpoDoc', 'TpoImp', 'TotDoc', 'TotMntExe', 'TotMntNeto', 'TotMntIVA',
             'TotIVAUsoComun', 'FctProp', 'TotCredIVAUsoComun', 'TotMntTotal'],
            $this->ordenHijos($tp),
        );

        // Detalle COMPRA: IVAUsoComun presente, FctProp AUSENTE.
        $detalle = $dom->getElementsByTagNameNS(self::NS_SII, 'Detalle')->item(0);
        self::assertInstanceOf(DOMElement::class, $detalle);
        self::assertSame('1', $this->hijo($detalle, 'TpoImp'), 'Detalle compra lleva TpoImp');
        self::assertSame('19000', $this->hijo($detalle, 'IVAUsoComun'));
        self::assertSame(
            0,
            $detalle->getElementsByTagNameNS(self::NS_SII, 'FctProp')->length,
            'FctProp NO debe ir en el Detalle de compras',
        );
        // Orden del Detalle COMPRA.
        self::assertSame(
            ['TpoDoc', 'NroDoc', 'TpoImp', 'TasaImp', 'FchDoc', 'RUTDoc', 'RznSoc',
             'MntExe', 'MntNeto', 'MntIVA', 'IVAUsoComun', 'MntTotal'],
            $this->ordenHijos($detalle),
        );
    }

    public function testCompraSinUsoComunNoPoneFctProp(): void
    {
        $libro = new Libro(
            tipoOperacion: TipoOperacionLibro::Compra,
            periodoTributario: '2026-05',
            tipoLibro: TipoLibro::Especial,
            tipoEnvio: TipoEnvioLibro::Total,
            folioNotificacion: 1,
            lineas: [
                new LineaLibro(33, 100, new DateTimeImmutable('2026-05-08'), '55555555-5', 'Proveedor X', 0, 100000, 19000, 119000),
            ],
        );

        $dom = (new LibroXmlBuilder())->build($libro, $this->emisor(), '13520634-2', Ambiente::Certificacion);

        self::assertSame(0, $dom->getElementsByTagNameNS(self::NS_SII, 'FctProp')->length);
        self::assertSame(0, $dom->getElementsByTagNameNS(self::NS_SII, 'TotIVAUsoComun')->length);
        self::assertSame(0, $dom->getElementsByTagNameNS(self::NS_SII, 'IVAUsoComun')->length);
    }

    public function testRetencionTotalIvaCod15RecalculaMntTotalIgnorandoElPayload(): void
    {
        // Retencion total del IVA (factura de compra 46, CodImp=15): mntIva
        // NO se fuerza a 0 (mntIvaEfectivo solo lo hace para ivaUsoComun/
        // codIvaNoRec), pero mntTotal se recalcula SIEMPRE como
        // neto + exento + iva - retenido, ignorando el mntTotal del payload
        // (aqui deliberadamente mal puesto en 999999).
        $libro = new Libro(
            tipoOperacion: TipoOperacionLibro::Compra,
            periodoTributario: '2026-05',
            tipoLibro: TipoLibro::Especial,
            tipoEnvio: TipoEnvioLibro::Total,
            folioNotificacion: 1,
            lineas: [
                new LineaLibro(
                    tpoDoc: 46,
                    nroDoc: 200,
                    fecha: new DateTimeImmutable('2026-05-08'),
                    rutContraparte: '55555555-5',
                    razonSocial: 'Proveedor Y',
                    mntExe: 0,
                    mntNeto: 100000,
                    mntIva: 19000,
                    mntTotal: 999999, // deliberadamente mal puesto: el motor debe ignorarlo.
                    codOtroImp: 15,
                    mntOtroImp: 19000,
                ),
            ],
        );

        $dom = (new LibroXmlBuilder())->build($libro, $this->emisor(), '13520634-2', Ambiente::Certificacion);

        $detalle = $dom->getElementsByTagNameNS(self::NS_SII, 'Detalle')->item(0);
        self::assertInstanceOf(DOMElement::class, $detalle);
        // MntIVA sigue en 19000: no se fuerza a 0 (no rompe la leccion ya validada).
        self::assertSame('19000', $this->hijo($detalle, 'MntIVA'));
        // MntTotal recalculado: 100000 + 0 + 19000 - 19000 = 100000 (no 999999).
        self::assertSame('100000', $this->hijo($detalle, 'MntTotal'));

        // El ResumenPeriodo debe cuadrar IGUAL que el Detalle (mismo valor efectivo).
        $tp = $dom->getElementsByTagNameNS(self::NS_SII, 'TotalesPeriodo')->item(0);
        self::assertInstanceOf(DOMElement::class, $tp);
        self::assertSame('100000', $this->hijo($tp, 'TotMntTotal'));
    }

    public function testSinCodOtroImp15MntTotalSigueSiendoElDelPayloadTalCual(): void
    {
        // No-regresion: codOtroImp ausente (u otro codigo) -> MntTotal es
        // exactamente el del payload, sin recalculo.
        $libro = new Libro(
            tipoOperacion: TipoOperacionLibro::Compra,
            periodoTributario: '2026-05',
            tipoLibro: TipoLibro::Especial,
            tipoEnvio: TipoEnvioLibro::Total,
            folioNotificacion: 1,
            lineas: [
                new LineaLibro(33, 100, new DateTimeImmutable('2026-05-08'), '55555555-5', 'Proveedor X', 0, 100000, 19000, 119000),
            ],
        );

        $dom = (new LibroXmlBuilder())->build($libro, $this->emisor(), '13520634-2', Ambiente::Certificacion);

        $detalle = $dom->getElementsByTagNameNS(self::NS_SII, 'Detalle')->item(0);
        self::assertInstanceOf(DOMElement::class, $detalle);
        self::assertSame('119000', $this->hijo($detalle, 'MntTotal'));

        $tp = $dom->getElementsByTagNameNS(self::NS_SII, 'TotalesPeriodo')->item(0);
        self::assertInstanceOf(DOMElement::class, $tp);
        self::assertSame('119000', $this->hijo($tp, 'TotMntTotal'));
    }

    public function testProduccionUsaNroResolReal(): void
    {
        $dom = (new LibroXmlBuilder())->build($this->libroVenta(), $this->emisor(), '13520634-2', Ambiente::Produccion);
        self::assertSame('99', $this->texto($dom, 'NroResol'));
    }

    public function testFirmaDelEnvioLibroValidaConOpensslVerify(): void
    {
        $cert = $this->certificadoAutoFirmado();
        $dom  = (new LibroXmlBuilder())->build($this->libroVenta(), $this->emisor(), '13520634-2', Ambiente::Certificacion);

        $envio = $dom->getElementsByTagNameNS(self::NS_SII, 'EnvioLibro')->item(0);
        self::assertInstanceOf(DOMElement::class, $envio);
        (new XmlSigner())->firmarNodo($envio, 'LibroCV', $cert);

        $xml = (string) $dom->saveXML();
        self::assertStringNotContainsString('default:', $xml);
        $sig = $dom->getElementsByTagNameNS(self::NS_DSIG, 'Signature')->item(0);
        self::assertNotNull($sig);
        self::assertSame('LibroCompraVenta', $sig->parentNode?->localName);

        $ref = $dom->getElementsByTagNameNS(self::NS_DSIG, 'Reference')->item(0);
        self::assertSame('#LibroCV', $ref->getAttribute('URI'));

        $signedInfo = $dom->getElementsByTagNameNS(self::NS_DSIG, 'SignedInfo')->item(0);
        $sigValue   = $dom->getElementsByTagNameNS(self::NS_DSIG, 'SignatureValue')->item(0);
        self::assertNotNull($signedInfo);
        self::assertNotNull($sigValue);
        $pub = openssl_pkey_get_public($cert->certData);
        self::assertNotFalse($pub);
        self::assertSame(
            1,
            openssl_verify($signedInfo->C14N(), base64_decode(trim((string) $sigValue->textContent)), $pub, OPENSSL_ALGO_SHA1),
        );
    }

    public function testRectificaConCodigoValidoEmiteCodAutRecAlFinalDeLaCaratula(): void
    {
        $libro = new Libro(
            tipoOperacion: TipoOperacionLibro::Venta,
            periodoTributario: '2026-05',
            tipoLibro: TipoLibro::Rectifica,
            tipoEnvio: TipoEnvioLibro::Total,
            folioNotificacion: 1,
            lineas: $this->libroVenta()->lineas,
            codigoAutorizacionReemplazo: 'ABC123',
        );

        $dom = (new LibroXmlBuilder())->build($libro, $this->emisor(), '13520634-2', Ambiente::Certificacion);

        self::assertSame('ABC123', $this->texto($dom, 'CodAutRec'));

        $caratula = $dom->getElementsByTagNameNS(self::NS_SII, 'Caratula')->item(0);
        self::assertInstanceOf(DOMElement::class, $caratula);
        self::assertSame(
            ['RutEmisorLibro', 'RutEnvia', 'PeriodoTributario', 'FchResol', 'NroResol',
             'TipoOperacion', 'TipoLibro', 'TipoEnvio', 'FolioNotificacion', 'CodAutRec'],
            $this->ordenHijos($caratula),
        );
    }

    public function testRectificaSinCodigoLanzaExcepcion(): void
    {
        $libro = new Libro(
            tipoOperacion: TipoOperacionLibro::Venta,
            periodoTributario: '2026-05',
            tipoLibro: TipoLibro::Rectifica,
            tipoEnvio: TipoEnvioLibro::Total,
            folioNotificacion: 1,
            lineas: $this->libroVenta()->lineas,
        );

        $this->expectException(DocumentoInvalidoException::class);
        $this->expectExceptionMessage('codigoAutorizacionReemplazo');
        (new LibroXmlBuilder())->build($libro, $this->emisor(), '13520634-2', Ambiente::Certificacion);
    }

    public function testRectificaConCodigoDeOnceCaracteresLanzaExcepcion(): void
    {
        $libro = new Libro(
            tipoOperacion: TipoOperacionLibro::Venta,
            periodoTributario: '2026-05',
            tipoLibro: TipoLibro::Rectifica,
            tipoEnvio: TipoEnvioLibro::Total,
            folioNotificacion: 1,
            lineas: $this->libroVenta()->lineas,
            codigoAutorizacionReemplazo: 'ABCDE12345F', // 11 caracteres
        );

        $this->expectException(DocumentoInvalidoException::class);
        $this->expectExceptionMessage('largo maximo');
        (new LibroXmlBuilder())->build($libro, $this->emisor(), '13520634-2', Ambiente::Certificacion);
    }

    public function testMensualOEspecialIgnoraElCodigoSinExcepcionYSinCodAutRec(): void
    {
        // ESPECIAL con codigoAutorizacionReemplazo informado por error: se ignora,
        // no es RECTIFICA asi que no aplica -- no debe fallar ni emitir CodAutRec.
        $libro = new Libro(
            tipoOperacion: TipoOperacionLibro::Venta,
            periodoTributario: '2026-05',
            tipoLibro: TipoLibro::Especial,
            tipoEnvio: TipoEnvioLibro::Total,
            folioNotificacion: 1,
            lineas: $this->libroVenta()->lineas,
            codigoAutorizacionReemplazo: 'ABC123',
        );

        $dom = (new LibroXmlBuilder())->build($libro, $this->emisor(), '13520634-2', Ambiente::Certificacion);

        self::assertSame(0, $dom->getElementsByTagNameNS(self::NS_SII, 'CodAutRec')->length);
    }

    public function testMensualSinCodigoNoEmiteCodAutRecNiFalla(): void
    {
        $libro = new Libro(
            tipoOperacion: TipoOperacionLibro::Venta,
            periodoTributario: '2026-05',
            tipoLibro: TipoLibro::Mensual,
            tipoEnvio: TipoEnvioLibro::Total,
            folioNotificacion: 1,
            lineas: $this->libroVenta()->lineas,
        );

        $dom = (new LibroXmlBuilder())->build($libro, $this->emisor(), '13520634-2', Ambiente::Certificacion);

        self::assertSame(0, $dom->getElementsByTagNameNS(self::NS_SII, 'CodAutRec')->length);
    }

    private function certificadoAutoFirmado(): Certificado
    {
        $pkey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($pkey === false) {
            self::markTestSkipped('openssl_pkey_new fallo (falta openssl.cnf)');
        }
        $csr = openssl_csr_new(['commonName' => 'test.sii.local'], $pkey);
        $cert = openssl_csr_sign($csr, null, $pkey, 1);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($pkey, $pkeyPem);
        return new Certificado($certPem, $pkeyPem);
    }
}
