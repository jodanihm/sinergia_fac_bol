<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Sii\RehacerDocumento;
use RuntimeException;

final class RehacerDocumentoTest extends TestCase
{
    /** Sobre con dos documentos: el pedido NUNCA puede salir del primero. */
    private function sobre(string ...$documentos): string
    {
        $dtes = '';
        foreach ($documentos as $d) {
            $dtes .= '<DTE version="1.0">' . $d . '</DTE>';
        }

        // En ISO-8859-1 de verdad, como lo guarda el motor.
        return (string) mb_convert_encoding('<?xml version="1.0" encoding="ISO-8859-1"?>'
            . '<EnvioDTE xmlns="http://www.sii.cl/SiiDte" version="1.0"><SetDTE ID="SetDoc">'
            . '<Caratula version="1.0"><RutEmisor>78157243-8</RutEmisor></Caratula>'
            . $dtes . '</SetDTE></EnvioDTE>', 'ISO-8859-1', 'UTF-8');
    }

    private function documento(int $tipo, int $folio, string $idDocExtra, string $receptor, string $detalles, string $resto = ''): string
    {
        return '<Documento ID="F' . $folio . 'T' . $tipo . '"><Encabezado><IdDoc>'
            . '<TipoDTE>' . $tipo . '</TipoDTE><Folio>' . $folio . '</Folio><FchEmis>2026-11-12</FchEmis>'
            . $idDocExtra . '</IdDoc>'
            . '<Receptor>' . $receptor . '</Receptor><Totales><MntTotal>1</MntTotal></Totales></Encabezado>'
            . $detalles . $resto . '</Documento>';
    }

    private function receptor(string $rut = '78040258-K'): string
    {
        return '<RUTRecep>' . $rut . '</RUTRecep><RznSocRecep>CENTRO TERAPÉUTICO TEMPLANZA SPA</RznSocRecep>'
            . '<GiroRecep>salud</GiroRecep><DirRecep>Bernardo O?Higgins Nº580</DirRecep><CmnaRecep>Osorno</CmnaRecep>';
    }

    public function testFacturaNetaDelFolio43(): void
    {
        $otro  = $this->documento(33, 42, '', $this->receptor('11111111-1'),
            '<Detalle><NroLinDet>1</NroLinDet><NmbItem>OTRO</NmbItem><QtyItem>9</QtyItem><PrcItem>9</PrcItem><MontoItem>81</MontoItem></Detalle>');
        $pedido = $this->documento(33, 43, '<FmaPago>1</FmaPago>', $this->receptor(),
            '<Detalle><NroLinDet>1</NroLinDet><NmbItem>Pagina web + agenda</NmbItem><QtyItem>1</QtyItem><PrcItem>100000</PrcItem><MontoItem>100000</MontoItem></Detalle>');

        $form = RehacerDocumento::formulario($this->sobre($otro, $pedido), 33, 43);

        self::assertSame('78040258-K', $form['receptor']['rut']);
        self::assertSame('CENTRO TERAPÉUTICO TEMPLANZA SPA', $form['receptor']['razonSocial']);
        self::assertSame('Osorno', $form['receptor']['comuna']);
        self::assertCount(1, $form['detalles']);
        self::assertSame('Pagina web + agenda', $form['detalles'][0]['nombre']);
        self::assertSame('100000', $form['detalles'][0]['precioUnitario']);
        self::assertSame('neto', $form['detalles'][0]['origen']);
        self::assertFalse($form['detalles'][0]['exento']);
        self::assertSame('1', $form['formaPago']);
        self::assertArrayNotHasKey('referencias', $form);
    }

