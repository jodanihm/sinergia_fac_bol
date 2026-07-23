<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Sii;

use Plantiflex\FacturacionCl\Dto\LibroPayloadResultado;

/**
 * Construye el body de POST /api/v1/libro (Libro de Ventas) a partir de los
 * documentos YA EMITIDOS del Set Basico aprobado (dte_emitido), NO del
 * archivo SIISetDePruebas -- el Libro de Ventas reporta lo que el propio
 * tenant emitio, no lo que el SII dicto para comprar.
 *
 * Regla "exento por diferencia": dte_emitido no separa el monto exento de un
 * documento con items exentos (solo guarda neto/iva/total agregados), asi que
 * mntExe se recalcula como total - neto - iva. Regla ya validada en
 * produccion (payload_libro_ventas_v2.json, aceptado por el SII con LOK/LTC/SOK).
 */
final class LibroVentasPayloadBuilder
{
    /**
     * Receptor de prueba: el MISMO fijo que usa SetBasicoPayloadBuilder para
     * todos los documentos del set basico (66666666-6 / Cliente de Prueba).
     * dte_emitido guarda receptor_rut pero no razonSocial, y ese RUT siempre
     * es este mismo (es el unico receptor del set basico de certificacion),
     * asi que se usa el mismo par fijo en vez de ir a buscarlo.
     */
    private const RECEPTOR_RUT = '66666666-6';
    private const RECEPTOR_RAZON_SOCIAL = 'Cliente de Prueba';

    /**
     * IMPORTANTE: preserva el orden de $documentosEmitidos TAL CUAL lo recibe
     * -- NO reordena por tipoDte/folio. Evidencia real (payload_libro_ventas_v2.json,
     * aceptado LOK/LTC/SOK): el orden de las lineas es el orden de EMISION del
     * lote (FACTURA x4, NOTA_CREDITO x3, NOTA_DEBITO x1), no un orden numerico
     * por tipoDte -- un intento inicial de este builder ordenaba por
     * "tipoDte ASC, folio ASC" (mismo criterio que listarEmitidosFactura() del
     * panel) y NO reprodujo el payload aceptado (la ND tipo 56 quedo antes de
     * las NC tipo 61, cuando el SII acepto el orden con la ND al final). El
     * llamador debe consultar dte_emitido en el orden real de emision (ej.
     * "ORDER BY id ASC").
     *
     * @param list<array{tipoDte:int, folio:int, fechaEmision:string, neto:int, iva:int, total:int}> $documentosEmitidos
     */
    public function construir(array $documentosEmitidos, int $folioNotificacion): LibroPayloadResultado
    {
        if ($documentosEmitidos === []) {
            return new LibroPayloadResultado(null, ['No hay documentos emitidos del Set Basico para construir el Libro de Ventas.']);
        }

        $docs = $documentosEmitidos;

        $periodos = array_unique(array_map(static fn (array $d): string => substr($d['fechaEmision'], 0, 7), $docs));
        if (count($periodos) !== 1) {
            return new LibroPayloadResultado(null, [
                'Los documentos emitidos abarcan mas de un periodo tributario (' . implode(', ', $periodos) . '); '
                . 'no se puede determinar un unico periodoTributario sin adivinar.',
            ]);
        }

        $lineas = [];
        foreach ($docs as $d) {
            $lineas[] = [
                'tpoDoc'         => $d['tipoDte'],
                'nroDoc'         => $d['folio'],
                'fecha'          => $d['fechaEmision'],
                'rutContraparte' => self::RECEPTOR_RUT,
                'razonSocial'    => self::RECEPTOR_RAZON_SOCIAL,
                'mntExe'         => $d['total'] - $d['neto'] - $d['iva'],
                'mntNeto'        => $d['neto'],
                'mntIva'         => $d['iva'],
                'mntTotal'       => $d['total'],
            ];
        }

        return new LibroPayloadResultado([
            'tipoOperacion'     => 'VENTA',
            'periodoTributario' => $periodos[0],
            'tipoLibro'         => 'ESPECIAL',
            'tipoEnvio'         => 'TOTAL',
            'folioNotificacion' => $folioNotificacion,
            'lineas'            => $lineas,
        ], []);
    }
}
