<?php

declare(strict_types=1);

namespace Plantiflex\Integration\Facturacion;

use RuntimeException;

/**
 * Otra anulacion del mismo documento esta en curso: la emision no se hizo y
 * no se gasto ningun folio. Ver AnulacionUnica::emitirSinPisarse().
 */
final class AnulacionEnCursoException extends RuntimeException
{
    public function __construct(public readonly int $tipoDte, public readonly int $folio)
    {
        parent::__construct("ya hay una anulacion en curso para el documento tipo {$tipoDte} folio {$folio}");
    }
}
