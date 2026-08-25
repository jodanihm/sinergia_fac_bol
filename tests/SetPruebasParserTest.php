<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Dto\CasoSetBasico;
use Plantiflex\FacturacionCl\Dto\SetPruebasParseado;
use Plantiflex\FacturacionCl\Sii\SetPruebasParser;

final class SetPruebasParserTest extends TestCase
{
    use FixtureFueraDelRepo;

    private static ?SetPruebasParseado $parseado = null;

    private function parseadoFixtureReal(): SetPruebasParseado
    {
        if (self::$parseado === null) {
            $bytes = $this->fixtureFueraDelRepo('easyagenda/SIISetDePruebas781572438.txt');
            self::$parseado = (new SetPruebasParser())->parse($bytes);
        }

        return self::$parseado;
    }

    private function casoPorNumero(SetPruebasParseado $r, int $numero): CasoSetBasico
    {
        foreach ($r->casos as $c) {
            if ($c->numeroCaso === $numero) {
                return $c;
            }
        }
        self::fail("No se encontro el caso {$numero}");
    }

    public function testNumerosDeAtencion(): void
    {
        $r = $this->parseadoFixtureReal();
        self::assertSame(4951090, $r->numeroAtencionSetBasico);
        self::assertSame(4951091, $r->numeroAtencionLibroVentas);
        self::assertSame(4951092, $r->numeroAtencionLibroCompras);
    }

    public function testOchoCasosConTiposCorrectos(): void
    {
        $r = $this->parseadoFixtureReal();
        self::assertCount(8, $r->casos);

        $porTipo = array_count_values(array_map(static fn (CasoSetBasico $c): string => $c->tipoDocumento, $r->casos));
        self::assertSame(4, $porTipo['FACTURA'] ?? 0);
        self::assertSame(3, $porTipo['NOTA_CREDITO'] ?? 0);
        self::assertSame(1, $porTipo['NOTA_DEBITO'] ?? 0);
    }

    public function testCaso1ItemConTildeExacta(): void
    {
        $r = $this->parseadoFixtureReal();
        $item = $this->casoPorNumero($r, 1)->items[0];

        // Verificacion explicita del caracter o-con-tilde: la conversion
        // ISO-8859-1 -> UTF-8 es el punto mas critico del parser (un caracter
        // mal decodificado ya provoco un rechazo real del SII).
        self::assertSame("Caj\u{F3}n AFECTO", $item->nombre);
        self::assertStringContainsString("\u{F3}", $item->nombre);
        self::assertStringNotContainsString('?', $item->nombre);
        self::assertSame(188, $item->cantidad);
        self::assertSame(4611, $item->precioUnitario);
    }

    public function testCaso2ItemConDescuentoYEneExacta(): void
    {
        $r = $this->parseadoFixtureReal();
        $item = $this->casoPorNumero($r, 2)->items[0];

        self::assertSame("Pa\u{F1}uelo AFECTO", $item->nombre);
        self::assertStringContainsString("\u{F1}", $item->nombre);
        self::assertSame(12, $item->descuentoPorcentaje);
    }

    public function testCaso4DescuentoGlobal(): void
    {
        $r = $this->parseadoFixtureReal();
        self::assertSame(29, $this->casoPorNumero($r, 4)->descuentoGlobalPct);
    }

    public function testCaso5ReferenciaYRazon(): void
    {
        $r = $this->parseadoFixtureReal();
        $caso5 = $this->casoPorNumero($r, 5);

        self::assertSame(1, $caso5->referenciaCaso);
        self::assertStringContainsString('CORRIGE GIRO', (string) $caso5->razonReferencia);
    }

    public function testLibroDeComprasCasosEspeciales(): void
    {
        $r = $this->parseadoFixtureReal();
        self::assertCount(7, $r->casosLibroCompras);

        $porFolio = [];
        foreach ($r->casosLibroCompras as $c) {
            $porFolio[$c->folio] = $c;
        }

        self::assertArrayHasKey(781, $porFolio);
        self::assertTrue($porFolio[781]->ivaUsoComun);

        self::assertArrayHasKey(67, $porFolio);
        self::assertTrue($porFolio[67]->ivaNoRecuperable);
    }

    public function testFactorProporcionalidad(): void
    {
        $r = $this->parseadoFixtureReal();
        self::assertEqualsWithDelta(0.60, $r->factorProporcionalidadIvaUsoComun, 0.0001);
    }
}
