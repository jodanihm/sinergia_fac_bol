<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Dto\Certificado;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Exceptions\SiiAutenticacionException;
use Plantiflex\FacturacionCl\Sii\SiiAutenticador;
use Plantiflex\FacturacionCl\Sii\XmlSigner;
use Psr\Http\Message\RequestInterface;

final class SiiAutenticadorTest extends TestCase
{
    /** @var list<array{request: RequestInterface, response: mixed, ...}> */
    private array $history = [];

    /**
     * @param list<Response> $responses
     */
    private function makeAutenticador(array $responses): SiiAutenticador
    {
        $this->history = [];
        $mock  = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));
        return new SiiAutenticador(new Client(['handler' => $stack]), new XmlSigner());
    }

    private function fixture(string $nombre): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/' . $nombre);
    }

    private function certificadoAutoFirmado(): Certificado
    {
        $pkey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($pkey === false) {
            self::markTestSkipped('openssl_pkey_new fallo (probablemente falta openssl.cnf)');
        }
        $csr = openssl_csr_new(['commonName' => 'test.sii.local'], $pkey);
        $cert = openssl_csr_sign($csr, null, $pkey, 1);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($pkey, $pkeyPem);

        return new Certificado($certPem, $pkeyPem);
    }

    // ------------------------------------------------------------------

    public function testObtenerTokenHaceLos3PasosYDevuelveElTokenContraMaullin(): void
    {
        $auth = $this->makeAutenticador([
            new Response(200, ['Content-Type' => 'text/xml'], $this->fixture('sii_seed_response.xml')),
            new Response(200, ['Content-Type' => 'text/xml'], $this->fixture('sii_token_response.xml')),
        ]);

        $token = $auth->obtenerToken($this->certificadoAutoFirmado(), Ambiente::Certificacion);

        self::assertSame('7E0AAFB1-FAF7-46AF-9183-E2EFC07F45A2', $token);
        self::assertCount(2, $this->history);

        // Paso 1: POST a maullin / CrSeed.jws con SOAPAction "".
        /** @var RequestInterface $r1 */
        $r1 = $this->history[0]['request'];
        self::assertSame('POST', $r1->getMethod());
        self::assertStringContainsString('maullin.sii.cl', (string) $r1->getUri());
        self::assertStringContainsString('CrSeed.jws', (string) $r1->getUri());
        self::assertSame('""', $r1->getHeaderLine('SOAPAction'));
        self::assertStringContainsString('<getSeed/>', (string) $r1->getBody());

        // Paso 3: POST a maullin / GetTokenFromSeed.jws con el XML firmado adentro.
        $r2 = $this->history[1]['request'];
        self::assertStringContainsString('GetTokenFromSeed.jws', (string) $r2->getUri());
        $body2 = (string) $r2->getBody();
        self::assertStringContainsString('<getToken>', $body2);
        self::assertStringContainsString('<Semilla>041220190614</Semilla>', $body2);
        self::assertStringContainsString('<SignatureValue>', $body2);
        self::assertStringContainsString('<X509Certificate>', $body2);
    }

    public function testProduccionUsaPalena(): void
    {
        $auth = $this->makeAutenticador([
            new Response(200, [], $this->fixture('sii_seed_response.xml')),
            new Response(200, [], $this->fixture('sii_token_response.xml')),
        ]);

        $auth->obtenerToken($this->certificadoAutoFirmado(), Ambiente::Produccion);

        self::assertStringContainsString('palena.sii.cl', (string) $this->history[0]['request']->getUri());
        self::assertStringContainsString('palena.sii.cl', (string) $this->history[1]['request']->getUri());
    }

    public function testEstadoNoCeroEnGetTokenLanzaSiiAutenticacionExceptionConCodigo(): void
    {
        $errorResponse = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<SOAP-ENV:Body><ns1:getTokenResponse xmlns:ns1="http://DefaultNamespace">'
            . '<getTokenReturn>'
            . '&lt;SII:RESPUESTA xmlns:SII=&quot;http://www.sii.cl/XMLSchema&quot;&gt;'
            . '&lt;SII:RESP_HDR&gt;&lt;ESTADO&gt;01&lt;/ESTADO&gt;&lt;GLOSA&gt;Firma no valida&lt;/GLOSA&gt;&lt;/SII:RESP_HDR&gt;'
            . '&lt;/SII:RESPUESTA&gt;'
            . '</getTokenReturn>'
            . '</ns1:getTokenResponse></SOAP-ENV:Body></SOAP-ENV:Envelope>';

        $auth = $this->makeAutenticador([
            new Response(200, [], $this->fixture('sii_seed_response.xml')),
            new Response(200, [], $errorResponse),
        ]);

        try {
            $auth->obtenerToken($this->certificadoAutoFirmado(), Ambiente::Certificacion);
            self::fail('Se esperaba SiiAutenticacionException');
        } catch (SiiAutenticacionException $e) {
            self::assertSame('01', $e->estadoSii);
            self::assertSame('Firma no valida', $e->glosaSii);
        }
    }

    public function testEstadoNoCeroEnGetSeedLanzaSiiAutenticacionException(): void
    {
        $errorSeed = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<SOAP-ENV:Body><ns1:getSeedResponse xmlns:ns1="http://DefaultNamespace">'
            . '<getSeedReturn>'
            . '&lt;SII:RESPUESTA&gt;&lt;SII:RESP_HDR&gt;&lt;ESTADO&gt;-1&lt;/ESTADO&gt;&lt;GLOSA&gt;Sistema no disponible&lt;/GLOSA&gt;&lt;/SII:RESP_HDR&gt;&lt;/SII:RESPUESTA&gt;'
            . '</getSeedReturn>'
            . '</ns1:getSeedResponse></SOAP-ENV:Body></SOAP-ENV:Envelope>';

        $auth = $this->makeAutenticador([
            new Response(200, [], $errorSeed),
        ]);

        try {
            $auth->obtenerToken($this->certificadoAutoFirmado(), Ambiente::Certificacion);
            self::fail('Se esperaba SiiAutenticacionException');
        } catch (SiiAutenticacionException $e) {
            self::assertSame('-1', $e->estadoSii);
            self::assertSame('Sistema no disponible', $e->glosaSii);
        }

        self::assertCount(1, $this->history, 'No debe llegar a llamar GetTokenFromSeed');
    }
}
