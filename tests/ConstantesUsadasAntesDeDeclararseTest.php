<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Que ninguna constante del router se USE antes de su propia declaracion.
 *
 * DE DONDE SALE ESTE TEST: de un fatal que tumbaba el panel ENTERO.
 *
 * RUTA_RETORNO_PAGO se declaraba unas lineas mas abajo de la lista
 * RUTAS_PUBLICAS, que la usa DENTRO de su propia definicion. Las const de nivel
 * de archivo no se hoistean: solo existen cuando la ejecucion pasa por su linea.
 * Resultado: "Undefined constant" en la PRIMERA peticion, cualquiera que fuese
 * -- no una pantalla rota, todas. Se descubrio levantando un preview del arbol;
 * la suite entera estaba en verde.
 *
 *
 * QUE HUECO CIERRA, QUE NO ES EL DE SU VECINO
 * -----------------------------------------------------------------------------
 * ConstantesAntesDelDespachoTest vigila el otro extremo del mismo problema: que
 * una constante no se declare DESPUES del punto donde el router despacha. Aquel
 * caso nacio de un fatal real y sigue siendo necesario, pero mira una sola
 * frontera -- el despacho -- y da por bueno todo lo que ocurra antes.
 *
 * Este fallo vivia justo ahi: declaracion Y uso estaban los dos ANTES del
 * despacho, en el orden equivocado entre si. Para aquel test el archivo era
 * correcto, y lo era segun su propio criterio.
 *
 *
 * POR QUE CON TOKENS Y NO CON grep
 * -----------------------------------------------------------------------------
 * Un par de expresiones regulares comparando numeros de linea se rompe con la
 * primera mencion en un comentario, con un nombre parecido o con la propia
 * palabra dentro de un docblock -- y un test que da falsos positivos se acaba
 * borrando. token_get_all() es el lexer de PHP: distingue codigo de comentario
 * sin que haya que ensenarselo, y sabe donde empieza y acaba un bloque.
 *
 * No es un parser: son treinta lineas recorriendo una lista de tokens.
 *
 * SOLO MIRA CONSTANTES DE NIVEL DE ARCHIVO. Las de clase viven dentro de su
 * clase, se resuelven por el autoloader y no tienen este problema -- son las
 * mismas que ConstantesAntesDelDespacho excluye, y por el mismo motivo.
 *
 * Y SOLO CUENTA LOS USOS INMEDIATOS: los que PHP evalua mientras lee el archivo.
 * Un uso DENTRO de una funcion no es un fallo, por mucho que la constante se
 * declare cien lineas mas abajo: ese cuerpo no corre al leer el archivo, corre
 * cuando alguien llama a la funcion, y para entonces ya existe todo. El router
 * tiene dos casos asi (NOTA_VENTA_ENCABEZADOS, NOTA_VENTA_FORMAS_PAGO) y los dos
 * son correctos; un test que los acusara seria un test que alguien acaba
 * silenciando.
 */
final class ConstantesUsadasAntesDeDeclararseTest extends TestCase
{
    private const ROUTER = __DIR__ . '/../panel/public/index.php';

    public function testNingunaConstanteSeUsaAntesDeDeclararse(): void
    {
        $fuente = file_get_contents(self::ROUTER);
        self::assertNotFalse($fuente);

        [$declaradas, $usos] = self::analizar($fuente);
        self::assertNotEmpty($declaradas, 'el router tiene constantes de nivel de archivo');

        $rotas = [];
        foreach ($declaradas as $nombre => $lineaDeclaracion) {
            foreach ($usos[$nombre] ?? [] as $lineaUso) {
                if ($lineaUso < $lineaDeclaracion) {
                    $rotas[] = sprintf(
                        '%s se usa en la linea %d pero se declara en la %d',
                        $nombre,
                        $lineaUso,
                        $lineaDeclaracion
                    );
                    break;
                }
            }
        }

        self::assertSame(
            [],
            $rotas,
            "Hay constantes usadas antes de existir. Las const de nivel de archivo NO se\n"
            . "hoistean: el panel revienta con 'Undefined constant' en la primera peticion.\n"
            . 'Mueve la declaracion por encima de su primer uso: ' . implode(' | ', $rotas)
        );
    }

