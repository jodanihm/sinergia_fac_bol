<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Enums\Ambiente;
use Plantiflex\FacturacionCl\Sii\RegistroVeredictoSii;
use Plantiflex\FacturacionCl\Sii\SiiConsultor;

/**
 * Los contadores del RESP_BODY de getEstUp.
 *
 * EL FIXTURE ES UNA RESPUESTA REAL, capturada del log del runner el 04-08-2026
 * (track 0253081988). No es sintetica y no se toca: cada vez que alguien la
 * "arregle" para que quede mas prolija, este test deja de probar lo que pasa de
 * verdad.
 */
final class EstadisticaEnvioSiiTest extends TestCase
{
    /** RESP_BODY primero, RESP_HDR despues: asi viene del SII. */
    private const RESPUESTA_REAL = <<<'XML'
        <?xml version="1.0"?>
        <SII:RESPUESTA xmlns:SII="http://www.sii.cl/XMLSchema">
         <SII:RESP_BODY>
          <TIPO_DOCTO>33</TIPO_DOCTO><INFORMADOS>22</INFORMADOS><ACEPTADOS>22</ACEPTADOS><RECHAZADOS>0</RECHAZADOS><REPAROS>0</REPAROS>
          <TIPO_DOCTO>56</TIPO_DOCTO><INFORMADOS>2</INFORMADOS><ACEPTADOS>2</ACEPTADOS><RECHAZADOS>0</RECHAZADOS><REPAROS>0</REPAROS>
          <TIPO_DOCTO>61</TIPO_DOCTO><INFORMADOS>6</INFORMADOS><ACEPTADOS>3</ACEPTADOS><RECHAZADOS>0</RECHAZADOS><REPAROS>3</REPAROS>
         </SII:RESP_BODY>
         <SII:RESP_HDR>
          <TRACKID>0253081988</TRACKID><ESTADO>EPR</ESTADO><GLOSA>Envio Procesado</GLOSA><NUM_ATENCION>370027 ( 2026/08/04 14:20:53)</NUM_ATENCION>
         </SII:RESP_HDR>
        </SII:RESPUESTA>
        XML;

    /** El caso que hay que atrapar: EPR con un rechazo adentro. */
    private const RESPUESTA_CON_RECHAZO = <<<'XML'
        <?xml version="1.0"?>
        <SII:RESPUESTA xmlns:SII="http://www.sii.cl/XMLSchema">
         <SII:RESP_BODY>
          <TIPO_DOCTO>56</TIPO_DOCTO><INFORMADOS>1</INFORMADOS><ACEPTADOS>0</ACEPTADOS><RECHAZADOS>1</RECHAZADOS><REPAROS>0</REPAROS>
         </SII:RESP_BODY>
         <SII:RESP_HDR>
          <TRACKID>0253081999</TRACKID><ESTADO>EPR</ESTADO><GLOSA>Envio Procesado</GLOSA>
         </SII:RESP_HDR>
        </SII:RESPUESTA>
        XML;

    /** Un sobre que NO es EPR: el SII no manda RESP_BODY. */
    private const RESPUESTA_SIN_BODY = <<<'XML'
        <?xml version="1.0"?>
        <SII:RESPUESTA xmlns:SII="http://www.sii.cl/XMLSchema">
         <SII:RESP_HDR>
          <TRACKID>0253082000</TRACKID><ESTADO>RCT</ESTADO><GLOSA>Rechazado por Error en Caratula</GLOSA>
         </SII:RESP_HDR>
        </SII:RESPUESTA>
        XML;

    private function consultar(string $innerXml): array
    {
        $soap = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<SOAP-ENV:Body><ns1:getEstUpResponse xmlns:ns1="http://DefaultNamespace">'
            . '<getEstUpReturn>' . htmlspecialchars($innerXml, ENT_QUOTES | ENT_XML1) . '</getEstUpReturn>'
            . '</ns1:getEstUpResponse></SOAP-ENV:Body></SOAP-ENV:Envelope>';

        $stack = HandlerStack::create(new MockHandler([new Response(200, [], $soap)]));

        return (new SiiConsultor(new Client(['handler' => $stack])))
            ->consultarEnvio('77724622-4', '0253081988', 'T', Ambiente::Produccion);
    }

    // --- VERIFICACION 1: los tres bloques con sus cinco valores --------------

