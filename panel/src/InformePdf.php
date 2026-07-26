<?php

declare(strict_types=1);

/**
 * Documento PDF de un informe del panel: membrete, tabla y pie.
 *
 * USA TCPDF DIRECTO desde vendor/ (Composer lo autocarga por classmap, ver
 * vendor/composer/autoload_classmap.php: 'TCPDF' => tecnickcom/tcpdf/tcpdf.php).
 *
 * A PROPOSITO NO se reutiliza sasco\LibreDTE\PDF, que es lo que extienden las
 * cuatro clases de src/Pdf/ del motor. Dos razones:
 *   1. Ese wrapper vive en oracle/LibreDTE-master/, un directorio IGNORADO por
 *      git (.gitignore, bloque C1) y con 0 archivos versionados. Depender de el
 *      desde el panel ataria el modulo de informes a codigo que no esta en el
 *      repositorio.
 *   2. Lo unico que aporta es layout de DTE y timbre PDF417. Un informe no
 *      lleva timbre, ni folio, ni CAF: es una tabla con membrete.
 *
 * Orientacion HORIZONTAL (A4 landscape) por el informe de detalle documento a
 * documento, que lleva 8 columnas y en vertical las corta. Los otros cinco se
 * ven bien igual en horizontal.
 *
 * Header() y Footer() se sobrescriben en vez de dibujarse a mano porque TCPDF
 * los invoca solo en CADA pagina: asi el membrete se repite cuando el detalle
 * pagina, sin tener que controlar los saltos desde el llamador.
 */
final class InformePdf extends TCPDF
{
    /** El mismo logo que sirve el sidebar. panel/src/ -> panel/public/img/. */
    private const LOGO = __DIR__ . '/../public/img/logo.png';

    private const MARGEN = 12;

    /** Reserva horizontal del logo dentro del membrete, en mm. */
    private const ANCHO_LOGO = 42;

    public function __construct(
        private string $tituloInforme,
        private string $razonSocial,
        private string $rutEmisor,
        private string $periodo = '',
    ) {
        parent::__construct('L', 'mm', 'A4', true, 'UTF-8');

        $this->SetCreator('Sinergia');
        $this->SetAuthor($this->razonSocial);
        $this->SetTitle($this->tituloInforme);
        $this->SetMargins(self::MARGEN, 30, self::MARGEN);
        $this->SetAutoPageBreak(true, 18);

        // TCPDF estampa "Powered by TCPDF (www.tcpdf.org)" en el pie por
        // defecto (propiedad tcpdflink). Un informe que el tenant entrega a su
        // contador o a un banco no lleva publicidad de la libreria.
        $this->tcpdflink = false;

        $this->AddPage();
    }

    /**
     * Membrete. Se repite en cada pagina: lo llama TCPDF, no el llamador.
     */
    public function Header(): void // phpcs:ignore PSR1.Methods.CamelCapsMethodName
    {
        if (self::puedeDibujarLogo()) {
            // Alto fijo 12mm y ancho 0 = automatico: misma logica que el
            // max-height del sidebar, el logo no se deforma nunca.
            $this->Image(self::LOGO, self::MARGEN, 8, 0, 12, 'PNG', '', 'T', true, 300);
        }

        $x = self::MARGEN + self::ANCHO_LOGO;

        $this->SetFont('helvetica', 'B', 13);
        $this->SetTextColor(34, 34, 34);
        $this->SetXY($x, 9);
        $this->Cell(0, 6, $this->tituloInforme, 0, 1, 'L');

        $this->SetFont('helvetica', '', 9);
        $this->SetTextColor(90, 90, 90);
        $this->SetX($x);
        $this->Cell(0, 5, $this->razonSocial . '  -  RUT ' . $this->rutEmisor, 0, 1, 'L');

        if ($this->periodo !== '') {
            $this->SetX($x);
            $this->Cell(0, 5, 'Periodo: ' . $this->periodo, 0, 1, 'L');
        }

        $this->SetDrawColor(221, 221, 221);
        $this->Line(self::MARGEN, 26, $this->getPageWidth() - self::MARGEN, 26);
    }

    /**
     * Pie: fecha de generacion a la izquierda, paginacion a la derecha.
     */
    public function Footer(): void // phpcs:ignore PSR1.Methods.CamelCapsMethodName
    {
        $this->SetY(-14);
        $this->SetFont('helvetica', '', 8);
        $this->SetTextColor(110, 110, 110);

        // El ancho se parte en dos mitades para que las dos celdas no se pisen
        // ni empujen un salto de linea.
        $mitad = ($this->getPageWidth() - 2 * self::MARGEN) / 2;
        $this->Cell($mitad, 5, 'Generado el ' . date('d-m-Y H:i'), 0, 0, 'L');
        $this->Cell(
            $mitad,
            5,
            'Pagina ' . $this->getAliasNumPage() . ' de ' . $this->getAliasNbPages(),
            0,
            0,
            'R'
        );
    }

