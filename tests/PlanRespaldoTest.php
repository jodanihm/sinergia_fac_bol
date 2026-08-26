<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\TestCase;
use PlanRespaldo;

require_once __DIR__ . '/../panel/src/AislamientoTenant.php';
require_once __DIR__ . '/../panel/src/PlanRespaldo.php';

/**
 * Tests del recorte por cliente del respaldo nocturno.
 *
 * POR QUE ESTA LOGICA TIENE TESTS Y NO ES UN PAR DE LINEAS EN EL .sh. Lo que
 * decide esta clase es QUE FILAS SON DE QUIEN. Un error aqui no se ve: produce
 * un respaldo perfectamente valido, con menos filas de las que corresponden --
 * o peor, con filas de otra empresa adentro -- y se descubre el dia que hay que
 * restaurar, que es el peor dia posible. Un WHERE de mas o de menos no lanza
 * ninguna excepcion.
 *
 * Los esquemas de estos tests son inventados y minimos a proposito: cada uno
 * aisla UNA forma de llegar a la cuenta. El plan contra el esquema real se
 * comprueba corriendo el script, no aqui.
 */
final class PlanRespaldoTest extends TestCase
{
    public function testLaTablaDeCuentasSeLlevaSuPropiaFila(): void
    {
        $plan = PlanRespaldo::construir(['cuenta'], ['cuenta' => ['id', 'nombre']], []);

        self::assertSame(PlanRespaldo::RAIZ, $plan['cuenta']['modo']);
        self::assertSame('`id` = %d', $plan['cuenta']['where']);
    }

    public function testUnaTablaConCuentaIdSeFiltraPorEsaColumna(): void
    {
        $plan = PlanRespaldo::construir(
            ['cuenta', 'cliente'],
            ['cuenta' => ['id'], 'cliente' => ['id', 'cuenta_id', 'rut']],
            []
        );

        self::assertSame(PlanRespaldo::DIRECTO, $plan['cliente']['modo']);
        self::assertSame('`cuenta_id` = %d', $plan['cliente']['where']);
    }

    /**
     * La columna manda sobre la FK declarada: hay tablas con cuenta_id y sin
     * constraint, y para recortar filas la columna alcanza.
     */
    public function testNoNecesitaQueCuentaIdTengaClaveForanea(): void
    {
        $plan = PlanRespaldo::construir(
            ['cuenta', 'suelta'],
            ['cuenta' => ['id'], 'suelta' => ['id', 'cuenta_id']],
            []
        );

        self::assertSame(PlanRespaldo::DIRECTO, $plan['suelta']['modo']);
    }

    public function testUnSaltoDeClaveForaneaSeTraduceAUnInAnidado(): void
    {
        $plan = PlanRespaldo::construir(
            ['cuenta', 'rol', 'permiso'],
            ['cuenta' => ['id'], 'rol' => ['id', 'cuenta_id'], 'permiso' => ['id', 'rol_id']],
            [['tabla' => 'permiso', 'columnas' => ['rol_id'], 'refTabla' => 'rol', 'refColumnas' => ['id']],
             ['tabla' => 'rol', 'columnas' => ['cuenta_id'], 'refTabla' => 'cuenta', 'refColumnas' => ['id']]]
        );

        self::assertSame(PlanRespaldo::INDIRECTO, $plan['permiso']['modo']);
        self::assertSame(
            '(`rol_id`) IN (SELECT `id` FROM `rol` WHERE (`cuenta_id`) IN (SELECT `id` FROM `cuenta` WHERE `id` = %d))',
            $plan['permiso']['where']
        );
    }

    /**
     * LA FK COMPUESTA ES EL CASO QUE MAS CARO SALE SI SE ROMPE. Las once tablas
     * de DTE llegan a dte_emisor por (rut_emisor, ambiente). Filtrar por la
     * mitad de la clave -- solo rut_emisor -- traeria los documentos de
     * certificacion Y los de produccion mezclados; peor todavia, filtrar solo
     * por ambiente traeria los de TODAS las empresas.
     */
    public function testUnaClaveForaneaCompuestaFiltraPorLasDosColumnas(): void
    {
        $plan = PlanRespaldo::construir(
            ['cuenta', 'dte_emisor', 'dte_emitido'],
            [
                'cuenta'      => ['id'],
                'dte_emisor'  => ['id', 'cuenta_id', 'rut_emisor', 'ambiente'],
                'dte_emitido' => ['id', 'rut_emisor', 'ambiente', 'folio'],
            ],
            [
                ['tabla' => 'dte_emitido', 'columnas' => ['rut_emisor', 'ambiente'],
                 'refTabla' => 'dte_emisor', 'refColumnas' => ['rut_emisor', 'ambiente']],
                ['tabla' => 'dte_emisor', 'columnas' => ['cuenta_id'], 'refTabla' => 'cuenta', 'refColumnas' => ['id']],
            ]
        );

        self::assertSame(
            '(`rut_emisor`, `ambiente`) IN (SELECT `rut_emisor`, `ambiente` FROM `dte_emisor` '
            . 'WHERE (`cuenta_id`) IN (SELECT `id` FROM `cuenta` WHERE `id` = %d))',
            $plan['dte_emitido']['where']
        );
    }

