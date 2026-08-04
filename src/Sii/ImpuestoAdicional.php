<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Sii;

/**
 * Codigos de impuesto adicional y retencion del SII (ImpAdicDTEType).
 *
 * Es la enumeracion que el XSD declara para DOS campos distintos y que tienen
 * que valer lo mismo en los dos: CodImpAdic, en cada linea de Detalle
 * (DTE_v10.xsd:1649, maxOccurs=2), y TipoImp, dentro de cada bloque ImptoReten
 * de Totales (DTE_v10.xsd:1159).
 *
 *
 * DE DONDE SALE LA LISTA, Y POR QUE NO SE LEE EL XSD EN CALIENTE
 * -----------------------------------------------------------------------------
 * Los 27 valores de abajo se EXTRAJERON del propio
 * docs/18_Schema_XML_DTE/SiiTypes_v10.xsd:393, en orden y con su documentacion
 * textual. No es una lista curada ni un subconjunto elegido por nosotros: es la
 * enumeracion completa del SII, con codigos que este sistema no usara nunca
 * (IVA retenido de trigo, chatarra, PPA...) precisamente porque recortarla
 * seria empezar a decidir por el SII.
 *
 * Lo natural seria leer el XSD en tiempo de ejecucion y no tener copia. NO SE
 * PUEDE: /docs/ esta en .gitignore -- son 33 MB de documentacion oficial con
 * 1131 PNG, excluidos a proposito del repositorio -- asi que el XSD NO viaja
 * con el codigo y en produccion ese archivo no existe. Una validacion que
 * dependiera de el fallaria justo donde importa.
 *
 * EL VINCULO CON EL XSD NO SE PIERDE, SE MUEVE A UN TEST.
 * tests/ImpuestoAdicionalTest.php vuelve a extraer la enumeracion del XSD y la
 * compara con esta constante: si el SII agrega, quita o renumera un codigo, el
 * test falla y obliga a mirar. Cuando el XSD no esta (produccion, CI sin docs)
 * ese test se marca skipped en vez de romper el build.
 *
 * Si algun dia se decide versionar los cuatro XSD -- son 131 KB de los 33 MB --
 * esta clase puede pasar a leerlos directo y la constante desaparece.
 *
 *
 * LAS TASAS NO ESTAN AQUI, Y ES DELIBERADO
 * -----------------------------------------------------------------------------
 * El codigo 26 (cervezas) vale hoy 20,5%, pero las tasas las cambia la ley y ya
 * cambiaron antes. Codificarlas convertiria cada reforma tributaria en un
 * despliegue, y peor: un motor con la tasa vieja seguiria emitiendo documentos
 * que el SII rechazaria, sin que nadie se entere hasta el rechazo.
 *
 * La tasa viaja en el payload, por linea. El motor no la conoce ni la
 * verifica contra ninguna tabla: la transporta al TasaImp del XML y la usa para
 * calcular el MontoImp. Quien emite es quien sabe que tasa le corresponde a su
 * producto en la fecha en que vende.
 */
