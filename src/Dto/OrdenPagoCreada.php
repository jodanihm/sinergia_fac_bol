<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Dto;

use Plantiflex\FacturacionCl\Exceptions\DocumentoInvalidoException;

/**
 * La orden que la pasarela acepto crear, con el link que se le da al pagador.
 */
final readonly class OrdenPagoCreada
{
    /**
     * @param string $ordenExterna id de la orden EN LA PASARELA. Es por donde la
     *                             va a encontrar la confirmacion cuando llegue.
     * @param string $url          el link completo, ya armado y listo para pegar
     *                             en el correo
     */
    public function __construct(
        public string $ordenExterna,
        public string $url,
    ) {
        if (trim($this->ordenExterna) === '') {
            throw new DocumentoInvalidoException('OrdenPagoCreada: ordenExterna no puede ser vacia');
        }
        // https OBLIGATORIO. Este valor acaba dentro de un href en un correo que
        // sale a un tercero: si la pasarela devolviera algo raro, aqui se corta,
        // y no en la bandeja de entrada de un cliente. PreparadorEnvio lo vuelve
        // a comprobar antes de pintar -- dos cinturones para el mismo dato,
        // porque es el unico del correo que viene de fuera de casa.
        if (! str_starts_with($this->url, 'https://')) {
            throw new DocumentoInvalidoException('OrdenPagoCreada: la url tiene que empezar por https://');
        }
    }
}
