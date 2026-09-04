<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Sii\Rut;
use RuntimeException;

/**
 * Stub de invalido() PARA EL CODIGO EXTRAIDO DEL MOTOR (ver el docblock de la
 * clase). El de verdad responde 422 y hace exit; aqui lanza, que es lo unico que
 * cambia. El mensaje y el campo se conservan intactos y se comprueban.
 */
function invalido(string $error, string $campo): never
{
    throw new RuntimeException($campo . '|' . $error);
}

/**
 * LAS FORMAS DE NOTA QUE EL SII RECHAZA, ATAJADAS ANTES DE QUE CUESTEN UN FOLIO.
 *
 * Dos reglas, las dos con su costo ya medido en produccion, las dos validadas en
 * validarDocumentoDte() -- o sea ANTES de asignar folio y sin tocar el SII.
 *
 *   A. Una nota (61/56) sobre un documento SIN IVA no puede llevar lineas
 *      afectas.                          exigirLineasExentasSiLaRefEsExenta()
 *   B. Una nota de DEBITO que anula (CodRef=1) solo puede anular una nota de
 *      credito.       exigirQueLaNotaDeDebitoAnuleUnaNotaDeCredito()
 *
 * REGLA A -- DE DONDE SALE: de dos folios de produccion perdidos en tres dias,
 * de la misma serie de NC del RUT 78225195-3.
 *
 *   folio 5, 02-09-2026  RSC "Rechazado por Error en Schema" -- el RUT del
 *                        receptor viajo con puntos. Ya arreglado aparte, en
 *                        armarDocumentoEmision() con Rut::normalizar().
 *   folio 6, 04-09-2026  el sobre se proceso (EPR, TrackID 12426894426) y el
 *                        SII RECHAZO el documento (RECHAZADOS=1). La NC anulaba
 *                        la factura EXENTA 34 folio 744, de 29.990 sin IVA, y
 *                        salio con MntNeto 29.990 + IVA 5.698 = 35.688, porque
 *                        sus lineas iban afectas. La consulta individual del
 *                        documento devuelve DNK, "Datos NO Coinciden".
 *
 * En los dos casos el folio ya estaba quemado cuando llego el rechazo: se asigna
 * antes de enviar y no se devuelve.
 *
 * REGLA B -- DE DONDE SALE: de las CINCO notas de debito emitidas en produccion
 * en toda la historia de la base. Las cinco referencian una FACTURA 33 con
 * CodRef=1, y ninguna fue aceptada: dos con RECHAZADOS=1 explicito
 * (78454034-0 folios 2 y 3, 26-07-2026) y tres cuyo veredicto por documento
 * nunca se registro. Enfrente, las 21 notas de debito de CERTIFICACION -- con
 * las que el SII autorizo a estos mismos emisores -- referencian todas 61 con
 * CodRef=1. La forma buena y la mala estan medidas y no se solapan.
 *
 * COMO SE PRUEBA EL MOTOR, QUE ES UN FRONT CONTROLLER. public/index.php no se
 * puede require-ear en un test: al incluirlo despacha la ruta y llama a
 * resolverTenant(pdo()), o sea exige base de datos y una api_key. Asi que se
 * EXTRAE del archivo real el texto de la funcion y se compila con un
 * invalido() de prueba. Lo que se ejercita es el codigo que se despliega, no una
 * copia: si alguien cambia la funcion en el motor, este test corre lo nuevo.
 */
final class NotasQueElSiiRechazaTest extends TestCase
{
    private const MOTOR = __DIR__ . '/../public/index.php';
    private const PANEL = __DIR__ . '/../panel/public/index.php';

