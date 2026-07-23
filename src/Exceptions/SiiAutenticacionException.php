<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Exceptions;

/**
 * El SII rechazo la autenticacion (semilla o token), o devolvio un estado != 00.
 *
 * Se distinguen las dos posibles dimensiones del fallo:
 *   - estadoSii: codigo retornado por el SII en RESP_HDR/ESTADO (ej "01", "02").
 *   - glosaSii:  glosa retornada por el SII en RESP_HDR/GLOSA (texto humano).
 *
 * Ambos quedan disponibles como propiedades publicas para que el caller pueda
 * decidir si reintentar, refrescar credenciales o escalar.
 */
class SiiAutenticacionException extends FacturacionException
{
    public function __construct(
        public readonly string $estadoSii,
        public readonly string $glosaSii = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        $glosa = $glosaSii === '' ? '(sin glosa)' : $glosaSii;
        parent::__construct(
            sprintf('SII rechazo autenticacion: estado=%s glosa=%s', $estadoSii, $glosa),
            $code,
            $previous,
        );
    }
}
