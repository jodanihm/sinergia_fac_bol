<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Exceptions;

/**
 * El SII rechazo la RECEPCION del envio (upload del EnvioDTE).
 *
 * Distinto de SiiAutenticacionException (que es del flujo de token) y de
 * EmisionRechazadaException (proveedores REST). Aqui el SII recibio el archivo
 * pero devolvio STATUS != 0 en la respuesta de DTEUpload.
 *
 * status:  codigo STATUS devuelto por el SII (ej "99", "RCT", etc).
 * trackId: TRACKID si el SII alcanzo a asignar uno (puede ser null).
 */
class EnvioRechazadoException extends FacturacionException
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $trackId = null,
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : sprintf('SII rechazo el envio (STATUS=%s)', $status),
            $code,
            $previous,
        );
    }
}
