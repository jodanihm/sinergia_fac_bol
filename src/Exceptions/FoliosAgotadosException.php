<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Exceptions;

/**
 * No quedan folios disponibles en ningun CAF activo para el emisor/tipo/ambiente
 * solicitado. El sistema anfitrion debe cargar un CAF nuevo (subir XML) antes
 * de poder seguir emitiendo.
 */
class FoliosAgotadosException extends FacturacionException
{
}
