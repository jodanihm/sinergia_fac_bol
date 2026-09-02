<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Pago;

use GuzzleHttp\Client;
use Plantiflex\FacturacionCl\Contracts\PasarelaPagoInterface;
use Plantiflex\FacturacionCl\Exceptions\PasarelaPermanenteException;
use Plantiflex\FacturacionCl\Providers\FlowPasarelaPago;

/**
 * Traduce la cadena guardada en pago_pasarela_cuenta.proveedor a la clase que
 * sabe hablar con esa pasarela.
 *
 * EL MAPA ES CERRADO, Y ESO ES TODO EL PUNTO. Una cadena que no este aqui NO se
 * instancia: se lanza. Nunca se hace `new $algo` con texto que viene de la base,
 * ni se deduce un nombre de clase concatenando. Es la misma disciplina de
 * PERMISOS_RUTA en el panel -- lo que no esta declarado, no pasa -- y por el
 * mismo motivo: aqui lo que hay al otro lado es dinero de un tercero.
 *
 * ANIADIR UNA PASARELA es escribir su clase, implementar PasarelaPagoInterface y
 * agregar una linea a este mapa. Nada mas: ni el correo, ni el runner, ni las
 * tablas, ni las pantallas saben que pasarelas existen.
 */
final class FabricaPasarela
{
    /** @var array<string, class-string<PasarelaPagoInterface>> */
    private const MAPA = [
        'flow' => FlowPasarelaPago::class,
    ];

    /** Las claves validas, para que una pantalla pueda ofrecerlas sin duplicar la lista. */
    public static function proveedores(): array
    {
        return array_keys(self::MAPA);
    }

    /**
     * @throws PasarelaPermanenteException si el proveedor no existe. Permanente
     *         y no transitoria a proposito: reintentar cada cinco minutos una
     *         cadena que no existe no la va a hacer existir, y el correo tiene
     *         que quedar visible esperando a que alguien corrija la
     *         configuracion.
     */
    public static function crear(string $proveedor, ?Client $http = null): PasarelaPagoInterface
    {
        $clase = self::MAPA[$proveedor] ?? null;
        if ($clase === null) {
            throw new PasarelaPermanenteException(sprintf(
                "Pasarela de pago desconocida: '%s'. Las disponibles son: %s.",
                $proveedor,
                implode(', ', self::proveedores())
            ));
        }

        return new $clase($http ?? new Client());
    }
}
