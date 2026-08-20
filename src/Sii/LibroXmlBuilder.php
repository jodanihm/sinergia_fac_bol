<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Sii;

use DOMDocument;
use DOMElement;
use Plantiflex\FacturacionCl\Dto\DatosEmisor;
use Plantiflex\FacturacionCl\Dto\Libro;
use Plantiflex\FacturacionCl\Dto\LineaLibro;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Enums\TipoLibro;
use Plantiflex\FacturacionCl\Enums\TipoOperacionLibro;
use Plantiflex\FacturacionCl\Exceptions\DocumentoInvalidoException;

/**
 * Construye el XML <LibroCompraVenta> (IECV) SIN firmar.
 *
 * Salida:
 *   <LibroCompraVenta xmlns="http://www.sii.cl/SiiDte" xmlns:xsi=... version="1.0">
 *     <EnvioLibro ID="LibroCV">
 *       <Caratula>...</Caratula>
 *       <ResumenPeriodo><TotalesPeriodo/>+ </ResumenPeriodo>
 *       <Detalle/>+
 *       <TmstFirma/>
 *     </EnvioLibro>
 *   </LibroCompraVenta>
 *
 * La firma <Signature> se agrega despues con XmlSigner::firmarNodo() sobre el
 * <EnvioLibro> (Reference URI="#LibroCV"), igual que el SetDTE del EnvioDTE.
 *
 * Se insertan saltos de linea ("\n") entre los hijos de <EnvioLibro> para que
 * ninguna linea supere el limite del SII (CHR-00002: Line too long, ~4096). Se
 * agregan ANTES de firmar, por lo que quedan dentro del contenido canonicalizado
 * (C14N) y no invalidan la firma.
 */
final class LibroXmlBuilder
{
    private const NS_SII = 'http://www.sii.cl/SiiDte';
    private const NS_XSI = 'http://www.w3.org/2001/XMLSchema-instance';

    public function build(
        Libro $libro,
        DatosEmisor $emisor,
        string $rutEnvia,
        Ambiente $ambiente
    ): DOMDocument {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        $root = $dom->createElementNS(self::NS_SII, 'LibroCompraVenta');
        $root->setAttribute('version', '1.0');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', self::NS_XSI);
        $root->setAttributeNS(self::NS_XSI, 'xsi:schemaLocation', 'http://www.sii.cl/SiiDte LibroCV_v10.xsd');
        $dom->appendChild($root);

        $envio = $dom->createElementNS(self::NS_SII, 'EnvioLibro');
        $envio->setAttribute('ID', 'LibroCV'); // ID estandar para la firma.
        $root->appendChild($envio);

        $esCompra = $libro->tipoOperacion === TipoOperacionLibro::Compra;

        $envio->appendChild($this->buildCaratula($dom, $libro, $emisor, $rutEnvia, $ambiente));
        $envio->appendChild($dom->createTextNode("\n"));
        $envio->appendChild(
            $esCompra ? $this->buildResumenCompra($dom, $libro) : $this->buildResumenVenta($dom, $libro)
        );
        $envio->appendChild($dom->createTextNode("\n"));

        foreach ($libro->lineas as $linea) {
            $envio->appendChild(
                $esCompra ? $this->buildDetalleCompra($dom, $linea) : $this->buildDetalleVenta($dom, $linea)
            );
            $envio->appendChild($dom->createTextNode("\n"));
        }

        $envio->appendChild($this->el($dom, 'TmstFirma', date('Y-m-d\TH:i:s')));

        return $dom;
    }

