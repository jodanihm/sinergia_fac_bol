<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Sii\SiiConsultor;
use Psr\Http\Message\RequestInterface;

final class SiiConsultorTest extends TestCase
{
    /** @var list<array{request: RequestInterface, response: mixed, ...}> */
    private array $history = [];

    /**
     * @param list<Response> $responses
     */
    private function makeConsultor(array $responses): SiiConsultor
    {
        $this->history = [];
        $mock  = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));
        return new SiiConsultor(new Client(['handler' => $stack]));
    }

    /**
     * Sobre SOAP con el XML interno escapado (mismo patron que getSeed/getToken).
     */
    private function respuesta(string $estado, string $glosa): string
    {
        $inner = '<?xml version="1.0"?>'
            . '<SII:RESPUESTA xmlns:SII="http://www.sii.cl/XMLSchema">'
            . '<SII:RESP_HDR><ESTADO>' . $estado . '</ESTADO><GLOSA>' . $glosa . '</GLOSA></SII:RESP_HDR>'
            . '<SII:RESP_BODY/>'
            . '</SII:RESPUESTA>';
        $escapado = htmlspecialchars($inner, ENT_QUOTES | ENT_XML1);

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<SOAP-ENV:Body><ns1:getEstUpResponse xmlns:ns1="http://DefaultNamespace">'
            . '<getEstUpReturn>' . $escapado . '</getEstUpReturn>'
            . '</ns1:getEstUpResponse></SOAP-ENV:Body></SOAP-ENV:Envelope>';
    }

    public function testEnvioProcesadoDevuelveEstadoEpr(): void
    {
        $consultor = $this->makeConsultor([
            new Response(200, [], $this->respuesta('EPR', 'Envio Procesado')),
        ]);

        $res = $consultor->consultarEnvio('77724622-4', '0249995332', 'TOKEN-X', Ambiente::Certificacion);

        self::assertSame('EPR', $res['estado']);
        self::assertSame('Envio Procesado', $res['glosa']);
    }

    public function testEnvioRechazadoDevuelveEstadoDeRechazo(): void
    {
        $consultor = $this->makeConsultor([
            new Response(200, [], $this->respuesta('RCT', 'Rechazado por error de schema')),
        ]);

        $res = $consultor->consultarEnvio('77724622-4', '0249995332', 'TOKEN-X', Ambiente::Certificacion);

        self::assertSame('RCT', $res['estado']);
        self::assertStringContainsString('schema', $res['glosa']);
    }

    public function testUsaUrlPorAmbienteYMandaTrackIdYToken(): void
    {
        // Certificacion -> maullin.
        $consultor = $this->makeConsultor([
            new Response(200, [], $this->respuesta('EPR', 'ok')),
        ]);
        $consultor->consultarEnvio('77724622-4', '0249995332', 'MI-TOKEN', Ambiente::Certificacion);

        /** @var RequestInterface $req */
        $req = $this->history[0]['request'];
        self::assertSame('POST', $req->getMethod());
        self::assertStringContainsString('maullin.sii.cl', (string) $req->getUri());
        self::assertStringContainsString('/DTEWS/QueryEstUp.jws', (string) $req->getUri());

        $body = (string) $req->getBody();
        self::assertStringContainsString('<def:getEstUp>', $body);
        self::assertStringContainsString('<Rut>77724622</Rut>', $body);
        self::assertStringContainsString('<Dv>4</Dv>', $body);
        self::assertStringContainsString('<TrackId>0249995332</TrackId>', $body);
        self::assertStringContainsString('<Token>MI-TOKEN</Token>', $body);
    }

    public function testProduccionUsaPalena(): void
    {
        $consultor = $this->makeConsultor([
            new Response(200, [], $this->respuesta('EPR', 'ok')),
        ]);
        $consultor->consultarEnvio('77724622-4', '1', 'T', Ambiente::Produccion);

        self::assertStringContainsString('palena.sii.cl', (string) $this->history[0]['request']->getUri());
    }
}
