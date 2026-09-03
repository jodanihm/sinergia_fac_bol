<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Pago\MontoPasarela;

/**
 * Tests de la lectura del monto que informa la pasarela.
 *
 * DE DONDE SALEN. La version anterior hacia `(int) $monto` y luego exigia
 * `is_int()`. Dos defectos opuestos en una linea, y los dos caros:
 *
 *   - `(int) '49990.5'` da 49990 y `(int) 'mucho'` da 0: un valor corrupto se
 *     convertia en un numero de aspecto razonable ANTES de que nadie lo mirara.
 *   - Flow documenta amount como `number <float>`, asi que json_decode devuelve
 *     49990.0 para un pago perfectamente valido. Exigir is_int() lo habria
 *     RECHAZADO y mandado la factura a revision manual sin motivo.
 *
 * Estricto donde no debia, laxo donde importaba.
 */
final class MontoPasarelaTest extends TestCase
{
    /** @return list<array{mixed,int}> */
    public static function montosValidos(): array
    {
        return [
            'entero'                 => [49990, 49990],
            'float entero'           => [49990.0, 49990],
            'float entero grande'    => [1234567.0, 1234567],
            'cero'                   => [0, 0],
            'negativo entero'        => [-5, -5],
        ];
    }

    #[DataProvider('montosValidos')]
    public function testUnaCantidadEnteraSeAcepta(mixed $entrada, int $esperado): void
    {
        self::assertSame($esperado, MontoPasarela::normalizar($entrada));
    }

    public function testUnFloatEnteroNoSeRechazaPorSerFloat(): void
    {
        // EL CASO QUE HABRIA RECHAZADO UN PAGO BUENO. json_decode('49990.0')
        // devuelve float, y Flow documenta amount como number<float>.
        self::assertSame(49990, MontoPasarela::normalizar(49990.0));
        self::assertSame(49990, MontoPasarela::normalizar(json_decode('49990.0')));
        self::assertSame(49990, MontoPasarela::normalizar(json_decode('49990')));
    }

    /** @return list<array{mixed,string}> */
    public static function montosInvalidos(): array
    {
        return [
            'con decimales'   => [49990.5, 'no es una cantidad de pesos'],
            'NAN'             => [NAN, 'no es finito'],
            'INF'             => [INF, 'no es finito'],
            '-INF'            => [-INF, 'no es finito'],
            'null'            => [null, 'ausente'],
            'texto'           => ['mucho', 'texto'],
            'cadena numerica' => ['49990', 'string, aunque parezca un numero'],
            'cadena vacia'    => ['', 'vacia'],
            'true'            => [true, 'booleano'],
            'false'           => [false, 'booleano'],
            'array'           => [[49990], 'array'],
        ];
    }

    #[DataProvider('montosInvalidos')]
    public function testLoQueNoEsUnaCantidadEnteraSeRechaza(mixed $entrada, string $porque): void
    {
        // null significa "este dato no sirve", NUNCA cero: cero es un monto.
        self::assertNull(MontoPasarela::normalizar($entrada), $porque);
    }

    public function testUnaCadenaNumericaNoSeAceptaYEsDeliberado(): void
    {
        // json_decode() sin flags devuelve int o float para un numero de JSON,
        // nunca string. Una cadena solo llegaria si la pasarela mandara "49990"
        // entre comillas, contradiciendo el tipo `number` que ella documenta.
        // Aceptarla seria ensanchar la puerta sin una sola evidencia.
        self::assertNull(MontoPasarela::normalizar('49990'));
        self::assertIsInt(json_decode('49990'), 'el decoder da int, no string');
        self::assertIsFloat(json_decode('49990.0'), 'y da float cuando lleva punto');
    }

    public function testNoHayToleranciaNiRedondeo(): void
    {
        // El peso chileno no tiene decimales: un 49990.5 no es un problema de
        // precision, es un dato que no cuadra. Nada de epsilon.
        self::assertNull(MontoPasarela::normalizar(49990.5));
        self::assertNull(MontoPasarela::normalizar(49989.999999));
        self::assertSame(49991, MontoPasarela::normalizar(49991.0), 'pero un entero vecino si es valido');
    }

    public function testUnFloatFueraDelRangoDeEnteroSeRechaza(): void
    {
        // El cast seria silenciosamente incorrecto.
        self::assertNull(MontoPasarela::normalizar(1.0e30));
        self::assertNull(MontoPasarela::normalizar(-1.0e30));
    }
}
