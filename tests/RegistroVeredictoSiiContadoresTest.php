<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Dto\EstadisticaEnvioSii;
use Plantiflex\FacturacionCl\Sii\RegistroVeredictoSii;

/**
 * La persistencia de los contadores, contra SQLite en memoria.
 *
 * Lo que se vigila aqui es la GRANULARIDAD: el estado es del sobre y los
 * contadores son de (sobre, tipo). Un solo UPDATE para todo escribiria los
 * numeros del ultimo bloque en las filas de todos los tipos.
 */
final class RegistroVeredictoSiiContadoresTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec(
            'CREATE TABLE dte_emitido (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                rut_emisor TEXT NOT NULL, ambiente TEXT NOT NULL,
                tipo_dte INTEGER NOT NULL, folio INTEGER NOT NULL,
                track_id TEXT, estado TEXT NOT NULL, glosa_sii TEXT,
                sii_informados INTEGER NOT NULL DEFAULT 0,
                sii_aceptados INTEGER NOT NULL DEFAULT 0,
                sii_rechazados INTEGER NOT NULL DEFAULT 0,
                sii_reparos INTEGER NOT NULL DEFAULT 0
            )'
        );
        // Un sobre real: 22 facturas, 2 notas de debito, 6 notas de credito.
        $ins = $this->pdo->prepare(
            "INSERT INTO dte_emitido (rut_emisor, ambiente, tipo_dte, folio, track_id, estado)
             VALUES ('77724622-4', 'produccion', ?, ?, '0253081988', 'enviado')"
        );
        $folio = 1;
        foreach ([33 => 22, 56 => 2, 61 => 6] as $tipo => $n) {
            for ($i = 0; $i < $n; $i++) {
                $ins->execute([$tipo, $folio++]);
            }
        }
    }

    /** @return list<EstadisticaEnvioSii> los tres bloques de la respuesta real */
    private function estadisticaReal(): array
    {
        return [
            new EstadisticaEnvioSii(33, 22, 22, 0, 0),
            new EstadisticaEnvioSii(56, 2, 2, 0, 0),
            new EstadisticaEnvioSii(61, 6, 3, 0, 3),
        ];
    }

    /** @return array{int,int,int,int} */
    private function contadoresDe(int $tipo): array
    {
        $r = $this->pdo->query(
            "SELECT sii_informados, sii_aceptados, sii_rechazados, sii_reparos
             FROM dte_emitido WHERE tipo_dte = $tipo LIMIT 1"
        )->fetch(PDO::FETCH_NUM);

        return [(int) $r[0], (int) $r[1], (int) $r[2], (int) $r[3]];
    }

    public function testCadaTipoRecibeSusPropiosContadores(): void
    {
        RegistroVeredictoSii::persistir(
            $this->pdo, '77724622-4', 'produccion', '0253081988', 'EPR', 'Envio Procesado',
            $this->estadisticaReal(),
        );

        self::assertSame([22, 22, 0, 0], $this->contadoresDe(33));
        self::assertSame([2, 2, 0, 0], $this->contadoresDe(56));
        self::assertSame([6, 3, 0, 3], $this->contadoresDe(61), 'el bloque de las notas de credito');
    }

    public function testTodasLasFilasDeUnTipoQuedanMarcadas(): void
    {
        RegistroVeredictoSii::persistir(
            $this->pdo, '77724622-4', 'produccion', '0253081988', 'EPR', null,
            $this->estadisticaReal(),
        );

        // Las SEIS notas de credito quedan marcadas con reparos=3, no solo una:
        // el contador es del BLOQUE, no de un folio, y el SII no dice cual de las
        // seis. Marcarlas todas es lo unico honesto que se puede hacer con este
        // dato; por eso mismo NO se usa para restar de ningun total.
        $n = (int) $this->pdo->query('SELECT COUNT(*) FROM dte_emitido WHERE tipo_dte = 61 AND sii_reparos = 3')
            ->fetchColumn();
        self::assertSame(6, $n);

        // Y las 22 facturas siguen limpias.
        $limpias = (int) $this->pdo->query('SELECT COUNT(*) FROM dte_emitido WHERE tipo_dte = 33 AND sii_reparos = 0 AND sii_rechazados = 0')
            ->fetchColumn();
        self::assertSame(22, $limpias);
    }

    public function testElEstadoSigueSiendoDelSobreEntero(): void
    {
        $filas = RegistroVeredictoSii::persistir(
            $this->pdo, '77724622-4', 'produccion', '0253081988', 'EPR', 'Envio Procesado',
            $this->estadisticaReal(),
        );

        self::assertSame(30, $filas, 'fan-out a los 30 documentos del sobre');
        $distintos = (int) $this->pdo->query("SELECT COUNT(DISTINCT estado) FROM dte_emitido")->fetchColumn();
        self::assertSame(1, $distintos);
        self::assertSame('EPR', $this->pdo->query('SELECT estado FROM dte_emitido LIMIT 1')->fetchColumn());
    }

    /** Un bloque ilegible NO se escribe: nada de poner 0 donde no se pudo leer. */
    public function testBloqueIncompletoNoSeEscribe(): void
    {
        RegistroVeredictoSii::persistir(
            $this->pdo, '77724622-4', 'produccion', '0253081988', 'EPR', null,
            [
                new EstadisticaEnvioSii(33, 22, 22, 0, 0),
                new EstadisticaEnvioSii(61, 6, null, null, null),
            ],
        );

        self::assertSame([22, 22, 0, 0], $this->contadoresDe(33), 'el bloque bueno si se escribe');
        self::assertSame([0, 0, 0, 0], $this->contadoresDe(61), 'el ilegible queda en el default');

        // Queda en 0, y da igual: el aviso no lee estas columnas sino la
        // respuesta recien parseada, asi que el bloque ilegible ya alerto.
        self::assertTrue(RegistroVeredictoSii::rechazoInterno([
            new EstadisticaEnvioSii(61, 6, null, null, null),
        ]));
    }

    /** Sin estadistica, persistir() se comporta EXACTAMENTE como antes. */
    public function testSinEstadisticaNoTocaLosContadores(): void
    {
        $filas = RegistroVeredictoSii::persistir(
            $this->pdo, '77724622-4', 'produccion', '0253081988', 'RCT', 'Rechazado por Error en Caratula',
        );

        self::assertSame(30, $filas);
        self::assertSame([0, 0, 0, 0], $this->contadoresDe(33));
        self::assertSame(
            'Rechazado por Error en Caratula',
            $this->pdo->query('SELECT glosa_sii FROM dte_emitido LIMIT 1')->fetchColumn(),
        );
    }

    // -----------------------------------------------------------------------
    //  LA GUARDA: guardar contadores no puede tumbar el veredicto
    //
    //  Misma regla que el encolado de correo, y por el mismo motivo: cuando se
    //  llega a persistir() el veredicto ya es lo unico que importa, y los
    //  contadores son un extra que ni siquiera se lee todavia.
    //
    //  El fallo NO es hipotetico: el 04-08-2026 el runner murio entero por una
    //  columna que no existia, y el A/B de certificacion lo reprodujo -- estado
    //  y glosa escritos BIEN, y despues la PDOException escapando al llamador.
    // -----------------------------------------------------------------------

    /** La tabla como estaba ANTES de la 030: sin ninguna columna sii_*. */
    private function tablaSinLa030(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec(
            'CREATE TABLE dte_emitido (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                rut_emisor TEXT NOT NULL, ambiente TEXT NOT NULL,
                tipo_dte INTEGER NOT NULL, folio INTEGER NOT NULL,
                track_id TEXT, estado TEXT NOT NULL, glosa_sii TEXT
            )'
        );
        $ins = $pdo->prepare(
            "INSERT INTO dte_emitido (rut_emisor, ambiente, tipo_dte, folio, track_id, estado)
             VALUES ('77724622-4', 'produccion', ?, ?, '0253081988', 'enviado')"
        );
        $ins->execute([33, 1]);
        $ins->execute([61, 2]);

        return $pdo;
    }

    public function testSiFallaElGuardadoDeContadoresElVeredictoIgualSeGuarda(): void
    {
        $pdo = $this->tablaSinLa030();

        $filas = RegistroVeredictoSii::persistir(
            $pdo, '77724622-4', 'produccion', '0253081988', 'EPR', 'Envio Procesado',
            $this->estadisticaReal(),
        );

        // NO lanzo -- si lanzara, este test no llegaria hasta aqui.
        self::assertSame(2, $filas, 'el fan-out del estado ocurrio igual');

        $r = $pdo->query('SELECT estado, glosa_sii FROM dte_emitido ORDER BY folio')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($r as $fila) {
            self::assertSame('EPR', $fila['estado'], 'lo esencial se guardo');
            self::assertSame('Envio Procesado', $fila['glosa_sii'], 'la glosa tambien');
        }
    }

    /** Un fallo que no se registra es un fallo que se perdio. */
    public function testElFalloDeLosContadoresQuedaRegistrado(): void
    {
        $log     = tempnam(sys_get_temp_dir(), 'ab_log_');
        $previo  = (string) ini_get('error_log');
        ini_set('error_log', $log);

        try {
            RegistroVeredictoSii::persistir(
                $this->tablaSinLa030(), '77724622-4', 'produccion', '0253081988',
                'EPR', 'Envio Procesado', $this->estadisticaReal(),
            );
            $escrito = (string) file_get_contents($log);
        } finally {
            ini_set('error_log', $previo);
            @unlink($log);
        }

        self::assertStringContainsString('RegistroVeredictoSii', $escrito);
        self::assertStringContainsString('no se pudieron guardar los contadores', $escrito);
        self::assertStringContainsString('EL VEREDICTO SI QUEDO GUARDADO', $escrito);
        self::assertStringContainsString('0253081988', $escrito, 'el track, para poder buscarlo');
        self::assertStringContainsString('030', $escrito, 'la pista de que puede faltar la migracion');
    }

    /**
     * LA GUARDA NO PUEDE TAPAR EL UPDATE DEL ESTADO. Si falla lo esencial, tiene
     * que fallar a gritos: es la unica escritura que el sistema no puede perder.
     */
    public function testSiFallaElUpdateDelEstadoSiLanza(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        // Sin tabla dte_emitido: el UPDATE del estado no tiene donde escribir.
        $this->expectException(\PDOException::class);

        RegistroVeredictoSii::persistir(
            $pdo, '77724622-4', 'produccion', '0253081988', 'EPR', 'Envio Procesado',
            $this->estadisticaReal(),
        );
    }

    /** El scope sigue siendo (rut, ambiente, track): otro emisor no se toca. */
    public function testNoSeSaleDelSobre(): void
    {
        $this->pdo->exec(
            "INSERT INTO dte_emitido (rut_emisor, ambiente, tipo_dte, folio, track_id, estado)
             VALUES ('11111111-1', 'produccion', 33, 999, '0253081988', 'enviado')"
        );

        RegistroVeredictoSii::persistir(
            $this->pdo, '77724622-4', 'produccion', '0253081988', 'EPR', null,
            [new EstadisticaEnvioSii(33, 22, 22, 0, 0)],
        );

        $otro = $this->pdo->query("SELECT estado, sii_informados FROM dte_emitido WHERE rut_emisor = '11111111-1'")
            ->fetch(PDO::FETCH_NUM);
        self::assertSame('enviado', $otro[0]);
        self::assertSame(0, (int) $otro[1]);
    }
}
