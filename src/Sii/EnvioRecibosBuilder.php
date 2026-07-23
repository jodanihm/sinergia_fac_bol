<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Sii;

use DOMDocument;
use DOMElement;
use Plantiflex\FacturacionCl\Dto\Certificado;
use RuntimeException;

final class EnvioRecibosBuilder
{
    private const NS_SII = 'http://www.sii.cl/SiiDte';
    private const XSI    = 'http://www.w3.org/2001/XMLSchema-instance';
    private const XMLNS  = 'http://www.w3.org/2000/xmlns/';
    private const SET_ID = 'SetDteRecibidos';
    private const DECLARACION = 'El acuse de recibo que se declara en este acto, de acuerdo a lo dispuesto en la letra b) del Art. 4, y la letra c) del Art. 5 de la Ley 19.983, acredita que la entrega de mercaderias o servicio(s) prestado(s) ha(n) sido recibido(s).';

    public function __construct(private XmlSigner $signer)
    {
    }

    public function generar(
        EnvioDteParser $envio,
        array $caratula,
        Certificado $cert,
        string $recinto,
        string $rutFirma,
    ): string {
        $recibos = [];
        foreach ($envio->documentos as $d) {
            if ($d['RUTRecep'] !== $caratula['RutResponde']) {
                continue; // recibo solo para DTE dirigidos a nosotros
            }
            $recibos[] = $this->reciboFirmado($d, $cert, $recinto, $rutFirma);
        }
        if ($recibos === []) {
            throw new RuntimeException('EnvioRecibosBuilder: ningun documento dirigido a ' . $caratula['RutResponde']);
        }

        $doc = $this->nuevoDoc();
        $env = $doc->createElementNS(self::NS_SII, 'EnvioRecibos');
        $env->setAttributeNS(self::XMLNS, 'xmlns:xsi', self::XSI);
        $env->setAttributeNS(self::XSI, 'xsi:schemaLocation', self::NS_SII . ' EnvioRecibo_v10.xsd');
        $env->setAttribute('version', '1.0');
        $doc->appendChild($env);

        $set = $doc->createElementNS(self::NS_SII, 'SetRecibos');
        $set->setAttribute('ID', self::SET_ID);
        $env->appendChild($set);
        $set->appendChild($this->caratulaEl($doc, $caratula));
        $set->appendChild($doc->createElementNS(self::NS_SII, 'Recibo'));

        $envXml = (string) $doc->saveXML();

        $marca = '<Recibo/>';
        if (strpos($envXml, $marca) === false) {
            throw new RuntimeException('EnvioRecibosBuilder: no se encontro el placeholder <Recibo/>');
        }
        $envXml = str_replace($marca, implode("\n", $recibos), $envXml);

        $doc2 = new DOMDocument();
        $doc2->preserveWhiteSpace = false;
        if ($doc2->loadXML($envXml) === false) {
            throw new RuntimeException('EnvioRecibosBuilder: no se pudo re-parsear el sobre');
        }
        $set2 = $this->buscarPorId($doc2, self::SET_ID);
        $this->signer->firmarNodo($set2, self::SET_ID, $cert);

        $xml = (string) $doc2->saveXML();
        if ($xml === '') {
            throw new RuntimeException('EnvioRecibosBuilder: saveXML fallo');
        }
        return $xml;
    }

    private function reciboFirmado(array $d, Certificado $cert, string $recinto, string $rutFirma): string
    {
        $id = 'T' . $d['TipoDTE'] . 'F' . $d['Folio'];
        $doc = $this->nuevoDoc();
        $recibo = $doc->createElement('Recibo');
        $recibo->setAttribute('version', '1.0');
        $doc->appendChild($recibo);

        $dr = $doc->createElement('DocumentoRecibo');
        $dr->setAttribute('ID', $id);
        $recibo->appendChild($dr);
        $this->add($doc, $dr, 'TipoDoc', $d['TipoDTE']);
        $this->add($doc, $dr, 'Folio', $d['Folio']);
        $this->add($doc, $dr, 'FchEmis', $d['FchEmis']);
        $this->add($doc, $dr, 'RUTEmisor', $d['RUTEmisor']);
        $this->add($doc, $dr, 'RUTRecep', $d['RUTRecep']);
        $this->add($doc, $dr, 'MntTotal', $d['MntTotal']);
        $this->add($doc, $dr, 'Recinto', $recinto);
        $this->add($doc, $dr, 'RutFirma', $rutFirma);
        $this->add($doc, $dr, 'Declaracion', self::DECLARACION);
        $this->add($doc, $dr, 'TmstFirmaRecibo', date('Y-m-d\TH:i:s'));

        $this->signer->firmarNodo($dr, $id, $cert);

        return (string) $doc->saveXML($doc->documentElement);
    }

    private function caratulaEl(DOMDocument $doc, array $caratula): DOMElement
    {
        $car = $doc->createElementNS(self::NS_SII, 'Caratula');
        $car->setAttribute('version', '1.0');
        $this->add($doc, $car, 'RutResponde', (string) $caratula['RutResponde'], true);
        $this->add($doc, $car, 'RutRecibe', (string) $caratula['RutRecibe'], true);
        if (! empty($caratula['NmbContacto'])) {
            $this->add($doc, $car, 'NmbContacto', (string) $caratula['NmbContacto'], true);
        }
        if (! empty($caratula['FonoContacto'])) {
            $this->add($doc, $car, 'FonoContacto', (string) $caratula['FonoContacto'], true);
        }
        if (! empty($caratula['MailContacto'])) {
            $this->add($doc, $car, 'MailContacto', (string) $caratula['MailContacto'], true);
        }
        $this->add($doc, $car, 'TmstFirmaEnv', date('Y-m-d\TH:i:s'), true);
        return $car;
    }

    private function nuevoDoc(): DOMDocument
    {
        $doc = new DOMDocument('1.0', 'ISO-8859-1');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = false;
        return $doc;
    }

    private function add(DOMDocument $doc, DOMElement $parent, string $name, string $value, bool $conNs = false): DOMElement
    {
        $el = $conNs ? $doc->createElementNS(self::NS_SII, $name) : $doc->createElement($name);
        $el->textContent = $value;
        $parent->appendChild($el);
        return $el;
    }

    private function buscarPorId(DOMDocument $doc, string $id): DOMElement
    {
        foreach ($doc->getElementsByTagName('*') as $el) {
            if ($el instanceof DOMElement && $el->getAttribute('ID') === $id) {
                return $el;
            }
        }
        throw new RuntimeException("EnvioRecibosBuilder: no se encontro nodo ID=$id");
    }
}
