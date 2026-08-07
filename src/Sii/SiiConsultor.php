<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Sii;

use DOMDocument;
use DOMElement;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use InvalidArgumentException;
use Plantiflex\FacturacionCl\Dto\EstadisticaEnvioSii;
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
 *
 *
 * EL RESP_BODY DE getEstUp, MEDIDO SOBRE UNA RESPUESTA REAL
 * -----------------------------------------------------------------------------
 * Hasta esta entrega se leian ESTADO y GLOSA y se tiraba el resto. El resto es
 * lo que dice si un sobre EPR trae documentos rechazados adentro. Respuesta real
 * capturada del log del runner el 04-08-2026 (track 0253081988), desescapada:
 *
 *   <SII:RESPUESTA xmlns:SII="http://www.sii.cl/XMLSchema">
 *    <SII:RESP_BODY>
 *     <TIPO_DOCTO>33</TIPO_DOCTO><INFORMADOS>22</INFORMADOS><ACEPTADOS>22</ACEPTADOS><RECHAZADOS>0</RECHAZADOS><REPAROS>0</REPAROS>
 *     <TIPO_DOCTO>56</TIPO_DOCTO><INFORMADOS>2</INFORMADOS><ACEPTADOS>2</ACEPTADOS><RECHAZADOS>0</RECHAZADOS><REPAROS>0</REPAROS>
 *     <TIPO_DOCTO>61</TIPO_DOCTO><INFORMADOS>6</INFORMADOS><ACEPTADOS>3</ACEPTADOS><RECHAZADOS>0</RECHAZADOS><REPAROS>3</REPAROS>
 *    </SII:RESP_BODY>
 *    <SII:RESP_HDR>
 *     <TRACKID>0253081988</TRACKID><ESTADO>EPR</ESTADO><GLOSA>Envio Procesado</GLOSA><NUM_ATENCION>...</NUM_ATENCION>
 *    </SII:RESP_HDR>
 *   </SII:RESPUESTA>
 *
 * TRES COSAS QUE MANDAN SOBRE EL PARSEO, Y QUE NO SE DEDUCEN DE NINGUN XSD:
 *
 *   1. RESP_BODY VIENE PRIMERO. Por eso ESTADO y GLOSA se buscan DENTRO de
 *      RESP_HDR y no en todo el documento: hoy funciona porque este RESP_BODY no
 *      tiene ningun <ESTADO>, pero depender de eso es depender de que el SII no
 *      agregue un campo.
 *
 *   2. LOS BLOQUES SON PLANOS. No hay contenedor por tipo: son cinco etiquetas
 *      que se repiten en secuencia. Ver parsearEstadistica() para por que eso
 *      obliga a agrupar por TIPO_DOCTO y no a "leer los cuatro siguientes".
 *
 *   3. NO HAY FOLIOS. El SII dice CUANTOS rechazo, no CUALES.
 *
 * OJO, el XSD que NO describe esto: docs/29_Schema_XML_Respuesta_SII_Envios/
 * RespSII_v10.xsd es la RespuestaEnvio del INTERCAMBIO -- la que un receptor le
 * manda al emisor --, no el cuerpo de getEstUp. Sus nombres (TIPODOC, INFORMADO,
 * ACEPTA) no calzan con los de arriba y promete un REVISIONENVIO con folios que
 * aqui no llega. Queda anotado para que nadie vuelva a confundirlos.
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
     * @return array{estado: string, glosa: string, estadistica: list<EstadisticaEnvioSii>, raw: string}
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

        // ESTADO y GLOSA ACOTADOS A RESP_HDR. Antes se buscaban en todo el
        // documento con getElementsByTagName(...)->item(0), y el orden de los
        // bloques lo decidia todo. Si RESP_HDR no viniera, se cae al documento
        // entero: es exactamente lo que se hacia hasta hoy, asi que ninguna
        // respuesta que funcionaba antes deja de funcionar ahora.
        $hdr = $this->primerElementoLocal($doc, 'RESP_HDR');

        return [
            'estado'      => $this->textoEn($hdr, $doc, 'ESTADO'),
            'glosa'       => $this->textoEn($hdr, $doc, 'GLOSA'),
            'estadistica' => $this->parsearEstadistica($doc),
            'raw'         => $raw,
        ];
    }

    /**
     * Los contadores por tipo de documento del RESP_BODY.
     *
     * POR QUE NO SIRVE "ITERAR TIPO_DOCTO Y LEER LOS CUATRO SIGUIENTES"
     * -------------------------------------------------------------------------
     * Los bloques son planos, sin contenedor. Si a un bloque le faltara un campo,
     * leer por posicion haria que el bloque se comiera el TIPO_DOCTO del
     * siguiente y TODO lo que viene despues quedaria corrido un lugar. Un solo
     * campo ausente convertiria la respuesta entera en numeros equivocados --
     * numeros que ademas parecerian validos.
     *
     * Aqui se agrupa POR ETIQUETA, no por posicion: se recorren los hijos de
     * RESP_BODY en orden, un TIPO_DOCTO ABRE un bloque nuevo y cierra el
     * anterior, y cada contador conocido rellena el hueco que le corresponde en
     * el bloque abierto. Un campo que falta queda en null y muere DENTRO de su
     * bloque: el siguiente empieza limpio en su propio TIPO_DOCTO.
     *
     * Un valor no numerico ("", "N/A", basura) se trata igual que uno ausente.
     * No se convierte a 0: un 0 diria "no hubo rechazos", que es una afirmacion
     * que no podemos hacer sobre algo que no pudimos leer.
     *
     * Sin RESP_BODY -- los sobres que no son EPR no lo traen -- devuelve [].
     *
     * @return list<EstadisticaEnvioSii>
     */
    private function parsearEstadistica(DOMDocument $doc): array
    {
        $body = $this->primerElementoLocal($doc, 'RESP_BODY');
        if ($body === null) {
            return [];
        }

        $campos = [
            'INFORMADOS' => 'informados',
            'ACEPTADOS'  => 'aceptados',
            'RECHAZADOS' => 'rechazados',
            'REPAROS'    => 'reparos',
        ];

        $bloques = [];
        $actual  = null;

        foreach ($body->childNodes as $nodo) {
            if (! $nodo instanceof DOMElement) {
                continue;
            }
            $etiqueta = $nodo->localName;

            if ($etiqueta === 'TIPO_DOCTO') {
                if ($actual !== null) {
                    $bloques[] = $actual;
                }
                $actual = [
                    'tipoDocto'  => $this->enteroONull($nodo->textContent),
                    'informados' => null,
                    'aceptados'  => null,
                    'rechazados' => null,
                    'reparos'    => null,
                ];
                continue;
            }

            // Un contador ANTES del primer TIPO_DOCTO no tiene bloque al que
            // pertenecer. Se descarta en vez de inventarle uno: adivinar de que
            // tipo es seria peor que perderlo.
            if ($actual === null || ! isset($campos[$etiqueta])) {
                continue;
            }
            $actual[$campos[$etiqueta]] = $this->enteroONull($nodo->textContent);
        }

        if ($actual !== null) {
            $bloques[] = $actual;
        }

        return array_map(
            static fn (array $b): EstadisticaEnvioSii => new EstadisticaEnvioSii(
                $b['tipoDocto'],
                $b['informados'],
                $b['aceptados'],
                $b['rechazados'],
                $b['reparos'],
            ),
            $bloques,
        );
    }

    /**
     * Primer elemento cuyo NOMBRE LOCAL sea $local, tenga el prefijo que tenga.
     *
     * Los bloques que interesan vienen como <SII:RESP_HDR> y <SII:RESP_BODY>, con
     * prefijo. getElementsByTagName('RESP_HDR') los encuentra igual en PHP
     * -- compara contra el nombre local de libxml --, pero de eso no hay ninguna
     * prueba en este repo: todas las etiquetas que el codigo busca hoy (ESTADO,
     * GLOSA, TOKEN, SEMILLA) van SIN prefijo, asi que nadie ejercito nunca ese
     * camino. Y si esa suposicion fuera falsa, el fallo seria del peor tipo
     * posible: cero bloques, cero rechazados, y un sobre sucio pasando por bueno
     * exactamente igual que antes de esta entrega, sin ningun error a la vista.
     *
     * Recorrer con '*' y comparar localName no supone nada. El documento tiene
     * decenas de nodos, no miles.
     */
    private function primerElementoLocal(DOMDocument $doc, string $local): ?DOMElement
    {
        foreach ($doc->getElementsByTagName('*') as $el) {
            if ($el instanceof DOMElement && $el->localName === $local) {
                return $el;
            }
        }

        return null;
    }

    /** Entero no negativo, o null si el texto no es exactamente eso. */
    private function enteroONull(string $texto): ?int
    {
        $t = trim($texto);

        return $t !== '' && ctype_digit($t) ? (int) $t : null;
    }

    /** Texto de $tag dentro de $ambito; si no hay ambito, en todo el documento. */
    private function textoEn(?DOMElement $ambito, DOMDocument $doc, string $tag): string
    {
        if ($ambito !== null) {
            $node = $ambito->getElementsByTagName($tag)->item(0);
            if ($node !== null) {
                return trim((string) $node->textContent);
            }
        }

        return $this->primerTexto($doc, $tag);
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
