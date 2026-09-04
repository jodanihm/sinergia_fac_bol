<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Enums;

/**
 * Contra que mundo de la pasarela de pago se trabaja.
 *
 * NO ES EL Ambiente DEL SII, y por eso es un enum aparte en vez de reusar aquel.
 * Un contribuyente puede estar certificando ante el SII y cobrando de verdad, o
 * al reves. Mezclarlos ataria dos decisiones que no tienen por que ir juntas.
 *
 * POR QUE UN ENUM Y NO UN bool $sandbox
 * -----------------------------------------------------------------------------
 * Porque un booleano tiene un default, y el default de un booleano de cobro es
 * una trampa. La version anterior de este codigo llevaba `bool $sandbox = false`
 * en las credenciales: olvidarse de pasarlo no daba error, daba PRODUCCION. Y
 * eso fue exactamente lo que paso -- ResolutorLinkPago construia las credenciales
 * sin tocar ese parametro y toda orden salia contra el Flow real, con las
 * constantes de sandbox como codigo muerto.
 *
 * Con un enum SIN valor por defecto, olvidarse no compila. La unica forma de
 * cobrar dinero real es escribir Produccion.
 */
enum AmbientePasarela: string
{
    case Sandbox    = 'sandbox';
    case Produccion = 'produccion';

    public function esProduccion(): bool
    {
        return $this === self::Produccion;
    }

    /**
     * Traduce lo que venga de la base o de un formulario.
     *
     * CUALQUIER COSA QUE NO SEA EXACTAMENTE 'produccion' CAE EN SANDBOX. Un valor
     * corrupto, un NULL de una fila anterior a la migracion 053 o una cadena
     * vacia tienen que llevar al lado barato: dejar de cobrar se nota y se
     * arregla; cobrar sin querer, no.
     */
    public static function desde(?string $valor): self
    {
        return $valor === self::Produccion->value ? self::Produccion : self::Sandbox;
    }

    public function etiqueta(): string
    {
        return $this === self::Produccion ? 'Produccion (cobra de verdad)' : 'Sandbox (pruebas)';
    }
}
