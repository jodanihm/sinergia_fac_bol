<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Dto\VocabularioConsulta;
use Plantiflex\Integration\Facturacion\MySqlConsultaVentasRepository;

/**
 * EL TEST QUE CONVIERTE UNA CONVENCION EN UNA GARANTIA.
 *
 * El prompt del traductor no lista las opciones a mano: las recibe en un
 * VocabularioConsulta que se construye con las constantes del repositorio. Pero
 * ese cableado es una convencion -- el traductor vive en src/ y el repositorio en
 * integration/, y hacer que src/ dependa de integration/ seria invertir la
 * dependencia, asi que el compilador no lo puede exigir.
 *
 * Lo exige esto. Si alguien agrega una metrica al repositorio y no la ve
 * reflejada, este test se pone rojo. Sin el, el sintoma seria un modelo que
 * ofrece una opcion que el validador rechaza -- o peor, que nunca ofrece una que
 * si existe, y nadie lo nota porque no falla nada.
 */
final class VocabularioConsultaTest extends TestCase
{
    private function delRepositorio(): VocabularioConsulta
    {
        return new VocabularioConsulta(
            MySqlConsultaVentasRepository::METRICAS,
            MySqlConsultaVentasRepository::AGRUPACIONES,
            MySqlConsultaVentasRepository::ORDENES,
            MySqlConsultaVentasRepository::LIMITE_MAX,
            MySqlConsultaVentasRepository::AGRUPACIONES_IMPOSIBLES,
        );
    }

    public function testElTextoDelPromptOfreceTodasLasOpcionesDelRepositorio(): void
    {
        $texto = $this->delRepositorio()->comoTexto();

        foreach (MySqlConsultaVentasRepository::METRICAS as $m) {
            self::assertStringContainsString($m, $texto, "falta la metrica {$m}");
        }
        foreach (MySqlConsultaVentasRepository::AGRUPACIONES as $a) {
            self::assertStringContainsString($a, $texto, "falta la agrupacion {$a}");
        }
        foreach (MySqlConsultaVentasRepository::ORDENES as $o) {
            self::assertStringContainsString($o, $texto, "falta el orden {$o}");
        }
        self::assertStringContainsString((string) MySqlConsultaVentasRepository::LIMITE_MAX, $texto);
    }

    public function testElTextoOfreceCadaImposibleConSuMotivo(): void
    {
        $texto = $this->delRepositorio()->comoTexto();

        foreach (MySqlConsultaVentasRepository::AGRUPACIONES_IMPOSIBLES as $que => $porque) {
            self::assertStringContainsString($que, $texto, "falta el imposible {$que}");
            // EL MOTIVO TAMBIEN. Sin el, el modelo sabe que no puede pero no
            // puede explicarle al usuario por que, y termina inventando una
            // explicacion.
            self::assertStringContainsString($porque, $texto, "falta el motivo de {$que}");
        }
    }

    public function testNingunaOpcionImposibleEstaEntreLasValidas(): void
    {
        // Si una agrupacion estuviera en las dos listas, el prompt la ofreceria y
        // la prohibiria a la vez.
        foreach (array_keys(MySqlConsultaVentasRepository::AGRUPACIONES_IMPOSIBLES) as $imposible) {
            self::assertNotContains(
                $imposible,
                MySqlConsultaVentasRepository::AGRUPACIONES,
                "'{$imposible}' esta a la vez entre las validas y entre las imposibles"
            );
        }
    }

    public function testElVocabularioNoEsVacio(): void
    {
        // Una lista vacia produciria un prompt que no ofrece nada y un modelo que
        // inventa. Vale mas fallar aqui.
        $v = $this->delRepositorio();
        self::assertNotEmpty($v->metricas);
        self::assertNotEmpty($v->agrupaciones);
        self::assertNotEmpty($v->ordenes);
        self::assertGreaterThan(0, $v->limiteMax);
    }
}
