<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Contracts;

use Plantiflex\FacturacionCl\Dto\Certificado;
use Plantiflex\FacturacionCl\Dto\DatosEmisor;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Exceptions\CertificadoNoEncontradoException;
use Plantiflex\FacturacionCl\Exceptions\FacturacionException;

/**
 * Contrato unificado para acceder a los datos del emisor:
 *   - Certificado digital (sensible, cifrado en persistencia).
 *   - Datos publicos del emisor (razon social, giro, acteco, resolucion).
 *
 * Se unifican en un solo contrato porque siempre se consultan juntos al armar
 * un DTE: el certificado para autenticar/firmar y los datos publicos para
 * llenar el Encabezado del XML.
 *
 * Reglas:
 *   1. Multi-tenant: filtrar SIEMPRE por rutEmisor.
 *   2. Multi-ambiente: certificacion y produccion pueden tener certificados y
 *      hasta resoluciones distintas. No mezclarlos.
 *   3. El certificado retornado debe llegar al caller DESCIFRADO.
 *   4. La llave privada solo existe DESCIFRADA en memoria, dentro del DTO.
 *      No se loguea ni se persiste en claro.
 */
interface EmisorRepositoryInterface
{
    /**
     * Certificado + llave privada del emisor, descifrados.
     *
     * @throws CertificadoNoEncontradoException
     */
    public function obtenerCertificado(string $rutEmisor, Ambiente $ambiente): Certificado;

    /**
     * Datos publicos del emisor (razon social, giro, acteco, resolucion).
     *
     * @throws FacturacionException Si no hay registro para (rutEmisor, ambiente).
     */
    public function obtenerDatosEmisor(string $rutEmisor, Ambiente $ambiente): DatosEmisor;
}