    private function buildCaratula(
        DOMDocument $dom,
        Libro $libro,
        DatosEmisor $emisor,
        string $rutEnvia,
        Ambiente $ambiente
    ): DOMElement {
        // En certificacion NroResol=0 (igual criterio que el EnvioDTE). La
        // evidencia esta en EnvioDteBuilder::buildCaratula().
        $nroResol = $ambiente === Ambiente::Certificacion ? 0 : $emisor->resolucionNumero;

        $car = $dom->createElementNS(self::NS_SII, 'Caratula');
        $car->appendChild($this->el($dom, 'RutEmisorLibro', $emisor->rutEmisor));
        $car->appendChild($this->el($dom, 'RutEnvia', $rutEnvia));
        $car->appendChild($this->el($dom, 'PeriodoTributario', $libro->periodoTributario));
        $car->appendChild($this->el($dom, 'FchResol', $emisor->resolucionFecha));
        $car->appendChild($this->el($dom, 'NroResol', (string) $nroResol));
        $car->appendChild($this->el($dom, 'TipoOperacion', $libro->tipoOperacion->value));
        $car->appendChild($this->el($dom, 'TipoLibro', $libro->tipoLibro->value));
        $car->appendChild($this->el($dom, 'TipoEnvio', $libro->tipoEnvio->value));
        $car->appendChild($this->el($dom, 'FolioNotificacion', (string) $libro->folioNotificacion));

        // CodAutRec: solo aplica a RECTIFICA (obligatorio ahi); en MENSUAL/ESPECIAL
        // se ignora aunque venga informado (no es un caso que deba fallar). Va AL
        // FINAL de la Caratula, despues de FolioNotificacion: es el ultimo campo
        // de la secuencia segun el schema oficial (docs/21_.../LibroCV_v10.xsd),
        // no inmediatamente despues de TipoLibro.
        if ($libro->tipoLibro === TipoLibro::Rectifica) {
            $cod = $libro->codigoAutorizacionReemplazo;
            if ($cod === null || $cod === '') {
                throw new DocumentoInvalidoException(
                    'Libro RECTIFICA requiere codigoAutorizacionReemplazo (CodAutRec).',
                );
            }
            if (strlen($cod) > 10) {
                throw new DocumentoInvalidoException(
                    'codigoAutorizacionReemplazo (CodAutRec) excede el largo maximo de 10 caracteres.',
                );
            }
            $car->appendChild($this->el($dom, 'CodAutRec', $cod));
        }

        return $car;
    }

    /**
     * MntIVA que corresponde sumar/escribir para esta linea: 0 si la linea
     * tiene IVA uso comun o IVA no recuperable (el IVA de esa linea vive
     * SOLO en esos campos especiales, nunca en MntIVA).
     *
     * Fuente (confirmada antes de codear, no supuesta):
     *   - Manual oficial del SII, docs/40_Casos_Especiales_Registro_Documentos_IECV.pdf:
     *     pagina 2 (IVA no recuperable): "El Monto IVA <MntIVA> debe dejarse en
     *     valor 0 y en la SubTabla de IVA no recuperable se debe indicar..."
     *     pagina 3 (IVA uso comun): "A nivel de detalle del libro, el Monto IVA
     *     <MntIVA> debe dejarse en valor 0 y en el campo IVA uso comun
     *     <IVAUsoComun> se debe registrar el IVA de la factura."
     *   - src/Dto/LineaLibro.php lineas 18-19 y 22 (docblock, ya documentaba esta
     *     misma regla): "Cuando la linea es de uso comun, mntIva debe ir en 0";
     *     "codIvaNoRec/mntIvaNoRec... Cuando se usa, mntIva debe ir en 0."
     *
     * El motor lo GARANTIZA aqui (en vez de confiar en que el payload del
     * cliente ya haya puesto mntIva=0): si un tenant arma mal el payload, el
     * XML que sale hacia el SII queda igual correcto. mntNeto/mntTotal NO se
     * tocan: mntTotal ya incluye ese IVA (solo cambia de "bolsillo": pasa de
     * MntIVA a IVAUsoComun/MntIVANoRec), asi que el cuadre Neto+IVA+especiales
     * = Total se mantiene.
     *
     * Solo aplica a COMPRA: ivaUsoComun/codIvaNoRec son campos "solo COMPRA"
     * (ver LineaLibro.php linea 16 y 21); una linea de VENTA nunca los trae,
     * asi que para venta esta funcion siempre devuelve $l->mntIva sin cambios.
     */
    private function mntIvaEfectivo(LineaLibro $l): int
    {
        return ($l->ivaUsoComun !== null || $l->codIvaNoRec !== null) ? 0 : $l->mntIva;
    }

