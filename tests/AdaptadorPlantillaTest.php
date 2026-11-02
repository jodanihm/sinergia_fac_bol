<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use AdaptadorPlantilla;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../panel/src/AdaptadorPlantilla.php';

/**
 * La regla que estos tests cuidan: el adaptador cambia la FORMA de los datos,
 * nunca su significado. Cada caso de "se deja como venia" es tan importante
 * como los de "se normaliza": son los que impiden inventar.
 */
final class AdaptadorPlantillaTest extends TestCase
{
    /** Copia de NOTA_VENTA_ENCABEZADOS (vive en el router, que no se puede incluir aqui). */
    private const PLANTILLA = [
        'identificador_externo', 'rut_receptor', 'razon_social_receptor', 'giro_receptor',
        'direccion_receptor', 'comuna_receptor', 'email_receptor', 'fecha_nota',
        'producto_servicio', 'cantidad', 'precio_unitario', 'exento',
        'forma_pago', 'fecha_vencimiento',
        'folio_boleta_a_anular', 'fecha_boleta_a_anular',
        'precio_final',
    ];

    public function testLaCopiaDeLaPlantillaCoincideConLaDelRouter(): void
    {
        $router = (string) file_get_contents(__DIR__ . '/../panel/public/index.php');
        self::assertSame(1, preg_match('/const NOTA_VENTA_ENCABEZADOS = \[(.*?)\];/s', $router, $m));
        preg_match_all("/'([a-z_]+)'/", (string) preg_replace('#//[^\n]*#', '', $m[1]), $cols);
        self::assertSame(self::PLANTILLA, $cols[1]);
    }

    public function testElCatalogoDeSinonimosCubreLaPlantillaExacta(): void
    {
        AdaptadorPlantilla::verificarCatalogo(self::PLANTILLA);
        $this->expectException(\LogicException::class);
        AdaptadorPlantilla::verificarCatalogo([...self::PLANTILLA, 'columna_nueva']);
    }

    /** @return array<string, array{string, string}> */
    public static function encabezados(): array
    {
        return [
            'tildes y mayusculas' => ['Razón Social', 'razon_social'],
            'numero con simbolo'  => ['N° Pedido ', 'n_pedido'],
            'numero abreviado'    => ['Nro. Pedido', 'n_pedido'],
            'espacios multiples'  => ['  RUT   Cliente ', 'rut_cliente'],
            'ya normalizado'      => ['rut_receptor', 'rut_receptor'],
        ];
    }

    #[DataProvider('encabezados')]
    public function testNormalizaEncabezados(string $entrada, string $esperado): void
    {
        self::assertSame($esperado, AdaptadorPlantilla::normalizarEncabezado($entrada));
    }

    public function testProponeExactasYSinonimosYNoEligeLoAmbiguoNiLoDudoso(): void
    {
        $p = AdaptadorPlantilla::proponerEquivalencias(['RUT Cliente', 'fecha_nota', 'Precio', 'Total', 'Observaciones']);

        self::assertSame(['rut_receptor', 'sinonimo'], [$p[0]['destino'], $p[0]['nivel']]);
        self::assertSame(['fecha_nota', 'exacta'], [$p[1]['destino'], $p[1]['nivel']]);
        // "Precio" no dice si trae IVA: dos candidatas, ninguna elegida.
        self::assertNull($p[2]['destino']);
        self::assertSame('ambigua', $p[2]['nivel']);
        self::assertSame(['precio_unitario', 'precio_final'], $p[2]['candidatos']);
        // "Total" puede ser cantidad x precio: se sugiere, no se elige.
        self::assertNull($p[3]['destino']);
        self::assertSame(['dudosa', ['precio_final']], [$p[3]['nivel'], $p[3]['candidatos']]);
        self::assertNotNull($p[3]['condicion']);
        self::assertSame('sin_uso', $p[4]['nivel']);
    }

    public function testDosColumnasConElMismoDestinoQuedanAmbasSinElegir(): void
    {
        $p = AdaptadorPlantilla::proponerEquivalencias(['Correo', 'Email']);

        self::assertNull($p[0]['destino']);
        self::assertNull($p[1]['destino']);
        self::assertSame(['ambigua', 'ambigua'], [$p[0]['nivel'], $p[1]['nivel']]);
    }

    public function testEncuentraElEncabezadoDebajoDeUnTitulo(): void
    {
        $filas = [
            ['Ventas noviembre 2026', '', ''],
            ['', '', ''],
            ['Pedido', 'RUT', 'Producto'],
            ['A-1', '11111111-1', 'Algo'],
        ];
        self::assertSame(2, AdaptadorPlantilla::detectarEncabezado($filas)['indice']);
        self::assertNull(AdaptadorPlantilla::detectarEncabezado([['Ventas', 'noviembre'], ['1', '2']]));
    }

