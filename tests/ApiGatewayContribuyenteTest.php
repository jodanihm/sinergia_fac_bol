<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\ConnectException;
use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Exceptions\ConsultaContribuyenteException;
use Plantiflex\FacturacionCl\Providers\ApiGatewayContribuyente;
use Psr\Http\Message\RequestInterface;

/**
 * El cliente de consulta de contribuyente, contra un MockHandler.
 *
 * NINGUNA PETICION REAL: el proveedor cobra por consulta y ademas el test no
 * puede depender de la red. La respuesta del fixture es una REAL, capturada de
 * la consulta del RUT 78454034-0.
 *
 * Se verifica tanto lo que se PARSEA como lo que se ENVIA: el prefijo literal
 * "Token" de la cabecera Authorization es el tipo de detalle que se rompe en
 * silencio -- da 401 y parece "el proveedor no responde" -- asi que se
 * comprueba con Middleware::history en vez de confiar en que esta bien escrito.
 */
final class ApiGatewayContribuyenteTest extends TestCase
{
    private const TOKEN = 'token-de-prueba-no-real';

    /** Respuesta REAL del proveedor para 78454034-0. */
    private const RESPUESTA_REAL = <<<'JSON'
        {"data":{"rut":"78454034-0","autorizado":true,"razon_social":"SINERGIA INNOVACIÓN APLICADA SPA","resolucion":{"numero":80,"fecha":"2014-08-22"},"direccion_regional":"XIX","software":"mercado","documentos":[{"codigo":33,"descripcion":"FACTURA ELECTRONICA","autorizado":"2026-07-15","desautorizado":null},{"codigo":34,"descripcion":"FACTURA NO AFECTA O EXENTA ELECTRONICA","autorizado":"2026-07-15","desautorizado":null},{"codigo":52,"descripcion":"GUIA DESPACHO ELECTRONICA","autorizado":"2026-07-15","desautorizado":null},{"codigo":56,"descripcion":"NOTA DEBITO ELECTRONICA","autorizado":"2026-07-15","desautorizado":null},{"codigo":61,"descripcion":"NOTA CREDITO ELECTRONICA","autorizado":"2026-07-15","desautorizado":null}]},"metadata":{"timestamp":"2026-08-06T16:29:01.539221-04:00"}}
        JSON;

    /** @var list<array{request: RequestInterface, response: mixed}> */
    private array $historial = [];

    /**
     * @param list<Response|\Throwable> $respuestas
     */
    private function consultor(array $respuestas, string $token = self::TOKEN): ApiGatewayContribuyente
    {
        $this->historial = [];
        $stack = HandlerStack::create(new MockHandler($respuestas));
        $stack->push(Middleware::history($this->historial));

        return new ApiGatewayContribuyente(
            new Client([
                'handler'     => $stack,
                'base_uri'    => ApiGatewayContribuyente::BASE_URL,
                'http_errors' => false,
            ]),
            $token,
        );
    }

    public function testParseaLaRespuestaRealCompleta(): void
    {
        $datos = $this->consultor([new Response(200, [], self::RESPUESTA_REAL)])
            ->consultar('78454034-0');

        self::assertSame('78454034-0', $datos->rut);
        self::assertTrue($datos->autorizado);
        self::assertSame('SINERGIA INNOVACIÓN APLICADA SPA', $datos->razonSocial);
        self::assertSame(80, $datos->resolucionNumero);
        self::assertSame('2014-08-22', $datos->resolucionFecha);
        self::assertSame('XIX', $datos->direccionRegional);
        self::assertSame('mercado', $datos->software);
    }

    public function testParseaLosCincoTiposAutorizados(): void
    {
        $datos = $this->consultor([new Response(200, [], self::RESPUESTA_REAL)])
            ->consultar('78454034-0');

        self::assertCount(5, $datos->documentos);
        self::assertSame([33, 34, 52, 56, 61], $datos->codigosVigentes());
        self::assertSame('FACTURA ELECTRONICA', $datos->documentos[0]->descripcion);
        self::assertSame('2026-07-15', $datos->documentos[0]->fechaAutorizacion);
        self::assertNull($datos->documentos[0]->fechaDesautorizacion);
        self::assertTrue($datos->documentos[0]->vigente());
    }