    public function testFacturaBrutaUsaPrecioFinalYLasExentasVanSinIva(): void
    {
        $doc = $this->documento(33, 5, '<MntBruto>1</MntBruto><FmaPago>2</FmaPago><FchVenc>2026-12-12</FchVenc>', $this->receptor(),
            '<Detalle><NroLinDet>1</NroLinDet><NmbItem>Afecto</NmbItem><DscItem>Mes de octubre</DscItem><QtyItem>2</QtyItem><UnmdItem>UN</UnmdItem><PrcItem>11900</PrcItem><MontoItem>23800</MontoItem></Detalle>'
            . '<Detalle><NroLinDet>2</NroLinDet><IndExe>1</IndExe><NmbItem>Exento</NmbItem><QtyItem>1</QtyItem><PrcItem>5000</PrcItem><MontoItem>5000</MontoItem></Detalle>',
            '<DscRcgGlobal><NroLinDR>1</NroLinDR><TpoMov>D</TpoMov><TpoValor>%</TpoValor><ValorDR>10</ValorDR></DscRcgGlobal>');

        $form = RehacerDocumento::formulario($this->sobre($doc), 33, 5);

        self::assertSame(['nombre' => 'Afecto', 'cantidad' => '2', 'unidad' => 'UN', 'descripcion' => 'Mes de octubre', 'exento' => false, 'precioFinal' => '11900', 'origen' => 'bruto'], $form['detalles'][0]);
        self::assertSame('5000', $form['detalles'][1]['precioUnitario']);
        self::assertTrue($form['detalles'][1]['exento']);
        self::assertSame('10', $form['descuentoGlobalPct']);
        self::assertSame('2', $form['formaPago']);
        self::assertSame('2026-12-12', $form['fechaVencimiento']);
    }

    public function testDescuentoDeLineaSeIncorporaAlPrecio(): void
    {
        $doc = $this->documento(33, 6, '', $this->receptor(),
            '<Detalle><NroLinDet>1</NroLinDet><NmbItem>X</NmbItem><QtyItem>4</QtyItem><PrcItem>1000</PrcItem><DescuentoPct>10</DescuentoPct><DescuentoMonto>400</DescuentoMonto><MontoItem>3600</MontoItem></Detalle>');

        $form = RehacerDocumento::formulario($this->sobre($doc), 33, 6);

        self::assertSame('900', $form['detalles'][0]['precioUnitario']);
    }

    public function testBoletaAConsumidorFinalDejaElRutVacioYEsBruta(): void
    {
        $doc = $this->documento(39, 7, '<IndServicio>3</IndServicio>', '<RUTRecep>66666666-6</RUTRecep>',
            '<Detalle><NroLinDet>1</NroLinDet><NmbItem>Cafe</NmbItem><QtyItem>1</QtyItem><PrcItem>2500</PrcItem><MontoItem>2500</MontoItem></Detalle>');

        $form = RehacerDocumento::formulario($this->sobre($doc), 39, 7);

        self::assertSame('', $form['receptor']['rut']);
        self::assertSame('2500', $form['detalles'][0]['precioFinal']);
        self::assertSame('bruto', $form['detalles'][0]['origen']);
        self::assertArrayNotHasKey('formaPago', $form);
    }

    public function testNotaDeCreditoTraeSuReferenciaYLaGlosaPura(): void
    {
        $doc = $this->documento(61, 8, '', $this->receptor(),
            '<Detalle><NroLinDet>1</NroLinDet><NmbItem>Anula documento</NmbItem><MontoItem>0</MontoItem></Detalle>',
            '<Referencia><NroLinRef>1</NroLinRef><TpoDocRef>33</TpoDocRef><FolioRef>40</FolioRef><FchRef>2026-10-21</FchRef><CodRef>1</CodRef><RazonRef>Anula documento N 40</RazonRef></Referencia>');

        $form = RehacerDocumento::formulario($this->sobre($doc), 61, 8);

        self::assertSame([['tipoDocumento' => '33', 'folio' => '40', 'fecha' => '2026-10-21', 'codigo' => '1', 'razon' => 'Anula documento N 40']], $form['referencias']);
        self::assertSame('0', $form['detalles'][0]['precioUnitario']);
    }

    public function testDocumentoAusenteLanzaEnVezDeDevolverOtro(): void
    {
        $this->expectException(RuntimeException::class);
        RehacerDocumento::formulario($this->sobre($this->documento(33, 42, '', $this->receptor(), '')), 33, 43);
    }

    public function testSoloSePermiteEnRechazosDelSii(): void
    {
        foreach (['RFR', 'RSC', 'RCT', 'RCH', 'RCO', 'VOF', ' RFR '] as $e) {
            self::assertTrue(RehacerDocumento::permite(33, $e), $e);
        }
        // RPT: se rechazo la copia, el original entro. DNK/ANC: el SII si lo tiene.
        foreach (['EPR', 'DOK', 'RPR', 'enviado', 'RPT', 'DNK', 'ANC', '', null] as $e) {
            self::assertFalse(RehacerDocumento::permite(33, $e), (string) $e);
        }
        self::assertFalse(RehacerDocumento::permite(52, 'RFR'), 'guia: el formulario no la emite');
    }
}
