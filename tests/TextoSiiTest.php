<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Dto\DatosEmisor;
use Plantiflex\FacturacionCl\Dto\Detalle;
use Plantiflex\FacturacionCl\Dto\DocumentoTributario;
use Plantiflex\FacturacionCl\Dto\Receptor;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Sii\DteXmlBuilder;
use Plantiflex\FacturacionCl\Sii\TextoSii;

/**
 * Texto que sobrevive intacto el paso UTF-8 (donde se firma) -> ISO-8859-1
 * (lo que se sube al SII). Origen: folio 43 de EASY AGENDA, RFR por un ’.
 */
final class TextoSiiTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function casos(): array
    {
        return [
            'folio 43: apostrofo curvo'  => ['Bernardo O’Higgins Nº580', "Bernardo O'Higgins Nº580"],
            'comillas curvas'            => ['“La Esquina” ‘SpA’', '"La Esquina" \'SpA\''],
            'rayas'                      => ['Asesoría – mes — octubre', 'Asesoría - mes - octubre'],
            'puntos suspensivos'         => ['Varios…', 'Varios...'],
            'espacio fino e invisibles'  => ["Av\u{202F}Sur\u{200B} 12", 'Av Sur 12'],
            'emoji se quita'             => ['Café ☕ rico', 'Café  rico'],
            'acento suelto (Mac)'        => ["Peñalole\u{0301}n Pin\u{0303}a", 'Peñalolén Piña'],
            'latin1 queda igual'         => ['Ñuñoa ÁÉÍÓÚ ü º ° ½', 'Ñuñoa ÁÉÍÓÚ ü º ° ½'],
            'ascii queda igual'          => ['EASY AGENDA SPA & Cia <x>', 'EASY AGENDA SPA & Cia <x>'],
        ];
    }

    #[DataProvider('casos')]
    public function testNormaliza(string $entrada, string $esperado): void
    {
        self::assertSame($esperado, TextoSii::normalizar($entrada));
    }

    #[DataProvider('casos')]
    public function testElResultadoSobreviveLaConversionAIso(string $entrada): void
    {
        $limpio = TextoSii::normalizar($entrada);
        $ida    = mb_convert_encoding($limpio, 'ISO-8859-1', 'UTF-8');
        self::assertSame($limpio, mb_convert_encoding($ida, 'UTF-8', 'ISO-8859-1'));
    }

    public function testEsIdempotente(): void
    {
        $una = TextoSii::normalizar('O’Higgins … “x”');
        self::assertSame($una, TextoSii::normalizar($una));
    }

    /**
     * El caso real: el DTE armado con la direccion del folio 43 ya no lleva
     * nada que la conversion a ISO-8859-1 vaya a cambiar.
     */
    public function testDteDelFolio43NoCambiaAlPasarAIso(): void
    {
        $doc = new DocumentoTributario(
            tipoDte:  TipoDte::FacturaElectronica,
            receptor: new Receptor(
                '78040258-K',
                'CENTRO TERAPÉUTICO TEMPLANZA SPA',
                'centros de salud mental y bienestar',
                'Bernardo O’Higgins Nº580, Oficina 111',
                'Osorno',
            ),
            detalles: [new Detalle('Pagina web + agenda', 1, 100000)],
            montosSonBrutos: false,
        );
        $emisor = new DatosEmisor(
            rutEmisor:        '77724622-4',
            razonSocial:      'Plantiflex SpA',
            giro:             'Venta de plantas',
            acteco:           477310,
            dirOrigen:        'Av Siempre Viva 123',
            cmnaOrigen:       'Santiago',
            resolucionFecha:  '2024-01-01',
            resolucionNumero: 0,
        );

        $dom  = (new DteXmlBuilder())->build($doc, $emisor, 43);
        $utf8 = (string) $dom->saveXML();
        $iso  = mb_convert_encoding($utf8, 'ISO-8859-1', 'UTF-8');

        self::assertSame($utf8, mb_convert_encoding($iso, 'UTF-8', 'ISO-8859-1'));
        $dir = $dom->getElementsByTagNameNS('http://www.sii.cl/SiiDte', 'DirRecep')->item(0);
        self::assertSame("Bernardo O'Higgins Nº580, Oficina 111", $dir?->textContent);
    }
}
