<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Dto\CredencialesPasarela;
use Plantiflex\FacturacionCl\Dto\SolicitudPago;
use Plantiflex\FacturacionCl\Exceptions\PasarelaNoConfiguradaException;
use Plantiflex\FacturacionCl\Exceptions\PasarelaPermanenteException;
use Plantiflex\FacturacionCl\Exceptions\PasarelaTransitoriaException;
use Plantiflex\FacturacionCl\Pago\FabricaPasarela;
use Plantiflex\FacturacionCl\Providers\FlowPasarelaPago;

/**
 * Tests del cobro por link contra Flow, sin tocar la red.
 *
 * LO QUE MAS IMPORTA AQUI NO ES EL CAMINO FELIZ. Esta clase mueve dinero de un
 * tercero, asi que lo que hay que fijar con tests es:
 *
 *   - que la FIRMA se calcule exactamente como Flow la espera (un error da 401 y
 *     ninguna pista de cual de las tres reglas se rompio);
 *   - que el MONTO viaje como entero puro, porque "49.990" firmaria mal Y
 *     cobraria mal;
 *   - que cada fallo se clasifique en transitorio o permanente, que es EL DATO
 *     con el que despues se decide si una factura espera o se aparca.
 */
final class FlowPasarelaPagoTest extends TestCase
{
    private const API_KEY = 'llave-publica-del-comercio';
    private const SECRETO = 'secreto-que-nunca-se-imprime';

    /** @var list<Request> */
    private array $peticiones = [];

    private function pasarela(array $respuestas): FlowPasarelaPago
    {
        $this->peticiones = [];
        $stack = HandlerStack::create(new MockHandler($respuestas));
        $stack->push(Middleware::history($this->peticiones));

        return new FlowPasarelaPago(new Client(['handler' => $stack]));
    }

    private function credenciales(bool $sandbox = false): CredencialesPasarela
    {
        return new CredencialesPasarela(self::API_KEY, self::SECRETO, $sandbox);
    }

    private function solicitud(int $monto = 49990): SolicitudPago
    {
        return new SolicitudPago(
            referencia: 'SIN-7-33-745',
            monto: $monto,
            asunto: 'Factura electronica N 745',
            emailPagador: 'cliente@ejemplo.cl',
            urlConfirmacion: 'https://facturacion.sinergiaia.cl/pagos/flow/confirmacion',
            urlRetorno: 'https://facturacion.sinergiaia.cl/pagos/gracias',
        );
    }

    private function respuestaOk(): Response
    {
        return new Response(200, [], (string) json_encode([
            'url'       => 'https://www.flow.cl/app/web/pay.php',
            'token'     => '33373581FC32576FAF33C46FC6454B1FFEBD7E1H',
            'flowOrder' => 8765456,
        ]));
    }

    /** @return array<string,string> los parametros del form que se enviaron */
    private function parametrosEnviados(): array
    {
        parse_str((string) $this->peticiones[0]['request']->getBody(), $params);

        return array_map('strval', $params);
    }

    // -----------------------------------------------------------------------
    //  Camino feliz
    // -----------------------------------------------------------------------

    public function testDevuelveElLinkArmadoComoLoDocumentaFlow(): void
    {
        $orden = $this->pasarela([$this->respuestaOk()])
            ->crearOrden($this->solicitud(), $this->credenciales());

        // El link del pagador es url + '?token=' + token. Ese armado es de Flow.
        self::assertSame(
            'https://www.flow.cl/app/web/pay.php?token=33373581FC32576FAF33C46FC6454B1FFEBD7E1H',
            $orden->url
        );
        self::assertSame('8765456', $orden->ordenExterna, 'flowOrder es por donde la buscara la confirmacion');
    }

