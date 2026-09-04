<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Que la migracion 054 no invente el ambiente de ninguna orden.
 *
 * DE DONDE SALE. La primera version de la 054 terminaba el backfill con
 *
 *     UPDATE dte_pago_link SET ambiente = 'sandbox' WHERE ambiente IS NULL;
 *
 * justificado como "sandbox es el lado seguro". Estaba mal por dos motivos, y el
 * segundo es el que duele:
 *
 *   1. Marcar de sandbox una orden que nacio en produccion la deja
 *      consultandose contra el endpoint equivocado. O sea un cobro real que no
 *      se registra nunca: exactamente el fallo que la 054 viene a cerrar,
 *      reintroducido por la puerta de atras.
 *   2. Ese UPDATE garantizaba que no quedara NINGUN NULL, asi que el SIGNAL que
 *      venia despues -- puesto para abortar si algo quedaba sin resolver -- no
 *      podia dispararse jamas. Una guarda decorativa es peor que ninguna: da la
 *      impresion de que el caso esta cubierto.
 *
 * LA REGLA QUE FIJAN ESTOS TESTS: ante una orden sin evidencia de su ambiente, la
 * migracion PREFIERE FALLAR. Una orden puede mover dinero; elegirle un ambiente
 * a ojo no es un default razonable, es una decision que nadie tomo.
 *
 *
 * POR QUE SE EJECUTA LA MIGRACION DE VERDAD Y NO SE ANALIZA EL TEXTO
 * -----------------------------------------------------------------------------
 * Porque lo que hay que comprobar es el COMPORTAMIENTO -- que aborte, que no
 * rellene, que la columna acabe NOT NULL sin default -- y eso son SIGNAL,
 * PREPARE/EXECUTE e information_schema, que solo existen en MySQL. Un test sobre
 * el SQL como cadena habria dado por buena la version rota: el UPDATE de sandbox
 * y el SIGNAL convivian en el archivo tan tranquilos.
 *
 * NECESITA UN MySQL DESECHABLE, que no viene con la suite. Sin el, se salta: es
 * preferible a apuntar los tests a una base real, donde un error de un test
 * podria tocar datos de alguien. Se le pasa por TEST_MYSQL_DSN / _USER / _PASS.
 * Cada caso crea su propia base con nombre unico y la borra al terminar.
 */
final class BackfillAmbiente054Test extends MysqlDesechableTestCase
{
    private const MIGRACION = __DIR__ . '/../integration/plantiflex/migrations/054_pago_credenciales_por_ambiente.sql';

