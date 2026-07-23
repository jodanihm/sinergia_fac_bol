<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Exceptions;

/**
 * No existe un certificado registrado para el emisor/ambiente solicitado.
 * El sistema anfitrion debe cargar el .p12/.pfx (o el PEM ya extraido) antes
 * de poder emitir DTEs por API Gateway.
 */
class CertificadoNoEncontradoException extends FacturacionException
{
}
