<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Enums;

enum TipoDte: int
{
    case FacturaElectronica = 33;
    case FacturaExentaElectronica = 34;
    case BoletaElectronica = 39;
    case BoletaExentaElectronica = 41;
    case NotaCreditoElectronica = 61;
    case NotaDebitoElectronica = 56;
    case GuiaDespachoElectronica = 52;

    public function esBoleta(): bool
    {
        return match ($this) {
            self::BoletaElectronica,
            self::BoletaExentaElectronica => true,
            default => false,
        };
    }

    public function esFactura(): bool
    {
        return match ($this) {
            self::FacturaElectronica,
            self::FacturaExentaElectronica => true,
            default => false,
        };
    }

    public function esExento(): bool
    {
        return match ($this) {
            self::FacturaExentaElectronica,
            self::BoletaExentaElectronica => true,
            default => false,
        };
    }

    /**
     * Los tipos que el sistema MANEJA hoy: emite, lista, imprime o carga CAF.
     *
     * NO ES cases(), Y NO PUEDE SERLO. El enum modela el catalogo del SII, que
     * incluye guia de despacho (52) y boleta exenta (41): tipos que existen para
     * el SII pero que este sistema no emite ni sabe imprimir. Recorrer cases()
     * en un selector de CAF le ofreceria al usuario cargar folios de guia de
     * despacho, y el sistema no tendria despues como emitir contra ellos -- un
     * problema peor que el que se vino a arreglar.
     *
     * Cuando el sistema empiece a manejar un tipo nuevo, se agrega AQUI. Esta
     * lista es la frontera entre "lo que el SII define" y "lo que nosotros
     * hacemos", y esa frontera tiene que estar escrita en un solo lugar.
     *
     * @var list<int>
     */
    public const MANEJADOS = [33, 34, 61, 56, 39];

    /**
     * Tipos que POR DEFINICION no llevan IVA, en INT CRUDO.
     *
     *   32  Factura de venta de bienes y servicios no afectos o exentos de IVA
     *   34  Factura no afecta o exenta electronica
     *   38  Boleta exenta
     *   41  Boleta no afecta o exenta electronica
     *
     * NO ES esExento() NI PUEDE SERLO, y por eso es una lista de enteros y no un
     * match sobre casos. esExento() responde sobre un TipoDte, o sea sobre los
     * siete tipos que este enum modela; 32 y 38 no son casos -- son documentos en
     * PAPEL, que este sistema no emite. Pero SI pueden aparecer como TpoDocRef de
     * una nota de credito electronica: se corrige en electronico un documento que
     * se emitio en papel, y ese es un uso normal. from() reventaria con ellos.
     *
     * PARA QUE SE CONSULTA: una NC (61) o ND (56) que corrige uno de estos NO
     * puede llevar lineas afectas. El documento original nunca tuvo IVA, asi que
     * una nota con IVA no lo corrige -- declara un impuesto que no existe, el SII
     * la rechaza, y el folio se pierde igual porque se asigna antes de enviar.
     *
     * NO ESTAN LOS DE EXPORTACION (110, 111, 112): tambien van sin IVA, pero se
     * corrigen con nota de exportacion (111/112) y no con 61/56, asi que no
     * pueden llegar a esta pregunta.
     *
     * @var list<int>
     */
    public const SIN_IVA = [32, 34, 38, 41];

    /**
     * Si un tipo, EN INT CRUDO, es de los que no llevan IVA. Mismo patron que
     * nombreDe(): recibe el int porque quien pregunta lo tiene asi -- el
     * TpoDocRef de una referencia sale de un formulario o de un JSON -- y
     * tryFrom() no sirve, porque 32 y 38 no son casos del enum.
     */
    public static function esSinIva(int $tipo): bool
    {
        return in_array($tipo, self::SIN_IVA, true);
    }

    /**
     * Notas de credito, en INT CRUDO: 61 la electronica, 60 la de papel.
     *
     * PARA QUE SE CONSULTA: una NOTA DE DEBITO que ANULA (CodRef=1) solo puede
     * anular una nota de credito. No una factura, no una factura exenta, no una
     * boleta. Es la regla que el Formato DTE enuncia para el codigo 1 y que la
     * ayuda del formulario de emision ya recitaba sin que nadie la hiciera
     * cumplir. Para referenciar una FACTURA, una ND tiene que usar CodRef=3
     * (corrige montos), que es otro documento tributario distinto.
     *
     * 60 va en la lista por el mismo motivo que 32 y 38 en SIN_IVA: no es un
     * caso del enum -- no lo emitimos -- pero si puede ser el TpoDocRef de una
     * ND electronica que corrige una nota de credito emitida en papel.
     *
     * @var list<int>
     */
    public const NOTAS_CREDITO = [60, 61];

    /** Si un tipo, EN INT CRUDO, es una nota de credito (electronica o de papel). */
    public static function esNotaCredito(int $tipo): bool
    {
        return in_array($tipo, self::NOTAS_CREDITO, true);
    }

    /**
     * Nombre CORTO, el de la interfaz. Es el que va en tablas, badges y
     * selectores: en una tabla el nombre largo estorba, y .tabla-scroll ya
     * desborda por debajo de 1024px sin ayuda.
     */
    public function nombre(): string
    {
        return match ($this) {
            self::FacturaElectronica       => 'Factura',
            self::FacturaExentaElectronica => 'Factura exenta',
            self::NotaCreditoElectronica   => 'Nota de credito',
            self::NotaDebitoElectronica    => 'Nota de debito',
            self::BoletaElectronica        => 'Boleta',
            self::BoletaExentaElectronica  => 'Boleta exenta',
            self::GuiaDespachoElectronica  => 'Guia de despacho',
        };
    }

    /**
     * Nombre LARGO, con "electronica". Se usa en UN solo lugar: el asunto del
     * correo al receptor (PreparadorEnvio), que es lo unico que ve un tercero
     * fuera del panel y donde el nombre completo si aporta.
     */
    public function nombreLargo(): string
    {
        return $this->nombre() . ' electronica';
    }

    /**
     * Nombre de un tipo a partir del INT CRUDO, con el fallback ADENTRO.
     *
     * POR QUE ESTE ESTATICO EXISTE: los ~18 sitios que pintan un nombre reciben
     * un int (de la base, del motor, de un formulario), no un TipoDte. Y
     * TipoDte::from() LANZA ValueError con un tipo que el enum no conoce, asi
     * que cada uno tendria que escribir su propio tryFrom() con su propio
     * fallback -- que es exactamente como se llego a tener seis mapas y CINCO
     * redacciones distintas de fallback ("Tipo N", "Documento tipo N",
     * "tipo N", "Documento tributario tipo N"). Con el patron aqui adentro, hay
     * una sola regla y un solo texto.
     *
     * FALLBACK UNICO: "Documento tipo N".
     */
    public static function nombreDe(int $tipo, bool $largo = false): string
    {
        $caso = self::tryFrom($tipo);
        if ($caso === null) {
            return "Documento tipo {$tipo}";
        }

        return $largo ? $caso->nombreLargo() : $caso->nombre();
    }

    /**
     * Catalogo tipo => nombre corto de los MANEJADOS, para los selectores y
     * resumenes que necesitan recorrer la lista en vez de consultarla.
     *
     * @return array<int,string>
     */
    public static function catalogo(): array
    {
        $out = [];
        foreach (self::MANEJADOS as $tipo) {
            $out[$tipo] = self::nombreDe($tipo);
        }

        return $out;
    }
}
