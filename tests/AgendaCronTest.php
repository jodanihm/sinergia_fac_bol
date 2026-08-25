<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use AgendaCron;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../panel/src/AgendaCron.php';

/**
 * Tests de la agenda de tareas programadas.
 *
 * AgendaCron vive en panel/src/, fuera del autoload PSR-4, y se carga con un
 * require_once explicito -- mismo patron que RutasDelRouterTest.
 *
 * Lo que se protege aca es que la pantalla /admin/tareas no MIENTA sobre
 * cuando va a correr un cron. Una tabla que dice "proxima corrida 10:15" y se
 * equivoca es peor que no tener la tabla: quien la mira deja de revisar el
 * servidor. Por eso los casos incluyen los bordes donde un calculo ingenuo
 * falla: el cambio de hora, el cambio de dia, el 29 de febrero y la regla de
 * cron que suma dia-del-mes con dia-de-semana en vez de cruzarlos.
 *
 * El ultimo test corre contra el archivo de datos DE VERDAD, para que si
 * alguien agrega una tarea con una expresion mal escrita se entere aca y no en
 * la pantalla.
 */
final class AgendaCronTest extends TestCase
{
    public function testCadaCincoMinutosCaeEnLosMultiplosDeCinco(): void
    {
        $proximas = AgendaCron::proximas('*/5 * * * *', new DateTimeImmutable('2026-08-25 10:07:30'), 3);

        self::assertSame(
            ['2026-08-25 10:10', '2026-08-25 10:15', '2026-08-25 10:20'],
            $this->comoTexto($proximas)
        );
    }

    /**
     * El minuto en curso YA PASO. Con un paso de cinco minutos, a las 10:10:00
     * exactas la proxima es 10:15: cron ya disparo la de las 10:10 en el segundo 0.
     * Devolverla como futura haria que la pantalla muestre como pendiente algo
     * que acaba de ocurrir.
     */
    public function testLaEjecucionDelMinutoActualNoCuentaComoProxima(): void
    {
        $proximas = AgendaCron::proximas('*/5 * * * *', new DateTimeImmutable('2026-08-25 10:10:00'), 1);

        self::assertSame(['2026-08-25 10:15'], $this->comoTexto($proximas));
    }

    public function testCruzaElCambioDeHoraYElCambioDeDia(): void
    {
        $proximas = AgendaCron::proximas('*/15 * * * *', new DateTimeImmutable('2026-08-25 23:50:00'), 3);

        self::assertSame(
            ['2026-08-26 00:00', '2026-08-26 00:15', '2026-08-26 00:30'],
            $this->comoTexto($proximas)
        );
    }

    public function testHoraFijaDiariaSaltaAlDiaSiguienteSiYaPaso(): void
    {
        $proximas = AgendaCron::proximas('0 7 * * *', new DateTimeImmutable('2026-08-25 09:00:00'), 2);

        self::assertSame(['2026-08-26 07:00', '2026-08-27 07:00'], $this->comoTexto($proximas));
    }

    public function testMinutoFijoCadaSeisHoras(): void
    {
        // La forma del cron de correos bloqueados del proyecto hermano.
        $proximas = AgendaCron::proximas('15 */6 * * *', new DateTimeImmutable('2026-08-25 07:00:00'), 3);

        self::assertSame(
            ['2026-08-25 12:15', '2026-08-25 18:15', '2026-08-26 00:15'],
            $this->comoTexto($proximas)
        );
    }

    public function testListasYRangosConPaso(): void
    {
        $proximas = AgendaCron::proximas('0,30 9-11/2 * * *', new DateTimeImmutable('2026-08-25 08:00:00'), 5);

        self::assertSame(
            [
                '2026-08-25 09:00', '2026-08-25 09:30',
                '2026-08-25 11:00', '2026-08-25 11:30',
                '2026-08-26 09:00',
            ],
            $this->comoTexto($proximas)
        );
    }

    /**
     * El horizonte de busqueda tiene que cubrir un salto de anio bisiesto. Con
     * un limite de 365 dias esto devolveria vacio y la pantalla diria "--".
     */
    public function testEncuentraElVeintinueveDeFebreroAunqueFaltenCuatroAnios(): void
    {
        $proximas = AgendaCron::proximas('0 3 29 2 *', new DateTimeImmutable('2026-08-25 10:00:00'), 1);

        self::assertSame(['2028-02-29 03:00'], $this->comoTexto($proximas));
    }

    /**
     * La regla contraintuitiva de cron: con dia del mes Y dia de semana
     * restringidos, corre si calza CUALQUIERA. '0 0 13 * 5' no es "viernes 13",
     * es "todos los 13 y todos los viernes".
     */
    public function testConDiaDelMesYDiaDeSemanaRestringidosSumaEnVezDeCruzar(): void
    {
        // 2026-11-13 es viernes. El 6 de noviembre tambien es viernes.
        $proximas = AgendaCron::proximas('0 0 13 * 5', new DateTimeImmutable('2026-11-01 10:00:00'), 3);

        self::assertSame(
            ['2026-11-06 00:00', '2026-11-13 00:00', '2026-11-20 00:00'],
            $this->comoTexto($proximas)
        );
    }

    public function testConSoloDiaDeSemanaRestringidoNoAgregaOtrosDias(): void
    {
        $proximas = AgendaCron::proximas('0 0 * * 1', new DateTimeImmutable('2026-08-25 10:00:00'), 2);

        self::assertSame(['2026-08-31 00:00', '2026-09-07 00:00'], $this->comoTexto($proximas));
    }

