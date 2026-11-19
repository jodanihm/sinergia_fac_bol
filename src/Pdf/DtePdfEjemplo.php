<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Pdf;

/**
 * FACTURA DE EJEMPLO para "Configuracion > Vista previa de facturas".
 *
 * Arma el arreglo de datos de una factura electronica (33) con los datos
 * REALES del emisor -- razon social, giro, direccion, comuna, resolucion y
 * logo, que es justo lo que la empresa quiere revisar -- y un receptor,
 * detalle y totales FICTICIOS, y lo dibuja con el mismo DtePdfDocumento de
 * las facturas emitidas (DtePdfGenerator::generarDesdeDatos()).
 *
 * NO PUEDE PASAR POR UNA FACTURA: folio 0, receptor de ejemplo con el RUT
 * generico 66.666.666-6, un timbre que no es un TED (su codigo de barras dice
 * "documento de ejemplo") y una marca de agua "EJEMPLO" cruzada sobre la
 * pagina, mas una franja arriba que lo dice con todas las letras. No se
 * guarda en ninguna tabla ni consume folio.
 */
final class DtePdfEjemplo
{
    public const MARCA = 'EJEMPLO - DOCUMENTO SIN VALIDEZ TRIBUTARIA - NO CONSUME FOLIO';

    /**
     * @param array<string,string> $emisor RUTEmisor, RznSoc, GiroEmis, DirOrigen, CmnaOrigen (datosEmisorParaImpreso()).
     * @param array{FchResol:string,NroResol:string} $resolucion
     * @param ?string $logo Bytes del PNG con '@' delante (LogoEmpresa::paraTcpdf()), o null.
     */
    public static function generar(array $emisor, array $resolucion, ?string $logo): string
    {
        return (new DtePdfGenerator())->generarDesdeDatos(
            self::datos($emisor),
            'DOCUMENTO DE EJEMPLO SIN VALIDEZ TRIBUTARIA',
            $resolucion,
            $logo,
            self::MARCA
        );
    }

    /**
     * Los datos del documento. Publico para poder probarlo sin dibujar.
     *
     * @param array<string,string> $emisor
     * @return array<string,mixed>
     */
    public static function datos(array $emisor): array
    {
        $lineas = [
            ['Servicio de asesoria contable mensual', 'Periodo en curso', 1, 180000],
            ['Licencia de software de gestion', 'Plan anual, 5 usuarios', 1, 240000],
            ['Capacitacion presencial', 'Jornada de 4 horas', 2, 65000],
        ];
        $detalle = [];
        $neto    = 0;
        foreach ($lineas as $i => [$nombre, $desc, $cant, $precio]) {
            $monto     = $cant * $precio;
            $neto     += $monto;
            $detalle[] = [
                'NroLinDet' => $i + 1,
                'NmbItem'   => $nombre,
                'DscItem'   => $desc,
                'QtyItem'   => $cant,
                'PrcItem'   => $precio,
                'MontoItem' => $monto,
            ];
        }
        $iva = (int) round($neto * 0.19);

        $hoy = date('Y-m-d');

        return [
            'Encabezado' => [
                'IdDoc' => [
                    'TipoDTE' => 33,
                    'Folio'   => 0,
                    'FchEmis' => $hoy,
                    'FmaPago' => 1,
                    'FchVenc' => date('Y-m-d', strtotime('+30 days')),
                ],
                'Emisor' => [
                    'RUTEmisor'  => (string) ($emisor['RUTEmisor'] ?? '76.000.000-0'),
                    'RznSoc'     => (string) ($emisor['RznSoc'] ?? 'Tu empresa'),
                    'GiroEmis'   => (string) ($emisor['GiroEmis'] ?? 'Giro de tu empresa'),
                    'DirOrigen'  => (string) ($emisor['DirOrigen'] ?? 'Direccion de tu empresa'),
                    'CmnaOrigen' => (string) ($emisor['CmnaOrigen'] ?? 'Comuna'),
                ],
                'Receptor' => [
                    'RUTRecep'    => '66666666-6',
                    'RznSocRecep' => 'CLIENTE DE EJEMPLO SPA',
                    'GiroRecep'   => 'Comercializadora de ejemplo',
                    'DirRecep'    => 'Av. Ejemplo 1234, Oficina 56',
                    'CmnaRecep'   => 'Santiago',
                ],
                'Totales' => [
                    'MntNeto'  => $neto,
                    'TasaIVA'  => 19,
                    'IVA'      => $iva,
                    'MntTotal' => $neto + $iva,
                ],
            ],
            'Detalle' => $detalle,
        ];
    }
}