    /**
     * Dibuja la tabla del informe.
     *
     * Las tres estructuras vienen de informeColumnasYFilas() en el front
     * controller, la MISMA que alimenta la vista previa en pantalla y el
     * Excel: es lo que impide que los tres formatos se desincronicen.
     *
     * @param list<array{titulo:string, ancho:float, alineacion:string}> $columnas
     * @param list<list<string>>                                        $filas
     * @param list<string>|null                                         $totales cadena vacia donde no aplique
     */
    public function tabla(array $columnas, array $filas, ?array $totales = null): void
    {
        if ($columnas === []) {
            return;
        }

        $this->encabezado($columnas);

        $this->SetFont('helvetica', '', 8.5);
        $this->SetTextColor(34, 34, 34);
        $this->SetFillColor(250, 250, 251);

        foreach ($filas as $i => $fila) {
            // El encabezado se repite tras un salto de pagina: TCPDF ya llamo a
            // Header(), pero los titulos de columna son de la tabla, no del
            // membrete, asi que hay que reponerlos aqui.
            if ($this->GetY() > $this->getPageHeight() - 28) {
                $this->AddPage();
                $this->encabezado($columnas);
                $this->SetFont('helvetica', '', 8.5);
                $this->SetTextColor(34, 34, 34);
            }

            $alterna = $i % 2 === 1;
            foreach ($fila as $j => $celda) {
                if (! isset($columnas[$j])) {
                    continue;
                }
                $texto = self::formatear($celda, $columnas[$j]['num'] ?? false);
                $this->Cell($columnas[$j]['ancho'], 6, $texto, 0, 0, $columnas[$j]['alineacion'], $alterna);
            }
            $this->Ln();
        }

        if ($filas === []) {
            $this->SetFont('helvetica', 'I', 9);
            $this->SetTextColor(110, 110, 110);
            $this->Cell(0, 10, 'Sin datos para el periodo seleccionado.', 0, 1, 'L');
            return;
        }

        if ($totales !== null) {
            $this->SetFont('helvetica', 'B', 8.5);
            $this->SetTextColor(34, 34, 34);
            $this->SetDrawColor(180, 180, 180);
            foreach ($totales as $j => $celda) {
                if (! isset($columnas[$j])) {
                    continue;
                }
                $texto = self::formatear($celda, $columnas[$j]['num'] ?? false);
                $this->Cell($columnas[$j]['ancho'], 7, $texto, 'T', 0, $columnas[$j]['alineacion']);
            }
            $this->Ln();
        }
    }

    /**
     * Las filas traen los numeros EN CRUDO (int), no como texto formateado: el
     * Excel los necesita asi para poder sumarlos, y si vinieran como "100.000"
     * PhpSpreadsheet leeria 100 al interpretar el punto como decimal. El PDF,
     * que si es presentacion pura, los formatea aqui.
     *
     * Se formatea en la clase y no llamando a informeCelda() del front
     * controller para que InformePdf no dependa de una funcion global y siga
     * siendo testeable por si sola.
     */
    private static function formatear(mixed $valor, bool $esNumerica): string
    {
        if (! $esNumerica || $valor === '' || $valor === null) {
            return (string) $valor;
        }

        return number_format(is_numeric($valor) ? (float) $valor : 0.0, 0, ',', '.');
    }

    /**
     * El logo es RGBA con transparencia, y TCPDF EXIGE GD o Imagick para PNG
     * con canal alfa: sin ninguna de las dos aborta la generacion entera con
     * "TCPDF ERROR: TCPDF requires the Imagick or GD extension...".
     *
     * La imagen del proyecto (Dockerfile.php) instala gd, asi que en produccion
     * el logo se dibuja. Esta comprobacion existe para que un entorno sin gd
     * entregue el informe SIN membrete grafico en vez de no entregar nada: el
     * logo es decorativo, los datos no.
     */
    private static function puedeDibujarLogo(): bool
    {
        return is_file(self::LOGO)
            && (extension_loaded('gd') || extension_loaded('imagick'));
    }

    /**
     * Fila de titulos de columna. Separada porque hay que repetirla en cada
     * pagina nueva de una tabla larga.
     *
     * @param list<array{titulo:string, ancho:float, alineacion:string}> $columnas
     */
    private function encabezado(array $columnas): void
    {
        $this->SetFont('helvetica', 'B', 8.5);
        $this->SetTextColor(34, 34, 34);
        // #f7f7f8: el mismo gris de encabezado que usan las tablas del panel.
        $this->SetFillColor(247, 247, 248);
        $this->SetDrawColor(180, 180, 180);

        foreach ($columnas as $c) {
            $this->Cell($c['ancho'], 7, $c['titulo'], 'B', 0, $c['alineacion'], true);
        }
        $this->Ln();
    }
}