    public function testSinFlowOrderSeQuedaConElTokenComoIdentificador(): void
    {
        $sinFlowOrder = new Response(200, [], (string) json_encode([
            'url'   => 'https://www.flow.cl/app/web/pay.php',
            'token' => 'TOKEN123',
        ]));

        $orden = $this->pasarela([$sinFlowOrder])->crearOrden($this->solicitud(), $this->credenciales());

        // Quedarse sin ningun identificador dejaria una orden que la confirmacion
        // no sabria encontrar.
        self::assertSame('TOKEN123', $orden->ordenExterna);
    }

    // -----------------------------------------------------------------------
    //  La firma
    // -----------------------------------------------------------------------

    public function testLaFirmaSigueElAlgoritmoDeFlow(): void
    {
        $this->pasarela([$this->respuestaOk()])->crearOrden($this->solicitud(), $this->credenciales());

        $enviados = $this->parametrosEnviados();
        $firma    = $enviados['s'];
        unset($enviados['s']);

        // Se rehace a mano, sin usar el helper, para que el test compruebe el
        // algoritmo y no se limite a repetir la implementacion.
        ksort($enviados, SORT_STRING);
        $aFirmar = '';
        foreach ($enviados as $k => $v) {
            $aFirmar .= $k . $v;
        }

        self::assertSame(hash_hmac('sha256', $aFirmar, self::SECRETO), $firma);
    }

    public function testLaFirmaNoSeIncluyeASiMisma(): void
    {
        // Si 's' entrara en lo que se firma, la firma dependeria de si misma y
        // nunca cuadraria del lado de Flow.
        $params = ['b' => '2', 'a' => '1', 's' => 'basura-previa'];

        self::assertSame(
            hash_hmac('sha256', 'a1b2', self::SECRETO),
            FlowPasarelaPago::firmar($params, self::SECRETO)
        );
    }

    public function testLaFirmaOrdenaLasClavesAlfabeticamenteYNoPorComoSeEscribieron(): void
    {
        self::assertSame(
            FlowPasarelaPago::firmar(['a' => '1', 'b' => '2'], self::SECRETO),
            FlowPasarelaPago::firmar(['b' => '2', 'a' => '1'], self::SECRETO)
        );
    }

    // -----------------------------------------------------------------------
    //  El monto
    // -----------------------------------------------------------------------

    public function testElMontoViajaComoEnteroPuroSinSeparadores(): void
    {
        $this->pasarela([$this->respuestaOk()])
            ->crearOrden($this->solicitud(1234567), $this->credenciales());

        // "1.234.567" o "1234567.00" firmarian mal Y cobrarian mal.
        self::assertSame('1234567', $this->parametrosEnviados()['amount']);
    }

    public function testUnMontoCeroONegativoNiSiquieraSaleDeCasa(): void
    {
        $this->expectException(\Plantiflex\FacturacionCl\Exceptions\DocumentoInvalidoException::class);
        $this->solicitud(0);
    }

    // -----------------------------------------------------------------------
    //  La referencia: la defensa contra el doble cobro
    // -----------------------------------------------------------------------

    public function testLaReferenciaViajaComoCommerceOrder(): void
    {
        $this->pasarela([$this->respuestaOk()])->crearOrden($this->solicitud(), $this->credenciales());

        // Es lo que hace idempotente el reintento: Flow trata commerceOrder como
        // clave del comercio y no crea una segunda orden con la misma.
        self::assertSame('SIN-7-33-745', $this->parametrosEnviados()['commerceOrder']);
    }

    public function testDosLlamadasConLaMismaSolicitudMandanLaMismaReferencia(): void
    {
        $pasarela = $this->pasarela([$this->respuestaOk(), $this->respuestaOk()]);
        $pasarela->crearOrden($this->solicitud(), $this->credenciales());
        $primera = $this->parametrosEnviados()['commerceOrder'];

        parse_str((string) $this->peticiones[0]['request']->getBody(), $p1);
        $pasarela->crearOrden($this->solicitud(), $this->credenciales());
        parse_str((string) $this->peticiones[1]['request']->getBody(), $p2);

        self::assertSame($p1['commerceOrder'], $p2['commerceOrder']);
        self::assertSame('SIN-7-33-745', $primera);
    }

