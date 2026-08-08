<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Providers\SiiDirectoFacturador;
use ReflectionMethod;
use RuntimeException;

/**
 * datosConsultaDte(): los cinco datos que getEstDte exige tienen que salir DEL
 * DOCUMENTO PEDIDO, no del primero del sobre.
 *
 * POR QUE ESTE TEST EXISTE
 * -----------------------------------------------------------------------------
 * El xml que guarda cada fila de dte_emitido es el EnvioDTE COMPLETO. Antes de
 * este arreglo, datosConsultaDte() leia con getElementsByTagNameNS(...)->item(0)
 * sobre el documento entero, asi que en un lote de 20 facturas 19 consultaban al
 * SII con el receptor, la fecha y el monto de la PRIMERA -- y recibian DNK,
 * "datos no coinciden", indistinguible de un rechazo real.
 *
 * Nadie lo noto porque el camino no se usa: no hay ni una fila en estado DOK en
 * produccion, y DOK solo lo escribe ese camino. Era un defecto latente.
 *
 * SE PRUEBA POR REFLEXION, y es deliberado: el metodo es privado y llegar a el
 * por consultarEstado() arrastraria el certificado, el token y el SII entero.
 * Lo que hay que vigilar es la SELECCION del documento dentro del sobre, y eso
 * no necesita red ni openssl.
 */
final class DatosConsultaDteTest extends TestCase
{
    private const NS = 'http://www.sii.cl/SiiDte';

    /** Invoca el metodo privado con los tres argumentos. */
    private function datos(string $xml, int $tipoDte, int $folio): array
    {
        // Las dependencias no se tocan en este camino: datosConsultaDte solo
        // parsea una cadena. Se pasan las minimas que exige el constructor.
        $facturador = new SiiDirectoFacturador(
            new Client(),
            new InMemoryFolioRepository(),
            new InMemoryEmisorRepository(),
        );
        $m = new ReflectionMethod($facturador, 'datosConsultaDte');
        $m->setAccessible(true);

        return $m->invoke($facturador, $xml, $tipoDte, $folio);
    }

    /**
     * Un EnvioDTE con $n documentos, cada uno con datos DISTINTOS y derivables
     * del folio: asi cualquier confusion entre documentos se ve en el valor.
     */
    private function sobre(int $n, int $tipo = 33, int $folioBase = 100): string
    {
        $docs = '';
        for ($i = 0; $i < $n; $i++) {
            $folio = $folioBase + $i;
            $docs .= sprintf(
                '<DTE version="1.0"><Documento ID="F%dT%d">'
                . '<Encabezado>'
                . '<IdDoc><TipoDTE>%d</TipoDTE><Folio>%d</Folio><FchEmis>2026-08-%02d</FchEmis></IdDoc>'
                . '<Emisor><RUTEmisor>77724622-4</RUTEmisor></Emisor>'
                . '<Receptor><RUTRecep>%d-9</RUTRecep></Receptor>'
                . '<Totales><MntTotal>%d</MntTotal></Totales>'
                . '</Encabezado></Documento></DTE>',
                $folio, $tipo, $tipo, $folio, ($i % 28) + 1, 60000000 + $i, 1000 + $i
            );
        }

        return '<?xml version="1.0" encoding="ISO-8859-1"?>'
            . '<EnvioDTE xmlns="' . self::NS . '" version="1.0"><SetDTE ID="SetDoc">'
            . '<Caratula version="1.0"><RutEmisor>77724622-4</RutEmisor></Caratula>'
            . $docs
            . '</SetDTE></EnvioDTE>';
    }

    // --- VERIFICACION 1: el documento 7 de 20 -------------------------------

    public function testTomaLosDatosDelDocumentoPedidoYNoDelPrimero(): void
    {
        $xml = $this->sobre(20);

        // Documento 7 (indice 6): folio 106, receptor 60000006-9, monto 1006,
        // fecha 2026-08-07.
        $d = $this->datos($xml, 33, 106);

        self::assertSame('2026-08-07', $d['fchEmis']);
        self::assertSame('60000006-9', $d['rutRecep']);
        self::assertSame(1006, $d['mntTotal']);

        // Y NO los del primero, que es lo que devolvia antes del arreglo.
        $primero = $this->datos($xml, 33, 100);
        self::assertSame('2026-08-01', $primero['fchEmis']);
        self::assertSame('60000000-9', $primero['rutRecep']);
        self::assertSame(1000, $primero['mntTotal']);
        self::assertNotSame($primero, $d, 'si fueran iguales, seguiria tomando el primero');
    }

    /** Los 20, uno por uno: ninguno recibe los datos de otro. */
    public function testLosVeinteDocumentosDevuelvenCadaUnoLoSuyo(): void
    {
        $xml = $this->sobre(20);

        for ($i = 0; $i < 20; $i++) {
            $folio = 100 + $i;
            $d     = $this->datos($xml, 33, $folio);
            self::assertSame(60000000 + $i . '-9', $d['rutRecep'], "folio {$folio}");
            self::assertSame(1000 + $i, $d['mntTotal'], "folio {$folio}");
        }
    }

