<?php

declare(strict_types=1);

/**
 * Que plan tiene contratado la cuenta: Basico, Pyme o Pro.
 *
 * ES EL SEGUNDO EJE Y NO REEMPLAZA A [[TipoCuenta]]. Aquel dice QUE RELACION hay
 * -- paga, evalua, es de la casa -- y este QUE CONTRATO. Con un solo campo
 * habria que mentir en uno de los dos: una cuenta en trial tambien esta
 * evaluando un plan concreto, que es el dato que dice cuanto va a pagar si se
 * queda, y una interna no tiene ninguno.
 *
 * LOS PRECIOS Y LOS TOPES SON UNA REFERENCIA, NO UNA REGLA. Salen de
 * panel/public/planes.html, la pagina publica de venta, y estan aqui para que
 * quien mire el panel sepa de que esta hablando. El sistema NO cobra, NO cuenta
 * facturas contra el tope y NO cambia ningun limite tecnico por este campo:
 * marcar una cuenta como 'basico' no la corta en la factura 101.
 *
 * POR ESO EL PRECIO VIVE EN EL TEXTO DE AYUDA Y NO EN UN CAMPO APARTE. Un
 * numero que se pueda sumar invita a sumarlo, y una cifra de facturacion
 * mensual sacada de aqui seria una cifra de ventas construida sobre una
 * etiqueta que nadie cobra. Si algun dia hay que informar ingresos, que salga
 * de donde se cobra de verdad.
 *
 * 'ninguno' Y 'sin_definir' NO SON LO MISMO, y esa distincion es la razon de
 * que haya cinco valores y no cuatro: "no tiene plan" es una afirmacion sobre
 * una cuenta interna, y "no se" es trabajo pendiente sobre un cliente. Tratarlos
 * igual esconde lo segundo detras de algo que parece resuelto.
 */
final class PlanCuenta
{
    /**
     * Los valores, en el orden en que se muestran. clave => [etiqueta, clase, ayuda]
     *
     * @return array<string, array{0:string, 1:string, 2:string}>
     */
    public static function catalogo(): array
    {
        return [
            'sin_definir' => ['Sin definir', 'tag err',
                'Nadie dijo todavia que plan tiene. Quedaron asi las cuentas comerciales que existian antes de '
                . 'que el sistema lo preguntara.'],
            'basico'      => ['Basico', 'tag',
                'Referencia de la pagina de planes: 0,5 UF al mes + IVA, hasta 100 facturas al mes. '
                . 'El sistema no cobra ni controla ese tope.'],
            'pyme'        => ['Pyme', 'tag',
                'Referencia de la pagina de planes: 0,8 UF al mes + IVA, hasta 400 facturas al mes. '
                . 'El sistema no cobra ni controla ese tope.'],
            'pro'         => ['Pro', 'tag',
                'Referencia de la pagina de planes: 1,5 UF al mes + IVA, facturacion ilimitada. '
                . 'El sistema no cobra.'],
            'ninguno'     => ['Sin plan', 'tag',
                'No contrata ningun plan, y es una afirmacion: es lo que corresponde a una cuenta interna o a '
                . 'la de demostracion.'],
        ];
    }

    /** Las claves validas, para whitelists y para el ENUM. @return list<string> */
    public static function claves(): array
    {
        return array_keys(self::catalogo());
    }

    /** Si el valor es uno de los declarados. Unica puerta para lo que llega de afuera. */
    public static function valido(string $plan): bool
    {
        return isset(self::catalogo()[$plan]);
    }

    /** Como se llama para una persona. */
    public static function etiqueta(string $plan): string
    {
        return self::catalogo()[$plan][0] ?? $plan;
    }

    /** La clase CSS del tag. */
    public static function clase(string $plan): string
    {
        return self::catalogo()[$plan][1] ?? 'tag';
    }

    /** El texto del title: que incluye, cuanto cuesta de referencia y que NO hace el sistema. */
    public static function ayuda(string $plan): string
    {
        return self::catalogo()[$plan][2] ?? '';
    }

    /** Los tres que son un contrato de verdad. @return list<string> */
    public static function contratados(): array
    {
        return ['basico', 'pyme', 'pro'];
    }

    /**
     * Si a esta combinacion de tipo y plan le falta algo.
     *
     * NO ES UNA VALIDACION Y NO BLOQUEA NADA: es lo que permite pintar en la
     * pantalla "esta cuenta cobra y no dice que plan". Se deja como aviso y no
     * como regla a proposito -- una cuenta interna puede tener un plan asignado
     * para probar algo, y prohibirlo obligaria a mentir en el tipo para poder
     * hacerlo.
     */
    public static function incoherente(string $tipo, string $plan): bool
    {
        return in_array($tipo, TipoCuenta::comerciales(), true)
            && ! in_array($plan, self::contratados(), true);
    }
}
