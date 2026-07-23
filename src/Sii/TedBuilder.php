<?php
declare(strict_types=1);
namespace Plantiflex\FacturacionCl\Sii;
use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use Plantiflex\FacturacionCl\Dto\DatosEmisor;
use Plantiflex\FacturacionCl\Dto\DocumentoTributario;
use RuntimeException;
/**
 * Construye el TED (Timbre Electronico DTE) e inserta dentro del <Documento>.
 *
 * FIRMA DEL TED (NO es XML-DSig, NO es C14N):
 *   - Se firma el <DD> en su FORMATO PLANO del SII: serializado SIN el xmlns,
 *     aplanado (sin whitespace entre tags) y en ISO-8859-1. La llave es la
 *     RSASK del CAF (no el certificado del emisor). SHA1withRSA.
 *   - Verificado contra un DTE de LibreDTE aceptado por el SII: firmar el DD con
 *     C14N (que agrega xmlns="SiiDte" y produce UTF-8) hace que el SII rechace el
 *     timbre como "Firma DTE Incorrecta" (DTE-3-505).
 */
final class TedBuilder
{
    private const NS_SII = 'http://www.sii.cl/SiiDte';
    /**
     * @param DOMDocument  $dteDoc          Documento raiz donde se crean los nodos (envioDoc o DTE individual).
     * @param DOMElement|null $targetDocumento Cuando se pasa, apunta directamente al <Documento> objetivo
     *                                         en lugar de tomar .item(0). Necesario en envios lote con
     *                                         multiples <Documento>. Null = comportamiento original (item(0)).
     */
    public function build(
        DOMDocument $dteDoc,
        DocumentoTributario $doc,
        DatosEmisor $emisor,
        int $folio,
        string $cafXml,
        ?DOMElement $targetDocumento = null
    ): void {
        $documento = $targetDocumento
            ?? $dteDoc->getElementsByTagNameNS(self::NS_SII, 'Documento')->item(0);
        if (! $documento instanceof DOMElement) {
            throw new RuntimeException('TedBuilder: no se encontro <Documento> en el DTE');
        }
        $cafParaParsear = mb_check_encoding($cafXml, 'UTF-8')
            ? $cafXml
            : mb_convert_encoding($cafXml, 'UTF-8', 'ISO-8859-1');
        $cafDoc = new DOMDocument();
        $cafDoc->preserveWhiteSpace = false;
        libxml_use_internal_errors(true);
        $ok = $cafDoc->loadXML($cafParaParsear);
        libxml_clear_errors();
        libxml_use_internal_errors(false);
        if (! $ok) {
            throw new RuntimeException('TedBuilder: el XML del CAF no es valido');
        }
        $rsask = $this->primerTexto($cafDoc, 'RSASK');
        if ($rsask === '') {
            throw new RuntimeException('TedBuilder: el CAF no contiene <RSASK> (llave privada)');
        }
        $cafNode = $cafDoc->getElementsByTagName('CAF')->item(0);
        if (! $cafNode instanceof DOMElement) {
            throw new RuntimeException('TedBuilder: el CAF no contiene bloque <CAF>');
        }
        // Leer MntTotal desde el Documento objetivo (no de todo el envioDoc) para
        // evitar leer el MntTotal del primer DTE cuando hay multiples en el sobre.
        $searchIn = $targetDocumento ?? $dteDoc;
        $mntTotalNode = $searchIn->getElementsByTagNameNS(self::NS_SII, 'MntTotal')->item(0);
        $mntTotal = $mntTotalNode !== null ? trim((string) $mntTotalNode->textContent) : '';
        if ($mntTotal === '') {
            throw new RuntimeException('TedBuilder: el DTE no tiene <MntTotal> en Totales');
        }
        $fecha = ($doc->fechaEmision ?? new DateTimeImmutable())->format('Y-m-d');
        // --- DD --- elementFormDefault="qualified": TED, DD y sus hijos en SiiDte.
        $ted = $dteDoc->createElementNS(self::NS_SII, 'TED');
        $ted->setAttribute('version', '1.0');
        $dd = $dteDoc->createElementNS(self::NS_SII, 'DD');
        $ted->appendChild($dd);
        $dd->appendChild($this->el($dteDoc, 'RE', $emisor->rutEmisor));
        $dd->appendChild($this->el($dteDoc, 'TD', (string) $doc->tipoDte->value));
        $dd->appendChild($this->el($dteDoc, 'F', (string) $folio));
        $dd->appendChild($this->el($dteDoc, 'FE', $fecha));
        $dd->appendChild($this->el($dteDoc, 'RR', $doc->receptor->rut));
        $dd->appendChild($this->el($dteDoc, 'RSR', $this->trunc($doc->receptor->razonSocial, 40)));
        $dd->appendChild($this->el($dteDoc, 'MNT', $mntTotal));
        $dd->appendChild($this->el($dteDoc, 'IT1', $this->trunc($doc->detalles[0]->nombre, 40)));
        $dd->appendChild($dteDoc->importNode($cafNode, true));
        $dd->appendChild($this->el($dteDoc, 'TSTED', date('Y-m-d\TH:i:s')));
        $documento->appendChild($ted);
        // --- Firmar DD con la llave privada del CAF, en el FORMATO PLANO del SII ---
        // getFlattened: serializar el DD, quitar xmlns (y xsi heredado si lo trae),
        // aplanar (sin whitespace entre tags) y pasar a ISO-8859-1. ESTA es la forma
        // que el SII canonicaliza para validar el timbre (NO C14N).
        $ddXml = (string) $dteDoc->saveXML($dd);
        $ddXml = (string) preg_replace('/ xmlns="http:\/\/www\.sii\.cl\/SiiDte"/', '', $ddXml, 1);
        $ddXml = (string) preg_replace('/ xmlns:xsi="[^"]*"/', '', $ddXml, 1);
        $ddXml = (string) preg_replace('/ xsi:schemaLocation="[^"]*"/', '', $ddXml, 1);
        $ddXml = (string) preg_replace('/>\s+</', '><', $ddXml);
        $canonical = mb_convert_encoding($ddXml, 'ISO-8859-1', 'UTF-8');
        $pkey = openssl_pkey_get_private($rsask);
        if ($pkey === false) {
            throw new RuntimeException('TedBuilder: RSASK del CAF invalida o no soportada');
        }
        $firma = '';
        if (! openssl_sign($canonical, $firma, $pkey, OPENSSL_ALGO_SHA1)) {
            throw new RuntimeException('TedBuilder: openssl_sign del DD fallo');
        }
        $frmt = $this->el($dteDoc, 'FRMT', rtrim(chunk_split(base64_encode($firma), 64, "\n"), "\n"));
        $frmt->setAttribute('algoritmo', 'SHA1withRSA');
        $ted->appendChild($frmt);
        $documento->appendChild($this->el($dteDoc, 'TmstFirma', date('Y-m-d\TH:i:s')));
    }
    private function el(DOMDocument $dom, string $name, string $value): DOMElement
    {
        $el = $dom->createElementNS(self::NS_SII, $name);
        $el->appendChild($dom->createTextNode($value));
        return $el;
    }
    private function trunc(string $s, int $max): string
    {
        return mb_substr($s, 0, $max);
    }
    private function primerTexto(DOMDocument $doc, string $tag): string
    {
        $n = $doc->getElementsByTagName($tag)->item(0);
        return $n === null ? '' : trim((string) $n->textContent);
    }
}
