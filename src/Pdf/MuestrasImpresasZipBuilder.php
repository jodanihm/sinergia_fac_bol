<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Pdf;

use Closure;
use RuntimeException;
use ZipArchive;

/**
 * Genera los PDF de "Muestras Impresas" (Set Basico + Simulacion) y los
 * empaqueta en un unico ZIP para descarga -- la subida en si al SII
 * (www4.sii.cl/pdfdteInternet) sigue siendo manual (portal web, sin API).
 *
 * Reusa DtePdfGenerator TAL CUAL (mismo mecanismo que GET /api/v1/dte/{tipo}/{folio}/pdf,
 * incluido el fix de seleccionar el documento correcto dentro de un sobre con
 * varios). Las facturas (33) llevan copia CEDIBLE ademas de la tributaria; NC
 * (61) y ND (56) no.
 *
 * El generador de PDF se inyecta como callable (default: DtePdfGenerator real)
 * en vez de acoplarse a esa clase concreta: renderizar un PDF real requiere un
 * TED firmado con un CAF (LibreDTE/TCPDF fallan con datos sinteticos
 * incompletos), asi que los tests de empaquetado/nombrado usan un generador
 * FALSO liviano -- la generacion real ya esta probada en produccion via
 * GET /api/v1/dte/{tipo}/{folio}/pdf, no hace falta re-probarla aqui.
 *
 * Requiere la extension ZipArchive de PHP (no viene por defecto en la imagen
 * Docker base -- ver Dockerfile.php, que ya la agrega). Si no esta disponible
 * en tiempo de ejecucion, lanza RuntimeException en vez de fallar a medias.
 */
final class MuestrasImpresasZipBuilder
{
    private const TIPOS_CON_CEDIBLE = [33];

    private const NOMBRES_TIPO = [
        33 => 'factura_33',
        61 => 'nc_61',
        56 => 'nd_56',
    ];

    private Closure $generarPdf;

    /** @param ?callable(string,bool,int,int):string $generarPdf */
    public function __construct(?callable $generarPdf = null)
    {
        $this->generarPdf = Closure::fromCallable(
            $generarPdf ?? [new DtePdfGenerator(), 'generarDesdeEnvioXml']
        );
    }

    /**
     * @param list<array{tipoDte:int, folio:int, xml:string, origen:string}> $documentos
     *        origen: texto libre usado como prefijo del nombre de archivo (ej. "prueba", "simulacion").
     * @return array{zip: string, archivos: list<array{nombre:string, tipoDte:int, folio:int, origen:string, cedible:bool}>}
     */
    public function construir(array $documentos): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('La extension ZipArchive de PHP no esta disponible en el servidor.');
        }
        if ($documentos === []) {
            throw new RuntimeException('No hay documentos para empaquetar en el ZIP de muestras impresas.');
        }

        $tmpZip = tempnam(sys_get_temp_dir(), 'muestras_impresas_');
        if ($tmpZip === false) {
            throw new RuntimeException('No se pudo crear un archivo temporal para el ZIP.');
        }

        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpZip);
            throw new RuntimeException('No se pudo abrir el archivo ZIP para escritura.');
        }

        $archivos = [];
        try {
            foreach ($documentos as $d) {
                $nombreBase = sprintf(
                    '%s_%s_folio%d',
                    $d['origen'],
                    self::NOMBRES_TIPO[$d['tipoDte']] ?? ('tipo_' . $d['tipoDte']),
                    $d['folio']
                );

                $pdf    = ($this->generarPdf)($d['xml'], false, $d['tipoDte'], $d['folio']);
                $nombre = $nombreBase . '.pdf';
                $zip->addFromString($nombre, $pdf);
                $archivos[] = [
                    'nombre' => $nombre, 'tipoDte' => $d['tipoDte'], 'folio' => $d['folio'],
                    'origen' => $d['origen'], 'cedible' => false,
                ];

                if (in_array($d['tipoDte'], self::TIPOS_CON_CEDIBLE, true)) {
                    $pdfCedible    = ($this->generarPdf)($d['xml'], true, $d['tipoDte'], $d['folio']);
                    $nombreCedible = $nombreBase . '_cedible.pdf';
                    $zip->addFromString($nombreCedible, $pdfCedible);
                    $archivos[] = [
                        'nombre' => $nombreCedible, 'tipoDte' => $d['tipoDte'], 'folio' => $d['folio'],
                        'origen' => $d['origen'], 'cedible' => true,
                    ];
                }
            }
        } finally {
            $zip->close();
        }

        $zipBytes = file_get_contents($tmpZip);
        unlink($tmpZip);
        if ($zipBytes === false) {
            throw new RuntimeException('No se pudo leer el ZIP generado.');
        }

        return ['zip' => $zipBytes, 'archivos' => $archivos];
    }
}
