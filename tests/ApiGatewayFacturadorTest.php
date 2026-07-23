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
use Plantiflex\FacturacionCl\Dto\Credenciales;
use Plantiflex\FacturacionCl\Dto\DatosEmisor;
use Plantiflex\FacturacionCl\Dto\Detalle;
use Plantiflex\FacturacionCl\Dto\DocumentoOriginal;
use Plantiflex\FacturacionCl\Dto\DocumentoTributario;
use Plantiflex\FacturacionCl\Dto\Receptor;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Enums\TipoAnulacion;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Exceptions\AnulacionNoSoportadaException;
use Plantiflex\FacturacionCl\Exceptions\CertificadoNoEncontradoException;
use Plantiflex\FacturacionCl\Exceptions\ConexionException;
use Plantiflex\FacturacionCl\Exceptions\CredencialesInvalidasException;
use Plantiflex\FacturacionCl\Exceptions\CuentaNoHabilitadaException;
use Plantiflex\FacturacionCl\Exceptions\OperacionNoSoportadaException;
use Plantiflex\FacturacionCl\Providers\ApiGatewayFacturador;
use Psr\Http\Message\RequestInterface;

final class ApiGatewayFacturadorTest extends TestCase
{
    private const RUT = '77724622-4';
    private const TOKEN = 'apigw-token-xyz';

    /** @var list<array{request: RequestInterface, response: mixed, ...}> */
    private array $requestsLog = [];

    private InMemoryFolioRepository $folios;
    private InMemoryEmisorRepository $emisor;

    protected function setUp(): void
    {
        $this->folios = new InMemoryFolioRepository();
        $this->emisor = new InMemoryEmisorRepository();
    }

