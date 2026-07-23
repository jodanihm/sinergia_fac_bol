<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use DateTimeImmutable;
use DOMDocument;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Dto\Certificado;
use Plantiflex\FacturacionCl\Dto\Credenciales;
use Plantiflex\FacturacionCl\Dto\DatosEmisor;
use Plantiflex\FacturacionCl\Dto\Libro;
use Plantiflex\FacturacionCl\Dto\LineaLibro;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Enums\TipoEnvioLibro;
use Plantiflex\FacturacionCl\Enums\TipoLibro;
use Plantiflex\FacturacionCl\Enums\TipoOperacionLibro;
use Plantiflex\FacturacionCl\Sii\LibroService;
use Psr\Http\Message\RequestInterface;

final class LibroServiceTest extends TestCase
{
    private const RUT     = '77724622-4';
    private const NS_SII  = 'http://www.sii.cl/SiiDte';
    private const NS_DSIG = 'http://www.w3.org/2000/09/xmldsig#';

    /** @var list<array{request: RequestInterface, response: mixed, ...}> */
    private array $history = [];

    private InMemoryEmisorRepository $emisor;

    protected function setUp(): void
    {
        $this->emisor = new InMemoryEmisorRepository();
    }

    private function certificadoAutoFirmado(): Certificado
    {
        $pkey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($pkey === false) {
            self::markTestSkipped('openssl_pkey_new fallo (falta openssl.cnf)');
        }
        $csr = openssl_csr_new(['commonName' => 'test.sii.local'], $pkey);
        $cert = openssl_csr_sign($csr, null, $pkey, 1);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($pkey, $pkeyPem);
        return new Certificado($certPem, $pkeyPem);
    }

    private function cargarEmisor(): void
    {
        $this->emisor->cargarCertificado(self::RUT, Ambiente::Certificacion, $this->certificadoAutoFirmado());
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

    /**
     * @param list<Response> $responses
     */
    private function makeService(array $responses): LibroService
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        return new LibroService(new Client(['handler' => $stack]), $this->emisor);
    }

    private function seed(): Response
    {
        return new Response(200, [], (string) file_get_contents(__DIR__ . '/fixtures/sii_seed_response.xml'));
    }

    private function tokenResp(): Response
    {
        return new Response(200, [], (string) file_get_contents(__DIR__ . '/fixtures/sii_token_response.xml'));
    }

    private function uploadOk(string $trackId): Response
    {
        return new Response(200, [], "<?xml version=\"1.0\"?><RECEPCIONDTE><TRACKID>$trackId</TRACKID><STATUS>0</STATUS></RECEPCIONDTE>");
    }

    private function credenciales(): Credenciales
    {
        return new Credenciales(
            rutEmisor: self::RUT,
            apiToken:  'no-usado',
            ambiente:  Ambiente::Certificacion,
            rutSender: '13520634-2',
        );
    }

    private function libro(): Libro
    {
        return new Libro(
            tipoOperacion: TipoOperacionLibro::Venta,
            periodoTributario: '2026-05',
            tipoLibro: TipoLibro::Especial,
            tipoEnvio: TipoEnvioLibro::Total,
            folioNotificacion: 1,
            lineas: [
                new LineaLibro(33, 1, new DateTimeImmutable('2026-05-10'), '99999999-9', 'Cliente A', 0, 10000, 1900, 11900),
            ],
        );
    }

    public function testEnviarLibroAutenticaFirmaYSube(): void
    {
        $this->cargarEmisor();
        $service = $this->makeService([$this->seed(), $this->tokenResp(), $this->uploadOk('LIBRO-777')]);

        $res = $service->enviarLibro($this->libro(), $this->credenciales());

        self::assertSame('0', $res['status']);
        self::assertSame('LIBRO-777', $res['trackId']);

        // 3 llamadas: seed, token, upload del libro.
        self::assertCount(3, $this->history);

        // El cuerpo del upload (3er request) contiene el LibroCompraVenta firmado.
        $body = (string) $this->history[2]['request']->getBody();
        $ini  = strpos($body, '<LibroCompraVenta');
        $fin  = strpos($body, '</LibroCompraVenta>');
        self::assertNotFalse($ini);
        self::assertNotFalse($fin);
        $xml = substr($body, $ini, $fin - $ini + strlen('</LibroCompraVenta>'));

        self::assertStringNotContainsString('default:', $xml);

        $dom = new DOMDocument();
        self::assertTrue($dom->loadXML($xml));

        // EnvioLibro con ID y firma hermana.
        $envio = $dom->getElementsByTagNameNS(self::NS_SII, 'EnvioLibro')->item(0);
        self::assertNotNull($envio);
        self::assertSame('LibroCV', $envio->getAttribute('ID'));

        $firmas = $dom->getElementsByTagNameNS(self::NS_DSIG, 'Signature');
        self::assertSame(1, $firmas->length);
        self::assertSame('LibroCompraVenta', $firmas->item(0)->parentNode?->localName);

        $ref = $dom->getElementsByTagNameNS(self::NS_DSIG, 'Reference')->item(0);
        self::assertSame('#LibroCV', $ref->getAttribute('URI'));

        // La firma verifica con la llave publica del cert del emisor.
        $signedInfo = $dom->getElementsByTagNameNS(self::NS_DSIG, 'SignedInfo')->item(0);
        $sigValue   = $dom->getElementsByTagNameNS(self::NS_DSIG, 'SignatureValue')->item(0);
        $pub = openssl_pkey_get_public(
            $this->emisor->obtenerCertificado(self::RUT, Ambiente::Certificacion)->certData,
        );
        self::assertNotFalse($pub);
        self::assertSame(
            1,
            openssl_verify($signedInfo->C14N(), base64_decode(trim((string) $sigValue->textContent)), $pub, OPENSSL_ALGO_SHA1),
        );

        // La ultima request es el upload (multipart con archivo).
        self::assertStringContainsString('multipart/form-data', $this->history[2]['request']->getHeaderLine('Content-Type'));
    }
}