    public static function setUpBeforeClass(): void
    {
        if (function_exists(__NAMESPACE__ . '\exigirQueLaNotaDeDebitoAnuleUnaNotaDeCredito')) {
            return;
        }

        $fuente = (string) file_get_contents(self::MOTOR);
        $ok = preg_match(
            '/^function exigirLineasExentasSiLaRefEsExenta.*?\n\}\n/ms',
            $fuente,
            $m
        );
        self::assertSame(1, $ok, 'no se encontro exigirLineasExentasSiLaRefEsExenta() en el motor');

        // Se compila DENTRO del namespace de los tests: asi la llamada a
        // invalido() de la funcion resuelve al stub de arriba y no al de verdad,
        // que haria exit y se llevaria por delante la corrida entera de PHPUnit.
        eval('namespace ' . __NAMESPACE__ . ";\nuse " . TipoDte::class . ";\n" . $m[0]);

        $ok = preg_match(
            '/^function exigirQueLaNotaDeDebitoAnuleUnaNotaDeCredito.*?\n\}\n/ms',
            $fuente,
            $m
        );
        self::assertSame(1, $ok, 'no se encontro exigirQueLaNotaDeDebitoAnuleUnaNotaDeCredito() en el motor');
        eval('namespace ' . __NAMESPACE__ . ";\nuse " . TipoDte::class . ";\n" . $m[0]);

        // validarReferencias() y la validaFecha() de la que depende. Se extrae la
        // de verdad y no un stub: el formato que acepte el test tiene que ser el
        // mismo que acepte el motor.
        foreach (['validaFecha', 'validarReferencias'] as $fn) {
            $ok = preg_match('/^function ' . $fn . '\\(.*?\\n\\}\\n/ms', $fuente, $m);
            self::assertSame(1, $ok, "no se encontro {$fn}() en el motor");
            eval('namespace ' . __NAMESPACE__ . ";\n" . $m[0]);
        }

        // Y la capa del panel, por el mismo camino y por el mismo motivo:
        // panel/public/index.php es otro front controller de 19.000 lineas que
        // al incluirlo arranca sesion, base de datos y router.
        $panel = (string) file_get_contents(self::PANEL);
        $ok = preg_match('/^function armarDocumentoEmision.*?\n\}\n/ms', $panel, $m);
        self::assertSame(1, $ok, 'no se encontro armarDocumentoEmision() en el panel');
        eval(
            'namespace ' . __NAMESPACE__ . ";\n"
            . 'use ' . TipoDte::class . ";\n"
            . 'use ' . Rut::class . ";\n"
            . $m[0]
        );
    }

    /** @param list<array<string,mixed>> $detalles */
    private function validar(array $detalles, int $tipoRef, string $prefijo = ''): void
    {
        $fn = __NAMESPACE__ . '\exigirLineasExentasSiLaRefEsExenta';
        $fn($detalles, $tipoRef, $prefijo);
    }

    // -----------------------------------------------------------------------
    //  El caso que costo el folio 6
    // -----------------------------------------------------------------------

    public function testLaNcQueAnulaUnaFacturaExentaConLineasAfectasSeRechaza(): void
    {
        $this->expectException(RuntimeException::class);
        // El campo exacto importa: es lo que el formulario del panel usa para
        // resaltar el control equivocado al re-renderizar el 422.
        $this->expectExceptionMessage('detalles[0].exento|');

        $this->validar(
            [['nombre' => 'PLAN CONTABLE', 'cantidad' => 1, 'precioUnitario' => 29990]],
            34,
        );
    }

    public function testElMensajeDiceQueTipoEsYQueHayQueHacer(): void
    {
        try {
            $this->validar([['nombre' => 'PLAN CONTABLE', 'cantidad' => 1, 'precioUnitario' => 29990]], 34);
            self::fail('deberia haber rechazado');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('tipo 34', $e->getMessage());
            self::assertStringContainsString('exento=true', $e->getMessage());
            // Que se nombre el costo, porque es la razon de ser de la regla.
            self::assertStringContainsString('folio', $e->getMessage());
        }
    }

    public function testLaMismaNcConTodasSusLineasExentasPasa(): void
    {
        $this->validar(
            [['nombre' => 'PLAN CONTABLE', 'cantidad' => 1, 'precioUnitario' => 29990, 'exento' => true]],
            34,
        );
        $this->expectNotToPerformAssertions();
    }

    public function testBastaUnaSolaLineaAfectaEntreVariasExentas(): void
    {
        $this->expectException(RuntimeException::class);
        // La segunda: el indice del mensaje tiene que ser el de la linea mala.
        $this->expectExceptionMessage('detalles[1].exento|');

        $this->validar([
            ['nombre' => 'PLAN CONTABLE', 'cantidad' => 1, 'precioUnitario' => 29990, 'exento' => true],
            ['nombre' => 'ASESORIA',      'cantidad' => 1, 'precioUnitario' => 10000],
        ], 34);
    }

