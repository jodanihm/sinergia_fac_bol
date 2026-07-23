<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Sii;

use DOMDocument;
use DOMElement;
use Plantiflex\FacturacionCl\Dto\Certificado;
use RuntimeException;

/**
 * Construye y firma la RespuestaDTE del intercambio (etapa 3 de certificacion).
 *
 *   - acuseRecibo()       -> bloque <RecepcionEnvio> (acuse de recibo del envio)
 *   - aceptacionRechazo() -> bloque <ResultadoDTE>   (aceptacion/rechazo comercial)
 *
 * Firma XMLDSIG sobre <Resultado ID="ResultadoEnvio"> via XmlSigner::firmarNodo.
 * Salida ISO-8859-1; la firma se calcula sobre C14N (UTF-8), independiente del
 * encoding del archivo. Las glosas con acento usan \u{ED} para mantener la
 * fuente en ASCII puro.
 */
final class RespuestaDteBuilder
{
    private const NS_SII       = 'http://www.sii.cl/SiiDte';
    private const XSI          = 'http://www.w3.org/2001/XMLSchema-instance';
    private const XMLNS        = 'http://www.w3.org/2000/xmlns/';
    private const RESULTADO_ID = 'ResultadoEnvio';

    private const GLOSA_ENVIO = [
        0  => "Env\u{ED}o Recibido Conforme",
        1  => "Env\u{ED}o Rechazado - Error de Schema",
        2  => "Env\u{ED}o Rechazado - Error de Firma",
        3  => "Env\u{ED}o Rechazado - RUT Receptor No Corresponde",
        90 => "Env\u{ED}o Rechazado - Archivo Repetido",
        91 => "Env\u{ED}o Rechazado - Archivo Ilegible",
        99 => "Env\u{ED}o Rechazado - Otros",
    ];
    private const GLOSA_DOC = [
        0  => 'DTE Recibido OK',
        1  => 'DTE No Recibido - Error de Firma',
        2  => 'DTE No Recibido - Error en RUT Emisor',
        3  => 'DTE No Recibido - Error en RUT Receptor',
        4  => 'DTE No Recibido - DTE Repetido',
        99 => 'DTE No Recibido - Otros',
    ];
    private const GLOSA_RESP = [
        0 => 'ACEPTADO OK',
        1 => 'ACEPTADO CON DISCREPANCIAS',
        2 => 'RECHAZADO',
    ];

    public function __construct(private XmlSigner $signer)
    {
    }

    public function acuseRecibo(
        EnvioDteParser $envio,
        array $caratula,
        Certificado $cert,
        int $estadoEnvio = 0,
        int $estadoDocs = 0,
    ): string {
        $doc = $this->nuevoDoc();
        $resultado = $this->armarEsqueleto($doc, $caratula, 1);

        $recep = $this->el($doc, 'RecepcionEnvio');
        $this->add($doc, $recep, 'NmbEnvio', $envio->nmbEnvio);
        $this->add($doc, $recep, 'FchRecep', date('Y-m-d\TH:i:s'));
        $this->add($doc, $recep, 'CodEnvio', '1');
        $this->add($doc, $recep, 'EnvioDTEID', $envio->setDteId);
        if ($envio->digest !== '') {
            $this->add($doc, $recep, 'Digest', $envio->digest);
        }
        if (isset($envio->caratula['RutEmisor'])) {
            $this->add($doc, $recep, 'RutEmisor', $envio->caratula['RutEmisor']);
        }
        if (isset($envio->caratula['RutReceptor'])) {
            $this->add($doc, $recep, 'RutReceptor', $envio->caratula['RutReceptor']);
        }
        $this->add($doc, $recep, 'EstadoRecepEnv', (string) $estadoEnvio);
        $this->add($doc, $recep, 'RecepEnvGlosa', self::GLOSA_ENVIO[$estadoEnvio] ?? self::GLOSA_ENVIO[99]);
        $this->add($doc, $recep, 'NroDTE', (string) count($envio->documentos));

        foreach ($envio->documentos as $d) {
            $estadoDoc = ($d['RUTRecep'] === $caratula['RutResponde']) ? 0 : 3;
            $rd = $this->el($doc, 'RecepcionDTE');
            $this->add($doc, $rd, 'TipoDTE', $d['TipoDTE']);
            $this->add($doc, $rd, 'Folio', $d['Folio']);
            $this->add($doc, $rd, 'FchEmis', $d['FchEmis']);
            $this->add($doc, $rd, 'RUTEmisor', $d['RUTEmisor']);
            $this->add($doc, $rd, 'RUTRecep', $d['RUTRecep']);
            $this->add($doc, $rd, 'MntTotal', $d['MntTotal']);
            $this->add($doc, $rd, 'EstadoRecepDTE', (string) $estadoDoc);
            $this->add($doc, $rd, 'RecepDTEGlosa', self::GLOSA_DOC[$estadoDoc] ?? self::GLOSA_DOC[99]);
            $recep->appendChild($rd);
        }
        $resultado->appendChild($recep);

        return $this->firmarYSerializar($doc, $resultado, $cert);
    }

