<?php

declare(strict_types=1);

/**
 * QUE RUTAS DESPACHA EL ROUTER, leidas de su propio codigo fuente.
 *
 * POR QUE LEER EL FUENTE Y NO UNA LISTA
 * -----------------------------------------------------------------------------
 * El gate de permisos (exigirPermisoDeRuta) falla CERRADO: una ruta que el
 * router despacha pero que nadie declaro en PERMISOS_RUTA, PERMISOS_RUTA_PATRON,
 * RUTAS_PUBLICAS o los espacios con gate propio termina en un 404, antes de que
 * su handler llegue a ejecutarse. Eso es lo correcto -- es la unica direccion
 * segura del error --, pero tiene una consecuencia incomoda: una ruta nueva sin
 * declarar NO se rompe con un mensaje que diga que falta declararla. Devuelve un
 * 404 comun, indistinguible de un enlace viejo o de un typo, y solo deja rastro
 * en el error_log del servidor.
 *
 * O sea: el olvido existe, cierra por el lado seguro, y es INVISIBLE hasta que
 * alguien reporta que "una pantalla no anda".
 *
 * Esta clase hace visible ese hueco: extrae las rutas que el router realmente
 * despacha y permite cruzarlas contra lo declarado. La unica fuente de verdad
 * sobre que rutas existen es el router mismo, asi que se lee de ahi. Una lista
 * escrita a mano tendria exactamente el mismo problema que se quiere detectar.
 *
 * LO QUE ESTA LISTA NO ES: un inventario de agujeros de seguridad. Al reves --
 * son rutas que HOY no funcionan para nadie. El riesgo que reporta es de
 * funcionalidad rota en silencio, no de acceso indebido.
 *
 * LIMITE CONOCIDO: esto es analisis de TEXTO, no de sintaxis PHP. Reconoce las
 * dos formas que el router usa hoy, cada una en una sola linea. Si alguien
 * escribe un despacho de otra forma (partido en dos lineas, con la ruta en una
 * variable), esta clase no lo vera y la ruta faltara en el informe. Se prefiere
 * asi antes que un parser de PHP entero: el formato del router es uniforme y
 * verificable de un vistazo, y el costo del error es un informe incompleto, no
 * un panel roto.
 */
final class RutasDelRouter
{
    /**
     * Despacho con ruta literal:
     *   if ($metodo === 'GET' && $ruta === '/maestros/clientes') {
     */
    private const RE_EXACTA = "/^\\s*if \\(\\\$metodo === '([A-Z]+)' && \\\$ruta === '([^']*)'\\)/m";

    /**
     * Despacho con patron:
     *   if ($metodo === 'POST' && preg_match('#^/x/(\d+)$#', $ruta, $mX)) {
     *
     * Exige la coma y $ruta despues del patron, para no confundirse con
     * cualquier otro preg_match que aparezca en el archivo.
     */
    private const RE_PATRON = "/^\\s*if \\(\\\$metodo === '([A-Z]+)' && preg_match\\('([^']*)', \\\$ruta/m";

    /**
     * @return list<array{metodo:string, ruta:string, esPatron:bool}>
     *         'ruta' es la ruta literal, o el regex tal cual esta escrito.
     */
    public static function extraer(string $fuente): array
    {
        $rutas = [];

        preg_match_all(self::RE_EXACTA, $fuente, $exactas, PREG_SET_ORDER);
        foreach ($exactas as $m) {
            $rutas[] = ['metodo' => $m[1], 'ruta' => $m[2], 'esPatron' => false];
        }

        preg_match_all(self::RE_PATRON, $fuente, $patrones, PREG_SET_ORDER);
        foreach ($patrones as $m) {
            $rutas[] = ['metodo' => $m[1], 'ruta' => $m[2], 'esPatron' => true];
        }

        return $rutas;
    }

