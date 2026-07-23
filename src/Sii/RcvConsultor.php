<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Sii;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use InvalidArgumentException;
use Plantiflex\FacturacionCl\Dto\Certificado;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Exceptions\ConexionException;
use Plantiflex\FacturacionCl\Exceptions\RcvConsultaException;
use Psr\Http\Message\ResponseInterface;

/**
 * Descarga el Registro de Compras y Ventas (RCV) desde el backend de la UI del
 * SII: https://www4.sii.cl/consdcvinternetui/services/data/facadeService/
 *
 * Autenticacion: el MISMO token por certificado de DTEWS (CrSeed/GetTokenFromSeed)
 * que produce SiiAutenticador, enviado como Cookie TOKEN. El backend NO exige
 * reCAPTCHA cuando la sesion viene autenticada por certificado (verificado contra
 * produccion en el spike previo: getResumen y getDetalleCompraExport -> HTTP 200,
 * codRespuesta:0).
 *
 * AMBIENTE: el RCV de descarga existe SOLO en produccion (www4.sii.cl). No hay
 * equivalente en certificacion/maullin; pasar Ambiente::Certificacion es un error.
 *
 * Contrato de la respuesta:
 *   - getResumen          -> JSON; el panorama del periodo viene en data{}, y el
 *                            estado de la operacion en respEstado.codRespuesta (0 = OK).
 *   - getDetalle*Export   -> JSON cuyo data[] es el listado de filas del CSV (con
 *                            su encabezado); se unen con saltos de linea -> texto .csv.
 */