    /**
     * @param list<Response> $responses
     */
    private function makeFacturador(array $responses): ApiGatewayFacturador
    {
        $this->requestsLog = [];
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->requestsLog));
        $client = new Client(['handler' => $stack]);
        return new ApiGatewayFacturador($client, $this->folios, $this->emisor);
    }

    private function credenciales(): Credenciales
    {
        return new Credenciales(
            rutEmisor: self::RUT,
            apiToken:  self::TOKEN,
            ambiente:  Ambiente::Certificacion,
            baseUrlOverride: 'https://api.test.local',
        );
    }

    private function cargarEmisorYCaf(TipoDte $tipo): void
    {
        $this->folios->cargarCaf(self::RUT, $tipo, Ambiente::Certificacion, 100, 199, '<CAF-XML/>');
        $this->emisor->cargarCertificado(self::RUT, Ambiente::Certificacion, new Certificado(
            certData: "-----BEGIN CERTIFICATE-----\nCERT\n-----END CERTIFICATE-----",
            pkeyData: "-----BEGIN PRIVATE KEY-----\nPKEY\n-----END PRIVATE KEY-----",
        ));
        $this->emisor->cargarDatos(self::RUT, Ambiente::Certificacion, new DatosEmisor(
            rutEmisor:        self::RUT,
            razonSocial:      'Plantiflex SpA',
            giro:             'Venta de plantas',
            acteco:           477310,
            dirOrigen:        'Av Siempre Viva 123',
            cmnaOrigen:       'Santiago',
            resolucionFecha:  '2024-01-01',
            resolucionNumero: 0,
        ));
    }

    private function boleta(): DocumentoTributario
    {
        return new DocumentoTributario(
            tipoDte: TipoDte::BoletaElectronica,
            receptor: new Receptor('11111111-1', 'Cliente Final'),
            detalles: [new Detalle('Maceta', 1, 1990)],
            montosSonBrutos: true,
        );
    }

    private function factura(): DocumentoTributario
    {
        return new DocumentoTributario(
            tipoDte: TipoDte::FacturaElectronica,
            receptor: new Receptor(
                rut: '99999999-9',
                razonSocial: 'Empresa Cliente SpA',
                giro: 'Comercio',
                direccion: 'Calle Falsa 1',
                comuna: 'Santiago',
            ),
            detalles: [new Detalle('Servicio', 1, 50000)],
            montosSonBrutos: false,
        );
    }

    // ------------------------------------------------------------------

    public function testEmisionExitosaArmaPayloadYMarcaFolioConExito(): void
    {
        $this->cargarEmisorYCaf(TipoDte::BoletaElectronica);

        $facturador = $this->makeFacturador([
            // /generar
            new Response(200, [], (string) json_encode([
                'dte'      => base64_encode('<DTE/>'),
                'sii'      => base64_encode('<EnvioDTE/>'),
                'receptor' => base64_encode('<Recibo/>'),
            ])),
            // /enviar_sii
            new Response(200, [], (string) json_encode([
                'track_id' => 'TRK-555',
                'estado'   => 'enviado',
            ])),
        ]);

        $resultado = $facturador->emitir($this->boleta(), $this->credenciales());

        self::assertSame(100, $resultado->folio);
        self::assertSame('enviado', $resultado->estado);
        self::assertSame('TRK-555', $resultado->trackId);
        self::assertSame('<DTE/>', $resultado->xml);

        // 2 llamadas HTTP: generar + enviar_sii.
        self::assertCount(2, $this->requestsLog);

        /** @var RequestInterface $reqGenerar */
        $reqGenerar = $this->requestsLog[0]['request'];
        self::assertSame('POST', $reqGenerar->getMethod());
        self::assertSame('Token ' . self::TOKEN, $reqGenerar->getHeaderLine('Authorization'));

        $uri = (string) $reqGenerar->getUri();
        self::assertStringContainsString('/api/v1/libredte/dte/documentos/generar', $uri);
        self::assertStringContainsString('normalizar=1', $uri);
        self::assertStringContainsString('formato=json', $uri);
        self::assertStringContainsString('enviar_sii=0', $uri);

        $payload = json_decode((string) $reqGenerar->getBody(), true);
        self::assertIsArray($payload);

        // Los 4 bloques obligatorios.
        self::assertArrayHasKey('auth', $payload);
        self::assertArrayHasKey('dte', $payload);
        self::assertArrayHasKey('resolucion', $payload);
        self::assertArrayHasKey('caf', $payload);

        self::assertSame("-----BEGIN CERTIFICATE-----\nCERT\n-----END CERTIFICATE-----",
            $payload['auth']['cert']['cert-data']);
        self::assertSame("-----BEGIN PRIVATE KEY-----\nPKEY\n-----END PRIVATE KEY-----",
            $payload['auth']['cert']['pkey-data']);

        self::assertSame(base64_encode('<CAF-XML/>'), $payload['caf']);
        self::assertSame('2024-01-01', $payload['resolucion']['fecha']);
        self::assertSame(0, $payload['resolucion']['numero']);

        self::assertSame(100, $payload['dte']['Encabezado']['IdDoc']['Folio']);
        self::assertSame(39,  $payload['dte']['Encabezado']['IdDoc']['TipoDTE']);
        // Boleta con montosSonBrutos=true -> MntBruto=1.
        self::assertSame(1, $payload['dte']['Encabezado']['IdDoc']['MntBruto']);

        // marcarFolioComoUsado(true)
        $log = $this->folios->log();
        self::assertCount(1, $log);
        self::assertSame(100, $log[0]['folio']);
        self::assertTrue($log[0]['exitosa']);
    }

    public function testFalloEnEnvioAlSiiMarcaFolioComoFallidoYRelanza(): void
    {
        $this->cargarEmisorYCaf(TipoDte::BoletaElectronica);

        $facturador = $this->makeFacturador([
            // /generar OK
            new Response(200, [], (string) json_encode([
                'dte'      => base64_encode('<DTE/>'),
                'sii'      => base64_encode('<E/>'),
                'receptor' => base64_encode('<R/>'),
            ])),
            // /enviar_sii falla
            new Response(503, [], (string) json_encode(['error' => 'sii caido'])),
        ]);

        try {
            $facturador->emitir($this->boleta(), $this->credenciales());
            self::fail('Se esperaba ConexionException por HTTP 5xx en envio SII');
        } catch (ConexionException $e) {
            // OK
        }

        $log = $this->folios->log();
        self::assertCount(1, $log);
        self::assertSame(100, $log[0]['folio']);
        self::assertFalse(
            $log[0]['exitosa'],
            'Folio debe quedar quemado con exitosa=false tras fallo en envio',
        );
    }

    public function testHttp402LanzaCuentaNoHabilitadaYMarcaFolioFallido(): void
    {
        $this->cargarEmisorYCaf(TipoDte::BoletaElectronica);

        $facturador = $this->makeFacturador([
            new Response(402, [], (string) json_encode(['message' => 'sin creditos'])),
        ]);

        try {
            $facturador->emitir($this->boleta(), $this->credenciales());
            self::fail('Se esperaba CuentaNoHabilitadaException');
        } catch (CuentaNoHabilitadaException $e) {
            self::assertStringContainsString('402', $e->getMessage());
        }

        $log = $this->folios->log();
        self::assertCount(1, $log);
        self::assertFalse($log[0]['exitosa']);
    }

    public function testHttp401LanzaCredencialesInvalidas(): void
    {
        $this->cargarEmisorYCaf(TipoDte::BoletaElectronica);

        $facturador = $this->makeFacturador([
            new Response(401, [], (string) json_encode(['message' => 'token invalido'])),
        ]);

        $this->expectException(CredencialesInvalidasException::class);
        $facturador->emitir($this->boleta(), $this->credenciales());
    }

    public function testBoletaUsaRznSocEmisorYFacturaUsaRznSoc(): void
    {
        $okResponses = static fn (): array => [
            new Response(200, [], (string) json_encode([
                'dte' => base64_encode('<x/>'), 'sii' => base64_encode('<x/>'), 'receptor' => base64_encode('<x/>'),
            ])),
            new Response(200, [], (string) json_encode(['estado' => 'enviado'])),
        ];

        // BOLETA
        $this->cargarEmisorYCaf(TipoDte::BoletaElectronica);
        $facturador = $this->makeFacturador($okResponses());
        $facturador->emitir($this->boleta(), $this->credenciales());

        $emisorBoleta = json_decode((string) $this->requestsLog[0]['request']->getBody(), true)
            ['dte']['Encabezado']['Emisor'];
        self::assertArrayHasKey('RznSocEmisor', $emisorBoleta);
        self::assertArrayHasKey('GiroEmisor', $emisorBoleta);
        self::assertArrayNotHasKey('RznSoc', $emisorBoleta);
        self::assertArrayNotHasKey('GiroEmis', $emisorBoleta);
        self::assertSame('Plantiflex SpA', $emisorBoleta['RznSocEmisor']);

        // FACTURA - nuevo facturador / nuevo set de fixtures
        $this->setUp();
        $this->cargarEmisorYCaf(TipoDte::FacturaElectronica);
        $facturador = $this->makeFacturador($okResponses());
        $facturador->emitir($this->factura(), $this->credenciales());

        $emisorFactura = json_decode((string) $this->requestsLog[0]['request']->getBody(), true)
            ['dte']['Encabezado']['Emisor'];
        self::assertArrayHasKey('RznSoc', $emisorFactura);
        self::assertArrayHasKey('GiroEmis', $emisorFactura);
        self::assertArrayNotHasKey('RznSocEmisor', $emisorFactura);
        self::assertArrayNotHasKey('GiroEmisor', $emisorFactura);

        // Factura con montosSonBrutos=false -> NO debe enviar MntBruto.
        $idDoc = json_decode((string) $this->requestsLog[0]['request']->getBody(), true)
            ['dte']['Encabezado']['IdDoc'];
        self::assertArrayNotHasKey('MntBruto', $idDoc);
    }

    public function testAnularDeFacturaEmiteNotaCreditoConReferenciaYTotalesReplicados(): void
    {
        // CAF de NC tipo 61 (la NC necesita su propio folio).
        $this->folios->cargarCaf(
            self::RUT,
            TipoDte::NotaCreditoElectronica,
            Ambiente::Certificacion,
            200,
            299,
            '<CAF-NC/>',
        );
        $this->emisor->cargarCertificado(self::RUT, Ambiente::Certificacion, new \Plantiflex\FacturacionCl\Dto\Certificado(
            certData: "-----BEGIN CERTIFICATE-----\nCERT\n-----END CERTIFICATE-----",
            pkeyData: "-----BEGIN PRIVATE KEY-----\nPKEY\n-----END PRIVATE KEY-----",
        ));
        $this->emisor->cargarDatos(self::RUT, Ambiente::Certificacion, new \Plantiflex\FacturacionCl\Dto\DatosEmisor(
            rutEmisor:        self::RUT,
            razonSocial:      'Plantiflex SpA',
            giro:             'Venta de plantas',
            acteco:           477310,
            dirOrigen:        'Av Siempre Viva 123',
            cmnaOrigen:       'Santiago',
            resolucionFecha:  '2024-01-01',
            resolucionNumero: 0,
        ));

        $facturador = $this->makeFacturador([
            new Response(200, [], (string) json_encode([
                'dte'      => base64_encode('<NC/>'),
                'sii'      => base64_encode('<EnvioDTE-NC/>'),
                'receptor' => base64_encode('<R/>'),
            ])),
            new Response(200, [], (string) json_encode([
                'estado'   => 'enviado',
                'track_id' => 'TRK-NC-9',
            ])),
        ]);

        $receptorReal = new Receptor(
            rut: '99999999-9',
            razonSocial: 'Empresa Cliente SpA',
            giro: 'Comercio',
            direccion: 'Calle Falsa 1',
            comuna: 'Santiago',
        );
        $original = new DocumentoOriginal(
            tipoDte:         TipoDte::FacturaElectronica,
            folio:           4242,
            fechaEmision:    new \DateTimeImmutable('2025-03-15'),
            receptor:        $receptorReal,
            detalles:        [new Detalle('Servicio', 1, 50000)],
            montoNeto:       50000,
            iva:             9500,
            montoTotal:      59500,
            montosSonBrutos: false,
        );

        $resultado = $facturador->anular(
            original: $original,
            motivo:   'Error en datos del cliente',
            tipo:     TipoAnulacion::AnulaTotal,
            cred:     $this->credenciales(),
        );

        // La NC se emitio: tipo 61, folio asignado de la serie 61.
        self::assertSame(TipoDte::NotaCreditoElectronica, $resultado->tipoDte);
        self::assertSame(200, $resultado->folio);

        $payload = json_decode((string) $this->requestsLog[0]['request']->getBody(), true);

        // Encabezado.IdDoc
        self::assertSame(61,  $payload['dte']['Encabezado']['IdDoc']['TipoDTE']);
        self::assertSame(200, $payload['dte']['Encabezado']['IdDoc']['Folio']);

        // Receptor REAL del original (no placeholder).
        self::assertSame('99999999-9', $payload['dte']['Encabezado']['Receptor']['RUTRecep']);
        self::assertSame('Empresa Cliente SpA', $payload['dte']['Encabezado']['Receptor']['RznSocRecep']);

        // Referencia: TpoDocRef=33 (factura), FolioRef=4242, FchRef, CodRef=1, RazonRef.
        $ref = $payload['dte']['Referencia'][0];
        self::assertSame(33,         $ref['TpoDocRef']);
        self::assertSame(4242,       $ref['FolioRef']);
        self::assertSame('2025-03-15', $ref['FchRef']);
        self::assertSame(1,          $ref['CodRef'], 'CodRef=1 anula');
        self::assertSame('Error en datos del cliente', $ref['RazonRef']);

        // Totales replicados del original.
        self::assertSame(50000, $payload['dte']['Encabezado']['Totales']['MntNeto']);
        self::assertSame(9500,  $payload['dte']['Encabezado']['Totales']['IVA']);
        self::assertSame(59500, $payload['dte']['Encabezado']['Totales']['MntTotal']);

        // El folio quedo marcado como exitoso en el log.
        $log = $this->folios->log();
        self::assertCount(1, $log);
        self::assertSame(200, $log[0]['folio']);
        self::assertTrue($log[0]['exitosa']);
    }

    public function testAnularDeBoletaLanzaAnulacionNoSoportadaYNoQuemaFolio(): void
    {
        $facturador = $this->makeFacturador([]);

        $original = new DocumentoOriginal(
            tipoDte:         TipoDte::BoletaElectronica,
            folio:           7,
            fechaEmision:    new \DateTimeImmutable('2025-03-15'),
            receptor:        new Receptor('11111111-1', 'Cliente Final'),
            detalles:        [new Detalle('Maceta', 1, 1990)],
            montoNeto:       1672,
            iva:             318,
            montoTotal:      1990,
            montosSonBrutos: true,
        );

        try {
            $facturador->anular($original, 'cambio de opinion', TipoAnulacion::AnulaTotal, $this->credenciales());
            self::fail('Se esperaba AnulacionNoSoportadaException');
        } catch (AnulacionNoSoportadaException $e) {
            self::assertStringContainsString('boletas', $e->getMessage());
        }
        self::assertCount(0, $this->requestsLog);
        self::assertCount(0, $this->folios->log(), 'No debe haberse asignado folio de NC');
    }

    public function testAnularConCorrigeMontoLanzaOperacionNoSoportada(): void
    {
        $facturador = $this->makeFacturador([]);

        $original = new DocumentoOriginal(
            tipoDte:      TipoDte::FacturaElectronica,
            folio:        1,
            fechaEmision: new \DateTimeImmutable('2025-03-15'),
            receptor:     new Receptor('99999999-9', 'X'),
            detalles:     [new Detalle('item', 1, 1000)],
            montoNeto:    1000,
            iva:          190,
            montoTotal:   1190,
        );

        $this->expectException(OperacionNoSoportadaException::class);
        $facturador->anular($original, 'rebaja', TipoAnulacion::CorrigeMonto, $this->credenciales());
    }

    public function testConsultarEstadoLanzaOperacionNoSoportada(): void
    {
        $facturador = $this->makeFacturador([]);

        $this->expectException(OperacionNoSoportadaException::class);
        $facturador->consultarEstado(123, TipoDte::FacturaElectronica->value, $this->credenciales());
    }

    public function testNombreProveedor(): void
    {
        $facturador = $this->makeFacturador([]);
        self::assertSame('apigateway', $facturador->nombreProveedor());
    }

    public function testCertificadoFaltanteNoQuemaNingunFolio(): void
    {
        // Cargamos CAF pero NO certificado ni datos del emisor.
        // obtenerCertificado debe lanzar antes de que se llame asignarSiguienteFolio.
        $this->folios->cargarCaf(
            self::RUT,
            TipoDte::BoletaElectronica,
            Ambiente::Certificacion,
            100,
            199,
            '<CAF/>',
        );

        $facturador = $this->makeFacturador([]); // no debe llegar a hacer HTTP

        try {
            $facturador->emitir($this->boleta(), $this->credenciales());
            self::fail('Se esperaba CertificadoNoEncontradoException');
        } catch (CertificadoNoEncontradoException $e) {
            // OK
        }

        self::assertCount(
            0,
            $this->folios->log(),
            'Ningun folio debe haber sido asignado por una falla de configuracion',
        );
        self::assertCount(0, $this->requestsLog, 'No debe haber hecho HTTP');

        // Y el siguiente folio del CAF debe seguir siendo 100 (proximo intacto).
        $proximo = $this->folios->asignarSiguienteFolio(
            self::RUT,
            TipoDte::BoletaElectronica,
            Ambiente::Certificacion,
        );
        self::assertSame(
            100,
            $proximo,
            'El proximo folio del CAF debe seguir intacto tras un fallo de dependencias',
        );
    }

    public function testRespuestaIncompletaDeGenerarLanzaEmisionRechazada(): void
    {
        $this->cargarEmisorYCaf(TipoDte::BoletaElectronica);

        $facturador = $this->makeFacturador([
            // Falta clave "receptor"
            new Response(200, [], (string) json_encode([
                'dte' => base64_encode('<x/>'),
                'sii' => base64_encode('<x/>'),
            ])),
        ]);

        try {
            $facturador->emitir($this->boleta(), $this->credenciales());
            self::fail('Se esperaba EmisionRechazadaException por respuesta incompleta');
        } catch (\Plantiflex\FacturacionCl\Exceptions\EmisionRechazadaException $e) {
            self::assertStringContainsString('receptor', $e->getMessage());
        }

        // El folio debe quedar marcado fallido.
        self::assertFalse($this->folios->log()[0]['exitosa']);
    }
}
