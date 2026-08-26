<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\TestCase;
use PlanCuenta;
use TipoCuenta;

require_once __DIR__ . '/../panel/src/TipoCuenta.php';
require_once __DIR__ . '/../panel/src/PlanCuenta.php';

/**
 * Tests del plan comercial de la cuenta (Basico / Pyme / Pro).
 *
 * Mismo criterio que TipoCuentaTest, y con un test mas que aquel no necesita:
 * la distincion entre 'ninguno' y 'sin_definir'. Son dos cosas distintas -- "no
 * contrata" es una afirmacion sobre una cuenta interna, "no se" es trabajo
 * pendiente sobre un cliente -- y si alguna vez se colapsan en una, el trabajo
 * pendiente queda escondido detras de algo que parece resuelto.
 */
final class PlanCuentaTest extends TestCase
{
    public function testLosCincoValoresEstanDeclarados(): void
    {
        self::assertSame(['sin_definir', 'basico', 'pyme', 'pro', 'ninguno'], PlanCuenta::claves());
    }

    public function testSoloPasaLoQueEstaDeclarado(): void
    {
        self::assertTrue(PlanCuenta::valido('pyme'));
        self::assertTrue(PlanCuenta::valido('ninguno'));

        self::assertFalse(PlanCuenta::valido('PYME'));
        self::assertFalse(PlanCuenta::valido('premium'));
        self::assertFalse(PlanCuenta::valido(''));
    }

    public function testCadaValorTieneEtiquetaClaseYAyuda(): void
    {
        foreach (PlanCuenta::claves() as $clave) {
            self::assertNotSame('', PlanCuenta::etiqueta($clave), "'{$clave}' sin etiqueta");
            self::assertStringStartsWith('tag', PlanCuenta::clase($clave), "'{$clave}' sin clase de tag");
            self::assertNotSame('', PlanCuenta::ayuda($clave), "'{$clave}' sin texto de ayuda");
        }
    }

    /**
     * La ayuda de cada plan contratado dice su precio de referencia Y que el
     * sistema no lo cobra. Lo segundo importa tanto como lo primero: un precio
     * en pantalla sin esa aclaracion se lee como si el sistema facturara.
     */
    public function testCadaPlanContratadoDeclaraPrecioYQueElSistemaNoCobra(): void
    {
        foreach (PlanCuenta::contratados() as $clave) {
            self::assertStringContainsString('UF', PlanCuenta::ayuda($clave), "el plan '{$clave}' no dice su precio");
            self::assertStringContainsString('no cobra', PlanCuenta::ayuda($clave), "el plan '{$clave}' no aclara que el sistema no cobra");
        }
    }

    public function testSoloLosTresPlanesSonUnContrato(): void
    {
        self::assertSame(['basico', 'pyme', 'pro'], PlanCuenta::contratados());

        foreach (['sin_definir', 'ninguno'] as $noContrato) {
            self::assertNotContains($noContrato, PlanCuenta::contratados());
        }
    }

    /**
     * El aviso: una cuenta que cobra o evalua tiene que decir que plan. Una
     * interna o la demo, no.
     */
    public function testAvisaCuandoUnaCuentaComercialNoDeclaraPlan(): void
    {
        self::assertTrue(PlanCuenta::incoherente('pago', 'sin_definir'));
        self::assertTrue(PlanCuenta::incoherente('pago', 'ninguno'));
        self::assertTrue(PlanCuenta::incoherente('trial', 'sin_definir'));

        self::assertFalse(PlanCuenta::incoherente('pago', 'pyme'));
        self::assertFalse(PlanCuenta::incoherente('trial', 'basico'));
        self::assertFalse(PlanCuenta::incoherente('interna', 'ninguno'));
        self::assertFalse(PlanCuenta::incoherente('demo', 'ninguno'));

        // Una interna CON plan no es un error: puede tenerlo para probar algo, y
        // por eso esto avisa en vez de prohibir.
        self::assertFalse(PlanCuenta::incoherente('interna', 'pro'));
        self::assertFalse(PlanCuenta::incoherente('sin_definir', 'sin_definir'));
    }

    public function testUnValorDesconocidoNoRevienta(): void
    {
        self::assertSame('vaya', PlanCuenta::etiqueta('vaya'));
        self::assertSame('tag', PlanCuenta::clase('vaya'));
        self::assertSame('', PlanCuenta::ayuda('vaya'));
    }

    /**
     * La lista de PHP y el ENUM del .sql tienen que declarar los mismos valores.
     * Desincronizados no falla nada hasta que alguien guarda.
     */
    public function testElEnumDeLaMigracionDiceLoMismoQueEstaClase(): void
    {
        // La ULTIMA migracion que define la columna, no un archivo escrito a
        // mano: el dia que se amplie el ENUM (como le paso a cuenta.tipo en la
        // 049), este test tiene que comparar contra la definicion nueva y no
        // seguir pasando en verde contra la vieja.
        $ultima   = null;
        $archivos = glob(__DIR__ . '/../integration/plantiflex/migrations/*.sql') ?: [];
        sort($archivos);

        foreach ($archivos as $archivo) {
            if (preg_match("/(?:ADD|MODIFY) COLUMN plan ENUM\(([^)]*)\)/", (string) file_get_contents($archivo), $m) === 1) {
                $ultima = $m[1];
            }
        }

        self::assertNotNull($ultima, 'ninguna migracion define el ENUM de cuenta.plan');

        preg_match_all("/'([a-z_]+)'/", $ultima, $valores);

        // Como conjuntos: el orden del ENUM es el de guardado y el de esta clase
        // el de presentacion (ver TipoCuentaTest, mismo criterio).
        $delSql = $valores[1];
        $delPhp = PlanCuenta::claves();
        sort($delSql);
        sort($delPhp);

        self::assertSame($delPhp, $delSql, 'la ultima migracion que define cuenta.plan y PlanCuenta ya no declaran los mismos valores');
    }

    /**
     * El precio y el tope de cada plan salen de la pagina publica de venta. Si
     * alli cambian y aqui no, el panel informa un precio que ya no existe.
     */
    public function testLosPreciosCoincidenConLaPaginaDeVenta(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../panel/public/planes.html');

        foreach (['basico' => '0,5', 'pyme' => '0,8', 'pro' => '1,5'] as $clave => $precio) {
            self::assertStringContainsString(
                $precio,
                PlanCuenta::ayuda($clave),
                "la ayuda del plan '{$clave}' no declara el precio {$precio} UF"
            );
            self::assertStringContainsString(
                '>' . $precio . '<',
                $html,
                "planes.html ya no publica {$precio} UF: el panel esta informando un precio viejo"
            );
        }
    }
}
