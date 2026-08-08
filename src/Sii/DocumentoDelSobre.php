<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Sii;

use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * Ubica UN documento dentro de un EnvioDTE persistido, por tipo y folio.
 *
 * POR QUE ESTA CLASE EXISTE
 * -----------------------------------------------------------------------------
 * El xml que guarda cada fila de dte_emitido es el EnvioDTE COMPLETO, no el
 * documento: lo dice persistirEmitidosLote() de SiiDirectoFacturador, y en
 * produccion hay ocho sobres de lote -- seis de veinte documentos, uno de siete
 * y uno de cuatro, 136 documentos en total.
 *
 * Quien lea ese XML con getElementsByTagName(...)->item(0) NO obtiene el
 * documento que pidio: obtiene el PRIMERO del sobre. Ese error aparecio dos
 * veces, en dos funciones distintas, escrito por separado:
 *
 *   datosConsultaDte()      mandaba a getEstDte el receptor, la fecha y el monto
 *                           del primer documento. El SII contestaba DNK -- "datos
 *                           no coinciden" -- indistinguible de un rechazo real.
 *
 *   reconstruirOriginal()   armaba la nota de credito de anulacion con el
 *                           receptor y los montos del primero, Y CON LAS LINEAS
 *                           DE DETALLE DE TODOS los documentos del sobre.
 *
 * Dos veces el mismo error no es una coincidencia: es que el criterio no estaba
 * en ninguna parte donde se pudiera reusar. Aqui esta.
 *
 *
 * EL CRITERIO, Y POR QUE ESTE Y NO EL OTRO
 * -----------------------------------------------------------------------------
 * En el repo habia dos formas de ubicar un documento en un sobre, y NO son
 * equivalentes:
 *
 *   DtePdfGenerator::seleccionarDocumento()  compara TipoDTE y Folio del propio
 *                                            XML, y LANZA si no lo encuentra.
 *   persistirEmitidosLote()                  indexa por el atributo ID
 *                                            "F{folio}T{tipo}", convencion
 *                                            NUESTRA, y cae a ceros EN SILENCIO.
 *
 * Se toma el primero. Por el modo de fallo -- caer en silencio a datos de otro
 * documento es exactamente lo que hay que impedir -- y porque compara datos que
 * pone el SII en vez de un identificador que ponemos nosotros.
 *
 *
 * LAS TRES GUARDAS VAN JUNTAS, Y ESO ES LA MITAD DEL VALOR
 * -----------------------------------------------------------------------------
 * Vacio, ilegible y ausente son tres formas distintas de no tener el documento,
 * y las tres terminaban devolviendo datos de otro o ceros. Estan aqui las tres
 * para que ningun llamador nuevo implemente dos y se olvide de la tercera.
 */
final class DocumentoDelSobre
{
    private const NS_SII = 'http://www.sii.cl/SiiDte';

    /**
     * El <Documento> pedido, ya parseado.
     *
     * @param string $envioXml EnvioDTE completo, tal como se persiste.
     *
     * @throws RuntimeException si el XML esta vacio, no parsea, o no contiene
     *         ese tipo y folio. NUNCA devuelve "el primero" como consuelo.
     */
    public static function ubicar(string $envioXml, int $tipoDte, int $folio): DOMElement
    {
        // EL VACIO SE ATRAPA ANTES DE loadXML: en PHP 8,
        // DOMDocument::loadXML('') lanza un ValueError -- "Argument #1 ($source)
        // must not be empty" -- que se escapa POR ENCIMA de la comprobacion del
        // retorno. Ese mensaje habla de un argumento de una libreria; este dice
        // QUE documento esta vacio, que es lo que alguien necesita para ir a
        // mirar la fila.
        //
        // Con trim() y no con === '': un XML de puros espacios no lo frena
        // MySqlDteEmitidoRepository::obtenerXml(), que solo descarta la cadena
        // vacia exacta.
        if (trim($envioXml) === '') {
            throw new RuntimeException(sprintf(
                'El XML persistido del DTE %d/%d esta vacio: no hay documento que ubicar.',
                $tipoDte,
                $folio,
            ));
        }

        $dom    = new DOMDocument();
        $previo = libxml_use_internal_errors(true);
        $ok     = $dom->loadXML($envioXml);
        libxml_clear_errors();
        libxml_use_internal_errors($previo);

        // EL RETORNO DE loadXML SE COMPRUEBA. Sin esto, un XML ilegible deja un
        // documento vacio y las lecturas posteriores devuelven cadenas vacias y
        // ceros -- que se usan igual, sin ningun error a la vista.
        if (! $ok) {
            throw new RuntimeException(sprintf(
                'El XML persistido del DTE %d/%d no se pudo parsear: no hay documento que ubicar.',
                $tipoDte,
                $folio,
            ));
        }

        foreach ($dom->getElementsByTagNameNS(self::NS_SII, 'Documento') as $documento) {
            if (! $documento instanceof DOMElement) {
                continue;
            }
            // Tipo y folio se leen del IdDoc y no del Documento entero: <Folio>
            // podria aparecer en otros bloques, y acotar cuesta una linea.
            $idDoc = $documento->getElementsByTagNameNS(self::NS_SII, 'IdDoc')->item(0);
            if (! $idDoc instanceof DOMElement) {
                continue;
            }
            if (self::texto($idDoc, 'TipoDTE') === (string) $tipoDte
                && self::texto($idDoc, 'Folio') === (string) $folio
            ) {
                return $documento;
            }
        }

        throw new RuntimeException(sprintf(
            'El documento tipo %d folio %d no esta en el EnvioDTE persistido.',
            $tipoDte,
            $folio,
        ));
    }

    /**
     * Texto del primer $tag DENTRO de $ambito.
     *
     * El ambito es siempre un elemento, nunca el documento: es justamente lo que
     * distingue leer el documento correcto de leer el primero del sobre.
     */
    public static function texto(DOMElement $ambito, string $tag): string
    {
        $n = $ambito->getElementsByTagNameNS(self::NS_SII, $tag)->item(0);

        return $n !== null ? trim($n->textContent) : '';
    }

    /**
     * Los <Detalle> de UN documento.
     *
     * Existe como metodo y no como getElementsByTagNameNS suelto en cada
     * llamador porque este es el punto donde mas caro sale equivocarse: una nota
     * de credito armada con los Detalle del sobre entero replica las lineas de
     * los treinta documentos. Pedirlo por nombre deja constancia del ambito.
     *
     * @return list<DOMElement>
     */
    public static function detalles(DOMElement $documento): array
    {
        $out = [];
        foreach ($documento->getElementsByTagNameNS(self::NS_SII, 'Detalle') as $det) {
            if ($det instanceof DOMElement) {
                $out[] = $det;
            }
        }

        return $out;
    }
}
