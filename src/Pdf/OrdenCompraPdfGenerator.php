<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Pdf;

/**
 * Genera el PDF de una orden de compra.
 *
 * Mismo papel que CotizacionPdfGenerator y por el mismo motivo: la orden no
 * viaja en ningun XML firmado, asi que recibe arreglos del panel
 * (MySqlOrdenCompraRepository) y no un documento del SII.
 */
final class OrdenCompraPdfGenerator
{
    /**
     * @param array<string,mixed>       $emisor
     * @param array<string,mixed>       $orden   cabecera tal como la devuelve el repositorio
     * @param list<array<string,mixed>> $lineas
     * @param ?string                   $logo    lo que devuelve LogoEmpresa::paraTcpdf(), o null
     */
    public function generar(array $emisor, array $orden, array $lineas, ?string $logo = null): string
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

        $pdf = new OrdenCompraPdfDocumento();
        $pdf->setFooterText([
            'left'  => (string) ($emisor['RznSoc'] ?? ''),
            // Con paginacion el numero de pagina deja de ser decorativo: una
            // orden de 40 lineas ocupa dos hojas y el proveedor tiene que poder
            // saber si le falta alguna.
            'right' => 'Pagina ' . $pdf->getAliasNumPage() . ' de ' . $pdf->getAliasNbPages(),
        ]);
        if ($logo !== null && $logo !== '') {
            $pdf->setLogo($logo);
        }
        $pdf->agregar($emisor, $orden, $lineas);

        return (string) $pdf->Output('', 'S');
    }
}
