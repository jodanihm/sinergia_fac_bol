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
use Plantiflex\FacturacionCl\Exceptions\EnvioRechazadoException;
use Plantiflex\FacturacionCl\Sii\SiiUploader;
use Psr\Http\Message\RequestInterface;

final class SiiUploaderTest extends TestCase
{
    /** @var list<array{request: RequestInterface, response: mixed, ...}> */
    private array $history = [];

    /**
     * @param list<Response> $responses
     */
    private function makeUploader(array $responses): SiiUploader
    {
        $this->history = [];
        $mock  = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));
        return new SiiUploader(new Client(['handler' => $stack]));
    }

    private function respuestaSii(string $status, string $trackId = ''): string
    {
        $track = $trackId === '' ? '' : "<TRACKID>$trackId</TRACKID>";
        return '<?xml version="1.0" encoding="ISO-8859-1"?>'
            . "<RECEPCIONDTE>$track<STATUS>$status</STATUS></RECEPCIONDTE>";
    }

    public function testSubirOkDevuelveTrackId(): void
    {
        $uploader = $this->makeUploader([
            new Response(200, [], $this->respuestaSii('0', '12345')),
        ]);

        $res = $uploader->subir('<EnvioDTE/>', 'TOKEN-XYZ', '13520634-2', '77724622-4', Ambiente::Certificacion);

        self::assertSame('12345', $res['trackId']);
        self::assertSame('0', $res['status']);
    }

    public function testSubirRechazadoLanzaEnvioRechazado(): void
    {
        $uploader = $this->makeUploader([
            new Response(200, [], $this->respuestaSii('99')),
        ]);

        try {
            $uploader->subir('<EnvioDTE/>', 'TOKEN', '13520634-2', '77724622-4', Ambiente::Certificacion);
            self::fail('Se esperaba EnvioRechazadoException');
        } catch (EnvioRechazadoException $e) {
            self::assertSame('99', $e->status);
        }
    }

    public function testBodyEsMultipartConCamposEsperados(): void
    {
        $uploader = $this->makeUploader([
            new Response(200, [], $this->respuestaSii('0', '777')),
        ]);

        $uploader->subir('<EnvioDTE>contenido</EnvioDTE>', 'TOKEN', '13520634-2', '77724622-4', Ambiente::Certificacion);

        /** @var RequestInterface $req */
        $req = $this->history[0]['request'];
        self::assertSame('POST', $req->getMethod());
        self::assertStringContainsString('maullin.sii.cl', (string) $req->getUri());
        self::assertStringContainsString('/cgi_dte/UPL/DTEUpload', (string) $req->getUri());
        self::assertStringContainsString('multipart/form-data', $req->getHeaderLine('Content-Type'));

        $body = (string) $req->getBody();
        self::assertStringContainsString('name="rutSender"', $body);
        self::assertStringContainsString('name="dvSender"', $body);
        self::assertStringContainsString('name="rutCompany"', $body);
        self::assertStringContainsString('name="dvCompany"', $body);
        self::assertStringContainsString('name="archivo"', $body);
        // rut/dv separados correctamente.
        self::assertStringContainsString("13520634", $body);
        self::assertStringContainsString('<EnvioDTE>contenido</EnvioDTE>', $body);
    }

    public function testHeadersUserAgentYCookieToken(): void
    {
        $uploader = $this->makeUploader([
            new Response(200, [], $this->respuestaSii('0', '1')),
        ]);

        $uploader->subir('<EnvioDTE/>', 'MI-TOKEN', '13520634-2', '77724622-4', Ambiente::Produccion);

        $req = $this->history[0]['request'];
        self::assertSame(
            'Mozilla/4.0 (compatible; PROG 1.0; Windows NT 5.0; YComp 5.0.2.4)',
            $req->getHeaderLine('User-Agent'),
        );
        self::assertSame('TOKEN=MI-TOKEN', $req->getHeaderLine('Cookie'));
        // Produccion -> palena.
        self::assertStringContainsString('palena.sii.cl', (string) $req->getUri());
    }
}
