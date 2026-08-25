<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use AislamientoTenant;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../panel/src/AislamientoTenant.php';

/**
 * Tests de la clasificacion de aislamiento multi-tenant.
 *
 * AislamientoTenant vive en panel/src/, fuera del autoload PSR-4 (que cubre
 * solo src/, el motor), asi que se carga con un require_once explicito -- mismo
 * patron que FechaExcelTest y DiffAuditoriaTest.
 *
 * Lo que se prueba con mas insistencia es el recorrido del grafo: los ciclos
 * son la unica forma que tiene esta clase de colgar el panel, y un esquema
 * real los tiene.
 */
final class AislamientoTenantTest extends TestCase
{
    /** El caso trivial: la tabla de cuentas es el dueno, no cuelga de nadie. */
    public function testLaTablaDeCuentasEsLaRaiz(): void
    {
        $r = AislamientoTenant::clasificar(['cuenta'], ['cuenta' => ['id', 'nombre']], []);

        $this->assertSame(AislamientoTenant::RAIZ, $r['cuenta']['clase']);
    }

    public function testUnaColumnaCuentaIdEsAislamientoDirecto(): void
    {
        $r = AislamientoTenant::clasificar(
            ['cuenta', 'usuario'],
            ['cuenta' => ['id'], 'usuario' => ['id', 'cuenta_id', 'email']],
            []
        );

        $this->assertSame(AislamientoTenant::DIRECTO, $r['usuario']['clase']);
        // Directo no necesita camino: el WHERE esta a la vista en la propia tabla.
        $this->assertSame([], $r['usuario']['camino']);
    }

    public function testSeSigueLaCadenaDeClavesForaneasHastaCuenta(): void
    {
        // El caso real del repo: permiso -> rol -> cuenta.
        $r = AislamientoTenant::clasificar(
            ['cuenta', 'rol', 'permiso'],
            ['cuenta' => ['id'], 'rol' => ['id', 'cuenta_id'], 'permiso' => ['rol_id', 'modulo', 'accion']],
            [
                ['tabla' => 'rol',     'columna' => 'cuenta_id', 'refTabla' => 'cuenta'],
                ['tabla' => 'permiso', 'columna' => 'rol_id',    'refTabla' => 'rol'],
            ]
        );

        $this->assertSame(AislamientoTenant::INDIRECTO, $r['permiso']['clase']);
        $this->assertSame(
            ['permiso.rol_id -> rol', 'rol.cuenta_id -> cuenta'],
            $r['permiso']['camino']
        );
    }

    public function testDistingueSinRutaDeGlobalPorElDiscriminador(): void
    {
        // Las dos son iguales para el grafo (no hay camino). Lo que las separa
        // es si la tabla guarda datos de un contribuyente.
        $r = AislamientoTenant::clasificar(
            ['cuenta', 'dte_emitido', 'catalogo_comunas'],
            [
                'cuenta'           => ['id'],
                'dte_emitido'      => ['id', 'rut_emisor', 'folio'],
                'catalogo_comunas' => ['codigo', 'nombre'],
            ],
            []
        );

        $this->assertSame(AislamientoTenant::SIN_RUTA, $r['dte_emitido']['clase']);
        $this->assertSame(AislamientoTenant::GLOBAL, $r['catalogo_comunas']['clase']);
    }

