<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use DOMDocument;
use DOMElement;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Dto\Certificado;
use Plantiflex\FacturacionCl\Dto\Credenciales;
use Plantiflex\FacturacionCl\Dto\DatosEmisor;
use Plantiflex\FacturacionCl\Dto\DocumentoOriginal;
use Plantiflex\FacturacionCl\Dto\DocumentoTributario;
use Plantiflex\FacturacionCl\Dto\Detalle;
use Plantiflex\FacturacionCl\Dto\Receptor;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Enums\TipoAnulacion;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Exceptions\CertificadoNoEncontradoException;
use Plantiflex\FacturacionCl\Exceptions\EnvioRechazadoException;
use Plantiflex\FacturacionCl\Exceptions\OperacionNoSoportadaException;
use Plantiflex\FacturacionCl\Providers\SiiDirectoFacturador;
use Plantiflex\FacturacionCl\Sii\XmlSigner;
use Psr\Http\Message\RequestInterface;

final class SiiDirectoFacturadorTest extends TestCase
{
    private const RUT     = '77724622-4';
    private const NS_DSIG = 'http://www.w3.org/2000/09/xmldsig#';
    private const NS_SII  = 'http://www.sii.cl/SiiDte';

    /** @var list<array{request: RequestInterface, response: mixed, ...}> */
    private array $history = [];

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

