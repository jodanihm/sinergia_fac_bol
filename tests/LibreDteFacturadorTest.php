<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Dto\Credenciales;
use Plantiflex\FacturacionCl\Dto\Detalle;
use Plantiflex\FacturacionCl\Dto\DocumentoOriginal;
use Plantiflex\FacturacionCl\Dto\DocumentoTributario;
use Plantiflex\FacturacionCl\Dto\Receptor;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Enums\TipoAnulacion;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Exceptions\AnulacionNoSoportadaException;
use Plantiflex\FacturacionCl\Exceptions\CredencialesInvalidasException;
use Plantiflex\FacturacionCl\Exceptions\DocumentoInvalidoException;
use Plantiflex\FacturacionCl\Exceptions\EmisionRechazadaException;
use Plantiflex\FacturacionCl\Providers\LibreDteFacturador;
use Psr\Http\Message\RequestInterface;

final class LibreDteFacturadorTest extends TestCase
{
    /** @var list<RequestInterface> */
    private array $requestsLog = [];

    /**
     * @param list<Response> $responses
     */
    private function makeFacturador(array $responses): LibreDteFacturador
    {
        $this->requestsLog = [];
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->requestsLog));
        $client = new Client(['handler' => $stack]);
        return new LibreDteFacturador($client);
    }

    private function credencialesValidas(): Credenciales
    {
        return new Credenciales(
            rutEmisor: '76543210-K',
            apiToken: 'tok-test-123',
            ambiente: Ambiente::Certificacion,
            baseUrlOverride: 'https://api.test.local',
        );
    }

    private function boletaDeEjemplo(): DocumentoTributario
    {
        return new DocumentoTributario(
            tipoDte: TipoDte::BoletaElectronica,
            receptor: new Receptor(rut: '11111111-1', razonSocial: 'Cliente Final'),
            detalles: [
                new Detalle(nombre: 'Producto A', cantidad: 2, precioUnitario: 1000),
            ],
            montosSonBrutos: true,
        );
    }

    public function testEmisionExitosaDevuelveResultadoConFolio(): void
    {
        $facturador = $this->makeFacturador([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'folio'   => 1234,
                'estado'  => 'emitido',
                'track_id' => 'TRK-9001',
                'pdf'     => 'https://api.test.local/pdf/1234',
            ])),
        ]);

        $resultado = $facturador->emitir($this->boletaDeEjemplo(), $this->credencialesValidas());

        self::assertSame(1234, $resultado->folio);
        self::assertSame(TipoDte::BoletaElectronica, $resultado->tipoDte);
        self::assertSame('emitido', $resultado->estado);
        self::assertSame('TRK-9001', $resultado->trackId);

        self::assertCount(1, $this->requestsLog);
        /** @var RequestInterface $req */
        $req = $this->requestsLog[0]['request'];
        self::assertSame('POST', $req->getMethod());
        self::assertSame('Bearer tok-test-123', $req->getHeaderLine('Authorization'));
        self::assertStringContainsString('/api/dte/documento/emitir', (string) $req->getUri());

        $body = json_decode((string) $req->getBody(), true);
        self::assertSame('76543210-K', $body['Encabezado']['Emisor']['RUTEmisor']);
        self::assertSame(39, $body['Encabezado']['IdDoc']['TipoDTE']);
        self::assertSame(1, $body['Encabezado']['IdDoc']['MntBruto'], 'montosSonBrutos=true => MntBruto=1');
        self::assertSame(2, $body['Detalle'][0]['QtyItem']);
        self::assertSame(1000, $body['Detalle'][0]['PrcItem']);
    }

    public function testEmisionConMontosNetosNoEnviaMntBruto(): void
    {
        $facturador = $this->makeFacturador([
            new Response(200, [], json_encode(['folio' => 1, 'estado' => 'ok'])),
        ]);

        $doc = new DocumentoTributario(
            tipoDte: TipoDte::FacturaElectronica,
            receptor: new Receptor(rut: '22222222-2', razonSocial: 'Empresa SpA'),
            detalles: [new Detalle('Item', 1, 1000)],
            montosSonBrutos: false,
        );

        $facturador->emitir($doc, $this->credencialesValidas());

        $body = json_decode((string) $this->requestsLog[0]['request']->getBody(), true);
        self::assertArrayNotHasKey('MntBruto', $body['Encabezado']['IdDoc']);
    }

    public function testEmisionRechazadaLanzaExcepcionConDetalle(): void
    {
        $facturador = $this->makeFacturador([
            new Response(422, ['Content-Type' => 'application/json'], json_encode([
                'message' => 'RUT receptor invalido',
                'error'   => true,
            ])),
        ]);

        try {
            $facturador->emitir($this->boletaDeEjemplo(), $this->credencialesValidas());
            self::fail('Se esperaba EmisionRechazadaException');
        } catch (EmisionRechazadaException $e) {
            self::assertStringContainsString('RUT receptor invalido', $e->getMessage());
            self::assertSame(true, $e->detalle['error']);
        }
    }

    public function testCredencialesInvalidasMapeaHTTP401(): void
    {
        $facturador = $this->makeFacturador([
            new Response(401, [], json_encode(['message' => 'token invalido'])),
        ]);

        $this->expectException(CredencialesInvalidasException::class);
        $facturador->emitir($this->boletaDeEjemplo(), $this->credencialesValidas());
    }

    public function testConsultarEstadoDevuelveEstadoDte(): void
    {
        $facturador = $this->makeFacturador([
            new Response(200, [], json_encode([
                'estado' => 'aceptado',
                'glosa'  => 'DTE aceptado por SII',
            ])),
        ]);

        $estado = $facturador->consultarEstado(
            folio: 555,
            tipoDte: TipoDte::FacturaElectronica->value,
            cred: $this->credencialesValidas(),
        );

        self::assertSame(555, $estado->folio);
        self::assertSame(TipoDte::FacturaElectronica, $estado->tipoDte);
        self::assertSame('aceptado', $estado->estado);
        self::assertSame('DTE aceptado por SII', $estado->glosa);

        $req = $this->requestsLog[0]['request'];
        self::assertSame('GET', $req->getMethod());
        self::assertStringContainsString('/api/dte/dte_emitidos/info/33/555/', (string) $req->getUri());
    }

    public function testAnularLanzaAnulacionNoSoportadaYNoHaceLlamadasHttp(): void
    {
        // Sin respuestas en el mock: si anular() intentara emitir, fallaria.
        $facturador = $this->makeFacturador([]);

        $original = new DocumentoOriginal(
            tipoDte:         TipoDte::FacturaElectronica,
            folio:           4242,
            fechaEmision:    new \DateTimeImmutable('2025-03-15'),
            receptor:        new Receptor('99999999-9', 'Cliente'),
            detalles:        [new Detalle('Servicio', 1, 50000)],
            montoNeto:       50000,
            iva:             9500,
            montoTotal:      59500,
            montosSonBrutos: false,
        );

        try {
            $facturador->anular(
                original: $original,
                motivo:   'Error en datos del cliente',
                tipo:     TipoAnulacion::AnulaTotal,
                cred:     $this->credencialesValidas(),
            );
            self::fail('Se esperaba AnulacionNoSoportadaException');
        } catch (AnulacionNoSoportadaException $e) {
            self::assertStringContainsString('LibreDteFacturador', $e->getMessage());
        }

        self::assertCount(0, $this->requestsLog, 'anular() no debe hacer llamadas HTTP en LibreDte');
    }

    public function testConsultarEstadoConTipoDteInvalidoLanzaDocumentoInvalido(): void
    {
        $facturador = $this->makeFacturador([]); // no debe llegar a hacer HTTP

        try {
            $facturador->consultarEstado(
                folio: 1,
                tipoDte: 45, // no existe en TipoDte
                cred: $this->credencialesValidas(),
            );
            self::fail('Se esperaba DocumentoInvalidoException');
        } catch (DocumentoInvalidoException $e) {
            self::assertStringContainsString('45', $e->getMessage());
        }

        self::assertCount(0, $this->requestsLog);
    }
}
