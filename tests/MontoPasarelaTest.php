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
 *
 * Y LUEGO LA CORRECCION SE PASO DE ESTRICTA POR EL OTRO LADO: rechazaba TODA
 * cadena, con el argumento de que un `"1190"` entre comillas contradice el tipo
 * `number` que Flow documenta. Flow Sandbox mando exactamente eso, y una factura
 * pagada quedo en estado 'error' con un descuadre inventado.
 *
 * Van dos suposiciones sobre el formato de Flow y dos desmentidas (la otra: que
 * el aviso viniera firmado). De ahi la forma de estos tests: los casos que se
 * ACEPTAN salen de respuestas vistas de verdad, y los que se RECHAZAN de lo que
 * podria ocultar un dato incorrecto -- sobre todo '1.000', que en Chile son mil
 * pesos y que is_numeric() + (int) convertiria en uno.
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
            // EL CASO REAL DE FLOW SANDBOX. Ver el test dedicado mas abajo.
            'cadena de digitos'      => ['49990', 49990],
            'cadena 1190'            => ['1190', 1190],
            'cadena cero'            => ['0', 0],
            'cadena negativa'        => ['-500', -500],
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
            'cadena vacia'    => ['', 'vacia'],
            'solo espacios'   => ['   ', 'no hay digitos'],
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

    public function testUnaCadenaDeDigitosSeAceptaPorqueFlowLaManda(): void
    {
        // EL BUG QUE ESTE TEST FIJA. Este mismo test decia lo contrario: que una
        // cadena "contradice el tipo number que Flow documenta" y que aceptarla
        // seria "ensanchar la puerta sin una sola evidencia". La evidencia llego
        // de Flow Sandbox: confirmo una orden como pagada y devolvio
        // amount: '1190', entre comillas. La factura acabo en estado 'error' con
        // un descuadre inventado -- "monto informado '1190' (normalizado NULL)
        // distinto del cobrado 1190" -- sobre un pago que estaba perfecto.
        self::assertSame(1190, MontoPasarela::normalizar('1190'));
        self::assertSame(49990, MontoPasarela::normalizar('49990'));

        // Y por json_decode tambien, que es como llega de verdad.
        $cuerpo = json_decode('{"status":2,"amount":"1190"}', true);
        self::assertSame(1190, MontoPasarela::normalizar($cuerpo['amount']));
    }

    /** @return list<array{string}> */
    public static function cadenasQueNoSonUnMontoLimpio(): array
    {
        return [
            'decimal con resto'      => ['1190.5'],
            'separador de miles'     => ['1.190'],
            'coma inglesa'           => ['1,190'],
            'con simbolo'            => ['$1190'],
            'con palabra'            => ['1190 pesos'],
            'con espacios alrededor' => [' 1190 '],
            'espacio en medio'       => ['1 190'],
            'vacia'                  => [''],
            'solo espacios'          => ['   '],
            'cientifica'             => ['1.19e3'],
            'cientifica sin punto'   => ['1e3'],
            'con signo mas'          => ['+1190'],
            'cero negativo'          => ['-0'],
            'ceros a la izquierda'   => ['049990'],
            'hexadecimal'            => ['0x4A6'],
            'texto'                  => ['mucho'],
            'nulo escrito'           => ['null'],
            'fuera de rango'         => ['9223372036854775808'],
            'fuera de rango negativo' => ['-9223372036854775809'],
        ];
    }

    #[DataProvider('cadenasQueNoSonUnMontoLimpio')]
    public function testUnaCadenaQueNoEsSoloDigitosSeRechaza(string $entrada): void
    {
        self::assertNull(MontoPasarela::normalizar($entrada), "'{$entrada}' no es un monto limpio");
    }

    public function testUnPuntoEnLaCadenaSeRechazaSIEMPRE_INCLUSO_CON_DECIMALES_EN_CERO(): void
    {
        // '1190.0' parece inofensivo por simetria con el float 1190.0. No lo es:
        // para distinguirlo de '1.000' -- que en Chile son MIL pesos, con el punto
        // como separador de miles -- habria que saber si el punto es decimal o de
        // miles, y eso no se puede saber mirando la cadena. La unica regla que no
        // se puede confundir es "ningun punto".
        self::assertNull(MontoPasarela::normalizar('1190.0'));
        self::assertNull(MontoPasarela::normalizar('1.000'), 'esto son mil pesos, no uno');

        // Lo que is_numeric() + (int) habria hecho con esa ultima, y es la razon
        // de no usarlos: convertir un cobro de mil pesos en uno de un peso.
        self::assertTrue(is_numeric('1.000'));
        self::assertSame(1, (int) '1.000');
    }

    public function testElFloatEquivalenteSiSeAceptaYNoEsIncoherente(): void
    {
        // La asimetria es a proposito: un float ya viene parseado por json_decode,
        // que sabe que el punto era decimal. Una cadena no trae esa informacion.
        self::assertSame(1190, MontoPasarela::normalizar(1190.0));
        self::assertNull(MontoPasarela::normalizar('1190.0'));
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
