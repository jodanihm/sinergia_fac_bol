<?php

declare(strict_types=1);

/**
 * Que clase de cuenta es: interna, demo, trial o de pago.
 *
 * POR QUE EXISTE ESTA CLASE Y NO UNA CONSTANTE SUELTA. Los cinco valores se
 * usan en cinco lugares -- el filtro del listado, la columna del listado, el
 * <select> del alta, el bloque de la ficha y la validacion del POST -- y cada
 * uno necesita ademas la etiqueta que ve una persona y el color del tag. Con
 * los valores repartidos, agregar un tipo manana obliga a acordarse de los
 * cinco: el que se olvide no falla, simplemente deja de ofrecer el valor nuevo
 * en una pantalla, y eso no lo nota nadie hasta que alguien pregunta por que
 * no puede elegirlo.
 *
 * LA LISTA MANDA SOBRE LO QUE LLEGA DE AFUERA. valido() es la unica puerta: lo
 * que venga por POST o por la URL se compara contra estas claves y se descarta
 * si no esta. El ENUM de la base es la segunda barrera, no la primera -- un
 * valor invalido que llegue hasta MySQL es un 500, no un mensaje.
 *
 * 'sin_definir' NO ES UN TIPO, ES LA AUSENCIA DE UNO, y esta en la lista a
 * proposito: las cuentas que ya existian cuando se agrego la columna quedaron
 * asi, y la pantalla tiene que poder decir "nadie dijo todavia que es esto" en
 * vez de inventar una respuesta. Por eso su color es el de alerta y no el
 * neutro: es trabajo pendiente, no un estado de reposo.
 */
final class TipoCuenta
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
                'Nadie dijo todavia que clase de cuenta es. Quedaron asi las que existian antes de que el sistema '
                . 'lo preguntara: hay que elegirles el tipo.'],
            'pago'        => ['De pago', 'tag ok',
                'Cliente que paga por el servicio.'],
            'trial'       => ['Trial', 'tag warn',
                'Cliente evaluando el producto. El sistema NO la caduca sola: no se guarda fecha de termino.'],
            'cortesia'    => ['Cortesia', 'tag',
                'No paga y no es de la casa: un socio, un contador aliado, una cuenta liberada por acuerdo. '
                . 'Contesta POR QUE esta cuenta no factura, que es lo que "interna" o "de pago" esconderian. '
                . 'No cuenta como cuenta comercial y no se le exige plan: puede tener uno liberado o ninguno.'],
            'demo'        => ['Demostracion', 'tag',
                'La cuenta publica de demostracion, de solo lectura. Se reconoce ademas por la marca de su usuario.'],
            'interna'     => ['Interna', 'tag',
                'De la casa: pruebas, desarrollo, las otras marcas del grupo. No paga y no se le vende; es lo que '
                . 'hay que excluir de cualquier cifra comercial.'],
        ];
    }

    /** Las claves validas, para whitelists y para el ENUM. @return list<string> */
    public static function claves(): array
    {
        return array_keys(self::catalogo());
    }

    /** Si el valor es uno de los declarados. Unica puerta para lo que llega de afuera. */
    public static function valido(string $tipo): bool
    {
        return isset(self::catalogo()[$tipo]);
    }

    /** Como se llama para una persona. */
    public static function etiqueta(string $tipo): string
    {
        return self::catalogo()[$tipo][0] ?? $tipo;
    }

    /** La clase CSS del tag, para que las cinco pantallas pinten igual. */
    public static function clase(string $tipo): string
    {
        return self::catalogo()[$tipo][1] ?? 'tag';
    }

    /** El texto del title: que significa y que NO significa. */
    public static function ayuda(string $tipo): string
    {
        return self::catalogo()[$tipo][2] ?? '';
    }

    /**
     * Los que cuentan como cliente comercial.
     *
     * Se declara aqui, una vez, porque es la respuesta a "cuantos clientes
     * tenemos" y esa cuenta no puede depender de que cada pantalla se acuerde
     * de excluir las internas y la demo.
     *
     * @return list<string>
     */
    public static function comerciales(): array
    {
        return ['pago', 'trial'];
    }
}
