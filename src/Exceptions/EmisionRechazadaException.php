<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Exceptions;

class EmisionRechazadaException extends FacturacionException
{
    /**
     * @param array<string,mixed> $detalle Respuesta cruda del proveedor para diagnostico.
     */
    public function __construct(
        string $message,
        public readonly array $detalle = [],
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
