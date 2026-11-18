<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Sii;

use DOMElement;

/**
 * "Rehacer" un documento que el SII rechazo: sus datos, listos para el
 * formulario de emision.
 *
 * ------------------------------------------------------------------------
 * POR QUE EXISTE: el folio 43 de EASY AGENDA (factura, 12-11-2026) volvio con
 * RFR. Un documento rechazado NO existe para el SII -- no hay nada que anular
 * con nota de credito --, asi que lo unico que queda es emitirlo de nuevo, con
 * otro folio. Antes habia que retipear receptor y lineas mirando el PDF.
 *
 * NO EMITE NADA. Arma el arreglo $form que entiende renderEmisionForm(): el
 * mismo formulario de siempre, editable, con su propia confirmacion. Mismo
 * criterio que "Facturar cotizacion" y que "Anular con nota de credito".
 *
 * LA FUENTE ES EL XML QUE SE ENVIO, no la fila de dte_emitido: la fila tiene
 * totales, pero las lineas, la direccion o la forma de pago solo estan en el
 * XML. Se ubica EL documento pedido dentro del sobre (DocumentoDelSobre), nunca
 * el primero.
 * ------------------------------------------------------------------------
 */
final class RehacerDocumento
{
    /**
     * Estados en los que se ofrece rehacer. Son los RECHAZOS DEL SII: el
     * documento no quedo emitido y volver a emitirlo no duplica una venta.
     *
     * Sale de EstadoContable::ESTADOS_RECHAZADOS, pero NO es esa lista, y cada
     * diferencia es a proposito:
     *
     *   RPT  "Envio repetido rechazado": se rechazo la COPIA; el original
     *        probablemente SI entro. Rehacerlo duplicaria la venta.
     *   DNK  "Datos no coinciden": el SII TIENE ese folio, con otros datos. No
     *        es un rechazo, es una discrepancia que hay que mirar a mano.
     *   ANC  Anulado: el documento existio y se anulo con una nota. No se
     *        "rehace" -- si hace falta, se emite uno nuevo desde cero.
     *
     * @var list<string>
     */
    public const ESTADOS = ['RCT', 'RCH', 'RCO', 'RFR', 'RSC', 'VOF'];

    /** Tipos que el formulario de emision sabe emitir. */
    public const TIPOS = [33, 34, 39, 56, 61];

    /** RUT generico de la boleta sin receptor: en el formulario va vacio. */
    private const RUT_CONSUMIDOR_FINAL = '66666666-6';

    public static function permite(int $tipoDte, ?string $estado): bool
    {
        return in_array($tipoDte, self::TIPOS, true)
            && in_array(trim((string) $estado), self::ESTADOS, true);
    }

    /**
     * El $form para renderEmisionForm(), con los datos del documento $tipoDte/
     * $folio que viene dentro de $envioXml.
     *
     * @return array<string,mixed>
     *
     * @throws \RuntimeException si el XML esta vacio, no parsea o no trae ese
     *         documento (ver DocumentoDelSobre::ubicar).
     */
    public static function formulario(string $envioXml, int $tipoDte, int $folio): array
    {
        $doc = DocumentoDelSobre::ubicar($envioXml, $tipoDte, $folio);
        $txt = static fn (DOMElement $ambito, string $tag): string => DocumentoDelSobre::texto($ambito, $tag);

        $idDoc = self::hijo($doc, 'IdDoc') ?? $doc;

        // NETO O BRUTO: dice que significa PrcItem. Factura: MntBruto=1 es bruto.
        // Boleta: al reves, bruto salvo IndMntNeto=2.
        $brutos = $tipoDte === 39
            ? $txt($idDoc, 'IndMntNeto') !== '2'
            : $txt($idDoc, 'MntBruto') === '1';

        $rut = $txt($doc, 'RUTRecep');
        $form = [
            'receptor' => [
                'rut'         => $rut === self::RUT_CONSUMIDOR_FINAL ? '' : $rut,
                'razonSocial' => $txt($doc, 'RznSocRecep'),
                'giro'        => $txt($doc, 'GiroRecep'),
                'direccion'   => $txt($doc, 'DirRecep'),
                'comuna'      => $txt($doc, 'CmnaRecep'),
                'email'       => '',
            ],
            'detalles' => [],
        ];

        foreach (DocumentoDelSobre::detalles($doc) as $det) {
            $exento = $txt($det, 'IndExe') === '1';
            $qty    = $txt($det, 'QtyItem');
            $prc    = $txt($det, 'PrcItem');
            $monto  = $txt($det, 'MontoItem');

            // DESCUENTO DE LINEA: el formulario no tiene columna para el. Para
            // que el documento rehecho cobre lo mismo, el precio que se ofrece
            // es el ya descontado (MontoItem / cantidad).
            if ($prc !== '' && $txt($det, 'DescuentoPct') !== '' && is_numeric($monto)) {
                $cant = is_numeric($qty) && (float) $qty > 0 ? (float) $qty : 1.0;
                $prc  = self::num((float) $monto / $cant);
            }

            $linea = [
                'nombre'      => $txt($det, 'NmbItem'),
                'cantidad'    => $qty !== '' ? $qty : '1',
                'unidad'      => $txt($det, 'UnmdItem'),
                'descripcion' => $txt($det, 'DscItem'),
                'exento'      => $exento,
            ];
            // Linea de glosa pura (NC de texto): sin cantidad ni precio, MontoItem 0.
            if ($prc === '') {
                $linea['precioUnitario'] = '0';
                $linea['origen']         = 'neto';
            } elseif ($brutos && ! $exento) {
                $linea['precioFinal'] = $prc;
                $linea['origen']      = 'bruto';
            } else {
                $linea['precioUnitario'] = $prc;
                $linea['origen']         = 'neto';
            }
            $form['detalles'][] = $linea;
        }

        // Descuento global: el builder solo escribe D + % sobre el afecto.
        $dr = self::hijo($doc, 'DscRcgGlobal');
        if ($dr !== null && $txt($dr, 'TpoMov') === 'D' && $txt($dr, 'TpoValor') === '%') {
            $form['descuentoGlobalPct'] = $txt($dr, 'ValorDR');
        }

        if (in_array($tipoDte, [33, 34], true)) {
            $form['formaPago']        = $txt($idDoc, 'FmaPago');
            $form['fechaVencimiento'] = $txt($idDoc, 'FchVenc');
        }

        // REFERENCIA: solo en notas, que es donde el formulario la ofrece.
        $ref = self::hijo($doc, 'Referencia');
        if ($ref !== null && in_array($tipoDte, [56, 61], true)) {
            $form['referencias'] = [[
                'tipoDocumento' => $txt($ref, 'TpoDocRef'),
                'folio'         => $txt($ref, 'FolioRef'),
                'fecha'         => $txt($ref, 'FchRef'),
                'codigo'        => $txt($ref, 'CodRef'),
                'razon'         => $txt($ref, 'RazonRef'),
            ]];
        }

        return $form;
    }

    private static function hijo(DOMElement $ambito, string $tag): ?DOMElement
    {
        $n = $ambito->getElementsByTagNameNS('http://www.sii.cl/SiiDte', $tag)->item(0);

        return $n instanceof DOMElement ? $n : null;
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
    }
}