    /**
     * URL DE EJEMPLO que casa con un patron de ruta, o null si no se pudo
     * construir una.
     *
     * POR QUE HACE FALTA, y por que comparar los textos de los regex no sirve.
     * El gate no compara patrones entre si: corre preg_match del patron
     * DECLARADO contra la URL que llego. Dos regex escritos distinto pueden
     * cubrir exactamente las mismas URLs, y de hecho pasa en este router: el
     * despacho de /activar usa '#^/activar/([0-9a-f]{64})$#' -- con grupo de
     * captura, porque necesita el token -- y PATRONES_PUBLICOS declara
     * '#^/activar/[0-9a-f]{64}$#' sin el grupo, porque solo necesita decidir.
     * Comparar los textos daba esas dos rutas como NO DECLARADAS cuando el gate
     * las deja pasar sin problema.
     *
     * Con una URL de ejemplo el informe puede preguntar lo mismo que pregunta
     * el gate, en vez de una aproximacion.
     *
     * SE VERIFICA A SI MISMA: antes de devolverla, la muestra se prueba contra
     * el patron del que salio. Si no casa, se devuelve null y el informe dice
     * que no pudo determinarlo, en vez de afirmar algo falso. Un "no se pudo"
     * se investiga; una afirmacion equivocada se cree.
     */
    public static function muestraDePatron(string $patron): ?string
    {
        // Solo patrones anclados en los dos extremos, que es como los escribe
        // este router. Sin anclas la URL de ejemplo no estaria determinada.
        if (preg_match('/^(.)\^(.*)\$\1$/s', $patron, $m) !== 1) {
            return null;
        }

        $muestra = self::expandir($m[2]);
        if ($muestra === null) {
            return null;
        }

        return @preg_match($patron, $muestra) === 1 ? $muestra : null;
    }

    /**
     * Expande el cuerpo de un regex a un texto concreto que lo satisface.
     *
     * Cubre SOLO las construcciones que este router usa: literales, \d, clases
     * [...], grupos (...) con alternativas, y los cuantificadores + * ? {n}.
     * Cualquier otra cosa devuelve null -- se prefiere no saber a inventar.
     */
    private static function expandir(string $cuerpo): ?string
    {
        $salida   = '';
        $posicion = 0;
        $largo    = strlen($cuerpo);

        while ($posicion < $largo) {
            $caracter = $cuerpo[$posicion];

            if ($caracter === '\\') {
                if ($posicion + 1 >= $largo) {
                    return null;
                }
                $siguiente = $cuerpo[$posicion + 1];
                $atomo     = match ($siguiente) {
                    'd'     => '1',
                    'w'     => 'a',
                    default => $siguiente,   // \. \- \/ ... el caracter literal
                };
                $posicion += 2;
            } elseif ($caracter === '[') {
                $cierre = self::buscarCierre($cuerpo, $posicion, '[', ']');
                if ($cierre === null) {
                    return null;
                }
                $clase = substr($cuerpo, $posicion, $cierre - $posicion + 1);
                $atomo = self::representanteDeClase($clase);
                if ($atomo === null) {
                    return null;
                }
                $posicion = $cierre + 1;
            } elseif ($caracter === '(') {
                $cierre = self::buscarCierre($cuerpo, $posicion, '(', ')');
                if ($cierre === null) {
                    return null;
                }
                $interior = substr($cuerpo, $posicion + 1, $cierre - $posicion - 1);
                // Grupo sin captura: para generar da lo mismo.
                if (str_starts_with($interior, '?:')) {
                    $interior = substr($interior, 2);
                }
                // Con alternativas se toma la PRIMERA: cualquiera sirve para
                // decidir cobertura, y la primera es estable entre corridas.
                $alternativas = self::partirAlternativas($interior);
                $atomo        = self::expandir($alternativas[0]);
                if ($atomo === null) {
                    return null;
                }
                $posicion = $cierre + 1;
            } elseif (str_contains('.*+?{}|)]^$', $caracter)) {
                // Metacaracter suelto donde se esperaba un atomo.
                return null;
            } else {
                $atomo = $caracter;
                $posicion++;
            }

            [$repeticiones, $posicion] = self::leerCuantificador($cuerpo, $posicion);
            if ($repeticiones === null) {
                return null;
            }
            $salida .= str_repeat($atomo, $repeticiones);
        }

        return $salida;
    }

