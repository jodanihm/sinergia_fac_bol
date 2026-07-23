<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Exceptions\FoliosAgotadosException;

final class FolioRepositoryContractTest extends TestCase
{
    private const RUT_A = '77724622-4';
    private const RUT_B = '76543210-K';

    private InMemoryFolioRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new InMemoryFolioRepository();
    }

    public function testAsignacionEsCorrelativaDesdeElFolioDesde(): void
    {
        $this->repo->cargarCaf(self::RUT_A, TipoDte::BoletaElectronica, Ambiente::Produccion, 1, 10, '<CAF/>');

        $f1 = $this->repo->asignarSiguienteFolio(self::RUT_A, TipoDte::BoletaElectronica, Ambiente::Produccion);
        $f2 = $this->repo->asignarSiguienteFolio(self::RUT_A, TipoDte::BoletaElectronica, Ambiente::Produccion);
        $f3 = $this->repo->asignarSiguienteFolio(self::RUT_A, TipoDte::BoletaElectronica, Ambiente::Produccion);

        self::assertSame(1, $f1);
        self::assertSame(2, $f2);
        self::assertSame(3, $f3);
    }

    public function testNoRepiteFoliosEnCienAsignaciones(): void
    {
        $this->repo->cargarCaf(self::RUT_A, TipoDte::FacturaElectronica, Ambiente::Certificacion, 1, 100, '<CAF/>');

        $vistos = [];
        for ($i = 0; $i < 100; $i++) {
            $folio = $this->repo->asignarSiguienteFolio(
                self::RUT_A,
                TipoDte::FacturaElectronica,
                Ambiente::Certificacion,
            );
            self::assertArrayNotHasKey($folio, $vistos, "Folio $folio se repitio");
            $vistos[$folio] = true;
        }
        self::assertCount(100, $vistos);
        self::assertSame(range(1, 100), array_keys($vistos));
    }

    public function testAgotamientoLanzaFoliosAgotadosException(): void
    {
        $this->repo->cargarCaf(self::RUT_A, TipoDte::BoletaElectronica, Ambiente::Produccion, 1, 2, '<CAF/>');

        self::assertSame(1, $this->repo->asignarSiguienteFolio(self::RUT_A, TipoDte::BoletaElectronica, Ambiente::Produccion));
        self::assertSame(2, $this->repo->asignarSiguienteFolio(self::RUT_A, TipoDte::BoletaElectronica, Ambiente::Produccion));

        $this->expectException(FoliosAgotadosException::class);
        $this->repo->asignarSiguienteFolio(self::RUT_A, TipoDte::BoletaElectronica, Ambiente::Produccion);
    }

    public function testEnlazaMultiplesCafsEnOrdenAscendente(): void
    {
        // Cargados en orden inverso a proposito: la implementacion debe ordenar
        // por folio_desde, no por orden de carga.
        $this->repo->cargarCaf(self::RUT_A, TipoDte::BoletaElectronica, Ambiente::Produccion, 3, 5, '<CAF B/>');
        $this->repo->cargarCaf(self::RUT_A, TipoDte::BoletaElectronica, Ambiente::Produccion, 1, 2, '<CAF A/>');

        $secuencia = [];
        for ($i = 0; $i < 5; $i++) {
            $secuencia[] = $this->repo->asignarSiguienteFolio(self::RUT_A, TipoDte::BoletaElectronica, Ambiente::Produccion);
        }

        self::assertSame([1, 2, 3, 4, 5], $secuencia);

        $this->expectException(FoliosAgotadosException::class);
        $this->repo->asignarSiguienteFolio(self::RUT_A, TipoDte::BoletaElectronica, Ambiente::Produccion);
    }

    public function testMultiTenantNoCompartenFolios(): void
    {
        $this->repo->cargarCaf(self::RUT_A, TipoDte::BoletaElectronica, Ambiente::Produccion, 1, 5, '<CAF A/>');
        $this->repo->cargarCaf(self::RUT_B, TipoDte::BoletaElectronica, Ambiente::Produccion, 1, 5, '<CAF B/>');

        $folioA1 = $this->repo->asignarSiguienteFolio(self::RUT_A, TipoDte::BoletaElectronica, Ambiente::Produccion);
        $folioB1 = $this->repo->asignarSiguienteFolio(self::RUT_B, TipoDte::BoletaElectronica, Ambiente::Produccion);
        $folioA2 = $this->repo->asignarSiguienteFolio(self::RUT_A, TipoDte::BoletaElectronica, Ambiente::Produccion);

        self::assertSame(1, $folioA1);
        self::assertSame(1, $folioB1, 'Cada emisor lleva su propia secuencia');
        self::assertSame(2, $folioA2);

        self::assertSame('<CAF A/>', $this->repo->obtenerCafActivo(self::RUT_A, TipoDte::BoletaElectronica, Ambiente::Produccion));
        self::assertSame('<CAF B/>', $this->repo->obtenerCafActivo(self::RUT_B, TipoDte::BoletaElectronica, Ambiente::Produccion));
    }

    public function testCertificacionYProduccionNoSeMezclan(): void
    {
        $this->repo->cargarCaf(self::RUT_A, TipoDte::BoletaElectronica, Ambiente::Certificacion, 1, 3, '<CAF CERT/>');
        $this->repo->cargarCaf(self::RUT_A, TipoDte::BoletaElectronica, Ambiente::Produccion,    1, 3, '<CAF PROD/>');

        self::assertSame(1, $this->repo->asignarSiguienteFolio(self::RUT_A, TipoDte::BoletaElectronica, Ambiente::Certificacion));
        self::assertSame(1, $this->repo->asignarSiguienteFolio(self::RUT_A, TipoDte::BoletaElectronica, Ambiente::Produccion));
        self::assertSame(2, $this->repo->asignarSiguienteFolio(self::RUT_A, TipoDte::BoletaElectronica, Ambiente::Certificacion));
    }

    public function testMarcarFolioComoUsadoRegistraResultado(): void
    {
        $this->repo->cargarCaf(self::RUT_A, TipoDte::BoletaElectronica, Ambiente::Produccion, 1, 3, '<CAF/>');

        $f1 = $this->repo->asignarSiguienteFolio(self::RUT_A, TipoDte::BoletaElectronica, Ambiente::Produccion);
        $f2 = $this->repo->asignarSiguienteFolio(self::RUT_A, TipoDte::BoletaElectronica, Ambiente::Produccion);

        $this->repo->marcarFolioComoUsado(self::RUT_A, TipoDte::BoletaElectronica, Ambiente::Produccion, $f1, true);
        $this->repo->marcarFolioComoUsado(self::RUT_A, TipoDte::BoletaElectronica, Ambiente::Produccion, $f2, false);

        $log = $this->repo->log();
        self::assertCount(2, $log);
        self::assertSame($f1, $log[0]['folio']);
        self::assertTrue($log[0]['exitosa']);
        self::assertSame($f2, $log[1]['folio']);
        self::assertFalse($log[1]['exitosa']);
    }

    public function testObtenerCafActivoSinCafLanzaFoliosAgotados(): void
    {
        $this->expectException(FoliosAgotadosException::class);
        $this->repo->obtenerCafActivo(self::RUT_A, TipoDte::BoletaElectronica, Ambiente::Produccion);
    }

    public function testObtenerCafActivoTrasAgotarseLanzaFoliosAgotados(): void
    {
        $this->repo->cargarCaf(self::RUT_A, TipoDte::BoletaElectronica, Ambiente::Produccion, 1, 1, '<CAF/>');
        $this->repo->asignarSiguienteFolio(self::RUT_A, TipoDte::BoletaElectronica, Ambiente::Produccion);

        $this->expectException(FoliosAgotadosException::class);
        $this->repo->obtenerCafActivo(self::RUT_A, TipoDte::BoletaElectronica, Ambiente::Produccion);
    }
}
