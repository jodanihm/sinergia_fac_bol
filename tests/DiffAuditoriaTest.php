<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use DiffAuditoria;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../panel/src/DiffAuditoria.php';

/**
 * Tests del diff de admin_auditoria.
 *
 * DiffAuditoria vive en panel/src/, que no esta en el autoload PSR-4 (ese
 * cubre solo src/, el motor). Se carga con un require_once explicito, igual
 * que hace tests/FechaExcelTest.php con FechaExcel.
 *
 * Lo que se prueba es la propiedad que justifica la clase: de un snapshot
 * completo salen SOLO las claves que cambiaron. Y los casos que en produccion
 * llegan de verdad: la creacion (valor_anterior null), la eliminacion
 * (valor_nuevo null) y la fila que no se puede leer, que existe porque
 * admin_auditoria es append-only y una fila mala no se puede corregir.
 */
final class DiffAuditoriaTest extends TestCase
{
    public function testSoloDevuelveLasClavesQueCambiaron(): void
    {
        // El caso real de POST /admin/tenants/suspender: se guarda la fila
        // cuenta entera y lo unico que se movio es 'estado'.
        $antes = json_encode([
            'id' => 7, 'email' => 'a@b.cl', 'nombre' => 'Empresa',
            'estado' => 'activa', 'created_at' => '2026-01-02 03:04:05',
        ]);
        $despues = json_encode([
            'id' => 7, 'email' => 'a@b.cl', 'nombre' => 'Empresa',
            'estado' => 'suspendida', 'created_at' => '2026-01-02 03:04:05',
        ]);

        $diff = DiffAuditoria::comparar($antes, $despues);

        $this->assertTrue($diff['legible']);
        $this->assertSame(
            [['clave' => 'estado', 'antes' => 'activa', 'despues' => 'suspendida']],
            $diff['cambios']
        );
    }

    public function testDistingueUnNullDelJsonDeUnaClaveAusente(): void
    {
        // El caso real de POST /admin/tenants/revertir-etapa: el campo pasa de
        // una fecha a NULL. Ese null tiene que verse como valor, no como
        // "la clave no estaba".
        $diff = DiffAuditoria::comparar(
            json_encode(['simulacion_confirmada_at' => '2026-05-05 10:00:00']),
            json_encode(['simulacion_confirmada_at' => null])
        );

        $this->assertSame(
            [['clave' => 'simulacion_confirmada_at', 'antes' => '2026-05-05 10:00:00', 'despues' => 'null']],
            $diff['cambios']
        );

        // Clave que solo existe de un lado: ahi si va el null de PHP.
        $diff = DiffAuditoria::comparar(json_encode([]), json_encode(['nueva' => 1]));
        $this->assertSame([['clave' => 'nueva', 'antes' => null, 'despues' => '1']], $diff['cambios']);

        $diff = DiffAuditoria::comparar(json_encode(['vieja' => 1]), json_encode([]));
        $this->assertSame([['clave' => 'vieja', 'antes' => '1', 'despues' => null]], $diff['cambios']);
    }

    public function testColumnaNullEsUnCasoValidoYNoUnError(): void
    {
        // valor_anterior null = creacion; valor_nuevo null = eliminacion.
        $creacion = DiffAuditoria::comparar(null, json_encode(['nombre' => 'Nueva']));
        $this->assertTrue($creacion['legible']);
        $this->assertSame([['clave' => 'nombre', 'antes' => null, 'despues' => 'Nueva']], $creacion['cambios']);

        $borrado = DiffAuditoria::comparar(json_encode(['nombre' => 'Vieja']), null);
        $this->assertTrue($borrado['legible']);
        $this->assertSame([['clave' => 'nombre', 'antes' => 'Vieja', 'despues' => null]], $borrado['cambios']);

        $ambas = DiffAuditoria::comparar(null, null);
        $this->assertTrue($ambas['legible']);
        $this->assertSame([], $ambas['cambios']);
    }

    public function testSinCambiosDevuelveListaVacia(): void
    {
        $igual = json_encode(['estado' => 'activa', 'id' => 1]);

        $this->assertSame([], DiffAuditoria::comparar($igual, $igual)['cambios']);
    }

    public function testJsonIlegibleCaeAlLadoSeguroYNoInventaCambios(): void
    {
        foreach (['{no soy json', '"un string suelto"', '42'] as $basura) {
            $diff = DiffAuditoria::comparar($basura, json_encode(['a' => 1]));

            $this->assertFalse($diff['legible'], "deberia ser ilegible: {$basura}");
            $this->assertSame([], $diff['cambios']);
        }
    }

    public function testComparaSobreElTextoQueSeMuestra(): void
    {
        // 1 (int) y "1" (string) se ven igual en pantalla: marcarlos como
        // cambio mostraria dos veces lo mismo y haria dudar de todo el diff.
        $diff = DiffAuditoria::comparar(json_encode(['n' => 1]), json_encode(['n' => '1']));
        $this->assertSame([], $diff['cambios']);

        // Un valor anidado se muestra como su JSON compacto.
        $diff = DiffAuditoria::comparar(
            json_encode(['permisos' => ['ver']]),
            json_encode(['permisos' => ['ver', 'gestionar']])
        );
        $this->assertSame(
            [['clave' => 'permisos', 'antes' => '["ver"]', 'despues' => '["ver","gestionar"]']],
            $diff['cambios']
        );
    }
}
