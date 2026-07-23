<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Sii\SimulacionSetBuilder;
use RuntimeException;

/**
 * La plantilla real (integration/plantiflex/templates/simulacion_base.json)
 * es la semilla YA ACEPTADA por el SII en 2 envios reales (ver
 * payload_simulacion_v2.json, 22 factura / 6 NC / 2 ND para 30 documentos).
 * Estos tests validan la ESCALA a otras cantidades con la regla ~70/20/10
 * (no se exige reproducir el 22/6/2 exacto del payload original: la tarea
 * pide la PROPORCION aproximada, no la copia exacta de un unico caso).
 */
final class SimulacionSetBuilderTest extends TestCase
{
    private function conteoPorTipo(array $documentos): array
    {
        $conteo = [33 => 0, 61 => 0, 56 => 0];
        foreach ($documentos as $d) {
            $conteo[$d['tipoDte']]++;
        }

        return $conteo;
    }

    public function testMenorA20LanzaExcepcion(): void
    {
        $this->expectException(RuntimeException::class);
        (new SimulacionSetBuilder())->construir(19);
    }

    public function testMayorA100LanzaExcepcion(): void
    {
        $this->expectException(RuntimeException::class);
        (new SimulacionSetBuilder())->construir(101);
    }

    public function testTotal20GeneraLosTresTiposConProporcionAproximada(): void
    {
        $documentos = (new SimulacionSetBuilder())->construir(20);

        self::assertCount(20, $documentos);
        $conteo = $this->conteoPorTipo($documentos);
        self::assertSame(['33' => 14, '61' => 4, '56' => 2], array_combine(array_map('strval', array_keys($conteo)), $conteo));
    }

    public function testTotal30GeneraLosTresTiposConProporcionAproximada(): void
    {
        $documentos = (new SimulacionSetBuilder())->construir(30);

        self::assertCount(30, $documentos);
        $conteo = $this->conteoPorTipo($documentos);
        self::assertSame(21, $conteo[33]);
        self::assertSame(6, $conteo[61]);
        self::assertSame(3, $conteo[56]);
    }

    public function testTotal100GeneraLosTresTiposConProporcionAproximada(): void
    {
        $documentos = (new SimulacionSetBuilder())->construir(100);

        self::assertCount(100, $documentos);
        $conteo = $this->conteoPorTipo($documentos);
        self::assertSame(70, $conteo[33]);
        self::assertSame(20, $conteo[61]);
        self::assertSame(10, $conteo[56]);
    }

    public function testSinReferenciaAlSet(): void
    {
        // Ninguna referencia debe llevar 'tipoDocumento' => 'SET' (eso es
        // exclusivo del Set Basico); las referencias de Simulacion son
        // SIEMPRE intra-lote (refIndiceLote), nunca al SET.
        $documentos = (new SimulacionSetBuilder())->construir(30);

        foreach ($documentos as $d) {
            foreach (($d['referencias'] ?? []) as $ref) {
                self::assertArrayNotHasKey('tipoDocumento', $ref);
                self::assertArrayHasKey('refIndiceLote', $ref);
            }
        }
    }

    public function testTodaNotaDeCreditoReferenciaUnaFacturaYaGeneradaAntes(): void
    {
        $documentos = (new SimulacionSetBuilder())->construir(30);

        foreach ($documentos as $i => $d) {
            if ($d['tipoDte'] !== 61) {
                continue;
            }
            self::assertNotEmpty($d['referencias'] ?? [], "NC en indice {$i} debe tener referencia.");
            $refIndice = $d['referencias'][0]['refIndiceLote'];
            self::assertLessThan($i, $refIndice, 'La referencia debe apuntar a un documento YA generado antes en el lote.');
            self::assertSame(33, $documentos[$refIndice]['tipoDte'], 'Una NC de este template siempre referencia una FACTURA (33).');
        }
    }

    public function testTodaNotaDeDebitoReferenciaUnaNotaDeCreditoYaGeneradaAntes(): void
    {
        $documentos = (new SimulacionSetBuilder())->construir(30);

        foreach ($documentos as $i => $d) {
            if ($d['tipoDte'] !== 56) {
                continue;
            }
            self::assertNotEmpty($d['referencias'] ?? [], "ND en indice {$i} debe tener referencia.");
            $refIndice = $d['referencias'][0]['refIndiceLote'];
            self::assertLessThan($i, $refIndice, 'La referencia debe apuntar a un documento YA generado antes en el lote.');
            self::assertSame(61, $documentos[$refIndice]['tipoDte'], 'Una ND de este template siempre referencia una NOTA DE CREDITO (61).');
        }
    }

    public function testReceptorYGlosasVienenDeLaPlantillaRealAceptadaPorElSii(): void
    {
        $documentos = (new SimulacionSetBuilder())->construir(20);

        self::assertSame('66666666-6', $documentos[0]['receptor']['rut']);
        self::assertSame('Cliente de Prueba', $documentos[0]['receptor']['razonSocial']);
        // Primera glosa de la plantilla real (payload_simulacion_v2.json).
        self::assertSame('Desarrollo de sitio web corporativo', $documentos[0]['detalles'][0]['nombre']);
        self::assertSame(850000, $documentos[0]['detalles'][0]['precioUnitario']);
    }
}
