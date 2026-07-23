<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Exceptions\FoliosAgotadosException;
use Plantiflex\Integration\Facturacion\MySqlFolioRepository;

/**
 * Tests del MySqlFolioRepository contra SQLite en memoria.
 *
 * Estos tests validan la LOGICA del repositorio (numeracion correlativa,
 * cambio de CAF al agotarse, descifrado del CAF, filtros por ambiente,
 * rollback ante error). NO validan el comportamiento exacto de locking de
 * MySQL (SELECT ... FOR UPDATE), porque SQLite no implementa esa clausula
 * y usa un modelo de concurrencia distinto. Ese aspecto se valida en
 * staging contra MySQL real.
 */
final class MySqlFolioRepositoryTest extends TestCase
{
    private const RUT = '77724622-4';
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        // Schema adaptado a SQLite (los tipos cambian, la logica de
        // restricciones UNIQUE / FK / CHECK se preserva).
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE dte_caf (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                rut_emisor      TEXT    NOT NULL,
                tipo_dte        INTEGER NOT NULL,
                ambiente        TEXT    NOT NULL CHECK (ambiente IN ('certificacion','produccion')),
                folio_desde     INTEGER NOT NULL,
                folio_hasta     INTEGER NOT NULL,
                caf_xml_cifrado TEXT    NOT NULL,
                estado          TEXT    NOT NULL DEFAULT 'activo' CHECK (estado IN ('activo','agotado')),
                created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (rut_emisor, tipo_dte, ambiente, folio_desde, folio_hasta),
                CHECK (folio_hasta >= folio_desde)
            );
        SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE dte_folio (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                caf_id         INTEGER NOT NULL,
                rut_emisor     TEXT    NOT NULL,
                tipo_dte       INTEGER NOT NULL,
                ambiente       TEXT    NOT NULL CHECK (ambiente IN ('certificacion','produccion')),
                proximo_folio  INTEGER NOT NULL,
                folio_hasta    INTEGER NOT NULL,
                UNIQUE (caf_id),
                FOREIGN KEY (caf_id) REFERENCES dte_caf (id) ON DELETE CASCADE
            );
        SQL);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE dte_folio_log (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                rut_emisor      TEXT    NOT NULL,
                tipo_dte        INTEGER NOT NULL,
                ambiente        TEXT    NOT NULL CHECK (ambiente IN ('certificacion','produccion')),
                folio           INTEGER NOT NULL,
                emision_exitosa INTEGER NULL,
                asignado_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (rut_emisor, tipo_dte, ambiente, folio)
            );
        SQL);
    }

    private function cargarCaf(
        string $rut,
        TipoDte $tipo,
        Ambiente $amb,
        int $desde,
        int $hasta,
        string $xmlCifrado,
    ): int {
        $this->pdo->prepare(
            'INSERT INTO dte_caf (rut_emisor, tipo_dte, ambiente, folio_desde, folio_hasta, caf_xml_cifrado) '
            . 'VALUES (:rut, :tipo, :amb, :d, :h, :xml)'
        )->execute([
            ':rut' => $rut, ':tipo' => $tipo->value, ':amb' => $amb->value,
            ':d' => $desde, ':h' => $hasta, ':xml' => $xmlCifrado,
        ]);
        $cafId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO dte_folio (caf_id, rut_emisor, tipo_dte, ambiente, proximo_folio, folio_hasta) '
            . 'VALUES (:caf, :rut, :tipo, :amb, :prox, :h)'
        )->execute([
            ':caf' => $cafId, ':rut' => $rut, ':tipo' => $tipo->value, ':amb' => $amb->value,
            ':prox' => $desde, ':h' => $hasta,
        ]);

        return $cafId;
    }

    private function proximoFolio(int $cafId): int
    {
        $s = $this->pdo->prepare('SELECT proximo_folio FROM dte_folio WHERE caf_id = :id');
        $s->execute([':id' => $cafId]);
        return (int) $s->fetchColumn();
    }

    private function estadoCaf(int $cafId): string
    {
        $s = $this->pdo->prepare('SELECT estado FROM dte_caf WHERE id = :id');
        $s->execute([':id' => $cafId]);
        return (string) $s->fetchColumn();
    }

    // ----------------------------------------------------------------------

    public function testAsignacionCorrelativaContraBD(): void
    {
        $this->cargarCaf(self::RUT, TipoDte::BoletaElectronica, Ambiente::Produccion, 1, 5, '<CAF/>');
        $repo = new MySqlFolioRepository($this->pdo);

        $secuencia = [];
        for ($i = 0; $i < 5; $i++) {
            $secuencia[] = $repo->asignarSiguienteFolio(self::RUT, TipoDte::BoletaElectronica, Ambiente::Produccion);
        }
        self::assertSame([1, 2, 3, 4, 5], $secuencia);

        // Y el log refleja las 5 asignaciones con resultado pendiente.
        $logCount = (int) $this->pdo->query("SELECT COUNT(*) FROM dte_folio_log WHERE emision_exitosa IS NULL")->fetchColumn();
        self::assertSame(5, $logCount);
    }

    public function testSaltaAlSiguienteCafCuandoElPrimeroSeAgota(): void
    {
        $caf1 = $this->cargarCaf(self::RUT, TipoDte::BoletaElectronica, Ambiente::Produccion, 1, 2, '<CAF A/>');
        $caf2 = $this->cargarCaf(self::RUT, TipoDte::BoletaElectronica, Ambiente::Produccion, 3, 5, '<CAF B/>');
        $repo = new MySqlFolioRepository($this->pdo);

        $secuencia = [];
        for ($i = 0; $i < 5; $i++) {
            $secuencia[] = $repo->asignarSiguienteFolio(self::RUT, TipoDte::BoletaElectronica, Ambiente::Produccion);
        }
        self::assertSame([1, 2, 3, 4, 5], $secuencia);

        self::assertSame('agotado', $this->estadoCaf($caf1));
        self::assertSame('agotado', $this->estadoCaf($caf2));

        $this->expectException(FoliosAgotadosException::class);
        $repo->asignarSiguienteFolio(self::RUT, TipoDte::BoletaElectronica, Ambiente::Produccion);
    }

    public function testObtenerCafActivoDevuelveElXmlYAplicaClosureDeDescifrado(): void
    {
        $xmlPlano = '<CAF>contenido secreto</CAF>';
        $cifrado  = base64_encode($xmlPlano);

        $this->cargarCaf(self::RUT, TipoDte::FacturaElectronica, Ambiente::Certificacion, 1, 5, $cifrado);

        $descifrarLlamadoCon = null;
        $descifrar = function (string $c) use (&$descifrarLlamadoCon): string {
            $descifrarLlamadoCon = $c;
            return base64_decode($c, true);
        };

        $repo = new MySqlFolioRepository($this->pdo, $descifrar);
        $resultado = $repo->obtenerCafActivo(self::RUT, TipoDte::FacturaElectronica, Ambiente::Certificacion);

        self::assertSame($xmlPlano, $resultado, 'El CAF debe llegar descifrado al caller');
        self::assertSame($cifrado, $descifrarLlamadoCon, 'descifrarCaf debe pasar el blob cifrado a la closure');
    }

    public function testDescifrarCafSinClosureDevuelveElContenidoTalCual(): void
    {
        $this->cargarCaf(self::RUT, TipoDte::BoletaElectronica, Ambiente::Produccion, 1, 1, 'TEXTO_EN_CLARO');
        $repo = new MySqlFolioRepository($this->pdo); // sin closure

        self::assertSame(
            'TEXTO_EN_CLARO',
            $repo->obtenerCafActivo(self::RUT, TipoDte::BoletaElectronica, Ambiente::Produccion),
        );
    }

    public function testObtenerCafActivoSinCafLanzaFoliosAgotados(): void
    {
        $repo = new MySqlFolioRepository($this->pdo);
        $this->expectException(FoliosAgotadosException::class);
        $repo->obtenerCafActivo(self::RUT, TipoDte::BoletaElectronica, Ambiente::Produccion);
    }

    public function testMarcarFolioComoUsadoActualizaRegistroFiltrandoPorAmbiente(): void
    {
        // Mismo emisor, mismo tipo, mismo folio (1) en certificacion Y produccion.
        $this->cargarCaf(self::RUT, TipoDte::BoletaElectronica, Ambiente::Certificacion, 1, 5, '<CAF C/>');
        $this->cargarCaf(self::RUT, TipoDte::BoletaElectronica, Ambiente::Produccion,    1, 5, '<CAF P/>');
        $repo = new MySqlFolioRepository($this->pdo);

        $repo->asignarSiguienteFolio(self::RUT, TipoDte::BoletaElectronica, Ambiente::Certificacion); // folio 1 cert
        $repo->asignarSiguienteFolio(self::RUT, TipoDte::BoletaElectronica, Ambiente::Produccion);    // folio 1 prod

        // Solo marca el folio 1 de PRODUCCION como rechazado.
        $repo->marcarFolioComoUsado(self::RUT, TipoDte::BoletaElectronica, Ambiente::Produccion, 1, false);

        $s = $this->pdo->prepare(
            'SELECT ambiente, emision_exitosa FROM dte_folio_log '
            . 'WHERE rut_emisor = :rut AND tipo_dte = :tipo AND folio = 1 '
            . 'ORDER BY ambiente'
        );
        $s->execute([':rut' => self::RUT, ':tipo' => TipoDte::BoletaElectronica->value]);
        $filas = $s->fetchAll(PDO::FETCH_ASSOC);

        self::assertCount(2, $filas);
        self::assertSame('certificacion', $filas[0]['ambiente']);
        self::assertNull($filas[0]['emision_exitosa'], 'Certificacion NO debe haberse tocado');
        self::assertSame('produccion', $filas[1]['ambiente']);
        self::assertSame(0, (int) $filas[1]['emision_exitosa'], 'Produccion debe quedar marcado false (0)');
    }

    public function testRollbackSiFallaInsercionDeLogNoIncrementaProximoFolio(): void
    {
        $cafId = $this->cargarCaf(self::RUT, TipoDte::BoletaElectronica, Ambiente::Produccion, 1, 5, '<CAF/>');

        // Sembramos un registro previo en el log con folio=1 para forzar
        // violacion UNIQUE cuando asignarSiguienteFolio intente insertar.
        $this->pdo->prepare(
            'INSERT INTO dte_folio_log (rut_emisor, tipo_dte, ambiente, folio, emision_exitosa) '
            . 'VALUES (:rut, :tipo, :amb, 1, NULL)'
        )->execute([
            ':rut' => self::RUT,
            ':tipo' => TipoDte::BoletaElectronica->value,
            ':amb' => Ambiente::Produccion->value,
        ]);

        $repo = new MySqlFolioRepository($this->pdo);

        try {
            $repo->asignarSiguienteFolio(self::RUT, TipoDte::BoletaElectronica, Ambiente::Produccion);
            self::fail('Se esperaba PDOException por violacion UNIQUE en dte_folio_log');
        } catch (PDOException $e) {
            // OK
        }

        self::assertSame(
            1,
            $this->proximoFolio($cafId),
            'Tras el rollback, proximo_folio debe seguir en 1 (no incrementado)',
        );
        self::assertFalse($this->pdo->inTransaction(), 'La transaccion debe haberse cerrado por rollback');
    }
}
