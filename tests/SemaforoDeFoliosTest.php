<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * EL SEMAFORO DE FOLIOS SE MIDE EN JORNADAS, NO EN PORCENTAJE.
 *
 * DE DONDE SALE ESTE TEST: de la auditoria del 04-09-2026. El nivel salia del
 * porcentaje del rango que quedaba, y en produccion eso pintaba de verde a
 * cuatro emisores que estaban por quedarse sin poder emitir:
 *
 *   78454034-0  nota de debito     1 folio    25%  ambar
 *   78454034-0  nota de credito    3 folios   75%  VERDE
 *   78225195-3  nota de credito    3 folios   60%  VERDE
 *   78225195-3  factura exenta   383 folios   65%  VERDE   <- 5,6 jornadas suyas
 *
 * El ultimo es el que obliga a medir el ritmo y no un numero absoluto: 383
 * folios son tres meses para un emisor de 1 documento por jornada y menos de
 * seis dias para uno de 68,7.
 *
 * COMO SE PRUEBA. La regla vive en dashFoliosPorTipo(), dentro de un front
 * controller de 19.000 lineas que al incluirlo arranca sesion, base y router.
 * Se copia AQUI la decision -- las cuatro lineas del if -- y un segundo test
 * comprueba que la copia sigue siendo fiel al original leyendo su fuente. Es la
 * misma solucion que usa NotasQueElSiiRechazaTest para el motor, con la
 * diferencia de que alli la funcion se puede extraer entera y aqui no, porque
 * necesita un PDO.
 */
final class SemaforoDeFoliosTest extends TestCase
{
    private const ROUTER = __DIR__ . '/../panel/public/index.php';

    /** Las constantes, leidas del router para no repetir los numeros a mano. */
    private static function constante(string $nombre): float
    {
        $fuente = (string) file_get_contents(self::ROUTER);
        self::assertSame(
            1,
            preg_match('/^const ' . $nombre . '\s*=\s*([0-9.]+);/m', $fuente, $m),
            "no se encontro {$nombre} en el router"
        );

        return (float) $m[1];
    }

    /** La decision, tal como la toma dashFoliosPorTipo(). */
    private function nivel(int $disponibles, float $ritmo): string
    {
        $jornadas = $disponibles / $ritmo;

        if ($disponibles === 0 || $jornadas < self::constante('DASH_FOLIOS_JORNADAS_ROJO')) {
            return 'rojo';
        }
        if ($jornadas < self::constante('DASH_FOLIOS_JORNADAS_AMBAR')) {
            return 'ambar';
        }

        return 'ok';
    }

    // -----------------------------------------------------------------------
    //  Los casos medidos en produccion el dia de la auditoria
    // -----------------------------------------------------------------------

    /**
     * @return array<string,array{0:int,1:float,2:string}>
     */
    public static function casosDeProduccion(): array
    {
        return [
            // emisor / tipo                     quedan  ritmo   esperado
            '78454034-0 nota de debito'      => [1,      3.0,    'rojo'],
            '78454034-0 nota de credito'     => [3,      1.0,    'rojo'],
            '78225195-3 nota de credito'     => [3,      1.0,    'rojo'],
            '78454034-0 factura'             => [6,      1.3,    'rojo'],
            '77724622-4 factura'             => [7,      1.0,    'ambar'],
            '78454034-0 factura exenta'      => [9,      1.0,    'ambar'],
            '78454034-0 boleta'              => [14,     1.0,    'ambar'],
            '78225195-3 factura exenta'      => [383,    68.7,   'ambar'],
        ];
    }

    #[DataProvider('casosDeProduccion')]
    public function testLosCasosMedidosEnProduccionQuedanBienClasificados(int $disponibles, float $ritmo, string $esperado): void
    {
        self::assertSame($esperado, $this->nivel($disponibles, $ritmo));
    }

