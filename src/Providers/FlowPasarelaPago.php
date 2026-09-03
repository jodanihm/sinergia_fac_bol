<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Plantiflex\FacturacionCl\Contracts\PasarelaPagoInterface;
use Plantiflex\FacturacionCl\Dto\CredencialesPasarela;
use Plantiflex\FacturacionCl\Dto\OrdenPagoCreada;
use Plantiflex\FacturacionCl\Dto\SolicitudPago;
use Plantiflex\FacturacionCl\Exceptions\PasarelaPermanenteException;
use Plantiflex\FacturacionCl\Exceptions\PasarelaTransitoriaException;

/**
 * Cobro por link contra Flow (flow.cl).
 *
 * POST {base}/payment/create con application/x-www-form-urlencoded. La respuesta
 * trae {url, token, flowOrder} y el link del pagador se arma concatenando
 * url . '?token=' . token -- ese armado es de Flow, no una convencion nuestra.
 *
 *
 * LA FIRMA ES LO UNICO DELICADO DE ESTA CLASE
 * -----------------------------------------------------------------------------
 * Flow no manda el secreto: manda los parametros firmados con el. El algoritmo,
 * literal de su documentacion:
 *
 *   1. ordenar las CLAVES alfabeticamente
 *   2. concatenar clave y valor, seguidos, sin separador ninguno
 *   3. hash_hmac('sha256', $concatenado, $secretKey)
 *   4. mandar el resultado como un parametro mas, 's'
 *
 * Los tres detalles que rompen la firma si se olvidan, y que por eso estan
 * escritos aqui: 's' NO entra en lo que se firma; los valores se concatenan tal
 * cual se van a enviar (el monto como entero, no "1.234"); y el orden es
 * alfabetico de la clave, no el orden en que uno las escribio. Un error en
 * cualquiera de los tres da un 401 que no dice cual de los tres fue.
 *
 *
 * EL MOLDE ES ApiGatewayContribuyente, Y SE SIGUE EN LOS MISMOS PUNTOS
 * -----------------------------------------------------------------------------
 *   - El Client de Guzzle se inyecta por constructor: produccion lo construye,
 *     un test le pasa uno con MockHandler. Sin esto la clase no seria testeable
 *     sin tocar la red -- y esta clase cobra dinero, asi que probarla contra la
 *     red de verdad no es una opcion.
 *   - http_errors = false: un 401 es UN DATO que hay que clasificar, no una
 *     excepcion de transporte que se escapa sin traducir.
 *   - Solo el fallo de CONEXION se vuelve excepcion directa, porque ahi no hay
 *     ninguna respuesta que interpretar.
 *
 *
 * POR QUE NO SE USA EL SDK DE FLOW
 * -----------------------------------------------------------------------------
 * Mismo criterio que BrevoMailer con el SDK de Brevo y que ApiGatewayContribuyente
 * con el de su proveedor: el SDK traeria sus DTO por todo el arbol y ataria el
 * resto del sistema a un proveedor que es una decision de cada empresa. Detras de
 * PasarelaPagoInterface, cambiarlo es escribir otra clase.
 */
final class FlowPasarelaPago implements PasarelaPagoInterface
{
    private const URL_PRODUCCION = 'https://www.flow.cl/api';
    private const URL_SANDBOX    = 'https://sandbox.flow.cl/api';

    /**
     * 30 segundos. Crear una orden es una escritura barata en Flow; si tarda mas
     * que esto es que algo va mal, y el runner prefiere reintentar dentro de
     * cinco minutos antes que tener un correo colgado medio minuto por documento
     * mientras el resto de la cola espera.
     */
    private const TIMEOUT = 30;

    public function __construct(private readonly Client $http)
    {
    }

    /**
     * El host segun el ambiente de las credenciales. Un solo sitio donde se
     * decide: si esta eleccion viviera repetida en cada metodo, arreglar uno y
     * olvidar el otro dejaria una operacion apuntando al mundo equivocado.
     */
    private static function base(CredencialesPasarela $cred): string
    {
        return $cred->ambiente->esProduccion() ? self::URL_PRODUCCION : self::URL_SANDBOX;
    }

    public function nombre(): string
    {
        return 'flow';
    }