    /**
     * QUE PETICION SE ENVIA. El prefijo "Token" y la URL exacta se comprueban
     * aqui porque son lo que el proveedor rechaza en silencio si esta mal.
     */
    public function testEnviaLaPeticionCorrecta(): void
    {
        $this->consultor([new Response(200, [], self::RESPUESTA_REAL)])->consultar('78454034-0');

        self::assertCount(1, $this->historial, 'una consulta = una peticion (cada una cuesta creditos)');
        $peticion = $this->historial[0]['request'];

        self::assertSame('GET', $peticion->getMethod());
        self::assertSame(
            'https://app.apigateway.cl/api/v2/sii/dte/contribuyentes/autorizado/78454034-0',
            (string) $peticion->getUri(),
        );
        self::assertSame('Token ' . self::TOKEN, $peticion->getHeaderLine('Authorization'));
        self::assertStringStartsWith('Token ', $peticion->getHeaderLine('Authorization'));
        self::assertStringNotContainsString('Bearer', $peticion->getHeaderLine('Authorization'));
        self::assertSame('application/json', $peticion->getHeaderLine('Accept'));
    }

    public function testSinTokenNoConsultaYDiceElMotivo(): void
    {
        try {
            $this->consultor([new Response(200, [], self::RESPUESTA_REAL)], '')->consultar('78454034-0');
            self::fail('deberia haber lanzado');
        } catch (ConsultaContribuyenteException $e) {
            self::assertSame(ConsultaContribuyenteException::SIN_TOKEN, $e->motivo);
            self::assertStringContainsString('APIGATEWAY_TOKEN', $e->getMessage());
        }

        // Y NO se gasto ninguna peticion: sin credencial no tiene sentido salir.
        self::assertCount(0, $this->historial);
    }

    public function testConexionCaidaDaSinRespuesta(): void
    {
        $consultor = $this->consultor([
            new ConnectException('cURL error 28: Operation timed out', new Request('GET', 'x')),
        ]);

        try {
            $consultor->consultar('78454034-0');
            self::fail('deberia haber lanzado');
        } catch (ConsultaContribuyenteException $e) {
            self::assertSame(ConsultaContribuyenteException::SIN_RESPUESTA, $e->motivo);
        }
    }

    /**
     * "autorizado": false NO es una excepcion. Es una respuesta valida que la
     * pantalla tiene que mostrar: le dice al usuario que ese RUT no puede
     * emitir, que es justo lo que vino a averiguar.
     */
    public function testNoAutorizadoNoLanzaYLlegaComoDato(): void
    {
        $cuerpo = json_encode([
            'data' => [
                'rut'          => '11111111-1',
                'autorizado'   => false,
                'razon_social' => 'EMPRESA SIN HABILITAR SPA',
                'resolucion'   => ['numero' => null, 'fecha' => null],
                'documentos'   => [],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $datos = $this->consultor([new Response(200, [], (string) $cuerpo)])->consultar('11111111-1');

        self::assertFalse($datos->autorizado);
        self::assertSame('EMPRESA SIN HABILITAR SPA', $datos->razonSocial);
        self::assertNull($datos->resolucionNumero);
        self::assertSame([], $datos->codigosVigentes());
    }

    public function testUn401DelProveedorDaSinRespuesta(): void
    {
        try {
            $this->consultor([new Response(401, [], '{"detail":"Invalid token."}')])->consultar('78454034-0');
            self::fail('deberia haber lanzado');
        } catch (ConsultaContribuyenteException $e) {
            self::assertSame(ConsultaContribuyenteException::SIN_RESPUESTA, $e->motivo);
            self::assertStringContainsString('401', $e->getMessage());
        }
    }

    public function testCuerpoSinDataDaRespuestaIlegible(): void
    {
        try {
            $this->consultor([new Response(200, [], '{"cualquier":"cosa"}')])->consultar('78454034-0');
            self::fail('deberia haber lanzado');
        } catch (ConsultaContribuyenteException $e) {
            self::assertSame(ConsultaContribuyenteException::RESPUESTA_ILEGIBLE, $e->motivo);
        }
    }

    /** Un tipo desautorizado sigue en el arreglo pero NO cuenta como vigente. */
    public function testUnTipoDesautorizadoNoCuentaComoVigente(): void
    {
        $cuerpo = json_encode([
            'data' => [
                'rut' => '78454034-0', 'autorizado' => true, 'razon_social' => 'X',
                'resolucion' => ['numero' => 0, 'fecha' => '2026-07-17'],
                'documentos' => [
                    ['codigo' => 33, 'descripcion' => 'FACTURA', 'autorizado' => '2026-01-01', 'desautorizado' => null],
                    ['codigo' => 52, 'descripcion' => 'GUIA', 'autorizado' => '2026-01-01', 'desautorizado' => '2026-06-30'],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $datos = $this->consultor([new Response(200, [], (string) $cuerpo)])->consultar('78454034-0');

        self::assertCount(2, $datos->documentos);
        self::assertSame([33], $datos->codigosVigentes());
        // resolucion.numero = 0 es legitimo (certificacion): NO se convierte en null.
        self::assertSame(0, $datos->resolucionNumero);
    }
}
