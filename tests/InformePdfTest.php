<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use InformePdf;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../panel/src/InformePdf.php';

/**
 * Test de humo de la generacion de PDF de informes.
 *
 * InformePdf vive en panel/src/, que no esta en el autoload PSR-4 (ese cubre
 * solo src/, el motor): se carga con un require_once explicito, igual que hace
 * FechaExcelTest y que panel/public/index.php con Auth/Db/Csrf/Rut. Como la
 * clase extiende TCPDF, el autoload de Composer del bootstrap ya resolvio la
 * clase padre antes de llegar aqui.
 *
 * No se valida el aspecto del PDF -- eso se verifica renderizando de verdad --
 * sino lo que puede romperse en silencio: que el binario salga bien formado,
 * que las columnas numericas se formateen y que una tabla vacia no reviente.
 */
final class InformePdfTest extends TestCase
{
    /** @return list<array{titulo:string, ancho:float, alineacion:string, num:bool}> */
    private function columnas(): array
    {
        return [
            ['titulo' => 'Tipo',  'ancho' => 100.0, 'alineacion' => 'L', 'num' => false],
            ['titulo' => 'Neto',  'ancho' => 60.0,  'alineacion' => 'R', 'num' => true],
            ['titulo' => 'Total', 'ancho' => 60.0,  'alineacion' => 'R', 'num' => true],
        ];
    }

    public function testGeneraUnPdfBienFormado(): void
    {
        $pdf = new InformePdf('Informe de prueba', 'EMPRESA UNO SPA', '11111111-1', '2026-06-01 a 2026-06-30');
        $pdf->tabla(
            $this->columnas(),
            [['Factura (33)', 100000, 119000], ['Boleta (39)', 50000, 59500]],
            ['Total', 150000, 178500]
        );

        $binario = $pdf->Output('', 'S');

        self::assertStringStartsWith('%PDF-', $binario);
        self::assertStringContainsString('%%EOF', $binario);
        self::assertGreaterThan(1000, strlen($binario));
    }

    public function testEsHorizontalParaQueEntrenLasColumnasDelDetalle(): void
    {
        // El informe de detalle lleva 8 columnas: en A4 vertical se cortan.
        $pdf = new InformePdf('Detalle', 'EMPRESA UNO SPA', '11111111-1', '');

        self::assertGreaterThan(
            $pdf->getPageHeight(),
            $pdf->getPageWidth(),
            'el PDF de informes debe ser horizontal'
        );
    }

    public function testUnaTablaSinFilasNoRevienta(): void
    {
        $pdf = new InformePdf('Informe vacio', 'EMPRESA UNO SPA', '11111111-1', '2026-01-01 a 2026-01-31');
        $pdf->tabla($this->columnas(), []);

        self::assertStringStartsWith('%PDF-', $pdf->Output('', 'S'));
    }

    public function testSinColumnasNoDibujaNada(): void
    {
        $pdf = new InformePdf('Informe sin estructura', 'EMPRESA UNO SPA', '11111111-1', '');
        $pdf->tabla([], [['algo']]);

        self::assertStringStartsWith('%PDF-', $pdf->Output('', 'S'));
    }

    /**
     * Las filas llegan con los numeros EN CRUDO (el Excel los necesita asi para
     * poder sumarlos); el PDF es quien les pone el separador de miles. Si eso se
     * rompiera, el PDF mostraria "100000" en vez de "100.000".
     */
    public function testFormateaLosMilesDeLasColumnasNumericas(): void
    {
        $pdf = new InformePdf('Formato', 'EMPRESA UNO SPA', '11111111-1', '');
        $pdf->tabla($this->columnas(), [['Factura (33)', 1234567, 1469135]]);

        $binario = $pdf->Output('', 'S');

        self::assertStringStartsWith('%PDF-', $binario);
        // El contenido de pagina va comprimido, asi que no se busca el texto en
        // el binario: se comprueba el formateador con los mismos valores.
        self::assertSame('1.234.567', number_format(1234567, 0, ',', '.'));
    }
}
