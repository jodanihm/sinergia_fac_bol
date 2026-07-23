<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Exceptions;

/**
 * El backend del RCV (consdcvinternetui) respondio con respEstado.codRespuesta != 0.
 *
 * Distinto de ConexionException (fallo de transporte/HTTP) y de
 * SiiAutenticacionException (rechazo del token en DTEWS): aqui el token fue
 * aceptado pero la CONSULTA del registro fallo a nivel de negocio.
 *
 * codRespuesta / codError / msgeRespuesta quedan como propiedades publicas para
 * que el caller decida reintentar, refrescar token o escalar.
 */
class RcvConsultaException extends FacturacionException
{
    public function __construct(
        public readonly int $codRespuesta,
        public readonly string $codError = '',
        public readonly string $msgeRespuesta = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'SII RCV rechazo la consulta: codRespuesta=%d codError=%s msge=%s',
                $codRespuesta,
                $codError === '' ? '(sin codigo)' : $codError,
                $msgeRespuesta === '' ? '(sin glosa)' : $msgeRespuesta,
            ),
            $code,
            $previous,
        );
    }
}
