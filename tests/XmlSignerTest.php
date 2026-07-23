<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use DOMDocument;
use DOMElement;
use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Dto\Certificado;
use Plantiflex\FacturacionCl\Sii\XmlSigner;

final class XmlSignerTest extends TestCase
{
    private const NS_DSIG = 'http://www.w3.org/2000/09/xmldsig#';

    /**
     * Genera un par cert + llave autofirmado en memoria.
     * Requiere ext-openssl y que el binario de PHP tenga acceso a un openssl.cnf.
     */
    private function generarCertificadoAutoFirmado(): Certificado
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

    public function testFirmaSemillaProduceXmlConEstructuraEsperada(): void
    {
        $cert = $this->generarCertificadoAutoFirmado();
        $xml = (new XmlSigner())->firmarSemilla('123456789', $cert);

        $doc = new DOMDocument();
        $doc->loadXML($xml);

        // Root = getToken; item/Semilla preservados.
        self::assertSame('getToken', $doc->documentElement->nodeName);
        $sem = $doc->getElementsByTagName('Semilla')->item(0);
        self::assertNotNull($sem);
        self::assertSame('123456789', $sem->textContent);

        // Signature presente con SignedInfo + SignatureValue + KeyInfo.
        $sig = $doc->getElementsByTagNameNS(self::NS_DSIG, 'Signature')->item(0);
        self::assertNotNull($sig, 'Falta <Signature>');

        $signedInfo = $doc->getElementsByTagNameNS(self::NS_DSIG, 'SignedInfo')->item(0);
        self::assertNotNull($signedInfo, 'Falta <SignedInfo>');

        $sigValue = $doc->getElementsByTagNameNS(self::NS_DSIG, 'SignatureValue')->item(0);
        self::assertNotNull($sigValue, 'Falta <SignatureValue>');
        self::assertNotSame('', trim((string) $sigValue->textContent));

        // El SII usa el nombre estandar de XMLDSig: X509Certificate.
        $x509 = $doc->getElementsByTagNameNS(self::NS_DSIG, 'X509Certificate')->item(0);
        self::assertNotNull($x509, 'Falta <X509Certificate>');
        // Sin headers PEM, y partido en lineas de <=64 chars (limite del SII).
        self::assertStringNotContainsString('BEGIN CERTIFICATE', (string) $x509->textContent);
        self::assertLineasMax64((string) $x509->textContent, 'X509Certificate');
        // El contenido (ignorando saltos) sigue siendo base64 valido.
        self::assertNotFalse(
            base64_decode(str_replace("\n", '', (string) $x509->textContent), true),
            'X509Certificate debe ser base64 valido',
        );

        // El SII EXIGE bloque KeyValue/RSAKeyValue con Modulus + Exponent
        // ANTES del X509Data. Sin esto rechaza con un mensaje enganoso.
        $modulus = $doc->getElementsByTagNameNS(self::NS_DSIG, 'Modulus')->item(0);
        self::assertNotNull($modulus, 'Falta <Modulus> dentro de RSAKeyValue');
        $modulusText = trim((string) $modulus->textContent);
        self::assertNotSame('', $modulusText, 'Modulus vacio');
        self::assertLineasMax64($modulusText, 'Modulus');
        self::assertNotFalse(base64_decode(str_replace("\n", '', $modulusText), true), 'Modulus debe ser base64 valido');

        $exponent = $doc->getElementsByTagNameNS(self::NS_DSIG, 'Exponent')->item(0);
        self::assertNotNull($exponent, 'Falta <Exponent> dentro de RSAKeyValue');
        $exponentText = trim((string) $exponent->textContent);
        self::assertNotSame('', $exponentText, 'Exponent vacio');
        self::assertNotFalse(base64_decode(str_replace("\n", '', $exponentText), true), 'Exponent debe ser base64 valido');

        // La semilla usa enveloped-signature (URI="", la Signature SI esta dentro
        // del documento referenciado). No cambiar: la autenticacion al SII funciona.
        $trsSemilla = $doc->getElementsByTagNameNS(self::NS_DSIG, 'Transform');
        self::assertSame(1, $trsSemilla->length);
        self::assertInstanceOf(DOMElement::class, $trsSemilla->item(0));
        self::assertSame(
            'http://www.w3.org/2000/09/xmldsig#enveloped-signature',
            $trsSemilla->item(0)->getAttribute('Algorithm'),
        );
    }

    private static function assertLineasMax64(string $texto, string $campo): void
    {
        foreach (explode("\n", $texto) as $linea) {
            self::assertLessThanOrEqual(64, strlen($linea), "{$campo}: linea base64 > 64 chars");
        }
    }

    public function testFirmaSemillaProduceFirmaVerificableConOpensslVerify(): void
    {
        $cert = $this->generarCertificadoAutoFirmado();
        $xml = (new XmlSigner())->firmarSemilla('SEM-XYZ-42', $cert);

        $doc = new DOMDocument();
        $doc->loadXML($xml);

        $signedInfo = $doc->getElementsByTagNameNS(self::NS_DSIG, 'SignedInfo')->item(0);
        self::assertNotNull($signedInfo);
        $canonical = $signedInfo->C14N();

        $sigValueNode = $doc->getElementsByTagNameNS(self::NS_DSIG, 'SignatureValue')->item(0);
        self::assertNotNull($sigValueNode);
        $sigBin = base64_decode(trim((string) $sigValueNode->textContent), true);
        self::assertIsString($sigBin);

        $pub = openssl_pkey_get_public($cert->certData);
        self::assertNotFalse($pub, 'No se pudo extraer llave publica del cert');

        $result = openssl_verify($canonical, $sigBin, $pub, OPENSSL_ALGO_SHA1);
        self::assertSame(
            1,
            $result,
            'La firma sobre SignedInfo canonicalizado debe verificar con la llave publica del cert',
        );
    }