    /**
     * EL TEST QUE MAS IMPORTA. Sin marcar lo visitado, un ciclo deja el BFS
     * dando vueltas para siempre y la pagina no responde nunca. Un esquema real
     * tiene ciclos, asi que esto no es un caso hipotetico.
     */
    public function testUnCicloNoDejaElRecorridoDandoVueltas(): void
    {
        $r = AislamientoTenant::clasificar(
            ['cuenta', 'a', 'b', 'c'],
            ['cuenta' => ['id'], 'a' => ['id'], 'b' => ['id'], 'c' => ['id']],
            [
                ['tabla' => 'a', 'columna' => 'b_id', 'refTabla' => 'b'],
                ['tabla' => 'b', 'columna' => 'c_id', 'refTabla' => 'c'],
                ['tabla' => 'c', 'columna' => 'a_id', 'refTabla' => 'a'],  // cierra el ciclo
            ]
        );

        // Termina, y ninguna llega a cuenta.
        $this->assertSame(AislamientoTenant::GLOBAL, $r['a']['clase']);
        $this->assertSame(AislamientoTenant::GLOBAL, $r['b']['clase']);
        $this->assertSame(AislamientoTenant::GLOBAL, $r['c']['clase']);
    }

    public function testUnaTablaQueSeApuntaASiMismaTampocoCuelga(): void
    {
        $r = AislamientoTenant::clasificar(
            ['cuenta', 'arbol'],
            ['cuenta' => ['id'], 'arbol' => ['id', 'padre_id']],
            [['tabla' => 'arbol', 'columna' => 'padre_id', 'refTabla' => 'arbol']]
        );

        $this->assertSame(AislamientoTenant::GLOBAL, $r['arbol']['clase']);
    }

    public function testDevuelveElCaminoMasCortoCuandoHayVarios(): void
    {
        // hoja llega a cuenta por un salto o por tres; se muestra el de uno,
        // que es el JOIN que alguien escribiria de verdad.
        $r = AislamientoTenant::clasificar(
            ['cuenta', 'hoja', 'medio1', 'medio2'],
            ['cuenta' => ['id'], 'hoja' => ['id'], 'medio1' => ['id'], 'medio2' => ['id']],
            [
                ['tabla' => 'hoja',   'columna' => 'medio1_id', 'refTabla' => 'medio1'],
                ['tabla' => 'medio1', 'columna' => 'medio2_id', 'refTabla' => 'medio2'],
                ['tabla' => 'medio2', 'columna' => 'cuenta_id', 'refTabla' => 'cuenta'],
                ['tabla' => 'hoja',   'columna' => 'cuenta_id', 'refTabla' => 'cuenta'],
            ]
        );

        $this->assertSame(AislamientoTenant::INDIRECTO, $r['hoja']['clase']);
        $this->assertSame(['hoja.cuenta_id -> cuenta'], $r['hoja']['camino']);
    }

    public function testUnaForaneaHaciaAfueraDelEsquemaNoCuentaComoCamino(): void
    {
        // Si el destino no esta en la lista de tablas inspeccionadas, el camino
        // no se puede verificar y por lo tanto no se afirma.
        $r = AislamientoTenant::clasificar(
            ['cuenta', 'suelta'],
            ['cuenta' => ['id'], 'suelta' => ['id', 'externa_id']],
            [['tabla' => 'suelta', 'columna' => 'externa_id', 'refTabla' => 'tabla_de_otro_schema']]
        );

        $this->assertSame(AislamientoTenant::GLOBAL, $r['suelta']['clase']);
    }

