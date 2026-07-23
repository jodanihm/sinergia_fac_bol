<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Dto\Credenciales;
use Plantiflex\FacturacionCl\Dto\Detalle;
use Plantiflex\FacturacionCl\Dto\DocumentoOriginal;
use Plantiflex\FacturacionCl\Dto\DocumentoTributario;
use Plantiflex\FacturacionCl\Dto\Receptor;
use Plantiflex\FacturacionCl\Enums\TipoAnulacion;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Exceptions\CredencialesInvalidasException;
use Plantiflex\FacturacionCl\Exceptions\DocumentoInvalidoException;

final class DtoValidationTest extends TestCase
{
    public function testTipoDteHelpers(): void
    {
        self::assertTrue(TipoDte::BoletaElectronica->esBoleta());
        self::assertFalse(TipoDte::BoletaElectronica->esFactura());
        self::assertTrue(TipoDte::FacturaElectronica->esFactura());
        self::assertTrue(TipoDte::FacturaExentaElectronica->esExento());
    }

    public function testCredencialesRechazaRutVacio(): void
    {
        $this->expectException(CredencialesInvalidasException::class);
        new Credenciales(rutEmisor: '', apiToken: 'x');
    }

    public function testCredencialesRechazaTokenVacio(): void
    {
        $this->expectException(CredencialesInvalidasException::class);
        new Credenciales(rutEmisor: '1-9', apiToken: '');
    }

    public function testReceptorRechazaRutVacio(): void
    {
        $this->expectException(DocumentoInvalidoException::class);
        new Receptor(rut: '', razonSocial: 'X');
    }

    public function testDetalleRechazaCantidadCero(): void
    {
        $this->expectException(DocumentoInvalidoException::class);
        new Detalle(nombre: 'X', cantidad: 0, precioUnitario: 100);
    }

    public function testDocumentoTributarioRechazaSinDetalles(): void
    {
        $this->expectException(DocumentoInvalidoException::class);
        new DocumentoTributario(
            tipoDte: TipoDte::BoletaElectronica,
            receptor: new Receptor('1-9', 'X'),
            detalles: [],
        );
    }

    public function testDocumentoTributarioToArrayIncluyeFlagDeMontos(): void
    {
        $doc = new DocumentoTributario(
            tipoDte: TipoDte::BoletaElectronica,
            receptor: new Receptor('1-9', 'X'),
            detalles: [new Detalle('A', 1, 100)],
            montosSonBrutos: true,
        );

        $arr = $doc->toArray();
        self::assertTrue($arr['montosSonBrutos']);
        self::assertSame(39, $arr['tipoDte']);
    }

    public function testTipoAnulacionTieneValoresDeCodRefSii(): void
    {
        self::assertSame(1, TipoAnulacion::AnulaTotal->value);
        self::assertSame(2, TipoAnulacion::CorrigeTexto->value);
        self::assertSame(3, TipoAnulacion::CorrigeMonto->value);
    }

    private function originalValido(): DocumentoOriginal
    {
        return new DocumentoOriginal(
            tipoDte:      TipoDte::FacturaElectronica,
            folio:        100,
            fechaEmision: new \DateTimeImmutable('2025-03-15'),
            receptor:     new Receptor('99999999-9', 'Cliente'),
            detalles:     [new Detalle('item', 1, 1000)],
            montoNeto:    1000,
            iva:          190,
            montoTotal:   1190,
        );
    }

    public function testDocumentoOriginalAceptaDatosValidos(): void
    {
        $o = $this->originalValido();
        self::assertSame(100, $o->folio);
        self::assertSame(TipoDte::FacturaElectronica, $o->tipoDte);
        self::assertCount(1, $o->detalles);
    }

    public function testDocumentoOriginalRechazaFolioCeroONegativo(): void
    {
        $this->expectException(\Plantiflex\FacturacionCl\Exceptions\DocumentoInvalidoException::class);
        new DocumentoOriginal(
            tipoDte:      TipoDte::FacturaElectronica,
            folio:        0,
            fechaEmision: new \DateTimeImmutable(),
            receptor:     new Receptor('1-9', 'X'),
            detalles:     [new Detalle('a', 1, 100)],
            montoNeto:    100, iva: 19, montoTotal: 119,
        );
    }

    public function testDocumentoOriginalRechazaSinDetalles(): void
    {
        $this->expectException(\Plantiflex\FacturacionCl\Exceptions\DocumentoInvalidoException::class);
        new DocumentoOriginal(
            tipoDte:      TipoDte::FacturaElectronica,
            folio:        1,
            fechaEmision: new \DateTimeImmutable(),
            receptor:     new Receptor('1-9', 'X'),
            detalles:     [],
            montoNeto:    100, iva: 19, montoTotal: 119,
        );
    }

    public function testDocumentoOriginalRechazaMontoTotalNoPositivo(): void
    {
        $this->expectException(\Plantiflex\FacturacionCl\Exceptions\DocumentoInvalidoException::class);
        new DocumentoOriginal(
            tipoDte:      TipoDte::FacturaElectronica,
            folio:        1,
            fechaEmision: new \DateTimeImmutable(),
            receptor:     new Receptor('1-9', 'X'),
            detalles:     [new Detalle('a', 1, 100)],
            montoNeto:    0, iva: 0, montoTotal: 0,
        );
    }
}