    public function crearOrden(SolicitudPago $solicitud, CredencialesPasarela $cred): OrdenPagoCreada
    {
        $base = self::base($cred);

        // El monto va como entero puro. (string) sobre un int no mete separador
        // de miles ni decimales; number_format aqui romperia la firma Y el cobro.
        $params = [
            'apiKey'          => $cred->apiKey,
            'commerceOrder'   => $solicitud->referencia,
            'subject'         => $solicitud->asunto,
            'amount'          => (string) $solicitud->monto,
            'email'           => $solicitud->emailPagador,
            'urlConfirmation' => $solicitud->urlConfirmacion,
            'urlReturn'       => $solicitud->urlRetorno ?? $cred->urlRetorno ?? $solicitud->urlConfirmacion,
        ];
        $params['s'] = self::firmar($params, $cred->secreto);

        try {
            $resp = $this->http->post($base . '/payment/create', [
                'form_params'  => $params,
                'timeout'      => self::TIMEOUT,
                'http_errors'  => false,
            ]);
        } catch (GuzzleException $e) {
            // No hubo respuesta: no se sabe si la orden se creo. Transitoria a
            // proposito -- el reintento manda la MISMA referencia, asi que si
            // Flow alcanzo a crearla nos devolvera esa y no una segunda.
            throw new PasarelaTransitoriaException(
                'No se pudo contactar a Flow: ' . $e->getMessage(),
                0,
                $e
            );
        }

        $status = $resp->getStatusCode();
        $cuerpo = (string) $resp->getBody();

        if ($status >= 500 || $status === 429) {
            throw new PasarelaTransitoriaException(
                sprintf('Flow respondio %d: %s', $status, self::recorte($cuerpo))
            );
        }
        if ($status !== 200 && $status !== 201) {
            // 400/401/403: credenciales, firma o datos que Flow no acepta.
            // Reintentar con lo mismo da lo mismo, asi que es permanente y
            // alguien tiene que mirar la configuracion.
            throw new PasarelaPermanenteException(
                sprintf('Flow rechazo la orden (HTTP %d): %s', $status, self::recorte($cuerpo))
            );
        }

        $datos = json_decode($cuerpo, true);
        if (! is_array($datos)) {
            throw new PasarelaPermanenteException('Flow devolvio algo que no es JSON: ' . self::recorte($cuerpo));
        }

        $url   = trim((string) ($datos['url'] ?? ''));
        $token = trim((string) ($datos['token'] ?? ''));
        if ($url === '' || $token === '') {
            throw new PasarelaPermanenteException(
                'La respuesta de Flow no trae url y token: ' . self::recorte($cuerpo)
            );
        }

        // SE GUARDA EL TOKEN, NO flowOrder, y la eleccion importa.
        //
        // El aviso de pago que manda Flow trae EL TOKEN y nada mas. Guardar
        // flowOrder dejaba una fila que ese aviso no sabia encontrar: mientras la
        // consulta de estado respondiera se podia salir del paso, porque devuelve
        // commerceOrder, pero en cuanto esa consulta falla no queda forma de
        // saber de que documento hablaba el aviso -- y ese es justo el caso que
        // hay que poder reconciliar despues.
        //
        // flowOrder no se pierde para siempre: getStatus lo devuelve cuando haga
        // falta. El token es el que hace falta AQUI.
        return new OrdenPagoCreada($token, $url . '?token=' . $token);
    }

    /**
     * GET {base}/payment/getStatus?apiKey=..&token=..&s=..
     *
     * Flow llama a urlConfirmation por CUALQUIER desenlace, no solo por un pago
     * bueno. Sin esta consulta no se puede distinguir "pago" de "rechazo", y
     * marcar todo como pagado seria peor que no marcar nada.
     *
     * status = 2 es "pagada" en Flow (1 pendiente, 3 rechazada, 4 anulada).
     */
    public function consultarEstado(string $referenciaExterna, CredencialesPasarela $cred): array
    {
        $base   = self::base($cred);
        $params = ['apiKey' => $cred->apiKey, 'token' => $referenciaExterna];
        $params['s'] = self::firmar($params, $cred->secreto);

        try {
            $resp = $this->http->get($base . '/payment/getStatus', [
                'query'       => $params,
                'timeout'     => self::TIMEOUT,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw new PasarelaTransitoriaException('No se pudo consultar a Flow: ' . $e->getMessage(), 0, $e);
        }

        $status = $resp->getStatusCode();
        $cuerpo = (string) $resp->getBody();

        if ($status >= 500 || $status === 429) {
            throw new PasarelaTransitoriaException(sprintf('Flow respondio %d al consultar', $status));
        }
        if ($status !== 200) {
            throw new PasarelaPermanenteException(
                sprintf('Flow rechazo la consulta (HTTP %d): %s', $status, self::recorte($cuerpo))
            );
        }

        $datos = json_decode($cuerpo, true);
        if (! is_array($datos) || ! isset($datos['commerceOrder'])) {
            throw new PasarelaPermanenteException('Respuesta de getStatus sin commerceOrder: ' . self::recorte($cuerpo));
        }

        $monto = $datos['amount'] ?? null;

        return [
            'pagada'     => ((int) ($datos['status'] ?? 0)) === 2,
            'referencia' => (string) $datos['commerceOrder'],
            'monto'      => $monto === null ? null : (int) $monto,
        ];
    }

    /**
     * Firma HMAC-SHA256 de los parametros, segun el algoritmo de Flow.
     *
     * Publica y estatica para que la pueda usar tambien quien VERIFIQUE la
     * confirmacion que Flow nos manda de vuelta: alli el problema es el mismo al
     * reves -- rehacer la firma sobre lo recibido y compararla -- y tener dos
     * copias del algoritmo es como se llega a que una se arregle y la otra no.
     *
     * @param array<string,string> $params sin la clave 's'
     */
    public static function firmar(array $params, string $secreto): string
    {
        unset($params['s']);   // por si el llamador la trae; firmarse a si misma no tiene sentido
        ksort($params, SORT_STRING);

        $aFirmar = '';
        foreach ($params as $clave => $valor) {
            $aFirmar .= $clave . $valor;
        }

        return hash_hmac('sha256', $aFirmar, $secreto);
    }

    /** El cuerpo de un error va a un ultimo_error de 500 y a un log: se acota. */
    private static function recorte(string $cuerpo): string
    {
        $limpio = trim(preg_replace('/\s+/', ' ', $cuerpo) ?? '');

        return mb_substr($limpio, 0, 200);
    }
}
