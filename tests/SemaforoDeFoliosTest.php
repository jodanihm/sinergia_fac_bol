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
    //  Cuantos folios pedirle al SII
    // -----------------------------------------------------------------------

    private function sugeridos(float $porDia, int $docs = 999, int $dias = 999): int
    {
        $sirve = $docs >= self::constante('DASH_FOLIOS_HISTORIAL_MINIMO_DOCS')
            && $dias >= self::constante('DASH_FOLIOS_HISTORIAL_MINIMO_DIAS');

        $proyectado = $sirve ? (int) ceil($porDia * self::constante('DASH_FOLIOS_HORIZONTE_DIAS')) : 0;
        $n = (int) max(self::constante('DASH_FOLIOS_SOLICITUD_MINIMA'), $proyectado);

        foreach ([50, 100, 200, 300, 500, 750, 1000, 1500, 2000, 3000, 5000] as $escalon) {
            if ($n <= $escalon) {
                return $escalon;
            }
        }

        return (int) (ceil($n / 5000) * 5000);
    }

    /**
     * EL UNICO CASO CON HISTORIAL DE VERDAD. creapyme emitio 206 facturas
     * exentas en 32 dias corridos: 6,4 al dia, o sea ~1.159 para seis meses, que
     * redondeado es 1.500. Coincide con las dos cuentas hechas a mano en la
     * auditoria (por calendario y por jornadas), que daban ~1.240.
     */
    public function testElCasoConHistorialRealProyectaLoQueSeCalculoAMano(): void
    {
        self::assertSame(1500, $this->sugeridos(206 / 32, docs: 206, dias: 32));
    }

    /**
     * CON HISTORIAL FLACO NO SE PROYECTA. Al probar esto contra los datos
     * reales, la nota de credito de 78225195-3 sugeria 200 folios y lo
     * justificaba con "2 emitidos en 2 dias" -- y esos dos eran los DOS INTENTOS
     * FALLIDOS de esa semana. Proyectar seis meses desde ahi es fingir
     * precision, asi que cae al piso y la pantalla lo admite.
     */
    public function testConHistorialFlacoCaeAlPisoAunqueLaProyeccionSeaAlta(): void
    {
        // 1 al dia proyectaria 180 folios; 2 documentos en 2 dias no dan para eso.
        self::assertSame(50, $this->sugeridos(1.0, docs: 2, dias: 2));
        // Muchos documentos pero en pocos dias: tampoco.
        self::assertSame(50, $this->sugeridos(20.0, docs: 200, dias: 10));
        // Muchos dias pero pocos documentos: tampoco.
        self::assertSame(50, $this->sugeridos(0.1, docs: 4, dias: 41));
    }

    /**
     * Y LOS DEMAS CAEN AL PISO, que es lo que tiene que pasar. A 3 documentos al
     * mes la proyeccion da 18 folios, y pedirle 18 al SII es volver a la
     * ventanilla en dos meses.
     */
    #[DataProvider('emisoresChicos')]
    public function testUnEmisorChicoRecibeElPisoYNoLaProyeccion(float $porDia): void
    {
        self::assertSame(
            (int) self::constante('DASH_FOLIOS_SOLICITUD_MINIMA'),
            $this->sugeridos($porDia)
        );
    }

    public static function emisoresChicos(): array
    {
        return [
            '78454034-0 factura   (4 docs / 41 dias)' => [4 / 41],
            '78454034-0 nota debito (3 / 40)'         => [3 / 40],
            '77724622-4 factura   (1 doc / 37 dias)'  => [1 / 37],
            'sin historial'                           => [0.0],
        ];
    }

    /**
     * NUNCA SE REDONDEA A LA BAJA: dejar la sugerencia por debajo de la
     * proyeccion la convertiria en una que se queda corta, que es exactamente el
     * problema del que venimos.
     */
    #[DataProvider('proyecciones')]
    public function testElRedondeoNuncaQuedaBajoLaProyeccion(float $porDia): void
    {
        $proyectado = ceil($porDia * self::constante('DASH_FOLIOS_HORIZONTE_DIAS'));
        self::assertGreaterThanOrEqual($proyectado, $this->sugeridos($porDia));
    }

    public static function proyecciones(): array
    {
        return array_map(
            static fn (float $x): array => [$x],
            [0.0, 0.1, 0.5, 1.0, 3.0, 6.4, 12.0, 40.0, 100.0]
        );
    }

    /** Un emisor grande de verdad no queda atrapado en el ultimo escalon. */
    public function testUnEmisorMuyGrandeEscalaMasAllaDeLaTabla(): void
    {
        // 100 documentos al dia son 18.000 en seis meses.
        self::assertSame(20000, $this->sugeridos(100.0));
    }

    /**
     * EL DENOMINADOR DEL RITMO DE CALENDARIO VA HASTA HOY y no hasta la ultima
     * emision. Con "hasta la ultima", los 3 documentos que 78454034-0 emitio en
     * UNA tarde darian 3 al dia y proyectarian 540 folios. Contando hasta hoy
     * son 3 en 40 dias y cae al piso.
     */
    public function testUnaSolaJornadaNoSeProyectaComoRitmoDiario(): void
    {
        self::assertSame(50, $this->sugeridos(3 / 40, docs: 30, dias: 40));   // como se mide ahora
        self::assertSame(750, $this->sugeridos(3 / 1, docs: 30, dias: 40));   // como se habria medido mal
    }

    public function testElRouterMideElCalendarioHastaHoy(): void
    {
        $fuente = (string) file_get_contents(self::ROUTER);
        self::assertStringContainsString(
            'DATEDIFF(CURDATE(), DATE(MIN(created_at)))',
            $fuente,
            'el ritmo de calendario dejo de medirse hasta hoy: una sola jornada volveria a proyectarse como ritmo diario'
        );
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