    public function testLaFirmaIgnoraMayusculasYTildesPeroNoElOrden(): void
    {
        self::assertSame(
            AdaptadorPlantilla::firma(['RUT', 'Razón Social']),
            AdaptadorPlantilla::firma(['rut', 'razon social'])
        );
        self::assertNotSame(
            AdaptadorPlantilla::firma(['RUT', 'Razón Social']),
            AdaptadorPlantilla::firma(['Razón Social', 'RUT'])
        );
    }

    /** @return array<string, array{string, ?string}> */
    public static function numeros(): array
    {
        return [
            'miles chilenos'          => ['10.000', '10000'],
            'con signo peso'          => ['$ 1.234.567', '1234567'],
            'miles y decimales CL'    => ['1.234,5', '1234.5'],
            'miles y decimales US'    => ['1,234.5', '1234.5'],
            'coma decimal'            => ['10,5', '10.5'],
            'punto decimal'           => ['10.5', '10.5'],
            'entero'                  => ['42', '42'],
            'coma ambigua'            => ['1,500', null],
            'grupos mal formados'     => ['1.23.4', null],
            'texto'                   => ['diez', null],
        ];
    }

    #[DataProvider('numeros')]
    public function testNumerosSoloSeConviertenCuandoNoHayDosLecturas(string $entrada, ?string $esperado): void
    {
        [$n, $motivo] = AdaptadorPlantilla::numero($entrada);
        self::assertSame($esperado, $n);
        if ($esperado === null) {
            self::assertNotNull($motivo);
        }
    }

    /** @return array<string, array{string, ?string}> */
    public static function fechas(): array
    {
        return [
            'iso'                 => ['2026-11-01', '2026-11-01'],
            'iso con hora'        => ['2026-11-01 00:00:00', '2026-11-01'],
            'chilena con barra'   => ['01/11/2026', '2026-11-01'],
            'chilena con punto'   => ['1.11.2026', '2026-11-01'],
            'mes en palabras'     => ['1 de noviembre de 2026', '2026-11-01'],
            'mes abreviado'       => ['01-nov-2026', '2026-11-01'],
            'año de dos digitos'  => ['01-11-26', null],
            'fecha imposible'     => ['31/02/2026', null],
            'texto'               => ['mañana', null],
        ];
    }

    #[DataProvider('fechas')]
    public function testFechas(string $entrada, ?string $esperado): void
    {
        self::assertSame($esperado, AdaptadorPlantilla::fecha($entrada));
    }

    public function testElRutSoloCambiaDeFormaNuncaDeDigitos(): void
    {
        self::assertSame(['12345678-5', 'formato_rut', null], AdaptadorPlantilla::normalizarValor('rut_receptor', '12.345.678-5'));
        self::assertSame(['12345678-K', 'formato_rut', null], AdaptadorPlantilla::normalizarValor('rut_receptor', '12345678k'));
        // Incompleto: se deja como venia (sin digito verificador no se inventa uno).
        self::assertSame('1234', AdaptadorPlantilla::normalizarValor('rut_receptor', '1234')[0]);
    }

    public function testValoresDeListaCerradaSinEquivalenciaQuedanComoVenian(): void
    {
        self::assertSame(['CREDITO', 'formato_texto', null], AdaptadorPlantilla::normalizarValor('forma_pago', 'Crédito'));
        self::assertSame(['Efectivo', null, null], AdaptadorPlantilla::normalizarValor('forma_pago', 'Efectivo'));
        self::assertSame(['CONTADO', 'equivalencia_valor', null], AdaptadorPlantilla::normalizarValor('forma_pago', 'Efectivo', ['Efectivo' => 'CONTADO']));
        self::assertSame(['SI', 'formato_texto', null], AdaptadorPlantilla::normalizarValor('exento', 'Sí'));
        self::assertSame(['x', null, null], AdaptadorPlantilla::normalizarValor('exento', 'x'));
    }

    public function testValoresSinReconocerSonLosDistintosQueLaCargaRechazaria(): void
    {
        $datos = [['Efectivo'], ['contado'], ['Efectivo'], [''], ['30 dias']];
        self::assertSame(['Efectivo', '30 dias'], AdaptadorPlantilla::valoresSinReconocer($datos, 0, 'forma_pago'));
    }

