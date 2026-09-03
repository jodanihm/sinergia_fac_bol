<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Enums\AmbientePasarela;
use Plantiflex\FacturacionCl\Exceptions\PasarelaNoConfiguradaException;
use Plantiflex\FacturacionCl\Pago\UrlPublica;

/**
 * Tests de la direccion publica a la que la pasarela avisa del pago.
 *
 * POR QUE ESTO MERECE TESTS PROPIOS: esa direccion viaja DENTRO de la orden. Si
 * esta mal, la orden se crea igual, el cliente paga igual y el aviso no llega
 * nunca -- cobro real sin registrar. Y el fallo no se ve al configurar ni al
 * emitir: se ve semanas despues, cuando alguien pregunta por que una factura
 * pagada figura como pendiente.
 *
 * Antes de esta entrega la comprobacion era `!== ''`.
 */
final class UrlPublicaTest extends TestCase
{
    // -----------------------------------------------------------------------
    //  Produccion: estricto
    // -----------------------------------------------------------------------

    public function testEnProduccionUnaUrlBuenaPasaYSeNormaliza(): void
    {
        self::assertSame(
            'https://facturacion.ejemplo.cl',
            UrlPublica::validar('https://facturacion.ejemplo.cl/', AmbientePasarela::Produccion)
        );
    }

    /** @return list<array{string,string}> */
    public static function urlsQueNoSirvenEnProduccion(): array
    {
        return [
            'sin cifrar'          => ['http://facturacion.ejemplo.cl', 'https'],
            'localhost'           => ['https://localhost:8086', 'alcanzar'],
            'loopback v4'         => ['https://127.0.0.1', 'privada'],
            'loopback v6'         => ['https://[::1]', 'privada'],
            'red privada 10.x'    => ['https://10.0.0.5', 'privada'],
            'red privada 192.168' => ['https://192.168.1.10', 'privada'],
            'red privada 172.16'  => ['https://172.16.0.1', 'privada'],
            'vacia'               => ['', 'vacia'],
            'sin esquema'         => ['facturacion.ejemplo.cl', 'valida'],
            'esquema raro'        => ['ftp://facturacion.ejemplo.cl', 'http o https'],
        ];
    }

    #[DataProvider('urlsQueNoSirvenEnProduccion')]
    public function testEnProduccionSeRechazaLoQueLaPasarelaNoPodriaAlcanzar(string $url, string $pista): void
    {
        $this->expectException(PasarelaNoConfiguradaException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($pista, '/') . '/i');

        UrlPublica::validar($url, AmbientePasarela::Produccion);
    }

    public function testUnDominioPublicoConIpPrivadaNoSeDetectaYSeDiceAsi(): void
    {
        // Solo se miran IPs LITERALES. Resolver DNS desde una validacion la
        // volveria lenta, dependiente de la red y distinta segun donde corra. Que
        // este caso no se cubra aqui esta escrito en el codigo, no escondido.
        self::assertSame(
            'https://interno.miempresa.cl',
            UrlPublica::validar('https://interno.miempresa.cl', AmbientePasarela::Produccion)
        );
    }

    // -----------------------------------------------------------------------
    //  Sandbox: lo justo para poder desarrollar
    // -----------------------------------------------------------------------

    /** @return list<array{string}> */
    public static function urlsDeDesarrollo(): array
    {
        return [
            ['http://localhost:8086'],
            ['http://127.0.0.1:8086'],
            ['https://localhost'],
            ['http://192.168.1.10'],
        ];
    }

    #[DataProvider('urlsDeDesarrollo')]
    public function testEnSandboxSePermiteUnaDireccionDeDesarrollo(string $url): void
    {
        // Aflojarlo SOLO en sandbox es lo que permite tener reglas estrictas en
        // produccion sin que nadie las quiera desactivar para poder trabajar --
        // que es como acaban desactivadas.
        self::assertSame(rtrim($url, '/'), UrlPublica::validar($url, AmbientePasarela::Sandbox));
    }

    public function testNiSiquieraEnSandboxSeAceptaBasura(): void
    {
        // Aflojar no es dejar de mirar.
        $this->expectException(PasarelaNoConfiguradaException::class);
        UrlPublica::validar('esto no es una url', AmbientePasarela::Sandbox);
    }

    public function testNiSiquieraEnSandboxSeAceptaVacia(): void
    {
        $this->expectException(PasarelaNoConfiguradaException::class);
        UrlPublica::validar('   ', AmbientePasarela::Sandbox);
    }

    // -----------------------------------------------------------------------

    public function testLaBarraFinalSeQuitaSiempre(): void
    {
        // El resolutor pega '/pagos/...' detras: sin esto saldria una doble barra
        // en la url que viaja dentro de la orden.
        self::assertSame(
            'https://a.cl',
            UrlPublica::validar('https://a.cl///', AmbientePasarela::Produccion)
        );
    }
}
