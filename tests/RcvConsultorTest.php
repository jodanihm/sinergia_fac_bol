<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Dto\Certificado;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Exceptions\ConexionException;
use Plantiflex\FacturacionCl\Exceptions\RcvConsultaException;
use Plantiflex\FacturacionCl\Sii\RcvConsultor;
use Plantiflex\FacturacionCl\Sii\SiiAutenticador;
use Plantiflex\FacturacionCl\Sii\XmlSigner;
use Psr\Http\Message\RequestInterface;

final class RcvConsultorTest extends TestCase
{
    /** Token que devuelven las fixtures de autenticacion (sii_token_response.xml). */
    private const TOKEN = '7E0AAFB1-FAF7-46AF-9183-E2EFC07F45A2';

    /** @var list<array{request: RequestInterface, response: mixed, ...}> */
    private array $rcvHistory = [];

    /**
     * SiiAutenticador real, pero con un cliente Guzzle mockeado que responde las
     * fixtures de semilla + token (sin tocar el SII).
     */
    private function makeAutenticador(): SiiAutenticador
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'text/xml'], $this->fixture('sii_seed_response.xml')),
            new Response(200, ['Content-Type' => 'text/xml'], $this->fixture('sii_token_response.xml')),
        ]);
        return new SiiAutenticador(new Client(['handler' => HandlerStack::create($mock)]), new XmlSigner());
    }

    /**
     * RcvConsultor con un cliente Guzzle mockeado (responde el JSON del RCV) y un
     * SiiAutenticador real mockeado para el token.
     *
     * @param list<Response> $rcvResponses
     */
    private function makeConsultor(array $rcvResponses): RcvConsultor
    {
        $this->rcvHistory = [];
        $stack = HandlerStack::create(new MockHandler($rcvResponses));
        $stack->push(Middleware::history($this->rcvHistory));
        // Sleeper no-op: el backoff de reintentos no debe esperar segundos reales en tests.
        return new RcvConsultor(
            new Client(['handler' => $stack]),
            $this->makeAutenticador(),
            static fn (int $s): null => null,
        );
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
        $csr  = openssl_csr_new(['commonName' => 'test.sii.local'], $pkey);
        $cert = openssl_csr_sign($csr, null, $pkey, 1);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($pkey, $pkeyPem);

        return new Certificado($certPem, $pkeyPem);
    }

    // ------------------------------------------------------------------

    public function testGetResumenParseaJsonYValidaCodRespuesta(): void
    {
        $payload = [
            'respEstado' => ['codRespuesta' => 0, 'codError' => null, 'msgeRespuesta' => 'OK'],
            'data'       => ['totalDoc' => 3, 'montoTotal' => 119000],
        ];
        $consultor = $this->makeConsultor([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($payload)),
        ]);

        $out = $consultor->getResumen(
            $this->certificadoAutoFirmado(),
            '77724622',
            '4',
            '202605',
            'COMPRA',
        );

        self::assertSame(0, $out['respEstado']['codRespuesta']);
        self::assertSame(3, $out['data']['totalDoc']);

        // Verifica el request al backend del RCV.
        self::assertCount(1, $this->rcvHistory);
        /** @var RequestInterface $req */
        $req = $this->rcvHistory[0]['request'];
        self::assertSame('POST', $req->getMethod());
        self::assertStringContainsString(
            'www4.sii.cl/consdcvinternetui/services/data/facadeService/getResumen',
            (string) $req->getUri(),
        );
        self::assertSame('TOKEN=' . self::TOKEN, $req->getHeaderLine('Cookie'));
        self::assertSame('application/json', $req->getHeaderLine('Content-Type'));

        // Decodificar el body en vez de buscar substrings: json_encode escapa las
        // barras ("FacadeService\/getResumen"), asi el assert no depende del escape.
        $body = json_decode((string) $req->getBody(), true);
        self::assertSame(self::TOKEN, $body['metaData']['conversationId']);
        self::assertSame(
            'cl.sii.sdi.lob.diii.consdcv.data.api.interfaces.FacadeService/getResumen',
            $body['metaData']['namespace'],
        );
        self::assertSame('202605', $body['data']['ptributario']);
        self::assertSame('COMPRA', $body['data']['operacion']);
        self::assertSame('REGISTRO', $body['data']['estadoContab']);
    }

    public function testGetDetalleCsvReconstruyeElCsvDesdeDataArray(): void
    {
        $payload = [
            'respEstado' => ['codRespuesta' => 0],
            'data'       => [
                'Nro;Tipo Doc;RUT;Razon Social;Folio;Fecha;Monto Neto;IVA;Monto Total',
                '1;33;76000000-0;PROVEEDOR UNO SPA;101;2026-05-03;100000;19000;119000',
                '2;33;77000000-7;PROVEEDOR DOS LTDA;55;2026-05-12;50000;9500;59500',
            ],
        ];
        $consultor = $this->makeConsultor([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($payload)),
        ]);

        $csv = $consultor->getDetalleCsv(
            $this->certificadoAutoFirmado(),
            '77724622',
            '4',
            '202605',
            'COMPRA',
        );

        $esperado = "Nro;Tipo Doc;RUT;Razon Social;Folio;Fecha;Monto Neto;IVA;Monto Total\n"
            . "1;33;76000000-0;PROVEEDOR UNO SPA;101;2026-05-03;100000;19000;119000\n"
            . "2;33;77000000-7;PROVEEDOR DOS LTDA;55;2026-05-12;50000;9500;59500";
        self::assertSame($esperado, $csv);

        /** @var RequestInterface $req */
        $req = $this->rcvHistory[0]['request'];
        self::assertStringContainsString('getDetalleCompraExport', (string) $req->getUri());
        $body = (string) $req->getBody();
        self::assertStringContainsString('"accionRecaptcha":"RCV_DDETC"', $body);
        self::assertStringContainsString('"tokenRecaptcha":""', $body);
    }

    public function testVentaUsaEndpointDeVenta(): void
    {
        $consultor = $this->makeConsultor([
            new Response(200, [], (string) json_encode(['respEstado' => ['codRespuesta' => 0], 'data' => ['h']])),
        ]);

        $consultor->getDetalleCsv($this->certificadoAutoFirmado(), '77724622', '4', '202605', 'VENTA');

        /** @var RequestInterface $req */
        $req = $this->rcvHistory[0]['request'];
        self::assertStringContainsString('getDetalleVentaExport', (string) $req->getUri());
        self::assertStringContainsString('"accionRecaptcha":"RCV_DDETV"', (string) $req->getBody());
    }

    public function testCodRespuestaNoCeroLanzaRcvConsultaException(): void
    {
        $payload = [
            'respEstado' => ['codRespuesta' => 7, 'codError' => 'RCV-AUTH', 'msgeRespuesta' => 'Sin permisos sobre el RUT'],
        ];
        $consultor = $this->makeConsultor([
            new Response(200, [], (string) json_encode($payload)),
        ]);

        try {
            $consultor->getResumen($this->certificadoAutoFirmado(), '77724622', '4', '202605', 'COMPRA');
            self::fail('Se esperaba RcvConsultaException');
        } catch (RcvConsultaException $e) {
            self::assertSame(7, $e->codRespuesta);
            self::assertSame('RCV-AUTH', $e->codError);
            self::assertSame('Sin permisos sobre el RUT', $e->msgeRespuesta);
        }
    }

    public function testCertificacionNoAplicaYLanzaInvalidArgument(): void
    {
        $consultor = $this->makeConsultor([]);

        $this->expectException(InvalidArgumentException::class);
        $consultor->getResumen($this->certificadoAutoFirmado(), '77724622', '4', '202605', 'COMPRA', Ambiente::Certificacion);
    }

    public function testOperacionInvalidaLanzaInvalidArgument(): void
    {
        $consultor = $this->makeConsultor([]);

        $this->expectException(InvalidArgumentException::class);
        $consultor->getResumen($this->certificadoAutoFirmado(), '77724622', '4', '202605', 'AMBOS');
    }

    public function testPeriodoInvalidoLanzaInvalidArgument(): void
    {
        $consultor = $this->makeConsultor([]);

        $this->expectException(InvalidArgumentException::class);
        $consultor->getResumen($this->certificadoAutoFirmado(), '77724622', '4', '2026-05', 'COMPRA');
    }

    public function testReintentaTrasHttp503YDevuelveElResultado(): void
    {
        $payload = ['respEstado' => ['codRespuesta' => 0], 'data' => ['totalDoc' => 4]];
        $consultor = $this->makeConsultor([
            new Response(503, [], 'Service Unavailable'),                                  // 1er intento: transitorio
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($payload)), // 2do: OK
        ]);

        $out = $consultor->getResumen($this->certificadoAutoFirmado(), '77724622', '4', '202605', 'COMPRA');

        self::assertSame(0, $out['respEstado']['codRespuesta']);
        self::assertSame(4, $out['data']['totalDoc']);
        // Dos requests al backend del RCV => efectivamente reintento (mismo token, sin re-autenticar).
        self::assertCount(2, $this->rcvHistory);
    }

    public function testTres503AgotaReintentosYLanzaConexionException(): void
    {
        $consultor = $this->makeConsultor([
            new Response(503, [], 'x'),
            new Response(503, [], 'x'),
            new Response(503, [], 'x'),
        ]);

        try {
            $consultor->getResumen($this->certificadoAutoFirmado(), '77724622', '4', '202605', 'COMPRA');
            self::fail('Se esperaba ConexionException tras agotar reintentos');
        } catch (ConexionException $e) {
            self::assertStringContainsString('503', $e->getMessage());
        }
        self::assertCount(3, $this->rcvHistory, 'Debe intentar exactamente 3 veces');
    }
}