    public function aceptacionRechazo(
        EnvioDteParser $envio,
        array $caratula,
        Certificado $cert,
        int $estadoDte = 0,
    ): string {
        $doc = $this->nuevoDoc();
        $resultado = $this->armarEsqueleto($doc, $caratula, count($envio->documentos));

        $i = 1;
        foreach ($envio->documentos as $d) {
            $estadoDoc = ($d['RUTRecep'] === $caratula['RutResponde']) ? 0 : 2;
            $rd = $this->el($doc, 'ResultadoDTE');
            $this->add($doc, $rd, 'TipoDTE', $d['TipoDTE']);
            $this->add($doc, $rd, 'Folio', $d['Folio']);
            $this->add($doc, $rd, 'FchEmis', $d['FchEmis']);
            $this->add($doc, $rd, 'RUTEmisor', $d['RUTEmisor']);
            $this->add($doc, $rd, 'RUTRecep', $d['RUTRecep']);
            $this->add($doc, $rd, 'MntTotal', $d['MntTotal']);
            $this->add($doc, $rd, 'CodEnvio', (string) $i++);
            $this->add($doc, $rd, 'EstadoDTE', (string) $estadoDoc);
            $this->add($doc, $rd, 'EstadoDTEGlosa', self::GLOSA_RESP[$estadoDoc] ?? self::GLOSA_RESP[2]);
            $resultado->appendChild($rd);
        }

        return $this->firmarYSerializar($doc, $resultado, $cert);
    }

    private function nuevoDoc(): DOMDocument
    {
        $doc = new DOMDocument('1.0', 'ISO-8859-1');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = false;
        return $doc;
    }

    private function armarEsqueleto(DOMDocument $doc, array $caratula, int $nroDetalles): DOMElement
    {
        $respuesta = $doc->createElementNS(self::NS_SII, 'RespuestaDTE');
        $respuesta->setAttributeNS(self::XMLNS, 'xmlns:xsi', self::XSI);
        $respuesta->setAttributeNS(self::XSI, 'xsi:schemaLocation', self::NS_SII . ' RespuestaEnvioDTE_v10.xsd');
        $respuesta->setAttribute('version', '1.0');
        $doc->appendChild($respuesta);

        $resultado = $this->el($doc, 'Resultado');
        $resultado->setAttribute('ID', self::RESULTADO_ID);
        $respuesta->appendChild($resultado);

        $car = $this->el($doc, 'Caratula');
        $car->setAttribute('version', '1.0');
        $this->add($doc, $car, 'RutResponde', (string) $caratula['RutResponde']);
        $this->add($doc, $car, 'RutRecibe', (string) $caratula['RutRecibe']);
        $this->add($doc, $car, 'IdRespuesta', (string) ($caratula['IdRespuesta'] ?? 1));
        $this->add($doc, $car, 'NroDetalles', (string) $nroDetalles);
        if (! empty($caratula['NmbContacto'])) {
            $this->add($doc, $car, 'NmbContacto', (string) $caratula['NmbContacto']);
        }
        if (! empty($caratula['FonoContacto'])) {
            $this->add($doc, $car, 'FonoContacto', (string) $caratula['FonoContacto']);
        }
        if (! empty($caratula['MailContacto'])) {
            $this->add($doc, $car, 'MailContacto', (string) $caratula['MailContacto']);
        }
        $this->add($doc, $car, 'TmstFirmaResp', date('Y-m-d\TH:i:s'));
        $resultado->appendChild($car);

        return $resultado;
    }

    private function el(DOMDocument $doc, string $name): DOMElement
    {
        return $doc->createElementNS(self::NS_SII, $name);
    }

    private function add(DOMDocument $doc, DOMElement $parent, string $name, string $value): DOMElement
    {
        $el = $doc->createElementNS(self::NS_SII, $name);
        $el->textContent = $value;
        $parent->appendChild($el);
        return $el;
    }

    private function firmarYSerializar(DOMDocument $doc, DOMElement $resultado, Certificado $cert): string
    {
        $this->signer->firmarNodo($resultado, self::RESULTADO_ID, $cert);
        $xml = $doc->saveXML();
        if ($xml === false) {
            throw new RuntimeException('RespuestaDteBuilder: saveXML fallo');
        }
        return $xml;
    }
}