    /**
     * EL PUENTE DEL DISCRIMINADOR SE ELIGE, NO SE TOMA EL PRIMERO. Contra el
     * esquema real, "la primera tabla con rut_emisor y cuenta_id" es
     * cotizacion_factura, que solo guarda los RUT de las facturas que salieron
     * de una cotizacion: puentear por ahi da un respaldo con MENOS filas y sin
     * ningun error. La tabla correcta es la que las demas referencian por esa
     * columna.
     */
    public function testElPuenteDelDiscriminadorEsLaTablaMaestraYNoLaPrimera(): void
    {
        $plan = PlanRespaldo::construir(
            ['cuenta', 'cotizacion_factura', 'dte_emisor', 'dte_emitido', 'dte_logo'],
            [
                'cuenta'             => ['id'],
                // Alfabeticamente va antes que dte_emisor, y tiene las dos columnas.
                'cotizacion_factura' => ['id', 'cuenta_id', 'rut_emisor'],
                'dte_emisor'         => ['id', 'cuenta_id', 'rut_emisor', 'ambiente'],
                'dte_emitido'        => ['id', 'rut_emisor', 'ambiente'],
                // Sin ambiente: no pudo recibir la FK de la migracion 045.
                'dte_logo'           => ['id', 'rut_emisor', 'imagen'],
            ],
            [
                ['tabla' => 'dte_emitido', 'columnas' => ['rut_emisor', 'ambiente'],
                 'refTabla' => 'dte_emisor', 'refColumnas' => ['rut_emisor', 'ambiente']],
            ]
        );

        self::assertSame(PlanRespaldo::DISCRIMINADOR, $plan['dte_logo']['modo']);
        self::assertSame(
            '`rut_emisor` IN (SELECT `rut_emisor` FROM `dte_emisor` WHERE `cuenta_id` = %d)',
            $plan['dte_logo']['where']
        );
    }

    /**
     * EL CASO QUE ROMPIO LA PRIMERA CORRIDA CONTRA LA BASE REAL. El esquema
     * quedo partido en dos collations (ver la migracion 045) y el puente del
     * discriminador es la unica comparacion que se hace sin una clave foranea
     * que garantice que las dos puntas coinciden. Sin el COLLATE, MySQL corta
     * con "Illegal mix of collations (1267)" y ese cliente se queda sin
     * respaldo esa noche.
     */
    public function testElPuenteLlevaCollateCuandoLasDosColumnasNoCoinciden(): void
    {
        $plan = PlanRespaldo::construir(
            ['cuenta', 'dte_emisor', 'dte_emitido_bak'],
            [
                'cuenta'          => ['id'],
                'dte_emisor'      => ['id', 'cuenta_id', 'rut_emisor'],
                'dte_emitido_bak' => ['id', 'rut_emisor'],
            ],
            [],
            [
                'dte_emisor.rut_emisor'      => 'utf8mb4_unicode_ci',
                'dte_emitido_bak.rut_emisor' => 'utf8mb4_0900_ai_ci',
            ]
        );

        self::assertSame(
            '`rut_emisor` COLLATE utf8mb4_unicode_ci IN (SELECT `rut_emisor` FROM `dte_emisor` WHERE `cuenta_id` = %d)',
            $plan['dte_emitido_bak']['where'],
            'manda la collation de la tabla maestra, que es la que tiene el dato bueno'
        );
    }

    /**
     * Y no lo lleva cuando no hace falta: un COLLATE en todas las
     * comparaciones ensuciaria el plan y escondería el caso raro entre el ruido.
     */
    public function testElPuenteNoLlevaCollateSiLasDosColumnasYaCoinciden(): void
    {
        $plan = PlanRespaldo::construir(
            ['cuenta', 'dte_emisor', 'dte_logo'],
            [
                'cuenta'     => ['id'],
                'dte_emisor' => ['id', 'cuenta_id', 'rut_emisor'],
                'dte_logo'   => ['id', 'rut_emisor'],
            ],
            [],
            [
                'dte_emisor.rut_emisor' => 'utf8mb4_unicode_ci',
                'dte_logo.rut_emisor'   => 'utf8mb4_unicode_ci',
            ]
        );

        self::assertStringNotContainsString('COLLATE', (string) $plan['dte_logo']['where']);
    }

