<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Sii\SetBasicoPayloadBuilder;
use Plantiflex\FacturacionCl\Sii\SetPruebasParser;

/**
 * Compara el payload generado por SetBasicoPayloadBuilder, a partir del
 * archivo REAL de EASY AGENDA SPA, contra payload_set_basico_v2.json: el
 * payload que el SII realmente ACEPTO (EPR + SOK) cuando se armo a mano. Si
 * cualquier campo difiere sin una razon documentada, este test debe fallar.
 */
final class SetBasicoPayloadBuilderTest extends TestCase
{
    public function testPayloadGeneradoEsEquivalenteAlAceptadoPorElSii(): void
    {
        $bytes = file_get_contents(__DIR__ . '/../easyagenda/SIISetDePruebas781572438.txt');
        self::assertNotFalse($bytes);
        $parseado = (new SetPruebasParser())->parse($bytes);

        // Misma fecha que el payload historico (payload_set_basico_v2.json,
        // razon "CASO 4951090-N" -> las referencias SET fijaron fecha
        // 2026-07-13 ese dia): se inyecta para que la comparacion sea exacta,
        // no aproximada. En uso real (panel), se pasa la fecha de HOY.
        $resultado = (new SetBasicoPayloadBuilder())->construir($parseado, new DateTimeImmutable('2026-07-13'));

        self::assertSame([], $resultado->errores, 'El builder no debe reportar casos ambiguos para el archivo real de EASY AGENDA.');
        self::assertNotNull($resultado->payload);

        $esperadoJson = file_get_contents(__DIR__ . '/../payload_set_basico_v2.json');
        self::assertNotFalse($esperadoJson);
        $esperado = json_decode($esperadoJson, true, flags: JSON_THROW_ON_ERROR);

        // Desviacion CONOCIDA y documentada (ver comentario de la regla (a) en
        // SetBasicoPayloadBuilder::construir()): el payload historico, armado
        // a mano, puso en documentos[7].detalles[0].nombre una version
        // acortada ("ANULA NOTA DE CREDITO") en vez del texto completo de
        // razonReferencia ("ANULA NOTA DE CREDITO ELECTRONICA"). La regla (a)
        // de esta tarea pide usar razonReferencia TAL CUAL, sin acortar. Es
        // una glosa libre en una linea de monto 0 (no toca CodRef,
        // refIndiceLote ni montos): funcionalmente equivalente para el SII.
        // Se parchea ANTES de comparar para que el resto del test siga
        // detectando CUALQUIER OTRA diferencia real byte a byte.
        $esperado['documentos'][7]['detalles'][0]['nombre'] = 'ANULA NOTA DE CREDITO ELECTRONICA';

        self::assertSame($esperado, $resultado->payload);
    }
}
