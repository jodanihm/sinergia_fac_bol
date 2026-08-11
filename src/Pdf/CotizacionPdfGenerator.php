<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Pdf;

/**
 * Genera el PDF de una cotizacion.
 *
 * MISMO PAPEL QUE DtePdfGenerator, PERO SIN XML NI SII: la cotizacion no viaja
 * en ningun EnvioDTE, asi que este generador recibe arreglos del panel
 * (MySqlCotizacionRepository) y no un documento firmado.
 *
 * EXTIENDE DtePdfGenerator SOLO PARA REUSAR EL REGISTRO DEL AUTOLOADER de
 * LibreDTE, que es private static y no se puede llamar de otra forma. Instanciar
 * el padre es lo unico que hace falta: su constructor lo registra. No se hereda
 * nada mas ni se llama a ninguno de sus metodos -- de ahi que esta clase sea
 * final y no comparta jerarquia con el documento.
 */
final class CotizacionPdfGenerator
{
    /**
     * @param array<string,mixed>       $emisor     RznSoc, GiroEmis, DirOrigen, CmnaOrigen, RUTEmisor, Telefono?, CorreoEmisor?
     * @param array<string,mixed>       $cotizacion cabecera tal como la devuelve el repositorio
     * @param list<array<string,mixed>> $lineas     lineas tal como las devuelve el repositorio
     * @param ?string                   $logo       lo que devuelve LogoEmpresa::paraTcpdf(), o null
     * @return string Binario del PDF.
     */
    public function generar(array $emisor, array $cotizacion, array $lineas, ?string $logo = null): string
    {
        date_default_timezone_set('America/Santiago');
        // Mismo silenciado que DtePdfGenerator: LibreDTE y TCPDF son codigo de
        // 2015 lleno de notices. OJO: esto es lo que hizo que el defecto del
        // telefono viviera años sin que nadie viera el warning.
        error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

        // El constructor de DtePdfGenerator registra el autoloader de LibreDTE,
        // sin el cual \sasco\LibreDTE\PDF no existe y la clase del documento no
        // se puede ni declarar.
        new DtePdfGenerator();

        $pdf = new CotizacionPdfDocumento();
        $pdf->setFooterText([
            'left'  => (string) ($emisor['RznSoc'] ?? ''),
            // Con paginacion el numero de pagina deja de ser decorativo: una
            // cotizacion de 40 lineas ocupa dos hojas y hay que saber si falta
            // alguna. getAliasNumPage()/getAliasNbPages() los sustituye TCPDF al
            // cerrar el documento, cuando ya sabe cuantas paginas hubo.
            'right' => 'Pagina ' . $pdf->getAliasNumPage() . ' de ' . $pdf->getAliasNbPages(),
        ]);
        if ($logo !== null && $logo !== '') {
            $pdf->setLogo($logo);
        }
        $pdf->agregar($emisor, $cotizacion, $lineas);

        // 'S' = devolver como string, no escribir a disco ni mandar al navegador.
        return (string) $pdf->Output('', 'S');
    }
}