    public function testEnLoteElCampoLlevaElIndiceDelDocumento(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('documentos[3].detalles[0].exento|');

        $this->validar(
            [['nombre' => 'ASESORIA', 'cantidad' => 1, 'precioUnitario' => 1000]],
            34,
            'documentos[3].',
        );
    }

    // -----------------------------------------------------------------------
    //  Los tipos: cuales activan la regla y cuales no
    // -----------------------------------------------------------------------

    /** Los cuatro tipos que por definicion no llevan IVA. */
    public static function tiposSinIva(): array
    {
        return [
            'factura de venta no afecta o exenta (papel)' => [32],
            'factura exenta electronica'                  => [34],
            'boleta exenta (papel)'                       => [38],
            'boleta no afecta o exenta electronica'       => [41],
        ];
    }

    #[DataProvider('tiposSinIva')]
    public function testTodoTipoSinIvaActivaLaRegla(int $tipoRef): void
    {
        $this->expectException(RuntimeException::class);
        $this->validar([['nombre' => 'X', 'cantidad' => 1, 'precioUnitario' => 1000]], $tipoRef);
    }

    /**
     * Los tipos AFECTOS no activan nada: una NC sobre una factura 33 puede
     * llevar lineas afectas, que es el caso normal, y ninguno de estos puede
     * quedar bloqueado por el arreglo.
     */
    public static function tiposConIva(): array
    {
        return [
            'factura (papel)'          => [30],
            'factura electronica'      => [33],
            'boleta electronica'       => [39],
            'factura de compra'        => [46],
            'guia de despacho'         => [52],
            'nota de debito'           => [56],
            'nota de credito'          => [61],
        ];
    }

    #[DataProvider('tiposConIva')]
    public function testUnTipoAfectoNoObligaANada(int $tipoRef): void
    {
        $this->validar([['nombre' => 'X', 'cantidad' => 1, 'precioUnitario' => 1000]], $tipoRef);
        $this->expectNotToPerformAssertions();
    }

    /**
     * NO SE HACE AL REVES. Una nota EXENTA sobre una factura 33 es legitima: un
     * 33 puede traer lineas exentas y la nota puede corregir justo esas. Si la
     * regla fuera simetrica, romperia ese caso.
     */
    public function testUnaNotaExentaSobreUnaFacturaAfectaSeAcepta(): void
    {
        $this->validar([['nombre' => 'SERVICIO EXENTO', 'cantidad' => 1, 'precioUnitario' => 1000, 'exento' => true]], 33);
        $this->expectNotToPerformAssertions();
    }

    // -----------------------------------------------------------------------
    //  La lista vive en un solo lugar
    // -----------------------------------------------------------------------

    public function testElEnumEsLaUnicaFuenteDeLaLista(): void
    {
        self::assertSame([32, 34, 38, 41], TipoDte::SIN_IVA);
        foreach (TipoDte::SIN_IVA as $tipo) {
            self::assertTrue(TipoDte::esSinIva($tipo));
        }
        self::assertFalse(TipoDte::esSinIva(33));
        self::assertFalse(TipoDte::esSinIva(39));
    }

    /**
     * 32 y 38 NO son casos del enum -- son documentos en papel que este sistema
     * no emite --, y aun asi tienen que responder a la pregunta. Es la razon de
     * que esSinIva() reciba un int y no un TipoDte: from() reventaria.
     */
    public function testLosTiposDePapelRespondenAunqueElEnumNoLosModele(): void
    {
        self::assertNull(TipoDte::tryFrom(32));
        self::assertNull(TipoDte::tryFrom(38));
        self::assertTrue(TipoDte::esSinIva(32));
        self::assertTrue(TipoDte::esSinIva(38));
    }

    /**
     * Ni el motor ni el panel pueden volver a escribir la lista a mano: fue una
     * copia por archivo lo que hizo falta borrar para dejarla en el enum.
     */
    // -----------------------------------------------------------------------
    //  La capa del panel: el usuario no deberia tener que saber esta regla
    // -----------------------------------------------------------------------

