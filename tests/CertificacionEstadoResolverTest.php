<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Sii\CertificacionEstadoResolver;

/**
 * Reproduce EXACTAMENTE el bug real encontrado en EASY AGENDA SPA
 * (78157243-8): dos envios del Set Basico en EPR con los 3 tipos de
 * documento, uno de ellos (0253051518) el envio VIEJO rechazado en contenido
 * (SRH) por el SII, el otro (0253079814) el REAL aprobado (SOK). El array de
 * envios llega ordenado por ultimoId DESC (mismo orden real que produce
 * agruparEmitidosPorEnvio() del panel), asi que 0253051518 aparece PRIMERO
 * si tiene un id de fila mas alto en dte_emitido -- el criterio viejo
 * (estado==='EPR' + 3 tipos, primero encontrado) lo tomaba como "aprobado"
 * sin importar cual de los dos habia recibido SOK realmente.
 */
final class CertificacionEstadoResolverTest extends TestCase
{
    /** @return list<array{trackId:string, estado:string, tipos:list<int>}> */
    private function enviosSetBasicoRealEscenario(): array
    {
        // Orden real: el envio con mayor id de fila en dte_emitido va PRIMERO
        // (agruparEmitidosPorEnvio() ordena por ultimoId DESC). En el caso
        // real, el envio viejo rechazado (0253051518) tenia id de fila MAS
        // ALTO que el envio realmente aprobado (0253079814) -- por eso
        // aparecia primero y el criterio viejo lo tomaba mal.
        return [
            ['trackId' => '0253051518', 'estado' => 'EPR', 'tipos' => [33, 61, 56]],
            ['trackId' => '0253079814', 'estado' => 'EPR', 'tipos' => [33, 61, 56]],
        ];
    }

    public function testNingunEnvioMarcadoSokDaAprobadoFalsoAunqueAmbosEstenEnEprConLosTresTipos(): void
    {
        $resultado = CertificacionEstadoResolver::setBasicoAprobado($this->enviosSetBasicoRealEscenario(), []);

        self::assertFalse($resultado['aprobado']);
        self::assertNull($resultado['trackId']);
    }

    public function testMarcarElTrackIdRealmenteAprobadoLoDetectaAunqueTengaIdMasBajo(): void
    {
        // 0253079814 es el SEGUNDO en el array (id de fila mas bajo que
        // 0253051518), pero es el UNICO marcado SOK -- debe ganar igual, sin
        // importar que 0253051518 aparezca primero en el orden por id.
        $sokPorTrackId = ['0253079814' => '2026-07-15 09:00:00'];

        $resultado = CertificacionEstadoResolver::setBasicoAprobado($this->enviosSetBasicoRealEscenario(), $sokPorTrackId);

        self::assertTrue($resultado['aprobado']);
        self::assertSame('0253079814', $resultado['trackId']);
    }

    public function testMarcarElEnvioEquivocadoLoAprobariaIgual_ElCriterioSigueLaMarcaSokNoElId(): void
    {
        // Prueba de regresion inversa: si se marca (por error, o en un
        // escenario distinto) el envio con id de fila MAS ALTO (0253051518,
        // primero en el array), el resolver debe seguir la marca SOK, no la
        // posicion/recencia -- confirma que el criterio depende 100% de
        // sokPorTrackId, nunca del orden de $envios por si solo.
        $sokPorTrackId = ['0253051518' => '2026-07-15 09:00:00'];

        $resultado = CertificacionEstadoResolver::setBasicoAprobado($this->enviosSetBasicoRealEscenario(), $sokPorTrackId);

        self::assertTrue($resultado['aprobado']);
        self::assertSame('0253051518', $resultado['trackId']);
    }