    /**
     * El caso concreto que motivo el test, fijado por nombre.
     *
     * El test general de arriba ya lo cubriria, pero este deja escrito CUAL fue
     * el fallo: si alguien vuelve a mover esa constante, el mensaje nombra la
     * ruta de retorno en vez de hablar en abstracto.
     */
    public function testLaRutaDeRetornoSeDeclaraAntesDeLaListaDeRutasPublicas(): void
    {
        $fuente = file_get_contents(self::ROUTER);
        self::assertNotFalse($fuente);

        [$declaradas, $usos] = self::analizar($fuente);

        self::assertArrayHasKey('RUTA_RETORNO_PAGO', $declaradas);
        self::assertArrayHasKey('RUTAS_PUBLICAS', $declaradas);
        self::assertLessThan(
            $declaradas['RUTAS_PUBLICAS'],
            $declaradas['RUTA_RETORNO_PAGO'],
            'RUTAS_PUBLICAS usa RUTA_RETORNO_PAGO dentro de su propia definicion'
        );

        foreach ($usos['RUTA_RETORNO_PAGO'] ?? [] as $linea) {
            self::assertGreaterThan($declaradas['RUTA_RETORNO_PAGO'], $linea);
        }
    }

    /**
     * Devuelve [constantes de nivel de archivo => linea, nombre => lineas de uso].
     *
     * @return array{array<string,int>, array<string,list<int>>}
     */
    private static function analizar(string $fuente): array
    {
        $tokens     = token_get_all($fuente);
        $declaradas = [];
        $usos       = [];

        // Una entrada por cada llave abierta: true si esa llave abre el CUERPO
        // DE UNA FUNCION. La pila vacia = nivel de archivo.
        $pila              = [];
        $proximaLlaveDifiere = false;

        foreach ($tokens as $i => $token) {
            // Las llaves de bloque llegan como string suelto.
            if ($token === '{') {
                $pila[]              = $proximaLlaveDifiere;
                $proximaLlaveDifiere = false;
                continue;
            }
            if ($token === '}') {
                array_pop($pila);
                continue;
            }
            // Metodo declarado sin cuerpo (interface, abstract): el `function`
            // que acabamos de ver no abrira ninguna llave, se cierra en ';'.
            if ($token === ';') {
                $proximaLlaveDifiere = false;
                continue;
            }
            if (! is_array($token)) {
                continue;
            }

            // Y LA INTERPOLACION DE CADENAS TAMBIEN ABRE LLAVE, pero con un
            // token propio: "{$var}" produce T_CURLY_OPEN al abrir y un '}'
            // SUELTO al cerrar. Sin contar la apertura, cada interpolacion
            // restaba uno al nivel -- y este router usa "{$x}" por todas partes,
            // asi que el nivel de archivo dejaba de reconocerse y no se veia
            // ninguna constante. Es el fallo que tuvo la primera version de este
            // test.
            if ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                $pila[] = false;
                continue;
            }

            if ($token[0] === T_FUNCTION) {
                $proximaLlaveDifiere = true;
                continue;
            }

            // Declaracion: `const NOMBRE` con la pila vacia. Dentro de una clase
            // hay al menos una llave abierta, asi que las constantes de clase se
            // caen solas de esta lista.
            if ($token[0] === T_CONST && $pila === []) {
                $nombre = self::siguienteIdentificador($tokens, $i);
                if ($nombre !== null) {
                    $declaradas[$nombre[0]] = $nombre[1];
                }
                continue;
            }

            // Uso INMEDIATO: un identificador que se evalua mientras PHP lee el
            // archivo. Dentro de una funcion no cuenta: ese cuerpo no corre al
            // leer, corre cuando alguien la llama, y para entonces todas las
            // const del archivo ya existen. Sin esta distincion el test acusaba
            // a NOTA_VENTA_ENCABEZADOS y NOTA_VENTA_FORMAS_PAGO, que se usan
            // dentro de funciones y estan perfectamente bien.
            //
            // Los comentarios y docblocks llegan como T_COMMENT / T_DOC_COMMENT
            // y no como T_STRING, asi que nombrar la constante en un comentario
            // no cuenta -- que es justamente lo que rompia la version con grep.
            if ($token[0] === T_STRING && ! in_array(true, $pila, true)) {
                $usos[$token[1]][] = $token[2];
            }
        }

        // La linea de la propia declaracion no es un uso.
        foreach ($declaradas as $nombre => $linea) {
            $usos[$nombre] = array_values(array_filter(
                $usos[$nombre] ?? [],
                static fn (int $l): bool => $l !== $linea
            ));
        }

        return [$declaradas, $usos];
    }

    /**
     * El identificador que sigue a un token, saltando espacios y comentarios.
     *
     * @param list<array{int,string,int}|string> $tokens
     *
     * @return array{string,int}|null [nombre, linea]
     */
    private static function siguienteIdentificador(array $tokens, int $desde): ?array
    {
        $total = count($tokens);
        for ($j = $desde + 1; $j < $total; $j++) {
            $t = $tokens[$j];
            if (! is_array($t)) {
                return null;   // algo inesperado antes del nombre
            }
            if (in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if ($t[0] === T_STRING) {
                return [$t[1], $t[2]];
            }

            return null;
        }

        return null;
    }
}
