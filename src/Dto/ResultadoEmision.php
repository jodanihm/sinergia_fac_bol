<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Dto;

use Plantiflex\FacturacionCl\Enums\TipoDte;

final readonly class ResultadoEmision
{
    /**
     * @param array<string,mixed> $raw Respuesta cruda del proveedor (para auditoria).
     */
    public function __construct(
        public TipoDte $tipoDte,
        public ?int $folio,
        public string $estado,
        public ?string $trackId = null,
        public ?string $pdfUrl = null,
        public ?string $xml = null,
        public array $raw = [],
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'tipoDte' => $this->tipoDte->value,
            'folio'   => $this->folio,
            'estado'  => $this->estado,
            'trackId' => $this->trackId,
            'pdfUrl'  => $this->pdfUrl,
            'xml'     => $this->xml,
        ];
    }
}
