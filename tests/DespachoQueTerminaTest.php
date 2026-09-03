<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Que un handler que escribe su propio cuerpo TERMINE la peticion.
 *
 * DE DONDE SALE. Un POST valido a /pagos/flow/confirmacion/1 respondia HTTP 200
 * con este cuerpo:
 *
 *     ok404 - ruta no encontrada
 *
 * El pago se procesaba bien -- por eso costo verlo --, pero Flow recibia la
 * respuesta con basura pegada. La causa: los `if` del despacho de este router NO
 * llevan exit, porque casi todos los handlers acaban en vista() o redirigir(),
 * declaradas `never`. handleConfirmacionPagoPost() hacia echo y VOLVIA, asi que
 * la ejecucion seguia bajando por el front controller hasta el 404 final y le
 * concatenaba su cuerpo. El http_response_code(404) de ahi no se noto solo
 * porque el echo ya habia mandado los headers.
 *
 *
 * POR QUE UN TEST ESTATICO Y NO UNA PETICION DE VERDAD
 * -----------------------------------------------------------------------------
 * Porque el router es un front controller de 19.000 lineas que abre sesion y se
 * conecta a MySQL al incluirlo: ningun test de esta suite lo carga, y montar eso
 * para comprobar una propiedad estructural costaria mas de lo que vale. Es la
 * misma via que ya usan ConstantesAntesDelDespachoTest y RutasDelRouterTest.
 *
 * QUE GARANTIZA Y QUE NO. Garantiza que el handler esta declarado `never` y que
 * su cuerpo acaba cortando la ejecucion. Lo segundo lo refuerza el propio PHP:
 * una funcion `never` que llegue a retornar revienta en tiempo de ejecucion, asi
 * que quitar el exit no puede pasar desapercibido. Lo que NO comprueba es el
 * cuerpo HTTP servido; eso se verifico a mano contra un preview aislado, antes y
 * despues del arreglo.
 */
final class DespachoQueTerminaTest extends TestCase
{
    private const ROUTER = __DIR__ . '/../panel/public/index.php';

    /**
     * Handlers de rutas PUBLICAS que escriben su propio cuerpo.
     *
     * Se vigilan estos y no todos porque son los que responden a un tercero:
     * quien llama es Flow o el navegador de un pagador, ninguno de los dos va a
     * avisarnos de que la respuesta venia con cola.
     *
     * @return list<array{string}>
     */
    public static function handlersQueDebenTerminar(): array
    {
        return [
            'confirmacion de pago' => ['handleConfirmacionPagoPost'],
            'retorno del pagador'  => ['handleRetornoPagoGet'],
        ];
    }

    #[DataProvider('handlersQueDebenTerminar')]
    public function testElHandlerNoPuedeDevolverElControlAlRouter(string $handler): void
    {
        $cuerpo = self::cuerpoDe($handler);

        self::assertTrue(
            self::corta($cuerpo),
            "{$handler}() tiene que cortar la ejecucion (exit, o una llamada a vista()/"
            . "redirigir(), que son `never`). Si vuelve, el router sigue bajando hasta el "
            . '404 final y le pega "404 - ruta no encontrada" al cuerpo.'
        );
    }

    public function testElHandlerQueEscribeSuCuerpoSeDeclaraNever(): void
    {
        // `never` no es documentacion: si la funcion llega a retornar, PHP lanza
        // un fatal. Es la red que impide que alguien quite el exit y no se note
        // hasta que Flow reciba otra respuesta con cola.
        //
        // SOLO SE EXIGE AL QUE ESCRIBE SU PROPIO CUERPO. handleRetornoPagoGet()
        // corta igual de bien, pero delegando en vista(), que ya es `never`;
        // ahi la firma es una mejora opcional y no una defensa que falte.
        self::assertMatchesRegularExpression(
            '/function\s+handleConfirmacionPagoPost\s*\([^)]*\)\s*:\s*never\b/',
            self::fuente(),
            'handleConfirmacionPagoPost() tiene que declararse `never`'
        );
    }