    /** El ultimo del sobre tambien: no se cae por el borde. */
    public function testElUltimoDelSobre(): void
    {
        $d = $this->datos($this->sobre(20), 33, 119);
        self::assertSame(1019, $d['mntTotal']);
    }

    // --- VERIFICACION 4: un sobre de UN documento ---------------------------

    /**
     * Es el caso comun -- emitir() guarda un sobre de un solo documento -- y el
     * unico en el que el codigo viejo acertaba. Tiene que seguir dando lo mismo.
     */
    public function testSobreDeUnSoloDocumentoNoCambia(): void
    {
        $d = $this->datos($this->sobre(1), 33, 100);

        self::assertSame('2026-08-01', $d['fchEmis']);
        self::assertSame('60000000-9', $d['rutRecep']);
        self::assertSame(1000, $d['mntTotal']);
    }

    // --- VERIFICACION 2: fallar ruidoso -------------------------------------

    public function testXmlQueNoParseaLanzaEnVezDeConsultarConCeros(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no se pudo parsear/i');

        $this->datos('<EnvioDTE><esto no cierra', 33, 100);
    }

    /**
     * EL VACIO TIENE SU PROPIO MENSAJE, y por eso este test es mas estricto que
     * "lanza algo".
     *
     * En PHP 8, DOMDocument::loadXML('') lanza un ValueError de la libreria
     * ("Argument #1 ($source) must not be empty") que pasa POR ENCIMA de la
     * comprobacion del retorno. Esta corrida lo destapo. Se podria haber
     * aceptado ese ValueError en el test, pero el mensaje habla de un argumento
     * de DOMDocument y no dice QUE documento esta vacio; el de aqui nombra tipo
     * y folio, que es lo que alguien necesita para ir a mirar la fila.
     */
    public function testXmlVacioLanzaConMensajeUtilYNoElValueErrorDeLaLibreria(): void
    {
        try {
            $this->datos('', 33, 100);
            self::fail('deberia haber lanzado');
        } catch (\ValueError $e) {
            self::fail('escapo el ValueError de DOMDocument: ' . $e->getMessage());
        } catch (RuntimeException $e) {
            self::assertStringContainsString('esta vacio', $e->getMessage());
            self::assertStringContainsString('33', $e->getMessage(), 'el mensaje nombra el tipo');
            self::assertStringContainsString('100', $e->getMessage(), 'y el folio');
        }
    }

    /** Puros espacios: obtenerXml() solo descarta la cadena vacia exacta. */
    public function testXmlDeSoloEspaciosLanzaIgual(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/esta vacio/i');

        $this->datos("   \n\t  ", 33, 100);
    }

    /**
     * El folio que no esta en el sobre LANZA. Antes caia al primero y consultaba
     * con datos de otro documento, que es la forma mas silenciosa de fallar.
     */
    public function testFolioQueNoEstaEnElSobreLanza(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no esta en el EnvioDTE/i');

        $this->datos($this->sobre(20), 33, 999);
    }

    /** El tipo tambien cuenta: mismo folio, otro tipo, no es el mismo documento. */
    public function testTipoDistintoConMismoFolioLanza(): void
    {
        $this->expectException(RuntimeException::class);
        $this->datos($this->sobre(20, 33), 61, 100);
    }

    /** Un documento sin receptor ni fecha no se consulta con vacios. */
    public function testDocumentoSinLosDatosExigidosLanza(): void
    {
        $xml = '<?xml version="1.0" encoding="ISO-8859-1"?>'
            . '<EnvioDTE xmlns="' . self::NS . '"><SetDTE>'
            . '<DTE><Documento ID="F1T33"><Encabezado>'
            . '<IdDoc><TipoDTE>33</TipoDTE><Folio>1</Folio></IdDoc>'
            . '<Totales><MntTotal>500</MntTotal></Totales>'
            . '</Encabezado></Documento></DTE></SetDTE></EnvioDTE>';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/faltan datos/i');

        $this->datos($xml, 33, 1);
    }

    /**
     * MntTotal 0 SI es legitimo: una nota de credito de correccion de texto lo
     * lleva. No puede confundirse con "no se pudo leer".
     */
    public function testMontoCeroEsValido(): void
    {
        $xml = '<?xml version="1.0" encoding="ISO-8859-1"?>'
            . '<EnvioDTE xmlns="' . self::NS . '"><SetDTE>'
            . '<DTE><Documento ID="F5T61"><Encabezado>'
            . '<IdDoc><TipoDTE>61</TipoDTE><Folio>5</Folio><FchEmis>2026-08-04</FchEmis></IdDoc>'
            . '<Receptor><RUTRecep>60803000-K</RUTRecep></Receptor>'
            . '<Totales><MntTotal>0</MntTotal></Totales>'
            . '</Encabezado></Documento></DTE></SetDTE></EnvioDTE>';

        $d = $this->datos($xml, 61, 5);

        self::assertSame(0, $d['mntTotal']);
        self::assertSame('60803000-K', $d['rutRecep']);
    }
}