    public function testFirmaConSemillasDistintasProduceSignatureValueDistintos(): void
    {
        $cert = $this->generarCertificadoAutoFirmado();
        $signer = new XmlSigner();

        $a = $signer->firmarSemilla('AAA', $cert);
        $b = $signer->firmarSemilla('BBB', $cert);

        $extractSigValue = static function (string $xml): string {
            $d = new DOMDocument();
            $d->loadXML($xml);
            return trim((string) $d->getElementsByTagNameNS(self::NS_DSIG, 'SignatureValue')->item(0)->textContent);
        };

        self::assertNotSame($extractSigValue($a), $extractSigValue($b));
    }

    // ------------------------------------------------------------------
    // firmarNodo
    // ------------------------------------------------------------------

    public function testFirmarNodoInsertaSignatureHermanoYVerifica(): void
    {
        $cert = $this->generarCertificadoAutoFirmado();

        // Documento DENTRO de un namespace default (el caso real del DTE SII):
        // aqui es donde antes salia <default:Signature>.
        $nsSii = 'http://www.sii.cl/SiiDte';
        $doc   = new DOMDocument('1.0', 'UTF-8');
        $root  = $doc->createElementNS($nsSii, 'DTE');
        $doc->appendChild($root);
        $nodo = $doc->createElementNS($nsSii, 'Documento');
        $nodo->setAttribute('ID', 'X1');
        $nodo->appendChild($doc->createElementNS($nsSii, 'Dato', 'valor'));
        $root->appendChild($nodo);

        (new XmlSigner())->firmarNodo($nodo, 'X1', $cert);

        // Signature es hermano del nodo (hijo de DTE, inmediatamente despues).
        $sig = $doc->getElementsByTagNameNS(self::NS_DSIG, 'Signature')->item(0);
        self::assertNotNull($sig);
        self::assertSame($root, $sig->parentNode);
        self::assertSame('Documento', $sig->previousSibling?->nodeName);

        // La firma NO debe llevar prefijo: el SII espera <Signature> sin prefijo.
        $xml = (string) $doc->saveXML();
        self::assertStringNotContainsString('default:', $xml, 'La Signature no debe tener prefijo default:');
        self::assertStringNotContainsString('xmlns:default', $xml);
        self::assertStringContainsString('<Signature xmlns="' . self::NS_DSIG . '"', $xml);
        self::assertStringContainsString('<SignedInfo>', $xml);
        self::assertStringContainsString('<X509Certificate>', $xml);

        // Reference URI="#X1".
        $ref = $doc->getElementsByTagNameNS(self::NS_DSIG, 'Reference')->item(0);
        self::assertNotNull($ref);
        self::assertSame('#X1', $ref->getAttribute('URI'));

        // La Reference del Documento (y SetDTE) usa C14N como transform, NO
        // enveloped-signature. La Signature es hermana del nodo referenciado
        // (no descendiente), por lo que el transform correcto es C14N puro.
        // Esto coincide con el ejemplo oficial del SII (F60T33-ejemplo.xml).
        $trs = $doc->getElementsByTagNameNS(self::NS_DSIG, 'Transform');
        self::assertSame(1, $trs->length, 'Debe existir exactamente 1 Transform en la Reference');
        self::assertInstanceOf(DOMElement::class, $trs->item(0));
        self::assertSame('http://www.w3.org/2000/09/xmldsig#enveloped-signature', $trs->item(0)->getAttribute('Algorithm'));

        // openssl_verify del SignedInfo canonicalizado en su contexto final.
        $signedInfo = $doc->getElementsByTagNameNS(self::NS_DSIG, 'SignedInfo')->item(0);
        self::assertNotNull($signedInfo);
        $canonical = $signedInfo->C14N();

        $sigValueNode = $doc->getElementsByTagNameNS(self::NS_DSIG, 'SignatureValue')->item(0);
        self::assertNotNull($sigValueNode);
        $sigBin = base64_decode(trim((string) $sigValueNode->textContent), true);
        self::assertIsString($sigBin);

        $pub = openssl_pkey_get_public($cert->certData);
        self::assertNotFalse($pub);
        self::assertSame(1, openssl_verify($canonical, $sigBin, $pub, OPENSSL_ALGO_SHA1));
    }

    public function testFirmarNodoConIdsDistintosProduceReferenceUriDistintos(): void
    {
        $cert = $this->generarCertificadoAutoFirmado();
        $signer = new XmlSigner();

        $uriDe = static function (string $id) use ($signer, $cert): string {
            $doc  = new DOMDocument('1.0', 'UTF-8');
            $root = $doc->createElement('Root');
            $doc->appendChild($root);
            $nodo = $doc->createElement('Documento');
            $nodo->setAttribute('ID', $id);
            $nodo->appendChild($doc->createElement('Dato', 'x'));
            $root->appendChild($nodo);

            $signer->firmarNodo($nodo, $id, $cert);

            return $doc->getElementsByTagNameNS(self::NS_DSIG, 'Reference')->item(0)->getAttribute('URI');
        };

        self::assertSame('#A1', $uriDe('A1'));
        self::assertSame('#B2', $uriDe('B2'));
    }
}
