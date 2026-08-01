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