    /**
     * LA FORMA QUE DEJO LA MIGRACION 045, y el caso por el que 'restriccion'
     * existe. Las tablas de DTE llegan a dte_emisor por una clave foranea
     * COMPUESTA (rut_emisor, ambiente), que information_schema entrega como dos
     * filas distintas. Si no se vuelven a juntar por el nombre de la
     * constraint, el recorrido toma una sola de las dos y rotula el salto con
     * la columna equivocada -- decia "dte_emitido.ambiente -> dte_emisor",
     * que describe un camino real de una forma que no sirve para escribir el
     * JOIN.
     */
    public function testUnaForaneaCompuestaEsUnSoloSaltoConSusDosColumnas(): void
    {
        $r = AislamientoTenant::clasificar(
            ['cuenta', 'dte_emisor', 'dte_emitido'],
            [
                'cuenta'      => ['id'],
                'dte_emisor'  => ['id', 'rut_emisor', 'cuenta_id', 'ambiente'],
                'dte_emitido' => ['id', 'rut_emisor', 'ambiente', 'folio'],
            ],
            [
                ['tabla' => 'dte_emisor',  'columna' => 'cuenta_id',  'refTabla' => 'cuenta',     'restriccion' => 'fk_emisor_cuenta'],
                ['tabla' => 'dte_emitido', 'columna' => 'rut_emisor', 'refTabla' => 'dte_emisor', 'restriccion' => 'fk_emitido_emisor'],
                ['tabla' => 'dte_emitido', 'columna' => 'ambiente',   'refTabla' => 'dte_emisor', 'restriccion' => 'fk_emitido_emisor'],
            ]
        );

        // Deja de ser 'sin_ruta' aunque no tenga cuenta_id: ahora el motor
        // impone el camino, que es exactamente lo que la 045 fue a comprar.
        $this->assertSame(AislamientoTenant::INDIRECTO, $r['dte_emitido']['clase']);
        $this->assertSame(
            ['dte_emitido.(rut_emisor, ambiente) -> dte_emisor', 'dte_emisor.cuenta_id -> cuenta'],
            $r['dte_emitido']['camino']
        );
    }

    /**
     * El reverso del anterior: agrupar por (tabla, tabla destino) a secas seria
     * mas simple y estaria mal. Dos claves foraneas DISTINTAS de la misma tabla
     * a la misma tabla destino son dos caminos, no una clave de dos columnas, y
     * fundirlas inventaria un salto '(creador_id, aprobador_id)' que no existe.
     */
    public function testDosForaneasDistintasAlMismoDestinoNoSeFunden(): void
    {
        $r = AislamientoTenant::clasificar(
            ['cuenta', 'usuario', 'documento'],
            [
                'cuenta'    => ['id'],
                'usuario'   => ['id', 'cuenta_id'],
                'documento' => ['id', 'creador_id', 'aprobador_id'],
            ],
            [
                ['tabla' => 'usuario',   'columna' => 'cuenta_id',    'refTabla' => 'cuenta',  'restriccion' => 'fk_usuario_cuenta'],
                ['tabla' => 'documento', 'columna' => 'creador_id',   'refTabla' => 'usuario', 'restriccion' => 'fk_doc_creador'],
                ['tabla' => 'documento', 'columna' => 'aprobador_id', 'refTabla' => 'usuario', 'restriccion' => 'fk_doc_aprobador'],
            ]
        );

        $this->assertSame(AislamientoTenant::INDIRECTO, $r['documento']['clase']);
        // Una sola columna por salto, sin parentesis: son dos caminos de largo
        // 2 y el BFS se queda con el primero.
        $this->assertSame(
            ['documento.creador_id -> usuario', 'usuario.cuenta_id -> cuenta'],
            $r['documento']['camino']
        );
    }

    /**
     * La compatibilidad hacia atras que hace que este cambio no toque a nadie
     * mas: sin 'restriccion', cada fila sigue siendo su propia clave foranea.
     * Es lo correcto mientras todas sean de una columna, y es como llamaban a
     * clasificar() los tests de mas arriba antes de que la 045 existiera.
     */
    public function testSinNombreDeConstraintCadaColumnaSigueSiendoUnaForanea(): void
    {
        $r = AislamientoTenant::clasificar(
            ['cuenta', 'rol', 'permiso'],
            ['cuenta' => ['id'], 'rol' => ['id', 'cuenta_id'], 'permiso' => ['rol_id']],
            [
                ['tabla' => 'rol',     'columna' => 'cuenta_id', 'refTabla' => 'cuenta'],
                ['tabla' => 'permiso', 'columna' => 'rol_id',    'refTabla' => 'rol'],
            ]
        );

        $this->assertSame(
            ['permiso.rol_id -> rol', 'rol.cuenta_id -> cuenta'],
            $r['permiso']['camino']
        );
    }
}
