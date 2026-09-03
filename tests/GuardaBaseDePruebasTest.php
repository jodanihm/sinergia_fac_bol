<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Que el test que EJECUTA migraciones no pueda apuntar a una base real.
 *
 * POR QUE ESTO ES UN TEST APARTE. BackfillAmbiente054Test crea y BORRA bases
 * enteras. Su guarda -- el prefijo obligatorio y la lista de prohibidas -- solo
 * corre cuando hay un MySQL delante, asi que en una maquina sin MySQL nadie la
 * mira. Esto la prueba sin base de datos ninguna: es la parte que tiene que estar
 * verde SIEMPRE, incluso en el entorno donde el otro test se salta.
 *
 * LAS DOS CAPAS. Aqui se prueba la de arriba, la del codigo. La de abajo es el
 * GRANT: deploy.sh crea un usuario de pruebas con permisos limitados a
 * `pruebamig\_%`.*, de modo que aunque esta guarda fallara, MySQL rechazaria por
 * su cuenta cualquier operacion sobre sinergia_fac_bol. Una guarda que depende de
 * que quien prepara el entorno lo haya hecho bien no basta cuando lo que esta al
 * otro lado es la base de produccion.
 */
final class GuardaBaseDePruebasTest extends TestCase
{
    #[DataProvider('dsnQueDebenRechazarse')]
    public function testUnDsnQueNoEsDesechableSeRechaza(string $dsn, string $porque): void
    {
        $base = self::baseDelDsn($dsn);

        $aceptable = $base !== ''
            && ! in_array($base, ['sinergia_fac_bol', 'preview_fac', 'mysql', 'information_schema'], true)
            && str_starts_with($base, BackfillAmbiente054Test::PREFIJO);

        self::assertFalse($aceptable, $porque);
    }

    /** @return list<array{string,string}> */
    public static function dsnQueDebenRechazarse(): array
    {
        return [
            'produccion'    => ['mysql:host=sinergia_mysql;dbname=sinergia_fac_bol', 'es la base de produccion'],
            'preview'       => ['mysql:host=x;dbname=preview_fac', 'es la base del preview'],
            'mysql interna' => ['mysql:host=x;dbname=mysql', 'es la base interna del motor'],
            'sin dbname'    => ['mysql:host=x;charset=utf8mb4', 'sin base, el test operaria sobre la que toque'],
            'sin prefijo'   => ['mysql:host=x;dbname=cualquiera', 'no lleva el prefijo desechable'],
            'prefijo a medias' => ['mysql:host=x;dbname=pruebami', 'parecido no es igual'],
            'prefijo al final' => ['mysql:host=x;dbname=otra_pruebamig_', 'el prefijo va al principio'],
        ];
    }

    public function testUnDsnDesechableSiSeAcepta(): void
    {
        $base = self::baseDelDsn('mysql:host=sinergia_mysql;dbname=pruebamig_a1b2c3;charset=utf8mb4');

        self::assertSame('pruebamig_a1b2c3', $base);
        self::assertStringStartsWith(BackfillAmbiente054Test::PREFIJO, $base);
    }

    public function testElPrefijoEsElMismoQueUsaDeploySh(): void
    {
        // Si alguien cambia uno de los dos, el usuario de pruebas deja de tener
        // permiso sobre las bases que el test crea, o peor: lo tiene sobre mas
        // de la cuenta. Se fijan juntos.
        $deploy = file_get_contents(__DIR__ . '/../deploy.sh');
        self::assertNotFalse($deploy);

        self::assertStringContainsString(
            "PREFIJO_BASE_PRUEBAS='" . BackfillAmbiente054Test::PREFIJO . "'",
            $deploy,
            'deploy.sh y el test tienen que usar el MISMO prefijo'
        );
    }

    public function testDeployShNuncaApuntaLosTestsALaBaseReal(): void
    {
        $deploy = file_get_contents(__DIR__ . '/../deploy.sh');
        self::assertNotFalse($deploy);

        // Que no exista un TEST_MYSQL_DSN con la base de produccion escrito a
        // mano, por copiar y pegar de otra funcion del propio script.
        preg_match_all('/TEST_MYSQL_DSN=([^\s"\']+)/', $deploy, $m);
        foreach ($m[1] as $valor) {
            self::assertStringNotContainsString('sinergia_fac_bol', $valor);
        }
    }

    public function testDeployShBorraLaBaseTemporalPaseLoQuePase(): void
    {
        // Sin trap, un fallo de la suite deja bases colgando en el MySQL de
        // produccion hasta que alguien las vea. Con set -e, ademas, el deploy
        // sale antes de llegar a cualquier limpieza escrita al final.
        $deploy = file_get_contents(__DIR__ . '/../deploy.sh');
        self::assertNotFalse($deploy);

        self::assertMatchesRegularExpression(
            '/trap\s+[\'"]?limpiar_mysql_de_pruebas/',
            $deploy,
            'la limpieza tiene que ir en un trap EXIT, no al final del camino feliz'
        );
    }

    private static function baseDelDsn(string $dsn): string
    {
        return preg_match('/(?:^|;)dbname=([^;]*)/', $dsn, $m) === 1 ? trim($m[1]) : '';
    }
}
