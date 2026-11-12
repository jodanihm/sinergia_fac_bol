<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\TestCase;
use Plantiflex\Integration\Facturacion\SincronizacionCliente;

/**
 * PUT /api/v1/clientes/{rut}: la regla de mezcla, sin base de datos.
 *
 * Lo que mas importa proteger: una sincronizacion que no trae giro ni comuna
 * (EasyAgenda no los tiene) NO puede borrar los que alguien cargo en el panel,
 * porque sin ellos la factura no se emite.
 */
final class SincronizacionClienteTest extends TestCase
{
    private const RUT = '76543210-3';

    /** @return array<string,mixed> */
    private function existente(array $sobre = []): array
    {
        return $sobre + [
            'id' => 7, 'rut_cliente' => self::RUT, 'razon_social' => 'Centro Uno SpA',
            'nombre_fantasia' => null, 'giro' => 'Servicios de salud', 'direccion' => 'Calle 1',
            'comuna' => 'Providencia', 'email' => 'a@b.cl', 'telefono' => null,
            'pago_link' => false, 'activo' => true, 'created_at' => '', 'updated_at' => '',
        ];
    }

    public function testCampoAusenteNoSeTocaYCampoPresenteSeEscribe(): void
    {
        $v = SincronizacionCliente::validar(self::RUT, ['razonSocial' => 'Centro Uno Ltda', 'telefono' => '+56911112222']);
        self::assertSame([], $v['errores']);

        $p = SincronizacionCliente::plan($this->existente(), $v['rut'], $v['campos'], $v['activo']);

        self::assertSame('actualizar', $p['accion']);
        self::assertSame('Centro Uno Ltda', $p['datos']['razon_social']);
        self::assertSame('+56911112222', $p['datos']['telefono']);
        self::assertSame('Servicios de salud', $p['datos']['giro'], 'el giro cargado a mano no se puede perder');
        self::assertSame('Providencia', $p['datos']['comuna']);
        self::assertFalse($p['datos']['pago_link'], 'pago_link no es sincronizable: se conserva');
    }

    public function testNullExplicitoBorraElCampo(): void
    {
        $v = SincronizacionCliente::validar(self::RUT, ['email' => null]);
        $p = SincronizacionCliente::plan($this->existente(), $v['rut'], $v['campos'], null);

        self::assertSame('actualizar', $p['accion']);
        self::assertNull($p['datos']['email']);
    }

    public function testDatosIgualesNoEscriben(): void
    {
        $v = SincronizacionCliente::validar(self::RUT, ['razonSocial' => ' Centro Uno SpA ', 'email' => 'a@b.cl']);
        $p = SincronizacionCliente::plan($this->existente(), $v['rut'], $v['campos'], true);

        self::assertSame('sin_cambios', $p['accion']);
        self::assertSame([], $p['datos']);
        self::assertNull($p['cambiarActivo']);
    }

    public function testSoloCambiarActivoNoReescribeLaFila(): void
    {
        $p = SincronizacionCliente::plan($this->existente(), self::RUT, [], false);

        self::assertSame('actualizar', $p['accion']);
        self::assertSame([], $p['datos']);
        self::assertFalse($p['cambiarActivo']);
    }

    public function testAltaExigeRazonSocial(): void
    {
        $p = SincronizacionCliente::plan(null, self::RUT, ['email' => 'x@y.cl'], null);
        self::assertNotNull($p['error']);
    }

    public function testAltaInactivaSeCreaYSeDesactiva(): void
    {
        $p = SincronizacionCliente::plan(null, self::RUT, ['razon_social' => 'Nuevo SpA'], false);

        self::assertSame('crear', $p['accion']);
        self::assertSame(self::RUT, $p['datos']['rut_cliente']);
        self::assertArrayNotHasKey('pago_link', $p['datos'], 'el alta toma el default de la tabla');
        self::assertFalse($p['cambiarActivo']);
    }

    public function testRutConPuntosSeNormaliza(): void
    {
        $v = SincronizacionCliente::validar('76.543.210-3', ['rut' => '76543210-3']);
        self::assertSame([], $v['errores']);
        self::assertSame(self::RUT, $v['rut']);
    }

    /** @return array<string,array{0:string,1:array<mixed>,2:string}> */
    public static function entradasInvalidas(): array
    {
        return [
            'dv malo'               => ['76543210-4', [], 'rut'],
            'rut del cuerpo otro'   => [self::RUT, ['rut' => '11111111-1'], 'rut'],
            'razon vacia'           => [self::RUT, ['razonSocial' => '  '], 'razonSocial'],
            'email malo'            => [self::RUT, ['email' => 'no-es-correo'], 'email'],
            'numero en texto'       => [self::RUT, ['telefono' => 56911112222], 'telefono'],
            'comuna larga'          => [self::RUT, ['comuna' => str_repeat('a', 101)], 'comuna'],
            'activo no booleano'    => [self::RUT, ['activo' => 1], 'activo'],
            'nombre en snake_case'  => [self::RUT, ['razon_social' => 'X'], 'razon_social'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('entradasInvalidas')]
    public function testEntradasInvalidas(string $rut, array $cuerpo, string $campo): void
    {
        $v = SincronizacionCliente::validar($rut, $cuerpo);
        self::assertArrayHasKey($campo, $v['errores']);
    }

    public function testListoParaFacturarTrataVacioComoFaltante(): void
    {
        self::assertTrue(SincronizacionCliente::aJson($this->existente())['listoParaFacturar']);
        self::assertFalse(SincronizacionCliente::aJson($this->existente(['comuna' => '']))['listoParaFacturar']);
        self::assertArrayNotHasKey('pago_link', SincronizacionCliente::aJson($this->existente()));
    }
}
