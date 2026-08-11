<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Dto;

/**
 * Lo que el modelo entendio de la pregunta del usuario.
 *
 * TRES DESENLACES, Y SE DISTINGUEN A PROPOSITO. Un traductor que solo pudiera
 * devolver perillas obligaria al modelo a inventar algo para toda pregunta que
 * no encaje, que es exactamente el comportamiento que hay que impedir.
 *
 *   perillas   Entendio la pregunta y la tradujo. Las perillas TODAVIA NO ESTAN
 *              VALIDADAS: quien las reciba tiene que pasarlas por el repositorio,
 *              que las rechaza con lista cerrada. Un modelo que alucine una
 *              metrica tiene que dar el MISMO error que un POST malformado.
 *   imposible  Entendio la pregunta y NO SE PUEDE responder con los datos que
 *              hay. Lleva el motivo, que es lo que el usuario necesita leer --
 *              "que producto vendi mas" no es un error tecnico.
 *   noEntendida La pregunta no es sobre ventas, o no se entendio. Tambien lleva
 *              motivo, y NO es lo mismo que imposible: aqui reformular sirve.
 */
final class PreguntaTraducida
{
    public const PERILLAS     = 'perillas';
    public const IMPOSIBLE    = 'imposible';
    public const NO_ENTENDIDA = 'no_entendida';

    /**
     * @param array<string,mixed> $perillas vacio salvo en el caso PERILLAS
     */
    private function __construct(
        public readonly string $desenlace,
        public readonly array $perillas,
        public readonly ?string $motivo,
    ) {
    }

    /** @param array<string,mixed> $perillas */
    public static function conPerillas(array $perillas): self
    {
        return new self(self::PERILLAS, $perillas, null);
    }

    public static function imposible(string $motivo): self
    {
        return new self(self::IMPOSIBLE, [], $motivo);
    }

    public static function noEntendida(string $motivo): self
    {
        return new self(self::NO_ENTENDIDA, [], $motivo);
    }

    public function hayQueConsultar(): bool
    {
        return $this->desenlace === self::PERILLAS;
    }
}