    public function testParseaLosTresBloquesDeLaRespuestaReal(): void
    {
        $res = $this->consultar(self::RESPUESTA_REAL);

        self::assertSame('EPR', $res['estado']);
        self::assertSame('Envio Procesado', $res['glosa']);
        self::assertCount(3, $res['estadistica']);

        $esperado = [
            [33, 22, 22, 0, 0],
            [56, 2, 2, 0, 0],
            [61, 6, 3, 0, 3],
        ];
        foreach ($esperado as $i => [$tipo, $inf, $ace, $rec, $rep]) {
            $b = $res['estadistica'][$i];
            self::assertSame($tipo, $b->tipoDocto, "bloque $i tipo");
            self::assertSame($inf, $b->informados, "bloque $i informados");
            self::assertSame($ace, $b->aceptados, "bloque $i aceptados");
            self::assertSame($rec, $b->rechazados, "bloque $i rechazados");
            self::assertSame($rep, $b->reparos, "bloque $i reparos");
            self::assertTrue($b->completo(), "bloque $i completo");
        }
    }

    /**
     * EL BLOQUE 61 ES EL QUE HOY PASA POR BUENO. Sobre EPR, glosa "Envio
     * Procesado", y tres notas de credito con reparos que nadie miro.
     */
    public function testElBloque61SaleConTresReparosYNoEsSano(): void
    {
        $res = $this->consultar(self::RESPUESTA_REAL);
        $b61 = $res['estadistica'][2];

        self::assertSame(61, $b61->tipoDocto);
        self::assertSame(3, $b61->reparos);
        self::assertSame(6, $b61->informados);
        self::assertSame(3, $b61->aceptados);
        self::assertFalse($b61->sano(), '6 informados y 3 con reparos no es un bloque sano');

        // Y el sobre entero: estado bueno, contenido malo.
        self::assertFalse(RegistroVeredictoSii::esRechazo($res['estado']), 'EPR no es rechazo de sobre');
        self::assertTrue(RegistroVeredictoSii::rechazoInterno($res['estadistica']), 'pero adentro hay reparos');
        self::assertSame(
            RegistroVeredictoSii::AVISO_DOCUMENTOS_CON_REPAROS,
            RegistroVeredictoSii::motivoAviso($res['estado'], $res['estadistica']),
        );
    }

    public function testLosOtrosDosBloquesSiSonSanos(): void
    {
        $res = $this->consultar(self::RESPUESTA_REAL);

        self::assertTrue($res['estadistica'][0]->sano(), 'tipo 33: 22 de 22');
        self::assertTrue($res['estadistica'][1]->sano(), 'tipo 56: 2 de 2');
    }

    // --- El caso EPR con RECHAZADOS -----------------------------------------

    public function testEprConUnRechazoSeDetectaYSeDistingueEnElAsunto(): void
    {
        $res = $this->consultar(self::RESPUESTA_CON_RECHAZO);

        self::assertSame('EPR', $res['estado']);
        self::assertSame(1, $res['estadistica'][0]->rechazados);
        self::assertSame(0, $res['estadistica'][0]->aceptados);

        $motivo = RegistroVeredictoSii::motivoAviso($res['estado'], $res['estadistica']);
        self::assertSame(RegistroVeredictoSii::AVISO_DOCUMENTOS_RECHAZADOS, $motivo);

        // El asunto NO puede ser el mismo que el de un sobre rechazado.
        $rechazadoDeVerdad = RegistroVeredictoSii::glosaMotivo(RegistroVeredictoSii::AVISO_SOBRE_RECHAZADO);
        self::assertNotSame($rechazadoDeVerdad, RegistroVeredictoSii::glosaMotivo($motivo));
        self::assertStringContainsString('CON DOCUMENTOS RECHAZADOS', RegistroVeredictoSii::glosaMotivo($motivo));
    }

    public function testUnSobreRechazadoGanaSobreLosContadores(): void
    {
        // Si el estado ya es de rechazo, el motivo es ese: no tiene sentido
        // decirle "procesado con rechazos" a algo que no se proceso.
        self::assertSame(
            RegistroVeredictoSii::AVISO_SOBRE_RECHAZADO,
            RegistroVeredictoSii::motivoAviso('RCT', []),
        );
    }

    // --- VERIFICACION 4: sin RESP_BODY --------------------------------------

    public function testRespuestaSinRespBodyNoRompeNada(): void
    {
        $res = $this->consultar(self::RESPUESTA_SIN_BODY);

        self::assertSame('RCT', $res['estado']);
        self::assertSame('Rechazado por Error en Caratula', $res['glosa']);
        self::assertSame([], $res['estadistica']);
        self::assertFalse(RegistroVeredictoSii::rechazoInterno($res['estadistica']));
        // El estado sigue decidiendo, como antes de esta entrega.
        self::assertTrue(RegistroVeredictoSii::esRechazo($res['estado']));
        self::assertSame(
            RegistroVeredictoSii::AVISO_SOBRE_RECHAZADO,
            RegistroVeredictoSii::motivoAviso($res['estado'], $res['estadistica']),
        );
    }

