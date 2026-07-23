<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Pdf;

/**
 * Dibuja el PDF de una BOLETA electronica (39/41) en formato A5 (148x210mm).
 *
 * Renderizador propio y liviano — a proposito NO usa sasco\LibreDTE\Sii\PDF\Dte:
 * esa clase tiene coordenadas hardcodeadas pensadas para A4/factura (confirmado
 * el 2026-07-09: bloques como el recuadro de folio llegan hasta x=200mm, muy por
 * encima del ancho de A5) y es codigo de terceros compartido con factura en
 * produccion. En vez de arriesgar ese archivo, esta clase:
 *   - Extiende sasco\LibreDTE\PDF (el wrapper GENERICO de TCPDF: Texto()/
 *     MultiTexto()/footer, SIN ninguna coordenada de layout de documento) para
 *     heredar esos helpers y write2DBarcode() (metodo publico de TCPDF).
 *   - Dibuja con coordenadas propias, pensadas desde cero para 148mm de ancho.
 *
 * Solo se instancia desde BoletaPdfGenerator::generarDesdeEnvioXml(), DESPUES
 * de que ese orquestador ya registro el autoloader de LibreDTE/TCPDF (por eso
 * esta clase puede extender sasco\LibreDTE\PDF sin el problema de orden de
 * carga que tendria si extendiera esa clase directamente en el orquestador).
 *
 * Layout: titulo, folio+fecha, emisor, receptor (solo RUT+razonSocial, boleta
 * no lleva giro/direccion/comuna de receptor), detalle en texto simple (sin
 * tabla de columnas fijas), totales, timbre PDF417, leyenda de verificacion.
 */
final class BoletaPdfDocumento extends \sasco\LibreDTE\PDF
{
    private const ANCHO = 148;
    private const X0    = 10;
    private const X1    = 138; // ANCHO - X0

    public function __construct()
    {
        parent::__construct('P', 'mm', 'A5', 0);
        $this->SetMargins(self::X0, 8, self::X0);
        $this->SetAutoPageBreak(true, 10);
        $this->SetTitle('Boleta Electronica (DTE) de Chile');
        $this->AddPage();
    }

