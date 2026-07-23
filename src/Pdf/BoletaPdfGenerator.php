<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Pdf;

use DOMDocument;
use DOMXPath;
use RuntimeException;

/**
 * Orquestador del PDF de BOLETA (39/41): parsea el EnvioBOLETA y delega el
 * dibujo en {@see BoletaPdfDocumento}.
 *
 * Es una clase SIN "extends" a proposito (mismo patron que DtePdfGenerator):
 * registra el autoloader de LibreDTE/TCPDF en el constructor, y solo DESPUES
 * instancia BoletaPdfDocumento (que si extiende sasco\LibreDTE\PDF). Si esta
 * clase extendiera directamente esa clase de LibreDTE, PHP intentaria resolver
 * el "extends" al cargar este archivo — ANTES de que el constructor alcance a
 * registrar el autoloader — y fallaria con "Class sasco\LibreDTE\PDF not found".
 */
final class BoletaPdfGenerator
{
    private static bool $autoloadRegistrado = false;

    public function __construct()
    {
        self::registrarLibreDte();
    }

    /**
     * Genera el PDF de UNA boleta de un EnvioBOLETA.
     *
     * Sin $tipoDte/$folio (retrocompatible: todos los llamadores actuales):
     * renderiza el PRIMER (y normalmente unico) <DTE> del sobre, igual que el
     * comportamiento vigente antes de este fix.
     *
     * Con $folio (y opcionalmente $tipoDte): busca DENTRO del EnvioBOLETA la
     * boleta cuyo Folio (y TipoDTE, si se indica) coinciden y renderiza SOLO
     * esa. Necesario cuando el XML persistido es el SOBRE COMPLETO de un lote
     * (ver BoletaFacturador::emitirLote(), que guarda el mismo EnvioBOLETA de
     * varias boletas en cada fila de dte_emitido). Si no existe esa boleta en
     * el sobre, lanza excepcion en vez de caer a la primera (eso ocultaria el
     * bug de folio equivocado en vez de mostrarlo). Mismo patron que
     * DtePdfGenerator::seleccionarDocumento().
     *
     * @return string Binario del PDF.
     */
    public function generarDesdeEnvioXml(string $envioXml, ?int $tipoDte = null, ?int $folio = null): string
    {
        date_default_timezone_set('America/Santiago');
        error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

        $envio = new \sasco\LibreDTE\Sii\EnvioDte();
        $envio->loadXML($envioXml);

        $documentos = $envio->getDocumentos();
        if (! $documentos) {
            throw new RuntimeException('No se pudieron leer documentos del EnvioBOLETA');
        }

        $dte   = $this->seleccionarDocumento($documentos, $tipoDte, $folio);
        $datos = $dte->getDatos();
        if (! $datos) {
            throw new RuntimeException('La boleta no tiene datos');
        }
        $ted = $dte->getTED();

        $resolucion = $this->resolucionPorXPath($envioXml);

        $pdf = new BoletaPdfDocumento();
        $pdf->dibujar($datos, (string) $ted, $resolucion);

        return (string) $pdf->Output('', 'S');
    }

    /**
     * Selecciona, dentro de los <DTE> ya parseados de un EnvioBOLETA, la
     * boleta a renderizar. Ver generarDesdeEnvioXml() para el criterio
     * completo (retrocompat sin $folio; match por folio, refinado por tipo si
     * viene). Replica el patron ya validado de
     * DtePdfGenerator::seleccionarDocumento().
     *
     * @param list<\sasco\LibreDTE\Sii\Dte> $documentos
     */
    private function seleccionarDocumento(array $documentos, ?int $tipoDte, ?int $folio): \sasco\LibreDTE\Sii\Dte
    {
        if ($folio === null) {
            return $documentos[0];
        }
        foreach ($documentos as $dte) {
            if ((int) $dte->getFolio() !== $folio) {
                continue;
            }
            if ($tipoDte !== null && (int) $dte->getTipo() !== $tipoDte) {
                continue;
            }
            return $dte;
        }

        throw new RuntimeException(sprintf(
            'Boleta tipo %s folio %d no esta en el EnvioBOLETA',
            $tipoDte !== null ? (string) $tipoDte : '(cualquiera)',
            $folio,
        ));
    }

    /**
     * Resuelve FchResol/NroResol leyendo <Caratula> directo del XML por XPath.
     * Mismo motivo que en DtePdfGenerator: EnvioDte::getCaratula() de LibreDTE
     * hardcodea la clave 'EnvioDTE' y no reconoce la raiz <EnvioBOLETA>. Sin
     * dependencia de TCPDF/LibreDTE: solo DOMDocument/DOMXPath, por eso vive
     * aqui (el orquestador) y no en BoletaPdfDocumento.
     *
     * @return array{FchResol: string, NroResol: string}
     */
    private function resolucionPorXPath(string $envioXml): array
    {
        $dom    = new DOMDocument();
        $previo = libxml_use_internal_errors(true);
        $dom->loadXML($envioXml);
        libxml_use_internal_errors($previo);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('sii', 'http://www.sii.cl/SiiDte');

        $fch = $xpath->query('//sii:Caratula/sii:FchResol')->item(0);
        $nro = $xpath->query('//sii:Caratula/sii:NroResol')->item(0);

        return [
            'FchResol' => $fch !== null ? trim($fch->textContent) : '',
            'NroResol' => $nro !== null ? trim($nro->textContent) : '0',
        ];
    }

    /**
     * Registra (una sola vez) el autoloader de LibreDTE y carga TCPDF. Duplica
     * el bootstrap de DtePdfGenerator a proposito: mantiene esta clase
     * independiente, sin acoplarse a esa otra clase.
     */
    private static function registrarLibreDte(): void
    {
        if (self::$autoloadRegistrado) {
            return;
        }

        $raiz  = __DIR__ . '/../..';
        $tcpdf = $raiz . '/vendor/tecnickcom/tcpdf/tcpdf.php';
        if (! is_file($tcpdf)) {
            throw new RuntimeException('TCPDF no disponible en ' . $tcpdf);
        }
        require_once $tcpdf;

        $base = $raiz . '/oracle/LibreDTE-master/lib';
        spl_autoload_register(static function (string $class) use ($base): void {
            $prefijo = 'sasco\\LibreDTE\\';
            if (strncmp($class, $prefijo, strlen($prefijo)) !== 0) {
                return;
            }
            $archivo = $base . '/' . str_replace('\\', '/', substr($class, strlen($prefijo))) . '.php';
            if (is_file($archivo)) {
                require $archivo;
            }
        });

        self::$autoloadRegistrado = true;
    }
}
