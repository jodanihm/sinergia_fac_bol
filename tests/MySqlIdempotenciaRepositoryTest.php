<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\Integration\Facturacion\MySqlIdempotenciaRepository;

/**
 * Tests del MySqlIdempotenciaRepository contra SQLite en memoria.
 *
 * Cubren las dos garantias del repositorio:
 *
 *   1. AT-MOST-ONCE por clave: reclamar dos veces la misma clave para el mismo
 *      emisor devuelve false la segunda vez.
 *   2. AISLAMIENTO ENTRE TENANTS: la PK es (rut_emisor, ambiente, clave) desde
 *      la migracion 001. Dos emisores pueden usar la misma Idempotency-Key sin
 *      pisarse, y ninguna consulta puede alcanzar la fila de otro emisor.
 *
 * La garantia 2 es la que faltaba: hasta este arreglo, obtener(), completar() y
 * el DELETE de reactivarSiMuerto() filtraban solo por (ambiente, clave). Como
 * obtener() devuelve la respuesta guardada de una emision previa, un choque de
 * claves entre cuentas podia entregarle a un contribuyente el folio de otro.
 *
 * NO se cubre aqui el comportamiento de concurrencia real de MySQL (dos
 * procesos compitiendo por el mismo INSERT o el mismo DELETE): SQLite usa otro
 * modelo. Eso se valida en desechable contra MySQL, igual que hace
 * MySqlFolioRepositoryTest con SELECT ... FOR UPDATE.
 */
final class MySqlIdempotenciaRepositoryTest extends TestCase
{
    private const RUT_A = '78454034-0';
    private const RUT_B = '77724622-4';
    private const CLAVE = 'idem-key-compartida';

