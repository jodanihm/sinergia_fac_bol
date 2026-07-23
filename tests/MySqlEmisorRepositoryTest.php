<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Exceptions\CertificadoNoEncontradoException;
use Plantiflex\FacturacionCl\Exceptions\DatosEmisorNoEncontradosException;
use Plantiflex\Integration\Facturacion\CertificadoCrypto;
use Plantiflex\Integration\Facturacion\MySqlEmisorRepository;

final class MySqlEmisorRepositoryTest extends TestCase
{
    private const RUT = '77724622-4';
    private const CERT_PEM = "-----BEGIN CERTIFICATE-----\nFAKE_CERT\n-----END CERTIFICATE-----\n";
    private const PKEY_PEM = "-----BEGIN PRIVATE KEY-----\nFAKE_PKEY\n-----END PRIVATE KEY-----\n";

    private PDO $pdo;
    private CertificadoCrypto $crypto;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE dte_certificado (
                id                INTEGER PRIMARY KEY AUTOINCREMENT,
                rut_emisor        TEXT    NOT NULL,
                ambiente          TEXT    NOT NULL CHECK (ambiente IN ('certificacion','produccion')),
                cert_data_cifrado TEXT    NOT NULL,
                pkey_data_cifrado TEXT    NOT NULL,
                created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (rut_emisor, ambiente)
            );
        SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE dte_emisor (
                id                INTEGER PRIMARY KEY AUTOINCREMENT,
                rut_emisor        TEXT    NOT NULL,
                ambiente          TEXT    NOT NULL CHECK (ambiente IN ('certificacion','produccion')),
                razon_social      TEXT    NOT NULL,
                giro              TEXT    NOT NULL,
                acteco            INTEGER NOT NULL,
                dir_origen        TEXT    NOT NULL,
                cmna_origen       TEXT    NOT NULL,
                resolucion_fecha  TEXT    NOT NULL,
                resolucion_numero INTEGER NOT NULL DEFAULT 0,
                UNIQUE (rut_emisor, ambiente)
            );
        SQL);

        $this->crypto = new CertificadoCrypto(str_repeat("\x07", 32));
    }

    private function insertarCertificado(string $rut, Ambiente $amb): void
    {
        $this->pdo->prepare(
            'INSERT INTO dte_certificado (rut_emisor, ambiente, cert_data_cifrado, pkey_data_cifrado) '
            . 'VALUES (:rut, :amb, :c, :p)'
        )->execute([
            ':rut' => $rut, ':amb' => $amb->value,
            ':c' => $this->crypto->cifrar(self::CERT_PEM),
            ':p' => $this->crypto->cifrar(self::PKEY_PEM),
        ]);
    }

    private function insertarDatosEmisor(string $rut, Ambiente $amb): void
    {
        $this->pdo->prepare(
            'INSERT INTO dte_emisor '
            . '(rut_emisor, ambiente, razon_social, giro, acteco, dir_origen, cmna_origen, resolucion_fecha, resolucion_numero) '
            . 'VALUES (:rut, :amb, :rs, :g, :a, :d, :c, :f, :n)'
        )->execute([
            ':rut' => $rut, ':amb' => $amb->value,
            ':rs' => 'Plantiflex SpA', ':g' => 'Venta de plantas', ':a' => 477310,
            ':d' => 'Av Siempre Viva 123', ':c' => 'Santiago',
            ':f' => '2024-01-01', ':n' => 0,
        ]);
    }

    public function testObtenerCertificadoDevuelveDatosDescifrados(): void
    {
        $this->insertarCertificado(self::RUT, Ambiente::Produccion);
        $repo = new MySqlEmisorRepository($this->pdo, $this->crypto);

        $cert = $repo->obtenerCertificado(self::RUT, Ambiente::Produccion);

        self::assertSame(self::CERT_PEM, $cert->certData);
        self::assertSame(self::PKEY_PEM, $cert->pkeyData);
    }

    public function testCertificadoInexistenteLanzaCertificadoNoEncontrado(): void
    {
        $repo = new MySqlEmisorRepository($this->pdo, $this->crypto);

        $this->expectException(CertificadoNoEncontradoException::class);
        $repo->obtenerCertificado(self::RUT, Ambiente::Produccion);
    }

    public function testObtenerDatosEmisorDevuelveDto(): void
    {
        $this->insertarDatosEmisor(self::RUT, Ambiente::Produccion);
        $repo = new MySqlEmisorRepository($this->pdo, $this->crypto);

        $datos = $repo->obtenerDatosEmisor(self::RUT, Ambiente::Produccion);

        self::assertSame(self::RUT, $datos->rutEmisor);
        self::assertSame('Plantiflex SpA', $datos->razonSocial);
        self::assertSame('Venta de plantas', $datos->giro);
        self::assertSame(477310, $datos->acteco);
        self::assertSame('Av Siempre Viva 123', $datos->dirOrigen);
        self::assertSame('Santiago', $datos->cmnaOrigen);
        self::assertSame('2024-01-01', $datos->resolucionFecha);
        self::assertSame(0, $datos->resolucionNumero);
    }

    public function testDatosEmisorInexistenteLanzaDatosEmisorNoEncontrados(): void
    {
        $repo = new MySqlEmisorRepository($this->pdo, $this->crypto);

        $this->expectException(DatosEmisorNoEncontradosException::class);
        $repo->obtenerDatosEmisor(self::RUT, Ambiente::Produccion);
    }

    public function testCertificadoYDatosViajanEnTablasSeparadas(): void
    {
        // Cargar solo certificado, NO datos.
        $this->insertarCertificado(self::RUT, Ambiente::Produccion);
        $repo = new MySqlEmisorRepository($this->pdo, $this->crypto);

        // El certificado se obtiene...
        $cert = $repo->obtenerCertificado(self::RUT, Ambiente::Produccion);
        self::assertSame(self::CERT_PEM, $cert->certData);

        // ...pero los datos no, y eso esta bien (tablas independientes).
        $this->expectException(DatosEmisorNoEncontradosException::class);
        $repo->obtenerDatosEmisor(self::RUT, Ambiente::Produccion);
    }

    public function testLoQueQuedaEnTablaCertificadoEstaCifrado(): void
    {
        $this->insertarCertificado(self::RUT, Ambiente::Produccion);

        $row = $this->pdo->query('SELECT cert_data_cifrado, pkey_data_cifrado FROM dte_certificado LIMIT 1')
            ->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        self::assertStringNotContainsString('FAKE_CERT', (string) $row['cert_data_cifrado']);
        self::assertStringNotContainsString('FAKE_PKEY', (string) $row['pkey_data_cifrado']);
    }
}
