<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Dto;

use DateTimeImmutable;
use Plantiflex\FacturacionCl\Exceptions\DocumentoInvalidoException;
use Plantiflex\FacturacionCl\Sii\Rut;

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
    /** RUT de la contraparte, siempre en forma canonica (ver el constructor). */
    public string $rutContraparte;

    public function __construct(
        public int               $tpoDoc,
        public int               $nroDoc,
        public DateTimeImmutable $fecha,
        string                   $rutContraparte,
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
        if (trim($rutContraparte) === '') {
            throw new DocumentoInvalidoException('LineaLibro: rutContraparte no puede ser vacio');
        }

        // EL RUT SE NORMALIZA AQUI, Y AQUI ES EL SITIO.
        //
        // Este DTO es el ULTIMO punto por el que pasa el RUT de la contraparte antes de
        // convertirse en <RUTDoc> del XML. Normalizar en cada llamador seria
        // confiar en que ninguno se olvide -- y uno se olvido: el 02-09-2026 un
        // RUT con puntos llego al SII y el documento volvio rechazado por
        // esquema, con el folio ya gastado.
        //
        // NORMALIZA PERO NO LANZA SI EL RUT NO EXISTE. Rechazar aqui un DV malo
        // seria un FATAL con traza, no un mensaje: en el motor este constructor
        // se llama FUERA del try de emitirDte() y el archivo no tiene
        // set_exception_handler (ver el comentario de las claves conocidas en
        // public/index.php). Avisar al usuario es trabajo de validarDocumentoDte(),
        // que responde 422 con el campo exacto y sin quemar folio. Aqui solo se
        // garantiza que lo que salga hacia el SII este BIEN ESCRITO.
        $this->rutContraparte = Rut::normalizar($rutContraparte);

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
