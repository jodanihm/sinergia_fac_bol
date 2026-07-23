<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Sii;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use InvalidArgumentException;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Exceptions\ConexionException;
use Plantiflex\FacturacionCl\Exceptions\EnvioRechazadoException;
use Psr\Http\Message\ResponseInterface;

/**
 * Sube un EnvioBOLETA al SII via multipart/form-data por el canal REST de
 * boletas (no el DTEUpload SOAP de facturas).
 *
 * Gemelo de SiiUploader. El multipart es identico (rutSender/dvSender/
 * rutCompany/dvCompany/archivo), pero:
 *   - Host pangal.sii.cl (cert) / rahue.sii.cl (prod), path
 *     /recursos/v1/boleta.electronica.envio.
 *   - La respuesta es JSON (no XML): { trackid, estado, fecha_recepcion, ... }.
 *     estado "REC" = recibido OK. El diagnostico real se consulta luego por
 *     trackid (servicio aparte).
 *   - El token debe ser de BOLETA (BoletaAutenticador); el de factura NO sirve.
 *
 * Responsable SOLO del transporte. La construccion/firma del XML las hace el
 * orquestador. El token lo provee BoletaAutenticador.
 */
final class BoletaUploader
{
    /** @var array<string,string> */
    private const HOSTS = [
        'produccion'    => 'https://rahue.sii.cl',
        'certificacion' => 'https://pangal.sii.cl',
    ];

    private const PATH = '/recursos/v1/boleta.electronica.envio';

    private const USER_AGENT = 'Mozilla/4.0 (compatible; PROG 1.0; Windows NT)';

    public function __construct(
        private readonly ClientInterface $http,
    ) {
    }

    /**
     * @return array{trackId: string, estado: string, raw: string}
     *
     * @param string $rutSender  RUT completo del firmante, ej "13520634-2".
     * @param string $rutCompany RUT completo del emisor,   ej "77724622-4".
     */
    public function subir(
        string $envioBoletaXml,
        string $token,
        string $rutSender,
        string $rutCompany,
        Ambiente $ambiente,
    ): array {
        [$rutS, $dvS] = $this->separarRutDv($rutSender);
        [$rutC, $dvC] = $this->separarRutDv($rutCompany);

        $url = $this->baseUrl($ambiente) . self::PATH;

        try {
            $response = $this->http->request('POST', $url, [
                'headers' => [
                    'User-Agent' => self::USER_AGENT,
                    'Cookie'     => 'TOKEN=' . $token,
                    'Accept'     => 'application/json',
                ],
                'multipart' => [
                    ['name' => 'rutSender',  'contents' => $rutS],
                    ['name' => 'dvSender',   'contents' => $dvS],
                    ['name' => 'rutCompany', 'contents' => $rutC],
                    ['name' => 'dvCompany',  'contents' => $dvC],
                    [
                        'name'     => 'archivo',
                        'contents' => $envioBoletaXml,
                        'filename' => 'envioBoleta.xml',
                        'headers'  => ['Content-Type' => 'text/xml'],
                    ],
                ],
                'http_errors' => false,
            ]);
        } catch (ConnectException $e) {
            throw new ConexionException('Fallo de conexion con SII (envio boleta): ' . $e->getMessage(), 0, $e);
        } catch (RequestException $e) {
            $r = $e->getResponse();
            if ($r === null) {
                throw new ConexionException('Fallo HTTP sin respuesta (envio boleta): ' . $e->getMessage(), 0, $e);
            }
            $response = $r;
        } catch (GuzzleException $e) {
            throw new ConexionException('Error Guzzle (envio boleta): ' . $e->getMessage(), 0, $e);
        }

        $this->garantizarHttpOk($response);

        $raw = trim((string) $response->getBody());

        // Token vencido / no autenticado: el SII responde texto plano, no JSON.
        if (stripos($raw, 'NO ESTA AUTENTICADO') !== false) {
            throw new EnvioRechazadoException('NO_AUTENTICADO', null, 'SII: NO ESTA AUTENTICADO (envio boleta)');
        }

        $parsed  = $this->parseRespuesta($raw);
        $trackId = $parsed['trackId'];
        $estado  = $parsed['estado'];

        // En la recepcion debe venir un trackid (estado "REC" = recibido OK). El
        // diagnostico de validacion se consulta despues por trackid.
        if ($trackId === '' || $trackId === '0') {
            throw new EnvioRechazadoException(
                $estado !== '' ? $estado : 'SIN_TRACKID',
                $trackId !== '' ? $trackId : null,
                sprintf(
                    'SII no acepto el envio de boleta (estado=%s). Cuerpo: %s',
                    $estado !== '' ? $estado : '(vacio)',
                    substr($raw, 0, 2000),
                ),
            );
        }

        return [
            'trackId' => $trackId,
            'estado'  => $estado,
            'raw'     => $raw,
        ];
    }

    /**
     * @return array{trackId: string, estado: string}
     */
    private function parseRespuesta(string $json): array
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            throw new EnvioRechazadoException(
                'JSON_INVALIDO',
                null,
                'Respuesta del envio de boleta no es JSON. Cuerpo: ' . substr($json, 0, 2000),
            );
        }

        // trackid puede venir como int o string segun el SII.
        $trackId = isset($data['trackid']) ? trim((string) $data['trackid']) : '';
        $estado  = isset($data['estado']) ? trim((string) $data['estado']) : '';

        return ['trackId' => $trackId, 'estado' => $estado];
    }

    /**
     * "13520634-2" -> ["13520634", "2"]. Tolera puntos.
     *
     * @return array{0: string, 1: string}
     */
    private function separarRutDv(string $rut): array
    {
        $limpio = str_replace('.', '', trim($rut));
        if (! str_contains($limpio, '-')) {
            throw new InvalidArgumentException("RUT sin guion: '$rut'");
        }
        [$num, $dv] = explode('-', $limpio, 2);
        if ($num === '' || $dv === '') {
            throw new InvalidArgumentException("RUT mal formado: '$rut'");
        }
        return [$num, strtoupper($dv)];
    }

    private function garantizarHttpOk(ResponseInterface $response): void
    {
        $status = $response->getStatusCode();
        if ($status >= 400) {
            $body = substr((string) $response->getBody(), 0, 2000);
            if ($status >= 500) {
                throw new ConexionException(sprintf('SII respondio HTTP %d en envio boleta. Body: %s', $status, $body));
            }
            throw new EnvioRechazadoException('HTTP_' . $status, null, sprintf('SII respondio HTTP %d en envio boleta. Body: %s', $status, $body));
        }
    }

    private function baseUrl(Ambiente $ambiente): string
    {
        return self::HOSTS[$ambiente->value];
    }
}