    /** El esquema TAL COMO ESTA ANTES de la 054: sin llavero y sin ambiente. */
    protected function prepararEsquema(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE cuenta (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255), nombre VARCHAR(255), estado VARCHAR(30)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE pago_pasarela_cuenta (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                cuenta_id BIGINT UNSIGNED NOT NULL,
                proveedor VARCHAR(30) NOT NULL DEFAULT 'flow',
                ambiente ENUM('sandbox','produccion') NOT NULL DEFAULT 'sandbox',
                habilitado TINYINT(1) NOT NULL DEFAULT 0,
                credencial_publica VARCHAR(255) NULL,
                credencial_cifrada TEXT NULL,
                url_retorno VARCHAR(500) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_pasarela_cuenta (cuenta_id),
                CONSTRAINT fk_pasarela_cuenta FOREIGN KEY (cuenta_id) REFERENCES cuenta (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE dte_pago_link (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                dte_emitido_id BIGINT UNSIGNED NOT NULL,
                cuenta_id BIGINT UNSIGNED NOT NULL,
                proveedor VARCHAR(30) NOT NULL,
                referencia VARCHAR(120) NOT NULL,
                orden_externa VARCHAR(120) NULL,
                url VARCHAR(500) NULL,
                monto INT UNSIGNED NOT NULL DEFAULT 0,
                estado ENUM('pendiente','creado','error','omitido','pagado') NOT NULL DEFAULT 'pendiente',
                intentos INT UNSIGNED NOT NULL DEFAULT 0,
                UNIQUE KEY uk_pago_link_documento (dte_emitido_id),
                UNIQUE KEY uk_pago_link_referencia (referencia)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);

        $this->pdo->exec("INSERT INTO cuenta (id, email, nombre, estado) VALUES (1, 'a@a.cl', 'Con config', 'activa')");
        $this->pdo->exec("INSERT INTO cuenta (id, email, nombre, estado) VALUES (99, 'b@b.cl', 'Sin config', 'activa')");
    }

    /** Configuracion CON credenciales: es lo que la 054 tiene que copiar al llavero. */
    private function config(int $cuentaId, string $ambiente, string $proveedor = 'flow'): void
    {
        $this->pdo->prepare(
            'INSERT INTO pago_pasarela_cuenta (cuenta_id, proveedor, ambiente, habilitado, credencial_publica, credencial_cifrada) '
            . "VALUES (:c, :p, :a, 1, 'apikey', 'secreto-cifrado')"
        )->execute([':c' => $cuentaId, ':p' => $proveedor, ':a' => $ambiente]);
    }

    private function orden(int $dteId, int $cuentaId, ?string $url, string $proveedor = 'flow'): void
    {
        $this->pdo->prepare(
            'INSERT INTO dte_pago_link (dte_emitido_id, cuenta_id, proveedor, referencia, url, monto, estado) '
            . "VALUES (:d, :c, :p, :r, :u, 1000, 'creado')"
        )->execute([
            ':d' => $dteId, ':c' => $cuentaId, ':p' => $proveedor,
            ':r' => 'SIN-' . $cuentaId . '-33-' . $dteId, ':u' => $url,
        ]);
    }

    /** Ejecuta la 054 entera. Devuelve null si fue bien, o el mensaje del error. */
    private function migrar(): ?string
    {
        return $this->ejecutarSql(self::MIGRACION);
    }

    private function ambienteDe(int $dteId): ?string
    {
        $st = $this->pdo->prepare('SELECT ambiente FROM dte_pago_link WHERE dte_emitido_id = :d');
        $st->execute([':d' => $dteId]);
        $v = $st->fetchColumn();

        return $v === false || $v === null ? null : (string) $v;
    }

    // ==================================================================
    //  A. La url decide, cuando el host es reconocible
    // ==================================================================

    #[DataProvider('urlsQueIdentificanElAmbiente')]
    public function testLaUrlDelCheckoutDecideElAmbiente(string $url, string $esperado): void
    {
        $this->config(1, 'produccion');   // la config dice lo CONTRARIO a proposito
        $this->orden(100, 1, $url);

        self::assertNull($this->migrar(), 'la migracion tiene que pasar');
        self::assertSame($esperado, $this->ambienteDe(100), 'manda la evidencia de la url');
    }

    /** @return list<array{string,string}> */
    public static function urlsQueIdentificanElAmbiente(): array
    {
        return [
            'sandbox' => ['https://sandbox.flow.cl/app/web/pay.php?token=x', 'sandbox'],
            'www'     => ['https://www.flow.cl/app/web/pay.php?token=x', 'produccion'],
            'apex'    => ['https://flow.cl/app/web/pay.php?token=x', 'produccion'],
        ];
    }

    public function testLaUrlManda_INCLUSO_SI_LaConfiguracionDiceOtraCosa(): void
    {
        // Una orden de sandbox en una cuenta que ya paso a produccion: es
        // exactamente el escenario del cambio de ambiente, y la url es lo unico
        // que sabe la verdad.
        $this->config(1, 'produccion');
        $this->orden(100, 1, 'https://sandbox.flow.cl/app/web/pay.php?token=x');

        self::assertNull($this->migrar());
        self::assertSame('sandbox', $this->ambienteDe(100));
    }

    // ==================================================================
    //  B. Sin url, decide la configuracion de SU cuenta
    // ==================================================================

    #[DataProvider('losDosAmbientes')]
    public function testSinUrlDecideLaConfiguracionDeLaCuenta(string $ambiente): void
    {
        $this->config(1, $ambiente);
        $this->orden(100, 1, null);

        self::assertNull($this->migrar());
        self::assertSame($ambiente, $this->ambienteDe(100));
    }

    /** @return list<array{string}> */
    public static function losDosAmbientes(): array
    {
        return [['sandbox'], ['produccion']];
    }

    public function testLaConfiguracionDeOtroProveedorNoSirve(): void
    {
        // El ambiente configurado para khipu no dice nada del ambiente de una
        // orden creada con flow.
        $this->config(1, 'produccion', proveedor: 'khipu');
        $this->orden(100, 1, null, proveedor: 'flow');

        $error = $this->migrar();

        self::assertNotNull($error, 'sin evidencia para ESE proveedor, aborta');
        self::assertStringContainsString('054 ABORTA', $error);
    }

    // ==================================================================
    //  C-E. Sin evidencia, la migracion FALLA. No inventa.
    // ==================================================================

    public function testSinUrlYSinConfiguracionLaMigracionFalla(): void
    {
        // EL CASO QUE MOTIVO ESTA CORRECCION. Antes quedaba 'sandbox' en
        // silencio; si esa orden era de produccion, su pago no se registraba
        // nunca y nadie se enteraba.
        $this->orden(100, 99, null);   // cuenta 99: sin fila de configuracion

        $error = $this->migrar();

        self::assertNotNull($error, 'tiene que abortar');
        self::assertStringContainsString('054 ABORTA', $error);
        self::assertStringContainsString('sin ambiente determinable', $error);
        self::assertStringContainsString('NO se aplico el NOT NULL', $error);
    }

    public function testUnaUrlIrreconocibleSinConfiguracionTambienFalla(): void
    {
        // Una url truncada, de otro proveedor o de un dominio que no conocemos no
        // es evidencia. Antes, cualquier url que no dijera "sandbox" se daba por
        // produccion -- una orden de pruebas marcada como cobro real.
        $this->orden(100, 99, 'https://pagos.otracosa.example/checkout/abc');

        $error = $this->migrar();

        self::assertNotNull($error);
        self::assertStringContainsString('054 ABORTA', $error);
    }

    public function testAlAbortarNoDejaNingunaFilaInventada(): void
    {
        $this->config(1, 'sandbox');
        $this->orden(100, 1, 'https://sandbox.flow.cl/pay');   // resoluble
        $this->orden(200, 99, null);                            // no resoluble

        self::assertNotNull($this->migrar(), 'aborta por la segunda');

        // La primera se resolvio, la segunda quedo en NULL esperando a una
        // persona. Ninguna quedo con un ambiente que nadie decidio.
        self::assertSame('sandbox', $this->ambienteDe(100));
        self::assertNull($this->ambienteDe(200));
    }

    public function testElMensajeDelAbortoDiceCuantasSonYComoEncontrarlas(): void
    {
        $this->orden(100, 99, null);
        $this->orden(200, 99, null);

        $error = (string) $this->migrar();

        // El mensaje dice CUANTAS son y por donde buscarlas. Cabe en los 128
        // caracteres de MESSAGE_TEXT; el detalle largo esta en la cabecera del
        // .sql, que es donde se lee con calma.
        self::assertStringContainsString('2 orden(es)', $error);
        self::assertStringContainsString('ambiente IS NULL', $error);
    }

    // ==================================================================
    //  La forma final de la columna
    // ==================================================================

    public function testAlTerminarBienLaColumnaEsNOT_NULL_Y_SIN_DEFAULT(): void
    {
        // SIN DEFAULT es la mitad que importa: con uno, una orden nueva podria
        // nacer con un ambiente por omision, que es como se cuela el fallo otra
        // vez. Quien crea una orden tiene que decir su ambiente.
        $this->config(1, 'produccion');
        $this->orden(100, 1, 'https://www.flow.cl/pay');

        self::assertNull($this->migrar());

        $st = $this->pdo->prepare(
            'SELECT IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
        );
        $st->execute([':t' => 'dte_pago_link', ':c' => 'ambiente']);
        $col = $st->fetch(PDO::FETCH_ASSOC);

        self::assertSame('NO', $col['IS_NULLABLE']);
        self::assertNull($col['COLUMN_DEFAULT'], 'sin default: ninguna fila llega a NOT NULL por omision');
    }

    public function testAlAbortarLaColumnaSIGUE_NULLABLE_Y_NoSeAplicoElNotNull(): void
    {
        $this->orden(100, 99, null);

        self::assertNotNull($this->migrar());

        $st = $this->pdo->prepare(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
        );
        $st->execute([':t' => 'dte_pago_link', ':c' => 'ambiente']);

        self::assertSame('YES', $st->fetchColumn(), 'el ALTER a NOT NULL no llego a ejecutarse');
    }

    public function testUnaVezResueltaLaFilaAmbiguaLaMigracionCompleta(): void
    {
        // El camino de vuelta: la persona mira las filas, decide, y la migracion
        // se vuelve a ejecutar. Es idempotente, asi que no hay que deshacer nada.
        $this->orden(100, 99, null);
        self::assertNotNull($this->migrar(), 'primera pasada: aborta');

        $this->pdo->exec("UPDATE dte_pago_link SET ambiente = 'produccion' WHERE dte_emitido_id = 100");

        self::assertNull($this->migrar(), 'segunda pasada: completa');
        self::assertSame('produccion', $this->ambienteDe(100));
    }

    public function testLaMigracionEsIdempotente(): void
    {
        $this->config(1, 'sandbox');
        $this->orden(100, 1, 'https://sandbox.flow.cl/pay');

        self::assertNull($this->migrar());
        self::assertNull($this->migrar(), 'dos veces no rompe');

        self::assertSame(1, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM pago_pasarela_credencial'
        )->fetchColumn());
    }
}
