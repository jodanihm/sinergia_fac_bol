<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Sii\EstadoContable;

/**
 * EstadoContable NO conoce los contadores del sobre, y este test existe para
 * que siga sin conocerlos.
 *
 * POR QUE. Los contadores del RESP_BODY (migracion 030) dicen CUANTOS documentos
 * rechazo u observo el SII, no CUALES. Excluir de los totales el bloque entero
 * se llevaria por delante los documentos buenos: de seis notas de credito con
 * tres observadas, saldrian las seis. Eso le mentiria al cliente sobre su
 * facturacion en la direccion contraria a la que este archivo existe para
 * arreglar, y por eso la entrega de los contadores DETECTA Y AVISA pero no toca
 * ningun total.
 *
 * Se afinara cuando se pueda identificar el folio, con getEstDte, y sea una
 * exclusion por documento en vez de por bloque.
 *
 * Mientras tanto lo que se vigila aqui es que los dos fragmentos de WHERE sigan
 * siendo EXACTAMENTE los de siempre: se concatenan en siete consultas de dos
 * front controllers, y cualquier condicion de mas cambia todas las cifras del
 * dashboard a la vez.
 */
final class EstadoContableContadoresTest extends TestCase
{
    private const LISTA = "'RCT', 'RCH', 'RCO', 'RFR', 'RSC', 'RPT', 'VOF', 'ANC', 'DNK'";

    public function testElFragmentoDeExclusionSoloMiraElEstado(): void
    {
        self::assertSame(
            ' AND estado NOT IN (' . self::LISTA . ') ',
            EstadoContable::sqlExcluirRechazados(),
        );
    }

    public function testElFragmentoInversoSoloMiraElEstado(): void
    {
        self::assertSame(
            ' AND estado IN (' . self::LISTA . ') ',
            EstadoContable::sqlSoloRechazados(),
        );
    }

    /**
     * Ninguna columna de la migracion 030 puede aparecer en estos fragmentos.
     * Si alguna se cuela, un sobre EPR con reparos empezaria a restar ventas
     * validas sin que nadie lo haya decidido.
     */
    public function testNingunFragmentoMencionaLosContadores(): void
    {
        foreach (['sii_informados', 'sii_aceptados', 'sii_rechazados', 'sii_reparos'] as $columna) {
            self::assertStringNotContainsString($columna, EstadoContable::sqlExcluirRechazados(), $columna);
            self::assertStringNotContainsString($columna, EstadoContable::sqlSoloRechazados(), $columna);
        }
    }

    /**
     * RPR ("Aceptado con Reparos") CUENTA como venta, y tiene que seguir
     * contando: el documento es valido y la venta existe. Es ademas el caso que
     * mejor muestra que las dos clasificaciones de la casa apuntan en direcciones
     * opuestas -- para el AVISO, RPR si dispara correo.
     */
    public function testRprSigueContandoComoVenta(): void
    {
        self::assertFalse(EstadoContable::esRechazado('RPR'));
        self::assertNotContains('RPR', EstadoContable::ESTADOS_RECHAZADOS);
        self::assertStringNotContainsString("'RPR'", EstadoContable::sqlExcluirRechazados());
    }

    /** Y EPR tampoco esta en la lista: hay sobres EPR perfectamente limpios. */
    public function testEprCuentaComoAntes(): void
    {
        self::assertFalse(EstadoContable::esRechazado('EPR'));
        self::assertFalse(EstadoContable::esRechazado('enviado'));
        self::assertFalse(EstadoContable::esRechazado('desconocido'));
        self::assertTrue(EstadoContable::esRechazado('RCT'));
    }
}
