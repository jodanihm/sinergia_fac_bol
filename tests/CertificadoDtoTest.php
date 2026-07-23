<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Dto\Certificado;
use Plantiflex\FacturacionCl\Exceptions\DocumentoInvalidoException;

final class CertificadoDtoTest extends TestCase
{
    private const CERT = "-----BEGIN CERTIFICATE-----\nMIIB...\n-----END CERTIFICATE-----\n";
    private const PKEY = "-----BEGIN PRIVATE KEY-----\nSUPER_SECRETO_QUE_NO_DEBE_LEAKEAR\n-----END PRIVATE KEY-----\n";

    public function testToAuthArrayArmaEstructuraParaApiGateway(): void
    {
        $cert = new Certificado(self::CERT, self::PKEY);

        $auth = $cert->toAuthArray();

        self::assertSame(
            [
                'cert' => [
                    'cert-data' => self::CERT,
                    'pkey-data' => self::PKEY,
                ],
            ],
            $auth,
        );
    }

    public function testDebugInfoOcultaLaLlavePrivada(): void
    {
        $cert = new Certificado(self::CERT, self::PKEY);

        $info = $cert->__debugInfo();

        self::assertSame('[REDACTED]', $info['pkeyData']);
        self::assertStringNotContainsString('SUPER_SECRETO_QUE_NO_DEBE_LEAKEAR', print_r($info, true));
        self::assertStringNotContainsString('SUPER_SECRETO_QUE_NO_DEBE_LEAKEAR', var_export($info, true));
    }

    public function testVarDumpDelObjetoNoExponeLaLlavePrivada(): void
    {
        $cert = new Certificado(self::CERT, self::PKEY);

        ob_start();
        var_dump($cert);
        $dump = (string) ob_get_clean();

        self::assertStringNotContainsString(
            'SUPER_SECRETO_QUE_NO_DEBE_LEAKEAR',
            $dump,
            'var_dump del Certificado JAMAS debe mostrar la llave privada',
        );
        self::assertStringContainsString('[REDACTED]', $dump);
    }

    public function testRechazaCertDataVacio(): void
    {
        $this->expectException(DocumentoInvalidoException::class);
        new Certificado('', self::PKEY);
    }

    public function testRechazaPkeyDataVacio(): void
    {
        $this->expectException(DocumentoInvalidoException::class);
        new Certificado(self::CERT, '');
    }
}
