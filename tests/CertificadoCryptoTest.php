<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\TestCase;
use Plantiflex\Integration\Facturacion\CertificadoCrypto;
use Plantiflex\Integration\Facturacion\CertificadoCryptoException;

final class CertificadoCryptoTest extends TestCase
{
    private function llaveValida(): string
    {
        // 32 bytes determinista para tests, no para produccion.
        return str_repeat("\x01", CertificadoCrypto::KEY_LENGTH);
    }

    public function testRoundTripDevuelveElOriginal(): void
    {
        $crypto = new CertificadoCrypto($this->llaveValida());

        $plano = "-----BEGIN PRIVATE KEY-----\nMIIE...secreto\n-----END PRIVATE KEY-----\n";
        $cifrado = $crypto->cifrar($plano);
        $descifrado = $crypto->descifrar($cifrado);

        self::assertSame($plano, $descifrado);
    }

    public function testDosCifradosDelMismoTextoProducenBlobsDistintos(): void
    {
        $crypto = new CertificadoCrypto($this->llaveValida());

        $a = $crypto->cifrar('hola');
        $b = $crypto->cifrar('hola');

        self::assertNotSame($a, $b, 'El IV aleatorio debe hacer que cada cifrado sea distinto');
        self::assertSame('hola', $crypto->descifrar($a));
        self::assertSame('hola', $crypto->descifrar($b));
    }

    public function testBlobAlteradoLanzaExcepcionPorTagInvalido(): void
    {
        $crypto = new CertificadoCrypto($this->llaveValida());

        $cifrado = $crypto->cifrar('payload');

        // Alteramos un byte en el medio. Decodificamos base64, mutamos, re-codificamos.
        $bytes = base64_decode($cifrado, true);
        self::assertIsString($bytes);
        $idx = (int) (strlen($bytes) / 2);
        $bytes[$idx] = chr((ord($bytes[$idx]) ^ 0x01));
        $cifradoAlterado = base64_encode($bytes);

        $this->expectException(CertificadoCryptoException::class);
        $crypto->descifrar($cifradoAlterado);
    }

    public function testDescifrarConOtraLlaveLanzaExcepcion(): void
    {
        $a = new CertificadoCrypto(str_repeat("\x01", 32));
        $b = new CertificadoCrypto(str_repeat("\x02", 32));

        $cifrado = $a->cifrar('payload');

        $this->expectException(CertificadoCryptoException::class);
        $b->descifrar($cifrado);
    }

    public function testLlaveMaestraDeLargoIncorrectoLanzaExcepcionEnConstructor(): void
    {
        $this->expectException(CertificadoCryptoException::class);
        new CertificadoCrypto('llave-muy-corta');
    }

    public function testBlobTruncadoLanzaExcepcion(): void
    {
        $crypto = new CertificadoCrypto($this->llaveValida());

        $this->expectException(CertificadoCryptoException::class);
        $crypto->descifrar(base64_encode('xx'));
    }
}