    /** Un RESP_BODY vacio -- el de los fixtures viejos -- tampoco rompe. */
    public function testRespBodyVacioDaListaVacia(): void
    {
        $res = $this->consultar(
            '<?xml version="1.0"?><SII:RESPUESTA xmlns:SII="http://www.sii.cl/XMLSchema">'
            . '<SII:RESP_HDR><ESTADO>EPR</ESTADO><GLOSA>Envio Procesado</GLOSA></SII:RESP_HDR>'
            . '<SII:RESP_BODY/></SII:RESPUESTA>'
        );

        self::assertSame('EPR', $res['estado']);
        self::assertSame([], $res['estadistica']);
        self::assertSame(
            RegistroVeredictoSii::AVISO_NINGUNO,
            RegistroVeredictoSii::motivoAviso($res['estado'], $res['estadistica']),
        );
    }

    // --- VERIFICACION 5: bloques incompletos y basura -----------------------

    /**
     * LA TRAMPA DE LOS BLOQUES PLANOS. Al primer bloque le falta REPAROS. Leer
     * "los cuatro siguientes hermanos" haria que se comiera el TIPO_DOCTO del
     * segundo y todo quedaria corrido: el bloque 1 diria reparos=56 y el bloque
     * 2 tendria los numeros del 1.
     *
     * Agrupando por etiqueta, el dano se queda dentro de su bloque.
     */
    public function testUnBloqueIncompletoNoContaminaAlSiguiente(): void
    {
        $res = $this->consultar(
            '<?xml version="1.0"?><SII:RESPUESTA xmlns:SII="http://www.sii.cl/XMLSchema">'
            . '<SII:RESP_BODY>'
            . '<TIPO_DOCTO>33</TIPO_DOCTO><INFORMADOS>10</INFORMADOS><ACEPTADOS>10</ACEPTADOS><RECHAZADOS>0</RECHAZADOS>'
            . '<TIPO_DOCTO>56</TIPO_DOCTO><INFORMADOS>4</INFORMADOS><ACEPTADOS>1</ACEPTADOS><RECHAZADOS>3</RECHAZADOS><REPAROS>0</REPAROS>'
            . '</SII:RESP_BODY>'
            . '<SII:RESP_HDR><ESTADO>EPR</ESTADO><GLOSA>Envio Procesado</GLOSA></SII:RESP_HDR>'
            . '</SII:RESPUESTA>'
        );

        self::assertCount(2, $res['estadistica'], 'siguen siendo dos bloques, no uno corrido');

        $a = $res['estadistica'][0];
        self::assertSame(33, $a->tipoDocto);
        self::assertSame(10, $a->informados);
        self::assertNull($a->reparos, 'lo que falta queda en null, no en 0');
        self::assertFalse($a->completo());
        self::assertFalse($a->sano(), 'un bloque ilegible NO se declara sano');

        // Y el de al lado quedo intacto: ni un valor corrido.
        $b = $res['estadistica'][1];
        self::assertSame(56, $b->tipoDocto);
        self::assertSame(4, $b->informados);
        self::assertSame(1, $b->aceptados);
        self::assertSame(3, $b->rechazados);
        self::assertSame(0, $b->reparos);
        self::assertTrue($b->completo());
    }

    public function testBasuraEnUnContadorNoSeConvierteEnCero(): void
    {
        $res = $this->consultar(
            '<?xml version="1.0"?><SII:RESPUESTA xmlns:SII="http://www.sii.cl/XMLSchema">'
            . '<SII:RESP_BODY>'
            . '<TIPO_DOCTO>33</TIPO_DOCTO><INFORMADOS>5</INFORMADOS><ACEPTADOS>5</ACEPTADOS>'
            . '<RECHAZADOS>N/A</RECHAZADOS><REPAROS></REPAROS>'
            . '</SII:RESP_BODY>'
            . '<SII:RESP_HDR><ESTADO>EPR</ESTADO><GLOSA>ok</GLOSA></SII:RESP_HDR>'
            . '</SII:RESPUESTA>'
        );

        $b = $res['estadistica'][0];
        self::assertNull($b->rechazados, '"N/A" no es 0');
        self::assertNull($b->reparos, 'vacio no es 0');
        self::assertFalse($b->completo());

        // Y ALERTA, porque no se puede afirmar que no hubo rechazos.
        self::assertTrue(RegistroVeredictoSii::rechazoInterno($res['estadistica']));
        self::assertSame(
            RegistroVeredictoSii::AVISO_CONTADORES_ILEGIBLES,
            RegistroVeredictoSii::motivoAviso('EPR', $res['estadistica']),
        );
    }

