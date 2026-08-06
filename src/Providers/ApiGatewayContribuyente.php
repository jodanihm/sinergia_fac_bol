<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Plantiflex\FacturacionCl\Contracts\ConsultaContribuyenteInterface;
use Plantiflex\FacturacionCl\Dto\ContribuyenteAutorizado;
use Plantiflex\FacturacionCl\Dto\DocumentoAutorizadoSii;
use Plantiflex\FacturacionCl\Exceptions\ConsultaContribuyenteException;

/**
 * Consulta de contribuyente autorizado contra API Gateway (app.apigateway.cl).
 *
 * GET /api/v2/sii/dte/contribuyentes/autorizado/{rut}
 * Cabecera: Authorization: Token <token>   -- el prefijo literal "Token", no
 * "Bearer": es un formato propio del proveedor y confundirlo da 401.
 *
 *
 * POR QUE UNA CLASE PROPIA Y NO EL PAQUETE DE COMPOSER DEL PROVEEDOR
 * -----------------------------------------------------------------------------
 * Porque el proveedor es una decision comercial que puede cambiar, y el paquete
 * traeria sus propios DTO por todo el arbol. Con esta clase detras de
 * ConsultaContribuyenteInterface, cambiar de proveedor es escribir otra
 * implementacion; con el paquete, seria tocar cada llamador. Mismo criterio que
 * ya rige para BrevoMailer, que tampoco usa el SDK de Brevo.
 *
 *
 * EL MOLDE ES BrevoMailer, Y SE SIGUE EN LOS TRES PUNTOS QUE IMPORTAN
 * -----------------------------------------------------------------------------
 *   - desdeEntorno(?Client $http = null): produccion la llama sin argumentos;
 *     un test le inyecta un Guzzle con MockHandler. Es lo que hace testeable
 *     esta clase sin tocar la red.
 *   - http_errors = false: un 404 o un 401 del proveedor es UN DATO, no una
 *     excepcion de transporte. Se interpretan aqui y se convierten en el motivo
 *     que corresponde.
 *   - Solo el fallo de CONEXION se vuelve excepcion, porque ahi no hay ninguna
 *     respuesta que interpretar.
 *
 *
 * TIMEOUT 45 SEGUNDOS, NO LOS 30 DE BrevoMailer
 * -----------------------------------------------------------------------------
 * El proveedor consulta al SII en linea y el SII puede tardar hasta 20 segundos.
 * Con 30 quedaria muy poco margen para el resto del viaje, y un timeout aqui no
 * es inocuo: le niega al usuario el dato que vino a buscar y lo manda a teclear
 * la fecha de resolucion a mano, que es exactamente el error que esta consulta
 * existe para evitar.
 *
 *
 * CADA CONSULTA CUESTA CREDITOS
 * -----------------------------------------------------------------------------
 * Esta clase NO cachea ni deduplica: hace una peticion por cada llamada, y es lo
 * correcto para una pieza de transporte. Evitar la consulta repetida es
 * responsabilidad de quien la usa -- la pantalla del alta consulta cuando el
 * usuario aprieta el boton y muestra el resultado, sin re-consultar para
 * repintar.
 */
final class ApiGatewayContribuyente implements ConsultaContribuyenteInterface
{
    public const BASE_URL = 'https://app.apigateway.cl/api/v2/';

    /** Nombre de la variable de entorno, en un solo lugar. */
    public const ENV_TOKEN = 'APIGATEWAY_TOKEN';

    /** Ver el docblock: 45 y no 30 por los 20 segundos del SII. */
    public const TIMEOUT_SEGUNDOS = 45;

    public function __construct(
        private readonly Client $http,
        private readonly string $token,
    ) {
    }

    /**
     * Construye el cliente desde el entorno.
     *
     * NO LANZA SI FALTA EL TOKEN, a diferencia de BrevoMailer::desdeEntorno().
     * La diferencia es deliberada y viene del uso: sin BREVO_API_KEY no se puede
     * mandar un correo y abortar es lo unico honesto, pero sin token AQUI la
     * pantalla del alta de empresa TIENE que seguir funcionando con todo
     * tecleado a mano. Por eso el token vacio se acepta al construir y el fallo
     * aparece al consultar, como ConsultaContribuyenteException::sinToken(),
     * que la pantalla sabe traducir a un mensaje util.
     */
    public static function desdeEntorno(?Client $http = null): self
    {
        $token = getenv(self::ENV_TOKEN);

        return new self(
            $http ?? new Client([
                'base_uri'    => self::BASE_URL,
                'timeout'     => self::TIMEOUT_SEGUNDOS,
                'http_errors' => false,
            ]),
            $token === false ? '' : trim($token),
        );
    }

