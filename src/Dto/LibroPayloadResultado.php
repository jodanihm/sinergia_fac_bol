<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Dto;

/**
 * Resultado de un builder de payload de Libro IECV (ventas o compras).
 *
 * Si $errores no esta vacio, $payload es SIEMPRE null: no se arma un libro
 * parcial. El llamador debe mostrar $errores al tenant y NO emitir nada.
 */
final readonly class LibroPayloadResultado
{
    /**
     * @param array<string,mixed>|null $payload listo para json_encode como body de POST /api/v1/libro
     * @param list<string> $errores casos que no se pudieron construir sin adivinar, con el motivo
     */
    public function __construct(
        public ?array $payload,
        public array $errores,
    ) {
    }
}