    /** Indice del cierre que corresponde a la apertura en $desde, o null. */
    private static function buscarCierre(string $texto, int $desde, string $abre, string $cierra): ?int
    {
        $profundidad = 0;
        $largo       = strlen($texto);

        for ($i = $desde; $i < $largo; $i++) {
            if ($texto[$i] === '\\') {
                $i++;
                continue;
            }
            if ($texto[$i] === $abre) {
                $profundidad++;
            } elseif ($texto[$i] === $cierra) {
                $profundidad--;
                if ($profundidad === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Un caracter que satisfaga la clase. Se prueban candidatos contra la clase
     * REAL en vez de interpretarla a mano: asi funciona igual con [0-9a-f],
     * [a-z-] o [^/], sin tener que entender rangos ni negaciones.
     */
    private static function representanteDeClase(string $clase): ?string
    {
        // EL DELIMITADOR SE ELIGE, no se fija. Con '/' fijo, una clase que
        // contenga una barra -- [^/] es la mas comun en rutas -- cierra el
        // patron antes de tiempo y preg_match falla por sintaxis, no porque el
        // candidato no sirva. Se toma el primero que no aparezca en la clase.
        $delimitador = null;
        foreach (['#', '~', '%', '!', '@'] as $posible) {
            if (! str_contains($clase, $posible)) {
                $delimitador = $posible;
                break;
            }
        }
        if ($delimitador === null) {
            return null;
        }

        foreach (['a', '1', 'x', '0', '-', '_'] as $candidato) {
            if (@preg_match($delimitador . '^' . $clase . '$' . $delimitador, $candidato) === 1) {
                return $candidato;
            }
        }

        return null;
    }

    /**
     * Parte por | de primer nivel, respetando grupos y clases anidados.
     *
     * @return list<string>
     */
    private static function partirAlternativas(string $texto): array
    {
        $partes      = [];
        $actual      = '';
        $profundidad = 0;
        $largo       = strlen($texto);

        for ($i = 0; $i < $largo; $i++) {
            $caracter = $texto[$i];
            if ($caracter === '\\' && $i + 1 < $largo) {
                $actual .= $caracter . $texto[$i + 1];
                $i++;
                continue;
            }
            if ($caracter === '(' || $caracter === '[') {
                $profundidad++;
            } elseif ($caracter === ')' || $caracter === ']') {
                $profundidad--;
            } elseif ($caracter === '|' && $profundidad === 0) {
                $partes[] = $actual;
                $actual   = '';
                continue;
            }
            $actual .= $caracter;
        }
        $partes[] = $actual;

        return $partes;
    }

    /**
     * Cuantas veces repetir el atomo que acaba de leerse, y donde sigue.
     *
     * Para + y * alcanza con UNA repeticion (la minima que satisface +, y una
     * cualquiera satisface *). Para {n} y {n,m} se usa n, el minimo exigido.
     *
     * @return array{0:?int, 1:int}
     */
    private static function leerCuantificador(string $cuerpo, int $posicion): array
    {
        if ($posicion >= strlen($cuerpo)) {
            return [1, $posicion];
        }

        return match ($cuerpo[$posicion]) {
            '+', '*' => [1, $posicion + 1],
            '?'      => [0, $posicion + 1],
            '{'      => self::leerLlaves($cuerpo, $posicion),
            default  => [1, $posicion],
        };
    }

    /** @return array{0:?int, 1:int} */
    private static function leerLlaves(string $cuerpo, int $posicion): array
    {
        $cierre = strpos($cuerpo, '}', $posicion);
        if ($cierre === false) {
            return [null, $posicion];
        }

        $contenido = substr($cuerpo, $posicion + 1, $cierre - $posicion - 1);
        $minimo    = explode(',', $contenido)[0];
        if (! ctype_digit($minimo)) {
            return [null, $posicion];
        }

        return [(int) $minimo, $cierre + 1];
    }

    /**
     * Parte fija del comienzo de un regex de ruta, hasta el primer
     * metacaracter. De '#^/admin/tenants/(\d+)$#' saca '/admin/tenants/'.
     *
     * Sirve para decidir si una ruta CON PARAMETRO cae dentro de un espacio con
     * gate propio, que se declara por prefijo. Sin esto, /admin/tenants/{id}
     * apareceria como no declarada cuando en realidad esta cubierta por el
     * prefijo '/admin/'.
     *
     * Ante la duda devuelve menos, no mas: si no logra leer el comienzo del
     * patron devuelve cadena vacia, que no coincide con ningun prefijo y deja
     * la ruta en la lista de no declaradas. Un falso positivo en el informe se
     * revisa; un falso negativo esconde justo lo que se busca.
     */
    public static function prefijoLiteral(string $patron): string
    {
        // Delimitador + ancla inicial. Sin ^ el patron puede coincidir en
        // cualquier parte y no hay prefijo que valga.
        if (! preg_match('/^(.)\^(.*)$/s', $patron, $m)) {
            return '';
        }

        $cuerpo   = $m[2];
        $prefijo  = '';
        $longitud = strlen($cuerpo);

        for ($i = 0; $i < $longitud; $i++) {
            $caracter = $cuerpo[$i];
            if (str_contains('([{|.*+?\\$', $caracter) || $caracter === $m[1]) {
                break;
            }
            $prefijo .= $caracter;
        }

        return $prefijo;
    }
}
