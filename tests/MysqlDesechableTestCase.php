<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Base de los tests que necesitan un MySQL de verdad y desechable.
 *
 * POR QUE HAY TESTS ASI. Lo que comprueban -- SIGNAL, PREPARE/EXECUTE,
 * information_schema, el veredicto de una migracion sobre un esquema real -- no
 * existe fuera de MySQL. Un test sobre el texto del .sql o sobre SQLite daria por
 * buenas cosas que en MySQL no funcionan; ya paso dos veces.
 *
 * LA GUARDA VIVE AQUI Y NO EN CADA TEST. Estas clases crean y BORRAN bases
 * enteras. Duplicar la comprobacion en cada una es garantizar que un dia
 * diverjan, y la que se quede corta sera la que apunte a la base equivocada.
 *
 * DOS CAPAS, y esta es la de arriba. La de abajo es el GRANT: deploy.sh crea el
 * usuario de pruebas con permisos limitados a `pruebamig\_%`.*, asi que aunque
 * esto fallara, MySQL rechazaria por su cuenta cualquier operacion sobre una base
 * real. Una guarda que depende de que quien prepara el entorno lo haya hecho bien
 * no basta cuando al otro lado esta produccion.
 */
abstract class MysqlDesechableTestCase extends TestCase
{
    /** El prefijo que TODA base de estos tests tiene que llevar. */
    public const PREFIJO = 'pruebamig_';

    /** Bases que estos tests NUNCA pueden tocar, pase lo que pase. */
    protected const PROHIBIDAS = ['sinergia_fac_bol', 'preview_fac', 'mysql', 'information_schema'];

    protected ?PDO $pdo = null;
    protected string $base = '';

    protected function setUp(): void
    {
        $dsn = getenv('TEST_MYSQL_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped(
                'Sin MySQL desechable (TEST_MYSQL_DSN). deploy.sh lo prepara solo y ademas '
                . 'corre estos tests con --fail-on-skipped, asi que ahi no pueden quedar en gris.'
            );
        }

        // FALLA, NO SE SALTA. Un DSN ausente es "aqui no hay MySQL" y saltar es
        // correcto. Un DSN que apunta a donde no debe es un error de
        // configuracion del que hay que enterarse: saltarlo lo dejaria pasar en
        // silencio, y esto crea y borra bases.
        $base = self::baseDelDsn($dsn);

        self::assertNotSame('', $base, "TEST_MYSQL_DSN tiene que traer dbname. DSN recibido sin base: {$dsn}");
        self::assertNotContains(
            $base,
            self::PROHIBIDAS,
            "TEST_MYSQL_DSN apunta a '{$base}', que es una base REAL. Estos tests crean y borran bases."
        );
        self::assertStringStartsWith(
            self::PREFIJO,
            $base,
            "TEST_MYSQL_DSN apunta a '{$base}', que no lleva el prefijo '" . self::PREFIJO . "'. "
            . 'Solo se opera sobre bases desechables.'
        );