    /** @param array<string,mixed> $post */
    private function armar(int $tipoDte, array $post): array
    {
        $fn = __NAMESPACE__ . '\armarDocumentoEmision';

        return $fn($tipoDte, $post);
    }

    /** El POST tal cual lo mandaba el formulario el 04-09-2026, folio 6. */
    private static function postDelFolio6(): array
    {
        return [
            'receptor' => [
                'rut'         => '78.447.717-7',
                'razonSocial' => 'SERVICIOS DE SALUD SEXUAL Y REPRODUCTIVA CATADEL PINO SpA',
                'giro'        => 'SALUD',
                'direccion'   => 'VALDIVIA',
                'comuna'      => 'VALDIVIA',
            ],
            // SIN 'exento': la casilla habla de la NOTA y el usuario esta
            // pensando en la factura que anula, asi que no la marca nadie.
            'detalles' => [
                ['nombre' => 'PLAN CONTABLE', 'cantidad' => '1', 'precioUnitario' => '29990'],
            ],
            'referencias' => [
                [
                    'tipoDocumento' => '34',
                    'folio'         => '744',
                    'fecha'         => '2026-09-02',
                    'codigo'        => '1',
                    'razon'         => 'Anula documento N 744',
                ],
            ],
        ];
    }

    public function testElPanelMarcaExentaLaNcQueAnulaUnaFacturaExentaAunqueNadieLoPida(): void
    {
        $doc = $this->armar(61, self::postDelFolio6());

        self::assertTrue($doc['detalles'][0]['exento']);
        // Y sale un payload que el motor acepta: si el panel no lo marcara, el
        // 422 de la regla de arriba lo frenaria -- pero el usuario tendria que
        // adivinar la correccion.
        $this->validar($doc['detalles'], 34);
    }

    public function testLaMismaNcSobreUnaFacturaAfectaRespetaLoQueElUsuarioMarco(): void
    {
        $post = self::postDelFolio6();
        $post['referencias'][0]['tipoDocumento'] = '33';

        $doc = $this->armar(61, $post);
        self::assertFalse($doc['detalles'][0]['exento']);

        $post['detalles'][0]['exento'] = '1';
        $doc = $this->armar(61, $post);
        self::assertTrue($doc['detalles'][0]['exento']);
    }

    public function testUnaFacturaNormalNoSeVeAfectadaPorLaRegla(): void
    {
        $post = self::postDelFolio6();
        unset($post['referencias']);

        $doc = $this->armar(33, $post);
        self::assertFalse($doc['detalles'][0]['exento']);
    }

    /**
     * El RUT con puntos, que es lo que costo el folio 5. Va aqui porque es el
     * mismo formulario y la misma serie: si alguien deshace la normalizacion,
     * este test lo dice antes que el SII.
     */
    public function testElRutDelReceptorSigueSaliendoSinPuntos(): void
    {
        $doc = $this->armar(61, self::postDelFolio6());
        self::assertSame('78447717-7', $doc['receptor']['rut']);
    }

    // -----------------------------------------------------------------------
    //  La nota de DEBITO que anula: solo puede anular una nota de credito
    // -----------------------------------------------------------------------

    private function validarNd(int $tipoDte, int $tipoRef, int $codRef, string $prefijo = ''): void
    {
        $fn = __NAMESPACE__ . '\\exigirQueLaNotaDeDebitoAnuleUnaNotaDeCredito';
        $fn($tipoDte, $tipoRef, $codRef, $prefijo);
    }

    /**
     * Los cinco folios de nota de debito emitidos en produccion: todos contra
     * una factura 33 con CodRef=1, ninguno aceptado por el SII.
     */
    public function testLaNdQueAnulaUnaFacturaSeRechaza(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('referencias|');
        $this->validarNd(56, 33, 1);
    }

    public function testElMensajeOfreceLaSalidaCorrecta(): void
    {
        try {
            $this->validarNd(56, 33, 1);
            self::fail('deberia haber rechazado');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('CodRef=3', $e->getMessage());
            self::assertStringContainsString('NOTA DE CREDITO', $e->getMessage());
            self::assertStringContainsString('folio', $e->getMessage());
        }
    }

