<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Pdf;

use DOMDocument;
use DOMXPath;
use RuntimeException;

/**
 * Genera el PDF (con timbre PDF417) de un DTE a partir de su EnvioDTE, usando el
 * generador SII-compliant de LibreDTE (sasco\LibreDTE\Sii\PDF\Dte) + TCPDF.
 *
 * Es la version reutilizable del armado de scripts/generar_muestras_pdf.php: toma
 * un EnvioDTE de UN documento (lo que persiste dte_emitido) y devuelve el binario
 * del PDF. La resolucion (FchResol/NroResol) sale de la Caratula del propio XML.
 *
 * LibreDTE es codigo de 2015: corre en PHP 8.2 con los avisos silenciados, igual
 * que el script original.
 */
final class DtePdfGenerator
{
    private static bool $autoloadRegistrado = false;

    public function __construct()
    {
        self::registrarLibreDte();
    }

    /**
     * Genera el PDF de UN documento de un EnvioDTE.
     *
     * Sin $tipoDte/$folio (retrocompatible: scripts CLI, cualquier llamador que
     * hoy pasa solo $envioXml o $envioXml+$cedible): renderiza el PRIMER (y
     * normalmente unico) <DTE> del sobre, igual que el comportamiento vigente
     * antes de esta tarea.
     *
     * Con $tipoDte y $folio (usado por GET /api/v1/dte/{tipoDte}/{folio}/pdf
     * cuando el XML persistido es el SOBRE COMPLETO de un lote -- ver
     * SiiDirectoFacturador::emitirLote()/persistirEmitidosLote(), que guardan
     * el mismo EnvioDTE de varios documentos en cada fila de dte_emitido):
     * busca DENTRO del EnvioDTE el <DTE> cuyo TipoDTE y Folio coinciden y
     * renderiza SOLO ese. Si no existe ese documento en el sobre, lanza
     * excepcion en vez de caer al primero (eso ocultaria el bug de folio
     * equivocado en vez de mostrarlo).
     *
     * NO se reimplementa el render de LibreDTE ni se reconstruye un EnvioDTE
     * minimo: EnvioDte::getDocumentos() YA devuelve un array de objetos Dte
     * INDEPENDIENTES, uno por cada <DTE> del sobre (cada uno construido desde
     * el C14N de su propio nodo, ver EnvioDte::getDocumentos() en
     * oracle/LibreDTE-master/lib/Sii/EnvioDte.php:317-336). Alcanza con
     * seleccionar el objeto correcto del array ya devuelto.
     *
     * EL LOGO ES EL QUINTO PARAMETRO, OPCIONAL Y AL FINAL, y esa posicion no es
     * casual. El logo NO viaja en el XML -- es el unico dato del PDF que no sale
     * de ahi --, asi que tiene que entrar por la firma. Y tiene que entrar SIN
     * mover los cuatro anteriores porque MuestrasImpresasZipBuilder no llama a
     * este metodo por nombre sino que lo guarda como CALLABLE
     * (Closure::fromCallable en su constructor): cambiar el orden o el tipo de
     * los parametros existentes romperia esa indireccion en silencio.
     *
     * Se espera lo que devuelve LogoEmpresa::paraTcpdf(): los bytes del PNG con
     * un '@' delante, o null. Null recorre exactamente el camino de siempre.
     *
     * @param ?string $logo Bytes del PNG con prefijo '@', o null si no hay.
     * @return string Binario del PDF.
     */
    public function generarDesdeEnvioXml(
        string $envioXml,
        bool $cedible = false,
        ?int $tipoDte = null,
        ?int $folio = null,
        ?string $logo = null,
    ): string {
        date_default_timezone_set('America/Santiago');
        error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

        $envio = new \sasco\LibreDTE\Sii\EnvioDte();
        $envio->loadXML($envioXml);

        $caratula   = $envio->getCaratula();
        $documentos = $envio->getDocumentos();
        if (! $documentos) {
            throw new RuntimeException('No se pudieron leer documentos del EnvioDTE');
        }

        $dte   = $this->seleccionarDocumento($documentos, $tipoDte, $folio);
        $datos = $dte->getDatos();
        if (! $datos) {
            throw new RuntimeException('El DTE no tiene datos');
        }
        $ted = $dte->getTED();

        // Boleta (39/41): EnvioDte::getCaratula() de LibreDTE hardcodea la clave
        // 'EnvioDTE' y devuelve false para un sobre <EnvioBOLETA>. En vez de tocar
        // esa clase de terceros, se resuelve FchResol/NroResol por XPath directo
        // sobre el XML solo para boleta; factura sigue usando getCaratula() igual
        // que siempre.
        $tipoDteDetectado = (int) ($datos['Encabezado']['IdDoc']['TipoDTE'] ?? 0);
        $esBoleta         = in_array($tipoDteDetectado, [39, 41], true);

        $resolucion = $esBoleta
            ? $this->resolucionPorXPath($envioXml)
            : [
                'FchResol' => $caratula['FchResol'] ?? '',
                'NroResol' => $caratula['NroResol'] ?? '0',
            ];

        // FORK PROPIO en vez de sasco\LibreDTE\Sii\PDF\Dte. Misma clase copiada
        // literal, con un solo cambio: dibuja los impuestos adicionales, que la
        // original descarta al normalizar los totales a cinco claves. El porque
        // del fork -- y por que no un parche en oracle/, que no esta en git --
        // esta en el docblock de DtePdfDocumento.
        //
        // Va DESPUES de registrarLibreDte(): la clase extiende
        // sasco\LibreDTE\PDF, que solo existe una vez registrado ese autoloader.
        $pdf = new DtePdfDocumento();
        $pdf->setResolucion($resolucion);
        // Sin logo NO se llama a setLogo(), y eso es lo que mantiene la inercia:
        // agregarEmisor() decide por isset($this->logo), asi que una empresa sin
        // logo dibuja exactamente los mismos operadores que antes de esta
        // entrega. Es el mismo mecanismo del impuesto adicional y del FchVenc.
        if ($logo !== null && $logo !== '') {
            $pdf->setLogo($logo);
        }
        if ($cedible) {
            $pdf->setCedible(true);
        }
        $pdf->agregar($datos, $ted);

        // 'S' = devolver el PDF como string (no escribir a disco ni enviar al navegador).
        return (string) $pdf->Output('', 'S');
    }

