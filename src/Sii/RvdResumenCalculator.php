<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Sii;

use RuntimeException;

/**
 * Agrupa boletas ya emitidas (filas de dte_emitido) en los Resumen que exige
 * RvdBuilder::build() para el RVD (ConsumoFolios) diario -- uno por tipo de
 * documento, con montos sumados y folios colapsados en rangos.
 *
 * Reemplaza el calculo A MANO que hacia scripts/emitir_rvd_boleta_ea.php (un
 * comentario con la aritmetica verificada folio por folio): esta clase lee lo
 * que YA quedo persistido en dte_emitido tras emitir el Set de Boleta
 * (BoletaFacturador::emitirLote(), ya con persistencia -- ver
 * BoletaFacturadorTest) y calcula el resumen en vivo.
 *
 * dte_emitido NO tiene columna propia para el monto exento (ver
 * estructura_facturacion_cl.sql): se deriva algebraicamente, MntTotal =
 * MntNeto + IVA + MntExento para boleta (verificado contra CASO-4 del set fijo
 * de certificacion: 14720 = 10689 + 2031 + 2000).
 */
final class RvdResumenCalculator
{
    private const TASA_IVA = 19;

    /**
     * @param list<array{tipoDte:int, folio:int, neto:int, iva:int, total:int}> $boletas
     *        Una fila por boleta emitida (SIN agrupar), cualquier orden.
     * @return list<array{
     *     tipoDocumento:int, mntNeto:int, mntIva:int, tasaIva:int, mntExento?:int,
     *     mntTotal:int, foliosEmitidos:int, foliosAnulados:int, foliosUtilizados:int,
     *     rangos:list<array{0:int,1:int}>
     * }>
     */
    public function calcular(array $boletas): array
    {
        if ($boletas === []) {
            throw new RuntimeException('RvdResumenCalculator: no hay boletas para resumir.');
        }

        $porTipo = [];
        foreach ($boletas as $b) {
            $porTipo[$b['tipoDte']][] = $b;
        }
        ksort($porTipo);

        $resumenes = [];
        foreach ($porTipo as $tipo => $docs) {
            $neto = $iva = $total = 0;
            $folios = [];
            foreach ($docs as $d) {
                $neto     += $d['neto'];
                $iva      += $d['iva'];
                $total    += $d['total'];
                $folios[] = $d['folio'];
            }
            $exento = $total - $neto - $iva;

            $resumen = [
                'tipoDocumento'    => $tipo,
                'mntNeto'          => $neto,
                'mntIva'           => $iva,
                'tasaIva'          => self::TASA_IVA,
                'mntTotal'         => $total,
                'foliosEmitidos'   => count($docs),
                'foliosAnulados'   => 0,
                'foliosUtilizados' => count($docs),
                'rangos'           => $this->colapsarRangos($folios),
            ];
            // RvdBuilder::buildResumen() lee cada clave por nombre (isset()),
            // no itera el array en orden: da igual en que posicion quede
            // mntExento dentro de $resumen, el XML sale en el orden correcto
            // del schema de todas formas.
            if ($exento > 0) {
                $resumen['mntExento'] = $exento;
            }

            $resumenes[] = $resumen;
        }

        return array_values($resumenes);
    }

    /**
     * @param list<int> $folios
     * @return list<array{0:int,1:int}>
     */
    private function colapsarRangos(array $folios): array
    {
        sort($folios);
        $rangos = [];
        $inicio = $fin = null;
        foreach ($folios as $f) {
            if ($inicio === null) {
                $inicio = $fin = $f;
                continue;
            }
            if ($f === $fin + 1) {
                $fin = $f;
                continue;
            }
            $rangos[] = [$inicio, $fin];
            $inicio = $fin = $f;
        }
        if ($inicio !== null) {
            $rangos[] = [$inicio, $fin];
        }

        return $rangos;
    }
}