    // -----------------------------------------------------------------------
    //  Clasificacion de fallos: transitorio contra permanente
    // -----------------------------------------------------------------------

    public function testUnFalloDeConexionEsTransitorio(): void
    {
        $this->expectException(PasarelaTransitoriaException::class);

        $this->pasarela([new ConnectException('sin ruta al host', new Request('POST', 'x'))])
            ->crearOrden($this->solicitud(), $this->credenciales());
    }

    public function testUn500EsTransitorio(): void
    {
        $this->expectException(PasarelaTransitoriaException::class);

        $this->pasarela([new Response(500, [], 'Internal Server Error')])
            ->crearOrden($this->solicitud(), $this->credenciales());
    }

    public function testUn429EsTransitorio(): void
    {
        $this->expectException(PasarelaTransitoriaException::class);

        $this->pasarela([new Response(429, [], 'Too Many Requests')])
            ->crearOrden($this->solicitud(), $this->credenciales());
    }

    public function testCredencialesRechazadasEsPermanente(): void
    {
        // Reintentar una clave mal pegada cada 5 minutos no la va a arreglar.
        $this->expectException(PasarelaPermanenteException::class);

        $this->pasarela([new Response(401, [], (string) json_encode(['message' => 'Invalid api key']))])
            ->crearOrden($this->solicitud(), $this->credenciales());
    }

    public function testUnaRespuestaQueNoEsJsonEsPermanente(): void
    {
        $this->expectException(PasarelaPermanenteException::class);

        $this->pasarela([new Response(200, [], '<html>mantenimiento</html>')])
            ->crearOrden($this->solicitud(), $this->credenciales());
    }

    public function testUnJsonSinUrlNiTokenEsPermanente(): void
    {
        $this->expectException(PasarelaPermanenteException::class);

        $this->pasarela([new Response(200, [], (string) json_encode(['flowOrder' => 1]))])
            ->crearOrden($this->solicitud(), $this->credenciales());
    }

    // -----------------------------------------------------------------------
    //  Sandbox, credenciales y fabrica
    // -----------------------------------------------------------------------

    public function testSandboxYProduccionVanADominiosDistintos(): void
    {
        $this->pasarela([$this->respuestaOk()])->crearOrden($this->solicitud(), $this->credenciales(sandbox: true));
        self::assertSame(
            'https://sandbox.flow.cl/api/payment/create',
            (string) $this->peticiones[0]['request']->getUri()
        );

        $this->pasarela([$this->respuestaOk()])->crearOrden($this->solicitud(), $this->credenciales(sandbox: false));
        self::assertSame(
            'https://www.flow.cl/api/payment/create',
            (string) $this->peticiones[0]['request']->getUri()
        );
    }

    public function testUnasCredencialesIncompletasNoSePuedenNiConstruir(): void
    {
        $this->expectException(PasarelaNoConfiguradaException::class);
        new CredencialesPasarela(self::API_KEY, '   ');
    }

    public function testElSecretoNoSeFiltraAlVolcarLasCredenciales(): void
    {
        // Un var_dump en un diagnostico, o una traza de excepcion, no pueden
        // dejar escrita la llave con la que se cobra.
        $volcado = print_r($this->credenciales(), true);

        self::assertStringNotContainsString(self::SECRETO, $volcado);
        self::assertStringContainsString('oculto', $volcado);
    }

    public function testLaFabricaDevuelveFlow(): void
    {
        self::assertSame('flow', FabricaPasarela::crear('flow')->nombre());
        self::assertSame(['flow'], FabricaPasarela::proveedores());
    }

    public function testLaFabricaFallaCerradoAnteUnProveedorDesconocido(): void
    {
        // Nunca se hace new sobre texto que viene de la base.
        $this->expectException(PasarelaPermanenteException::class);
        FabricaPasarela::crear('pasarela-inventada');
    }
}