    public function testAplicarReordenaNormalizaYNoPierdeNiDuplicaFilas(): void
    {
        // Archivo de cliente: otro orden, una fila vacia en medio, un numero
        // real del Excel (float) y montos escritos como texto.
        $datos = [
            ['Servicio A', ' 12.345.678-5 ', 'P-1', 2, '$ 10.000', '01/11/2026'],
            ['', '', '', '', '', ''],
            ['Servicio B', '12345678-5', 'P-2', 1.234, '1.234,5', '2026-11-01'],
        ];
        $mapeo = [0 => 'producto_servicio', 1 => 'rut_receptor', 2 => 'identificador_externo', 3 => 'cantidad', 4 => 'precio_unitario', 5 => 'fecha_nota'];

        $r = AdaptadorPlantilla::aplicar($datos, 5, self::PLANTILLA, $mapeo, ['forma_pago' => 'CONTADO'], [], null);

        self::assertCount(2, $r['filas']);
        self::assertSame([6], $r['vacias']);
        self::assertSame(count($datos), count($r['filas']) + count($r['vacias']), 'ninguna fila se pierde ni se duplica');

        $f = $r['filas'][0];
        self::assertSame(array_merge(self::PLANTILLA, ['fila_origen']), array_keys($f));
        self::assertSame('5', $f['fila_origen']);
        self::assertSame('12345678-5', $f['rut_receptor']);
        self::assertSame('2', $f['cantidad']);
        self::assertSame('10000', $f['precio_unitario']);
        self::assertSame('2026-11-01', $f['fecha_nota']);
        self::assertSame('CONTADO', $f['forma_pago']);
        self::assertSame('', $f['email_receptor'], 'lo que el archivo no trae queda vacio, no se completa');

        // El 1.234 de una celda numerica es uno coma dos, no mil doscientos.
        self::assertSame('1.234', $r['filas'][1]['cantidad']);
        self::assertSame('1234.5', $r['filas'][1]['precio_unitario']);

        $tipos = array_count_values(array_column($r['cambios'], 'tipo'));
        self::assertSame(2, $tipos['valor_indicado']);
        self::assertSame(2, $tipos['formato_numero']);
        self::assertArrayNotHasKey('identificador_por_fila', $tipos);
    }

    public function testElValorIndicadoNoPisaElDelArchivo(): void
    {
        $r = AdaptadorPlantilla::aplicar([['CREDITO'], ['']], 2, self::PLANTILLA, [0 => 'forma_pago'], ['forma_pago' => 'CONTADO'], [], null);
        // La segunda fila esta vacia del todo y se descarta; la primera conserva su valor.
        self::assertSame('CREDITO', $r['filas'][0]['forma_pago']);
    }

    public function testIdentificadorPorFilaSoloCuandoSeAutoriza(): void
    {
        $datos = [['Servicio A'], ['Servicio B']];
        $sin = AdaptadorPlantilla::aplicar($datos, 2, self::PLANTILLA, [0 => 'producto_servicio'], [], [], null);
        self::assertSame('', $sin['filas'][0]['identificador_externo']);

        $con = AdaptadorPlantilla::aplicar($datos, 2, self::PLANTILLA, [0 => 'producto_servicio'], [], [], 'abc12345');
        self::assertSame(['abc12345-F2', 'abc12345-F3'], array_column($con['filas'], 'identificador_externo'));
    }

    public function testLoAmbiguoQuedaComoVeniaYSeInforma(): void
    {
        $r = AdaptadorPlantilla::aplicar([['1,500']], 2, self::PLANTILLA, [0 => 'precio_unitario'], [], [], null);
        self::assertSame('1,500', $r['filas'][0]['precio_unitario']);
        self::assertCount(1, $r['sin_interpretar']);
        self::assertSame(2, $r['sin_interpretar'][0]['fila']);
    }

    public function testTodaColumnaDeLaPlantillaTieneEtiqueta(): void
    {
        self::assertSame(self::PLANTILLA, array_keys(AdaptadorPlantilla::ETIQUETAS));
    }

    public function testPendientes(): void
    {
        $todo = [0 => 'identificador_externo', 1 => 'rut_receptor', 2 => 'fecha_nota', 3 => 'producto_servicio', 4 => 'cantidad', 5 => 'precio_final'];
        self::assertSame([], AdaptadorPlantilla::pendientes($todo, ['forma_pago' => 'CONTADO'], false));

        $p = AdaptadorPlantilla::pendientes($todo, [], false);
        self::assertCount(1, $p);
        self::assertStringContainsString('forma de pago', $p[0]);

        // Sin precio, y con el identificador cubierto por la opcion de fila.
        $sinPrecio = $todo;
        unset($sinPrecio[5], $sinPrecio[0]);
        $p = AdaptadorPlantilla::pendientes($sinPrecio, ['forma_pago' => 'CONTADO'], true);
        self::assertCount(1, $p);
        self::assertStringContainsString('precio', $p[0]);

        // Un monto no se completa con un valor fijo.
        self::assertNotSame([], AdaptadorPlantilla::pendientes($todo, ['forma_pago' => 'CONTADO', 'cantidad' => '1'], false));
        // Dos columnas al mismo destino.
        self::assertNotSame([], AdaptadorPlantilla::pendientes($todo + [6 => 'rut_receptor'], ['forma_pago' => 'CONTADO'], false));
    }
}
