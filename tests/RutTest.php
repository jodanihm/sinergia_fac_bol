<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plantiflex\FacturacionCl\Dto\Detalle;
use Plantiflex\FacturacionCl\Dto\DocumentoTributario;
use Plantiflex\FacturacionCl\Dto\Receptor;
use Plantiflex\FacturacionCl\Enums\TipoDte;
use Plantiflex\FacturacionCl\Sii\Rut;

/**
 * Tests de la forma canonica del RUT.
 *
 * DE DONDE SALEN: de un rechazo real. El 02-09-2026 la nota de credito folio 5
 * volvio del SII con RSC ("Rechazado por Error en Schema") porque el RUT del
 * receptor viajo con puntos -- "78.159.082-7" -- y SiiTypes_v10.xsd exige
 * [0-9]+-([0-9]|K) con un maximo de 10 caracteres. El folio se gasto igual.
 *
 * LO QUE ESTOS TESTS PROTEGEN NO ES EL ALGORITMO, que es trivial y llevaba
 * anios funcionando. Es la DISTINCION entre las dos preguntas que el sistema
 * confundia: "este RUT existe" (modulo 11) y "este RUT se puede enviar"
 * (formato). Las dos validaciones que habia contestaban la primera quitando los
 * puntos, y por eso decian que si de un RUT que el SII no podia aceptar.
 */
final class RutTest extends TestCase
{
    // -----------------------------------------------------------------------
    //  normalizar(): deja el RUT como hay que escribirlo
    // -----------------------------------------------------------------------

    /**
     * @return list<array{string,string}>
     */
    public static function casosNormalizar(): array
    {
        return [
            'el caso real que rechazo el SII' => ['78.159.082-7', '78159082-7'],
            'ya canonico, no se toca'         => ['78159082-7', '78159082-7'],
            'k minuscula'                     => ['12345678-k', '12345678-K'],
            'puntos y k minuscula'            => ['12.345.678-k', '12345678-K'],
            'espacios alrededor'              => ['  77724622-4  ', '77724622-4'],
            'espacios interiores'             => ['77 724 622-4', '77724622-4'],
            'vacio sigue vacio'               => ['', ''],
        ];
    }

    #[DataProvider('casosNormalizar')]
    public function testNormalizarDejaElRutComoHayQueEscribirlo(string $entrada, string $esperado): void
    {
        self::assertSame($esperado, Rut::normalizar($entrada));
    }

    /** normalizar() NO opina sobre si el RUT existe: esa es otra pregunta. */
    public function testNormalizarNoValidaNiLanza(): void
    {
        self::assertSame('HOLA', Rut::normalizar(' hola '));
    }

    // -----------------------------------------------------------------------
    //  bienFormado(): la pregunta que faltaba
    // -----------------------------------------------------------------------

    public function testBienFormadoRechazaElRutConPuntos(): void
    {
        // EL TEST QUE HABRIA EVITADO EL RECHAZO. Sin normalizar, un RUT con
        // puntos no se puede enviar, y decirlo es justo lo que ninguna de las
        // dos validaciones anteriores hacia.
        self::assertFalse(Rut::bienFormado('78.159.082-7'));
        self::assertTrue(Rut::bienFormado('78159082-7'));
    }

    public function testBienFormadoNoMiraElDigitoVerificador(): void
    {
        // '78159082-8' tiene el DV cambiado: no existe, pero se PUEDE escribir
        // en el XML sin romper el esquema. Son dos defectos distintos y el SII
        // los reporta distinto (RSC contra rechazo del documento), asi que las
        // dos preguntas se responden por separado.
        self::assertTrue(Rut::bienFormado('78159082-8'));
        self::assertFalse(Rut::valido('78159082-8'));
    }

    public function testBienFormadoSigueElPatronDelEsquemaDelSii(): void
    {
        self::assertFalse(Rut::bienFormado('HOLA'));
        self::assertFalse(Rut::bienFormado('78159082'), 'sin guion no casa el patron');
        self::assertFalse(Rut::bienFormado('781590820-7'), 'nueve digitos pasan de maxLength=10');
        self::assertTrue(Rut::bienFormado('60803000-K'), 'el RUT del propio SII');
    }

    // -----------------------------------------------------------------------
    //  valido(): modulo 11, sobre el RUT ya normalizado
    // -----------------------------------------------------------------------

    public function testValidoAceptaRutRealesYRechazaDvCambiado(): void
    {
        self::assertTrue(Rut::valido('78159082-7'));
        self::assertTrue(Rut::valido('60803000-K'));
        self::assertFalse(Rut::valido('78159082-8'));
    }

    public function testValidoExigeElRutYaNormalizado(): void
    {
        // Es deliberado: si valido() aceptara el RUT con puntos, volveria a
        // esconder el problema que trajo esta clase.
        self::assertFalse(Rut::valido('78.159.082-7'));
        self::assertTrue(Rut::valido(Rut::normalizar('78.159.082-7')));
    }

    public function testValidoMantieneLaReglaDe7u8DigitosDelPanel(): void
    {
        // El esquema del SII admite desde 1 digito, pero el panel viene
        // exigiendo 7-8 en sus validaciones de formulario y esa decision no se
        // toca al arreglar el formato. '1-9' tiene el DV correcto y aun asi no
        // se acepta como dato tecleado.
        self::assertTrue(Rut::bienFormado('1-9'));
        self::assertFalse(Rut::valido('1-9'));
    }

    // -----------------------------------------------------------------------
    //  La garantia de verdad: por los DTO no pasa un RUT sin normalizar
    // -----------------------------------------------------------------------

    public function testElReceptorNormalizaElRutAlConstruirse(): void
    {
        // ESTA ES LA RED QUE IMPORTA. Aunque un llamador se olvide de
        // normalizar -- como se olvido armarDocumentoEmision() del panel --, lo
        // que sale hacia <RUTRecep> y <RR> ya viene escribible.
        $receptor = new Receptor('78.159.082-7', 'CLIENTE SPA');

        self::assertSame('78159082-7', $receptor->rut);
        self::assertTrue(Rut::bienFormado($receptor->rut));
    }

    public function testElRutNormalizadoLlegaAlArrayDelReceptor(): void
    {
        $receptor = new Receptor(' 12.345.678-k ', 'CLIENTE');

        self::assertSame('12345678-K', $receptor->toArray()['rut']);
    }

    public function testElReceptorSigueRechazandoUnRutVacio(): void
    {
        // La normalizacion no puede haberse comido la validacion que ya existia.
        $this->expectException(\Plantiflex\FacturacionCl\Exceptions\DocumentoInvalidoException::class);
        new Receptor('   ', 'CLIENTE');
    }

    public function testUnDocumentoCompletoLlevaElRutNormalizado(): void
    {
        $doc = new DocumentoTributario(
            tipoDte: TipoDte::NotaCreditoElectronica,
            receptor: new Receptor('78.159.082-7', 'CLIENTE SPA'),
            detalles: [new Detalle('Anulacion', 1, 1000)],
            montosSonBrutos: true,
            referencias: [['tipoDocumento' => 34, 'folio' => 745, 'fecha' => '2026-09-02', 'codigo' => 1]],
        );

        self::assertSame('78159082-7', $doc->receptor->rut);
    }
}
