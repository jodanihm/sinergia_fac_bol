<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Providers;

use DOMDocument;
use DOMElement;
use GuzzleHttp\ClientInterface;
use Plantiflex\FacturacionCl\Contracts\DteEmitidoRepositoryInterface;
use Plantiflex\FacturacionCl\Contracts\EmisorRepositoryInterface;
use Plantiflex\FacturacionCl\Contracts\FolioRepositoryInterface;
use Plantiflex\FacturacionCl\Dto\Certificado;
use Plantiflex\FacturacionCl\Dto\Credenciales;
use Plantiflex\FacturacionCl\Dto\DocumentoTributario;
use Plantiflex\FacturacionCl\Dto\ResultadoEmision;
use Plantiflex\FacturacionCl\Exceptions\CredencialesInvalidasException;
use Plantiflex\FacturacionCl\Exceptions\OperacionNoSoportadaException;
use Plantiflex\FacturacionCl\Sii\BoletaAutenticador;
use Plantiflex\FacturacionCl\Sii\BoletaEnvioBuilder;
use Plantiflex\FacturacionCl\Sii\BoletaUploader;
use Plantiflex\FacturacionCl\Sii\DteXmlBuilder;
use Plantiflex\FacturacionCl\Sii\SiiAutenticador;
use Plantiflex\FacturacionCl\Sii\SiiUploader;
use Plantiflex\FacturacionCl\Sii\TedBuilder;
use Plantiflex\FacturacionCl\Sii\XmlSigner;
use RuntimeException;
use Throwable;

/**
 * Emite BOLETAS electronicas (39/41) directo al SII.
 *
 * Canal REST/PANGAL: emitir() / emitirLote().
 *   - Auth: BoletaAutenticador (REST apicert).
 *   - Sobre: EnvioBOLETA.
 *   - Envio: BoletaUploader (pangal/rahue).
 *
 * Canal CLASICO/MAULLIN: emitirLoteClasico().
 *   - Solo diagnostico legado. No usar para certificar boleta.
 *   - Maullin es el canal clasico de factura y puede rechazar tipo 39 con HED-3-211.
 */
final class BoletaFacturador
{
    private const NS_SII = 'http://www.sii.cl/SiiDte';