    /**
     * La forma correcta, y la que usan las 21 notas de debito de certificacion
     * con las que el SII autorizo a estos emisores: 61 con CodRef=1.
     */
    public function testLaNdQueAnulaUnaNotaDeCreditoPasa(): void
    {
        $this->validarNd(56, 61, 1);
        $this->validarNd(56, 60, 1); // nota de credito en papel
        $this->expectNotToPerformAssertions();
    }

    /** Con CodRef=3 una ND puede referenciar una factura: es el unico codigo que se lo permite. */
    public function testLaNdQueCorrigeMontosDeUnaFacturaPasa(): void
    {
        $this->validarNd(56, 33, 3);
        $this->validarNd(56, 34, 3);
        $this->expectNotToPerformAssertions();
    }

    /**
     * CodRef=2 no se toca: la ayuda del formulario dice que corregir texto es
     * solo de la nota de credito, pero no hay ni un caso medido, y una guarda
     * inventada bloquea emisiones legitimas.
     */
    public function testLaNdConCorrigeTextoNoSeRechazaAqui(): void
    {
        $this->validarNd(56, 33, 2);
        $this->expectNotToPerformAssertions();
    }

    /** En la NOTA DE CREDITO anular una factura es el caso normal: no se toca. */
    public function testLaNcQueAnulaUnaFacturaNoSeVeAfectada(): void
    {
        foreach ([33, 34, 39, 41, 46, 56] as $tipoRef) {
            $this->validarNd(61, $tipoRef, 1);
        }
        $this->expectNotToPerformAssertions();
    }

    public function testEnLoteElCampoLlevaElIndiceDelDocumentoTambienAqui(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('documentos[2].referencias|');
        $this->validarNd(56, 33, 1, 'documentos[2].');
    }

    // -----------------------------------------------------------------------
    //  La FORMA de cada referencia: lo que exige el esquema del SII
    // -----------------------------------------------------------------------

    /** @param list<array<string,mixed>> $refs */
    private function validarRefs(array $refs, string $prefijo = ''): void
    {
        $fn = __NAMESPACE__ . '\\validarReferencias';
        $fn($refs, $prefijo);
    }

    private static function refCompleta(array $cambios = []): array
    {
        return $cambios + ['tipoDocumento' => 33, 'folio' => 744, 'fecha' => '2026-09-02', 'codigo' => 1, 'razon' => 'Anula documento N 744'];
    }

    public function testUnaReferenciaCompletaPasa(): void
    {
        $this->validarRefs([self::refCompleta()]);
        $this->expectNotToPerformAssertions();
    }

    /**
     * EL CASO QUE MOTIVO ESTO. El formulario ofrecia la fecha como opcional y el
     * esquema del SII la exige: sin FchRef el sobre vuelve RSC con el folio ya
     * gastado. Medido ejecutando DTE_v10.xsd contra el XML del builder.
     */
    public function testUnaReferenciaSinFechaSeRechaza(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('referencias[0].fecha|');
        $refs = self::refCompleta();
        unset($refs['fecha']);
        $this->validarRefs([$refs]);
    }

    public function testUnaFechaConFormatoChilenoSeRechaza(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('referencias[0].fecha|');
        $this->validarRefs([self::refCompleta(['fecha' => '02-09-2026'])]);
    }

    public function testSinTipoDeDocumentoSeRechaza(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('referencias[0].tipoDocumento|');
        $refs = self::refCompleta();
        unset($refs['tipoDocumento']);
        $this->validarRefs([$refs]);
    }

    public function testUnTipoDeMasDeTresCaracteresSeRechaza(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('referencias[0].tipoDocumento|');
        $this->validarRefs([self::refCompleta(['tipoDocumento' => 'SETX'])]);
    }

    public function testSinFolioSeRechaza(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('referencias[0].folio|');
        $refs = self::refCompleta();
        unset($refs['folio']);
        $this->validarRefs([$refs]);
    }

