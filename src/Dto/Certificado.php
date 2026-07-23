<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Dto;

use Plantiflex\FacturacionCl\Exceptions\DocumentoInvalidoException;

/**
 * Certificado digital del emisor para firmar/autenticar DTEs.
 *
 * Contiene el PAR (certificado + llave privada) en PEM, ya DESCIFRADOS y listos
 * para enviar al proveedor. Solo existe en memoria; la persistencia segura es
 * responsabilidad del repositorio.
 *
 * SEGURIDAD:
 *   - __debugInfo() oculta pkeyData. var_dump/dd/print_r NUNCA debe mostrar
 *     la llave privada en claro.
 *   - No implementamos __toString ni un toArray() generico para evitar que la
 *     llave se serialice por accidente (logs, JSON de debug, dumps).
 *   - toAuthArray() es explicito: si el caller lo invoca, asume la
 *     responsabilidad de no loguear el resultado.
 */
final readonly class Certificado
{
    public function __construct(
        public string $certData,
        public string $pkeyData,
    ) {
        if (trim($this->certData) === '') {
            throw new DocumentoInvalidoException('Certificado: certData no puede ser vacio');
        }
        if (trim($this->pkeyData) === '') {
            throw new DocumentoInvalidoException('Certificado: pkeyData no puede ser vacio');
        }
    }

    /**
     * Estructura que espera API Gateway en el cuerpo del request de emision.
     *
     * @return array{cert: array{cert-data: string, pkey-data: string}}
     */
    public function toAuthArray(): array
    {
        return [
            'cert' => [
                'cert-data' => $this->certData,
                'pkey-data' => $this->pkeyData,
            ],
        ];
    }

    /**
     * Oculta la llave privada en cualquier dump/log que use var_dump/print_r.
     *
     * @return array<string,string>
     */
    public function __debugInfo(): array
    {
        return [
            'certData' => '[' . strlen($this->certData) . ' bytes]',
            'pkeyData' => '[REDACTED]',
        ];
    }
}
