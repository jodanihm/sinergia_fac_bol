<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Dto;

/**
 * Un tipo de documento que el SII tiene autorizado a un contribuyente.
 *
 * Sale de documentos[] de la consulta de autorizacion. Se guarda AUNQUE la
 * pantalla del alta de empresa solo lo muestre de forma informativa: traerlo no
 * cuesta ninguna consulta extra -- viene en la misma respuesta -- y habilita una
 * validacion que hoy no existe. El motor acepta emitir 33, 34, 56 y 61 sin
 * comprobar en ningun momento que el SII se los haya autorizado a ESE emisor, y
 * ese folio se quema igual cuando el SII rechaza.
 *
 * fechaDesautorizacion normalmente es null. Cuando trae valor, el SII le quito
 * la autorizacion de ese tipo a ese contribuyente: la respuesta sigue
 * listandolo, asi que hay que mirar el campo y no la mera presencia en el
 * arreglo.
 */
final readonly class DocumentoAutorizadoSii
{
    public function __construct(
        public int $codigo,
        public string $descripcion,
        /** Fecha de autorizacion, formato YYYY-MM-DD. */
        public ?string $fechaAutorizacion = null,
        /** Fecha en que el SII revoco la autorizacion; null = sigue vigente. */
        public ?string $fechaDesautorizacion = null,
    ) {
    }

    /** ¿Este tipo esta vigente hoy, o el SII ya lo desautorizo? */
    public function vigente(): bool
    {
        return $this->fechaDesautorizacion === null;
    }
}