    /**
     * @param list<Response> $responses
     */
    private function makeFacturador(array $responses = []): SiiDirectoFacturador
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        return new SiiDirectoFacturador(
            new Client(['handler' => $stack]),
            $this->folios,
            $this->emisor,
        );
    }

    private function credenciales(): Credenciales
    {
        return new Credenciales(
            rutEmisor: self::RUT,
            apiToken:  'ignored-by-sii-directo',
            ambiente:  Ambiente::Certificacion,
            rutSender: '13520634-2',
        );
    }

    private function cargarCaf(TipoDte $tipo): void
    {
        // CAF valido con RSASK real: el flujo de emision ahora genera el TED,
        // que necesita la llave privada del CAF para firmar el DD.
        $caf = CafDePrueba::generar(self::RUT, $tipo->value, 1, 100);
        if ($caf === null) {
            self::markTestSkipped('No se pudo generar par RSA para el CAF (falta openssl.cnf)');
        }
        $this->folios->cargarCaf(self::RUT, $tipo, Ambiente::Certificacion, 1, 100, $caf['xml']);
    }

    private function cargarEmisorCompleto(): void
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

    private function factura(): DocumentoTributario
    {
        return new DocumentoTributario(
            tipoDte: TipoDte::FacturaElectronica,
            receptor: new Receptor('99999999-9', 'Empresa Cliente'),
            detalles: [new Detalle('Servicio', 1, 50000)],
            montosSonBrutos: false,
        );
    }

    private function seed(): Response
    {
        return new Response(200, [], (string) file_get_contents(__DIR__ . '/fixtures/sii_seed_response.xml'));
    }

    private function tokenResp(): Response
    {
        return new Response(200, [], (string) file_get_contents(__DIR__ . '/fixtures/sii_token_response.xml'));
    }

    private function uploadResp(string $status, string $trackId = ''): Response
    {
        $track = $trackId === '' ? '' : "<TRACKID>$trackId</TRACKID>";
        return new Response(200, [], "<?xml version=\"1.0\"?><RECEPCIONDTE>$track<STATUS>$status</STATUS></RECEPCIONDTE>");
    }

    // ------------------------------------------------------------------

    public function testNombreProveedor(): void
    {
        self::assertSame('sii_directo', $this->makeFacturador()->nombreProveedor());
    }

    public function testAutenticarObtieneCertYDevuelveTokenDelSii(): void
    {
        $this->emisor->cargarCertificado(self::RUT, Ambiente::Certificacion, $this->certificadoAutoFirmado());

        $facturador = $this->makeFacturador([$this->seed(), $this->tokenResp()]);

        $token = $facturador->autenticar($this->credenciales());
        self::assertSame('7E0AAFB1-FAF7-46AF-9183-E2EFC07F45A2', $token);
    }

    public function testConsultarEnvioPorTrackIdDevuelveEstadoDte(): void
    {
        $this->emisor->cargarCertificado(self::RUT, Ambiente::Certificacion, $this->certificadoAutoFirmado());

        $consultaInner = '<?xml version="1.0"?>'
            . '<SII:RESPUESTA xmlns:SII="http://www.sii.cl/XMLSchema">'
            . '<SII:RESP_HDR><ESTADO>EPR</ESTADO><GLOSA>Envio Procesado</GLOSA></SII:RESP_HDR>'
            . '</SII:RESPUESTA>';
        $consultaResp = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<SOAP-ENV:Body><ns1:getEstUpResponse xmlns:ns1="http://DefaultNamespace">'
            . '<getEstUpReturn>' . htmlspecialchars($consultaInner, ENT_QUOTES | ENT_XML1) . '</getEstUpReturn>'
            . '</ns1:getEstUpResponse></SOAP-ENV:Body></SOAP-ENV:Envelope>';

        // 3 llamadas: seed, token, consulta.
        $facturador = $this->makeFacturador([
            $this->seed(),
            $this->tokenResp(),
            new Response(200, [], $consultaResp),
        ]);

        $estado = $facturador->consultarEnvioPorTrackId('0249995332', $this->credenciales());

        self::assertSame('EPR', $estado->estado);
        self::assertSame('Envio Procesado', $estado->glosa);
        self::assertSame('0249995332', $estado->trackId);
        self::assertNull($estado->folio, 'Una consulta por TrackID no tiene folio unico');

        self::assertCount(3, $this->history);
        self::assertStringContainsString('QueryEstUp.jws', (string) $this->history[2]['request']->getUri());
    }

    public function testEmitirCompletoAsignaFolioFirmaYSube(): void
    {
        $this->cargarCaf(TipoDte::FacturaElectronica);
        $this->cargarEmisorCompleto();

        $facturador = $this->makeFacturador([
            $this->seed(),
            $this->tokenResp(),
            $this->uploadResp('0', '9988776'),
        ]);

        $resultado = $facturador->emitir($this->factura(), $this->credenciales());

        // Folio asignado (CAF 1-100 => 1) y trackId del upload.
        self::assertSame(1, $resultado->folio);
        self::assertSame('9988776', $resultado->trackId);
        self::assertSame(TipoDte::FacturaElectronica, $resultado->tipoDte);

        // marcarFolioComoUsado(true).
        $log = $this->folios->log();
        self::assertCount(1, $log);
        self::assertSame(1, $log[0]['folio']);
        self::assertTrue($log[0]['exitosa']);

        // 3 llamadas HTTP: getSeed, getToken, upload.
        self::assertCount(3, $this->history);

        // El body del upload (3er request) contiene el EnvioDTE con DOS firmas:
        // una hermana del <Documento> (dentro de <DTE>) y otra hermana del
        // <SetDTE> (dentro de <EnvioDTE>).
        $body = (string) $this->history[2]['request']->getBody();
        $ini  = strpos($body, '<EnvioDTE');
        $fin  = strpos($body, '</EnvioDTE>');
        self::assertNotFalse($ini);
        self::assertNotFalse($fin);
        $xml = substr($body, $ini, $fin - $ini + strlen('</EnvioDTE>'));

        // Ninguna firma debe llevar prefijo "default:" (el SII lo rechaza).
        // Esto cubre la firma del Documento, que antes salia con prefijo por el
        // importNode de EnvioDteBuilder.
        self::assertStringNotContainsString('default:', $xml, 'El EnvioDTE no debe tener prefijo default:');
        self::assertStringNotContainsString('xmlns:default', $xml);

        // Ninguna linea debe superar el limite del parser del SII (~4096): los
        // base64 largos (cert, modulus, firmas, FRMT) van partidos en 64.
        foreach (explode("\n", $xml) as $linea) {
            self::assertLessThanOrEqual(4096, strlen($linea), 'Linea del EnvioDTE supera 4096 chars');
        }

        $dom = new DOMDocument();
        self::assertTrue($dom->loadXML($xml));

        $firmas = $dom->getElementsByTagNameNS(self::NS_DSIG, 'Signature');
        self::assertSame(2, $firmas->length, 'Deben existir 2 firmas (Documento y SetDTE)');

        // Ambas firmas verifican matematicamente (Documento dentro del doc final).
        $pub = openssl_pkey_get_public(
            $this->emisor->obtenerCertificado(self::RUT, Ambiente::Certificacion)->certData,
        );
        self::assertNotFalse($pub);
        foreach ($firmas as $firma) {
            $ref = $firma->getElementsByTagNameNS(self::NS_DSIG, 'Reference')->item(0);
            self::assertNotNull($ref);
            $uri = $ref->getAttribute('URI');
            $id  = ltrim($uri, '#');

            $sigValue = $firma->getElementsByTagNameNS(self::NS_DSIG, 'SignatureValue')->item(0);
            self::assertNotNull($sigValue);
            $sigBin = base64_decode(trim((string) $sigValue->textContent), true);
            self::assertIsString($sigBin);

            // Resolver el nodo referenciado: el <Documento> se valida standalone
            // (SIN el xsi heredado del EnvioDTE); el SetDTE se valida en contexto.
            $nodoRef = null;
            foreach ($dom->getElementsByTagName('*') as $el) {
                if ($el->getAttribute('ID') === $id) {
                    $nodoRef = $el;
                    break;
                }
            }
            self::assertNotNull($nodoRef);
            $esDocumento = ($nodoRef->localName === 'Documento');

            // Reconstruir el C14N del SignedInfo TAL COMO se firmo / como lo valida el SII.
            $xmlVerif = XmlSigner::limpiarPrefijosDsig($xml);
            if ($esDocumento) {
                $xmlVerif = preg_replace('/ xmlns:xsi="[^"]*"/', '', $xmlVerif, 1);
                $xmlVerif = preg_replace('/ xsi:schemaLocation="[^"]*"/', '', $xmlVerif, 1);
            }
            $copia = new DOMDocument();
            self::assertTrue($copia->loadXML($xmlVerif));

            $siC14n = null;
            foreach ($copia->getElementsByTagNameNS(self::NS_DSIG, 'Signature') as $s) {
                $r = $s->getElementsByTagNameNS(self::NS_DSIG, 'Reference')->item(0);
                if ($r instanceof DOMElement && $r->getAttribute('URI') === $uri) {
                    $si = $s->getElementsByTagNameNS(self::NS_DSIG, 'SignedInfo')->item(0);
                    self::assertNotNull($si);
                    $siC14n = $si->C14N();
                    break;
                }
            }
            self::assertIsString($siC14n);

            self::assertSame(
                1,
                openssl_verify($siC14n, $sigBin, $pub, OPENSSL_ALGO_SHA1),
                "La firma de #$id debe verificar con la llave publica del cert (C14N como la valida el SII)",
            );
        }

        $padres = [];
        foreach ($firmas as $f) {
            $padres[] = $f->parentNode?->localName;
        }
        self::assertContains('DTE', $padres, 'Firma del Documento, hermana dentro de <DTE>');
        self::assertContains('EnvioDTE', $padres, 'Firma del SetDTE, hermana dentro de <EnvioDTE>');

        // El root EnvioDTE debe tener xmlns:xsi (SCH-00001 sin el).
        self::assertStringContainsString('xmlns:xsi', $xml);
        self::assertStringContainsString('xsi:schemaLocation="http://www.sii.cl/SiiDte EnvioDTE_v10.xsd"', $xml);

        // El Documento firmado conserva su ID.
        $documento = $dom->getElementsByTagNameNS(self::NS_SII, 'Documento')->item(0);
        self::assertNotNull($documento);
        self::assertSame('F1T33', $documento->getAttribute('ID'));

        self::assertInstanceOf(DOMElement::class, $documento);

        // El SII extrae el <Documento> del sobre y valida que su DigestValue sea
        // SHA1(C14N(Documento aislado tal cual viaja)). Da igual si el nodo trae
        // xmlns:xsi: lo que importa es que el digest se haya calculado sobre la
        // MISMA forma serializada (si no, DTE-3-505). Lo verificamos recomputando.
        $idDoc = $documento->getAttribute('ID');
        $marca = '<Documento ID="' . $idDoc . '"';
        $iniDoc = strpos($xml, $marca);
        self::assertNotFalse($iniDoc);
        $finDoc = strpos($xml, '</Documento>', $iniDoc);
        self::assertNotFalse($finDoc);
        $documentoStr = substr($xml, $iniDoc, $finDoc - $iniDoc + strlen('</Documento>'));

        $tmpDoc = new DOMDocument();
        self::assertTrue($tmpDoc->loadXML('<?xml version="1.0" encoding="UTF-8"?><DTE>' . $documentoStr . '</DTE>'));
        $docAislado = $tmpDoc->getElementsByTagName('Documento')->item(0);
        self::assertInstanceOf(DOMElement::class, $docAislado);
        $digestEsperado = base64_encode(sha1((string) $docAislado->C14N(), true));

        $digestDeclarado = null;
        foreach ($firmas as $f) {
            $r = $f->getElementsByTagNameNS(self::NS_DSIG, 'Reference')->item(0);
            if ($r instanceof DOMElement && $r->getAttribute('URI') === '#' . $idDoc) {
                $dv = $f->getElementsByTagNameNS(self::NS_DSIG, 'DigestValue')->item(0);
                self::assertNotNull($dv);
                $digestDeclarado = trim((string) $dv->textContent);
                break;
            }
        }
        self::assertSame($digestEsperado, $digestDeclarado, 'El DigestValue del Documento debe coincidir con SHA1(C14N(Documento aislado)) - asi lo valida el SII');

        // El TED quedo DENTRO del Documento firmado (con su FRMT). El TED esta
        // en el namespace SiiDte (elementFormDefault="qualified"), NO con xmlns="".
        self::assertSame(1, $documento->getElementsByTagNameNS(self::NS_SII, 'TED')->length);
        self::assertSame(1, $documento->getElementsByTagNameNS(self::NS_SII, 'FRMT')->length);
        self::assertDoesNotMatchRegularExpression('/<TED[^>]*xmlns=""/', $xml);
        // TmstFirma presente dentro del Documento (despues del TED, antes de la firma).
        self::assertSame(1, $documento->getElementsByTagNameNS(self::NS_SII, 'TmstFirma')->length);
    }

    public function testEmitirConUploadRechazadoMarcaFolioFallidoYRelanza(): void
    {
        $this->cargarCaf(TipoDte::FacturaElectronica);
        $this->cargarEmisorCompleto();

        $facturador = $this->makeFacturador([
            $this->seed(),
            $this->tokenResp(),
            $this->uploadResp('99'), // rechazo
        ]);

        try {
            $facturador->emitir($this->factura(), $this->credenciales());
            self::fail('Se esperaba EnvioRechazadoException');
        } catch (EnvioRechazadoException $e) {
            self::assertSame('99', $e->status);
        }

        $log = $this->folios->log();
        self::assertCount(1, $log);
        self::assertFalse($log[0]['exitosa'], 'Folio quemado con exito=false tras rechazo');
    }

    public function testEmitirSinCertificadoNoAsignaFolio(): void
    {
        // CAF cargado, pero NO certificado ni datos: obtenerCertificado falla
        // antes de asignar folio.
        $this->cargarCaf(TipoDte::FacturaElectronica);

        $facturador = $this->makeFacturador([]); // no debe hacer HTTP

        try {
            $facturador->emitir($this->factura(), $this->credenciales());
            self::fail('Se esperaba CertificadoNoEncontradoException');
        } catch (CertificadoNoEncontradoException $e) {
            // OK
        }

        self::assertCount(0, $this->folios->log(), 'No debe asignarse folio por falta de cert');
        self::assertCount(0, $this->history, 'No debe hacer HTTP');
    }

    public function testConsultarEstadoLanzaOperacionNoSoportada(): void
    {
        $facturador = $this->makeFacturador();
        $this->expectException(OperacionNoSoportadaException::class);
        $facturador->consultarEstado(1, TipoDte::FacturaElectronica->value, $this->credenciales());
    }

    private function original(TipoDte $tipo = TipoDte::FacturaElectronica): DocumentoOriginal
    {
        return new DocumentoOriginal(
            tipoDte:      $tipo,
            folio:        4242,
            fechaEmision: new \DateTimeImmutable('2026-05-20'),
            receptor:     new Receptor('99999999-9', 'Empresa Cliente'),
            detalles:     [new Detalle('Servicio', 1, 50000)],
            montoNeto:    50000,
            iva:          9500,
            montoTotal:   59500,
        );
    }

    /** EnvioDTE del ultimo request (el upload). */
    private function envioDelUpload(): DOMDocument
    {
        $entries = $this->history;
        $last = end($entries);
        $body = (string) $last['request']->getBody();
        $ini  = strpos($body, '<EnvioDTE');
        $fin  = strpos($body, '</EnvioDTE>');
        self::assertNotFalse($ini);
        self::assertNotFalse($fin);
        $dom = new DOMDocument();
        self::assertTrue($dom->loadXML(substr($body, $ini, $fin - $ini + strlen('</EnvioDTE>'))));
        return $dom;
    }

    private function refCampo(DOMDocument $dom, string $tag): string
    {
        $ref = $dom->getElementsByTagNameNS(self::NS_SII, 'Referencia')->item(0);
        self::assertInstanceOf(DOMElement::class, $ref);
        $n = $ref->getElementsByTagNameNS(self::NS_SII, $tag)->item(0);
        return $n === null ? '' : trim((string) $n->textContent);
    }

    private function cantidadReferencias(DOMDocument $dom): int
    {
        return $dom->getElementsByTagNameNS(self::NS_SII, 'Referencia')->length;
    }

    private function tipoDteEmitido(DOMDocument $dom): string
    {
        return trim((string) $dom->getElementsByTagNameNS(self::NS_SII, 'TipoDTE')->item(0)->textContent);
    }

    private function mntTotalEmitido(DOMDocument $dom): string
    {
        return trim((string) $dom->getElementsByTagNameNS(self::NS_SII, 'MntTotal')->item(0)->textContent);
    }

    private function primerTexto(DOMDocument $dom, string $tag): string
    {
        $n = $dom->getElementsByTagNameNS(self::NS_SII, $tag)->item(0);
        return $n === null ? '' : trim((string) $n->textContent);
    }

    public function testAnularAnulaTotalEmiteNc61ConReferenciaYMontoDelOriginal(): void
    {
        $this->cargarCaf(TipoDte::NotaCreditoElectronica);
        $this->cargarEmisorCompleto();
        $facturador = $this->makeFacturador([$this->seed(), $this->tokenResp(), $this->uploadResp('0', 'TRK-NC')]);

        $resultado = $facturador->anular($this->original(), 'Anula factura', TipoAnulacion::AnulaTotal, $this->credenciales());

        self::assertSame(TipoDte::NotaCreditoElectronica, $resultado->tipoDte);
        self::assertSame('TRK-NC', $resultado->trackId);

        $dom = $this->envioDelUpload();
        self::assertSame('61', $this->tipoDteEmitido($dom));
        self::assertSame('33', $this->refCampo($dom, 'TpoDocRef'));
        self::assertSame('4242', $this->refCampo($dom, 'FolioRef'));
        self::assertSame('2026-05-20', $this->refCampo($dom, 'FchRef'));
        self::assertSame('1', $this->refCampo($dom, 'CodRef'));
        self::assertSame('Anula factura', $this->refCampo($dom, 'RazonRef'));
        self::assertSame('59500', $this->mntTotalEmitido($dom), 'NC anula total: replica MntTotal del original');
    }

    public function testAnularAnulaTotalIncluyeMontoExentoDelOriginal(): void
    {
        $this->cargarCaf(TipoDte::NotaCreditoElectronica);
        $this->cargarEmisorCompleto();
        $facturador = $this->makeFacturador([$this->seed(), $this->tokenResp(), $this->uploadResp('0', 'TRK-NC')]);

        $original = new DocumentoOriginal(
            tipoDte:      TipoDte::FacturaElectronica,
            folio:        4243,
            fechaEmision: new \DateTimeImmutable('2026-05-20'),
            receptor:     new Receptor('99999999-9', 'Empresa Cliente'),
            detalles:     [
                new Detalle('Afecto', 1, 10000),
                new Detalle('Exento', 1, 5000, exento: true),
            ],
            montoNeto:    10000,
            iva:          1900,
            montoTotal:   16900,
        );

        $facturador->anular($original, 'Anula factura', TipoAnulacion::AnulaTotal, $this->credenciales());

        $dom = $this->envioDelUpload();
        self::assertSame('5000', $this->primerTexto($dom, 'MntExe'));
        self::assertSame('16900', $this->mntTotalEmitido($dom));
    }

    public function testAnularCorrigeTextoEmiteNc61ConMntTotalCero(): void
    {
        $this->cargarCaf(TipoDte::NotaCreditoElectronica);
        $this->cargarEmisorCompleto();
        $facturador = $this->makeFacturador([$this->seed(), $this->tokenResp(), $this->uploadResp('0', 'TRK')]);

        $facturador->anular($this->original(), 'Giro receptor erroneo', TipoAnulacion::CorrigeTexto, $this->credenciales());

        $dom = $this->envioDelUpload();
        self::assertSame('61', $this->tipoDteEmitido($dom));
        self::assertSame('2', $this->refCampo($dom, 'CodRef'), 'CorrigeTexto -> CodRef=2');
        self::assertSame('0', $this->mntTotalEmitido($dom), 'CorrigeTexto -> MntTotal=0');
    }

    public function testAnularCorrigeMontoLanzaOperacionNoSoportada(): void
    {
        $facturador = $this->makeFacturador();
        $this->expectException(OperacionNoSoportadaException::class);
        $facturador->anular($this->original(), 'motivo', TipoAnulacion::CorrigeMonto, $this->credenciales());
    }

    public function testEmitirDocumentoReferenciadoNcCorrigeMontoConDetallesPropios(): void
    {
        $this->cargarCaf(TipoDte::NotaCreditoElectronica);
        $this->cargarEmisorCompleto();
        $facturador = $this->makeFacturador([$this->seed(), $this->tokenResp(), $this->uploadResp('0', 'TRK')]);

        // NC por devolucion parcial: items propios.
        $nc = new DocumentoTributario(
            tipoDte: TipoDte::NotaCreditoElectronica,
            receptor: new Receptor('99999999-9', 'Empresa Cliente'),
            detalles: [new Detalle('Devolucion producto A', 1, 10000)],
            montosSonBrutos: false,
            referencias: [[
                'tipoDocumento' => '33',
                'folio'         => 4242,
                'fecha'         => '2026-05-20',
                'codigo'        => '3',
                'razon'         => 'Devolucion parcial',
            ]],
            observaciones: 'Devolucion parcial',
        );

        $resultado = $facturador->emitirDocumentoReferenciado(
            $nc,
            $this->original(),
            TipoAnulacion::CorrigeMonto,
            $this->credenciales(),
        );

        self::assertSame(TipoDte::NotaCreditoElectronica, $resultado->tipoDte);

        $dom = $this->envioDelUpload();
        self::assertSame('61', $this->tipoDteEmitido($dom));
        self::assertSame(1, $this->cantidadReferencias($dom), 'No debe duplicar Referencia si el documento ya la trae');
        self::assertSame('3', $this->refCampo($dom, 'CodRef'), 'CorrigeMonto -> CodRef=3');
        self::assertSame('Devolucion parcial', $this->refCampo($dom, 'RazonRef'));
        // Detalle propio presente (10000 neto -> 11900 total).
        self::assertSame('Devolucion producto A', trim((string) $dom->getElementsByTagNameNS(self::NS_SII, 'NmbItem')->item(0)->textContent));
        self::assertSame('11900', $this->mntTotalEmitido($dom));
    }

    public function testEmitirDocumentoReferenciadoNotaDebito56AnulaNc(): void
    {
        $this->cargarCaf(TipoDte::NotaDebitoElectronica);
        $this->cargarEmisorCompleto();
        $facturador = $this->makeFacturador([$this->seed(), $this->tokenResp(), $this->uploadResp('0', 'TRK')]);

        // ND (56) que anula una NC (61).
        $nd = new DocumentoTributario(
            tipoDte: TipoDte::NotaDebitoElectronica,
            receptor: new Receptor('99999999-9', 'Empresa Cliente'),
            detalles: [new Detalle('Anula NC', 1, 59500)],
            montosSonBrutos: false,
            observaciones: 'Anula nota de credito',
        );
        $ncOriginal = $this->original(TipoDte::NotaCreditoElectronica);

        $resultado = $facturador->emitirDocumentoReferenciado(
            $nd,
            $ncOriginal,
            TipoAnulacion::AnulaTotal,
            $this->credenciales(),
        );

        self::assertSame(TipoDte::NotaDebitoElectronica, $resultado->tipoDte);

        $dom = $this->envioDelUpload();
        self::assertSame('56', $this->tipoDteEmitido($dom));
        self::assertSame('61', $this->refCampo($dom, 'TpoDocRef'), 'Referencia a la NC (61)');
        self::assertSame('1', $this->refCampo($dom, 'CodRef'), 'AnulaTotal -> CodRef=1');
    }
}