final class ImpuestoAdicional
{
    /**
     * ImpAdicDTEType completo, verbatim de SiiTypes_v10.xsd:393.
     *
     * El valor es la <xs:documentation> del propio XSD, sin reescribir: sirve
     * para que un mensaje de error pueda decir que es el codigo 26 sin que
     * nadie tenga que abrir el esquema.
     *
     * @var array<string,string>
     */
    public const CODIGOS = [
        '14'  => 'IVA Margen Comercializacion (Factura Venta del Contribuyente) [F29 - C039]',
        '15'  => 'IVA Retenido Total (Factura Compra del Contribuyente) [F29 - C039]',
        '16'  => 'IVA Retenido Parcial (Factura Compra del Contribuyente) [F29]',
        '17'  => 'IVA Anticipado Faenamiento Carne [F29 - C042]',
        '18'  => 'IVA Anticipado Carne [F29 - C042]',
        '19'  => 'IVA Anticipado Harina [F29 - C042]',
        '23'  => 'Impuesto Adicional Productos Art. 37 a) b) c) Oro, Joyas, Pieles [F29 - C113]',
        '24'  => 'Impuesto Art. 42 a) Licores, Pisco, Destilados [F29 - C148]',
        '25'  => 'Impuesto Art. 42 c) Vinos',
        '26'  => 'Impuesto Art. 42 c) Cervezas y Bebidas Alcoholicas [F29 - C150]',
        '27'  => 'Impuesto Art. 42 d) y e) Bebidas Analcoholicas y Minerales [F29 - C146]',
        '28'  => 'Impuesto Especifico Diesel [F29 - C127]',
        '30'  => 'IVA Retenido Legumbres',
        '31'  => 'IVA Retenido Silvestres',
        '32'  => 'IVA Retenido Ganado',
        '33'  => 'IVA Retenido Madera',
        '34'  => 'IVA Retenido Trigo',
        '35'  => 'Impuesto Especifico Gasolina',
        '36'  => 'IVA Retenido Arroz',
        '37'  => 'IVA Retenido Hidrobiologicas',
        '38'  => 'IVA Retenido Chatarra',
        '39'  => 'IVA Retenido PPA',
        '40'  => 'IVA Retenido Opcional',
        '41'  => 'IVA Retenido Construccion',
        '44'  => 'Impuesto Adicional Productos Art. 37 e) h) i) l) 1ra Venta (Alfombras, C. Rodantes, Caviar, Armas) [F29 - C113]',
        '45'  => 'Impuesto Adicional Productos Art. 37 j) 1ra Venta (Pirotecnia) [F29 - C113]',
        '271' => 'Bebidas analcoholicas y Minerales con elevado contenido de azucares.',
    ];

    /** Ruta del XSD del que sale CODIGOS. Solo la usa el test; ver el docblock. */
    public const RUTA_XSD = __DIR__ . '/../../docs/18_Schema_XML_DTE/SiiTypes_v10.xsd';

    public static function existe(string $codigo): bool
    {
        return isset(self::CODIGOS[trim($codigo)]);
    }

    public static function glosa(string $codigo): ?string
    {
        return self::CODIGOS[trim($codigo)] ?? null;
    }

    /** Los codigos validos, para armar un mensaje de error util. */
    public static function listado(): string
    {
        return implode(', ', array_keys(self::CODIGOS));
    }

    /**
     * Extrae la enumeracion del XSD. Devuelve null si el archivo no esta.
     *
     * Vive aqui y no en el test para que el metodo de extraccion quede AL LADO
     * de la constante que produce: quien toque una ve la otra.
     *
     * @return array<string,string>|null
     */
    public static function desdeXsd(?string $ruta = null): ?array
    {
        $ruta = $ruta ?? self::RUTA_XSD;
        if (! is_file($ruta) || ! is_readable($ruta)) {
            return null;
        }
        $xsd = (string) file_get_contents($ruta);
        $ini = strpos($xsd, 'name="ImpAdicDTEType"');
        if ($ini === false) {
            return null;
        }
        $fin = strpos($xsd, '</xs:simpleType>', $ini);
        $blk = substr($xsd, $ini, $fin === false ? null : $fin - $ini);

        preg_match_all(
            '#<xs:enumeration value="([^"]+)">\s*<xs:annotation>\s*<xs:documentation>(.*?)</xs:documentation>#s',
            $blk,
            $m,
            PREG_SET_ORDER
        );

        $salida = [];
        foreach ($m as $par) {
            // El XSD viene en ISO-8859-1; se normaliza el espaciado igual que
            // al generar la constante.
            $salida[$par[1]] = trim((string) preg_replace('/\s+/', ' ', $par[2]));
        }

        return $salida !== [] ? $salida : null;
    }
}
