<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Dto\Certificado;
use Plantiflex\FacturacionCl\Dto\Credenciales;
use Plantiflex\FacturacionCl\Dto\DatosEmisor;
use Plantiflex\FacturacionCl\Dto\Detalle;
use Plantiflex\FacturacionCl\Dto\DocumentoTributario;
use Plantiflex\FacturacionCl\Dto\Receptor;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Providers\BoletaFacturador;

/**
 * emitirLote() (usado por el Set de Boleta de certificacion) NO persistia en
 * dte_emitido -- solo emitir() (single-doc) lo hacia, via persistirEmitido().
 * Este archivo protege el fix: cada boleta de un lote debe quedar en
 * dte_emitido con SUS PROPIOS montos (no los del primer documento del sobre,
 * que es el bug obvio si se reusa extraerMontos() sin adaptar el scope).
 */
final class BoletaFacturadorTest extends TestCase
{
    private const RUT = '78157243-8';

    private InMemoryFolioRepository $folios;
    private InMemoryEmisorRepository $emisor;

    protected function setUp(): void
    {
        $this->folios = new InMemoryFolioRepository();
        $this->emisor = new InMemoryEmisorRepository();
    }

    private function certificadoAutoFirmado(): Certificado
    {
        $pkey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($pkey === false) {
            self::markTestSkipped('openssl_pkey_new fallo');
        }
        $csr = openssl_csr_new(['commonName' => 'test.sii.local'], $pkey);
        $cert = openssl_csr_sign($csr, null, $pkey, 1);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($pkey, $pkeyPem);
        return new Certificado($certPem, $pkeyPem);
    }

    private function cargarCafBoleta(): void
    {
        $caf = CafDePrueba::generar(self::RUT, TipoDte::BoletaElectronica->value, 1, 100);
        if ($caf === null) {
            self::markTestSkipped('No se pudo generar par RSA para el CAF (falta openssl.cnf)');
        }
        $this->folios->cargarCaf(self::RUT, TipoDte::BoletaElectronica, Ambiente::Certificacion, 1, 100, $caf['xml']);
    }

    private function cargarEmisorCompleto(): void
    {
        $this->emisor->cargarCertificado(self::RUT, Ambiente::Certificacion, $this->certificadoAutoFirmado());
        $this->emisor->cargarDatos(self::RUT, Ambiente::Certificacion, new DatosEmisor(
            rutEmisor:        self::RUT,
            razonSocial:      'EASY AGENDA SPA',
            giro:             'Servicios de agenda online',
            acteco:           620200,
            dirOrigen:        'Direccion de prueba 123',
            cmnaOrigen:       'SANTIAGO',
            resolucionFecha:  '2026-01-01',
            resolucionNumero: 0,
        ));
    }

    private function credenciales(): Credenciales
    {
        return new Credenciales(
            rutEmisor: self::RUT,
            apiToken:  'no-usado-por-sii-directo',
            ambiente:  Ambiente::Certificacion,
            rutSender: '13520634-2',
        );
    }

    /** Respuesta REST de boleta: <SII:RESPUESTA> con ESTADO=00 (OK). */
    private function seedBoleta(): Response
    {
        $xml = '<?xml version="1.0"?><SII:RESPUESTA xmlns:SII="http://www.sii.cl/XMLSchema">'
            . '<SII:RESP_HDR><ESTADO>00</ESTADO></SII:RESP_HDR>'
            . '<SII:RESP_BODY><SEMILLA>123456</SEMILLA></SII:RESP_BODY>'
            . '</SII:RESPUESTA>';
        return new Response(200, [], $xml);
    }

    private function tokenBoleta(): Response
    {
        $xml = '<?xml version="1.0"?><SII:RESPUESTA xmlns:SII="http://www.sii.cl/XMLSchema">'
            . '<SII:RESP_HDR><ESTADO>00</ESTADO></SII:RESP_HDR>'
            . '<SII:RESP_BODY><TOKEN>TOKEN-BOLETA-TEST</TOKEN></SII:RESP_BODY>'
            . '</SII:RESPUESTA>';
        return new Response(200, [], $xml);
    }

    private function uploadBoleta(string $trackId, string $estado = 'REC'): Response
    {
        return new Response(200, [], json_encode(['trackid' => $trackId, 'estado' => $estado]));
    }

