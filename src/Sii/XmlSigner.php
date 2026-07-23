<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Sii;

use DOMDocument;
use DOMElement;
use Plantiflex\FacturacionCl\Dto\Certificado;
use RuntimeException;

/**
 * Firma XML-DSig "enveloped signature" para el SII chileno.
 *
 * RSA-SHA1, C14N clasica. Reglas confirmadas contra un DTE de LibreDTE aceptado
 * por el SII:
 *   - Transform (Reference): enveloped-signature, tanto en Documento como SetDTE.
 *   - El SII valida la firma del Documento EXTRAYENDOLO del sobre => SIN el xsi
 *     heredado del EnvioDTE.
 *   - El SetDTE se valida en el sobre => CON xsi.
 *
 *   CanonicalizationMethod: http://www.w3.org/TR/2001/REC-xml-c14n-20010315
 *   SignatureMethod       : http://www.w3.org/2000/09/xmldsig#rsa-sha1
 *   DigestMethod          : http://www.w3.org/2000/09/xmldsig#sha1
 *   Transform             : http://www.w3.org/2000/09/xmldsig#enveloped-signature
 *
 * REGLA DE ORO: SignedInfo se canonicaliza SOLO en su contexto final dentro del
 * documento; C14N hereda namespaces de los ancestros.
 */
final class XmlSigner
{
    private const NS_DSIG    = 'http://www.w3.org/2000/09/xmldsig#';
    private const ALGO_C14N  = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';
    private const ALGO_SIG   = 'http://www.w3.org/2000/09/xmldsig#rsa-sha1';
    private const ALGO_DIG   = 'http://www.w3.org/2000/09/xmldsig#sha1';
    private const ALGO_ENVEL = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';

    public function firmarSemilla(string $semilla, Certificado $cert): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = false;

        $getToken = $doc->createElement('getToken');
        $item     = $doc->createElement('item');
        $item->appendChild($doc->createElement('Semilla', $semilla));
        $getToken->appendChild($item);
        $doc->appendChild($getToken);

        $digestValue = base64_encode(sha1($doc->C14N(), true));
        $signature   = $this->construirSignatureSinFirma($doc, '', $digestValue, $cert, self::ALGO_ENVEL);
        $doc->documentElement->appendChild($signature);
        $this->firmarEnContexto($signature, $cert);

