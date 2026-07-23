<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Sii\RvdResumenCalculator;
use RuntimeException;

/**
 * Verifica el calculo dinamico del resumen del RVD contra la MISMA aritmetica
 * que scripts/emitir_rvd_boleta_ea.php dejo documentada a mano (comentario
 * verificado folio por folio, folios 101-105, set fijo de certificacion de
 * boleta): Neto=43831 IVA=8329 Exento=2000 Total=54160, rango [101,105].
 * Si este test pasa, el calculo dinamico reproduce EXACTAMENTE lo que un
 * humano verifico con calculadora -- ya no hace falta ese paso manual.
 */
final class RvdResumenCalculatorTest extends TestCase
{
    /** Las 5 boletas reales del set fijo de certificacion de boleta (CASO-1..5). */
    private function boletasSetFijo(): array
    {
        return [
            ['tipoDte' => 39, 'folio' => 101, 'neto' => 25042, 'iva' => 4758, 'total' => 29800], // CASO-1
            ['tipoDte' => 39, 'folio' => 102, 'neto' =>  1714, 'iva' =>  326, 'total' =>  2040], // CASO-2
            ['tipoDte' => 39, 'folio' => 103, 'neto' =>  3445, 'iva' =>  655, 'total' =>  4100], // CASO-3
            ['tipoDte' => 39, 'folio' => 104, 'neto' => 10689, 'iva' => 2031, 'total' => 14720], // CASO-4 (con exento)
            ['tipoDte' => 39, 'folio' => 105, 'neto' =>  2941, 'iva' =>  559, 'total' =>  3500], // CASO-5
        ];
    }

    public function testCalculaElMismoResumenQueLaAritmeticaManualDocumentada(): void
    {
        $resumenes = (new RvdResumenCalculator())->calcular($this->boletasSetFijo());

        self::assertCount(1, $resumenes, 'Un solo tipo de documento (39) en el set fijo');
        $r = $resumenes[0];

        self::assertSame(39, $r['tipoDocumento']);
        self::assertSame(43831, $r['mntNeto']);
        self::assertSame(8329, $r['mntIva']);
        self::assertSame(19, $r['tasaIva']);
        self::assertSame(2000, $r['mntExento']);
        self::assertSame(54160, $r['mntTotal']);
        self::assertSame(5, $r['foliosEmitidos']);
        self::assertSame(0, $r['foliosAnulados']);
        self::assertSame(5, $r['foliosUtilizados']);
        self::assertSame([[101, 105]], $r['rangos'], 'Folios 101-105 consecutivos colapsan en un solo rango');
    }

    public function testOrdenDeLasBoletasDeEntradaNoAlteraElResultado(): void
    {
        $boletas = $this->boletasSetFijo();
        shuffle($boletas);

        $r = (new RvdResumenCalculator())->calcular($boletas)[0];

        self::assertSame(43831, $r['mntNeto']);
        self::assertSame(8329, $r['mntIva']);
        self::assertSame(54160, $r['mntTotal']);
        self::assertSame([[101, 105]], $r['rangos']);
    }

    public function testSinExentoNoIncluyeLaClaveMntExento(): void
    {
        $r = (new RvdResumenCalculator())->calcular([
            ['tipoDte' => 39, 'folio' => 1, 'neto' => 840, 'iva' => 160, 'total' => 1000],
        ])[0];

        self::assertArrayNotHasKey('mntExento', $r, 'Sin monto exento, no se debe emitir <MntExento>0</MntExento>');
    }

    public function testAgrupaPorTipoDeDocumentoSeparadamente(): void
    {
        $r = (new RvdResumenCalculator())->calcular([
            ['tipoDte' => 39, 'folio' => 1, 'neto' => 840, 'iva' => 160, 'total' => 1000],
            ['tipoDte' => 41, 'folio' => 1, 'neto' => 840, 'iva' => 160, 'total' => 1000],
        ]);

        self::assertCount(2, $r);
        self::assertSame(39, $r[0]['tipoDocumento']);
        self::assertSame(41, $r[1]['tipoDocumento']);
    }

    public function testFoliosNoConsecutivosProducenVariosRangos(): void
    {
        $r = (new RvdResumenCalculator())->calcular([
            ['tipoDte' => 39, 'folio' => 1, 'neto' => 840, 'iva' => 160, 'total' => 1000],
            ['tipoDte' => 39, 'folio' => 2, 'neto' => 840, 'iva' => 160, 'total' => 1000],
            ['tipoDte' => 39, 'folio' => 5, 'neto' => 840, 'iva' => 160, 'total' => 1000],
        ])[0];

        self::assertSame([[1, 2], [5, 5]], $r['rangos']);
    }

    public function testListaVaciaLanzaExcepcion(): void
    {
        $this->expectException(RuntimeException::class);
        (new RvdResumenCalculator())->calcular([]);
    }
}
