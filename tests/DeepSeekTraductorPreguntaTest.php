<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Dto\PreguntaTraducida;
use Plantiflex\FacturacionCl\Dto\VocabularioConsulta;
use Plantiflex\FacturacionCl\Exceptions\TraduccionPreguntaException;
use Plantiflex\FacturacionCl\Providers\DeepSeekTraductorPregunta;
use Plantiflex\Integration\Facturacion\ConsultaVentasInvalidaException;
use Plantiflex\Integration\Facturacion\MySqlConsultaVentasRepository;

/**
 * NINGUNA CONSULTA REAL. Todo con MockHandler: no se gasta saldo de DeepSeek y
 * los tests corren sin red.
 */
final class DeepSeekTraductorPreguntaTest extends TestCase
{
    /** @var list<array<string,mixed>> */
    private array $historial = [];

    /**
     * El vocabulario SE CONSTRUYE CON LAS CONSTANTES DEL REPOSITORIO, no con
     * listas escritas aqui. Es el cableado que el codigo de produccion tiene que
     * hacer igual, y testVocabularioSaleDelRepositorio() lo fija.
     */
    private function vocabulario(): VocabularioConsulta
    {
        return new VocabularioConsulta(
            MySqlConsultaVentasRepository::METRICAS,
            MySqlConsultaVentasRepository::AGRUPACIONES,
            MySqlConsultaVentasRepository::ORDENES,
            MySqlConsultaVentasRepository::LIMITE_MAX,
            MySqlConsultaVentasRepository::AGRUPACIONES_IMPOSIBLES,
        );
    }

