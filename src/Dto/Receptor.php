<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Dto;

use Plantiflex\FacturacionCl\Exceptions\DocumentoInvalidoException;
use Plantiflex\FacturacionCl\Sii\Rut;

final readonly class Receptor
{
    /** RUT del receptor, siempre en forma canonica (ver el constructor). */
    public string $rut;

    public function __construct(
        string $rut,
        public string $razonSocial,
        public ?string $giro = null,
        public ?string $direccion = null,
        public ?string $comuna = null,
        public ?string $email = null,
    ) {
        if (trim($rut) === '') {
            throw new DocumentoInvalidoException('Receptor: rut no puede ser vacio');
        }

        // EL RUT SE NORMALIZA AQUI, Y AQUI ES EL SITIO.
        //
        // Este DTO es el ULTIMO punto por el que pasa el RUT que teclea el usuario antes de
        // convertirse en <RUTRecep> y <RR> del XML. Normalizar en cada llamador seria
        // confiar en que ninguno se olvide -- y uno se olvido: el 02-09-2026 un
        // RUT con puntos llego al SII y el documento volvio rechazado por
        // esquema, con el folio ya gastado.
        //
        // NORMALIZA PERO NO LANZA SI EL RUT NO EXISTE. Rechazar aqui un DV malo
        // seria un FATAL con traza, no un mensaje: en el motor este constructor
        // se llama FUERA del try de emitirDte() y el archivo no tiene
        // set_exception_handler (ver el comentario de las claves conocidas en
        // public/index.php). Avisar al usuario es trabajo de validarDocumentoDte(),
        // que responde 422 con el campo exacto y sin quemar folio. Aqui solo se
        // garantiza que lo que salga hacia el SII este BIEN ESCRITO.
        $this->rut = Rut::normalizar($rut);

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