    /**
     * MntTotal que corresponde escribir para esta linea: cuando codOtroImp=15
     * (retencion total del IVA, factura de compra 46), el total se recalcula
     * como neto + exento + IVA efectivo - el monto retenido, IGNORANDO lo que
     * traiga $l->mntTotal en el payload (mismo espiritu que mntIvaEfectivo():
     * el motor lo GARANTIZA en vez de confiar en que el cliente ya lo cuadro).
     * Para cualquier otro caso, $l->mntTotal se usa tal cual (comportamiento
     * actual, sin cambios).
     */
    private function mntTotalEfectivo(LineaLibro $l): int
    {
        if ($l->codOtroImp !== 15) {
            return $l->mntTotal;
        }
        return $l->mntNeto + $l->mntExe + $this->mntIvaEfectivo($l) - ($l->mntOtroImp ?? 0);
    }

    /**
     * @return array<int, array{TotDoc:int, exe:int, neto:int, iva:int, total:int,
     *         ivaUsoComun:int, ivaNoRec:array<int,array{op:int,mnt:int}>,
     *         otrosImp:array<int,int>}>
     *         Agrupado por TpoDoc en orden de primera aparicion.
     */
    private function agruparPorTpoDoc(Libro $libro): array
    {
        $grupos = [];
        foreach ($libro->lineas as $l) {
            if (! isset($grupos[$l->tpoDoc])) {
                $grupos[$l->tpoDoc] = [
                    'TotDoc' => 0, 'exe' => 0, 'neto' => 0, 'iva' => 0, 'total' => 0,
                    'ivaUsoComun' => 0, 'ivaNoRec' => [], 'otrosImp' => [],
                ];
            }
            $grupos[$l->tpoDoc]['TotDoc']++;
            $grupos[$l->tpoDoc]['exe']         += $l->mntExe;
            $grupos[$l->tpoDoc]['neto']        += $l->mntNeto;
            $grupos[$l->tpoDoc]['iva']         += $this->mntIvaEfectivo($l);
            $grupos[$l->tpoDoc]['total']       += $this->mntTotalEfectivo($l);
            $grupos[$l->tpoDoc]['ivaUsoComun'] += $l->ivaUsoComun ?? 0;

            // IVA no recuperable (p.ej. entrega gratuita, CodIVANoRec=4).
            if ($l->codIvaNoRec !== null) {
                $cod = $l->codIvaNoRec;
                if (! isset($grupos[$l->tpoDoc]['ivaNoRec'][$cod])) {
                    $grupos[$l->tpoDoc]['ivaNoRec'][$cod] = ['op' => 0, 'mnt' => 0];
                }
                $grupos[$l->tpoDoc]['ivaNoRec'][$cod]['op']++;
                $grupos[$l->tpoDoc]['ivaNoRec'][$cod]['mnt'] += $l->mntIvaNoRec ?? 0;
            }

            // OtrosImp (p.ej. factura de compra 46 con retencion total, CodImp=15).
            if ($l->codOtroImp !== null) {
                $cod = $l->codOtroImp;
                if (! isset($grupos[$l->tpoDoc]['otrosImp'][$cod])) {
                    $grupos[$l->tpoDoc]['otrosImp'][$cod] = 0;
                }
                $grupos[$l->tpoDoc]['otrosImp'][$cod] += $l->mntOtroImp ?? 0;
            }
        }
        return $grupos;
    }

    /**
     * ResumenPeriodo de VENTA (manual IECV 2.3). Orden:
     * TpoDoc, TotDoc, TotMntExe, TotMntNeto, TotMntIVA, TotMntTotal.
     * (TotAnulado y demas condicionales se omiten: no manejamos anulados aqui.)
     */
    private function buildResumenVenta(DOMDocument $dom, Libro $libro): DOMElement
    {
        $resumen = $dom->createElementNS(self::NS_SII, 'ResumenPeriodo');
        foreach ($this->agruparPorTpoDoc($libro) as $tpoDoc => $g) {
            $tp = $dom->createElementNS(self::NS_SII, 'TotalesPeriodo');
            $tp->appendChild($this->el($dom, 'TpoDoc', (string) $tpoDoc));
            $tp->appendChild($this->el($dom, 'TotDoc', (string) $g['TotDoc']));
            $tp->appendChild($this->el($dom, 'TotMntExe', (string) $g['exe']));
            $tp->appendChild($this->el($dom, 'TotMntNeto', (string) $g['neto']));
            $tp->appendChild($this->el($dom, 'TotMntIVA', (string) $g['iva']));
            $tp->appendChild($this->el($dom, 'TotMntTotal', (string) $g['total']));
            $resumen->appendChild($tp);
        }
        return $resumen;
    }