    /**
     * @param mixed $codigo
     */
    #[DataProvider('codigosInvalidos')]
    public function testUnCodRefFueraDeLaEnumeracionSeRechaza(mixed $codigo): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('referencias[0].codigo|');
        $this->validarRefs([self::refCompleta(['codigo' => $codigo])]);
    }

    public static function codigosInvalidos(): array
    {
        return ['cuatro' => [4], 'cero' => [0], 'negativo' => [-1], 'texto' => ['anula']];
    }

    public function testLosTresCodigosDelEsquemaPasan(): void
    {
        foreach ([1, 2, 3, '1', '2', '3'] as $cod) {
            $this->validarRefs([self::refCompleta(['codigo' => $cod])]);
        }
        // Y sin codigo tambien: CodRef es opcional en el esquema.
        $refs = self::refCompleta();
        unset($refs['codigo']);
        $this->validarRefs([$refs]);
        $this->expectNotToPerformAssertions();
    }

    public function testLaRazonSePuedeUsarHastaLos90Caracteres(): void
    {
        $this->validarRefs([self::refCompleta(['razon' => str_repeat('A', 90)])]);
        $this->expectNotToPerformAssertions();
    }

    public function testUnaRazonDe91CaracteresSeRechaza(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('referencias[0].razon|');
        $this->validarRefs([self::refCompleta(['razon' => str_repeat('A', 91)])]);
    }

    public function testElTopeDe40ReferenciasDelEsquema(): void
    {
        $this->validarRefs(array_fill(0, 40, self::refCompleta()));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('referencias|');
        $this->validarRefs(array_fill(0, 41, self::refCompleta()));
    }

    public function testElIndiceDelCampoSenalaLaReferenciaMala(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('documentos[7].referencias[2].fecha|');
        $mala = self::refCompleta();
        unset($mala['fecha']);
        $this->validarRefs([self::refCompleta(), self::refCompleta(), $mala], 'documentos[7].');
    }

    // --- Las dos formas que NO se pueden romper -----------------------------

    /**
     * AUTO-REFERENCIA AL SET DE CERTIFICACION: llega SIN folio, porque
     * DteXmlBuilder::buildReferencia() usa el folio PROPIO del documento. Es
     * literalmente la forma que arma SetBasicoPayloadBuilder para los tres tipos
     * del set basico, y pasa por esta validacion en cada certificacion. Si este
     * test se pone rojo, no se puede certificar a ningun emisor nuevo.
     */
    public function testLaAutoReferenciaAlSetDeCertificacionPasaSinFolio(): void
    {
        $this->validarRefs([[
            'tipoDocumento' => 'SET',
            'fecha'         => '2026-07-13',
            'razon'         => 'CASO 4860965-1',
        ]]);
        $this->expectNotToPerformAssertions();
    }

    /**
     * REFERENCIA INTRA-LOTE: llega SIN tipoDocumento, folio ni fecha. Los inyecta
     * emitirDteLote() DESPUES de validar, cuando ya sabe que folio le toco al
     * documento referenciado. Exigirlos aqui rompe el set basico entero.
     */
    public function testLaReferenciaIntraLotePasaSinTipoFolioNiFecha(): void
    {
        $this->validarRefs([[
            'refIndiceLote' => 0,
            'codigo'        => '1',
            'razon'         => 'ANULA FACTURA',
        ]]);
        $this->expectNotToPerformAssertions();
    }

    /** Pero a la intra-lote SI se le miran codigo y razon, que si viajan. */
    public function testALaIntraLoteSeLeSigueValidandoElCodigo(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('referencias[0].codigo|');
        $this->validarRefs([['refIndiceLote' => 0, 'codigo' => 9]]);
    }

    public function testNingunFrontControllerRepiteLaListaDeTipos(): void
    {
        foreach ([self::MOTOR, __DIR__ . '/../panel/public/index.php'] as $archivo) {
            $fuente = (string) file_get_contents($archivo);
            self::assertDoesNotMatchRegularExpression(
                '/\[\s*32\s*,\s*34\s*,\s*38\s*,\s*41\s*\]/',
                $fuente,
                basename(dirname($archivo, 2)) . '/' . basename($archivo)
                    . ' repite la lista de tipos sin IVA: usa TipoDte::SIN_IVA.'
            );
        }
    }
}
