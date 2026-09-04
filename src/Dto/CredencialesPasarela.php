<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Dto;

use Plantiflex\FacturacionCl\Enums\AmbientePasarela;
use Plantiflex\FacturacionCl\Exceptions\PasarelaNoConfiguradaException;

/**
 * Las llaves de la cuenta de cobro de UNA empresa.
 *
 * NUNCA se imprime el secreto. __debugInfo() lo tapa a proposito: un var_dump en
 * una sesion de diagnostico, un print_r en un log de error o una traza de
 * excepcion no pueden filtrar la llave con la que se cobra dinero. Es el mismo
 * cuidado que se tiene con la clave privada del certificado.
 */
final readonly class CredencialesPasarela
{
    /**
     * $ambiente NO TIENE VALOR POR DEFECTO, y es deliberado.
     *
     * Antes era `bool $sandbox = false`, o sea que olvidarse del parametro
     * significaba PRODUCCION. Eso no es una omision teorica: ResolutorLinkPago
     * construia las credenciales sin tocarlo y toda orden salia contra el Flow
     * real. Sin default, olvidarlo es un TypeError en el acto y no un cobro.
     */
    public function __construct(
        public string $apiKey,
        public string $secreto,
        public AmbientePasarela $ambiente,
        public ?string $urlRetorno = null,
    ) {
        if (trim($this->apiKey) === '' || trim($this->secreto) === '') {
            throw new PasarelaNoConfiguradaException(
                'La empresa no tiene completas las credenciales de su pasarela de pago.'
            );
        }
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return [
            'apiKey'  => $this->apiKey,
            'secreto' => '*** oculto ***',
            'ambiente' => $this->ambiente->value,
        ];
    }
}