    /**
     * ResumenPeriodo de COMPRA (manual IECV 3.3 / LibroCV_v10.xsd). Orden:
     * TpoDoc, TpoImp=1, TotDoc, TotMntExe, TotMntNeto, TotMntIVA,
     * [TotIVANoRec]*, [TotIVAUsoComun, FctProp, TotCredIVAUsoComun],
     * [TotOtrosImp]*, TotMntTotal.
     *
     * El FctProp es UNO para todo el libro (Libro::$factorProporcionalidad).
     * TotCredIVAUsoComun = round(FctProp * TotIVAUsoComun).
     */
    private function buildResumenCompra(DOMDocument $dom, Libro $libro): DOMElement
    {
        $resumen = $dom->createElementNS(self::NS_SII, 'ResumenPeriodo');
        $fctProp = $libro->factorProporcionalidad;

        foreach ($this->agruparPorTpoDoc($libro) as $tpoDoc => $g) {
            $tp = $dom->createElementNS(self::NS_SII, 'TotalesPeriodo');
            $tp->appendChild($this->el($dom, 'TpoDoc', (string) $tpoDoc));
            // TpoImp=1 (IVA).
            $tp->appendChild($this->el($dom, 'TpoImp', '1'));
            $tp->appendChild($this->el($dom, 'TotDoc', (string) $g['TotDoc']));
            $tp->appendChild($this->el($dom, 'TotMntExe', (string) $g['exe']));
            $tp->appendChild($this->el($dom, 'TotMntNeto', (string) $g['neto']));
            $tp->appendChild($this->el($dom, 'TotMntIVA', (string) $g['iva']));

            // IVA no recuperable, un bloque por CodIVANoRec.
            foreach ($g['ivaNoRec'] as $cod => $ivn) {
                $b = $dom->createElementNS(self::NS_SII, 'TotIVANoRec');
                $b->appendChild($this->el($dom, 'CodIVANoRec', (string) $cod));
                $b->appendChild($this->el($dom, 'TotOpIVANoRec', (string) $ivn['op']));
                $b->appendChild($this->el($dom, 'TotMntIVANoRec', (string) $ivn['mnt']));
                $tp->appendChild($b);
            }

            if ($g['ivaUsoComun'] > 0) {
                $tp->appendChild($this->el($dom, 'TotIVAUsoComun', (string) $g['ivaUsoComun']));
                if ($fctProp !== null) {
                    $tp->appendChild($this->el($dom, 'FctProp', $this->num($fctProp)));
                    $totCred = (int) round($fctProp * $g['ivaUsoComun']);
                    $tp->appendChild($this->el($dom, 'TotCredIVAUsoComun', (string) $totCred));
                }
            }

            // OtrosImp, un bloque por CodImp (p.ej. 15 retencion total).
            foreach ($g['otrosImp'] as $cod => $mnt) {
                $b = $dom->createElementNS(self::NS_SII, 'TotOtrosImp');
                $b->appendChild($this->el($dom, 'CodImp', (string) $cod));
                $b->appendChild($this->el($dom, 'TotMntImp', (string) $mnt));
                $tp->appendChild($b);
            }

            $tp->appendChild($this->el($dom, 'TotMntTotal', (string) $g['total']));
            $resumen->appendChild($tp);
        }
        return $resumen;
    }