    public function consultar(string $rut): ContribuyenteAutorizado
    {
        if ($this->token === '') {
            throw ConsultaContribuyenteException::sinToken(self::ENV_TOKEN);
        }

        // El RUT viaja en el PATH. rawurlencode por higiene: hoy un RUT
        // normalizado no trae nada que escapar, pero esta clase recibe lo que le
        // den y una barra en el path cambiaria el endpoint consultado.
        $ruta = 'sii/dte/contribuyentes/autorizado/' . rawurlencode(trim($rut));

        try {
            $respuesta = $this->http->request('GET', $ruta, [
                'headers' => [
                    // Prefijo literal "Token", no "Bearer".
                    'Authorization' => 'Token ' . $this->token,
                    'Accept'        => 'application/json',
                ],
            ]);
        } catch (GuzzleException $e) {
            // Sin respuesta HTTP no hay nada que interpretar: timeout, DNS,
            // conexion rechazada.
            throw ConsultaContribuyenteException::sinRespuesta($e->getMessage(), $e);
        }

        $status = $respuesta->getStatusCode();
        $cuerpo = (string) $respuesta->getBody();

        if ($status < 200 || $status >= 300) {
            // Incluye el 401 por token invalido y el 404 por RUT inexistente.
            // Se trata como "no respondio" a efectos del usuario -- no obtuvo su
            // dato -- pero el status queda en el mensaje para el log.
            throw ConsultaContribuyenteException::sinRespuesta(
                sprintf('HTTP %d %s', $status, substr($cuerpo, 0, 300)),
            );
        }

        $json = json_decode($cuerpo, true);
        if (! is_array($json) || ! isset($json['data']) || ! is_array($json['data'])) {
            throw ConsultaContribuyenteException::respuestaIlegible(
                'no trae el objeto data. Cuerpo: ' . substr($cuerpo, 0, 300),
            );
        }

        return $this->mapear($json['data']);
    }

    /**
     * data[] -> DTO nuestro.
     *
     * TODO SE LEE DEFENSIVAMENTE (?? y casts) menos el propio objeto data. No es
     * paranoia gratuita: el proveedor puede agregar campos o dejar de mandar uno
     * opcional sin avisar, y esta consulta NO puede tumbar el alta de una
     * empresa por un campo que ni siquiera se usa en el formulario.
     *
     * resolucion.numero puede venir 0 legitimamente -- es el valor que el SII
     * usa en certificacion -- asi que se distingue "ausente" (null) de "cero",
     * y NO se convierte un 0 en null.
     *
     * @param array<string,mixed> $data
     */
    private function mapear(array $data): ContribuyenteAutorizado
    {
        $resolucion = is_array($data['resolucion'] ?? null) ? $data['resolucion'] : [];

        $documentos = [];
        foreach (is_array($data['documentos'] ?? null) ? $data['documentos'] : [] as $doc) {
            if (! is_array($doc) || ! isset($doc['codigo'])) {
                continue;
            }
            $documentos[] = new DocumentoAutorizadoSii(
                codigo:               (int) $doc['codigo'],
                descripcion:          (string) ($doc['descripcion'] ?? ''),
                fechaAutorizacion:    $this->textoONull($doc['autorizado'] ?? null),
                fechaDesautorizacion: $this->textoONull($doc['desautorizado'] ?? null),
            );
        }

        return new ContribuyenteAutorizado(
            rut:               (string) ($data['rut'] ?? ''),
            autorizado:        (bool) ($data['autorizado'] ?? false),
            razonSocial:       (string) ($data['razon_social'] ?? ''),
            resolucionNumero:  isset($resolucion['numero']) ? (int) $resolucion['numero'] : null,
            resolucionFecha:   $this->textoONull($resolucion['fecha'] ?? null),
            direccionRegional: $this->textoONull($data['direccion_regional'] ?? null),
            software:          $this->textoONull($data['software'] ?? null),
            documentos:        $documentos,
        );
    }

    /** null, '' y no-escalares colapsan a null; el resto a string sin espacios. */
    private function textoONull(mixed $v): ?string
    {
        if ($v === null || is_array($v) || is_object($v)) {
            return null;
        }
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }
}
