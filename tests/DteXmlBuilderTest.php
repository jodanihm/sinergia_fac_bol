<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use DOMDocument;
use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Dto\DatosEmisor;
use Plantiflex\FacturacionCl\Dto\Detalle;
use Plantiflex\FacturacionCl\Dto\DocumentoTributario;
use Plantiflex\FacturacionCl\Dto\Receptor;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Sii\DteXmlBuilder;

final class DteXmlBuilderTest extends TestCase
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

    private function texto(DOMDocument $dom, string $tag): ?string
    {
        $n = $dom->getElementsByTagNameNS(self::NS_SII, $tag)->item(0);
        return $n === null ? null : trim((string) $n->textContent);
    }

    public function testBoleta39ConMontosBrutosSeparaNetoEIvaDesdeElTotal(): void
    {
        $doc = new DocumentoTributario(
            tipoDte: TipoDte::BoletaElectronica,
            receptor: new Receptor('11111111-1', 'Cliente Final'),
            detalles: [new Detalle('Maceta', 2, 1190)], // total bruto = 2380
            montosSonBrutos: true,
        );

        $dom = (new DteXmlBuilder())->build($doc, $this->emisor(), 7);

        self::assertSame('39', $this->texto($dom, 'TipoDTE'));
        self::assertNull($this->texto($dom, 'MntBruto')); // boleta: montos brutos por defecto, sin flag MntBruto (eso es de factura, en IdDoc)
        // 2380 / 1.19 = 2000 neto, IVA = 380.
        self::assertSame('2000', $this->texto($dom, 'MntNeto'));
        self::assertNull($this->texto($dom, 'TasaIVA')); // boleta no lleva TasaIVA en Totales
        self::assertSame('380', $this->texto($dom, 'IVA'));
        self::assertSame('2380', $this->texto($dom, 'MntTotal'));
        // Boleta -> RznSocEmisor (no RznSoc).
        self::assertSame('Plantiflex SpA', $this->texto($dom, 'RznSocEmisor'));
        self::assertNull($this->texto($dom, 'RznSoc'));
        // MontoItem de la linea = 2 * 1190.
        self::assertSame('2380', $this->texto($dom, 'MontoItem'));
    }

    public function testFactura33ConMontosNetosCalculaIvaYUsaRznSoc(): void
    {
        $doc = new DocumentoTributario(
            tipoDte: TipoDte::FacturaElectronica,
            receptor: new Receptor('99999999-9', 'Empresa Cliente'),
            detalles: [new Detalle('Servicio', 1, 50000)],
            montosSonBrutos: false,
        );

        $dom = (new DteXmlBuilder())->build($doc, $this->emisor(), 100);

        self::assertNull($this->texto($dom, 'MntBruto'), 'Factura neta no lleva MntBruto');
        self::assertSame('Plantiflex SpA', $this->texto($dom, 'RznSoc'));
        self::assertNull($this->texto($dom, 'RznSocEmisor'));
        self::assertSame('50000', $this->texto($dom, 'MntNeto'));
        self::assertSame('9500', $this->texto($dom, 'IVA')); // round(50000 * 0.19)
        self::assertSame('59500', $this->texto($dom, 'MntTotal'));
    }

    public function testItemExentoVaAMntExeYNoSumaIva(): void
    {
        $doc = new DocumentoTributario(
            tipoDte: TipoDte::FacturaElectronica,
            receptor: new Receptor('99999999-9', 'Empresa'),
            detalles: [
                new Detalle('Afecto', 1, 10000),
                new Detalle('Exento', 1, 5000, exento: true),
            ],
            montosSonBrutos: false,
        );

        $dom = (new DteXmlBuilder())->build($doc, $this->emisor(), 200);

        self::assertSame('10000', $this->texto($dom, 'MntNeto'));
        self::assertSame('1900', $this->texto($dom, 'IVA'));
        self::assertSame('5000', $this->texto($dom, 'MntExe'));
        // total = 10000 + 1900 + 5000.
        self::assertSame('16900', $this->texto($dom, 'MntTotal'));

        // IndExe=1 presente en algun Detalle.
        $indExe = $dom->getElementsByTagNameNS(self::NS_SII, 'IndExe')->item(0);
        self::assertNotNull($indExe);
        self::assertSame('1', trim((string) $indExe->textContent));
    }

    public function testIdDelDocumentoSigueFormatoSii(): void
    {
        $doc = new DocumentoTributario(
            tipoDte: TipoDte::FacturaElectronica,
            receptor: new Receptor('99999999-9', 'Empresa'),
            detalles: [new Detalle('item', 1, 1000)],
        );

        $dom = (new DteXmlBuilder())->build($doc, $this->emisor(), 4242);

        $documento = $dom->getElementsByTagNameNS(self::NS_SII, 'Documento')->item(0);
        self::assertNotNull($documento);
        self::assertSame('F4242T33', $documento->getAttribute('ID'));
        // Root namespace correcto.
        self::assertSame(self::NS_SII, $dom->documentElement->namespaceURI);
        self::assertSame('DTE', $dom->documentElement->localName);
    }

    /**
     * @return list<string>
     */
    private function todosLosTextos(DOMDocument $dom, string $tag): array
    {
        $out = [];
        foreach ($dom->getElementsByTagNameNS(self::NS_SII, $tag) as $n) {
            $out[] = trim((string) $n->textContent);
        }
        return $out;
    }

    public function testCaso2DescuentosPorLineaSeAplicanEnMontoItemYTotales(): void
    {
        // 2 items afectos netos con 3% y 4% de descuento por linea.
        $doc = new DocumentoTributario(
            tipoDte: TipoDte::FacturaElectronica,
            receptor: new Receptor('99999999-9', 'Empresa Cliente'),
            detalles: [
                new Detalle('Item A', 1, 10000, descuentoPorcentaje: 3.0), // 9700
                new Detalle('Item B', 1, 20000, descuentoPorcentaje: 4.0), // 19200
            ],
            montosSonBrutos: false,
        );

        $dom = (new DteXmlBuilder())->build($doc, $this->emisor(), 50);

        // DescuentoPct presente con ambos valores.
        self::assertSame(['3', '4'], $this->todosLosTextos($dom, 'DescuentoPct'));

        // DescuentoMonto presente y MontoItem con descuento aplicado.
        self::assertSame(['300', '800'], $this->todosLosTextos($dom, 'DescuentoMonto'));
        self::assertSame(['9700', '19200'], $this->todosLosTextos($dom, 'MontoItem'));

        // Totales: neto = 9700 + 19200 = 28900, IVA = round(28900*0.19) = 5491.
        self::assertSame('28900', $this->texto($dom, 'MntNeto'));
        self::assertSame('5491', $this->texto($dom, 'IVA'));
        self::assertSame('34391', $this->texto($dom, 'MntTotal'));
    }

    public function testCaso4DescuentoGlobalAplicaAlAfectoNeto(): void
    {
        // 2 afectos + 1 exento, con descuento global del 6%. Por contrato (y como esta
        // certificado/emitiendo) el descuento global aplica al AFECTO (neto), SIN
        // IndExeDR; los items exentos quedan INTACTOS (IndExeDR=1 seria solo si se
        // quisiera aplicar el descuento a los exentos, que no es el caso de esta API).
        $doc = new DocumentoTributario(
            tipoDte: TipoDte::FacturaElectronica,
            receptor: new Receptor('99999999-9', 'Empresa Cliente'),
            detalles: [
                new Detalle('Afecto A', 1, 10000),
                new Detalle('Afecto B', 1, 20000),
                new Detalle('Exento C', 1, 5000, exento: true),
            ],
            montosSonBrutos: false,
            descuentoGlobalPct: 6.0,
        );

        $dom = (new DteXmlBuilder())->build($doc, $this->emisor(), 60);

        // Bloque DscRcgGlobal presente y bien formado, SIN IndExeDR (aplica al afecto).
        $dr = $dom->getElementsByTagNameNS(self::NS_SII, 'DscRcgGlobal')->item(0);
        self::assertNotNull($dr, 'Falta DscRcgGlobal');
        self::assertSame('D', $this->texto($dom, 'TpoMov'));
        self::assertSame('%', $this->texto($dom, 'TpoValor'));
        self::assertSame('6', $this->texto($dom, 'ValorDR'));
        self::assertNull($this->texto($dom, 'IndExeDR'), 'No debe llevar IndExeDR: aplica al afecto');

        // Afecto con descuento: 30000 - round(30000*6%) = 28200. IVA = round(28200*19%) = 5358.
        self::assertSame('28200', $this->texto($dom, 'MntNeto'));
        self::assertSame('5358', $this->texto($dom, 'IVA'));
        // Exento INTACTO: el descuento global no lo toca.
        self::assertSame('5000', $this->texto($dom, 'MntExe'));
        // Total = 28200 + 5358 + 5000 = 38558.
        self::assertSame('38558', $this->texto($dom, 'MntTotal'));
    }

    public function testBoleta39RespetaIndServicioExplicito(): void
    {
        $doc = new DocumentoTributario(
            tipoDte: TipoDte::BoletaElectronica,
            receptor: new Receptor('11111111-1', 'Cliente Final'),
            detalles: [new Detalle('Servicio', 1, 5000)],
            montosSonBrutos: true,
            indServicio: 1,
        );

        $dom = (new DteXmlBuilder())->build($doc, $this->emisor(), 8);

        self::assertSame('1', $this->texto($dom, 'IndServicio'));
    }

    public function testBoleta39IndServicioPorDefectoEsTres(): void
    {
        $doc = new DocumentoTributario(
            tipoDte: TipoDte::BoletaElectronica,
            receptor: new Receptor('11111111-1', 'Cliente Final'),
            detalles: [new Detalle('Producto', 1, 5000)],
            montosSonBrutos: true,
        );

        $dom = (new DteXmlBuilder())->build($doc, $this->emisor(), 9);

        self::assertSame('3', $this->texto($dom, 'IndServicio'));
    }

    public function testBoletaMontosBrutosConIvaPorDiferenciaCuadraExactoConElBruto(): void
    {
        // Caso real que descuadraba antes del fix: bruto 85.000 -> neto
        // round(85000/1.19)=71.429, IVA con round(71429*19%)=13.572 daba
        // Total=85.001 (no 85.000). Con IVA por diferencia (afecto - neto)
        // el total cuadra exacto contra el bruto, sin importar el redondeo.
        $doc = new DocumentoTributario(
            tipoDte: TipoDte::BoletaElectronica,
            receptor: new Receptor('11111111-1', 'Cliente Final'),
            detalles: [new Detalle('Diseno pagina de reservas personalizada', 1, 85000)],
            montosSonBrutos: true,
        );

        $dom = (new DteXmlBuilder())->build($doc, $this->emisor(), 10);

        self::assertSame('71429', $this->texto($dom, 'MntNeto'));
        self::assertSame('13571', $this->texto($dom, 'IVA'));
        self::assertSame('85000', $this->texto($dom, 'MntTotal'));
    }

    public function testBoletaMontosBrutosQueYaCuadrabaSigueIgualTrasElFix(): void
    {
        // Caso que ya cuadraba antes del fix (bruto 84.990 -> neto 71.420,
        // IVA round(71420*19%)=13.570, total 84.990 exacto): debe seguir
        // dando lo mismo con IVA por diferencia (84990-71420=13570).
        $doc = new DocumentoTributario(
            tipoDte: TipoDte::BoletaElectronica,
            receptor: new Receptor('11111111-1', 'Cliente Final'),
            detalles: [new Detalle('Diseno pagina de reservas personalizada', 1, 84990)],
            montosSonBrutos: true,
        );

        $dom = (new DteXmlBuilder())->build($doc, $this->emisor(), 11);

        self::assertSame('71420', $this->texto($dom, 'MntNeto'));
        self::assertSame('13570', $this->texto($dom, 'IVA'));
        self::assertSame('84990', $this->texto($dom, 'MntTotal'));
    }
}
