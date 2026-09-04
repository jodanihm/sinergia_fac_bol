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
        // La tilde va como entidad, igual que el &deg; que ya usaba el cuerpo:
        // el archivo se queda en ASCII y el lector la ve acentuada.
        self::assertStringContainsString(
            'ya la pag&oacute; por otro medio',
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

    // -----------------------------------------------------------------------
    //  El CTA: que se vea como un boton y no como un enlace suelto
    // -----------------------------------------------------------------------

    public function testElBotonLlevaElHrefYSuTextoDeLlamada(): void
    {
        $html = self::bloque();

        self::assertStringContainsString('href="' . self::URL . '"', $html);
        self::assertStringContainsString('>Pagar factura</a>', $html);
        self::assertStringContainsString('Paga tu factura en l&iacute;nea', $html);
    }

    public function testNombraElDocumentoConSuTipoYFolio(): void
    {
        $html = self::bloque(folio: 3, etiqueta: 'Factura electronica');

        self::assertStringContainsString('Factura electronica N&deg; 3', $html);
    }

    public function testSinTipoOSinFolioNoPintaUnaReferenciaCoja(): void
    {
        // Peor que no decir el documento es decir "N&deg; 0" o un numero sin
        // sustantivo delante: la linea entera se cae.
        self::assertStringNotContainsString('N&deg;', self::bloque(folio: 0, etiqueta: 'Factura electronica'));
        self::assertStringNotContainsString('N&deg;', self::bloque(folio: 7, etiqueta: ''));
    }

    public function testElMontoVaDestacadoYNoSoloEnUnaFrase(): void
    {
        $html = self::bloque(monto: 1190);

        // 30px es el tamano del monto: si alguien lo deja como texto corrido, el
        // recuadro pierde justo el dato por el que se abre el correo.
        self::assertMatchesRegularExpression('/font:700 30px[^"]*">\$1\.190</', $html);
    }

    public function testDiceQuienProcesaElPago(): void
    {
        self::assertStringContainsString(
            'de forma segura a trav&eacute;s de Flow',
            self::bloque()
        );
    }

    public function testLlevaLaUrlEnTextoPorSiElBotonNoLlega(): void
    {
        // Hay filtros corporativos que quitan o reescriben botones. Sin esta
        // linea, a quien le pase eso se queda sin forma de pagar.
        $html = self::bloque();

        self::assertStringContainsString('copia y pega esta direcci&oacute;n', $html);
        // La url, ademas del href, como texto visible dentro de un div.
        self::assertStringContainsString('>' . self::URL . '</div>', $html);
    }

    public function testSeVeIgualEnOutlook(): void
    {
        $html = self::bloque();

        // Outlook de escritorio renderiza con Word: ignora padding y background
        // sobre un <a> suelto, y no hay <head> donde colgar una hoja de estilos.
        // De ahi las tablas y el style= en cada etiqueta.
        self::assertStringContainsString('role="presentation"', $html);
        self::assertStringContainsString('cellpadding="0"', $html);
        self::assertStringNotContainsString('<style', $html);
        self::assertStringNotContainsString('class=', $html);
        // El color del boton va en el <td> Y en el <a>: si uno se pierde, el
        // boton sigue siendo un boton y no un texto invisible.
        self::assertSame(2, substr_count($html, 'background:#1f6feb'));
    }

    public function testNoSeCuelanTildesCrudas(): void
    {
        // Convencion del cuerpo del correo (ya usaba &deg;): ASCII en el archivo,
        // entidades para el lector. No depende de que cada pasarela de correo
        // respete el charset.
        $html = self::bloque(folio: 3, etiqueta: 'Factura', ambiente: 'sandbox');

        self::assertSame($html, (string) preg_replace('/[^\x00-\x7F]/', '', $html));
    }

    // -----------------------------------------------------------------------
    //  El aviso de sandbox
    // -----------------------------------------------------------------------

    public function testEnSandboxAvisaDeQueNoSeCobraDeVerdad(): void
    {
        $html = self::bloque(ambiente: 'sandbox');

        self::assertStringContainsString('PRUEBA', $html);
        self::assertStringContainsString('Flow Sandbox', $html);
        self::assertStringContainsString('no realizar&aacute; un cobro real', $html);
    }

    public function testElAvisoVaEncimaDelRecuadroYNoDentro(): void
    {
        // Tiene que leerse ANTES del monto y del boton, o no sirve de aviso.
        $html = self::bloque(ambiente: 'sandbox');

        self::assertLessThan(
            strpos($html, 'Pagar factura'),
            strpos($html, 'PRUEBA'),
            'el aviso de prueba va primero'
        );
    }

    #[DataProvider('ambientesQueNoSonSandbox')]
    public function testEnProduccionNoAparecePalabraDePrueba(?string $ambiente): void
    {
        // Un aviso que sale siempre deja de ser un aviso. Y si el dato viene roto
        // se calla: decir "no se te cobrara" sobre un cobro real es el unico de
        // los dos errores que le cuesta dinero a alguien.
        $html = self::bloque(ambiente: $ambiente);

        self::assertStringNotContainsString('PRUEBA', $html);
        self::assertStringNotContainsString('Sandbox', $html);
        self::assertStringContainsString('Pagar factura', $html, 'el boton sigue ahi');
    }

    /** @return list<array{?string}> */
    public static function ambientesQueNoSonSandbox(): array
    {
        return [
            'produccion'   => ['produccion'],
            'null'         => [null],
            'vacio'        => [''],
            'desconocido'  => ['lo-que-sea'],
        ];
    }

    public function testElAmbienteSeLeeSinImportarMayusculasNiEspacios(): void
    {
        foreach (['SANDBOX', ' sandbox ', 'Sandbox'] as $variante) {
            self::assertStringContainsString(
                'PRUEBA',
                self::bloque(ambiente: $variante),
                "'{$variante}' es sandbox"
            );
        }
    }

    // -----------------------------------------------------------------------

    /** El bloque con valores utiles por defecto, para no repetir siete argumentos. */
    private static function bloque(
        string $url = self::URL,
        string $estado = 'creado',
        ?int $monto = 49990,
        int $tipoDte = 33,
        int $folio = 3,
        string $etiqueta = 'Factura electronica',
        ?string $ambiente = 'produccion'
    ): string {
        return PreparadorEnvio::bloqueLinkPago($url, $estado, $monto, $tipoDte, $folio, $etiqueta, $ambiente);
    }
}
