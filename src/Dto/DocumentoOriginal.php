<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Dto;

use DateTimeImmutable;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Exceptions\DocumentoInvalidoException;

/**
 * Representa el DTE original que se quiere anular o corregir.
 *
 * Diseñado para que el caller NOS pase los datos (receptor, detalles, montos,
 * fecha) en vez de que esta capa tenga que consultarlos al proveedor. Esto
 * desacopla la anulacion de endpoints de consulta que pueden no estar
 * confirmados.
 *
 * Los detalles y los totales son OBLIGATORIOS porque una NC valida ante el SII
 * tiene que replicar montos del documento original (no se pueden inventar ni
 * dejar en cero).
 */
final readonly class DocumentoOriginal
{
    /**
     * @param list<Detalle> $detalles
     */
    public function __construct(
        public TipoDte           $tipoDte,
        public int               $folio,
        public DateTimeImmutable $fechaEmision,
        public Receptor          $receptor,
        public array             $detalles,
        public int               $montoNeto,
        public int               $iva,
        public int               $montoTotal,
        public bool              $montosSonBrutos = false,
    ) {
        if ($this->folio <= 0) {
            throw new DocumentoInvalidoException('DocumentoOriginal: folio debe ser > 0');
        }
        if ($this->detalles === []) {
            throw new DocumentoInvalidoException('DocumentoOriginal: detalles no puede ser vacio');
        }
        foreach ($this->detalles as $i => $d) {
            if (! $d instanceof Detalle) {
                throw new DocumentoInvalidoException("DocumentoOriginal: detalles[$i] no es Detalle");
            }
        }
        if ($this->montoTotal <= 0) {
            throw new DocumentoInvalidoException('DocumentoOriginal: montoTotal debe ser > 0');
        }
    }
}
