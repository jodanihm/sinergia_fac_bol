<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Correo\PreparadorEnvio;

/**
 * Tests del bloque de link de pago del correo.
 *
 * POR QUE ESTE TROZO TIENE TESTS PROPIOS y no se prueba dentro de preparar():
 * es el UNICO dato del correo que viene de fuera de casa. La razon social, el
 * folio y las fechas salen de nuestra base; la URL de pago la devolvio un
 * tercero, viaja por la base y acaba dentro de un href que va a abrir el cliente
 * de nuestro cliente. Ese camino merece que cada guarda este fijada por separado.
 *
 * Y hay una segunda razon, mas prosaica: preparar() necesita PDO y genera un PDF
 * de verdad, asi que probar cada combinacion por ahi seria carisimo. El bloque se
 * extrajo a un estatico puro justamente para poder hacer esto.
 */
final class BloqueLinkPagoTest extends TestCase
{
    private const URL = 'https://www.flow.cl/app/web/pay.php?token=ABC123';

    public function testPintaElBloqueCuandoTodoEstaEnSuSitio(): void
    {
        $html = PreparadorEnvio::bloqueLinkPago(self::URL, 'creado', 49990, 33);

        self::assertStringContainsString('href="' . self::URL . '"', $html);
        self::assertStringContainsString('$49.990', $html, 'el monto se escribe como se lee en Chile');
    }

    public function testElMontoLlevaPuntoDeMilesYNoDecimales(): void
    {
        $html = PreparadorEnvio::bloqueLinkPago(self::URL, 'creado', 1234567, 33);

        self::assertStringContainsString('$1.234.567', $html);
        self::assertStringNotContainsString(',00', $html);
    }

    public function testAvisaDeQueIgnoreElMensajeSiYaPago(): void
    {
        // Mientras no exista la conciliacion, un link sigue vivo aunque el
        // cliente haya pagado por transferencia. Sin esta frase, alguien paga
        // dos veces.
        self::assertStringContainsString(
            'ya la pago por otro medio',
            PreparadorEnvio::bloqueLinkPago(self::URL, 'creado', 1000, 33)
        );
    }

    // -----------------------------------------------------------------------
    //  Las cuatro guardas, una por una
    // -----------------------------------------------------------------------

    /** @return list<array{int}> */
    public static function tiposQueNoSeCobran(): array
    {
        return [[61], [56], [39], [52], [41]];
    }

    #[DataProvider('tiposQueNoSeCobran')]
    public function testNoPintaEnLosTiposQueNoSeCobran(int $tipoDte): void
    {
        // La 61 es la que mas importa: DEVUELVE dinero. Un boton de pagar ahi
        // hace que alguien pague de mas.
        self::assertSame('', PreparadorEnvio::bloqueLinkPago(self::URL, 'creado', 49990, $tipoDte));
    }

    public function testSoloPintaEnFacturaYFacturaExenta(): void
    {
        self::assertNotSame('', PreparadorEnvio::bloqueLinkPago(self::URL, 'creado', 1000, 33));
        self::assertNotSame('', PreparadorEnvio::bloqueLinkPago(self::URL, 'creado', 1000, 34));
        self::assertSame([33, 34], PreparadorEnvio::TIPOS_CON_LINK_PAGO);
    }

    /** @return list<array{?string}> */
    public static function estadosQueNoPintan(): array
    {
        return [['pendiente'], ['error'], ['omitido'], ['pagado'], [null], ['']];
    }

    #[DataProvider('estadosQueNoPintan')]
    public function testSoloPintaConLaOrdenCreada(?string $estado): void
    {
        self::assertSame('', PreparadorEnvio::bloqueLinkPago(self::URL, $estado, 49990, 33));
    }

    public function testAQuienYaPagoNoSeLeVuelveAOfrecerPagar(): void
    {
        self::assertSame('', PreparadorEnvio::bloqueLinkPago(self::URL, 'pagado', 49990, 33));
    }

    public function testUnCorreoSoltadoAManoNoLlevaLink(): void
    {
        // 'omitido' es la decision humana de mandar el correo sin link porque la
        // pasarela no responde. Tiene que ganarle a que la orden exista.
        self::assertSame('', PreparadorEnvio::bloqueLinkPago(self::URL, 'omitido', 49990, 33));
    }

    /** @return list<array{?string}> */
    public static function urlsQueNoSePintan(): array
    {
        return [
            ['http://www.flow.cl/pay'],            // sin cifrar
            ['javascript:alert(1)'],               // el caso feo
            ['//flow.cl/pay'],                     // sin esquema
            ['data:text/html,<script>x</script>'],
            [''],
            ['   '],
            [null],
        ];
    }

    #[DataProvider('urlsQueNoSePintan')]
    public function testSoloSePintaUnaUrlHttps(?string $url): void
    {
        self::assertSame('', PreparadorEnvio::bloqueLinkPago($url, 'creado', 49990, 33));
    }

    public function testUnaUrlConComillasNoPuedeRomperElHref(): void
    {
        $venenosa = 'https://flow.cl/pay?t=1"><script>alert(1)</script>';

        $html = PreparadorEnvio::bloqueLinkPago($venenosa, 'creado', 1000, 33);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&quot;', $html, 'la comilla sale escapada');
    }

    /** @return list<array{?int}> */
    public static function montosQueNoPintan(): array
    {
        return [[0], [-1], [null]];
    }

    #[DataProvider('montosQueNoPintan')]
    public function testSinMontoNoSePideQuePaguen(int|null $monto): void
    {
        // Un correo que dice "paga" sin decir cuanto es peor que uno sin link.
        self::assertSame('', PreparadorEnvio::bloqueLinkPago(self::URL, 'creado', $monto, 33));
    }

    // -----------------------------------------------------------------------
    //  La garantia de no-regresion
    // -----------------------------------------------------------------------

    public function testSinLinkElBloqueEsVacioYElCuerpoQuedaIgualQueSiempre(): void
    {
        // Todo documento anterior a esta funcion, y todo el que no lleve cobro,
        // tiene que producir EXACTAMENTE el mismo correo que producia antes. El
        // bloque vacio en un sprintf('%s') no deja ni un caracter.
        self::assertSame('', PreparadorEnvio::bloqueLinkPago(null, null, null, 33));
        self::assertSame('', PreparadorEnvio::bloqueLinkPago(null, null, null, 61));
    }
}