        return $doc->saveXML($doc->documentElement);
    }

    public function firmarNodo(DOMElement $nodoAFirmar, string $idReferencia, Certificado $cert): void
    {
        $doc = $nodoAFirmar->ownerDocument;
        if ($doc === null) {
            throw new RuntimeException('XmlSigner: el nodo a firmar no pertenece a un documento');
        }
        if ($nodoAFirmar->parentNode === null) {
            throw new RuntimeException('XmlSigner: el nodo a firmar no tiene padre (no se puede insertar Signature hermano)');
        }
        if (! $nodoAFirmar->hasAttribute('ID')) {
            throw new RuntimeException('XmlSigner: el nodo a firmar no tiene atributo ID');
        }

        $nodoAFirmar->setIdAttribute('ID', true);

        $digestValue = base64_encode(sha1($nodoAFirmar->C14N(), true));

        // Transform enveloped-signature (perfil que el SII valida). La Signature es
        // hermana del nodo, asi que no remueve nada: el digest queda SHA1(C14N(nodo)).
        $signature = $this->construirSignatureSinFirma($doc, '#' . $idReferencia, $digestValue, $cert, self::ALGO_ENVEL);
        $nodoAFirmar->parentNode->insertBefore($signature, $nodoAFirmar->nextSibling);
        $this->firmarEnContexto($signature, $cert);
    }

    /**
     * Inserta el ESQUELETO de la <Signature> (sin DigestValue ni SignatureValue),
     * como hermano de $nodoAFirmar. Transform enveloped-signature. El digest y la
     * firma se calculan despues con calcularDigestYFirmar().
     */
    public function insertarEsqueletoFirma(DOMElement $nodoAFirmar, string $idReferencia, Certificado $cert): void
    {
        $doc = $nodoAFirmar->ownerDocument;
        if ($doc === null) {
            throw new RuntimeException('XmlSigner: el nodo a firmar no pertenece a un documento');
        }
        if ($nodoAFirmar->parentNode === null) {
            throw new RuntimeException('XmlSigner: el nodo a firmar no tiene padre');
        }
        if (! $nodoAFirmar->hasAttribute('ID')) {
            throw new RuntimeException('XmlSigner: el nodo a firmar no tiene atributo ID');
        }

        $signature = $this->construirSignatureSinFirma($doc, '#' . $idReferencia, '', $cert, self::ALGO_ENVEL);
        $nodoAFirmar->parentNode->insertBefore($signature, $nodoAFirmar->nextSibling);
    }

    public function congelar(DOMDocument $doc): void
    {
        $xml = (string) $doc->saveXML();
        if ($doc->loadXML($xml) === false) {
            throw new RuntimeException('XmlSigner::congelar: no se pudo re-parsear el XML');
        }
    }

    public function calcularDigestYFirmar(DOMDocument $doc, string $idReferencia, Certificado $cert): void
    {
        $signatureLive = $this->buscarSignaturePorUri($doc, '#' . $idReferencia);
        $dvLive = $signatureLive->getElementsByTagNameNS(self::NS_DSIG, 'DigestValue')->item(0);
        $svLive = $signatureLive->getElementsByTagNameNS(self::NS_DSIG, 'SignatureValue')->item(0);
        if (! $dvLive instanceof DOMElement || ! $svLive instanceof DOMElement) {
            throw new RuntimeException('XmlSigner: Signature sin DigestValue/SignatureValue');
        }

        $esDocumento = ($this->buscarNodoPorId($doc, $idReferencia)->localName === 'Documento');

        // --- DigestValue ---
        if ($esDocumento) {
            // El SII valida cada <Documento> como un DTE INDIVIDUAL standalone, SIN
            // el xmlns del EnvioDTE. El digest = SHA1 del <Documento> canonicalizado
            // FUERA del sobre (sin el namespace heredado). Hacer C14N del nodo dentro
            // del EnvioDTE agrega xmlns="http://www.sii.cl/SiiDte" y produce un digest
            // que el SII NO recomputa -> DTE-3-505. Esto replica como LibreDTE firma
            // el DTE individual antes de ensamblar el envio.
            $xml = (string) $doc->saveXML();
            if (! mb_check_encoding($xml, 'UTF-8')) {
                $xml = mb_convert_encoding($xml, 'UTF-8', 'ISO-8859-1');
            }
            $marca = '<Documento ID="' . $idReferencia . '"';
            $ini = strpos($xml, $marca);
            if ($ini === false) {
                throw new RuntimeException('XmlSigner: no se ubico el <Documento> para el digest');
            }
            $cierre = strpos($xml, '</Documento>', $ini);
            if ($cierre === false) {
                throw new RuntimeException('XmlSigner: no se ubico el cierre del <Documento>');
            }
            $documentoStr = substr($xml, $ini, $cierre + strlen('</Documento>') - $ini);
            // Aislar el <Documento> en un DTE sin xmlns => queda SIN namespace, igual
            // que el contexto en que el SII lo canonicaliza. C14N emite UTF-8.
            $tmp = new DOMDocument();
            if ($tmp->loadXML('<?xml version="1.0" encoding="UTF-8"?><DTE>' . $documentoStr . '</DTE>') === false) {
                throw new RuntimeException('XmlSigner: no se pudo aislar el <Documento> para el digest');
            }
            $nodoSA = $tmp->getElementsByTagName('Documento')->item(0);
            if (! $nodoSA instanceof DOMElement) {
                throw new RuntimeException('XmlSigner: Documento aislado no encontrado');
            }
            $c14nNodo = $nodoSA->C14N();
        } else {
            // SetDTE: el SII recomputa con C14N del nodo DENTRO del EnvioDTE (con los
            // namespaces heredados). Coincide con DOMNode::C14N() en contexto.
            $xmlA = self::limpiarPrefijosDsig((string) $doc->saveXML());
            $copiaA = new DOMDocument();
            if ($copiaA->loadXML($xmlA) === false) {
                throw new RuntimeException('XmlSigner: no se pudo copiar el documento para el digest');
            }
            $nodoCopia = $this->buscarNodoPorId($copiaA, $idReferencia);
            $c14nNodo = $nodoCopia->C14N();
        }
        if ($c14nNodo === false) {
            throw new RuntimeException('XmlSigner: C14N del nodo fallo');
        }
        $dvLive->textContent = base64_encode(sha1($c14nNodo, true));

        // --- SignatureValue (firma del SignedInfo, ya con el DigestValue escrito) ---
        $xmlB = self::limpiarPrefijosDsig((string) $doc->saveXML());
        if ($esDocumento) {
            $xmlB = self::quitarXsiDelRoot($xmlB);
        }
        $copiaB = new DOMDocument();
        if ($copiaB->loadXML($xmlB) === false) {
            throw new RuntimeException('XmlSigner: no se pudo copiar el documento para la firma');
        }
        $sigCopia = $this->buscarSignaturePorUri($copiaB, '#' . $idReferencia);
        $siCopia  = $sigCopia->getElementsByTagNameNS(self::NS_DSIG, 'SignedInfo')->item(0);
        if (! $siCopia instanceof DOMElement) {
            throw new RuntimeException('XmlSigner: SignedInfo no encontrado en la copia');
        }
        $c14nSignedInfo = $siCopia->C14N();
        if ($c14nSignedInfo === false) {
            throw new RuntimeException('XmlSigner: C14N del SignedInfo fallo');
        }

        $pkey = openssl_pkey_get_private($cert->pkeyData);
        if ($pkey === false) {
            throw new RuntimeException('XmlSigner: llave privada invalida o no soportada');
        }
        $sigBin = '';
        if (! openssl_sign($c14nSignedInfo, $sigBin, $pkey, OPENSSL_ALGO_SHA1)) {
            throw new RuntimeException('XmlSigner: openssl_sign fallo');
        }
        $svLive->textContent = $this->chunk64(base64_encode($sigBin));
    }

    private function buscarNodoPorId(DOMDocument $doc, string $id): DOMElement
    {
        foreach ($doc->getElementsByTagName('*') as $el) {
            if ($el instanceof DOMElement && $el->getAttribute('ID') === $id) {
                return $el;
            }
        }
        throw new RuntimeException("XmlSigner: no se encontro nodo con ID=$id");
    }

    private function buscarSignaturePorUri(DOMDocument $doc, string $uri): DOMElement
    {
        foreach ($doc->getElementsByTagNameNS(self::NS_DSIG, 'Signature') as $sig) {
            if (! $sig instanceof DOMElement) {
                continue;
            }
            $ref = $sig->getElementsByTagNameNS(self::NS_DSIG, 'Reference')->item(0);
            if ($ref instanceof DOMElement && $ref->getAttribute('URI') === $uri) {
                return $sig;
            }
        }
        throw new RuntimeException("XmlSigner: no se encontro Signature con Reference URI=$uri");
    }

    public static function limpiarPrefijosDsig(string $xml): string
    {
        $xml = (string) preg_replace(
            '/ xmlns:default\d*="http:\/\/www\.w3\.org\/2000\/09\/xmldsig#"/',
            '',
            $xml,
        );
        $xml = (string) preg_replace('/<(\/?)default\d*:/', '<$1', $xml);
        return $xml;
    }

    private static function quitarXsiDelRoot(string $xml): string
    {
        $xml = preg_replace('/ xmlns:xsi="[^"]*"/', '', $xml, 1);
        return preg_replace('/ xsi:schemaLocation="[^"]*"/', '', $xml, 1);
    }

    private function construirSignatureSinFirma(
        DOMDocument $destino,
        string $referenceUri,
        string $digestValue,
        Certificado $cert,
        string $transformAlgo,
    ): DOMElement {
        $signature = $destino->createElementNS(self::NS_DSIG, 'Signature');

        $signedInfo = $destino->createElementNS(self::NS_DSIG, 'SignedInfo');
        // El SII canonicaliza el SignedInfo del SetDTE y del ConsumoFolios (RVD)
        // como subarbol STANDALONE (desconectado del sobre raiz), por lo que pierde
        // el xmlns:xsi heredado del root. Hay que declararlo LOCALMENTE en el propio
        // SignedInfo del SetDTE/RVD (como hace LibreDTE) para que el C14N standalone
        // del SII lo conserve y la firma cuadre. Sin esto el SII rechaza con
        // DTE-3-505: la firma se calcula con xsi en contexto, pero el verificador
        // standalone no lo ve. El <Documento> se valida sin xsi, asi que NO lleva
        // esta declaracion.
        if ($referenceUri === '#SetDoc' || str_starts_with($referenceUri, '#RVD_')) {
            $signedInfo->setAttributeNS(
                'http://www.w3.org/2000/xmlns/',
                'xmlns:xsi',
                'http://www.w3.org/2001/XMLSchema-instance',
            );
        }
        $signature->appendChild($signedInfo);

        $cm = $destino->createElementNS(self::NS_DSIG, 'CanonicalizationMethod');
        $cm->setAttribute('Algorithm', self::ALGO_C14N);
        $signedInfo->appendChild($cm);

        $sm = $destino->createElementNS(self::NS_DSIG, 'SignatureMethod');
        $sm->setAttribute('Algorithm', self::ALGO_SIG);
        $signedInfo->appendChild($sm);

        $ref = $destino->createElementNS(self::NS_DSIG, 'Reference');
        $ref->setAttribute('URI', $referenceUri);
        $signedInfo->appendChild($ref);

        $transforms = $destino->createElementNS(self::NS_DSIG, 'Transforms');
        $ref->appendChild($transforms);
        $tr = $destino->createElementNS(self::NS_DSIG, 'Transform');
        $tr->setAttribute('Algorithm', $transformAlgo);
        $transforms->appendChild($tr);

        $dm = $destino->createElementNS(self::NS_DSIG, 'DigestMethod');
        $dm->setAttribute('Algorithm', self::ALGO_DIG);
        $ref->appendChild($dm);

        $ref->appendChild($destino->createElementNS(self::NS_DSIG, 'DigestValue', $digestValue));

        $signature->appendChild($destino->createElementNS(self::NS_DSIG, 'SignatureValue', ''));

        $pkey = openssl_pkey_get_private($cert->pkeyData);
        if ($pkey === false) {
            throw new RuntimeException('XmlSigner: llave privada invalida o no soportada');
        }
        $details = openssl_pkey_get_details($pkey);
        if ($details === false || ! isset($details['rsa']['n'], $details['rsa']['e'])) {
            throw new RuntimeException('XmlSigner: no se pudieron extraer modulus/exponent de la llave RSA');
        }

        $keyInfo  = $destino->createElementNS(self::NS_DSIG, 'KeyInfo');
        $keyValue = $destino->createElementNS(self::NS_DSIG, 'KeyValue');
        $rsa      = $destino->createElementNS(self::NS_DSIG, 'RSAKeyValue');
        $rsa->appendChild($destino->createElementNS(self::NS_DSIG, 'Modulus', $this->chunk64(base64_encode((string) $details['rsa']['n']))));
        $rsa->appendChild($destino->createElementNS(self::NS_DSIG, 'Exponent', $this->chunk64(base64_encode((string) $details['rsa']['e']))));
        $keyValue->appendChild($rsa);
        $keyInfo->appendChild($keyValue);

        $x509Data = $destino->createElementNS(self::NS_DSIG, 'X509Data');
        $x509Data->appendChild(
            $destino->createElementNS(self::NS_DSIG, 'X509Certificate', $this->chunk64($this->certEnUnaSolaLinea($cert->certData))),
        );
        $keyInfo->appendChild($x509Data);
        $signature->appendChild($keyInfo);

        return $signature;
    }

    private function firmarEnContexto(DOMElement $signature, Certificado $cert): void
    {
        $signedInfo = $signature->getElementsByTagNameNS(self::NS_DSIG, 'SignedInfo')->item(0);
        $sigValue   = $signature->getElementsByTagNameNS(self::NS_DSIG, 'SignatureValue')->item(0);
        if (! $signedInfo instanceof DOMElement || ! $sigValue instanceof DOMElement) {
            throw new RuntimeException('XmlSigner: estructura de Signature invalida al firmar');
        }

        $canonical = $signedInfo->C14N();

        $pkey = openssl_pkey_get_private($cert->pkeyData);
        if ($pkey === false) {
            throw new RuntimeException('XmlSigner: llave privada invalida o no soportada');
        }
        $sigBin = '';
        if (! openssl_sign($canonical, $sigBin, $pkey, OPENSSL_ALGO_SHA1)) {
            throw new RuntimeException('XmlSigner: openssl_sign fallo');
        }

        $sigValue->textContent = $this->chunk64(base64_encode($sigBin));
    }

    private function chunk64(string $base64): string
    {
        return rtrim(chunk_split($base64, 64, "\n"), "\n");
    }

    private function certEnUnaSolaLinea(string $pem): string
    {
        $clean = preg_replace(
            '/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/',
            '',
            $pem,
        );
        return (string) $clean;
    }
}