    /**
     * Detalle de VENTA (manual IECV 2.4). Orden:
     * TpoDoc, NroDoc, TasaImp, FchDoc, RUTDoc, RznSoc, MntExe, MntNeto, MntIVA, MntTotal.
     */
    private function buildDetalleVenta(DOMDocument $dom, LineaLibro $l): DOMElement
    {
        $det = $dom->createElementNS(self::NS_SII, 'Detalle');
        $det->appendChild($this->el($dom, 'TpoDoc', (string) $l->tpoDoc));
        $det->appendChild($this->el($dom, 'NroDoc', (string) $l->nroDoc));
        $det->appendChild($this->el($dom, 'TasaImp', (string) $l->tasaImp));
        $det->appendChild($this->el($dom, 'FchDoc', $l->fecha->format('Y-m-d')));
        $det->appendChild($this->el($dom, 'RUTDoc', $l->rutContraparte));
        $det->appendChild($this->el($dom, 'RznSoc', $l->razonSocial));
        $det->appendChild($this->el($dom, 'MntExe', (string) $l->mntExe));
        $det->appendChild($this->el($dom, 'MntNeto', (string) $l->mntNeto));
        $det->appendChild($this->el($dom, 'MntIVA', (string) $l->mntIva));
        $det->appendChild($this->el($dom, 'MntTotal', (string) $l->mntTotal));
        return $det;
    }

    /**
     * Detalle de COMPRA (manual IECV 3.4 / LibroCV_v10.xsd). Orden:
     * TpoDoc, NroDoc, TpoImp=1, TasaImp, FchDoc, RUTDoc, RznSoc, MntExe, MntNeto,
     * MntIVA, [IVANoRec], [IVAUsoComun], [OtrosImp], MntTotal.
     *
     * FctProp NO va aqui: va en el ResumenPeriodo (es del libro completo).
     */
    private function buildDetalleCompra(DOMDocument $dom, LineaLibro $l): DOMElement
    {
        $det = $dom->createElementNS(self::NS_SII, 'Detalle');
        $det->appendChild($this->el($dom, 'TpoDoc', (string) $l->tpoDoc));
        $det->appendChild($this->el($dom, 'NroDoc', (string) $l->nroDoc));
        $det->appendChild($this->el($dom, 'TpoImp', '1')); // IVA.
        $det->appendChild($this->el($dom, 'TasaImp', (string) $l->tasaImp));
        $det->appendChild($this->el($dom, 'FchDoc', $l->fecha->format('Y-m-d')));
        $det->appendChild($this->el($dom, 'RUTDoc', $l->rutContraparte));
        $det->appendChild($this->el($dom, 'RznSoc', $l->razonSocial));
        $det->appendChild($this->el($dom, 'MntExe', (string) $l->mntExe));
        $det->appendChild($this->el($dom, 'MntNeto', (string) $l->mntNeto));
        $det->appendChild($this->el($dom, 'MntIVA', (string) $this->mntIvaEfectivo($l)));

        if ($l->codIvaNoRec !== null) {
            $b = $dom->createElementNS(self::NS_SII, 'IVANoRec');
            $b->appendChild($this->el($dom, 'CodIVANoRec', (string) $l->codIvaNoRec));
            $b->appendChild($this->el($dom, 'MntIVANoRec', (string) ($l->mntIvaNoRec ?? 0)));
            $det->appendChild($b);
        }

        if ($l->ivaUsoComun !== null) {
            $det->appendChild($this->el($dom, 'IVAUsoComun', (string) $l->ivaUsoComun));
        }

        if ($l->codOtroImp !== null) {
            $b = $dom->createElementNS(self::NS_SII, 'OtrosImp');
            $b->appendChild($this->el($dom, 'CodImp', (string) $l->codOtroImp));
            $b->appendChild($this->el($dom, 'TasaImp', (string) $l->tasaOtroImp));
            $b->appendChild($this->el($dom, 'MntImp', (string) ($l->mntOtroImp ?? 0)));
            $det->appendChild($b);
        }

        $det->appendChild($this->el($dom, 'MntTotal', (string) $this->mntTotalEfectivo($l)));
        return $det;
    }

    private function el(DOMDocument $dom, string $name, string $value): DOMElement
    {
        $el = $dom->createElementNS(self::NS_SII, $name);
        $el->appendChild($dom->createTextNode($value));
        return $el;
    }

    private function num(float $n): string
    {
        if (floor($n) === $n) {
            return (string) (int) $n;
        }
        return rtrim(rtrim(sprintf('%.4f', $n), '0'), '.');
    }
}
