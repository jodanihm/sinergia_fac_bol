<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Dto;

use Plantiflex\FacturacionCl\Exceptions\DocumentoInvalidoException;

/**
 * Linea de detalle de un DTE.
 *
 * IMPORTANTE: el precio unitario se interpreta como NETO o BRUTO segun
 * el flag {@see DocumentoTributario::$montosSonBrutos} del documento que
 * contiene esta linea. El Detalle por si solo no decide IVA.
 */
final readonly class Detalle
{
    public function __construct(
        public string $nombre,
        public float $cantidad,
        public float $precioUnitario,
        public bool $exento = false,
        public ?string $descripcion = null,
        public ?string $unidad = null,
        public float $descuentoPorcentaje = 0.0,
    ) {
        if (trim($this->nombre) === '') {
            throw new DocumentoInvalidoException('Detalle: nombre no puede ser vacio');
        }
        if ($this->cantidad <= 0) {
            throw new DocumentoInvalidoException('Detalle: cantidad debe ser > 0');
        }
        if ($this->precioUnitario < 0) {
            throw new DocumentoInvalidoException('Detalle: precioUnitario no puede ser negativo');
        }
        if ($this->descuentoPorcentaje < 0 || $this->descuentoPorcentaje > 100) {
            throw new DocumentoInvalidoException('Detalle: descuentoPorcentaje debe estar entre 0 y 100');
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'nombre'              => $this->nombre,
            'descripcion'         => $this->descripcion,
            'cantidad'            => $this->cantidad,
            'precioUnitario'      => $this->precioUnitario,
            'exento'              => $this->exento,
            'unidad'              => $this->unidad,
            'descuentoPorcentaje' => $this->descuentoPorcentaje > 0 ? $this->descuentoPorcentaje : null,
        ], static fn ($v) => $v !== null && $v !== '');
    }
}
