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
 * Implementa el flujo de autenticacion del SII chileno (3 pasos):
 *
 *   1. getSeed    -> POST a CrSeed.jws            -> SEMILLA (numero).
 *   2. firmar     -> XmlSigner                    -> XML <getToken> firmado.
 *   3. getToken   -> POST a GetTokenFromSeed.jws  -> TOKEN.
 *
 * Los dos endpoints son SOAP 1.1 con SOAPAction vacio. La respuesta es un sobre
 * SOAP cuyo elemento de retorno contiene OTRO XML escapado (HTML entities) que
 * a su vez tiene la respuesta real con RESP_BODY / RESP_HDR.
 *
 * URLs por ambiente:
 *   produccion    -> https://palena.sii.cl/DTEWS
 *   certificacion -> https://maullin.sii.cl/DTEWS
 */
final class SiiAutenticador
{
    /** @var array<string,string> */
    private const HOSTS = [
        'produccion'    => 'https://palena.sii.cl',
        'certificacion' => 'https://maullin.sii.cl',
    ];

    public function __construct(
        private readonly ClientInterface $http,
        private readonly XmlSigner $signer,
    ) {
    }

    public function obtenerToken(Certificado $cert, Ambiente $ambiente): string
    {
        $semilla     = $this->getSeed($ambiente);
        $xmlFirmado  = $this->signer->firmarSemilla($semilla, $cert);
        return $this->getToken($xmlFirmado, $ambiente);
    }

    // ------------------------------------------------------------------
    // Paso 1: pedir semilla
    // ------------------------------------------------------------------

    private function getSeed(Ambiente $ambiente): string
    {
        $url = $this->baseUrl($ambiente) . '/DTEWS/CrSeed.jws';
        $sobre = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<SOAP-ENV:Body><getSeed/></SOAP-ENV:Body>'
            . '</SOAP-ENV:Envelope>';

        $response = $this->soapPost($url, $sobre);
        $resp = $this->parseRespuestaSii($this->extraerXmlInterno($response, 'getSeedReturn'));

        $this->garantizarEstadoOk($resp);
        if (! isset($resp['datos']['SEMILLA']) || $resp['datos']['SEMILLA'] === '') {
            throw new SiiAutenticacionException(
                $resp['estado'] ?: 'NO_SEMILLA',
                'SII no devolvio SEMILLA en RESP_BODY',
            );
        }
        return $resp['datos']['SEMILLA'];
    }

    // ------------------------------------------------------------------
    // Paso 3: pedir token enviando la semilla firmada
    // ------------------------------------------------------------------

    private function getToken(string $xmlFirmado, Ambiente $ambiente): string
    {
        $url = $this->baseUrl($ambiente) . '/DTEWS/GetTokenFromSeed.jws';
        $sobre = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<SOAP-ENV:Body>'
            . '<getToken><pszXml><![CDATA[' . $xmlFirmado . ']]></pszXml></getToken>'
            . '</SOAP-ENV:Body>'
            . '</SOAP-ENV:Envelope>';

        $response = $this->soapPost($url, $sobre);
        $resp = $this->parseRespuestaSii($this->extraerXmlInterno($response, 'getTokenReturn'));

        $this->garantizarEstadoOk($resp);
        if (! isset($resp['datos']['TOKEN']) || $resp['datos']['TOKEN'] === '') {
            throw new SiiAutenticacionException(
                $resp['estado'] ?: 'NO_TOKEN',
                'SII no devolvio TOKEN en RESP_BODY',
            );
        }
        return $resp['datos']['TOKEN'];
    }

    // ------------------------------------------------------------------
    // HTTP / parseo
    // ------------------------------------------------------------------

    private function soapPost(string $url, string $sobre): string
    {
        try {
            $response = $this->http->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'text/xml; charset=utf-8',
                    'SOAPAction'   => '""',
                ],
                'body'        => $sobre,
                'http_errors' => false,
            ]);
        } catch (ConnectException $e) {
            throw new ConexionException('Fallo de conexion con SII: ' . $e->getMessage(), 0, $e);
        } catch (RequestException $e) {
            $r = $e->getResponse();
            if ($r === null) {
                throw new ConexionException('Fallo HTTP sin respuesta: ' . $e->getMessage(), 0, $e);
            }
            $response = $r;
        } catch (GuzzleException $e) {
            throw new ConexionException('Error Guzzle: ' . $e->getMessage(), 0, $e);
        }

        $this->garantizarHttpOk($response);
        return (string) $response->getBody();
    }

    private function garantizarHttpOk(ResponseInterface $response): void
    {
        $status = $response->getStatusCode();
        if ($status >= 500) {
            throw new ConexionException("SII respondio HTTP $status");
        }
        if ($status >= 400) {
            throw new SiiAutenticacionException(
                'HTTP_' . $status,
                'SII respondio HTTP ' . $status,
            );
        }
    }

    /**
     * Extrae el contenido de texto de un tag del SOAP. Ese contenido es OTRO XML
     * que viene escapado con HTML entities (&lt; &gt; &quot;...).
     */
    private function extraerXmlInterno(string $soap, string $tag): string
    {
        $doc = $this->cargarXmlSeguro($soap, 'respuesta SOAP del SII');
        $nodes = $doc->getElementsByTagName($tag);
        if ($nodes->length === 0) {
            throw new SiiAutenticacionException(
                'NO_TAG',
                "No se encontro <$tag> en la respuesta SOAP",
            );
        }
        // El textContent ya viene des-escapeado por libxml.
        return trim((string) $nodes->item(0)->textContent);
    }

    /**
     * Parsea el XML interno con RESPUESTA / RESP_HDR / RESP_BODY del SII.
     *
     * @return array{estado: string, glosa: string, datos: array<string,string>}
     */
    private function parseRespuestaSii(string $innerXml): array
    {
        $doc = $this->cargarXmlSeguro($innerXml, 'respuesta interna del SII');

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

    private function cargarXmlSeguro(string $xml, string $contexto): DOMDocument
    {
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (! $ok) {
            throw new SiiAutenticacionException(
                'XML_INVALIDO',
                "No se pudo parsear $contexto",
            );
        }
        return $doc;
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