        try {
            $raiz = new PDO($dsn, (string) getenv('TEST_MYSQL_USER'), (string) getenv('TEST_MYSQL_PASS'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (PDOException $e) {
            // El mensaje de PDO puede traer el DSN, nunca la contrasena.
            self::fail('No se pudo conectar al MySQL de pruebas: ' . $e->getMessage());
        }

        // Base propia por caso, colgando de la que dio el entorno: dos casos no
        // se pisan y el nombre sigue casando con el GRANT del usuario.
        $this->base = $base . '_' . bin2hex(random_bytes(4));
        $raiz->exec('CREATE DATABASE ' . $this->base);

        $this->pdo = new PDO(
            self::dsnSinBase($dsn) . ';dbname=' . $this->base,
            (string) getenv('TEST_MYSQL_USER'),
            (string) getenv('TEST_MYSQL_PASS'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                // Los EXECUTE de las migraciones devuelven un resultset ('SELECT
                // 1' cuando el paso ya estaba hecho) y sin buffer dejan el cursor
                // abierto: la sentencia siguiente muere con "unbuffered queries
                // are active". El cliente mysql bufferiza, asi que esto reproduce
                // como se ejecuta de verdad.
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
                // Y emulacion activada: sin ella, query() manda por el protocolo
                // de prepared statements, que no admite PREPARE/EXECUTE
                // ("1295 This command is not supported...") -- y las migraciones
                // de este proyecto estan hechas de eso, porque es la unica forma
                // de tener ADD COLUMN idempotente en Oracle MySQL.
                PDO::ATTR_EMULATE_PREPARES => true,
            ]
        );

        $this->prepararEsquema();
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null && $this->base !== '') {
            $this->pdo->exec('DROP DATABASE IF EXISTS ' . $this->base);
        }
        $this->pdo = null;
    }

    /** Lo que cada test necesite en su base recien creada. */
    abstract protected function prepararEsquema(): void;

    /**
     * Ejecuta un archivo .sql sentencia a sentencia.
     *
     * Devuelve null si fue bien, o el mensaje del error, para que un test pueda
     * afirmar que una migracion ABORTA sin que la excepcion tumbe el caso.
     */
    protected function ejecutarSql(string $ruta): ?string
    {
        $sql = file_get_contents($ruta);
        self::assertNotFalse($sql, "no se pudo leer {$ruta}");

        try {
            foreach (self::sentencias($sql) as $sentencia) {
                // query() y no exec(): los EXECUTE devuelven un resultset que hay
                // que consumir, o la sentencia siguiente muere.
                $st = $this->pdo->query($sentencia);
                if ($st === false) {
                    continue;
                }
                do {
                    $st->fetchAll();
                } while ($st->nextRowset());
                $st->closeCursor();
            }
        } catch (PDOException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Parte un .sql en sentencias RESPETANDO LAS COMILLAS.
     *
     * Un explode(';') ingenuo no sirve: los COMMENT de las columnas contienen
     * puntos y coma, y cortar ahi produce SQL invalido. El cliente mysql maneja
     * bien esos casos, asi que el problema seria del test -- y un test que obligue
     * a escribir peor el SQL para poder ejecutarlo es un mal test.
     *
     * @return list<string>
     */
    protected static function sentencias(string $sql): array
    {
        $limpio = (string) preg_replace('/^\s*--.*$/m', '', $sql);

        $sentencias = [];
        $actual     = '';
        $enCadena   = false;
        $largo      = strlen($limpio);

        for ($i = 0; $i < $largo; $i++) {
            $c = $limpio[$i];

            if ($c === "'") {
                // '' dentro de una cadena es una comilla escapada, no el cierre.
                if ($enCadena && ($limpio[$i + 1] ?? '') === "'") {
                    $actual .= "''";
                    $i++;
                    continue;
                }
                $enCadena = ! $enCadena;
                $actual  .= $c;
                continue;
            }

            if ($c === ';' && ! $enCadena) {
                $sentencias[] = $actual;
                $actual       = '';
                continue;
            }

            $actual .= $c;
        }
        $sentencias[] = $actual;

        return array_values(array_filter(
            array_map('trim', $sentencias),
            static fn (string $s): bool => $s !== ''
        ));
    }

    /** El dbname de un DSN de PDO, o cadena vacia si no lo trae. */
    protected static function baseDelDsn(string $dsn): string
    {
        return preg_match('/(?:^|;)dbname=([^;]*)/', $dsn, $m) === 1 ? trim($m[1]) : '';
    }

    /** El mismo DSN sin su dbname. */
    protected static function dsnSinBase(string $dsn): string
    {
        $limpio = (string) preg_replace('/(?:^|;)dbname=[^;]*/', '', $dsn);

        return rtrim(str_replace(';;', ';', $limpio), ';');
    }
}