    /**
     * @param array{FchResol: string, NroResol: string} $resolucion
     */
    public function dibujar(array $datos, string $ted, array $resolucion): void
    {
        $idDoc    = $datos['Encabezado']['IdDoc'];
        $emisor   = $datos['Encabezado']['Emisor'];
        $receptor = $datos['Encabezado']['Receptor'];
        $totales  = $datos['Encabezado']['Totales'];
        $detalle  = $datos['Detalle'];
        if (! isset($detalle[0])) {
            $detalle = [$detalle]; // XML::toArray() colapsa un unico item a array asociativo plano.
        }

        // --- Titulo ---
        $this->setFont('', 'B', 14);
        $this->Texto('BOLETA ELECTRONICA', self::X0, 8, 'C', self::X1 - self::X0);

        // --- Folio + fecha ---
        $this->setFont('', 'B', 10);
        $this->Texto('Folio: ' . $idDoc['Folio'], self::X0, 16, 'L');
        $this->setFont('', '', 10);
        $this->Texto('Fecha: ' . $this->fecha($idDoc['FchEmis']), self::X0, 16, 'R', self::X1 - self::X0);

        $this->lineaSeparadora(21);

        // --- Emisor ---
        $y = 24;
        $this->setFont('', 'B', 11);
        $this->MultiTexto($emisor['RznSocEmisor'], self::X0, $y, 'L', self::X1 - self::X0);
        $y = $this->getY() + 1;
        $this->setFont('', '', 9);
        $this->MultiTexto('RUT: ' . $emisor['RUTEmisor'], self::X0, $y, 'L', self::X1 - self::X0);
        $y = $this->getY();
        if (! empty($emisor['GiroEmisor'])) {
            $this->MultiTexto($emisor['GiroEmisor'], self::X0, $y, 'L', self::X1 - self::X0);
            $y = $this->getY();
        }
        if (! empty($emisor['DirOrigen'])) {
            $dir = $emisor['DirOrigen'] . (! empty($emisor['CmnaOrigen']) ? ', ' . $emisor['CmnaOrigen'] : '');
            $this->MultiTexto($dir, self::X0, $y, 'L', self::X1 - self::X0);
            $y = $this->getY();
        }

        $this->lineaSeparadora($y + 2);

        // --- Receptor: SOLO RUT + razonSocial (boleta no lleva giro/direccion/comuna) ---
        $y = $y + 5;
        $this->setFont('', 'B', 9);
        $this->Texto('Receptor', self::X0, $y, 'L');
        $y += 4;
        $this->setFont('', '', 9);
        $this->MultiTexto('RUT: ' . $receptor['RUTRecep'] . '  -  ' . $receptor['RznSocRecep'], self::X0, $y, 'L', self::X1 - self::X0);
        $y = $this->getY();

        $this->lineaSeparadora($y + 2);

        // --- Detalle: texto simple, sin tabla de columnas fijas ---
        $y = $y + 5;
        $this->setFont('', 'B', 9);
        $this->Texto('Detalle', self::X0, $y, 'L');
        $y += 4;
        $this->setFont('', '', 9);
        $wMonto  = 25;
        $wNombre = self::X1 - self::X0 - $wMonto;
        foreach ($detalle as $item) {
            $cant   = (float) ($item['QtyItem'] ?? 1);
            $precio = (float) ($item['PrcItem'] ?? 0);
            $monto  = isset($item['MontoItem']) ? (float) $item['MontoItem'] : $cant * $precio;
            $linea  = $this->num($cant, $cant == (int) $cant ? 0 : 2) . ' x ' . ($item['NmbItem'] ?? '');
            $this->Texto($linea, self::X0, $y, 'L', $wNombre);
            $this->Texto('$' . $this->num($monto), self::X0 + $wNombre, $y, 'R', $wMonto);
            $y += 5;
        }

        $this->lineaSeparadora($y + 1);

        // --- Totales ---
        $y = $y + 4;
        $this->setFont('', '', 9);
        foreach ([['MntNeto', 'Neto'], ['MntExe', 'Exento'], ['IVA', 'IVA']] as [$clave, $glosa]) {
            if (! empty($totales[$clave])) {
                $this->Texto($glosa . ':', self::X0, $y, 'L');
                $this->Texto('$' . $this->num((float) $totales[$clave]), self::X0, $y, 'R', self::X1 - self::X0);
                $y += 5;
            }
        }
        $this->setFont('', 'B', 11);
        $this->Texto('Total:', self::X0, $y, 'L');
        $this->Texto('$' . $this->num((float) ($totales['MntTotal'] ?? 0)), self::X0, $y, 'R', self::X1 - self::X0);
        $y += 8;

        // --- Timbre PDF417 ---
        $wTimbre = 55;
        $xTimbre = self::X0 + ((self::X1 - self::X0 - $wTimbre) / 2);
        $this->agregarTimbre($ted, $xTimbre, $y, $wTimbre);
        $y += $wTimbre * 0.35 + 4; // alto aproximado del PDF417 boleta + separacion

        $this->setFont('', 'B', 8);
        $this->Texto('Timbre Electronico SII', self::X0, $y, 'C', self::X1 - self::X0);
        $y += 4;
        $this->setFont('', '', 7);
        if ($resolucion['FchResol'] !== '') {
            $this->Texto(
                'Resolucion ' . $resolucion['NroResol'] . ' de ' . explode('-', $resolucion['FchResol'])[0],
                self::X0,
                $y,
                'C',
                self::X1 - self::X0,
            );
            $y += 3.5;
        }
        $this->Texto('Verifique documento: www.sii.cl', self::X0, $y, 'C', self::X1 - self::X0, 'http://www.sii.cl');
    }

    private function agregarTimbre(string $timbre, float $x, float $y, float $w): void
    {
        $style = [
            'border'        => false,
            'vpadding'      => 0,
            'hpadding'      => 0,
            'fgcolor'       => [0, 0, 0],
            'bgcolor'       => false,
            'module_width'  => 1,
            'module_height' => 1,
        ];
        $this->write2DBarcode($timbre, 'PDF417', $x, $y, $w, 0, $style, 'B');
    }

    private function lineaSeparadora(float $y): void
    {
        $this->Line(self::X0, $y, self::X1, $y, ['width' => 0.2, 'color' => [180, 180, 180]]);
    }

    private function fecha(string $fchEmis): string
    {
        $ts = strtotime($fchEmis);
        return $ts !== false ? date('d-m-Y', $ts) : $fchEmis;
    }

    private function num(float $n, int $d = 0): string
    {
        return number_format($n, $d, ',', '.');
    }
}
