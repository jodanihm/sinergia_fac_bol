<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Sii\DocumentoDelSobre;
use RuntimeException;

/**
 * Ubicar un documento dentro de un EnvioDTE persistido.
 *
 * POR QUE IMPORTA TANTO
 * -----------------------------------------------------------------------------
 * El xml de cada fila de dte_emitido es el sobre COMPLETO. Quien lo lea con
 * item(0) obtiene el PRIMER documento, no el que pidio. Ese error aparecio DOS
 * VECES en dos funciones escritas por separado -- datosConsultaDte() y
 * reconstruirOriginal() --, y la segunda ademas recorria los <Detalle> del sobre
 * entero: una nota de credito de anulacion con las lineas de los treinta
 * documentos.
 *
 * En produccion hay ocho sobres de lote y 136 documentos con ese defecto armado.
 * No se disparo por casualidad. Este test es para que la casualidad deje de ser
 * lo que nos protege.
 */
final class DocumentoDelSobreTest extends TestCase
{
    private const NS = 'http://www.sii.cl/SiiDte';

    /**
     * Sobre con $n documentos, cada uno con datos DERIVABLES DEL FOLIO y con un
     * numero de lineas distinto: asi cualquier confusion entre documentos se ve
     * en el valor, y cualquier fuga de ambito se ve en el conteo.
     */
    private function sobre(int $n, int $tipo = 34, int $folioBase = 100): string
    {
        $docs = '';
        for ($i = 0; $i < $n; $i++) {
            $folio = $folioBase + $i;

            // Documento i tiene i+1 lineas. Total del sobre = n(n+1)/2.
            $detalles = '';
            for ($j = 0; $j <= $i; $j++) {
                $detalles .= sprintf(
                    '<Detalle><NroLinDet>%d</NroLinDet><IndExe>1</IndExe>'
                    . '<NmbItem>Item %d del folio %d</NmbItem>'
                    . '<QtyItem>%d</QtyItem><PrcItem>%d</PrcItem><MontoItem>%d</MontoItem></Detalle>',
                    $j + 1, $j + 1, $folio, $j + 1, 100 + $j, ($j + 1) * (100 + $j)
                );
            }

            $docs .= sprintf(
                '<DTE version="1.0"><Documento ID="F%dT%d">'
                . '<Encabezado>'
                . '<IdDoc><TipoDTE>%d</TipoDTE><Folio>%d</Folio><FchEmis>2026-08-%02d</FchEmis></IdDoc>'
                . '<Emisor><RUTEmisor>77724622-4</RUTEmisor></Emisor>'
                . '<Receptor><RUTRecep>%d-9</RUTRecep><RznSocRecep>CLIENTE %d</RznSocRecep>'
                . '<GiroRecep>GIRO %d</GiroRecep><DirRecep>CALLE %d</DirRecep><CmnaRecep>COMUNA %d</CmnaRecep></Receptor>'
                . '<Totales><MntExe>%d</MntExe><MntTotal>%d</MntTotal></Totales>'
                . '</Encabezado>%s</Documento></DTE>',
                $folio, $tipo, $tipo, $folio, ($i % 28) + 1,
                60000000 + $i, $folio, $folio, $folio, $folio,
                1000 + $i, 1000 + $i, $detalles
            );
        }

        return '<?xml version="1.0" encoding="ISO-8859-1"?>'
            . '<EnvioDTE xmlns="' . self::NS . '" version="1.0"><SetDTE ID="SetDoc">'
            . '<Caratula version="1.0"><RutEmisor>77724622-4</RutEmisor></Caratula>'
            . $docs
            . '</SetDTE></EnvioDTE>';
    }

    // --- Ubicar -------------------------------------------------------------

    public function testUbicaElDocumentoPedidoYNoElPrimero(): void
    {
        $xml = $this->sobre(20);

        // Documento 7 = indice 6 = folio 106.
        $doc = DocumentoDelSobre::ubicar($xml, 34, 106);

        self::assertSame('60000006-9', DocumentoDelSobre::texto($doc, 'RUTRecep'));
        self::assertSame('CLIENTE 106', DocumentoDelSobre::texto($doc, 'RznSocRecep'));
        self::assertSame('2026-08-07', DocumentoDelSobre::texto($doc, 'FchEmis'));
        self::assertSame('1006', DocumentoDelSobre::texto($doc, 'MntTotal'));

        // Y el primero es OTRO, que es lo que se devolvia antes.
        $primero = DocumentoDelSobre::ubicar($xml, 34, 100);
        self::assertSame('60000000-9', DocumentoDelSobre::texto($primero, 'RUTRecep'));
        self::assertSame('1000', DocumentoDelSobre::texto($primero, 'MntTotal'));
    }

    public function testLosVeinteDevuelvenCadaUnoLoSuyo(): void
    {
        $xml = $this->sobre(20);
        for ($i = 0; $i < 20; $i++) {
            $doc = DocumentoDelSobre::ubicar($xml, 34, 100 + $i);
            self::assertSame((string) (60000000 + $i) . '-9', DocumentoDelSobre::texto($doc, 'RUTRecep'));
        }
    }