    private readonly BoletaAutenticador $autenticador;
    private readonly XmlSigner $signer;
    private readonly DteXmlBuilder $dteBuilder;
    private readonly TedBuilder $tedBuilder;
    private readonly BoletaEnvioBuilder $envioBuilder;
    private readonly BoletaUploader $uploader;
    private readonly SiiAutenticador $autenticadorClasico;
    private readonly SiiUploader $uploaderClasico;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly FolioRepositoryInterface $folios,
        private readonly EmisorRepositoryInterface $emisor,
        ?BoletaAutenticador $autenticador = null,
        ?XmlSigner $signer = null,
        ?DteXmlBuilder $dteBuilder = null,
        ?BoletaEnvioBuilder $envioBuilder = null,
        ?BoletaUploader $uploader = null,
        ?TedBuilder $tedBuilder = null,
        ?SiiAutenticador $autenticadorClasico = null,
        ?SiiUploader $uploaderClasico = null,
        private readonly ?DteEmitidoRepositoryInterface $dteEmitido = null,
    ) {
        $this->signer              = $signer ?? new XmlSigner();
        $this->autenticador        = $autenticador ?? new BoletaAutenticador($http, $this->signer);
        $this->dteBuilder          = $dteBuilder ?? new DteXmlBuilder();
        $this->tedBuilder          = $tedBuilder ?? new TedBuilder();
        $this->envioBuilder        = $envioBuilder ?? new BoletaEnvioBuilder();
        $this->uploader            = $uploader ?? new BoletaUploader($http);
        $this->autenticadorClasico = $autenticadorClasico ?? new SiiAutenticador($http, $this->signer);
        $this->uploaderClasico     = $uploaderClasico ?? new SiiUploader($http);
    }

    public function autenticar(Credenciales $cred): string
    {
        $cert = $this->emisor->obtenerCertificado($cred->rutEmisor, $cred->ambiente);
        return $this->autenticador->obtenerToken($cert, $cred->ambiente);
    }

    public function emitir(DocumentoTributario $doc, Credenciales $cred): ResultadoEmision
    {
        if (! $doc->tipoDte->esBoleta()) {
            throw new OperacionNoSoportadaException(
                'BoletaFacturador solo emite boletas (39/41). Para facturas/notas usa SiiDirectoFacturador.'
            );
        }

        $cafXml    = $this->folios->obtenerCafActivo($cred->rutEmisor, $doc->tipoDte, $cred->ambiente);
        $cert      = $this->emisor->obtenerCertificado($cred->rutEmisor, $cred->ambiente);
        $datos     = $this->emisor->obtenerDatosEmisor($cred->rutEmisor, $cred->ambiente);
        $token     = $this->autenticador->obtenerToken($cert, $cred->ambiente);
        $rutSender = $this->resolverRutSender($cred, $cert);
        $folio     = $this->folios->asignarSiguienteFolio($cred->rutEmisor, $doc->tipoDte, $cred->ambiente);

        try {
            $dteDoc   = $this->dteBuilder->build($doc, $datos, $folio);
            $envioDoc = $this->envioBuilder->build([$dteDoc], $datos, $rutSender, $cred->ambiente);

            $this->tedBuilder->build($envioDoc, $doc, $datos, $folio, $cafXml);

            $idsDocumentos = [];
            foreach ($this->documentos($envioDoc) as $documento) {
                $id = $documento->getAttribute('ID');
                $this->signer->insertarEsqueletoFirma($documento, $id, $cert);
                $idsDocumentos[] = $id;
            }

            $this->signer->insertarEsqueletoFirma($this->primerElemento($envioDoc, 'SetDTE'), 'SetDoc', $cert);
            $this->envioBuilder->agregarSchemaLocation($envioDoc);
            $this->signer->congelar($envioDoc);

            foreach ($idsDocumentos as $idDoc) {
                $this->signer->calcularDigestYFirmar($envioDoc, $idDoc, $cert);
            }
            $this->signer->calcularDigestYFirmar($envioDoc, 'SetDoc', $cert);

            $envioXml  = $this->serializarParaSii($envioDoc);
            $resultado = $this->uploader->subir($envioXml, $token, $rutSender, $cred->rutEmisor, $cred->ambiente);

            $this->folios->marcarFolioComoUsado($cred->rutEmisor, $doc->tipoDte, $cred->ambiente, $folio, true);
            $this->persistirEmitido($doc, $cred, $folio, $resultado['trackId'] ?? null, $envioXml);

            return new ResultadoEmision(
                tipoDte: $doc->tipoDte,
                folio:   $folio,
                estado:  'enviado',
                trackId: $resultado['trackId'],
                pdfUrl:  null,
                xml:     $envioXml,
                raw:     $resultado,
            );
        } catch (Throwable $e) {
            $this->folios->marcarFolioComoUsado($cred->rutEmisor, $doc->tipoDte, $cred->ambiente, $folio, false);
            throw $e;
        }
    }

    /**
     * Persiste la emision en dte_emitido si hay repositorio inyectado. Best-effort:
     * captura cualquier error para no convertir una boleta ya emitida en una excepcion.
     * Estado inicial 'enviado' (REC del canal REST); el estado final EPR/rechazo se
     * sincroniza despues via actualizarEstado(), igual que en SiiDirectoFacturador.
     */
    private function persistirEmitido(
        DocumentoTributario $doc,
        Credenciales $cred,
        int $folio,
        ?string $trackId,
        string $envioXml,
    ): void {
        if ($this->dteEmitido === null) {
            return;
        }
        try {
            $m = $this->extraerMontos($envioXml);
            $this->dteEmitido->registrar(
                rutEmisor:    $cred->rutEmisor,
                ambiente:     $cred->ambiente,
                tipoDte:      $doc->tipoDte->value,
                folio:        $folio,
                trackId:      $trackId,
                estado:       'enviado',
                xml:          $envioXml,
                fechaEmision: $m['fecha'],
                neto:         $m['neto'],
                iva:          $m['iva'],
                total:        $m['total'],
                receptorRut:  $doc->receptor->rut,
                folioRef:     null,
                tipoDteRef:   null,
            );
        } catch (Throwable $e) {
            error_log('dte_emitido registrar fallo (boleta folio ' . $folio . '): ' . $e->getMessage());
        }
    }

    /**
     * Extrae MntNeto/IVA/MntTotal y FchEmis del EnvioBOLETA ya serializado.
     * Mismos nombres de tag que EnvioDTE (verificado contra boletas certificadas
     * DOK): getElementsByTagNameNS no depende de la ruta, solo del nombre+namespace.
     *
     * @return array{neto:int, iva:int, total:int, fecha:string}
     */
    private function extraerMontos(string $envioXml): array
    {
        $dom    = new DOMDocument();
        $previo = libxml_use_internal_errors(true);
        $dom->loadXML($envioXml);
        libxml_use_internal_errors($previo);

        $leer = function (string $tag) use ($dom): ?string {
            $n = $dom->getElementsByTagNameNS(self::NS_SII, $tag)->item(0);
            return $n !== null ? trim($n->textContent) : null;
        };

        return [
            'neto'  => (int) ($leer('MntNeto') ?? '0'),
            'iva'   => (int) ($leer('IVA') ?? '0'),
            'total' => (int) ($leer('MntTotal') ?? '0'),
            'fecha' => $leer('FchEmis') ?? date('Y-m-d'),
        ];
    }

    /**
     * @param list<DocumentoTributario> $docs
     * @return array{trackId: string, estado: string, boletas: list<array{folio: int}>, xml: string, raw: array}
     */
    public function emitirLote(array $docs, Credenciales $cred): array
    {
        return $this->emitirLoteInterno($docs, $cred, false);
    }

    /**
     * @param list<DocumentoTributario> $docs
     * @return array{trackId: string, estado: string, boletas: list<array{folio: int}>, xml: string, raw: array}
     */
    public function emitirLoteClasico(array $docs, Credenciales $cred): array
    {
        return $this->emitirLoteInterno($docs, $cred, true);
    }

    /**
     * @param list<DocumentoTributario> $docs
     * @return array{trackId: string, estado: string, boletas: list<array{folio: int}>, xml: string, raw: array}
     */
    private function emitirLoteInterno(array $docs, Credenciales $cred, bool $clasico): array
    {
        if ($docs === []) {
            throw new OperacionNoSoportadaException('emitirLote: el arreglo de documentos no puede estar vacio.');
        }

        foreach ($docs as $i => $doc) {
            if (! $doc instanceof DocumentoTributario) {
                throw new OperacionNoSoportadaException("emitirLote: entrada #{$i} no es DocumentoTributario.");
            }
            if (! $doc->tipoDte->esBoleta()) {
                throw new OperacionNoSoportadaException(
                    "emitirLote: entrada #{$i} (tipo {$doc->tipoDte->value}) no es boleta (39/41)."
                );
            }
        }

        $primero   = $docs[0];
        $cafXml    = $this->folios->obtenerCafActivo($cred->rutEmisor, $primero->tipoDte, $cred->ambiente);
        $cert      = $this->emisor->obtenerCertificado($cred->rutEmisor, $cred->ambiente);
        $datos     = $this->emisor->obtenerDatosEmisor($cred->rutEmisor, $cred->ambiente);
        $token     = $clasico
            ? $this->autenticadorClasico->obtenerToken($cert, $cred->ambiente)
            : $this->autenticador->obtenerToken($cert, $cred->ambiente);
        $rutSender = $this->resolverRutSender($cred, $cert);
        $tripletas = [];

        try {
            foreach ($docs as $doc) {
                $folio       = $this->folios->asignarSiguienteFolio($cred->rutEmisor, $doc->tipoDte, $cred->ambiente);
                $dteDoc      = $this->dteBuilder->build($doc, $datos, $folio);
                $tripletas[] = [$dteDoc, $doc, $folio];
            }

            $dteDocs  = array_map(fn ($t) => $t[0], $tripletas);
            $envioDoc = $this->envioBuilder->build($dteDocs, $datos, $rutSender, $cred->ambiente);

            foreach ($tripletas as [$dteDoc, $doc, $folio]) {
                unset($dteDoc);
                $id          = sprintf('F%dT%d', $folio, $doc->tipoDte->value);
                $documentoEl = $this->encontrarDocumentoPorId($envioDoc, $id);
                $this->tedBuilder->build($envioDoc, $doc, $datos, $folio, $cafXml, $documentoEl);
            }

            $idsDocumentos = [];
            foreach ($this->documentos($envioDoc) as $documento) {
                $id = $documento->getAttribute('ID');
                $this->signer->insertarEsqueletoFirma($documento, $id, $cert);
                $idsDocumentos[] = $id;
            }

            $this->signer->insertarEsqueletoFirma($this->primerElemento($envioDoc, 'SetDTE'), 'SetDoc', $cert);
            $this->envioBuilder->agregarSchemaLocation($envioDoc);
            $this->signer->congelar($envioDoc);

            foreach ($idsDocumentos as $idDoc) {
                $this->signer->calcularDigestYFirmar($envioDoc, $idDoc, $cert);
            }
            $this->signer->calcularDigestYFirmar($envioDoc, 'SetDoc', $cert);

            $envioXml = $this->serializarParaSii($envioDoc);

            // DEBUG: volcado del EnvioBOLETA final (byte a byte el mismo que se
            // sube al SII) para poder subirlo tambien MANUALMENTE por el portal
            // web. Best-effort, mismo criterio que persistirEmitido(): un fallo
            // al escribir a disco nunca debe romper un envio real.
            try {
                @file_put_contents(__DIR__ . '/../../envio_boleta_debug.xml', $envioXml);
            } catch (Throwable $e) {
                error_log('volcado envio_boleta_debug.xml fallo: ' . $e->getMessage());
            }

            $resultado = $clasico
                ? $this->uploaderClasico->subir($envioXml, $token, $rutSender, $cred->rutEmisor, $cred->ambiente)
                : $this->uploader->subir($envioXml, $token, $rutSender, $cred->rutEmisor, $cred->ambiente);

            foreach ($tripletas as [, $doc, $folio]) {
                $this->folios->marcarFolioComoUsado($cred->rutEmisor, $doc->tipoDte, $cred->ambiente, $folio, true);
            }

            // Persistir cada boleta del lote (best-effort, mismo criterio que
            // persistirEmitido() y SiiDirectoFacturador::persistirEmitidosLote()):
            // solo se llega aqui con el SII habiendo aceptado el envio.
            $this->persistirEmitidosLote($tripletas, $cred, $resultado['trackId'] ?? null, $envioXml);

            return [
                'trackId' => $resultado['trackId'],
                'estado'  => $resultado['estado'] ?? $resultado['status'] ?? 'enviado',
                'boletas' => array_map(fn ($t) => ['folio' => $t[2]], $tripletas),
                'xml'     => $envioXml,
                'raw'     => $resultado,
            ];
        } catch (Throwable $e) {
            foreach ($tripletas as [, $doc, $folio]) {
                $this->folios->marcarFolioComoUsado($cred->rutEmisor, $doc->tipoDte, $cred->ambiente, $folio, false);
            }
            throw $e;
        }
    }

    /**
     * Persiste cada boleta de un lote en dte_emitido, si hay repositorio
     * inyectado. Mismo criterio que SiiDirectoFacturador::persistirEmitidosLote():
     * best-effort por fila (un fallo de persistencia no convierte un lote ya
     * aceptado por el SII en excepcion), con montos/fecha leidos POR DOCUMENTO
     * desde su propio <Documento ID="F{folio}T{tipo}"> dentro del EnvioBOLETA ya
     * subido -- nunca del primero del sobre, a diferencia de extraerMontos()
     * (pensado solo para emitir() de UN documento).
     *
     * @param list<array{0: DOMDocument, 1: DocumentoTributario, 2: int}> $tripletas
     */
    private function persistirEmitidosLote(array $tripletas, Credenciales $cred, ?string $trackId, string $envioXml): void
    {
        if ($this->dteEmitido === null) {
            return;
        }

        $dom    = new DOMDocument();
        $previo = libxml_use_internal_errors(true);
        $dom->loadXML($envioXml);
        libxml_use_internal_errors($previo);

        $montosPorId = [];
        foreach ($dom->getElementsByTagNameNS(self::NS_SII, 'Documento') as $documento) {
            if (! $documento instanceof DOMElement) {
                continue;
            }
            $leer = static function (string $tag) use ($documento): ?string {
                $n = $documento->getElementsByTagNameNS(self::NS_SII, $tag)->item(0);
                return $n !== null ? trim($n->textContent) : null;
            };
            $montosPorId[$documento->getAttribute('ID')] = [
                'neto'  => (int) ($leer('MntNeto') ?? '0'),
                'iva'   => (int) ($leer('IVA') ?? '0'),
                'total' => (int) ($leer('MntTotal') ?? '0'),
                'fecha' => $leer('FchEmis') ?? date('Y-m-d'),
            ];
        }

        foreach ($tripletas as [, $doc, $folio]) {
            try {
                $id = sprintf('F%dT%d', $folio, $doc->tipoDte->value);
                $mt = $montosPorId[$id] ?? ['neto' => 0, 'iva' => 0, 'total' => 0, 'fecha' => date('Y-m-d')];

                $this->dteEmitido->registrar(
                    rutEmisor:    $cred->rutEmisor,
                    ambiente:     $cred->ambiente,
                    tipoDte:      $doc->tipoDte->value,
                    folio:        $folio,
                    trackId:      $trackId,
                    estado:       'enviado',
                    xml:          $envioXml,
                    fechaEmision: $mt['fecha'],
                    neto:         $mt['neto'],
                    iva:          $mt['iva'],
                    total:        $mt['total'],
                    receptorRut:  $doc->receptor->rut,
                    folioRef:     null,
                    tipoDteRef:   null,
                );
            } catch (Throwable $e) {
                error_log('dte_emitido registrar fallo (boleta lote, folio ' . $folio . '): ' . $e->getMessage());
            }
        }
    }

    private function resolverRutSender(Credenciales $cred, Certificado $cert): string
    {
        if ($cred->rutSender !== null && $cred->rutSender !== '') {
            return $cred->rutSender;
        }

        $rut = $this->extraerRutDeCertificado($cert);
        if ($rut !== null) {
            return $rut;
        }

        throw new CredencialesInvalidasException(
            'rutSender no provisto en Credenciales y no se pudo extraer del certificado'
        );
    }

    private function extraerRutDeCertificado(Certificado $cert): ?string
    {
        $parsed = openssl_x509_parse($cert->certData);
        if ($parsed === false) {
            return null;
        }

        $sn = $parsed['subject']['serialNumber'] ?? null;
        if (is_string($sn) && preg_match('/^\d{7,8}-[\dkK]$/', $sn)) {
            return $sn;
        }

        return null;
    }

    private function primerElemento(DOMDocument $doc, string $tag): DOMElement
    {
        $node = $doc->getElementsByTagNameNS(self::NS_SII, $tag)->item(0);
        if (! $node instanceof DOMElement) {
            throw new RuntimeException("BoletaFacturador: no se encontro <$tag> en el documento generado");
        }
        return $node;
    }

    private function encontrarDocumentoPorId(DOMDocument $envioDoc, string $id): DOMElement
    {
        foreach ($envioDoc->getElementsByTagNameNS(self::NS_SII, 'Documento') as $node) {
            if ($node instanceof DOMElement && $node->getAttribute('ID') === $id) {
                return $node;
            }
        }

        throw new RuntimeException(
            "BoletaFacturador: no se encontro <Documento ID=\"{$id}\"> en el envio generado"
        );
    }

    private function serializarParaSii(DOMDocument $doc): string
    {
        $utf8 = XmlSigner::limpiarPrefijosDsig((string) $doc->saveXML());
        $iso  = (string) mb_convert_encoding($utf8, 'ISO-8859-1', 'UTF-8');
        $iso  = (string) preg_replace(
            '/(<\?xml[^>]*encoding=")UTF-8("[^>]*\?>)/i',
            '${1}ISO-8859-1${2}',
            $iso,
            1,
        );
        return $iso;
    }

    /**
     * @return list<DOMElement>
     */
    private function documentos(DOMDocument $doc): array
    {
        $out = [];
        foreach ($doc->getElementsByTagNameNS(self::NS_SII, 'Documento') as $node) {
            if ($node instanceof DOMElement) {
                $out[] = $node;
            }
        }

        if ($out === []) {
            throw new RuntimeException('BoletaFacturador: no se encontro <Documento> en el envio generado');
        }

        return $out;
    }
}