    public function testElRetornoDelPagadorCortaDelegandoEnVista(): void
    {
        // No escribe su cuerpo a mano: llama a vista(), que hace exit. Se fija
        // aqui para que si alguien la convierte en un echo directo, aparezca la
        // exigencia del exit propio.
        $cuerpo = self::cuerpoDe('handleRetornoPagoGet');

        self::assertStringContainsString('vista(', $cuerpo);
        self::assertStringNotContainsString('echo ', $cuerpo, 'si pasa a escribir su cuerpo, necesita exit propio');
    }

    public function testLaConfirmacionDePagoAcabaExactamenteEnExit(): void
    {
        // El caso concreto que motivo el test, fijado por nombre: este handler
        // escribe su cuerpo con echo, asi que no puede delegar el corte en
        // vista(). Tiene que ser un exit propio.
        $cuerpo = self::cuerpoDe('handleConfirmacionPagoPost');

        self::assertStringContainsString('echo $resultado[\'cuerpo\'];', $cuerpo);
        self::assertSame(
            'exit',
            self::ultimaSentencia($cuerpo),
            'la ultima sentencia de handleConfirmacionPagoPost() tiene que ser exit'
        );
    }

    public function testElRouterSigueTeniendoUn404FinalQueEsElQueContaminaba(): void
    {
        // Si el 404 final desapareciera, este test dejaria de tener sentido y
        // conviene enterarse en vez de que quede vigilando un fantasma.
        self::assertStringContainsString("echo '404 - ruta no encontrada';", self::fuente());
    }

    // ------------------------------------------------------------------

    private static function fuente(): string
    {
        $fuente = file_get_contents(self::ROUTER);
        self::assertNotFalse($fuente);

        return $fuente;
    }

    /**
     * El cuerpo de una funcion de nivel de archivo, entre sus llaves.
     *
     * Cuenta llaves sobre los tokens del lexer, no sobre el texto: asi una llave
     * dentro de una cadena o de un comentario no descuadra el conteo.
     */
    private static function cuerpoDe(string $handler): string
    {
        $tokens = token_get_all(self::fuente());
        $total  = count($tokens);

        for ($i = 0; $i < $total; $i++) {
            $t = $tokens[$i];
            if (! is_array($t) || $t[0] !== T_FUNCTION) {
                continue;
            }
            // El identificador que sigue a `function`.
            $j = $i + 1;
            while ($j < $total && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if (! is_array($tokens[$j] ?? null) || $tokens[$j][0] !== T_STRING || $tokens[$j][1] !== $handler) {
                continue;
            }

            // Desde aqui hasta la primera '{' esta la firma; luego el cuerpo.
            $profundidad = 0;
            $cuerpo      = '';
            for ($k = $j; $k < $total; $k++) {
                $tk = $tokens[$k];
                if ($tk === '{') {
                    $profundidad++;
                    if ($profundidad === 1) {
                        continue;   // la llave de apertura no es cuerpo
                    }
                }
                if ($tk === '}') {
                    $profundidad--;
                    if ($profundidad === 0) {
                        return $cuerpo;
                    }
                }
                if ($profundidad >= 1) {
                    $cuerpo .= is_array($tk) ? $tk[1] : $tk;
                }
            }
        }

        self::fail("no se encontro la funcion {$handler}() en el router");
    }

    /** True si el cuerpo corta la ejecucion por alguna de las vias del archivo. */
    private static function corta(string $cuerpo): bool
    {
        foreach (['exit', 'vista(', 'redirigir(', 'redirigirPrg('] as $corte) {
            if (str_contains($cuerpo, $corte)) {
                return true;
            }
        }

        return false;
    }

    /** El ultimo token significativo del cuerpo, en minusculas. */
    private static function ultimaSentencia(string $cuerpo): string
    {
        $significativos = [];
        foreach (token_get_all('<?php ' . $cuerpo) as $t) {
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
                continue;
            }
            $texto = is_array($t) ? $t[1] : $t;
            if ($texto === ';') {
                continue;
            }
            $significativos[] = strtolower($texto);
        }

        return (string) end($significativos);
    }
}
