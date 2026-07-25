<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use FechaExcel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../panel/src/FechaExcel.php';

/**
 * Tests del parseo de fechas de la carga masiva (M4).
 *
 * FechaExcel vive en panel/src/, que no esta en el autoload PSR-4 (ese cubre
 * solo src/, el motor). Se carga con un require_once explicito, igual que hace
 * panel/public/index.php con Auth/Db/Csrf/Rut.
 *
 * Los dos caminos que cubre la clase se prueban por separado:
 *   - celda de fecha NATIVA -> numero de serie, exacto y sin ambiguedad
 *   - celda de TEXTO        -> parseo tolerante, ambiguo por definicion en
 *                              DD/MM vs MM/DD
 */
final class FechaExcelTest extends TestCase
{
    // -----------------------------------------------------------------------
    //  Celdas de fecha nativas de Excel
    // -----------------------------------------------------------------------

    public function testUnaCeldaDeFechaNativaSeLeeDesdeElNumeroDeSerie(): void
    {
        $hoja = (new Spreadsheet())->getActiveSheet();
        // 46228 es el numero de serie de 2026-07-25 en el calendario de Excel.
        $hoja->setCellValue('A1', 46228);
        $hoja->getStyle('A1')->getNumberFormat()->setFormatCode('yyyy-mm-dd');
        $celda = $hoja->getCell('A1');

        self::assertTrue(FechaExcel::esCeldaDeFecha($celda));
        self::assertSame('2026-07-25', FechaExcel::aIso($celda));
    }

    public function testElFormatoDeVisualizacionNoAlteraLaFechaLeida(): void
    {
        // MISMO numero de serie, formato de visualizacion estadounidense. El
        // bug original: con este formato el valor llegaba como "7/25/2026" en
        // vez de la fecha. Leyendo el serial, el formato es irrelevante.
        $hoja = (new Spreadsheet())->getActiveSheet();
        $hoja->setCellValue('A1', 46228);
        $hoja->getStyle('A1')->getNumberFormat()->setFormatCode('mm/dd/yyyy');

        self::assertSame('2026-07-25', FechaExcel::aIso($hoja->getCell('A1')));
    }

    public function testUnaCeldaDeTextoNoSeConfundeConUnaDeFecha(): void
    {
        $hoja = (new Spreadsheet())->getActiveSheet();
        $hoja->setCellValueExplicit('A1', '2026-07-25', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);

        self::assertFalse(FechaExcel::esCeldaDeFecha($hoja->getCell('A1')));
    }

    public function testUnNumeroSinFormatoDeFechaNoEsUnaFecha(): void
    {
        // precio_unitario 46228 no puede leerse como si fuera una fecha.
        $hoja = (new Spreadsheet())->getActiveSheet();
        $hoja->setCellValue('A1', 46228);

        self::assertFalse(FechaExcel::esCeldaDeFecha($hoja->getCell('A1')));
    }

    // -----------------------------------------------------------------------
    //  Texto
    // -----------------------------------------------------------------------

    /** @return list<array{0:string, 1:string}> */
    public static function fechasValidas(): array
    {
        return [
            'ISO con guion'            => ['2026-07-25', '2026-07-25'],
            'ISO con slash'            => ['2026/07/25', '2026-07-25'],
            'chileno con guion'        => ['25-07-2026', '2026-07-25'],
            'chileno con slash'        => ['25/07/2026', '2026-07-25'],
            'chileno sin cero inicial' => ['5/7/2026', '2026-07-05'],
            'con espacios alrededor'   => ['  25-07-2026  ', '2026-07-25'],
            'ambigua se lee como dia'  => ['05/07/2026', '2026-07-05'],
            'bisiesto real'            => ['29-02-2024', '2024-02-29'],
        ];
    }

    #[DataProvider('fechasValidas')]
    public function testNormalizaFechasValidas(string $entrada, string $esperado): void
    {
        self::assertSame($esperado, FechaExcel::normalizar($entrada));
    }

    /** @return list<array{0:string}> */
    public static function fechasInvalidas(): array
    {
        return [
            'vacia'                  => [''],
            'solo espacios'          => ['   '],
            'dia inexistente'        => ['30-02-2026'],
            'mes 13'                 => ['01-13-2026'],
            'dia 32'                 => ['32-01-2026'],
            'bisiesto que no es'     => ['29-02-2026'],
            'texto cualquiera'       => ['manana'],
            'ano de 2 digitos'       => ['25-07-26'],
            'formato estadounidense' => ['7/25/2026'],
            'numero suelto'          => ['46228'],
            'iso con hora'           => ['2026-07-25 10:30:00'],
        ];
    }

    #[DataProvider('fechasInvalidas')]
    public function testRechazaFechasInvalidas(string $entrada): void
    {
        self::assertNull(FechaExcel::normalizar($entrada));
    }

    public function testUnTextoEnFormatoEstadounidenseSeRechazaEnVezDeLeerseAlReves(): void
    {
        // "7/25/2026" es MM/DD. Bajo la lectura chilena el dia seria 7 y el mes
        // 25, que no existe: se rechaza. Es el desenlace deseado, porque el
        // valor NO se guarda mal en silencio.
        self::assertNull(FechaExcel::normalizar('7/25/2026'));
    }

    public function testLaAmbiguedadRealSeResuelveComoDiaMes(): void
    {
        // Este es el unico caso que puede quedar mal leido si el archivo lo
        // genero un Excel en ingles: alla "05/07/2026" es 7 de mayo. Se
        // documenta con un test para que el criterio sea explicito y un cambio
        // futuro tenga que romperlo a proposito.
        self::assertSame('2026-07-05', FechaExcel::normalizar('05/07/2026'));
    }
}