    /**
     * Resuelve FchResol/NroResol leyendo <Caratula> directo del XML por XPath.
     * Necesario para boleta porque EnvioDte::getCaratula() de LibreDTE no reconoce
     * la raiz <EnvioBOLETA> (solo <EnvioDTE>).
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
     * Selecciona, dentro de los <DTE> ya parseados de un EnvioDTE, el
     * documento a renderizar. Ver generarDesdeEnvioXml() para el criterio
     * completo (retrocompat sin parametros; match exacto tipo+folio con ellos).
     *
     * @param list<\sasco\LibreDTE\Sii\Dte> $documentos
     */
    private function seleccionarDocumento(array $documentos, ?int $tipoDte, ?int $folio): \sasco\LibreDTE\Sii\Dte
    {
        if ($tipoDte === null && $folio === null) {
            return $documentos[0];
        }
        if ($tipoDte === null || $folio === null) {
            throw new \InvalidArgumentException(
                'generarDesdeEnvioXml: tipoDte y folio deben pasarse juntos (o ninguno)'
            );
        }
        foreach ($documentos as $dte) {
            if ((int) $dte->getTipo() === $tipoDte && (int) $dte->getFolio() === $folio) {
                return $dte;
            }
        }

        throw new RuntimeException("Documento tipo {$tipoDte} folio {$folio} no esta en el EnvioDTE");
    }

    /**
     * Registra (una sola vez) el autoloader de LibreDTE y carga TCPDF. LibreDTE no
     * usa Composer; el script original lo carga con un spl_autoload_register propio.
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