    /** Traductor con respuestas prefabricadas y con el historial de peticiones. */
    private function traductor(array $respuestas, string $clave = 'clave-de-prueba'): DeepSeekTraductorPregunta
    {
        $mock  = new MockHandler($respuestas);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->historial));

        return new DeepSeekTraductorPregunta(new Client(['handler' => $stack, 'http_errors' => false]), $clave);
    }

    /** Sobre de DeepSeek con el JSON que "escribio" el modelo dentro. */
    private function sobre(string $contenido): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => $contenido]]],
        ]));
    }

    // =====================================================================
    // 1. Una respuesta bien formada se traduce a perillas validas
    // =====================================================================

    public function testRespuestaBienFormadaSeTraduceAPerillasQueElRepositorioAcepta(): void
    {
        $t = $this->traductor([$this->sobre((string) json_encode([
            'desenlace' => 'perillas',
            'perillas'  => [
                'metrica' => 'monto', 'agruparPor' => 'mes',
                'desde' => '2026-01-01', 'hasta' => '2026-07-31',
                'orden' => 'grupo_asc', 'limite' => 12,
            ],
        ]))]);

        $r = $t->traducir('cuanto vendi cada mes este año', $this->vocabulario(), '2026-08-11');

        self::assertTrue($r->hayQueConsultar());
        self::assertSame('monto', $r->perillas['metrica']);
        self::assertSame('mes', $r->perillas['agruparPor']);

        // Y LO QUE IMPORTA: que el repositorio las acepte. Se comprueba contra
        // las MISMAS listas cerradas que validan un POST.
        self::assertContains($r->perillas['metrica'], MySqlConsultaVentasRepository::METRICAS);
        self::assertContains($r->perillas['agruparPor'], MySqlConsultaVentasRepository::AGRUPACIONES);
        self::assertContains($r->perillas['orden'], MySqlConsultaVentasRepository::ORDENES);
        self::assertLessThanOrEqual(MySqlConsultaVentasRepository::LIMITE_MAX, $r->perillas['limite']);
    }

    public function testElLimiteComoTextoSeNormalizaAEntero(): void
    {
        // El modo JSON puede devolver "20" en vez de 20 y el validador exige
        // entero o digitos. Se normaliza el TIPO, no el valor.
        $t = $this->traductor([$this->sobre((string) json_encode([
            'desenlace' => 'perillas',
            'perillas'  => ['metrica' => 'neto', 'agruparPor' => 'cliente',
                            'desde' => '2026-01-01', 'hasta' => '2026-08-11',
                            'orden' => 'metrica_desc', 'limite' => '20'],
        ]))]);

        $r = $t->traducir('mis mejores clientes', $this->vocabulario(), '2026-08-11');
        self::assertSame(20, $r->perillas['limite']);
    }

    // =====================================================================
    // 2. Una metrica inventada da el MISMO rechazo que un valor invalido
    // =====================================================================

    public function testMetricaAlucinadaLlegaIntactaDesdeElTraductor(): void
    {
        $t = $this->traductor([$this->sobre((string) json_encode([
            'desenlace' => 'perillas',
            'perillas'  => ['metrica' => 'margen_bruto', 'agruparPor' => 'ninguna',
                            'desde' => '2026-01-01', 'hasta' => '2026-08-11'],
        ]))]);

        $r = $t->traducir('cual fue mi margen', $this->vocabulario(), '2026-08-11');

        // EL TRADUCTOR NO CORRIGE NI FILTRA: entrega lo que el modelo dijo, para
        // que el rechazo lo de un solo validador y no dos que puedan divergir.
        self::assertSame('margen_bruto', $r->perillas['metrica']);
    }

    /**
     * ESTE TEST NO EJERCITA EL REPOSITORIO ENTERO, Y EL NOMBRE LO DICE.
     *
     * La primera version lo instanciaba para llamar a consultar(), y no se pudo:
     * MySqlClienteRepository es final -- a proposito -- y no se puede doblar. Y
     * armar el grafo real (PDO contra una base) no habria aportado nada a lo que
     * esta afirmacion dice, que es que la metrica inventada NO ESTA en la lista
     * cerrada contra la que valida el repositorio.
     *
     * El rechazo de punta a punta -- traductor, repositorio y base -- SI se
     * ejercita, en scripts/verificar_consulta_ventas.php, verificacion 4.
     */
    public function testUnaMetricaAlucinadaNoEstaEnLaListaCerradaConLaQueValidaElRepositorio(): void
    {
        self::assertNotContains('margen_bruto', MySqlConsultaVentasRepository::METRICAS);

        // Y el rechazo NOMBRA la metrica, que es lo que la capa de arriba
        // necesita para poder decirle al usuario que fue lo que no se entendio.
        $e = ConsultaVentasInvalidaException::valorInvalido(
            'metrica',
            'margen_bruto',
            MySqlConsultaVentasRepository::METRICAS
        );
        self::assertStringContainsString('margen_bruto', $e->getMessage());
        self::assertStringContainsString('metrica', $e->getMessage());
    }

    // =====================================================================
    // 3. No-JSON y JSON con basura se rechazan sin reventar
    // =====================================================================

    public function testCuerpoQueNoEsJsonSeRechaza(): void
    {
        $t = $this->traductor([new Response(200, [], '<html>error</html>')]);
        $this->expectException(TraduccionPreguntaException::class);
        $this->expectExceptionMessageMatches('/no es JSON/');
        $t->traducir('hola', $this->vocabulario(), '2026-08-11');
    }

    public function testSobreSinContenidoSeRechaza(): void
    {
        $t = $this->traductor([new Response(200, [], (string) json_encode(['choices' => []]))]);
        $this->expectException(TraduccionPreguntaException::class);
        $this->expectExceptionMessageMatches('/choices/');
        $t->traducir('hola', $this->vocabulario(), '2026-08-11');
    }

    public function testContenidoQueNoEsJsonSeRechazaConLoQueDijoElModelo(): void
    {
        $t = $this->traductor([$this->sobre('Claro, con gusto te ayudo con eso.')]);
        try {
            $t->traducir('hola', $this->vocabulario(), '2026-08-11');
            self::fail('deberia haber lanzado');
        } catch (TraduccionPreguntaException $e) {
            self::assertSame(TraduccionPreguntaException::RESPUESTA_ILEGIBLE, $e->motivo);
            // El mensaje trae lo que dijo el modelo: sin eso, diagnosticar por
            // que fallo obliga a reproducir la consulta y volver a pagarla.
            self::assertStringContainsString('Claro, con gusto', $e->getMessage());
        }
    }

    public function testDesenlaceDesconocidoSeRechaza(): void
    {
        $t = $this->traductor([$this->sobre((string) json_encode(['desenlace' => 'quizas']))]);
        $this->expectException(TraduccionPreguntaException::class);
        $this->expectExceptionMessageMatches('/quizas/');
        $t->traducir('hola', $this->vocabulario(), '2026-08-11');
    }

    public function testDesenlacePerillasSinPerillasSeRechaza(): void
    {
        $t = $this->traductor([$this->sobre((string) json_encode(['desenlace' => 'perillas']))]);
        $this->expectException(TraduccionPreguntaException::class);
        $t->traducir('hola', $this->vocabulario(), '2026-08-11');
    }

    // =====================================================================
    // 4. La pregunta imposible llega como "no se puede", no como error
    // =====================================================================

    public function testPreguntaImposibleNoEsExcepcionYTraeElMotivoParaElUsuario(): void
    {
        $motivo = 'no puedo responder por producto: los documentos emitidos no guardan '
            . 'el detalle de lo vendido en un formato consultable';
        $t = $this->traductor([$this->sobre((string) json_encode([
            'desenlace' => 'imposible', 'motivo' => $motivo,
        ]))]);

        $r = $t->traducir('que producto vendi mas', $this->vocabulario(), '2026-08-11');

        self::assertSame(PreguntaTraducida::IMPOSIBLE, $r->desenlace);
        self::assertFalse($r->hayQueConsultar());
        self::assertSame($motivo, $r->motivo);
    }

    public function testElPromptLePasaAlModeloLasPreguntasImposiblesConSuMotivo(): void
    {
        $t = $this->traductor([$this->sobre((string) json_encode(['desenlace' => 'no_entendida']))]);
        $t->traducir('hola', $this->vocabulario(), '2026-08-11');

        $sistema = $this->cuerpoEnviado()['messages'][0]['content'];
        foreach (array_keys(MySqlConsultaVentasRepository::AGRUPACIONES_IMPOSIBLES) as $imposible) {
            self::assertStringContainsString($imposible, $sistema, "el prompt no menciona {$imposible}");
        }
        self::assertStringContainsString('imposible', $sistema);
        self::assertStringContainsString('no_entendida', $sistema);
    }

    // =====================================================================
    // 5. Sin clave: mensaje claro, y no revienta hacia la pantalla
    // =====================================================================

    public function testSinClaveLanzaConMotivoIdentificableYSinTocarLaRed(): void
    {
        $t = $this->traductor([], '');   // sin respuestas: si pidiera, reventaria
        try {
            $t->traducir('cuanto vendi', $this->vocabulario(), '2026-08-11');
            self::fail('deberia haber lanzado');
        } catch (TraduccionPreguntaException $e) {
            self::assertSame(TraduccionPreguntaException::SIN_CLAVE, $e->motivo);
            self::assertStringContainsString(DeepSeekTraductorPregunta::ENV_CLAVE, $e->getMessage());
            // El mensaje le dice al usuario que hacer mientras tanto.
            self::assertStringContainsString('informes', $e->getMessage());
        }
        self::assertSame([], $this->historial, 'sin clave no se puede haber hecho ninguna peticion');
    }

    public function testUn401DelProveedorSeTraduceAFaltaDeClave(): void
    {
        $t = $this->traductor([new Response(401, [], '{"error":"unauthorized"}')]);
        try {
            $t->traducir('cuanto vendi', $this->vocabulario(), '2026-08-11');
            self::fail('deberia haber lanzado');
        } catch (TraduccionPreguntaException $e) {
            self::assertSame(TraduccionPreguntaException::SIN_CLAVE, $e->motivo);
        }
    }

    public function testUn429SeTraduceASinRespuestaYNoAIlegible(): void
    {
        // Un 429 es "vuelve mas tarde", no "el formato cambio". La distincion
        // decide si la pantalla sugiere reintentar.
        $t = $this->traductor([new Response(429, [], 'rate limit')]);
        try {
            $t->traducir('cuanto vendi', $this->vocabulario(), '2026-08-11');
            self::fail('deberia haber lanzado');
        } catch (TraduccionPreguntaException $e) {
            self::assertSame(TraduccionPreguntaException::SIN_RESPUESTA, $e->motivo);
        }
    }

    // =====================================================================
    // 6. QUE SE ENVIA: solo la pregunta, nada del tenant
    // =====================================================================

    public function testLaPeticionNoLlevaNingunDatoDelTenant(): void
    {
        $t = $this->traductor([$this->sobre((string) json_encode(['desenlace' => 'no_entendida']))]);
        $t->traducir('cuantas facturas emiti en julio', $this->vocabulario(), '2026-08-11');

        self::assertCount(1, $this->historial);
        $peticion = $this->historial[0]['request'];
        $crudo    = (string) $peticion->getBody();

        // (a) La pregunta del usuario va, sola, como mensaje de usuario.
        $cuerpo = $this->cuerpoEnviado();
        self::assertSame('cuantas facturas emiti en julio', $cuerpo['messages'][1]['content']);
        self::assertSame('user', $cuerpo['messages'][1]['role']);
        self::assertCount(2, $cuerpo['messages'], 'solo system + user');

        // (b) NADA DEL TENANT. Se buscan en el cuerpo CRUDO -- prompt incluido --
        //     las formas en que un dato del tenant podria colarse.
        foreach ([
            'cuenta_id', 'cuentaId', 'rut_emisor', 'rutEmisor', 'receptor_rut',
            'dte_emitido', 'SELECT', 'FROM ', 'WHERE ',
            '77724622', '76192083',
        ] as $prohibido) {
            self::assertStringNotContainsString($prohibido, $crudo,
                "la peticion lleva '{$prohibido}', que es un dato o una forma del tenant");
        }

        // (c) La clave viaja en la cabecera, NO en el cuerpo.
        self::assertSame('Bearer clave-de-prueba', $peticion->getHeaderLine('Authorization'));
        self::assertStringNotContainsString('clave-de-prueba', $crudo);

        // (d) Modo JSON pedido explicitamente, y temperatura 0.
        self::assertSame(['type' => 'json_object'], $cuerpo['response_format']);

        // LA TEMPERATURA SE COMPARA COMO NUMERO, NO CON assertSame, Y SOLO ESTA.
        //
        // Un JSON no distingue 0 de 0.0: json_encode(0.0) escribe "0" y
        // json_decode lo devuelve como int. assertSame separa int de float y
        // fallaba por eso -- no porque el valor estuviera mal.
        //
        // NO SE RELAJA EL TEST ENTERO CON assertEquals: el resto de este metodo
        // -- los mensajes, la ruta, la cabecera, lo que NO puede aparecer -- si
        // tiene que ser estricto. Aqui se comprueba en dos pasos: que sea un
        // numero (un "0" en texto NO vale, seria otro tipo en el cuerpo) y que su
        // valor sea exactamente cero.
        self::assertTrue(
            is_int($cuerpo['temperature']) || is_float($cuerpo['temperature']),
            'la temperatura tiene que viajar como numero, no como texto'
        );
        self::assertSame(0.0, (float) $cuerpo['temperature']);

        // (e) La ruta y el metodo.
        self::assertSame('POST', $peticion->getMethod());
        self::assertStringEndsWith('chat/completions', (string) $peticion->getUri());
    }

    public function testElPromptSaleDelVocabularioYNoDeUnaListaEscritaAMano(): void
    {
        $t = $this->traductor([$this->sobre((string) json_encode(['desenlace' => 'no_entendida']))]);
        $t->traducir('hola', $this->vocabulario(), '2026-08-11');
        $sistema = $this->cuerpoEnviado()['messages'][0]['content'];

        foreach (MySqlConsultaVentasRepository::METRICAS as $m) {
            self::assertStringContainsString($m, $sistema, "el prompt no ofrece la metrica {$m}");
        }
        foreach (MySqlConsultaVentasRepository::AGRUPACIONES as $a) {
            self::assertStringContainsString($a, $sistema, "el prompt no ofrece la agrupacion {$a}");
        }
        foreach (MySqlConsultaVentasRepository::ORDENES as $o) {
            self::assertStringContainsString($o, $sistema, "el prompt no ofrece el orden {$o}");
        }
        self::assertStringContainsString((string) MySqlConsultaVentasRepository::LIMITE_MAX, $sistema);
        // La fecha de referencia se pasa y no se toma del reloj: sin eso este
        // test no seria reproducible.
        self::assertStringContainsString('2026-08-11', $sistema);
    }

    /** @return array<string,mixed> */
    private function cuerpoEnviado(): array
    {
        self::assertNotEmpty($this->historial, 'no se envio ninguna peticion');
        $json = json_decode((string) $this->historial[0]['request']->getBody(), true);
        self::assertIsArray($json);

        return $json;
    }
}
