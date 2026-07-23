<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Sii;

use DOMDocument;
use DOMElement;
use GuzzleHttp\ClientInterface;
use Plantiflex\FacturacionCl\Contracts\EmisorRepositoryInterface;
use Plantiflex\FacturacionCl\Dto\Certificado;
use Plantiflex\FacturacionCl\Dto\Credenciales;
use Plantiflex\FacturacionCl\Dto\Libro;
use Plantiflex\FacturacionCl\Exceptions\CredencialesInvalidasException;
use RuntimeException;

/**
 * Orquesta el envio de un Libro IECV al SII: obtiene cert + datos del emisor +
 * token, construye el LibroCompraVenta, firma el <EnvioLibro> (XML-DSig,
 * Reference URI="#LibroCV"), serializa a ISO-8859-1 y lo sube.
 *
 * Responsabilidad separada del DTE (SiiDirectoFacturador): los libros no usan
 * CAF ni folios y van a su propio uploader.
 */
final class LibroService
{
    private const NS_SII = 'http://www.sii.cl/SiiDte';

    private readonly XmlSigner $signer;
    private readonly LibroXmlBuilder $builder;
    private readonly SiiAutenticador $autenticador;
    private readonly LibroUploader $uploader;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly EmisorRepositoryInterface $emisor,
        ?XmlSigner $signer = null,
        ?LibroXmlBuilder $builder = null,
        ?SiiAutenticador $autenticador = null,
        ?LibroUploader $uploader = null,
    ) {
        $this->signer       = $signer ?? new XmlSigner();
        $this->builder      = $builder ?? new LibroXmlBuilder();
        $this->autenticador = $autenticador ?? new SiiAutenticador($http, $this->signer);
        $this->uploader     = $uploader ?? new LibroUploader($http);
    }

    /**
     * @return array{trackId: string, status: string, raw: string, xml: string}
     */
    public function enviarLibro(Libro $libro, Credenciales $cred): array
    {
        $cert  = $this->emisor->obtenerCertificado($cred->rutEmisor, $cred->ambiente);
        $datos = $this->emisor->obtenerDatosEmisor($cred->rutEmisor, $cred->ambiente);
        $token = $this->autenticador->obtenerToken($cert, $cred->ambiente);

        $rutSender = $this->resolverRutSender($cred, $cert);

        // Construir el libro y firmar el <EnvioLibro> en su contexto final.
        $dom = $this->builder->build($libro, $datos, $rutSender, $cred->ambiente);
        $this->signer->firmarNodo($this->envioLibro($dom), 'LibroCV', $cert);

        $xml      = $this->serializarParaSii($dom);
        $resultado = $this->uploader->subir($xml, $token, $rutSender, $cred->rutEmisor, $cred->ambiente);

        return [
            'trackId' => $resultado['trackId'],
            'status'  => $resultado['status'],
            'raw'     => $resultado['raw'],
            'xml'     => $xml,
        ];
    }

    private function envioLibro(DOMDocument $dom): DOMElement
    {
        $node = $dom->getElementsByTagNameNS(self::NS_SII, 'EnvioLibro')->item(0);
        if (! $node instanceof DOMElement) {
            throw new RuntimeException('LibroService: no se encontro <EnvioLibro> en el documento generado');
        }
        return $node;
    }

    private function resolverRutSender(Credenciales $cred, Certificado $cert): string
    {
        if ($cred->rutSender !== null && $cred->rutSender !== '') {
            return $cred->rutSender;
        }
        $parsed = openssl_x509_parse($cert->certData);
        $sn = is_array($parsed) ? ($parsed['subject']['serialNumber'] ?? null) : null;
        if (is_string($sn) && preg_match('/^\d{7,8}-[\dkK]$/', $sn)) {
            return $sn;
        }
        throw new CredencialesInvalidasException(
            'rutSender no provisto en Credenciales y no se pudo extraer del certificado'
        );
    }

    /**
     * Serializa el libro en ISO-8859-1 (lo que espera el SII). No afecta la
     * firma: se calculo sobre C14N (UTF-8) y el SII recanonicaliza en UTF-8.
     */
    private function serializarParaSii(DOMDocument $dom): string
    {
        $utf8 = (string) $dom->saveXML();
        $iso  = (string) mb_convert_encoding($utf8, 'ISO-8859-1', 'UTF-8');
        $iso  = (string) preg_replace(
            '/(<\?xml[^>]*encoding=")UTF-8("[^>]*\?>)/i',
            '${1}ISO-8859-1${2}',
            $iso,
            1,
        );
        return $iso;
    }
}
