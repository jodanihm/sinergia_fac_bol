<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Sii\DatosContribuyenteSiiParser;
use RuntimeException;

/**
 * Fixture REAL: tests/fixtures/ce_consulta_muestra_e_dwnld_78454034-0.csv --
 * archivo de "Datos para Construccion DTE" (pe_construccion_dte) del SII
 * para SINERGIA INNOVACION APLICADA SPA (78454034-0). Se lee tal cual viene
 * (ISO-8859-1), NUNCA reescrito a mano.
 *
 * Nota: la razon social y el giro reales SI traen tildes ("INNOVACIÓN",
 * "comercialización", "tecnológicas") -- se verifican exactas, no en ASCII.
 */
final class DatosContribuyenteSiiParserTest extends TestCase
{
    private function fixture(): string
    {
        $bytes = file_get_contents(__DIR__ . '/fixtures/ce_consulta_muestra_e_dwnld_78454034-0.csv');
        self::assertNotFalse($bytes, 'No se pudo leer el fixture real.');

        return $bytes;
    }

    public function testExtraeRutYRazonSocialConTildesExactas(): void
    {
        $datos = (new DatosContribuyenteSiiParser())->parse($this->fixture());

        self::assertSame('78454034-0', $datos->rut);
        self::assertSame('SINERGIA INNOVACIÓN APLICADA SPA', $datos->razonSocial);
    }

    public function testExtraeDireccionYComunaSeparadas(): void
    {
        $datos = (new DatosContribuyenteSiiParser())->parse($this->fixture());

        self::assertSame('CARELMAPU II 520 PLZA DEL', $datos->direccion);
        self::assertSame('VALDIVIA', $datos->comuna);
    }

    public function testExtraeGiroConTildesExactas(): void
    {
        $datos = (new DatosContribuyenteSiiParser())->parse($this->fixture());

        self::assertSame(
            'Desarrollo y comercialización de software y soluciones tecnológicas',
            $datos->giro,
        );
    }

    public function testExtraeLasDosActividadesEconomicasDelArchivoReal(): void
    {
        $datos = (new DatosContribuyenteSiiParser())->parse($this->fixture());

        self::assertCount(2, $datos->actividades);

        self::assertSame(620200, $datos->actividades[0]->codigo);
        self::assertSame(
            'ACTIVIDADES DE CONSULTORIA DE INFORMATICA Y DE GESTION DE INSTALACIONE',
            $datos->actividades[0]->descripcion,
        );
        self::assertTrue($datos->actividades[0]->afectoIva);

        self::assertSame(631200, $datos->actividades[1]->codigo);
        self::assertSame('PORTALES WEB', $datos->actividades[1]->descripcion);
        self::assertTrue($datos->actividades[1]->afectoIva);
    }

    public function testActecoPrincipalEsElPrimeroDeLaTablaPorFaltaDeMarcaExplicita(): void
    {
        $datos = (new DatosContribuyenteSiiParser())->parse($this->fixture());

        self::assertSame(620200, $datos->actecoPrincipal());
    }

    public function testArchivoIrreconocibleLanzaExcepcionClara(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Rut Contribuyente');

        (new DatosContribuyenteSiiParser())->parse("Contenido cualquiera\nque no es el formato esperado del SII\n");
    }
}
