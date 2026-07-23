<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Dto;

use Plantiflex\FacturacionCl\Exceptions\DocumentoInvalidoException;

final readonly class Receptor
{
    public function __construct(
        public string $rut,
        public string $razonSocial,
        public ?string $giro = null,
        public ?string $direccion = null,
        public ?string $comuna = null,
        public ?string $email = null,
    ) {
        if (trim($this->rut) === '') {
            throw new DocumentoInvalidoException('Receptor: rut no puede ser vacio');
        }
        if (trim($this->razonSocial) === '') {
            throw new DocumentoInvalidoException('Receptor: razonSocial no puede ser vacio');
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'rut'         => $this->rut,
            'razonSocial' => $this->razonSocial,
            'giro'        => $this->giro,
            'direccion'   => $this->direccion,
            'comuna'      => $this->comuna,
            'email'       => $this->email,
        ], static fn ($v) => $v !== null && $v !== '');
    }
}