    public function testElSieteYElCeroSonElMismoDomingo(): void
    {
        $conCero  = AgendaCron::proximas('0 0 * * 0', new DateTimeImmutable('2026-08-25 10:00:00'), 2);
        $conSiete = AgendaCron::proximas('0 0 * * 7', new DateTimeImmutable('2026-08-25 10:00:00'), 2);

        self::assertSame($this->comoTexto($conCero), $this->comoTexto($conSiete));
    }

    public function testLaFraseEnCastellanoSaleDeLaExpresion(): void
    {
        self::assertSame('cada 5 minutos', AgendaCron::enPalabras('*/5 * * * *'));
        self::assertSame('cada 15 minutos', AgendaCron::enPalabras('*/15 * * * *'));
        self::assertSame('cada 6 horas, en el minuto 15', AgendaCron::enPalabras('15 */6 * * *'));
        self::assertSame('todos los dias a las 07:00', AgendaCron::enPalabras('0 7 * * *'));
        self::assertSame('todos los dias a las 01:00 y 13:00', AgendaCron::enPalabras('0 1,13 * * *'));
        self::assertSame('los lunes a las 00:00', AgendaCron::enPalabras('0 0 * * 1'));
    }

    /**
     * Con muchas horas no se enumeran: un paso de cinco minutos son 288 corridas
     * al dia, y una celda con 288 horas no la lee nadie.
     */
    public function testNoEnumeraCuandoSonDemasiadasHoras(): void
    {
        self::assertSame('los lunes a 288 horas distintas del dia', AgendaCron::enPalabras('*/5 * * * 1'));
    }

    public function testLaFraseAvisaQueLaReglaDeCronSumaDias(): void
    {
        self::assertStringContainsString(' o ', AgendaCron::enPalabras('0 0 13 * 5'));
    }

    public function testFaltanRedondeaHaciaAbajo(): void
    {
        $ahora = new DateTimeImmutable('2026-08-25 10:00:00');

        self::assertSame('4 minutos', AgendaCron::faltan($ahora, new DateTimeImmutable('2026-08-25 10:04:59')));
        self::assertSame('1 minuto', AgendaCron::faltan($ahora, new DateTimeImmutable('2026-08-25 10:01:00')));
        self::assertSame('menos de un minuto', AgendaCron::faltan($ahora, new DateTimeImmutable('2026-08-25 10:00:30')));
        self::assertSame('2 horas', AgendaCron::faltan($ahora, new DateTimeImmutable('2026-08-25 12:30:00')));
        self::assertSame('3 dias', AgendaCron::faltan($ahora, new DateTimeImmutable('2026-08-28 11:00:00')));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('expresionesInvalidas')]
    public function testRechazaExpresionesInvalidas(string $expresion): void
    {
        $this->expectException(InvalidArgumentException::class);
        AgendaCron::proximas($expresion, new DateTimeImmutable('2026-08-25 10:00:00'), 1);
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function expresionesInvalidas(): array
    {
        return [
            'faltan campos'      => ['*/5 * * *'],
            'sobran campos'      => ['*/5 * * * * *'],
            'minuto fuera'       => ['60 * * * *'],
            'hora fuera'         => ['0 24 * * *'],
            'dia del mes cero'   => ['0 0 0 * *'],
            'mes trece'          => ['0 0 1 13 *'],
            'dia de semana ocho' => ['0 0 * * 8'],
            'paso cero'          => ['*/0 * * * *'],
            'rango al reves'     => ['0 9-5 * * *'],
            'basura'             => ['a b c d e'],
            'vacia'              => ['   '],
        ];
    }

    /**
     * El archivo de datos real, no un caso inventado: si alguien agrega una
     * tarea con la expresion mal escrita, la pantalla la marcaria en rojo y
     * quiza nadie la abre en un mes. Aca se entera enseguida.
     */
    public function testElCatalogoRealDeTareasSeEntiendeCompleto(): void
    {
        /** @var list<array<string, string>> $tareas */
        $tareas = require __DIR__ . '/../panel/datos/tareas_programadas.php';

        self::assertNotSame([], $tareas, 'el catalogo de tareas programadas no puede quedar vacio');

        $ahora = new DateTimeImmutable('2026-08-25 10:00:00');

        foreach ($tareas as $t) {
            foreach (['id', 'nombre', 'proposito', 'expresion', 'archivo', 'contenedor', 'comando', 'log', 'nota'] as $campo) {
                self::assertArrayHasKey($campo, $t, "la tarea '{$t['id']}' no declara '{$campo}'");
                self::assertNotSame('', trim((string) $t[$campo]), "la tarea '{$t['id']}' tiene '{$campo}' vacio");
            }

            self::assertNotSame(
                '',
                AgendaCron::enPalabras($t['expresion']),
                "no se pudo poner en palabras la expresion de '{$t['id']}'"
            );
            self::assertCount(
                3,
                AgendaCron::proximas($t['expresion'], $ahora, 3),
                "no se pudieron proyectar las proximas corridas de '{$t['id']}'"
            );
        }
    }

    /**
     * @param list<DateTimeImmutable> $momentos
     * @return list<string>
     */
    private function comoTexto(array $momentos): array
    {
        return array_map(static fn (DateTimeImmutable $m): string => $m->format('Y-m-d H:i'), $momentos);
    }
}
