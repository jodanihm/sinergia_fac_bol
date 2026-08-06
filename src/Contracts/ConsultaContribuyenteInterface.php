<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Contracts;

use Plantiflex\FacturacionCl\Dto\ContribuyenteAutorizado;
use Plantiflex\FacturacionCl\Exceptions\ConsultaContribuyenteException;

/**
 * Resuelve los datos de un contribuyente como emisor de DTE, a partir de su RUT.
 *
 * POR QUE HAY UNA INTERFAZ PARA UN SOLO PROVEEDOR. Porque el proveedor es una
 * decision comercial, no tecnica: hoy la consulta la cobra API Gateway por
 * credito, y el dia que cambie -- de proveedor, de precio o de disponibilidad --
 * lo que tiene que cambiar es UNA clase, no los llamadores. Es el mismo criterio
 * con el que existe FacturadorInterface con tres implementaciones.
 *
 * NO DEVUELVE DIRECCION NI COMUNA. Ninguna implementacion puede, porque la
 * fuente no las tiene: son datos que el usuario sigue tecleando. Se dice en el
 * contrato para que nadie escriba un llamador que las espere.
 */
interface ConsultaContribuyenteInterface
{
    /**
     * @param string $rut RUT con guion y digito verificador (77724622-4).
     *
     * @throws ConsultaContribuyenteException si no se pudo obtener una respuesta
     *         del proveedor. Un contribuyente NO AUTORIZADO no es una excepcion:
     *         se devuelve un ContribuyenteAutorizado con autorizado=false, que
     *         es una respuesta legitima y util.
     */
    public function consultar(string $rut): ContribuyenteAutorizado;
}