    /**
     * EL CASO QUE OBLIGO A MEDIR EL RITMO. Los mismos 383 folios, con el ritmo
     * de un emisor normal, no tienen por que alarmar a nadie.
     */
    public function testLosMismos383FoliosSonHolguraParaUnEmisorLento(): void
    {
        self::assertSame('ambar', $this->nivel(383, 68.7));
        self::assertSame('ok', $this->nivel(383, 1.0));
    }

    /** Y al reves: un numero absoluto grande no salva a quien consume rapido. */
    public function testUnNumeroGrandeNoSalvaAQuienConsumeRapido(): void
    {
        self::assertSame('rojo', $this->nivel(200, 68.7));   // menos de 3 jornadas
        self::assertSame('ok', $this->nivel(2000, 68.7));    // 29 jornadas
    }

    public function testCeroDisponiblesSiempreEsRojo(): void
    {
        foreach ([1.0, 68.7, 1000.0] as $ritmo) {
            self::assertSame('rojo', $this->nivel(0, $ritmo));
        }
    }

    /**
     * SIN HISTORIAL SE SUPONE EL PISO OBSERVADO, no ritmo cero. Con ritmo cero
     * la division reventaria; tratandolo como "no consume" un emisor recien
     * habilitado con tres folios saldria verde, que es el agujero original.
     */
    public function testSinHistorialElPisoDejaEnRojoAlQueTienePocosFolios(): void
    {
        $piso = self::constante('DASH_FOLIOS_RITMO_MINIMO');
        self::assertSame(1.0, $piso, 'el piso observado en produccion es 1 documento por jornada');
        self::assertSame('rojo', $this->nivel(3, $piso));
        self::assertSame('ok', $this->nivel(50, $piso));
    }

    // -----------------------------------------------------------------------
    //  Que la copia de arriba siga siendo fiel al router
    // -----------------------------------------------------------------------

    public function testElRouterDecideElNivelPorJornadasYNoPorPorcentaje(): void
    {
        $fuente = (string) file_get_contents(self::ROUTER);

        self::assertStringContainsString(
            '$jornadas = $disponibles / $ritmo;',
            $fuente,
            'dashFoliosPorTipo() ya no calcula las jornadas: este test quedo obsoleto'
        );
        self::assertMatchesRegularExpression(
            '/if \(\$disponibles === 0 \|\| \$jornadas < DASH_FOLIOS_JORNADAS_ROJO\)/',
            $fuente,
            'el umbral rojo dejo de mirar las jornadas'
        );
        self::assertMatchesRegularExpression(
            '/\} elseif \(\$jornadas < DASH_FOLIOS_JORNADAS_AMBAR\)/',
            $fuente,
            'el umbral ambar dejo de mirar las jornadas'
        );
    }

    /**
     * Las constantes del porcentaje se fueron enteras. Si alguien las repone,
     * es que volvio la regla vieja -- y con ella el verde sobre tres folios.
     */
    public function testLasConstantesDelPorcentajeYaNoExisten(): void
    {
        $fuente = (string) file_get_contents(self::ROUTER);

        self::assertStringNotContainsString('DASH_FOLIOS_UMBRAL_ROJO', $fuente);
        self::assertStringNotContainsString('DASH_FOLIOS_UMBRAL_AMBAR', $fuente);
    }

    /**
     * El ritmo se mide por dias CON EMISION. Promediar sobre el calendario
     * diluiria las rafagas -- creapyme hizo 206 exentas en 3 jornadas -- hasta
     * volver el aviso inutil.
     */
    public function testElRitmoSeMidePorDiasConEmisionYNoPorCalendario(): void
    {
        $fuente = (string) file_get_contents(self::ROUTER);

        self::assertStringContainsString(
            'COUNT(DISTINCT DATE(created_at)) AS jornadas',
            $fuente,
            'dashRitmoPorTipo() dejo de contar jornadas de emision'
        );
        self::assertStringContainsString(
            "ambiente = 'produccion'",
            $fuente,
            'el ritmo tiene que medirse solo sobre produccion'
        );
    }
}