    private PDO $pdo;
    private MySqlIdempotenciaRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Schema adaptado a SQLite. La PK compuesta se preserva tal cual la
        // dejo la migracion 001: (rut_emisor, ambiente, clave).
        //
        // La columna "SECOND" es un SHIM, no existe en produccion: el
        // repositorio usa TIMESTAMPDIFF(SECOND, ...), sintaxis de MySQL donde
        // SECOND es una palabra clave. SQLite la parsea como identificador de
        // columna, asi que tiene que existir para que la consulta compile. El
        // valor nunca se lee: la funcion TIMESTAMPDIFF registrada abajo ignora
        // su primer argumento.
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE dte_idempotencia (
                rut_emisor     TEXT    NOT NULL,
                ambiente       TEXT    NOT NULL CHECK (ambiente IN ('certificacion','produccion')),
                clave          TEXT    NOT NULL,
                tipo_dte       INTEGER,
                folio          INTEGER,
                http_status    INTEGER,
                respuesta_json TEXT,
                created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                SECOND         TEXT,
                PRIMARY KEY (rut_emisor, ambiente, clave)
            );
        SQL);

        // CURRENT_TIMESTAMP de SQLite es UTC: NOW() devuelve UTC tambien para
        // que la resta quede en el mismo marco temporal.
        $this->pdo->sqliteCreateFunction('NOW', static fn (): string => gmdate('Y-m-d H:i:s'), 0);
        $this->pdo->sqliteCreateFunction(
            'TIMESTAMPDIFF',
            static fn ($unidad, $desde, $hasta): int => strtotime((string) $hasta) - strtotime((string) $desde),
            3
        );

        $this->repo = new MySqlIdempotenciaRepository($this->pdo);
    }

    /** @return list<array<string,mixed>> */
    private function filas(): array
    {
        return $this->pdo
            ->query('SELECT rut_emisor, ambiente, clave, folio, http_status, respuesta_json FROM dte_idempotencia ORDER BY rut_emisor')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    // -----------------------------------------------------------------------
    //  Garantia 1: at-most-once por clave
    // -----------------------------------------------------------------------

    public function testReclamarDosVecesLaMismaClaveDelMismoEmisorDevuelveFalse(): void
    {
        self::assertTrue($this->repo->reclamar(self::RUT_A, Ambiente::Produccion, self::CLAVE));
        self::assertFalse($this->repo->reclamar(self::RUT_A, Ambiente::Produccion, self::CLAVE));
        self::assertCount(1, $this->filas());
    }

    public function testLaMismaClaveEnAmbientesDistintosNoColisiona(): void
    {
        self::assertTrue($this->repo->reclamar(self::RUT_A, Ambiente::Certificacion, self::CLAVE));
        self::assertTrue($this->repo->reclamar(self::RUT_A, Ambiente::Produccion, self::CLAVE));
        self::assertCount(2, $this->filas());
    }

    // -----------------------------------------------------------------------
    //  Garantia 2: aislamiento entre tenants
    // -----------------------------------------------------------------------

    public function testLaMismaClaveEnDosEmisoresDistintosConviveSinColisionar(): void
    {
        self::assertTrue($this->repo->reclamar(self::RUT_A, Ambiente::Produccion, self::CLAVE));
        self::assertTrue(
            $this->repo->reclamar(self::RUT_B, Ambiente::Produccion, self::CLAVE),
            'El segundo emisor debe poder reclamar la misma Idempotency-Key.'
        );

        $filas = $this->filas();
        self::assertCount(2, $filas);
        self::assertSame([self::RUT_B, self::RUT_A], array_column($filas, 'rut_emisor'));
    }

    public function testCadaEmisorRecuperaSuPropioResultado(): void
    {
        $this->repo->reclamar(self::RUT_A, Ambiente::Produccion, self::CLAVE);
        $this->repo->reclamar(self::RUT_B, Ambiente::Produccion, self::CLAVE);

        $this->repo->completar(self::RUT_A, Ambiente::Produccion, self::CLAVE, 33, 111, '{"folio":111}', 201);
        $this->repo->completar(self::RUT_B, Ambiente::Produccion, self::CLAVE, 33, 222, '{"folio":222}', 201);

        $a = $this->repo->obtener(self::RUT_A, Ambiente::Produccion, self::CLAVE);
        $b = $this->repo->obtener(self::RUT_B, Ambiente::Produccion, self::CLAVE);

        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertSame(111, $a['folio']);
        self::assertSame(222, $b['folio']);
        self::assertSame('{"folio":111}', $a['respuestaJson']);
        self::assertSame('{"folio":222}', $b['respuestaJson']);
    }

    public function testObtenerNuncaDevuelveLaFilaDeOtroEmisor(): void
    {
        // Solo el emisor A tiene esta clave.
        $this->repo->reclamar(self::RUT_A, Ambiente::Produccion, self::CLAVE);
        $this->repo->completar(self::RUT_A, Ambiente::Produccion, self::CLAVE, 33, 999, '{"folio":999}', 201);

        // El emisor B pregunta por la MISMA clave: no es suya, debe ser null.
        self::assertNull(
            $this->repo->obtener(self::RUT_B, Ambiente::Produccion, self::CLAVE),
            'obtener() de un emisor no puede devolver la fila de otro.'
        );
    }

    public function testCompletarEscribeSoloSobreLaFilaDelEmisorCorrecto(): void
    {
        $this->repo->reclamar(self::RUT_A, Ambiente::Produccion, self::CLAVE);
        $this->repo->reclamar(self::RUT_B, Ambiente::Produccion, self::CLAVE);

        $this->repo->completar(self::RUT_A, Ambiente::Produccion, self::CLAVE, 33, 500, '{"folio":500}', 201);

        $b = $this->repo->obtener(self::RUT_B, Ambiente::Produccion, self::CLAVE);
        self::assertNotNull($b);
        self::assertNull($b['folio'], 'El claim del otro emisor debe seguir sin folio.');
        self::assertNull($b['respuestaJson']);
    }

    public function testReactivarSiMuertoNoTocaElClaimDeOtroEmisor(): void
    {
        // Ambos emisores tienen un claim viejo y sin folio con la misma clave.
        $viejo = gmdate('Y-m-d H:i:s', time() - 600);
        foreach ([self::RUT_A, self::RUT_B] as $rut) {
            $this->pdo->prepare(
                'INSERT INTO dte_idempotencia (rut_emisor, ambiente, clave, created_at) VALUES (?, ?, ?, ?)'
            )->execute([$rut, Ambiente::Produccion->value, self::CLAVE, $viejo]);
        }

        self::assertTrue($this->repo->reactivarSiMuerto(self::RUT_A, Ambiente::Produccion, self::CLAVE, 300));

        // El claim de B sigue existiendo y con su created_at viejo intacto.
        $edadB = $this->repo->obtener(self::RUT_B, Ambiente::Produccion, self::CLAVE);
        self::assertNotNull($edadB, 'El claim del otro emisor no debe borrarse.');
        self::assertGreaterThanOrEqual(600, $edadB['edad']);
        self::assertCount(2, $this->filas());
    }

    public function testReactivarSiMuertoNoReactivaUnClaimFresco(): void
    {
        $this->repo->reclamar(self::RUT_A, Ambiente::Produccion, self::CLAVE);

        self::assertFalse(
            $this->repo->reactivarSiMuerto(self::RUT_A, Ambiente::Produccion, self::CLAVE, 300),
            'Un claim dentro del TTL representa una emision en curso: no se reactiva.'
        );
    }

    public function testObtenerDevuelveNullSiLaClaveNoExiste(): void
    {
        self::assertNull($this->repo->obtener(self::RUT_A, Ambiente::Produccion, 'clave-inexistente'));
    }
}
