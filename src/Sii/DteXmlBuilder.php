<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Sii;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use Plantiflex\FacturacionCl\Dto\DatosEmisor;
use Plantiflex\FacturacionCl\Dto\Detalle;
use Plantiflex\FacturacionCl\Dto\DocumentoTributario;
use Plantiflex\FacturacionCl\Exceptions\DocumentoInvalidoException;

/**
 * Construye el XML de un DTE INDIVIDUAL (sin firmar) segun el esquema SII.
 *
 * La firma se agrega despues con XmlSigner::firmarNodo() sobre el <Documento>.
 * Los campos especificos de boleta (39/41) van condicionados a esBoleta():
 *   - IdDoc: IndServicio obligatorio; sin MntBruto (IndMntNeto=2 si va neto).
 *   - Emisor: RznSocEmisor/GiroEmisor, SIN Acteco.
 *   - Receptor: SIN GiroRecep ni CorreoRecep.
 *   - Totales: SIN TasaIVA.
 *   - Detalle: SIN DescuentoPct/DescuentoMonto (el descuento queda en MontoItem).
 */
final class DteXmlBuilder
{
    private const NS_SII   = 'http://www.sii.cl/SiiDte';
    private const TASA_IVA = 19;

    public function build(DocumentoTributario $doc, DatosEmisor $emisor, int $folio): DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        $dte = $dom->createElementNS(self::NS_SII, 'DTE');
        $dte->setAttribute('version', '1.0');
        $dom->appendChild($dte);

        $documento = $dom->createElementNS(self::NS_SII, 'Documento');
        $documento->setAttribute('ID', sprintf('F%dT%d', $folio, $doc->tipoDte->value));
        $dte->appendChild($documento);

        $documento->appendChild($this->buildEncabezado($dom, $doc, $emisor, $folio));

        $esBoleta = $doc->tipoDte->esBoleta();
        foreach ($doc->detalles as $i => $detalle) {
            $documento->appendChild($this->buildDetalle($dom, $detalle, $i + 1, $esBoleta));
        }

        if ($doc->descuentoGlobalPct !== null && $doc->descuentoGlobalPct > 0) {
            $documento->appendChild($this->buildDscRcgGlobal($dom, $doc->descuentoGlobalPct));
        }

        foreach ($doc->referencias as $i => $ref) {
            $documento->appendChild($this->buildReferencia($dom, $ref, $i + 1, $folio));
        }

