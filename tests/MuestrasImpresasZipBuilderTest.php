<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Pdf\MuestrasImpresasZipBuilder;
use ZipArchive;

/**
 * Verifica el empaquetado ZIP de "Muestras Impresas" con la MISMA composicion
 * REAL de EASY AGENDA SPA (78157243-8):
 *   - Set Basico (envio 0253079814, aprobado EPR): 8 documentos exactos
 *     (4 factura 33 + 3 NC 61 + 1 ND 56) -- confirmado por
 *     setBasicoAprobado()/agruparEmitidosPorEnvio() del panel.
 *   - Simulacion (envio 0253082088): 30 documentos (22x33 + 6x61 + 2x56,
 *     confirmado en respuesta_simulacion_v2.json), de los cuales el panel
 *     solo toma 1 por TIPO (modo "porTipo", igual que
 *     scripts/generar_muestras_pdf.php) -> 3 documentos. Esa reduccion
 *     30->3 es responsabilidad de la consulta SQL del panel, no de esta
 *     clase; se paso ya reducida (verificado por separado con datos reales).
 *
 * MuestrasImpresasZipBuilder recibe el generador de PDF como CALLABLE
 * inyectable (ver el propio builder): renderizar un PDF real requiere un TED
 * firmado con un CAF real (LibreDTE/TCPDF no toleran datos sinteticos
 * incompletos), y esa generacion YA esta probada en produccion via
 * GET /api/v1/dte/{tipo}/{folio}/pdf (reusada TAL CUAL, sin cambios). Este
 * test verifica la parte NUEVA: conteo, nombrado y empaquetado ZIP, con un
 * generador FALSO liviano y determinista.
 */
final class MuestrasImpresasZipBuilderTest extends TestCase
{
    public function testZipDeMuestrasImpresasTieneLaCantidadCorrectaDeArchivos(): void
    {
        if (! class_exists(ZipArchive::class)) {
            self::markTestSkipped('La extension ZipArchive no esta disponible en este entorno de pruebas.');
        }

        // --- Set Basico: 8 documentos (4x33 + 3x61 + 1x56), igual que EASY AGENDA ---
        $documentos = [
            ['tipoDte' => 33, 'folio' => 33, 'xml' => '<xml-envio-set-basico/>', 'origen' => 'prueba'],
            ['tipoDte' => 33, 'folio' => 34, 'xml' => '<xml-envio-set-basico/>', 'origen' => 'prueba'],
            ['tipoDte' => 33, 'folio' => 35, 'xml' => '<xml-envio-set-basico/>', 'origen' => 'prueba'],
            ['tipoDte' => 33, 'folio' => 36, 'xml' => '<xml-envio-set-basico/>', 'origen' => 'prueba'],
            ['tipoDte' => 61, 'folio' => 25, 'xml' => '<xml-envio-set-basico/>', 'origen' => 'prueba'],
            ['tipoDte' => 61, 'folio' => 26, 'xml' => '<xml-envio-set-basico/>', 'origen' => 'prueba'],
            ['tipoDte' => 61, 'folio' => 27, 'xml' => '<xml-envio-set-basico/>', 'origen' => 'prueba'],
            ['tipoDte' => 56, 'folio' => 9,  'xml' => '<xml-envio-set-basico/>', 'origen' => 'prueba'],
            // --- Simulacion: YA REDUCIDA a 1 por tipo (modo "porTipo"), 30 -> 3 ---
            ['tipoDte' => 33, 'folio' => 59, 'xml' => '<xml-envio-simulacion/>', 'origen' => 'simulacion'],
            ['tipoDte' => 61, 'folio' => 34, 'xml' => '<xml-envio-simulacion/>', 'origen' => 'simulacion'],
            ['tipoDte' => 56, 'folio' => 12, 'xml' => '<xml-envio-simulacion/>', 'origen' => 'simulacion'],
        ];
        self::assertCount(11, $documentos);

        $generadorFalso = static fn (string $xml, bool $cedible, int $tipoDte, int $folio): string => sprintf(
            'PDF-FALSO xml=%s cedible=%s tipo=%d folio=%d',
            $xml,
            $cedible ? '1' : '0',
            $tipoDte,
            $folio
        );

        $resultado = (new MuestrasImpresasZipBuilder($generadorFalso))->construir($documentos);

        // 8 del Set Basico (4 facturas x2 por cedible + 3 NC + 1 ND = 12) +
        // 3 de la Simulacion (1 factura x2 + 1 NC + 1 ND = 4) = 16 archivos --
        // coincide EXACTO con el caso de referencia documentado
        // (docs/CERTIFICACION_MUESTRAS_IMPRESAS.md: 16 PDFs para Plantiflex).
        self::assertCount(16, $resultado['archivos']);

        $cedibles  = array_filter($resultado['archivos'], static fn (array $a): bool => $a['cedible']);
        $nombres   = array_column($resultado['archivos'], 'nombre');
        $porOrigen = array_count_values(array_column($resultado['archivos'], 'origen'));

        self::assertCount(5, $cedibles, 'Solo las 5 facturas (4 set basico + 1 simulacion) deben llevar cedible.');
        self::assertSame(12, $porOrigen['prueba'] ?? 0);
        self::assertSame(4, $porOrigen['simulacion'] ?? 0);
        self::assertContains('prueba_factura_33_folio33.pdf', $nombres);
        self::assertContains('prueba_factura_33_folio33_cedible.pdf', $nombres);
        self::assertContains('prueba_nc_61_folio25.pdf', $nombres);
        self::assertContains('prueba_nd_56_folio9.pdf', $nombres);
        self::assertContains('simulacion_factura_33_folio59.pdf', $nombres);
        self::assertContains('simulacion_factura_33_folio59_cedible.pdf', $nombres);
        // NC/ND no llevan cedible.
        self::assertNotContains('prueba_nc_61_folio25_cedible.pdf', $nombres);
        self::assertNotContains('simulacion_nd_56_folio12_cedible.pdf', $nombres);

        // El ZIP en si debe ser un archivo ZIP valido con exactamente 16 entradas,
        // y el contenido de cada entrada debe reflejar el PDF (falso) correcto.
        $tmp = tempnam(sys_get_temp_dir(), 'test_muestras_');
        file_put_contents($tmp, $resultado['zip']);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($tmp) === true);
        self::assertSame(16, $zip->numFiles);
        self::assertSame(
            'PDF-FALSO xml=<xml-envio-set-basico/> cedible=1 tipo=33 folio=33',
            $zip->getFromName('prueba_factura_33_folio33_cedible.pdf')
        );
        self::assertSame(
            'PDF-FALSO xml=<xml-envio-simulacion/> cedible=0 tipo=56 folio=12',
            $zip->getFromName('simulacion_nd_56_folio12.pdf')
        );
        $zip->close();
        unlink($tmp);
    }

    public function testFallaClaroSiNoHayDocumentos(): void
    {
        if (! class_exists(ZipArchive::class)) {
            self::markTestSkipped('La extension ZipArchive no esta disponible en este entorno de pruebas.');
        }

        $this->expectException(\RuntimeException::class);
        (new MuestrasImpresasZipBuilder(static fn () => ''))->construir([]);
    }
}
