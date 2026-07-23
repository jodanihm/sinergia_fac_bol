<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Sii\LibroComprasPayloadBuilder;
use Plantiflex\FacturacionCl\Sii\SetPruebasParser;

/**
 * Compara el payload generado por LibroComprasPayloadBuilder, a partir del
 * archivo REAL de EASY AGENDA SPA, contra payload_libro_compras_v2.json: el
 * payload que el SII realmente ACEPTO (LOK/LTC), con el fix de retencion
 * total del IVA ya aplicado.
 */
final class LibroComprasPayloadBuilderTest extends TestCase
{
    public function testPayloadGeneradoEsEquivalenteAlAceptadoPorElSii(): void
    {
        $bytes = file_get_contents(__DIR__ . '/../easyagenda/SIISetDePruebas781572438.txt');
        self::assertNotFalse($bytes);
        $parseado = (new SetPruebasParser())->parse($bytes);

        // Misma fecha que el payload historico (fecha "2026-07-14" en las 7
        // lineas de payload_libro_compras_v2.json): se inyecta para
        // comparacion exacta, no aproximada.
        $resultado = (new LibroComprasPayloadBuilder())->construir($parseado, new DateTimeImmutable('2026-07-14'));

        self::assertSame([], $resultado->errores, 'El builder no debe reportar folios sin reconocer para el archivo real de EASY AGENDA.');
        self::assertNotNull($resultado->payload);

        $esperadoJson = file_get_contents(__DIR__ . '/../payload_libro_compras_v2.json');
        self::assertNotFalse($esperadoJson);
        $esperado = json_decode($esperadoJson, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame($esperado, $resultado->payload);
    }
}