final class RcvConsultor
{
    private const HOST_PROD  = 'https://www4.sii.cl';
    private const PATH       = '/consdcvinternetui/services/data/facadeService/';
    private const NAMESPACE  = 'cl.sii.sdi.lob.diii.consdcv.data.api.interfaces.FacadeService/';
    private const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) facturacion-cl/RcvConsultor';

    /** Maximo de intentos del POST ante status transitorios. */
    private const REINTENTOS_MAX = 3;

    /** Status transitorios del backend del RCV que justifican reintentar. */
    private const STATUS_REINTENTABLE = [429, 502, 503, 504];

    /** Espera (segundos) antes de cada reintento: 1s, 2s, 4s. */
    private const BACKOFF_SEG = [1, 2, 4];

    /** @var \Closure(int):void Pausa entre reintentos (inyectable; default sleep()). */
    private readonly \Closure $sleeper;

    /**
     * @param (callable(int):void)|null $sleeper Pausa en segundos entre reintentos.
     *        Por defecto usa sleep(); los tests inyectan un no-op para no esperar.
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly SiiAutenticador $autenticador,
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper !== null
            ? \Closure::fromCallable($sleeper)
            : static function (int $segundos): void { sleep($segundos); };
    }

    /**
     * Resumen del RCV de un periodo (estado REGISTRO). Devuelve el JSON decodificado.
     *
     * @param string $periodo   Periodo tributario AAAAMM (ej. "202605").
     * @param string $operacion "COMPRA" | "VENTA".
     * @return array<string,mixed>
     *
     * @throws RcvConsultaException codRespuesta != 0.
     * @throws ConexionException    fallo de transporte / HTTP != 2xx / respuesta no JSON.
     */
    public function getResumen(
        Certificado $cert,
        string $rutEmisor,
        string $dvEmisor,
        string $periodo,
        string $operacion,
        Ambiente $ambiente = Ambiente::Produccion,
    ): array {
        $op = $this->normalizarOperacion($operacion);
        $this->garantizarPeriodo($periodo);
        $this->garantizarProduccion($ambiente);

        $token = $this->autenticador->obtenerToken($cert, $ambiente);
        $raw   = $this->postFacade('getResumen', $token, $this->buildData($rutEmisor, $dvEmisor, $periodo, $op));
        $json  = $this->decodeJson($raw, 'getResumen');
        $this->garantizarCodRespuestaOk($json);

        return $json;
    }

    /**
     * Detalle del RCV como CSV crudo (texto del .csv tal cual, con su encabezado).
     * Usa getDetalleCompraExport (COMPRA) o getDetalleVentaExport (VENTA).
     *
     * @throws RcvConsultaException codRespuesta != 0.
     * @throws ConexionException    fallo de transporte / HTTP != 2xx / respuesta sin data[].
     */
    public function getDetalleCsv(
        Certificado $cert,
        string $rutEmisor,
        string $dvEmisor,
        string $periodo,
        string $operacion,
        Ambiente $ambiente = Ambiente::Produccion,
    ): string {
        $op = $this->normalizarOperacion($operacion);
        $this->garantizarPeriodo($periodo);
        $this->garantizarProduccion($ambiente);

        $metodo = $op === 'COMPRA' ? 'getDetalleCompraExport' : 'getDetalleVentaExport';
        $accion = $op === 'COMPRA' ? 'RCV_DDETC' : 'RCV_DDETV';

        $data = $this->buildData($rutEmisor, $dvEmisor, $periodo, $op);
        $data['accionRecaptcha'] = $accion;
        $data['tokenRecaptcha']  = '';

        $token = $this->autenticador->obtenerToken($cert, $ambiente);
        $raw   = $this->postFacade($metodo, $token, $data);
        $json  = $this->decodeJson($raw, $metodo);
        $this->garantizarCodRespuestaOk($json);

        $filas = $json['data'] ?? null;
        if (! is_array($filas)) {
            throw new ConexionException(
                "RCV $metodo: la respuesta no trae arreglo data[]. Cuerpo: " . substr($raw, 0, 1000),
            );
        }

        $lineas = array_map(
            static fn (mixed $f): string => is_string($f) ? $f : (string) json_encode($f),
            array_values($filas),
        );

        return implode("\n", $lineas);
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private function buildData(string $rutEmisor, string $dvEmisor, string $periodo, string $operacion): array
    {
        return [
            'rutEmisor'    => $rutEmisor,
            'dvEmisor'     => $dvEmisor,
            'ptributario'  => $periodo,
            'operacion'    => $operacion,
            'estadoContab' => 'REGISTRO',
            'codTipoDoc'   => 0,
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    private function postFacade(string $metodo, string $token, array $data): string
    {
        $url  = self::HOST_PROD . self::PATH . $metodo;
        $body = (string) json_encode([
            'metaData' => [
                'namespace'      => self::NAMESPACE . $metodo,
                'conversationId' => $token,
                'transactionId'  => $this->uuid4(),
                'page'           => null,
            ],
            'data' => $data,
        ]);

        // Reintentos con backoff SOLO ante status transitorios (429/502/503/504).
        // Los errores de conexion/Guzzle se propagan en el primer intento (ejecutarPost).
        for ($intento = 1; ; $intento++) {
            $response = $this->ejecutarPost($url, $body, $token, $metodo);
            $status   = $response->getStatusCode();

            if (in_array($status, self::STATUS_REINTENTABLE, true) && $intento < self::REINTENTOS_MAX) {
                ($this->sleeper)(self::BACKOFF_SEG[$intento - 1]);
                continue;
            }

            $this->garantizarHttpOk($response, $metodo);

            return (string) $response->getBody();
        }
    }

    private function ejecutarPost(string $url, string $body, string $token, string $metodo): ResponseInterface
    {
        try {
            return $this->http->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json, text/plain, */*',
                    'Origin'       => self::HOST_PROD,
                    'Referer'      => self::HOST_PROD . '/consdcvinternetui/',
                    'User-Agent'   => self::USER_AGENT,
                    'Cookie'       => 'TOKEN=' . $token,
                ],
                'body'        => $body,
                'http_errors' => false,
            ]);
        } catch (ConnectException $e) {
            throw new ConexionException("Fallo de conexion con SII (RCV $metodo): " . $e->getMessage(), 0, $e);
        } catch (RequestException $e) {
            $r = $e->getResponse();
            if ($r === null) {
                throw new ConexionException("Fallo HTTP sin respuesta (RCV $metodo): " . $e->getMessage(), 0, $e);
            }
            return $r;
        } catch (GuzzleException $e) {
            throw new ConexionException("Error Guzzle (RCV $metodo): " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(string $raw, string $metodo): array
    {
        $json = json_decode(trim($raw), true);
        if (! is_array($json)) {
            throw new ConexionException("RCV $metodo: la respuesta no es JSON. Cuerpo: " . substr($raw, 0, 1000));
        }
        return $json;
    }

    /**
     * @param array<string,mixed> $json
     */
    private function garantizarCodRespuestaOk(array $json): void
    {
        $re = $json['respEstado'] ?? $json['metaData']['respEstado'] ?? null;
        $cod = is_array($re) && array_key_exists('codRespuesta', $re) ? (int) $re['codRespuesta'] : null;

        if ($cod === 0) {
            return;
        }

        throw new RcvConsultaException(
            $cod ?? -1,
            (string) (is_array($re) ? ($re['codError'] ?? ($cod === null ? 'SIN_RESP_ESTADO' : '')) : 'SIN_RESP_ESTADO'),
            (string) (is_array($re) ? ($re['msgeRespuesta'] ?? '') : ''),
        );
    }

    private function garantizarHttpOk(ResponseInterface $response, string $metodo): void
    {
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new ConexionException("SII respondio HTTP $status en RCV $metodo");
        }
    }

    private function normalizarOperacion(string $operacion): string
    {
        $op = strtoupper(trim($operacion));
        if ($op !== 'COMPRA' && $op !== 'VENTA') {
            throw new InvalidArgumentException("operacion invalida: '$operacion' (esperado COMPRA|VENTA)");
        }
        return $op;
    }

    private function garantizarPeriodo(string $periodo): void
    {
        if (preg_match('/^\d{6}$/', $periodo) !== 1) {
            throw new InvalidArgumentException("periodo invalido: '$periodo' (esperado AAAAMM)");
        }
    }

    private function garantizarProduccion(Ambiente $ambiente): void
    {
        if ($ambiente !== Ambiente::Produccion) {
            throw new InvalidArgumentException(
                'El RCV de descarga solo existe en produccion (www4.sii.cl); certificacion no aplica.',
            );
        }
    }

    private function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
