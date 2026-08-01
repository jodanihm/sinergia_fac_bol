<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Dto;

use DateTimeImmutable;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Exceptions\DocumentoInvalidoException;

/**
 * DTE listo para ser emitido por un proveedor.
 *
 * DECISION DE DISEÑO - montos netos vs brutos:
 * El SII (y los proveedores como LibreDTE) aceptan que los precios unitarios
 * de cada Detalle se expresen como NETOS (sin IVA) o como BRUTOS (con IVA
 * incluido). Esta diferencia es ambigua y propensa a errores si se asume
 * implicitamente.
 *
 * Por eso este DTO obliga a declararlo de forma explicita en {@see $montosSonBrutos}:
 *   - false (default): los precios en los Detalle son NETOS. El IVA se calcula
 *     y se agrega aparte.
 *   - true:            los precios en los Detalle son BRUTOS (IVA incluido).
 *
 * La implementacion del proveedor traduce este flag al parametro correspondiente
 * (en LibreDTE: campo "Encabezado.MntBruto" del JSON de emision).
 */
final readonly class DocumentoTributario
{
    /**
     * @param list<Detalle>            $detalles
     * @param list<array<string,mixed>> $referencias Referencias libres (ej: NC/ND apuntando a un folio).
     * @param array<string,mixed>|null  $totales     Totales explicitos del documento, en formato
     *                                               SII (MntNeto, IVA, MntTotal, MntExe...). Si es null,
     *                                               el proveedor (si soporta normalizar) los calcula desde
     *                                               los Detalles. Necesario al replicar montos del original
     *                                               en una NC, donde no queremos que el proveedor recalcule.
     */
    public function __construct(
        public TipoDte $tipoDte,
        public Receptor $receptor,
        public array $detalles,
        public bool $montosSonBrutos = false,
        public ?int $folio = null,
        public ?DateTimeImmutable $fechaEmision = null,
        public array $referencias = [],
        public ?string $observaciones = null,
        public ?array $totales = null,
        // Descuento global en % sobre los items AFECTOS (DscRcgGlobal TpoMov=D).
        // null = sin descuento global. Rango 0-100.
        public ?float $descuentoGlobalPct = null,
        // IndServicio para boletas (1=servicios periodicos, 2=servicios periodicos domiciliarios,
        // 3=venta y servicios). Default 3 si null.
        public ?int $indServicio = null,
        /**
         * Forma de pago (IdDoc/FmaPago): 1 contado, 2 credito, 3 sin costo.
         *
         * null NO ES NEUTRO. El Formato DTE v2.5 (pag. 4, cambio del 31/05/2017,
         * y pag. 14, campo 13) dice que factura, factura exenta y liquidacion
         * factura "deben informar obligatoriamente" este campo, y que "en caso de
         * no existir este campo se entendera que tiene valor 2 (Credito)". O sea
         * que omitirlo es declarar credito en silencio. Se deja nullable solo
         * porque los documentos que NO son factura (boleta, NC, ND) no lo llevan
         * y porque el DTO tiene que poder representar lo que el sistema emitio
         * antes de esta entrega.
         */
        public ?int $formaPago = null,
        /**
         * Fecha de vencimiento del pago (IdDoc/FchVenc). Solo tiene sentido con
         * formaPago = 2 (credito).
         */
        public ?DateTimeImmutable $fechaVencimiento = null,
    ) {
        if ($this->detalles === []) {
            throw new DocumentoInvalidoException('DocumentoTributario: detalles no puede ser vacio');
        }
        foreach ($this->detalles as $i => $d) {
            if (! $d instanceof Detalle) {
                throw new DocumentoInvalidoException("DocumentoTributario: detalles[$i] no es Detalle");
            }
        }
        if ($this->folio !== null && $this->folio <= 0) {
            throw new DocumentoInvalidoException('DocumentoTributario: folio debe ser > 0 o null');
        }
        if ($this->descuentoGlobalPct !== null
            && ($this->descuentoGlobalPct <= 0 || $this->descuentoGlobalPct > 100)
        ) {
            throw new DocumentoInvalidoException(
                'DocumentoTributario: descuentoGlobalPct debe estar entre 0 (exclusivo) y 100'
            );
        }
        // Enumeracion cerrada del XSD del SII (DTE_v10.xsd:194-215): 1 contado,
        // 2 credito, 3 sin costo. Cualquier otro valor lo rechaza el esquema.
        if ($this->formaPago !== null && ! in_array($this->formaPago, [1, 2, 3], true)) {
            throw new DocumentoInvalidoException(
                'DocumentoTributario: formaPago debe ser 1 (contado), 2 (credito) o 3 (sin costo)'
            );
        }
        // La fecha de vencimiento solo se sostiene si hay credito. Con contado o
        // sin costo el documento no tiene nada que vencer, y el Formato DTE no
        // autoriza esa combinacion en ninguna parte.
        if ($this->fechaVencimiento !== null && $this->formaPago !== 2) {
            throw new DocumentoInvalidoException(
                'DocumentoTributario: fechaVencimiento solo aplica con formaPago = 2 (credito)'
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'tipoDte'         => $this->tipoDte->value,
            'folio'           => $this->folio,
            'fechaEmision'    => $this->fechaEmision?->format('Y-m-d'),
            'montosSonBrutos' => $this->montosSonBrutos,
            'receptor'        => $this->receptor->toArray(),
            'detalles'        => array_map(static fn (Detalle $d) => $d->toArray(), $this->detalles),
            'referencias'     => $this->referencias,
            'observaciones'   => $this->observaciones,
            'totales'         => $this->totales,
            'formaPago'       => $this->formaPago,
            'fechaVencimiento' => $this->fechaVencimiento?->format('Y-m-d'),
        ], static fn ($v) => $v !== null && $v !== '');
    }
}