        return $dom;
    }

    private function buildEncabezado(DOMDocument $dom, DocumentoTributario $doc, DatosEmisor $emisor, int $folio): DOMElement
    {
        $enc = $dom->createElementNS(self::NS_SII, 'Encabezado');

        // IdDoc
        $idDoc = $dom->createElementNS(self::NS_SII, 'IdDoc');
        $idDoc->appendChild($this->el($dom, 'TipoDTE', (string) $doc->tipoDte->value));
        $idDoc->appendChild($this->el($dom, 'Folio', (string) $folio));
        $idDoc->appendChild($this->el(
            $dom,
            'FchEmis',
            ($doc->fechaEmision ?? new DateTimeImmutable())->format('Y-m-d'),
        ));
        // Boleta (39/41): IndServicio OBLIGATORIO (3 = ventas y servicios). La boleta
        // NO tiene MntBruto: por defecto el detalle va BRUTO; si va NETO -> IndMntNeto=2.
        // Factura SI usa MntBruto.
        if ($doc->tipoDte->esBoleta()) {
            $idDoc->appendChild($this->el($dom, 'IndServicio', (string) ($doc->indServicio ?? 3)));
            if (! $doc->montosSonBrutos) {
                $idDoc->appendChild($this->el($dom, 'IndMntNeto', '2'));
            }
        } elseif ($doc->montosSonBrutos) {
            $idDoc->appendChild($this->el($dom, 'MntBruto', '1'));
        }

        // FORMA DE PAGO Y VENCIMIENTO. El ORDEN IMPORTA: el XSD del SII
        // (docs/18_Schema_XML_DTE/DTE_v10.xsd) declara IdDoc como una SECUENCIA,
        // asi que los elementos tienen posicion fija. FmaPago va inmediatamente
        // despues de MntBruto, y FchVenc mucho mas atras, despues de los
        // TermPago* -- que este builder no emite, asi que quedan contiguos.
        //
        // SOLO PARA NO-BOLETA: la boleta tiene su propio esquema, con otra
        // secuencia en IdDoc, y ademas se emite por un camino distinto
        // (BoletaFacturador) que nunca fija estos campos. Emitirlos ahi seria
        // meterlos en un orden que no esta verificado contra ese esquema.
        //
        // NO INFORMAR FmaPago NO ES NEUTRO: el Formato DTE v2.5 (pag. 14, campo
        // 13) dice que si el campo no viene "se entendera que tiene valor 2
        // (Credito)". Los documentos emitidos antes de esta entrega quedaron
        // declarados como credito ante el SII sin que nadie lo eligiera.
        if (! $doc->tipoDte->esBoleta()) {
            if ($doc->formaPago !== null) {
                $idDoc->appendChild($this->el($dom, 'FmaPago', (string) $doc->formaPago));
            }
            if ($doc->fechaVencimiento !== null) {
                $idDoc->appendChild($this->el($dom, 'FchVenc', $doc->fechaVencimiento->format('Y-m-d')));
            }
        }
        $enc->appendChild($idDoc);

        // Emisor (nombres de campos distintos entre boleta y factura).
        $em = $dom->createElementNS(self::NS_SII, 'Emisor');
        $em->appendChild($this->el($dom, 'RUTEmisor', $emisor->rutEmisor));
        if ($doc->tipoDte->esBoleta()) {
            $em->appendChild($this->el($dom, 'RznSocEmisor', $emisor->razonSocial));
            $em->appendChild($this->el($dom, 'GiroEmisor', $emisor->giro));
            // Boleta NO lleva Acteco en el Emisor.
        } else {
            $em->appendChild($this->el($dom, 'RznSoc', $emisor->razonSocial));
            $em->appendChild($this->el($dom, 'GiroEmis', $emisor->giro));
            $em->appendChild($this->el($dom, 'Acteco', (string) $emisor->acteco));
        }
        $em->appendChild($this->el($dom, 'DirOrigen', $emisor->dirOrigen));
        $em->appendChild($this->el($dom, 'CmnaOrigen', $emisor->cmnaOrigen));
        $enc->appendChild($em);

        // Receptor
        $re = $dom->createElementNS(self::NS_SII, 'Receptor');
        $re->appendChild($this->el($dom, 'RUTRecep', $doc->receptor->rut));
        $re->appendChild($this->el($dom, 'RznSocRecep', $doc->receptor->razonSocial));
        // Boleta NO lleva GiroRecep ni CorreoRecep.
        if (! $doc->tipoDte->esBoleta()) {
            $this->elOpcional($dom, $re, 'GiroRecep', $doc->receptor->giro);
        }
        // CorreoRecep VA AQUI, ANTES DE DirRecep. El XSD del SII declara Receptor
        // como una SECUENCIA, o sea con posiciones fijas
        // (docs/18_Schema_XML_DTE/DTE_v10.xsd:543-670):
        //
        //   RUTRecep, CdgIntRecep?, RznSocRecep, Extranjero?, GiroRecep?,
        //   Contacto?, CorreoRecep?, DirRecep?, CmnaRecep?, CiudadRecep?, ...
        //
        // Hasta ahora este builder lo emitia DESPUES de DirRecep/CmnaRecep, que es
        // invalido. Nunca se noto porque el email JAMAS llega: los tres sitios que
        // construyen el Receptor en public/index.php omiten el parametro, asi que
        // elOpcional() no agrega el nodo y los 84 documentos emitidos no lo llevan.
        // Era una mina esperando al primero que pasara un correo.
        //
        // Medido con DOMDocument::schemaValidate() contra EnvioDTE_v10.xsd: con el
        // orden viejo y un email, el validador respondia "Element CorreoRecep: This
        // element is not expected. Expected is one of (CiudadRecep, DirPostal,
        // CmnaPostal, CiudadPostal)".
        if (! $doc->tipoDte->esBoleta()) {
            $this->elOpcional($dom, $re, 'CorreoRecep', $doc->receptor->email);
        }
        $this->elOpcional($dom, $re, 'DirRecep', $doc->receptor->direccion);
        $this->elOpcional($dom, $re, 'CmnaRecep', $doc->receptor->comuna);
        $enc->appendChild($re);

        // Totales
        $enc->appendChild($this->buildTotales($dom, $doc));

        return $enc;
    }

    private function buildTotales(DOMDocument $dom, DocumentoTributario $doc): DOMElement
    {
        $totales = $this->resolverTotales($doc);
        $tot = $dom->createElementNS(self::NS_SII, 'Totales');

        // ORDEN SEGUN EL XSD, QUE ES UNA SECUENCIA CON POSICIONES FIJAS.
        // Totales de DTE_v10.xsd:1106 (DTE > Documento > Encabezado), que NO es
        // el mismo que el de Liquidacion (:2823) ni el de Exportaciones (:4481):
        // esos declaran otra secuencia y otro tipo para TasaImp.
        //
        // Los 18 hijos, en orden: MntNeto, MntExe, MntBase, MntMargenCom,
        // TasaIVA, IVA, IVAProp, IVATerc, ImptoReten*, IVANoRet, CredEC,
        // GrntDep, Comisiones, MntTotal, MontoNF, MontoPeriodo, SaldoAnterior,
        // VlrPagar. De esos, MntTotal es el UNICO obligatorio.
        //
        // ImptoReten va en la posicion 9: DESPUES de IVA y ANTES de MntTotal.
        // La boleta NO lleva TasaIVA.
        $claves = $doc->tipoDte->esBoleta()
            ? ['MntNeto', 'MntExe', 'IVA']
            : ['MntNeto', 'MntExe', 'TasaIVA', 'IVA'];
        foreach ($claves as $clave) {
            if (isset($totales[$clave])) {
                $tot->appendChild($this->el($dom, $clave, (string) $totales[$clave]));
            }
        }

        // Un bloque ImptoReten por CODIGO, no por linea: el XSD admite hasta 20
        // repeticiones (maxOccurs="20") y el Formato DTE habla de "20
        // repeticiones de pares codigo - valor" (campo 115). Cada bloque lleva
        // su propia secuencia interna: TipoImp, TasaImp?, MontoImp.
        foreach ($totales['ImptoReten'] ?? [] as $imp) {
            $b = $dom->createElementNS(self::NS_SII, 'ImptoReten');
            $b->appendChild($this->el($dom, 'TipoImp', $imp['TipoImp']));
            $b->appendChild($this->el($dom, 'TasaImp', $this->num($imp['TasaImp'])));
            $b->appendChild($this->el($dom, 'MontoImp', (string) $imp['MontoImp']));
            $tot->appendChild($b);
        }

        $tot->appendChild($this->el($dom, 'MntTotal', (string) $totales['MntTotal']));

        return $tot;
    }

    /**
     * @return array<string,int>
     */
    private function resolverTotales(DocumentoTributario $doc): array
    {
        // Si vienen totales explicitos (ej: NC que replica el original), confiar
        // en ellos y solo proyectar las claves conocidas en el orden correcto.
        //
        // LA LISTA BLANCA ERA UNA TRAMPA. Antes esto proyectaba cinco claves y
        // punto: cualquier otra cosa que trajera $doc->totales se descartaba EN
        // SILENCIO. Con la llegada de ImptoReten eso significaba emitir una nota
        // de credito que anula una factura con ILA... sin el ILA, y con un
        // MntTotal copiado que ya no cuadraria con sus propios componentes. El
        // SII lo rechazaria y el motivo no estaria en ninguna parte del codigo.
        //
        // Ahora la lista sigue siendo cerrada -- proyectar a ciegas dejaria
        // pasar claves inventadas al XML -- pero lo que no reconoce REVIENTA en
        // vez de desaparecer. Agregar un total nuevo obliga a pasar por aqui.
        if ($doc->totales !== null && $doc->totales !== []) {
            $conocidas = ['MntNeto', 'MntExe', 'TasaIVA', 'IVA', 'MntTotal', 'ImptoReten'];
            $desconocidas = array_diff(array_keys($doc->totales), $conocidas);
            if ($desconocidas !== []) {
                throw new DocumentoInvalidoException(
                    'Totales explicitos con claves que este builder no sabe emitir: '
                    . implode(', ', $desconocidas)
                    . '. Agregarlas a buildTotales() en su posicion del XSD antes de usarlas.'
                );
            }

            $out = [];
            foreach (['MntNeto', 'MntExe', 'TasaIVA', 'IVA', 'MntTotal'] as $clave) {
                if (isset($doc->totales[$clave])) {
                    $out[$clave] = (int) $doc->totales[$clave];
                }
            }
            if (isset($doc->totales['ImptoReten']) && is_array($doc->totales['ImptoReten'])) {
                $out['ImptoReten'] = $doc->totales['ImptoReten'];
            }

            return $out;
        }

        $afecto = 0;
        $exento = 0;
        foreach ($doc->detalles as $d) {
            // MontoItem ya incluye el descuento por linea.
            $montoItem = $this->montoItem($d);
            if ($d->exento) {
                $exento += $montoItem;
            } else {
                $afecto += $montoItem;
            }
        }

        $totales = [];
        if ($afecto > 0) {
            if ($doc->montosSonBrutos) {
                // El afecto viene CON IVA incluido: separar neto e IVA.
                $neto = (int) round($afecto / (1 + self::TASA_IVA / 100));
            } else {
                $neto = $afecto;
            }

            // Descuento global sobre el AFECTO (itemes afectos). Sin IndExeDR
            // en el DscRcgGlobal, por lo que aplica al neto afecto.
            $tieneDescuentoGlobal = $doc->descuentoGlobalPct !== null && $doc->descuentoGlobalPct > 0;
            if ($tieneDescuentoGlobal) {
                $descGlobal = (int) round($neto * $doc->descuentoGlobalPct / 100);
                $neto -= $descGlobal;
            }

            if ($doc->montosSonBrutos && ! $tieneDescuentoGlobal) {
                // IVA por diferencia: garantiza MntNeto + IVA == afecto bruto
                // original exacto, sin importar el redondeo de la division
                // neto = afecto/1.19 (bug real: boleta $85.000 bruto daba
                // Neto 71.429 + IVA round(71429*19%)=13.572 = 85.001, no 85.000).
                $iva = $afecto - $neto;
            } else {
                // TODO: cuando montosSonBrutos=true CON descuentoGlobalPct>0,
                // este calculo (round) no garantiza que MntTotal cuadre exacto
                // contra el bruto original menos el descuento -- el descuento
                // ya introduce una diferencia legitima; queda pendiente de
                // revision aparte, no es el bug de hoy.
                $iva = (int) round($neto * self::TASA_IVA / 100);
            }

            $totales['MntNeto'] = $neto;
            $totales['TasaIVA'] = self::TASA_IVA;
            $totales['IVA']     = $iva;
        }
        if ($exento > 0) {
            $totales['MntExe'] = $exento;
        }

        // --- IMPUESTOS ADICIONALES ---
        //
        // Se agrupa por CODIGO y se suma la BASE de las lineas que lo llevan,
        // porque asi lo define el Formato DTE para MontoImp (campo 117, pag.
        // 31): "Tasa * (Suma de lineas de detalle con codigo de Impuesto
        // adicional o retencion)". No es la tasa sobre el neto del documento.
        //
        // La base de cada linea es su montoItem NETO. Si los montos vienen
        // brutos, hay que quitarles el IVA antes de aplicar la tasa: en otro
        // caso el impuesto adicional se calcularia sobre una base que ya
        // incluye IVA, y el resultado no cuadraria con lo que el SII recalcula.
        //
        // EL DESCUENTO GLOBAL NO SE PRORRATEA sobre estas bases, y se dice
        // aqui en vez de dejarlo implicito: el descuento global se aplica al
        // MntNeto agregado y no hay forma no ambigua de repartirlo entre las
        // lineas de cada codigo. Un documento con descuento global Y impuesto
        // adicional informaria un MontoImp calculado sobre la base sin
        // descontar. No se bloquea porque no sabemos que espera el SII ahi, y
        // no se inventa un prorrateo que ninguna fuente respalda.
        $basePorCodigo = [];
        $tasaPorCodigo = [];
        foreach ($doc->detalles as $d) {
            if ($d->codigoImpuestoAdicional === null) {
                continue;
            }
            $cod  = $d->codigoImpuestoAdicional;
            $base = $this->montoItem($d);
            if ($doc->montosSonBrutos) {
                $base = (int) round($base / (1 + self::TASA_IVA / 100));
            }
            $basePorCodigo[$cod] = ($basePorCodigo[$cod] ?? 0) + $base;
            $tasaPorCodigo[$cod] = $d->tasaImpuestoAdicional;
        }

        $totalImpuestos = 0;
        if ($basePorCodigo !== []) {
            $bloques = [];
            foreach ($basePorCodigo as $cod => $base) {
                $monto = (int) round($base * $tasaPorCodigo[$cod] / 100);
                $bloques[] = [
                    'TipoImp'  => (string) $cod,
                    'TasaImp'  => $tasaPorCodigo[$cod],
                    'MontoImp' => $monto,
                ];
                $totalImpuestos += $monto;
            }
            $totales['ImptoReten'] = $bloques;
        }

        // MntTotal = neto + IVA + exento + IMPUESTOS ADICIONALES. El Formato DTE
        // (campo 124, pag. 31) lo define como "Monto neto + Monto no afecto o
        // exento + IVA + Impuestos Adicionales + ...". Los sumandos que faltan
        // (impuestos especificos, margen de comercializacion, garantia de
        // envases, credito constructoras) corresponden a campos que este builder
        // no emite: cuando se emitan, se suman aqui.
        $afectoConIva = isset($totales['MntNeto']) ? $totales['MntNeto'] + $totales['IVA'] : 0;
        $totales['MntTotal'] = $afectoConIva + $exento + $totalImpuestos;

        return $totales;
    }

    private function buildDetalle(DOMDocument $dom, Detalle $d, int $linea, bool $esBoleta = false): DOMElement
    {
        $det = $dom->createElementNS(self::NS_SII, 'Detalle');
        $det->appendChild($this->el($dom, 'NroLinDet', (string) $linea));
        // Linea sin montos (glosa pura): NC/ND de anulacion o correccion con total 0.
        // Solo NmbItem + MontoItem 0, sin Qty/Prc/Descuento, para que el SII no
        // cuadre la linea. MontoItem es el unico campo de monto obligatorio.
        if ($d->precioUnitario == 0.0 && $d->descuentoPorcentaje <= 0) {
            $det->appendChild($this->el($dom, 'NmbItem', $d->nombre));
            $det->appendChild($this->el($dom, 'MontoItem', '0'));
            return $det;
        }
        // IndExe va inmediatamente despues de NroLinDet segun esquema SII.
        if ($d->exento) {
            $det->appendChild($this->el($dom, 'IndExe', '1'));
        }
        $det->appendChild($this->el($dom, 'NmbItem', $d->nombre));
        $this->elOpcional($dom, $det, 'DscItem', $d->descripcion);
        $det->appendChild($this->el($dom, 'QtyItem', $this->num($d->cantidad)));
        $this->elOpcional($dom, $det, 'UnmdItem', $d->unidad);
        $det->appendChild($this->el($dom, 'PrcItem', $this->num($d->precioUnitario)));
        // DescuentoPct/DescuentoMonto van despues de PrcItem y antes de MontoItem.
        // Factura: el validador SII espera el monto del descuento para cuadrar
        // MontoItem con QtyItem * PrcItem. Boleta no lleva estos campos.
        if (! $esBoleta && $d->descuentoPorcentaje > 0) {
            $det->appendChild($this->el($dom, 'DescuentoPct', $this->num($d->descuentoPorcentaje)));
            $det->appendChild($this->el($dom, 'DescuentoMonto', (string) $this->descuentoMonto($d)));
        }
        // CodImpAdic va INMEDIATAMENTE ANTES de MontoItem: es su posicion en la
        // secuencia de Detalle del XSD (DTE_v10.xsd:1649, justo antes de
        // MontoItem en :1653). Admite hasta 2 codigos por linea; aqui se emite
        // uno solo porque el DTO transporta uno.
        if ($d->codigoImpuestoAdicional !== null) {
            $det->appendChild($this->el($dom, 'CodImpAdic', $d->codigoImpuestoAdicional));
        }
        // MontoItem = monto de la linea YA con descuento aplicado.
        $det->appendChild($this->el($dom, 'MontoItem', (string) $this->montoItem($d)));
        return $det;
    }

    /**
     * Monto de una linea, con el descuento por linea ya aplicado y redondeado.
     */
    private function montoItem(Detalle $d): int
    {
        $monto = $d->cantidad * $d->precioUnitario;
        if ($d->descuentoPorcentaje > 0) {
            $monto *= (1 - $d->descuentoPorcentaje / 100);
        }
        return (int) round($monto);
    }

    private function descuentoMonto(Detalle $d): int
    {
        if ($d->descuentoPorcentaje <= 0) {
            return 0;
        }
        return (int) round($d->cantidad * $d->precioUnitario * $d->descuentoPorcentaje / 100);
    }

    private function buildDscRcgGlobal(DOMDocument $dom, float $pct): DOMElement
    {
        $dr = $dom->createElementNS(self::NS_SII, 'DscRcgGlobal');
        $dr->appendChild($this->el($dom, 'NroLinDR', '1'));
        $dr->appendChild($this->el($dom, 'TpoMov', 'D'));        // D = Descuento.
        $dr->appendChild($this->el($dom, 'TpoValor', '%'));
        $dr->appendChild($this->el($dom, 'ValorDR', $this->num($pct)));
        // Sin IndExeDR: el descuento global aplica al monto afecto (neto).
        return $dr;
    }

    /**
     * @param array<string,mixed> $ref
     */
    private function buildReferencia(DOMDocument $dom, array $ref, int $linea, int $folio): DOMElement
    {
        $r = $dom->createElementNS(self::NS_SII, 'Referencia');
        $r->appendChild($this->el($dom, 'NroLinRef', (string) $linea));
        if (isset($ref['tipoDocumento'])) {
            $r->appendChild($this->el($dom, 'TpoDocRef', (string) $ref['tipoDocumento']));
        }
        // Auto-referencia al SET de certificacion: FolioRef = folio PROPIO del documento
        // (el SII empareja por el folio propio, no por el numero de caso). Las demas
        // referencias (NC/ND -> documento referenciado) respetan el folio indicado.
        if (($ref['tipoDocumento'] ?? null) === 'SET') {
            $r->appendChild($this->el($dom, 'FolioRef', (string) $folio));
        } elseif (isset($ref['folio'])) {
            $r->appendChild($this->el($dom, 'FolioRef', (string) $ref['folio']));
        }
        if (isset($ref['fecha'])) {
            $r->appendChild($this->el($dom, 'FchRef', (string) $ref['fecha']));
        }
        if (isset($ref['codigo'])) {
            $r->appendChild($this->el($dom, 'CodRef', (string) $ref['codigo']));
        }
        if (isset($ref['razon'])) {
            $r->appendChild($this->el($dom, 'RazonRef', (string) $ref['razon']));
        }
        return $r;
    }

    private function el(DOMDocument $dom, string $name, string $value): DOMElement
    {
        // createTextNode escapa & < > correctamente (createElementNS con valor NO lo hace).
        $el = $dom->createElementNS(self::NS_SII, $name);
        $el->appendChild($dom->createTextNode($value));
        return $el;
    }

    private function elOpcional(DOMDocument $dom, DOMElement $parent, string $name, ?string $value): void
    {
        if ($value !== null && $value !== '') {
            $parent->appendChild($this->el($dom, $name, $value));
        }
    }

    private function num(float $n): string
    {
        if (floor($n) === $n) {
            return (string) (int) $n;
        }
        return rtrim(rtrim(sprintf('%.6f', $n), '0'), '.');
    }
}
