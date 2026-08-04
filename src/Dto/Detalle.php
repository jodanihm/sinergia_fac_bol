<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Dto;

use Plantiflex\FacturacionCl\Exceptions\DocumentoInvalidoException;

/**
 * Linea de detalle de un DTE.
 *
 * IMPORTANTE: el precio unitario se interpreta como NETO o BRUTO segun
 * el flag {@see DocumentoTributario::$montosSonBrutos} del documento que
 * contiene esta linea. El Detalle por si solo no decide IVA.
 */
final readonly class Detalle
{
    public function __construct(
        public string $nombre,
        public float $cantidad,
        public float $precioUnitario,
        public bool $exento = false,
        public ?string $descripcion = null,
        public ?string $unidad = null,
        public float $descuentoPorcentaje = 0.0,
        /**
         * Codigo de impuesto adicional de ESTA linea (CodImpAdic), de la
         * enumeracion ImpAdicDTEType del SII. Para la cerveza es '26'.
         *
         * VA EN LA LINEA Y NO EN LA CABECERA, aunque el ERP de origen lo tenga
         * como un porcentaje del pedido. El Formato DTE define MontoImp como
         * "Tasa * (Suma de lineas de detalle con codigo de Impuesto adicional o
         * retencion)" (campo 117, pag. 31), o sea sobre LAS LINEAS MARCADAS y no
         * sobre el neto del documento. Mandandolo por linea se cumple esa
         * lectura literal, y deja de importar si el SII tolera o no un
         * ImptoReten suelto en los totales.
         *
         * Ademas es la unica forma de expresar un pedido mixto -- cervezas con
         * ILA y snacks sin el -- que en un porcentaje de cabecera no cabe.
         */
        public ?string $codigoImpuestoAdicional = null,
        /**
         * Tasa del impuesto adicional de esta linea, en porcentaje (20.5 para
         * la cerveza). NO se deduce del codigo: ver el docblock de
         * ImpuestoAdicional sobre por que las tasas no viven en el motor.
         */
        public ?float $tasaImpuestoAdicional = null,
    ) {
        if (trim($this->nombre) === '') {
            throw new DocumentoInvalidoException('Detalle: nombre no puede ser vacio');
        }
        if ($this->cantidad <= 0) {
            throw new DocumentoInvalidoException('Detalle: cantidad debe ser > 0');
        }
        if ($this->precioUnitario < 0) {
            throw new DocumentoInvalidoException('Detalle: precioUnitario no puede ser negativo');
        }
        if ($this->descuentoPorcentaje < 0 || $this->descuentoPorcentaje > 100) {
            throw new DocumentoInvalidoException('Detalle: descuentoPorcentaje debe estar entre 0 y 100');
        }
        // Codigo y tasa van JUNTOS: con el codigo solo no se puede calcular el
        // MontoImp, y con la tasa sola no se sabe de que impuesto es. Mismo
        // criterio que LineaLibro con codOtroImp/mntOtroImp.
        if (($this->codigoImpuestoAdicional !== null) !== ($this->tasaImpuestoAdicional !== null)) {
            throw new DocumentoInvalidoException(
                'Detalle: codigoImpuestoAdicional y tasaImpuestoAdicional deben ir juntos'
            );
        }
        if ($this->tasaImpuestoAdicional !== null
            && ($this->tasaImpuestoAdicional <= 0 || $this->tasaImpuestoAdicional > 100)) {
            // El techo de 100 es del propio XSD: TasaImp restringe PctType con
            // maxInclusive="100.00" (DTE_v10.xsd:1163-1172).
            throw new DocumentoInvalidoException(
                'Detalle: tasaImpuestoAdicional debe ser > 0 y <= 100'
            );
        }
        if ($this->codigoImpuestoAdicional !== null && $this->exento) {
            // Un item exento no tiene sobre que aplicar un impuesto adicional.
            throw new DocumentoInvalidoException(
                'Detalle: una linea exenta no puede llevar impuesto adicional'
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'nombre'              => $this->nombre,
            'descripcion'         => $this->descripcion,
            'cantidad'            => $this->cantidad,
            'precioUnitario'      => $this->precioUnitario,
            'exento'              => $this->exento,
            'unidad'              => $this->unidad,
            'descuentoPorcentaje' => $this->descuentoPorcentaje > 0 ? $this->descuentoPorcentaje : null,
            'codigoImpuestoAdicional' => $this->codigoImpuestoAdicional,
            'tasaImpuestoAdicional'   => $this->tasaImpuestoAdicional,
        ], static fn ($v) => $v !== null && $v !== '');
    }
}