    /**
     * @param list<Response> $responses
     */
    private function makeFacturador(array $responses, ?InMemoryDteEmitidoRepository $dteEmitido = null): BoletaFacturador
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        return new BoletaFacturador(
            new Client(['handler' => $stack]),
            $this->folios,
            $this->emisor,
            dteEmitido: $dteEmitido,
        );
    }

    private function boleta(int $cantidad, float $precioUnitario, string $nombre): DocumentoTributario
    {
        return new DocumentoTributario(
            tipoDte: TipoDte::BoletaElectronica,
            receptor: new Receptor('66666666-6', 'Consumidor Final'),
            detalles: [new Detalle($nombre, $cantidad, $precioUnitario)],
            montosSonBrutos: true,
        );
    }

    public function testEmitirLotePersisteCadaBoletaEnDteEmitidoConSusPropiosMontos(): void
    {
        $this->cargarCafBoleta();
        $this->cargarEmisorCompleto();
        $dteEmitido = new InMemoryDteEmitidoRepository();

        $facturador = $this->makeFacturador(
            [$this->seedBoleta(), $this->tokenBoleta(), $this->uploadBoleta('555555')],
            $dteEmitido,
        );

        // Montos deliberadamente DISTINTOS entre los dos documentos: si el fix
        // agarrara los montos del primer <Documento> del sobre para ambas
        // filas (el bug obvio de reusar extraerMontos() sin acotar el scope),
        // ambas filas quedarian con el mismo total y este test lo detecta.
        $docs = [
            $this->boleta(1, 1000, 'Item A'),  // bruto 1000
            $this->boleta(3, 1000, 'Item B'),  // bruto 3000
        ];

        $resultado = $facturador->emitirLote($docs, $this->credenciales());

        self::assertSame('555555', $resultado['trackId']);
        self::assertCount(2, $resultado['boletas']);

        $filas = $dteEmitido->todas();
        self::assertCount(2, $filas, 'Debe persistir UNA fila por boleta del lote');

        // Folio 1 (Item A, bruto 1000): neto=840, iva=160 (diferencia exacta, ver DteXmlBuilder), total=1000.
        self::assertSame(1, $filas[0]['folio']);
        self::assertSame(39, $filas[0]['tipoDte']);
        self::assertSame('555555', $filas[0]['trackId']);
        self::assertSame('enviado', $filas[0]['estado']);
        self::assertSame(840, $filas[0]['neto']);
        self::assertSame(160, $filas[0]['iva']);
        self::assertSame(1000, $filas[0]['total']);

        // Folio 2 (Item B, bruto 3000): neto=2521, iva=479, total=3000 -- DISTINTO del folio 1.
        self::assertSame(2, $filas[1]['folio']);
        self::assertSame(2521, $filas[1]['neto']);
        self::assertSame(479, $filas[1]['iva']);
        self::assertSame(3000, $filas[1]['total']);

        // Ambas filas comparten el mismo XML de sobre (EnvioBOLETA completo),
        // mismo criterio que persistirEmitidosLote() de SiiDirectoFacturador.
        self::assertSame($filas[0]['xml'], $filas[1]['xml']);
        self::assertStringContainsString('<EnvioBOLETA', $filas[0]['xml']);
    }

    public function testEmitirLoteSinRepositorioInyectadoNoFalla(): void
    {
        $this->cargarCafBoleta();
        $this->cargarEmisorCompleto();

        // dteEmitido = null (default): emitirLote() debe seguir funcionando,
        // simplemente sin persistir nada.
        $facturador = $this->makeFacturador([$this->seedBoleta(), $this->tokenBoleta(), $this->uploadBoleta('777')]);

        $resultado = $facturador->emitirLote([$this->boleta(1, 1000, 'Item unico')], $this->credenciales());

        self::assertSame('777', $resultado['trackId']);
    }

    public function testEmitirLoteClasicoTambienPersiste(): void
    {
        // emitirLoteClasico() comparte emitirLoteInterno(): el fix debe cubrir
        // tambien ese path (aunque sea "solo diagnostico legado").
        $this->cargarCafBoleta();
        $this->cargarEmisorCompleto();
        $dteEmitido = new InMemoryDteEmitidoRepository();

        // Canal clasico: seed/token vienen del autenticadorClasico (SOAP), no
        // del REST -- reusa los mismos fixtures XML que SiiDirectoFacturadorTest.
        $facturador = $this->makeFacturador(
            [
                new Response(200, [], (string) file_get_contents(__DIR__ . '/fixtures/sii_seed_response.xml')),
                new Response(200, [], (string) file_get_contents(__DIR__ . '/fixtures/sii_token_response.xml')),
                new Response(200, [], '<?xml version="1.0"?><RECEPCIONDTE><TRACKID>999</TRACKID><STATUS>0</STATUS></RECEPCIONDTE>'),
            ],
            $dteEmitido,
        );

        $facturador->emitirLoteClasico([$this->boleta(1, 1000, 'Item unico')], $this->credenciales());

        self::assertCount(1, $dteEmitido->todas());
        self::assertSame('999', $dteEmitido->todas()[0]['trackId']);
    }
}
