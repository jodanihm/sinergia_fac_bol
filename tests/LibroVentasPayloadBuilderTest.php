<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Sii\LibroVentasPayloadBuilder;

/**
 * Compara el payload generado por LibroVentasPayloadBuilder contra
 * payload_libro_ventas_v2.json: el payload que el SII realmente ACEPTO
 * (LOK/LTC/SOK) para el envio 0253079814.
 */
final class LibroVentasPayloadBuilderTest extends TestCase
{
    public function testPayloadGeneradoEsEquivalenteAlAceptadoPorElSii(): void
    {
        $esperadoJson = file_get_contents(__DIR__ . '/../payload_libro_ventas_v2.json');
        self::assertNotFalse($esperadoJson);
        $esperado = json_decode($esperadoJson, true, flags: JSON_THROW_ON_ERROR);

        // "Documentos ya emitidos" reconstruidos desde el propio payload
        // aceptado (mismos neto/iva/total/fecha/folio/tipoDte que habria en
        // dte_emitido tras la emision real). mntExe NO se pasa: es lo que el
        // builder debe DERIVAR (total - neto - iva) y se compara contra el
        // mntExe que el SII realmente acepto para cada linea.
        $documentosEmitidos = array_map(static fn (array $l): array => [
            'tipoDte'      => $l['tpoDoc'],
            'folio'        => $l['nroDoc'],
            'fechaEmision' => $l['fecha'],
            'neto'         => $l['mntNeto'],
            'iva'          => $l['mntIva'],
            'total'        => $l['mntTotal'],
        ], $esperado['lineas']);

        $resultado = (new LibroVentasPayloadBuilder())->construir($documentosEmitidos, $esperado['folioNotificacion']);

        self::assertSame([], $resultado->errores);
        self::assertSame($esperado, $resultado->payload);
    }
}