    public function testElUltimoDelSobre(): void
    {
        $doc = DocumentoDelSobre::ubicar($this->sobre(20), 34, 119);
        self::assertSame('1019', DocumentoDelSobre::texto($doc, 'MntTotal'));
    }

    // --- EL CONTEO DE LINEAS: el dano entero ---------------------------------

    /**
     * EL NUMERO QUE MIDE EL DEFECTO. El sobre de 20 tiene 210 <Detalle> en total
     * (1+2+...+20). El documento 7 tiene SIETE. Antes se devolvian los 210.
     */
    public function testLosDetalleSonSoloLosDelDocumento(): void
    {
        $xml = $this->sobre(20);

        $doc = DocumentoDelSobre::ubicar($xml, 34, 106);
        self::assertCount(7, DocumentoDelSobre::detalles($doc), 'el documento 7 tiene 7 lineas');

        // Cuantos hay en el sobre entero, que es lo que devolvia el codigo viejo.
        self::assertSame(210, substr_count($xml, '<Detalle>'), 'el sobre entero tiene 210');
    }

    public function testCadaDocumentoTieneSuPropioNumeroDeLineas(): void
    {
        $xml = $this->sobre(20);
        for ($i = 0; $i < 20; $i++) {
            $doc = DocumentoDelSobre::ubicar($xml, 34, 100 + $i);
            self::assertCount($i + 1, DocumentoDelSobre::detalles($doc), 'folio ' . (100 + $i));
        }
    }

    /** Y las lineas son las SUYAS, no las de otro documento. */
    public function testLasLineasSonDelDocumentoCorrecto(): void
    {
        $doc = DocumentoDelSobre::ubicar($this->sobre(20), 34, 106);
        foreach (DocumentoDelSobre::detalles($doc) as $det) {
            self::assertStringContainsString(
                'del folio 106',
                DocumentoDelSobre::texto($det, 'NmbItem'),
            );
        }
    }

    // --- Documento unitario --------------------------------------------------

    /**
     * El caso comun -- emitir() guarda un sobre de un documento -- y el unico en
     * el que el codigo viejo acertaba. Tiene que seguir dando lo mismo.
     */
    public function testSobreDeUnSoloDocumento(): void
    {
        $doc = DocumentoDelSobre::ubicar($this->sobre(1), 34, 100);

        self::assertSame('60000000-9', DocumentoDelSobre::texto($doc, 'RUTRecep'));
        self::assertSame('CLIENTE 100', DocumentoDelSobre::texto($doc, 'RznSocRecep'));
        self::assertSame('GIRO 100', DocumentoDelSobre::texto($doc, 'GiroRecep'));
        self::assertSame('CALLE 100', DocumentoDelSobre::texto($doc, 'DirRecep'));
        self::assertSame('COMUNA 100', DocumentoDelSobre::texto($doc, 'CmnaRecep'));
        self::assertSame('1000', DocumentoDelSobre::texto($doc, 'MntTotal'));
        self::assertCount(1, DocumentoDelSobre::detalles($doc));
    }

    // --- Las tres guardas ----------------------------------------------------

    public function testFolioQueNoEstaEnElSobreLanza(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no esta en el EnvioDTE/i');

        DocumentoDelSobre::ubicar($this->sobre(20), 34, 999);
    }

    public function testTipoDistintoConMismoFolioLanza(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no esta en el EnvioDTE/i');

        DocumentoDelSobre::ubicar($this->sobre(20, 34), 61, 100);
    }

    public function testXmlQueNoParseaLanza(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no se pudo parsear/i');

        DocumentoDelSobre::ubicar('<EnvioDTE><esto no cierra', 34, 100);
    }

    /**
     * En PHP 8, loadXML('') lanza un ValueError de la libreria que pasa POR
     * ENCIMA de la comprobacion del retorno. El mensaje de aqui nombra tipo y
     * folio; el de DOMDocument habla de un argumento.
     */
    public function testXmlVacioLanzaConMensajeUtilYNoElValueError(): void
    {
        try {
            DocumentoDelSobre::ubicar('', 34, 100);
            self::fail('deberia haber lanzado');
        } catch (\ValueError $e) {
            self::fail('escapo el ValueError de DOMDocument: ' . $e->getMessage());
        } catch (RuntimeException $e) {
            self::assertStringContainsString('esta vacio', $e->getMessage());
            self::assertStringContainsString('34', $e->getMessage());
            self::assertStringContainsString('100', $e->getMessage());
        }
    }

    public function testXmlDeSoloEspaciosLanzaIgual(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/esta vacio/i');

        DocumentoDelSobre::ubicar("   \n\t  ", 34, 100);
    }

    /** texto() sobre un tag que no existe devuelve cadena vacia, no lanza. */
    public function testTextoDeUnTagAusenteEsCadenaVacia(): void
    {
        $doc = DocumentoDelSobre::ubicar($this->sobre(1), 34, 100);
        self::assertSame('', DocumentoDelSobre::texto($doc, 'NoExisteEsteTag'));
    }
}