    /**
     * Datos de un contribuyente sin ninguna forma de saber de cual. No se
     * respalda y se denuncia: es el unico desenlace que no puede quedar callado.
     */
    public function testUnaTablaQueNoSePuedeRecortarQuedaMarcadaComoAlarma(): void
    {
        $plan = PlanRespaldo::construir(
            ['cuenta', 'huerfana'],
            ['cuenta' => ['id'], 'huerfana' => ['id', 'rut_emisor']],
            []
        );

        self::assertSame(PlanRespaldo::SIN_MAPA, $plan['huerfana']['modo']);
        self::assertNull($plan['huerfana']['where']);
    }

    public function testUnaTablaQueNoEsDeNadieQuedaFuera(): void
    {
        $plan = PlanRespaldo::construir(
            ['cuenta', 'catalogo_comunas'],
            ['cuenta' => ['id'], 'catalogo_comunas' => ['id', 'nombre']],
            []
        );

        self::assertSame(PlanRespaldo::GLOBAL, $plan['catalogo_comunas']['modo']);
        self::assertNull($plan['catalogo_comunas']['where']);
    }

    /**
     * LAS TABLAS DE LA CASA NO VIAJAN EN EL RESPALDO DE UN CLIENTE aunque el
     * grafo encuentre un camino. admin_auditoria guarda el usuario_id de quien
     * hizo cada cosa, y ese usuario pertenece a la cuenta interna: sin esta
     * regla, el changelog administrativo de TODAS las empresas terminaria dentro
     * del respaldo de una.
     */
    public function testLasTablasDeLaCasaQuedanFueraAunqueLleguenACuenta(): void
    {
        $plan = PlanRespaldo::construir(
            ['cuenta', 'usuario', 'admin_auditoria', 'admin_actividad', 'pendiente'],
            [
                'cuenta'          => ['id'],
                'usuario'         => ['id', 'cuenta_id'],
                'admin_auditoria' => ['id', 'usuario_id'],
                'admin_actividad' => ['id', 'usuario_id'],
                'pendiente'       => ['id', 'titulo'],
            ],
            [
                ['tabla' => 'admin_auditoria', 'columnas' => ['usuario_id'], 'refTabla' => 'usuario', 'refColumnas' => ['id']],
                ['tabla' => 'admin_actividad', 'columnas' => ['usuario_id'], 'refTabla' => 'usuario', 'refColumnas' => ['id']],
                ['tabla' => 'usuario', 'columnas' => ['cuenta_id'], 'refTabla' => 'cuenta', 'refColumnas' => ['id']],
            ]
        );

        foreach (PlanRespaldo::DE_LA_CASA as $tabla) {
            self::assertSame(PlanRespaldo::GLOBAL, $plan[$tabla]['modo'], "{$tabla} tendria que quedar fuera");
            self::assertNull($plan[$tabla]['where']);
        }

        // El usuario si viaja: es de la cuenta.
        self::assertSame(PlanRespaldo::DIRECTO, $plan['usuario']['modo']);
    }

    /**
     * Un ciclo de claves foraneas -- que existe de verdad entre dte_emisor y sus
     * tablas -- no puede dejar la busqueda girando: seria un cron colgado a las
     * 03:40 y sin respaldo esa noche.
     */
    public function testUnCicloDeClavesForaneasNoCuelgaLaBusqueda(): void
    {
        $plan = PlanRespaldo::construir(
            ['cuenta', 'a', 'b'],
            ['cuenta' => ['id'], 'a' => ['id', 'b_id'], 'b' => ['id', 'a_id']],
            [
                ['tabla' => 'a', 'columnas' => ['b_id'], 'refTabla' => 'b', 'refColumnas' => ['id']],
                ['tabla' => 'b', 'columnas' => ['a_id'], 'refTabla' => 'a', 'refColumnas' => ['id']],
            ]
        );

        self::assertSame(PlanRespaldo::GLOBAL, $plan['a']['modo']);
        self::assertSame(PlanRespaldo::GLOBAL, $plan['b']['modo']);
    }

    /** Una FK que apunta a algo que no esta en la lista no es un camino verificado. */
    public function testUnaFkHaciaUnaTablaDesconocidaNoCuentaComoCamino(): void
    {
        $plan = PlanRespaldo::construir(
            ['cuenta', 'x'],
            ['cuenta' => ['id'], 'x' => ['id', 'externo_id']],
            [['tabla' => 'x', 'columnas' => ['externo_id'], 'refTabla' => 'otra_base', 'refColumnas' => ['id']]]
        );

        self::assertSame(PlanRespaldo::GLOBAL, $plan['x']['modo']);
    }
}
