<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Dto;

use DateTimeImmutable;
use Plantiflex\FacturacionCl\Exceptions\DocumentoInvalidoException;

/**
 * Una linea (un documento) del Libro Electronico de Compra/Venta (IECV).
 *
 * rutContraparte: en libro de VENTA es el RUT del receptor; en libro de COMPRA
 * es el RUT del emisor del documento recibido.
 *
 * ivaUsoComun (solo COMPRA): IVA de uso comun de esta linea, va en el <Detalle>.
 * El factor de proporcionalidad NO va aqui: es UNO para todo el libro y vive en
 * {@see Libro::$factorProporcionalidad} (ResumenPeriodo). Cuando la linea es de
 * uso comun, mntIva debe ir en 0 (el IVA va en ivaUsoComun).
 *
 * codIvaNoRec/mntIvaNoRec (solo COMPRA): IVA no recuperable (p.ej. entrega
 * gratuita del proveedor, CodIVANoRec=4). Cuando se usa, mntIva debe ir en 0.
 *
 * codOtroImp/mntOtroImp (solo COMPRA): bloque OtrosImp del detalle. Para factura
 * de compra (46) con retencion total del IVA se usa CodImp=15, mntOtroImp = IVA
 * retenido; en ese caso mntIva lleva ese mismo IVA y el MntTotal lo descuenta.
 */
final readonly class LineaLibro
{
    public function __construct(
        public int               $tpoDoc,
        public int               $nroDoc,
        public DateTimeImmutable $fecha,
        public string            $rutContraparte,
        public string            $razonSocial,
        public int               $mntExe,
        public int               $mntNeto,
        public int               $mntIva,
        public int               $mntTotal,
        public int               $tasaImp = 19,
        public ?int              $ivaUsoComun = null,
        public ?int              $codIvaNoRec = null,
        public ?int              $mntIvaNoRec = null,
        public ?int              $codOtroImp = null,
        public ?int              $mntOtroImp = null,
        public int               $tasaOtroImp = 19,
    ) {
        if ($this->tpoDoc <= 0) {
            throw new DocumentoInvalidoException('LineaLibro: tpoDoc debe ser > 0');
        }
        if ($this->nroDoc <= 0) {
            throw new DocumentoInvalidoException('LineaLibro: nroDoc debe ser > 0');
        }
        if (trim($this->rutContraparte) === '') {
            throw new DocumentoInvalidoException('LineaLibro: rutContraparte no puede ser vacio');
        }
        if ($this->mntTotal < 0) {
            throw new DocumentoInvalidoException('LineaLibro: mntTotal no puede ser negativo');
        }
        if (($this->codIvaNoRec !== null) !== ($this->mntIvaNoRec !== null)) {
            throw new DocumentoInvalidoException('LineaLibro: codIvaNoRec y mntIvaNoRec deben ir juntos');
        }
        if (($this->codOtroImp !== null) !== ($this->mntOtroImp !== null)) {
            throw new DocumentoInvalidoException('LineaLibro: codOtroImp y mntOtroImp deben ir juntos');
        }
    }
}
