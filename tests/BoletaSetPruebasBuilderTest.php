<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Sii\BoletaSetPruebasBuilder;

/**
 * Verifica que la plantilla fija reproduce LITERALMENTE los 5 CASO ya
 * usados y aceptados por el SII en scripts/emitir_set_boletas_ea.php -- no
 * es data inventada, es copia exacta (glosas, cantidades, precios,
 * exento/afecto, unidad de medida).
 */
final class BoletaSetPruebasBuilderTest extends TestCase
{
    public function testCasosTraeLosCincoCasosConSusGlosasExactas(): void
    {
        $casos = (new BoletaSetPruebasBuilder())->casos();

        self::assertCount(5, $casos);
        self::assertSame(['CASO-1', 'CASO-2', 'CASO-3', 'CASO-4', 'CASO-5'], array_column($casos, 'nombre'));

        self::assertSame('Cambio de aceite', $casos[0]['detalles'][0]['nombre']);
        self::assertSame(1, $casos[0]['detalles'][0]['cantidad']);
        self::assertSame(19900, $casos[0]['detalles'][0]['precioUnitario']);
        self::assertSame('Alineacion y balanceo', $casos[0]['detalles'][1]['nombre']);

        self::assertSame('Papel de regalo', $casos[1]['detalles'][0]['nombre']);
        self::assertSame(17, $casos[1]['detalles'][0]['cantidad']);
        self::assertSame(120, $casos[1]['detalles'][0]['precioUnitario']);

        self::assertSame('Arroz', $casos[4]['detalles'][0]['nombre']);
        self::assertSame('Kg', $casos[4]['detalles'][0]['unidad']);
    }

    public function testConstruirDocumentosArma5BoletasBrutoConReferenciaAlSet(): void
    {
        $docs = (new BoletaSetPruebasBuilder())->construirDocumentos();

        self::assertCount(5, $docs);
        foreach ($docs as $doc) {
            self::assertSame(TipoDte::BoletaElectronica, $doc->tipoDte);
            self::assertTrue($doc->montosSonBrutos);
            self::assertSame('66666666-6', $doc->receptor->rut);
            self::assertSame('Consumidor Final', $doc->receptor->razonSocial);
            self::assertCount(1, $doc->referencias);

            // "SET" EN TpoDocRef: lo exige el punto I.6 del instructivo del SII
            // (ver el comentario de BoletaSetPruebasBuilder). Sin esto la
            // revision del set responde "Tipo Doc. 00 / Folio 0" -- este assert
            // existe para que nadie lo vuelva a quitar por el razonamiento de
            // que "SET" no es un tipo tributario valido.
            self::assertSame('SET', $doc->referencias[0]['tipoDocumento']);

            // CodRef se mantiene ADEMAS: es lo que muestra el archivo del set
            // entregado al contribuyente. Los dos juntos ya pasaron el esquema.
            self::assertSame('SET', $doc->referencias[0]['codigo']);
        }

        // Con GUION, tal cual titula los casos el archivo del set. El
        // instructivo escribe "CASO xxxxx-x" con espacio, pero eso es una
        // plantilla para sets cuyo caso se numera "1062-1"; el de boleta no.
        self::assertSame('CASO-1', $docs[0]->referencias[0]['razon']);
        self::assertSame('CASO-5', $docs[4]->referencias[0]['razon']);

        // CASO-4: item afecto 1 (8x1590) + item exento 2 (2x1000, exento=true).
        $caso4 = $docs[3];
        self::assertCount(2, $caso4->detalles);
        self::assertFalse($caso4->detalles[0]->exento);
        self::assertTrue($caso4->detalles[1]->exento);
        self::assertSame(1000.0, $caso4->detalles[1]->precioUnitario);

        // CASO-5: Arroz con unidad de medida Kg.
        self::assertSame('Kg', $docs[4]->detalles[0]->unidad);
    }
}
