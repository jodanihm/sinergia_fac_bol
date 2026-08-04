<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Sii\ImpuestoAdicional;

/**
 * El vinculo entre ImpuestoAdicional::CODIGOS y el XSD del SII.
 *
 * POR QUE ESTE TEST EXISTE. Lo natural seria que la clase leyera el XSD en
 * caliente y no tuviera copia de la enumeracion. No se puede: /docs/ esta en
 * .gitignore (33 MB de documentacion oficial) y ese archivo no viaja con el
 * codigo, asi que en produccion no existe. La constante es la copia; este test
 * es lo que impide que se convierta en una lista nuestra que envejece sola.
 *
 * Cuando el XSD no esta -- produccion, o un CI sin docs -- el test se marca
 * SKIPPED en vez de romper el build. Eso es deliberado: su valor esta en la
 * maquina donde alguien edita la lista, que es justo donde el XSD si esta.
 */
final class ImpuestoAdicionalTest extends TestCase
{
    /** @return array<string,string> */
    private function delXsd(): array
    {
        $codigos = ImpuestoAdicional::desdeXsd();
        if ($codigos === null) {
            self::markTestSkipped(
                'SiiTypes_v10.xsd no esta disponible (docs/ no se versiona). '
                . 'Este test solo corre donde el esquema del SII esta a mano.'
            );
        }

        return $codigos;
    }

    public function testLosCodigosSonExactamenteLosDelXsd(): void
    {
        self::assertSame(
            array_keys($this->delXsd()),
            array_keys(ImpuestoAdicional::CODIGOS),
            'La constante y el XSD ya no coinciden: el SII agrego, quito o renumero un codigo. '
            . 'Hay que regenerar CODIGOS desde SiiTypes_v10.xsd, no editarla a mano.'
        );
    }

    public function testNoSeRecortoLaEnumeracion(): void
    {
        // La tentacion de dejar solo los codigos "que usamos" es justo lo que no
        // se debe hacer: recortar es empezar a decidir por el SII.
        self::assertCount(27, ImpuestoAdicional::CODIGOS);
    }

    public function testLaCervezaEsElCodigo26(): void
    {
        // Formato DTE v2.5, cap. 4 (pag. 51): "26 - DL 825/74, ART. 42, letra c)
        // - Cervezas y bebidas alcoholicas - Tasa del 20,5%".
        self::assertTrue(ImpuestoAdicional::existe('26'));
        self::assertStringContainsString('Cervezas', (string) ImpuestoAdicional::glosa('26'));
    }

    public function testUnCodigoFueraDeLaEnumeracionNoExiste(): void
    {
        // El 20, el 29 y el 42 son huecos reales de la enumeracion del SII.
        self::assertFalse(ImpuestoAdicional::existe('20'));
        self::assertFalse(ImpuestoAdicional::existe('29'));
        self::assertFalse(ImpuestoAdicional::existe('42'));
        self::assertFalse(ImpuestoAdicional::existe('99'));
        self::assertFalse(ImpuestoAdicional::existe(''));
    }

    public function testLasTasasNoEstanEnLaClase(): void
    {
        // Las tasas las cambia la ley; el motor no las conoce. Si alguien
        // agregara un mapa de tasas, este test lo delata.
        $fuente = (string) file_get_contents(__DIR__ . '/../src/Sii/ImpuestoAdicional.php');
        self::assertStringNotContainsString('20.5', $fuente);
        self::assertStringNotContainsString('31.5', $fuente);
    }
}
