<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Dto;

use Plantiflex\FacturacionCl\Enums\TipoDte;

/**
 * Estado de un DTE o de un envio.
 *
 * Segun el proveedor, el estado puede consultarse por folio+tipo (LibreDTE) o
 * por TrackID de envio (SII directo). Por eso tipoDte/folio son opcionales y se
 * agrega trackId: en una consulta por TrackID no hay un unico folio/tipo.
 */
final readonly class EstadoDte
{
    /**
     * @param array<string,mixed> $raw
     */
    public function __construct(
        public string $estado,
        public ?string $glosa = null,
        public ?string $trackId = null,
        public ?TipoDte $tipoDte = null,
        public ?int $folio = null,
        public array $raw = [],
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'estado'  => $this->estado,
            'glosa'   => $this->glosa,
            'trackId' => $this->trackId,
            'tipoDte' => $this->tipoDte?->value,
            'folio'   => $this->folio,
        ];
    }
}
