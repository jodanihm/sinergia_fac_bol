<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use DOMDocument;
use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Dto\Certificado;
use Plantiflex\FacturacionCl\Dto\DatosEmisor;
use Plantiflex\FacturacionCl\Dto\Detalle;
use Plantiflex\FacturacionCl\Dto\DocumentoTributario;
use Plantiflex\FacturacionCl\Dto\Receptor;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Sii\DteXmlBuilder;
use Plantiflex\FacturacionCl\Sii\EnvioDteBuilder;
use Plantiflex\FacturacionCl\Sii\EnvioDteParser;
use Plantiflex\FacturacionCl\Sii\EnvioRecibosBuilder;
use Plantiflex\FacturacionCl\Sii\RespuestaDteBuilder;
use Plantiflex\FacturacionCl\Sii\XmlSigner;

/**
 * Verifica el flujo completo de la etapa Intercambio (parse -> generar las 3
 * respuestas -> detectar aceptacion/rechazo) con un EnvioDTE SINTETICO (2
 * documentos: uno dirigido al RUT del tenant de prueba, otro a un RUT ajeno),
 * sin gastar el EnvioDTE real de EASY AGENDA (numero 4955508, de un solo uso)
 * ni tocar el SII.
 *
 * El EnvioDTE sintetico se construye con EnvioDteBuilder + DteXmlBuilder (las
 * MISMAS clases que ya usa el motor para emitir), no a mano: asi se garantiza
 * que es estructuralmente valido para EnvioDteParser sin tener que adivinar
 * el formato exacto del XML.
 */
final class IntercambioFlowTest extends TestCase
{
    private const RUT_TENANT       = '77724622-4';
    private const RUT_AJENO        = '11111111-1';
    private const RUT_SII_FICTICIO = '88888888-8';
    private const NS_SII           = 'http://www.sii.cl/SiiDte';

    private function certificadoAutoFirmado(): Certificado
    {
        $pkey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($pkey === false) {
            self::markTestSkipped('openssl_pkey_new fallo (falta openssl.cnf)');
        }
        $csr  = openssl_csr_new(['commonName' => 'test.sii.local'], $pkey);
        $cert = openssl_csr_sign($csr, null, $pkey, 1);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($pkey, $pkeyPem);

        return new Certificado($certPem, $pkeyPem);
    }

    private function emisorFicticio(): DatosEmisor
    {
        return new DatosEmisor(
            rutEmisor: self::RUT_SII_FICTICIO,
            razonSocial: 'SII Certificacion (ficticio)',
            giro: 'Servicios',
            acteco: 1,
            dirOrigen: 'Teatinos 120',
            cmnaOrigen: 'Santiago',
            resolucionFecha: '2024-01-01',
            resolucionNumero: 0,
        );
    }

    private function construirEnvioSintetico(): string
    {
        $emisor = $this->emisorFicticio();

        $docAceptado = new DocumentoTributario(
            tipoDte: TipoDte::FacturaElectronica,
            receptor: new Receptor(self::RUT_TENANT, 'Tenant de prueba'),
            detalles: [new Detalle('Item dirigido al tenant', 1, 1000)],
        );
        $docRechazado = new DocumentoTributario(
            tipoDte: TipoDte::FacturaElectronica,
            receptor: new Receptor(self::RUT_AJENO, 'Receptor ajeno'),
            detalles: [new Detalle('Item dirigido a un RUT ajeno', 1, 2000)],
        );

        $domAceptado  = (new DteXmlBuilder())->build($docAceptado, $emisor, 1);
        $domRechazado = (new DteXmlBuilder())->build($docRechazado, $emisor, 2);

        $domEnvio = (new EnvioDteBuilder())->build(
            [$domAceptado, $domRechazado],
            $emisor,
            self::RUT_TENANT,
            self::RUT_SII_FICTICIO,
            Ambiente::Certificacion,
        );

        return (string) $domEnvio->saveXML();
    }

    /** @return array<string,string> folio => EstadoDTE */
    private function estadosPorFolio(string $xmlResultado): array
    {
        $dom = new DOMDocument();
        $dom->loadXML($xmlResultado);

        $estados = [];
        foreach ($dom->getElementsByTagNameNS(self::NS_SII, 'ResultadoDTE') as $rd) {
            $folio  = $rd->getElementsByTagNameNS(self::NS_SII, 'Folio')->item(0)->textContent;
            $estado = $rd->getElementsByTagNameNS(self::NS_SII, 'EstadoDTE')->item(0)->textContent;
            $estados[$folio] = $estado;
        }

        return $estados;
    }

    public function testFlujoCompletoParseoGeneracionYDeteccionDeAceptacionRechazo(): void
    {
        $xmlOriginal = $this->construirEnvioSintetico();

        $envio = new EnvioDteParser();
        $envio->loadXML($xmlOriginal);

        self::assertCount(2, $envio->documentos);
        self::assertSame(self::RUT_SII_FICTICIO, $envio->caratula['RutEmisor'] ?? null);

        $cert = $this->certificadoAutoFirmado();
        $car  = [
            'RutResponde'  => self::RUT_TENANT,
            'RutRecibe'    => $envio->caratula['RutEmisor'] ?? '',
            'IdRespuesta'  => 1,
            'MailContacto' => 'tenant@ejemplo.cl',
        ];

        $xmlAcuse     = (new RespuestaDteBuilder(new XmlSigner()))->acuseRecibo($envio, $car, $cert);
        $xmlResultado = (new RespuestaDteBuilder(new XmlSigner()))->aceptacionRechazo($envio, $car, $cert);
        $xmlRecibos   = (new EnvioRecibosBuilder(new XmlSigner()))->generar($envio, $car, $cert, 'Av Siempre Viva 123', self::RUT_TENANT);

        self::assertNotSame('', $xmlAcuse);
        self::assertNotSame('', $xmlResultado);
        self::assertNotSame('', $xmlRecibos);

        // El documento dirigido al tenant (folio 1) debe quedar ACEPTADO (0);
        // el dirigido al RUT ajeno (folio 2) debe quedar RECHAZADO (2) -- el
        // "documento trampa" del intercambio real.
        $estados = $this->estadosPorFolio($xmlResultado);
        self::assertSame('0', $estados['1'] ?? null);
        self::assertSame('2', $estados['2'] ?? null);

        // EnvioRecibos solo debe traer un <Recibo> real (el del documento
        // aceptado); el rechazado no genera recibo (Ley 19.983 aplica solo a
        // documentos efectivamente dirigidos al tenant).
        self::assertSame(1, substr_count($xmlRecibos, '<DocumentoRecibo'));
        self::assertStringContainsString('T33F1', $xmlRecibos);
        self::assertStringNotContainsString('T33F2', $xmlRecibos);
    }

    public function testEnvioRecibosLanzaExcepcionSiNingunDocumentoVaDirigidoAlTenant(): void
    {
        $xmlOriginal = $this->construirEnvioSintetico();
        $envio = new EnvioDteParser();
        $envio->loadXML($xmlOriginal);

        $cert = $this->certificadoAutoFirmado();
        // RutResponde que no coincide con NINGUN RUTRecep del envio (ni el
        // tenant ni el ajeno): debe fallar explicito, no un 500 silencioso.
        $car = ['RutResponde' => '99999999-9', 'RutRecibe' => '', 'IdRespuesta' => 1];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ningun documento dirigido a 99999999-9');
        (new EnvioRecibosBuilder(new XmlSigner()))->generar($envio, $car, $cert, 'Av Siempre Viva 123', '99999999-9');
    }
}
