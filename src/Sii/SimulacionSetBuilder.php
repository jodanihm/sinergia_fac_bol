<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Sii;

use RuntimeException;

/**
 * Construye el arreglo "documentos" (misma forma que el body de
 * POST /api/v1/dte/lote, ver LoteDteEmisor::emitir()) para el Set de
 * Simulacion de certificacion: UN envio con los 3 tipos de documento
 * (33/61/56), 20-100 documentos, SIN referencia al SET (el SII valida
 * estructura/volumen, no contenido literal).
 *
 * La plantilla (integration/plantiflex/templates/simulacion_base.json) es la
 * semilla YA VALIDADA por el SII en 2 envios reales (EASY AGENDA y
 * sinergia, ver payload_simulacion_v2.json): NO se reinventan las glosas, se
 * escala la MISMA plantilla a la cantidad pedida.
 *
 * Las Notas de Credito/Debito de la plantilla real NO son standalone: cada
 * una referencia (via refIndiceLote) un documento YA emitido antes en el
 * mismo lote (una NC anula o corrige una factura anterior; una ND anula una
 * NC anterior) -- esa relacion se preserva al escalar, no solo el conteo por
 * tipo, porque una NC/ND sin referencia real no tiene sentido de negocio.
 */
final class SimulacionSetBuilder
{
    private const MIN_DOCUMENTOS = 20;
    private const MAX_DOCUMENTOS = 100;

    public function __construct(
        private readonly string $rutaPlantilla = __DIR__ . '/../../integration/plantiflex/templates/simulacion_base.json',
    ) {
    }

    /**
     * @return list<array<string,mixed>> misma forma que 'documentos' del body de POST /api/v1/dte/lote
     */
    public function construir(int $totalDocumentos): array
    {
        if ($totalDocumentos < self::MIN_DOCUMENTOS || $totalDocumentos > self::MAX_DOCUMENTOS) {
            throw new RuntimeException(sprintf(
                'SimulacionSetBuilder: total de documentos fuera de rango (%d); debe ser entre %d y %d.',
                $totalDocumentos,
                self::MIN_DOCUMENTOS,
                self::MAX_DOCUMENTOS,
            ));
        }

        $plantilla = $this->leerPlantilla();
        [$nFactura, $nNc, $nNd] = $this->calcularProporciones($totalDocumentos);

        $documentos = [];

        // --- Facturas: cicla sobre las 8 glosas base de la plantilla ---
        $glosas = $plantilla['glosasFactura'];
        for ($i = 0; $i < $nFactura; $i++) {
            $glosa = $glosas[$i % count($glosas)];
            $documentos[] = [
                'tipoDte'   => 33,
                'receptor'  => $plantilla['receptor'],
                'detalles'  => [[
                    'nombre'         => $glosa['nombre'],
                    'cantidad'       => 1,
                    'precioUnitario' => $glosa['precioUnitario'],
                ]],
            ];
        }

        // --- Notas de Credito: alternan "anula factura" (copia el monto de
        // la factura referenciada) y "corrige giro" (linea generica, monto
        // 0) -- mismo patron 50/50 que la plantilla real, referenciando
        // facturas YA generadas arriba (indice < posicion de la NC en el lote).
        $anulaFactura = $plantilla['notaCreditoAnulaFactura'];
        $corrigeGiro  = $plantilla['notaCreditoCorrigeGiro'];
        for ($i = 0; $i < $nNc; $i++) {
            $refIndice = $i % $nFactura;
            $esAnula   = $i % 2 === 0;
            $patron    = $esAnula ? $anulaFactura : $corrigeGiro;
            $glosaRef  = $documentos[$refIndice]['detalles'][0];

            $documentos[] = [
                'tipoDte'  => 61,
                'receptor' => $plantilla['receptor'],
                'detalles' => $esAnula
                    ? [['nombre' => $glosaRef['nombre'], 'cantidad' => 1, 'precioUnitario' => $glosaRef['precioUnitario']]]
                    : [['nombre' => $patron['razon'], 'cantidad' => 1, 'precioUnitario' => 0]],
                'referencias' => [[
                    'refIndiceLote' => $refIndice,
                    'codigo'        => $patron['codigo'],
                    'razon'         => $patron['razon'],
                ]],
            ];
        }

        // --- Notas de Debito: anulan una Nota de Credito YA generada arriba. ---
        $anulaNc       = $plantilla['notaDebitoAnulaNotaCredito'];
        $indiceBaseNc  = $nFactura;
        for ($i = 0; $i < $nNd; $i++) {
            $refIndice = $indiceBaseNc + ($i % $nNc);

            $documentos[] = [
                'tipoDte'  => 56,
                'receptor' => $plantilla['receptor'],
                'detalles' => [['nombre' => $anulaNc['razon'], 'cantidad' => 1, 'precioUnitario' => 0]],
                'referencias' => [[
                    'refIndiceLote' => $refIndice,
                    'codigo'        => $anulaNc['codigo'],
                    'razon'         => $anulaNc['razon'],
                ]],
            ];
        }

        return $documentos;
    }

    /**
     * Proporciones aproximadas ~70% factura / ~20% NC / ~10% ND, redondeando
     * y garantizando al menos 1 de cada tipo. El ND absorbe el resto (total
     * - factura - NC) para que la suma cuadre EXACTO con $total antes de
     * aplicar el minimo; si aplicar el minimo desajusta la suma, la factura
     * (el grupo mas grande) absorbe la diferencia.
     *
     * @return array{0:int,1:int,2:int} [nFactura, nNc, nNd]
     */
    private function calcularProporciones(int $total): array
    {
        $nFactura = (int) round($total * 0.70);
        $nNc      = (int) round($total * 0.20);
        $nNd      = $total - $nFactura - $nNc;

        $nFactura = max(1, $nFactura);
        $nNc      = max(1, $nNc);
        $nNd      = max(1, $nNd);

        $nFactura += $total - ($nFactura + $nNc + $nNd);

        return [$nFactura, $nNc, $nNd];
    }

    /** @return array<string,mixed> */
    private function leerPlantilla(): array
    {
        if (! is_file($this->rutaPlantilla)) {
            throw new RuntimeException("SimulacionSetBuilder: no se encontro la plantilla en {$this->rutaPlantilla}.");
        }
        $contenido = file_get_contents($this->rutaPlantilla);
        if ($contenido === false) {
            throw new RuntimeException("SimulacionSetBuilder: no se pudo leer la plantilla en {$this->rutaPlantilla}.");
        }

        return json_decode($contenido, true, flags: JSON_THROW_ON_ERROR);
    }
}
