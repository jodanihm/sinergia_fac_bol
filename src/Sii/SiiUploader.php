<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Sii;

use DOMDocument;
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
 * Sube un EnvioDTE al SII via multipart/form-data (endpoint DTEUpload).
 *
 * Responsable SOLO del transporte del upload. La construccion y firma del XML
 * las hace el orquestador (SiiDirectoFacturador). La autenticacion (token) la
 * hace SiiAutenticador.
 *
 * Respuesta del SII (RECEPCIONDTE): trae <TRACKID> y <STATUS>.
 *   STATUS == 0 -> recibido OK.
 *   STATUS != 0 -> rechazado (EnvioRechazadoException).
 */
final class SiiUploader
{
    /** @var array<string,string> */
    private const HOSTS = [
        'produccion'    => 'https://palena.sii.cl',
        'certificacion' => 'https://maullin.sii.cl',
    ];

    public function __construct(
        private readonly ClientInterface $http,
    ) {
    }

    /**
     * @return array{trackId: string, status: string, raw: string}
     *
     * @param string $rutSender  RUT completo del firmante, ej "13520634-2".
     * @param string $rutCompany RUT completo del emisor,   ej "77724622-4".
     */
    public function subir(
        string $envioDteXml,
        string $token,
        string $rutSender,
        string $rutCompany,
        Ambiente $ambiente,
    ): array {
        [$rutS, $dvS] = $this->separarRutDv($rutSender);
        [$rutC, $dvC] = $this->separarRutDv($rutCompany);

        $url = $this->baseUrl($ambiente) . '/cgi_dte/UPL/DTEUpload';

        $requestOpts = [
            'headers' => [
                // El SII EXIGE el token "PROG 1.0" en el User-Agent: sin el,
                // trata la peticion como browser y responde HTML de error
                // generico en vez de XML.
                'User-Agent' => 'Mozilla/4.0 (compatible; PROG 1.0; Windows NT 5.0; YComp 5.0.2.4)',
                'Cookie'     => 'TOKEN=' . $token,
                'Accept'     => '*/*',
            ],
            'multipart' => [
                ['name' => 'rutSender',  'contents' => $rutS],
                ['name' => 'dvSender',   'contents' => $dvS],
                ['name' => 'rutCompany', 'contents' => $rutC],
                ['name' => 'dvCompany',  'contents' => $dvC],
                [
                    'name'     => 'archivo',
                    'contents' => $envioDteXml,
                    'filename' => 'envio.xml',
                    'headers'  => ['Content-Type' => 'text/xml'],
                ],
            ],
            'http_errors' => false,
        ];

        $response   = null;
        $lastError  = null;
        $maxIntentos = 4;
        for ($intento = 1; $intento <= $maxIntentos; $intento++) {
            try {
                $response = $this->http->request('POST', $url, $requestOpts);
                break; // respuesta recibida (sea cual sea el HTTP status): no reintentar
            } catch (ConnectException $e) {
                $lastError = $e;
            } catch (RequestException $e) {
                if ($e->hasResponse()) {
                    $response = $e->getResponse();
                    break; // el servidor respondio: no reintentar
                }
                $lastError = $e;
            } catch (GuzzleException $e) {
                throw new ConexionException('Error Guzzle (upload): ' . $e->getMessage(), 0, $e);
            }
            if ($intento < $maxIntentos) {
                usleep(1_000_000 * $intento);
            }
        }

        if ($response === null) {
            throw new ConexionException('Fallo de conexion con SII (upload): ' . $lastError->getMessage(), 0, $lastError);
        }

        $this->garantizarHttpOk($response);

        $raw    = (string) $response->getBody();
        $parsed = $this->parseRespuesta($raw);

        if ($parsed['status'] !== '0') {
            // Detalle de los <ERROR> del SII (que campo del esquema fallo).
            // Si no hay ERROR, usar el cuerpo crudo como fallback.
            $detalle = $parsed['errores'] !== []
                ? implode(' | ', $parsed['errores'])
                : substr($raw, 0, 2000);

            throw new EnvioRechazadoException(
                $parsed['status'],
                $parsed['trackId'] !== '' ? $parsed['trackId'] : null,
                sprintf('SII rechazo el envio (STATUS=%s). Detalle: %s', $parsed['status'], $detalle),
            );
        }

        return [
            'trackId' => $parsed['trackId'],
            'status'  => $parsed['status'],
            'raw'     => $raw,
        ];
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
        if ($status >= 500) {
            throw new ConexionException("SII respondio HTTP $status en upload");
        }
        if ($status >= 400) {
            throw new EnvioRechazadoException('HTTP_' . $status, null, "SII respondio HTTP $status en upload");
        }
    }

    /**
     * @return array{status: string, trackId: string, errores: list<string>}
     */
    private function parseRespuesta(string $xml): array
    {
        $doc  = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok   = $doc->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (! $ok) {
            throw new EnvioRechazadoException(
                'XML_INVALIDO',
                null,
                'Respuesta de DTEUpload no es XML valido. Cuerpo recibido: ' . substr($xml, 0, 2000),
            );
        }

        // El bloque <DETAIL> trae uno o mas <ERROR> que indican el campo del
        // esquema que fallo (ej. STATUS=7 "Esquema Invalido").
        $errores = [];
        foreach ($doc->getElementsByTagName('ERROR') as $err) {
            $txt = trim((string) $err->textContent);
            if ($txt !== '') {
                $errores[] = $txt;
            }
        }

        return [
            'status'  => $this->primerTexto($doc, 'STATUS'),
            'trackId' => $this->primerTexto($doc, 'TRACKID'),
            'errores' => $errores,
        ];
    }

    private function primerTexto(DOMDocument $doc, string $tag): string
    {
        $node = $doc->getElementsByTagName($tag)->item(0);
        return $node === null ? '' : trim((string) $node->textContent);
    }

    private function baseUrl(Ambiente $ambiente): string
    {
        return self::HOSTS[$ambiente->value];
    }
}
