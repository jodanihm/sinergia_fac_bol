<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Pago;

use Plantiflex\FacturacionCl\Enums\AmbientePasarela;
use Plantiflex\FacturacionCl\Exceptions\PasarelaNoConfiguradaException;

/**
 * Comprueba que la url publica del panel sirva de verdad para que una pasarela
 * de pago nos hable de vuelta.
 *
 * POR QUE HACE FALTA COMPROBARLO ANTES DE CREAR NINGUNA ORDEN
 * -----------------------------------------------------------------------------
 * La direccion a la que la pasarela avisa del pago viaja DENTRO de la orden. Si
 * esta mal, la orden se crea igual, el cliente paga igual, y el aviso no llega
 * nunca: cobro real sin registrar. Y el fallo no se ve al configurar ni al
 * emitir: se ve semanas despues, cuando alguien pregunta por que una factura
 * pagada figura pendiente.
 *
 * Antes esto solo se comprobaba con un `!== ''`. Una direccion http://, un
 * localhost olvidado de una prueba o la IP de la intranet pasaban sin mas.
 *
 *
 * LAS REGLAS SON DISTINTAS SEGUN EL AMBIENTE, Y NO ES UNA CONCESION
 * -----------------------------------------------------------------------------
 * En PRODUCCION se exige https y una direccion alcanzable desde internet: si la
 * pasarela no puede llegar, hay dinero de por medio.
 *
 * En SANDBOX se permiten http y direcciones locales, porque en desarrollo el
 * panel corre en un localhost y ahi no hay dinero que perder. Aflojarlo solo en
 * sandbox es lo que permite tener reglas estrictas en produccion sin que nadie
 * las quiera desactivar para poder trabajar -- que es como acaban desactivadas.
 */
final class UrlPublica
{
    /**
     * Rangos que NUNCA pueden alcanzarse desde los servidores de una pasarela.
     * Se comprueban por nombre y por IP literal: apuntar a "localhost" y apuntar
     * a "127.0.0.1" son el mismo error escrito de dos formas.
     */
    private const NOMBRES_LOCALES = ['localhost', 'localhost.localdomain', 'ip6-localhost'];

    /**
     * Devuelve la url normalizada (sin barra final) o lanza.
     *
     * @throws PasarelaNoConfiguradaException con un motivo que se pueda leer en
     *         un log de cron sin tener que abrir el codigo. Es esa excepcion y no
     *         otra a proposito: el resolutor la traduce a 'esperar', asi que una
     *         url mal puesta RETIENE los correos en vez de mandarlos sin link.
     */
    public static function validar(string $url, AmbientePasarela $ambiente): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new PasarelaNoConfiguradaException(
                'PANEL_URL_PUBLICA esta vacia: la pasarela no tendria a donde avisar del pago.'
            );
        }

        $partes = parse_url($url);
        if ($partes === false || ! isset($partes['scheme'], $partes['host']) || $partes['host'] === '') {
            throw new PasarelaNoConfiguradaException(
                sprintf('PANEL_URL_PUBLICA no es una direccion valida: %s', $url)
            );
        }

        $esquema = strtolower((string) $partes['scheme']);
        $host    = strtolower((string) $partes['host']);

        if (! in_array($esquema, ['http', 'https'], true)) {
            throw new PasarelaNoConfiguradaException(
                sprintf('PANEL_URL_PUBLICA tiene que ser http o https, no "%s".', $esquema)
            );
        }

        // A partir de aqui, sandbox pasa. Es el unico punto donde las reglas se
        // separan, y esta escrito en una linea para que se vea de un vistazo que
        // produccion no hereda ninguna excepcion.
        if (! $ambiente->esProduccion()) {
            return rtrim($url, '/');
        }

        if ($esquema !== 'https') {
            throw new PasarelaNoConfiguradaException(
                'En produccion PANEL_URL_PUBLICA tiene que ser https: el aviso de pago no puede viajar en claro.'
            );
        }

        if (in_array($host, self::NOMBRES_LOCALES, true)) {
            throw new PasarelaNoConfiguradaException(
                sprintf('PANEL_URL_PUBLICA apunta a "%s", que la pasarela no puede alcanzar.', $host)
            );
        }

        if (self::esIpNoPublica($host)) {
            throw new PasarelaNoConfiguradaException(
                sprintf('PANEL_URL_PUBLICA apunta a la direccion privada %s, inalcanzable desde internet.', $host)
            );
        }

        return rtrim($url, '/');
    }

    /**
     * True si el host es una IP literal que no se puede alcanzar desde fuera.
     *
     * Solo mira IPs LITERALES. Un nombre de dominio que resuelva a una IP privada
     * no se detecta aqui, y no se intenta: resolver DNS desde una validacion la
     * volveria lenta, dependiente de la red y distinta segun donde corra. Ese
     * caso lo cubre la comprobacion real de alcanzabilidad, que es otra cosa y
     * se hace una vez al configurar.
     */
    private static function esIpNoPublica(string $host): bool
    {
        $ip = trim($host, '[]');   // IPv6 en una url viene entre corchetes
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}
