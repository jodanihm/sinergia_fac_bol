<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Dto;

use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Exceptions\CredencialesInvalidasException;

/**
 * Credenciales del emisor para una llamada al proveedor.
 *
 * Diseñado para multi-tenant: NO se inyecta en el constructor del Facturador,
 * sino que se pasa por parametro en cada operacion. Esto permite que una sola
 * instancia del Facturador atienda RUT distintos segun el contexto.
 */
final readonly class Credenciales
{
    public function __construct(
        public string $rutEmisor,
        public string $apiToken,
        public Ambiente $ambiente = Ambiente::Certificacion,
        public ?string $baseUrlOverride = null,
        // RUT del firmante (persona del certificado), ej "13520634-2".
        // Usado por SiiDirecto para los campos rutSender/dvSender del upload.
        // Opcional (default null) para no romper LibreDte/ApiGateway, que no lo
        // usan. Si SiiDirecto lo necesita y no viene, intenta extraerlo del cert.
        public ?string $rutSender = null,
    ) {
        if (trim($this->rutEmisor) === '') {
            throw new CredencialesInvalidasException('rutEmisor no puede ser vacio');
        }
        if (trim($this->apiToken) === '') {
            throw new CredencialesInvalidasException('apiToken no puede ser vacio');
        }
        if ($this->rutSender !== null && ! preg_match('/^\d{7,8}-[\dkK]$/', $this->rutSender)) {
            throw new CredencialesInvalidasException(
                'rutSender debe tener formato NNNNNNNN-X (ej 13520634-2)'
            );
        }
    }
}
