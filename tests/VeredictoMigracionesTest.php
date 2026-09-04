<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Que el verificador clasifique bien una migracion que BORRA.
 *
 * DE DONDE SALE. ./deploy.sh --dry-run --force mostraba
 *
 *     054  NO_APLICADA
 *     055  PARCIAL  3/0
 *
 * y "una PARCIAL aborta el despliegue AUNQUE este marcada como diferida". O sea
 * que la 055 -- destructiva y diferida a proposito, cuyas tres columnas HOY
 * tienen que seguir existiendo -- bloqueaba deploys por estar correctamente sin
 * aplicar.
 *
 * LA CAUSA NO ERA LA 055: era que el verificador da por hecho que las
 * migraciones AGREGAN. veredicto() compara "presentes" contra "esperados" y
 * llama NO_APLICADA a presentes === 0, lo cual solo funciona si "presente"
 * cuenta rasgos que la migracion CREA. Una que borra no encaja: con esperado = 0
 * y las tres columnas ahi, salia 3/0 -> PARCIAL.
 *
 * El arreglo no es una excepcion para la 055 ni maquillar la salida, sino contar
 * el rasgo que esa migracion SI produce -- la AUSENCIA -- para que "presente"
 * signifique lo mismo que en todas las demas. veredicto() no se toca.
 *
 * SE PRUEBA CONTRA MySQL DE VERDAD porque la huella consulta information_schema,
 * y porque lo que hay que verificar es la clasificacion sobre un esquema real en
 * cada uno de sus cuatro estados posibles.
 */
final class VeredictoMigracionesTest extends MysqlDesechableTestCase
{
    private const VIEJAS = ['credencial_publica', 'credencial_cifrada', 'ambiente'];

