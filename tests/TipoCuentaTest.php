<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\TestCase;
use TipoCuenta;

require_once __DIR__ . '/../panel/src/TipoCuenta.php';

/**
 * Tests del tipo de cuenta (interna / demo / trial / pago).
 *
 * LO QUE HAY QUE VIGILAR AQUI SON DOS COSAS QUE NO SE NOTAN AL ROMPERSE:
 *
 *   1. QUE LA LISTA DE PHP Y EL ENUM DE LA BASE DIGAN LO MISMO. Son dos listas
 *      en dos archivos distintos que tienen que coincidir exactamente. Si a una
 *      se le agrega un valor y a la otra no, no falla nada: el <select> ofrece
 *      un valor que MySQL rechaza (500 al guardar), o la base acepta uno que la
 *      pantalla no sabe dibujar. El ultimo test compara las dos contra el .sql.
 *
 *   2. QUE 'sin_definir' NO CUENTE COMO CLIENTE. La cifra "cuantas cuentas
 *      pagan" sale de comerciales(); meter ahi lo que todavia nadie clasifico
 *      seria inventar clientes.
 */
final class TipoCuentaTest extends TestCase
{
    public function testLosCincoValoresEstanDeclarados(): void
    {
        self::assertSame(['sin_definir', 'pago', 'trial', 'demo', 'interna'], TipoCuenta::claves());
    }

    public function testSoloPasaLoQueEstaDeclarado(): void
    {
        self::assertTrue(TipoCuenta::valido('pago'));
        self::assertTrue(TipoCuenta::valido('sin_definir'));

        self::assertFalse(TipoCuenta::valido('PAGO'));
        self::assertFalse(TipoCuenta::valido(''));
        self::assertFalse(TipoCuenta::valido("pago' OR 1=1"));
    }

    public function testCadaValorTieneEtiquetaClaseYAyuda(): void
    {
        foreach (TipoCuenta::claves() as $clave) {
            self::assertNotSame('', TipoCuenta::etiqueta($clave), "'{$clave}' sin etiqueta");
            self::assertStringStartsWith('tag', TipoCuenta::clase($clave), "'{$clave}' sin clase de tag");
            self::assertNotSame('', TipoCuenta::ayuda($clave), "'{$clave}' sin texto de ayuda");
        }
    }

    /**
     * Un valor que no existe no puede reventar una pantalla: si la base
     * devolviera algo raro, se dibuja tal cual y con el tag neutro.
     */
    public function testUnValorDesconocidoNoRevienta(): void
    {
        self::assertSame('vaya', TipoCuenta::etiqueta('vaya'));
        self::assertSame('tag', TipoCuenta::clase('vaya'));
        self::assertSame('', TipoCuenta::ayuda('vaya'));
    }

    public function testSoloPagoYTrialCuentanComoClientes(): void
    {
        self::assertSame(['pago', 'trial'], TipoCuenta::comerciales());

        // Las tres que NO son clientes, una por una: incluir cualquiera de
        // ellas inflaria la unica cifra comercial que da el panel.
        foreach (['sin_definir', 'demo', 'interna'] as $noCliente) {
            self::assertNotContains($noCliente, TipoCuenta::comerciales());
        }
    }

    /**
     * EL TEST QUE UNE LOS DOS ARCHIVOS. Se corre contra el .sql de verdad: la
     * lista de PHP y el ENUM de la migracion tienen que declarar exactamente
     * los mismos valores. Desincronizados no falla nada hasta que alguien
     * guarda.
     */
    public function testElEnumDeLaMigracionDiceLoMismoQueEstaClase(): void
    {
        $sql = (string) file_get_contents(__DIR__ . '/../integration/plantiflex/migrations/047_cuenta_tipo.sql');

        self::assertSame(
            1,
            preg_match("/ADD COLUMN tipo ENUM\(([^)]*)\)/", $sql, $m),
            'no se reconocio el ENUM en la migracion 047'
        );

        preg_match_all("/'([a-z_]+)'/", $m[1], $valores);

        // SE COMPARAN COMO CONJUNTOS, no como listas: los dos ordenes son
        // distintos a proposito. El del ENUM es el de guardado -- 'sin_definir'
        // primero, que es el default -- y el de esta clase es el de
        // presentacion, con lo que mas se mira arriba. Lo que no puede diferir
        // es QUE valores hay.
        $delSql = $valores[1];
        $delPhp = TipoCuenta::claves();
        sort($delSql);
        sort($delPhp);

        self::assertSame(
            $delPhp,
            $delSql,
            'el ENUM de la migracion 047 y TipoCuenta ya no declaran los mismos valores'
        );
    }
}