    public function testContadoresAntesDelPrimerTipoSeDescartan(): void
    {
        $res = $this->consultar(
            '<?xml version="1.0"?><SII:RESPUESTA xmlns:SII="http://www.sii.cl/XMLSchema">'
            . '<SII:RESP_BODY>'
            . '<INFORMADOS>99</INFORMADOS><BASURA>x</BASURA>'
            . '<TIPO_DOCTO>33</TIPO_DOCTO><INFORMADOS>7</INFORMADOS><ACEPTADOS>7</ACEPTADOS><RECHAZADOS>0</RECHAZADOS><REPAROS>0</REPAROS>'
            . '</SII:RESP_BODY>'
            . '<SII:RESP_HDR><ESTADO>EPR</ESTADO><GLOSA>ok</GLOSA></SII:RESP_HDR>'
            . '</SII:RESPUESTA>'
        );

        self::assertCount(1, $res['estadistica']);
        self::assertSame(7, $res['estadistica'][0]->informados, 'el 99 huerfano no se cuela');
        self::assertTrue($res['estadistica'][0]->sano());
    }

    /**
     * LOS BLOQUES VIENEN DENTRO DE <SII:RESP_BODY>, CON PREFIJO.
     *
     * Si la busqueda del contenedor no encontrara elementos prefijados, el
     * resultado no seria un error sino CERO BLOQUES: cero rechazados, sobre
     * sucio dado por bueno, y el agujero de esta entrega intacto y sin ninguna
     * senal. Este test existe para que ese modo de fallo silencioso no pueda
     * volver sin que algo se ponga rojo.
     */
    public function testEncuentraLosBloquesAunqueRespBodyVengaConPrefijo(): void
    {
        $conPrefijo = $this->consultar(self::RESPUESTA_REAL);
        self::assertCount(3, $conPrefijo['estadistica'], 'con <SII:RESP_BODY>');

        // El mismo contenido sin prefijo tiene que dar exactamente lo mismo.
        $sinPrefijo = $this->consultar(
            '<?xml version="1.0"?><RESPUESTA>'
            . '<RESP_BODY>'
            . '<TIPO_DOCTO>33</TIPO_DOCTO><INFORMADOS>22</INFORMADOS><ACEPTADOS>22</ACEPTADOS><RECHAZADOS>0</RECHAZADOS><REPAROS>0</REPAROS>'
            . '<TIPO_DOCTO>56</TIPO_DOCTO><INFORMADOS>2</INFORMADOS><ACEPTADOS>2</ACEPTADOS><RECHAZADOS>0</RECHAZADOS><REPAROS>0</REPAROS>'
            . '<TIPO_DOCTO>61</TIPO_DOCTO><INFORMADOS>6</INFORMADOS><ACEPTADOS>3</ACEPTADOS><RECHAZADOS>0</RECHAZADOS><REPAROS>3</REPAROS>'
            . '</RESP_BODY>'
            . '<RESP_HDR><ESTADO>EPR</ESTADO><GLOSA>Envio Procesado</GLOSA></RESP_HDR>'
            . '</RESPUESTA>'
        );

        self::assertCount(3, $sinPrefijo['estadistica'], 'sin prefijo');
        self::assertSame('EPR', $sinPrefijo['estado']);
        foreach ([0, 1, 2] as $i) {
            self::assertEquals(
                $conPrefijo['estadistica'][$i],
                $sinPrefijo['estadistica'][$i],
                "el prefijo no puede cambiar el bloque $i",
            );
        }
    }

    // --- El acotado de ESTADO/GLOSA a RESP_HDR ------------------------------

    /**
     * Con la respuesta real el resultado es el mismo que buscando en todo el
     * documento -- este RESP_BODY no tiene ESTADO --, asi que el acotado es
     * endurecimiento y no un cambio de comportamiento. Lo que si prueba es que
     * getElementsByTagName('RESP_HDR') encuentra un <SII:RESP_HDR> con prefijo.
     */
    public function testEstadoSaleDeRespHdrAunqueRespBodyVengaPrimero(): void
    {
        $res = $this->consultar(self::RESPUESTA_REAL);
        self::assertSame('EPR', $res['estado']);

        // Y si algun dia el SII metiera un ESTADO en RESP_BODY, gana el de
        // RESP_HDR. Esto es lo que el acotado compra.
        $conRuido = $this->consultar(
            '<?xml version="1.0"?><SII:RESPUESTA xmlns:SII="http://www.sii.cl/XMLSchema">'
            . '<SII:RESP_BODY><ESTADO>NO-ES-ESTE</ESTADO>'
            . '<TIPO_DOCTO>33</TIPO_DOCTO><INFORMADOS>1</INFORMADOS><ACEPTADOS>1</ACEPTADOS><RECHAZADOS>0</RECHAZADOS><REPAROS>0</REPAROS>'
            . '</SII:RESP_BODY>'
            . '<SII:RESP_HDR><ESTADO>EPR</ESTADO><GLOSA>Envio Procesado</GLOSA></SII:RESP_HDR>'
            . '</SII:RESPUESTA>'
        );
        self::assertSame('EPR', $conRuido['estado']);
    }
}
