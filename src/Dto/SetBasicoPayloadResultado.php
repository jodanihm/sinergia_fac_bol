<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Dto;

/**
 * Resultado de {@see \Plantiflex\FacturacionCl\Sii\SetBasicoPayloadBuilder::construir()}.
 *
 * Si $errores no esta vacio, $payload es SIEMPRE null: no se arma un lote
 * parcial. El llamador debe mostrar $errores al tenant y NO emitir nada.
 */
final readonly class SetBasicoPayloadResultado
{
    /**
     * @param array{documentos: list<array<string,mixed>>}|null $payload listo para json_encode como body de POST /api/v1/dte/lote
     * @param list<string> $errores casos que no se pudieron construir sin adivinar, con el motivo
     */
    public function __construct(
        public ?array $payload,
        public array $errores,
    ) {
    }
}
