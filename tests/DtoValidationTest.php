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

    /**
     * MONTO TOTAL CERO SE ACEPTA, y este test reemplaza a uno que exigia lo
     * contrario.
     *
     * La regla vieja era montoTotal > 0 y prohibia un documento VALIDO: una nota
     * de correccion de texto lleva MntTotal 0 por diseño, y este mismo repo la
     * emite asi -- SiiDirectoFacturador::anular() con TipoAnulacion::CorrigeTexto
     * usa totales: ['MntTotal' => 0]. En produccion hay cinco documentos con
     * total cero (tipo 56 folios 12 y 13, tipo 61 folios 37, 38 y 39) que con la
     * guarda vieja no se podian anular ni referenciar: la excepcion saltaba al
     * construir el DocumentoOriginal.
     *
     * El cero servia de canario contra una reconstruccion rota que devolvia todo
     * en cero. Ese canario se movio a reconstruirOriginal(), que es el unico
     * sitio donde se puede distinguir "el elemento MntTotal no vino" de "vale
     * cero" -- al DTO las dos cosas le llegan como int 0.
     */
    public function testDocumentoOriginalAceptaMontoTotalCero(): void
    {
        $o = new DocumentoOriginal(
            tipoDte:      TipoDte::NotaCreditoElectronica,
            folio:        37,
            fechaEmision: new \DateTimeImmutable('2026-08-04'),
            receptor:     new Receptor('60803000-K', 'CLIENTE'),
            detalles:     [new Detalle('Correccion de texto', 1, 0)],
            montoNeto:    0,
            iva:          0,
            montoTotal:   0,
        );

        self::assertSame(0, $o->montoTotal);
        self::assertSame(37, $o->folio);
    }

    /** Negativo sigue prohibido: eso no es un documento, es un error de signo. */
    public function testDocumentoOriginalRechazaMontoTotalNegativo(): void
    {
        $this->expectException(\Plantiflex\FacturacionCl\Exceptions\DocumentoInvalidoException::class);
        $this->expectExceptionMessageMatches('/no puede ser negativo/i');

        new DocumentoOriginal(
            tipoDte:      TipoDte::FacturaElectronica,
            folio:        1,
            fechaEmision: new \DateTimeImmutable(),
            receptor:     new Receptor('1-9', 'X'),
            detalles:     [new Detalle('a', 1, 100)],
            montoNeto:    0, iva: 0, montoTotal: -1,
        );
    }
}
