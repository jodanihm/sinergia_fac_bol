<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Sii;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Parser de un EnvioDTE entrante (etapa de Intercambio de Informacion).
 *
 * Extrae caratula, ID del SetDTE, digest del envio y los datos minimos de cada
 * DTE necesarios para construir las tres respuestas del intercambio:
 *   - Acuse de recibo del envio (RecepcionEnvio + RecepcionDTE por documento)
 *   - Recibo de mercaderias (EnvioRecibos, Ley 19.983)
 *   - Aceptacion / Rechazo comercial (ResultadoDTE)
 *
 * Lee la misma estructura sin importar quien emita: EnvioDTE > SetDTE >
 * (Caratula, DTE > Documento). Soporta ISO-8859-1 y UTF-8 segun el prologo XML.
 */
final class EnvioDteParser
{
    private const NS_SII  = 'http://www.sii.cl/SiiDte';
    private const NS_DSIG = 'http://www.w3.org/2000/09/xmldsig#';

    /** @var array<string,string> Caratula del envio recibido (RutEmisor, RutReceptor, ...) */
    public array $caratula = [];

    /** ID del nodo SetDTE (EnvioDTEID en la respuesta) */
    public string $setDteId = '';

    /** DigestValue del envio (de la firma sobre el SetDTE); puede ir vacio */
    public string $digest = '';

    /** Nombre del archivo recibido (NmbEnvio en la respuesta) */
    public string $nmbEnvio = '';

    /** @var list<array<string,string>> Un registro por Documento del envio */
    public array $documentos = [];

    public static function fromFile(string $path): self
    {
        if (! is_file($path)) {
            throw new RuntimeException("EnvioDteParser: archivo no existe: $path");
        }
        $self = new self();
        $self->nmbEnvio = basename($path);
        $self->loadXML((string) file_get_contents($path));
        return $self;
    }

    public function loadXML(string $xml): void
    {
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($xml);
        libxml_use_internal_errors($prev);
        if ($ok === false) {
            throw new RuntimeException('EnvioDteParser: XML invalido / no se pudo parsear');
        }

        $xp = new DOMXPath($doc);
        $xp->registerNamespace('sii', self::NS_SII);
        $xp->registerNamespace('ds', self::NS_DSIG);

        // --- SetDTE + ID ---
        $setDte = $xp->query('//sii:SetDTE')->item(0);
        if (! $setDte instanceof DOMElement) {
            throw new RuntimeException('EnvioDteParser: no se encontro <SetDTE> (no es un EnvioDTE valido)');
        }
        $this->setDteId = $setDte->getAttribute('ID');

        // --- Caratula ---
        foreach (['RutEmisor', 'RutEnvia', 'RutReceptor', 'FchResol', 'NroResol', 'TmstFirmaEnv'] as $campo) {
            $n = $xp->query('//sii:SetDTE/sii:Caratula/sii:' . $campo)->item(0);
            if ($n !== null) {
                $this->caratula[$campo] = trim($n->textContent);
            }
        }

        // --- Digest del envio: DigestValue de la firma cuya Reference apunta al SetDTE ---
        if ($this->setDteId !== '') {
            $dv = $xp->query('//ds:Reference[@URI="#' . $this->setDteId . '"]/ds:DigestValue')->item(0);
            if ($dv !== null) {
                $this->digest = (string) preg_replace('/\s+/', '', $dv->textContent);
            }
        }

        // --- Documentos ---
        $get = function (DOMElement $documento, array $steps) use ($xp): string {
            $path = './' . implode('/', array_map(static fn (string $s): string => 'sii:' . $s, $steps));
            $n = $xp->query($path, $documento)->item(0);
            return $n !== null ? trim($n->textContent) : '';
        };

        $docs = $xp->query('//sii:SetDTE/sii:DTE/sii:Documento');
        foreach ($docs as $documento) {
            if (! $documento instanceof DOMElement) {
                continue;
            }
            $this->documentos[] = [
                'TipoDTE'   => $get($documento, ['Encabezado', 'IdDoc', 'TipoDTE']),
                'Folio'     => $get($documento, ['Encabezado', 'IdDoc', 'Folio']),
                'FchEmis'   => $get($documento, ['Encabezado', 'IdDoc', 'FchEmis']),
                'RUTEmisor' => $get($documento, ['Encabezado', 'Emisor', 'RUTEmisor']),
                'RUTRecep'  => $get($documento, ['Encabezado', 'Receptor', 'RUTRecep']),
                'MntTotal'  => $get($documento, ['Encabezado', 'Totales', 'MntTotal']),
            ];
        }

        if ($this->documentos === []) {
            throw new RuntimeException('EnvioDteParser: no se encontraron documentos <Documento> en el envio');
        }
    }
}
