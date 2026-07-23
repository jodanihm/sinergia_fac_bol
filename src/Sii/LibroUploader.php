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
 * Sube un Libro IECV al SII via multipart/form-data.
 *
 * Mismo patron que SiiUploader (no se modifica aquel para no afectar el flujo
 * de DTE). La diferencia es el endpoint, parametrizable.
 *
 * TODO: confirmar contra el SII el endpoint exacto de envio de LIBROS IECV.
 * En la practica los libros suelen ir por el mismo DTEUpload que los DTE; se
 * deja ese path por defecto y configurable por si difiere.
 */
final class LibroUploader
{
    /** @var array<string,string> */
    private const HOSTS = [
        'produccion'    => 'https://palena.sii.cl',
        'certificacion' => 'https://maullin.sii.cl',
    ];

    public function __construct(
        private readonly ClientInterface $http,
        // TODO: confirmar endpoint de envio de libros IECV en ambiente real.
        private readonly string $endpointPath = '/cgi_dte/UPL/DTEUpload',
    ) {
    }

    /**
     * @return array{trackId: string, status: string, raw: string}
     */
    public function subir(
        string $libroXml,
        string $token,
        string $rutSender,
        string $rutCompany,
        Ambiente $ambiente,
    ): array {
        [$rutS, $dvS] = $this->separarRutDv($rutSender);
        [$rutC, $dvC] = $this->separarRutDv($rutCompany);

        $url = $this->baseUrl($ambiente) . $this->endpointPath;

        try {
            $response = $this->http->request('POST', $url, [
                'headers' => [
                    // El SII exige este User-Agent (token PROG 1.0) para responder XML.
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
                        'contents' => $libroXml,
                        'filename' => 'libro.xml',
                        'headers'  => ['Content-Type' => 'text/xml'],
                    ],
                ],
                'http_errors' => false,
            ]);
        } catch (ConnectException $e) {
            throw new ConexionException('Fallo de conexion con SII (libro): ' . $e->getMessage(), 0, $e);
        } catch (RequestException $e) {
            $r = $e->getResponse();
            if ($r === null) {
                throw new ConexionException('Fallo HTTP sin respuesta (libro): ' . $e->getMessage(), 0, $e);
            }
            $response = $r;
        } catch (GuzzleException $e) {
            throw new ConexionException('Error Guzzle (libro): ' . $e->getMessage(), 0, $e);
        }

        $this->garantizarHttpOk($response);

        $raw    = (string) $response->getBody();
        $parsed = $this->parseRespuesta($raw);

        if ($parsed['status'] !== '0') {
            $detalle = $parsed['errores'] !== []
                ? implode(' | ', $parsed['errores'])
                : substr($raw, 0, 2000);
            throw new EnvioRechazadoException(
                $parsed['status'],
                $parsed['trackId'] !== '' ? $parsed['trackId'] : null,
                sprintf('SII rechazo el libro (STATUS=%s). Detalle: %s', $parsed['status'], $detalle),
            );
        }

        return [
            'trackId' => $parsed['trackId'],
            'status'  => $parsed['status'],
            'raw'     => $raw,
        ];
    }

    /**
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
            throw new ConexionException("SII respondio HTTP $status en envio de libro");
        }
        if ($status >= 400) {
            throw new EnvioRechazadoException('HTTP_' . $status, null, "SII respondio HTTP $status en envio de libro");
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
                'Respuesta de envio de libro no es XML valido. Cuerpo recibido: ' . substr($xml, 0, 2000),
            );
        }

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
