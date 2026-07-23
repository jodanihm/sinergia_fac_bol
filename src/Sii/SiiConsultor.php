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
use Plantiflex\FacturacionCl\Exceptions\SiiAutenticacionException;

/**
 * Consulta el estado de un ENVIO al SII por TrackID (servicio QueryEstUp.jws).
 *
 * NO consume folios: solo consulta el resultado de un envio ya subido.
 *
 * Mismo patron SOAP que SiiAutenticador: POST con SOAPAction vacio, y la
 * respuesta es un sobre SOAP cuyo retorno (getEstUpReturn) contiene OTRO XML
 * escapado con RESP_HDR (ESTADO, GLOSA) y RESP_BODY.
 *
 * Estados tipicos: EPR (envio procesado), RCT/RFR/RSC (rechazos), -11 (en
 * proceso), etc. Aqui NO se lanza excepcion por el estado de negocio: se
 * devuelve tal cual para que el caller decida. Solo se lanza por fallos de
 * transporte/parseo.
 */
final class SiiConsultor
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
     * @return array{estado: string, glosa: string, raw: string}
     */
    public function consultarEnvio(
        string $rutEmisor,
        string $trackId,
        string $token,
        Ambiente $ambiente,
    ): array {
        [$rut, $dv] = $this->separarRutDv($rutEmisor);

        $url = $this->baseUrl($ambiente) . '/DTEWS/QueryEstUp.jws';
        $sobre = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"'
            . ' xmlns:def="http://DefaultNamespace">'
            . '<soapenv:Body>'
            . '<def:getEstUp>'
            . '<Rut>' . $rut . '</Rut>'
            . '<Dv>' . $dv . '</Dv>'
            . '<TrackId>' . $trackId . '</TrackId>'
            . '<Token>' . $token . '</Token>'
            . '</def:getEstUp>'
            . '</soapenv:Body>'
            . '</soapenv:Envelope>';

        $raw      = $this->soapPost($url, $sobre);
        $innerXml = $this->extraerXmlInterno($raw, 'getEstUpReturn');
        $doc      = $this->cargarXmlSeguro($innerXml);

        return [
            'estado' => $this->primerTexto($doc, 'ESTADO'),
            'glosa'  => $this->primerTexto($doc, 'GLOSA'),
            'raw'    => $raw,
        ];
    }

    /**
     * Consulta el estado de un DTE INDIVIDUAL por folio (QueryEstDte.jws, metodo
     * getEstDte). Devuelve el estado de ACEPTACION del documento: DOK (recibido
     * conforme), DNK (datos no coinciden) u otro. NO consume folios.
     *
     * Los datos (tipo/folio/fecha/receptor/monto) deben coincidir EXACTO con lo
     * emitido; si no, el SII responde DNK.
     *
     * @param string $fechaEmision FchEmis en YYYY-MM-DD (se convierte a dd-mm-aaaa).
     * @return array{estado:string, glosa:string, errCode:string, glosaErr:string, numAtencion:string, inner:string, raw:string}
     */
    public function consultarDte(
        string $rutConsultante,
        string $rutEmisor,
        string $rutReceptor,
        int $tipoDte,
        int $folio,
        string $fechaEmision,
        int $montoTotal,
        string $token,
        Ambiente $ambiente,
    ): array {
        [$rcN, $rcDv] = $this->separarRutDv($rutConsultante);
        [$coN, $coDv] = $this->separarRutDv($rutEmisor);
        [$reN, $reDv] = $this->separarRutDv($rutReceptor);

        // getEstDte espera la fecha en dd-mm-aaaa.
        [$y, $m, $d] = explode('-', $fechaEmision);
        $fecha = "{$d}-{$m}-{$y}";

        $url = $this->baseUrl($ambiente) . '/DTEWS/QueryEstDte.jws';
        $sobre = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"'
            . ' xmlns:def="http://DefaultNamespace">'
            . '<soapenv:Body><def:getEstDte>'
            . '<RutConsultante>' . $rcN . '</RutConsultante>'
            . '<DvConsultante>' . $rcDv . '</DvConsultante>'
            . '<RutCompania>' . $coN . '</RutCompania>'
            . '<DvCompania>' . $coDv . '</DvCompania>'
            . '<RutReceptor>' . $reN . '</RutReceptor>'
            . '<DvReceptor>' . $reDv . '</DvReceptor>'
            . '<TipoDte>' . $tipoDte . '</TipoDte>'
            . '<FolioDte>' . $folio . '</FolioDte>'
            . '<FechaEmisionDte>' . $fecha . '</FechaEmisionDte>'
            . '<MontoDte>' . $montoTotal . '</MontoDte>'
            . '<Token>' . $token . '</Token>'
            . '</def:getEstDte></soapenv:Body></soapenv:Envelope>';

        $raw      = $this->soapPost($url, $sobre);
        $innerXml = $this->extraerXmlInterno($raw, 'getEstDteReturn');
        $doc      = $this->cargarXmlSeguro($innerXml);

        return [
            'estado'      => $this->primerTexto($doc, 'ESTADO'),
            'glosa'       => $this->primerTexto($doc, 'GLOSA'),
            'errCode'     => $this->primerTexto($doc, 'ERR_CODE'),
            'glosaErr'    => $this->primerTexto($doc, 'GLOSA_ERR'),
            'numAtencion' => $this->primerTexto($doc, 'NUM_ATENCION'),
            'inner'       => $innerXml,
            'raw'         => $raw,
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
            throw new ConexionException('Fallo de conexion con SII (consulta): ' . $e->getMessage(), 0, $e);
        } catch (RequestException $e) {
            $r = $e->getResponse();
            if ($r === null) {
                throw new ConexionException('Fallo HTTP sin respuesta (consulta): ' . $e->getMessage(), 0, $e);
            }
            $response = $r;
        } catch (GuzzleException $e) {
            throw new ConexionException('Error Guzzle (consulta): ' . $e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        if ($status >= 500) {
            throw new ConexionException("SII respondio HTTP $status en consulta");
        }
        if ($status >= 400) {
            throw new SiiAutenticacionException('HTTP_' . $status, "SII respondio HTTP $status en consulta");
        }

        return (string) $response->getBody();
    }

    private function extraerXmlInterno(string $soap, string $tag): string
    {
        $doc = $this->cargarXmlSeguro($soap);
        $nodes = $doc->getElementsByTagName($tag);
        if ($nodes->length === 0 || $nodes->item(0) === null) {
            throw new SiiAutenticacionException('NO_TAG', "No se encontro <$tag> en la respuesta SOAP");
        }
        return trim((string) $nodes->item(0)->textContent);
    }

    private function cargarXmlSeguro(string $xml): DOMDocument
    {
        $doc  = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok   = $doc->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (! $ok) {
            throw new SiiAutenticacionException('XML_INVALIDO', 'No se pudo parsear la respuesta de consulta del SII');
        }
        return $doc;
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