    public function testAmbosMarcadosSokTomaElMasRecientePorOrdenDelArray(): void
    {
        // Ambiguedad real (no deberia pasar, pero no se asume imposible): si
        // los DOS quedaran marcados SOK, se toma el primero en el orden ya
        // dado (ultimoId DESC = mas reciente), documentado explicito en
        // CertificacionEstadoResolver::setBasicoAprobado().
        $sokPorTrackId = [
            '0253051518' => '2026-07-10 09:00:00',
            '0253079814' => '2026-07-15 09:00:00',
        ];

        $resultado = CertificacionEstadoResolver::setBasicoAprobado($this->enviosSetBasicoRealEscenario(), $sokPorTrackId);

        self::assertTrue($resultado['aprobado']);
        self::assertSame('0253051518', $resultado['trackId'], 'Debe tomar el primero del array (mas reciente por ultimoId DESC).');
    }

    public function testEnvioSinLosTresTiposNuncaApruebaAunqueEsteMarcadoSok(): void
    {
        $envios = [
            ['trackId' => '0253099999', 'estado' => 'EPR', 'tipos' => [33, 61]], // falta ND (56)
        ];
        $sokPorTrackId = ['0253099999' => '2026-07-15 09:00:00'];

        $resultado = CertificacionEstadoResolver::setBasicoAprobado($envios, $sokPorTrackId);

        self::assertFalse($resultado['aprobado']);
        self::assertNull($resultado['trackId']);
    }

    public function testEnvioRechazadoRctNuncaApruebaAunqueEsteMarcadoSokPorError(): void
    {
        $envios = [
            ['trackId' => '0253099998', 'estado' => 'RCT', 'tipos' => [33, 61, 56]],
        ];
        $sokPorTrackId = ['0253099998' => '2026-07-15 09:00:00'];

        $resultado = CertificacionEstadoResolver::setBasicoAprobado($envios, $sokPorTrackId);

        self::assertFalse($resultado['aprobado']);
        self::assertNull($resultado['trackId']);
    }

    public function testSetBasicoEnviadoSinReparosApruebaSinSokADiferenciaDeSetBasicoAprobado(): void
    {
        // Mismo escenario real (EPR+3-tipos, SIN ninguna entrada en
        // sokPorTrackId): setBasicoAprobado() sigue dando false (el criterio
        // protegido, sin cambios); setBasicoEnviadoSinReparos() da true (el
        // criterio MAS LAXO, ahora expuesto como opcion explicita para
        // "emitir Libro sin esperar SOK", nunca por defecto).
        $envios = $this->enviosSetBasicoRealEscenario();

        $protegido  = CertificacionEstadoResolver::setBasicoAprobado($envios, []);
        $sinReparos = CertificacionEstadoResolver::setBasicoEnviadoSinReparos($envios);

        self::assertFalse($protegido['aprobado'], 'setBasicoAprobado() no debe cambiar: sigue exigiendo SOK.');
        self::assertTrue($sinReparos['aprobado'], 'setBasicoEnviadoSinReparos() no exige SOK, solo EPR+3-tipos.');
        self::assertSame(
            '0253051518',
            $sinReparos['trackId'],
            'Toma el primero EPR+3-tipos del array, igual que el criterio anterior real.'
        );
    }

    public function testSetBasicoEnviadoSinReparosSigueExigiendoLosTresTipos(): void
    {
        $envios = [
            ['trackId' => '0253099999', 'estado' => 'EPR', 'tipos' => [33, 61]], // falta ND (56)
        ];

        $resultado = CertificacionEstadoResolver::setBasicoEnviadoSinReparos($envios);

        self::assertFalse($resultado['aprobado']);
        self::assertNull($resultado['trackId']);
    }

    public function testSetBasicoEnviadoSinReparosSigueExigiendoEpr(): void
    {
        $envios = [
            ['trackId' => '0253099998', 'estado' => 'RCT', 'tipos' => [33, 61, 56]],
        ];

        $resultado = CertificacionEstadoResolver::setBasicoEnviadoSinReparos($envios);

        self::assertFalse($resultado['aprobado']);
        self::assertNull($resultado['trackId']);
    }
}
