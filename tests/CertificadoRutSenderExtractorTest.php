<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\TestCase;
use Plantiflex\Integration\Facturacion\CertificadoRutSenderExtractor;

/**
 * Certificado real que motivo este fix: 13407848-0.pfx (sin RUT en el
 * serialNumber, solo en subjectAltName con el OID chileno de RUN).
 */
final class CertificadoRutSenderExtractorTest extends TestCase
{
    private const OID_RUT_CHILE = '1.3.6.1.4.1.8321.1';
    private const OID_SUBJECT_ALT_NAME = '2.5.29.17';

    public function testNoRegresionExtraePorSerialNumberCuandoNoHaySan(): void
    {
        $certPem = $this->certificadoConSerialNumber('13520634-2');

        self::assertSame('13520634-2', CertificadoRutSenderExtractor::extraer($certPem));
    }

    public function testFallbackExtraeDeSubjectAltNameCuandoNoHaySerialNumber(): void
    {
        // Mismo caso real que motivo la tarea: RUT SOLO en subjectAltName,
        // sin serialNumber en el subject -- el metodo 1 no encuentra nada,
        // debe caer al metodo 2 (parseo DER de la extension).
        $certPem = $this->certificadoConSanOtherName(self::OID_RUT_CHILE, '13407848-0');

        self::assertSame('13407848-0', CertificadoRutSenderExtractor::extraer($certPem));
    }

    public function testSanConOidDistintoNoSeConfundeConElDeRut(): void
    {
        // Un otherName con un OID DISTINTO al chileno de RUN no debe aceptarse
        // (aunque el valor "parezca" un RUT) -- prueba que el filtro por OID
        // realmente filtra, no solo toma el primer otherName que encuentre.
        $certPem = $this->certificadoConSanOtherName('1.2.3.4.5', '12345678-9');

        self::assertNull(CertificadoRutSenderExtractor::extraer($certPem));
    }

    public function testCertificadoSinNadaDevuelveNullSinLanzar(): void
    {
        $certPem = $this->certificadoConSerialNumber(''); // sin serialNumber real (DN vacio)

        self::assertNull(CertificadoRutSenderExtractor::extraer($certPem));
    }

    /**
     * Certificado autofirmado REAL (via ext-openssl, sin shell-out), con el
     * RUT en subject.serialNumber -- mismo patron ya usado en otros tests
     * del proyecto (ej. LibroXmlBuilderTest::certificadoAutoFirmado()).
     */
    private function certificadoConSerialNumber(string $serialNumber): string
    {
        $pkey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($pkey === false) {
            self::markTestSkipped('openssl_pkey_new fallo (falta openssl.cnf)');
        }

        $dn = ['commonName' => 'Test Firmante'];
        if ($serialNumber !== '') {
            $dn['serialNumber'] = $serialNumber;
        }

        $csr  = openssl_csr_new($dn, $pkey, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $pkey, 1, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $certPem);

        return $certPem;
    }

    /**
     * Certificado CON una extension subjectAltName conteniendo un otherName
     * ($oid, $valor), construido a mano byte a byte (DER puro, sin llamar a
     * ninguna funcion de ext-openssl ni a un binario externo): ext-openssl
     * NO permite embeber un otherName de OID arbitrario de forma confiable
     * via openssl_csr_new(), asi que se escribe el DER directo -- el resto
     * de los campos de tbsCertificate son placeholders opacos (el parser
     * bajo prueba los ignora, solo busca el campo [3] Extensions).
     */
    private function certificadoConSanOtherName(string $oid, string $valor): string
    {
        $oidBytes      = $this->derTlv(0x06, $this->derOid($oid));
        $valorExplicit = $this->derTlv(0xA0, $this->derTlv(0x0C, $valor)); // [0] EXPLICIT UTF8String
        $otherNameContenido = $oidBytes . $valorExplicit;
        $generalName   = chr(0xA0) . $this->derLongitud(strlen($otherNameContenido)) . $otherNameContenido; // [0] IMPLICIT OtherName
        $generalNames  = $this->derTlv(0x30, $generalName); // GeneralNames ::= SEQUENCE OF GeneralName

        $sanOid   = $this->derTlv(0x06, $this->derOid(self::OID_SUBJECT_ALT_NAME));
        $sanValor = $this->derTlv(0x04, $generalNames); // extnValue OCTET STRING
        $extension  = $this->derTlv(0x30, $sanOid . $sanValor);
        $extensions = $this->derTlv(0x30, $extension);
        $extensionesExplicit = chr(0xA3) . $this->derLongitud(strlen($extensions)) . $extensions; // [3] EXPLICIT

        $serialNumber   = $this->derTlv(0x02, chr(1)); // INTEGER 1 (placeholder)
        $dummySeqVacia  = $this->derTlv(0x30, '');     // placeholder para signature/issuer/validity/subject/spki
        $tbsCertificate = $this->derTlv(
            0x30,
            $serialNumber . $dummySeqVacia . $dummySeqVacia . $dummySeqVacia . $dummySeqVacia . $extensionesExplicit
        );

        $signatureAlgorithm = $this->derTlv(0x30, '');
        $signatureValue     = $this->derTlv(0x03, chr(0)); // BIT STRING (placeholder)
        $certificate        = $this->derTlv(0x30, $tbsCertificate . $signatureAlgorithm . $signatureValue);

        return "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($certificate), 64, "\n") . "-----END CERTIFICATE-----\n";
    }

    private function derTlv(int $tag, string $contenido): string
    {
        return chr($tag) . $this->derLongitud(strlen($contenido)) . $contenido;
    }

    private function derLongitud(int $longitud): string
    {
        if ($longitud < 0x80) {
            return chr($longitud);
        }
        $bytes = '';
        while ($longitud > 0) {
            $bytes = chr($longitud & 0xFF) . $bytes;
            $longitud >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private function derOid(string $oid): string
    {
        $arcos      = array_map('intval', explode('.', $oid));
        $primerByte = 40 * $arcos[0] + $arcos[1];
        $bytes      = chr($primerByte);
        for ($i = 2; $i < count($arcos); $i++) {
            $bytes .= $this->derOidArco($arcos[$i]);
        }
        return $bytes;
    }

    private function derOidArco(int $valor): string
    {
        if ($valor === 0) {
            return chr(0);
        }
        $grupos = [];
        while ($valor > 0) {
            $grupos[] = $valor & 0x7F;
            $valor >>= 7;
        }
        $grupos = array_reverse($grupos);
        $out    = '';
        foreach ($grupos as $i => $g) {
            $out .= chr($i < count($grupos) - 1 ? ($g | 0x80) : $g);
        }
        return $out;
    }
}
