<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Sii;

use DOMDocument;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Plantiflex\FacturacionCl\Dto\Certificado;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Exceptions\ConexionException;
use Plantiflex\FacturacionCl\Exceptions\SiiAutenticacionException;
use Psr\Http\Message\ResponseInterface;

/**
 * Autenticacion REST especifica para BOLETA electronica.
 *
 * Gemelo de SiiAutenticador pero por el canal REST de boletas (no SOAP):
 *   1. getSeed  -> GET  apicert/recursos/v1/boleta.electronica.semilla -> SEMILLA
 *   2. firmar   -> XmlSigner::firmarSemilla (IDENTICO a factura)
 *   3. getToken -> POST apicert/recursos/v1/boleta.electronica.token   -> TOKEN
 *                  (body = XML firmado, Content-Type application/xml)
 *
 * Diferencias clave vs factura:
 *   - REST, no SOAP: la respuesta es directamente <SII:RESPUESTA> (sin sobre
 *     SOAP ni doble-escape de entidades); por eso NO se usa extraerXmlInterno.
 *   - Hosts apicert/api, no maullin/palena.
 *   - El TOKEN obtenido es propio de boleta; el de factura NO sirve aca.
 *
 * El SII puede devolver RESP_HDR/RESP_BODY en orden variable: el parseo es por
 * nombre de tag (no posicional), igual que en SiiAutenticador.
 */
final class BoletaAutenticador
{
    /** @var array<string,string> */
    private const HOSTS = [
        'produccion'    => 'https://api.sii.cl',
        'certificacion' => 'https://apicert.sii.cl',
    ];

    private const USER_AGENT = 'Mozilla/4.0 (compatible; PROG 1.0; Windows NT)';

    public function __construct(
        private readonly ClientInterface $http,
        private readonly XmlSigner $signer,
    ) {
    }

    public function obtenerToken(Certificado $cert, Ambiente $ambiente): string
    {
        $semilla    = $this->getSeed($ambiente);
        $xmlFirmado  = $this->signer->firmarSemilla($semilla, $cert);
        return $this->getToken($xmlFirmado, $ambiente);
    }

    // ------------------------------------------------------------------
    // Paso 1: pedir semilla (GET)
    // ------------------------------------------------------------------

    private function getSeed(Ambiente $ambiente): string
    {
        $url  = $this->baseUrl($ambiente) . '/recursos/v1/boleta.electronica.semilla';
        $body = $this->rest('GET', $url, null, null);
        $resp = $this->parseRespuestaSii($body);

        $this->garantizarEstadoOk($resp);
        if (($resp['datos']['SEMILLA'] ?? '') === '') {
            throw new SiiAutenticacionException(
                $resp['estado'] !== '' ? $resp['estado'] : 'NO_SEMILLA',
                'SII no devolvio SEMILLA (boleta)',
            );
        }
        return $resp['datos']['SEMILLA'];
    }

    // ------------------------------------------------------------------
    // Paso 3: pedir token enviando la semilla firmada (POST application/xml)
    // ------------------------------------------------------------------

    private function getToken(string $xmlFirmado, Ambiente $ambiente): string
    {
        $url  = $this->baseUrl($ambiente) . '/recursos/v1/boleta.electronica.token';
        $body = $this->rest('POST', $url, $xmlFirmado, 'application/xml');
        $resp = $this->parseRespuestaSii($body);

        $this->garantizarEstadoOk($resp);
        if (($resp['datos']['TOKEN'] ?? '') === '') {
            throw new SiiAutenticacionException(
                $resp['estado'] !== '' ? $resp['estado'] : 'NO_TOKEN',
                'SII no devolvio TOKEN (boleta)',
            );
        }
        return $resp['datos']['TOKEN'];
    }

    // ------------------------------------------------------------------
    // HTTP / parseo
    // ------------------------------------------------------------------

    private function rest(string $metodo, string $url, ?string $body, ?string $contentType): string
    {
        $opciones = [
            'headers' => [
                'User-Agent' => self::USER_AGENT,
                'Accept'     => 'application/xml',
            ],
            'http_errors' => false,
        ];
        if ($body !== null) {
            $opciones['body'] = $body;
        }
        if ($contentType !== null) {
            $opciones['headers']['Content-Type'] = $contentType;
        }

        try {
            $response = $this->http->request($metodo, $url, $opciones);
        } catch (ConnectException $e) {
            throw new ConexionException('Fallo de conexion con SII (boleta auth): ' . $e->getMessage(), 0, $e);
        } catch (RequestException $e) {
            $r = $e->getResponse();
            if ($r === null) {
                throw new ConexionException('Fallo HTTP sin respuesta (boleta auth): ' . $e->getMessage(), 0, $e);
            }
            $response = $r;
        } catch (GuzzleException $e) {
            throw new ConexionException('Error Guzzle (boleta auth): ' . $e->getMessage(), 0, $e);
        }

        $this->garantizarHttpOk($response);

        $raw = trim((string) $response->getBody());

        // Token vencido / no autenticado: el SII responde texto plano, no XML.
        if (stripos($raw, 'NO ESTA AUTENTICADO') !== false) {
            throw new SiiAutenticacionException('NO_AUTENTICADO', 'SII: NO ESTA AUTENTICADO (boleta auth)');
        }

        return $raw;
    }

    private function garantizarHttpOk(ResponseInterface $response): void
    {
        $status = $response->getStatusCode();
        if ($status >= 500) {
            throw new ConexionException("SII respondio HTTP $status (boleta auth)");
        }
        if ($status >= 400) {
            throw new SiiAutenticacionException('HTTP_' . $status, "SII respondio HTTP $status (boleta auth)");
        }
    }

    /**
     * Parsea <SII:RESPUESTA> con RESP_HDR (ESTADO/GLOSA) y RESP_BODY (SEMILLA/TOKEN).
     * Order-independent: getElementsByTagName ignora prefijo y posicion.
     *
     * @return array{estado: string, glosa: string, datos: array<string,string>}
     */
    private function parseRespuestaSii(string $xml): array
    {
        $doc  = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok   = $doc->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (! $ok) {
            throw new SiiAutenticacionException(
                'XML_INVALIDO',
                'Respuesta de auth boleta no es XML. Cuerpo: ' . substr($xml, 0, 500),
            );
        }

        $estado = $this->primerTextoTag($doc, 'ESTADO');
        $glosa  = $this->primerTextoTag($doc, 'GLOSA');

        $datos = [];
        foreach (['SEMILLA', 'TOKEN'] as $clave) {
            $valor = $this->primerTextoTag($doc, $clave);
            if ($valor !== '') {
                $datos[$clave] = $valor;
            }
        }

        return ['estado' => $estado, 'glosa' => $glosa, 'datos' => $datos];
    }

    private function primerTextoTag(DOMDocument $doc, string $tag): string
    {
        $node = $doc->getElementsByTagName($tag)->item(0);
        return $node === null ? '' : trim((string) $node->textContent);
    }

    /**
     * @param array{estado: string, glosa: string, datos: array<string,string>} $resp
     */
    private function garantizarEstadoOk(array $resp): void
    {
        if ($resp['estado'] !== '00') {
            throw new SiiAutenticacionException($resp['estado'], $resp['glosa']);
        }
    }

    private function baseUrl(Ambiente $ambiente): string
    {
        return self::HOSTS[$ambiente->value];
    }
}