    protected function prepararEsquema(): void
    {
        // pago_pasarela_cuenta TAL COMO ESTA HOY: con las tres columnas que la
        // 055 vendra a retirar.
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE pago_pasarela_cuenta (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                cuenta_id BIGINT UNSIGNED NOT NULL,
                proveedor VARCHAR(30) NOT NULL DEFAULT 'flow',
                ambiente ENUM('sandbox','produccion') NOT NULL DEFAULT 'sandbox',
                ambiente_activo ENUM('sandbox','produccion') NOT NULL DEFAULT 'sandbox',
                habilitado TINYINT(1) NOT NULL DEFAULT 0,
                credencial_publica VARCHAR(255) NULL,
                credencial_cifrada TEXT NULL,
                url_retorno VARCHAR(500) NULL,
                UNIQUE KEY uk_pasarela_cuenta (cuenta_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);

        require_once __DIR__ . '/../scripts/catalogo_migraciones.php';
    }

    /** La huella real de la 055, tal como esta en el catalogo. */
    private function huella055(): array
    {
        foreach (MIGRACIONES as $m) {
            if ($m['id'] === '055') {
                self::assertCount(1, $m['huellas'], 'la 055 tiene una sola huella');

                return $m['huellas'][0];
            }
        }

        self::fail('no se encontro la migracion 055 en el catalogo');
    }

    private function veredicto055(): string
    {
        $e = evaluarHuella($this->pdo, $this->huella055());

        return veredicto((int) $e['presente'], (int) $e['esperado']);
    }

    private function borrar(string ...$columnas): void
    {
        foreach ($columnas as $c) {
            $this->pdo->exec("ALTER TABLE pago_pasarela_cuenta DROP COLUMN {$c}");
        }
    }

    // ==================================================================
    //  Los cuatro estados
    // ==================================================================

    public function testConLasTresColumnasLa055EstaNO_APLICADA(): void
    {
        // EL CASO DE HOY. Antes salia PARCIAL 3/0 y bloqueaba el despliegue.
        self::assertSame('NO_APLICADA', $this->veredicto055());
    }

    public function testSinNingunaDeLasTresLa055EstaAPLICADA(): void
    {
        $this->borrar(...self::VIEJAS);

        self::assertSame('APLICADA', $this->veredicto055());
    }

    #[DataProvider('unaSolaColumnaBorrada')]
    public function testSiFaltaSoloUnaLa055EsPARCIAL(string $columna): void
    {
        // Un DROP a medias -- corte entre dos ALTER, por ejemplo -- es
        // exactamente lo que hay que mirar a mano.
        $this->borrar($columna);

        self::assertSame('PARCIAL', $this->veredicto055());
    }

    /** @return list<array{string}> */
    public static function unaSolaColumnaBorrada(): array
    {
        return [['credencial_publica'], ['credencial_cifrada'], ['ambiente']];
    }

    #[DataProvider('dosColumnasBorradas')]
    public function testSiFaltanDosLa055TambienEsPARCIAL(string $a, string $b): void
    {
        $this->borrar($a, $b);

        self::assertSame('PARCIAL', $this->veredicto055());
    }

    /** @return list<array{string,string}> */
    public static function dosColumnasBorradas(): array
    {
        return [
            ['credencial_publica', 'credencial_cifrada'],
            ['credencial_publica', 'ambiente'],
            ['credencial_cifrada', 'ambiente'],
        ];
    }

    // ==================================================================
    //  Que el arreglo no haya debilitado nada
    // ==================================================================

    public function testUnaPARCIAL_SIGUE_AbortandoAunqueEsteDiferida(): void
    {
        // La proteccion global no se toca: "diferida" significa "todavia no la
        // corrimos", no "la corrimos a medias". Una diferida a medio aplicar es
        // un esquema que ningun archivo describe.
        $verificador = file_get_contents(__DIR__ . '/../scripts/estado_migraciones.php');
        self::assertNotFalse($verificador);

        self::assertStringContainsString(
            'Una PARCIAL aborta el despliegue AUNQUE este marcada como diferida.',
            $verificador
        );

        // Y en el codigo: el PARCIAL se clasifica ANTES de mirar si hay marca de
        // diferida, asi que la marca no puede rescatarlo.
        $posParcial  = strpos($verificador, "if (\$v === 'PARCIAL')");
        $posDiferida = strpos($verificador, '$diferidas[] =');
        self::assertNotFalse($posParcial);
        self::assertNotFalse($posDiferida);
        self::assertLessThan($posDiferida, $posParcial, 'PARCIAL se decide antes que la marca de diferida');
    }

    public function testLa055SigueMarcadaComoDiferidaConSuMotivo(): void
    {
        foreach (MIGRACIONES as $m) {
            if ($m['id'] !== '055') {
                continue;
            }
            self::assertArrayHasKey('diferida', $m, 'la 055 sigue siendo diferida a proposito');
            self::assertNotSame('', trim((string) $m['diferida']));
            self::assertStringContainsString('054', (string) $m['diferida'], 'el motivo dice de que depende');

            return;
        }
        self::fail('no se encontro la 055');
    }

    public function testLa054SinAplicarNO_EstaMarcadaComoDiferida(): void
    {
        // La 054 SI tiene que aplicarse, y su ausencia tiene que abortar el
        // despliegue. Si alguien le pusiera la marca para que el verificador
        // callara, el modelo nuevo se desplegaria sin su esquema.
        foreach (MIGRACIONES as $m) {
            if ($m['id'] !== '054') {
                continue;
            }
            self::assertArrayNotHasKey('diferida', $m, 'la 054 NO es diferida: hay que aplicarla');
            self::assertNotEmpty($m['huellas']);

            return;
        }
        self::fail('no se encontro la 054');
    }

    public function testLaHuellaDeAusenciaNoNecesitaDeclararEsperado(): void
    {
        // El esperado sale de contar las columnas: declararlo a mano abre la
        // puerta a que diga 3 cuando la lista tiene 4, y entonces una migracion
        // aplicada del todo se veria PARCIAL para siempre.
        $h = $this->huella055();

        self::assertSame('columnas_ausentes', $h['tipo']);
        self::assertArrayNotHasKey('esperado', $h);

        $e = evaluarHuella($this->pdo, $h);
        self::assertSame(count($h['columnas']), (int) $e['esperado']);
    }
}
