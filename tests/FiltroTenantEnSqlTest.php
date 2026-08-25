<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\TestCase;

/**
 * QUE NINGUNA CONSULTA A LAS TABLAS DE DTE SE OLVIDE DEL FILTRO DE TENANT.
 *
 * POR QUE EXISTE
 * -----------------------------------------------------------------------------
 * Este SaaS es multi-tenant POR FILA: todas las cuentas comparten las mismas
 * tablas y lo unico que separa a una empresa de otra es un WHERE que alguien
 * tiene que acordarse de escribir. La migracion 045 le puso a las once tablas
 * de DTE una clave foranea compuesta hacia dte_emisor, y con eso la base ya
 * puede decir de quien es cada fila -- pero UNA CLAVE FORANEA NO IMPIDE QUE UN
 * WHERE OLVIDADO LEA LAS FILAS DE OTRO. Esa mitad del problema no la resuelve
 * el esquema, y hasta este test no la comprobaba nada.
 *
 * Un olvido de ese WHERE no es un error de pantalla: es un contribuyente viendo
 * los documentos tributarios de otro.
 *
 * COMO LO COMPRUEBA, Y POR QUE ASI
 * -----------------------------------------------------------------------------
 * Recorre el codigo con el TOKENIZADOR de PHP, no con expresiones regulares: el
 * SQL de este repositorio se arma concatenando literales a lo largo de varias
 * lineas, con ternarios y variables en medio, y una regex sobre el texto crudo
 * se pierde en el primer ternario.
 *
 * LA GRANULARIDAD ES LA SENTENCIA, Y SI NO ALCANZA, LA FUNCION. Se probaron las
 * dos por separado y ninguna sirve sola:
 *
 *   - Solo la sentencia da falsos positivos a montones. El patron normal aqui
 *     es armar el WHERE en una variable y usarlo despues:
 *         $where = 'rut_emisor = :rut AND ambiente = :amb';
 *         ... $this->pdo->prepare('SELECT ... FROM dte_emitido WHERE ' . $where)
 *     La sentencia del prepare() no nombra rut_emisor y sin embargo filtra.
 *
 *   - Solo la funcion da falsos negativos: una funcion con dos consultas, una
 *     filtrada y otra no, pasaria entera.
 *
 * Y HAY UN TERCER NIVEL, que aparecio al escribirlo: MySqlDteEmitidoRepository
 * tiene un helper privado, filtroPeriodo(), que arma el WHERE con rut_emisor, y
 * tres metodos que lo LLAMAN y usan lo que devuelve. El filtro es correcto pero
 * vive en otra funcion. Por eso, si la funcion tampoco lo nombra, se miran las
 * funciones del mismo fichero a las que llama, UN nivel. Un nivel y no los que
 * hagan falta: mas profundidad terminaria aceptando cualquier cosa por
 * transitividad, que es como se vuelve inutil una comprobacion asi.
 *
 * Asi que una consulta pasa si el filtro aparece en SU sentencia, en la funcion
 * que la contiene, o en una funcion del mismo fichero a la que esa llama. Es
 * deliberadamente permisivo. UN TEST QUE GRITA EN
 * FALSO ES PEOR QUE NO TENERLO -- se ignora, y despues se ignora tambien el dia
 * que tiene razon. Este atrapa la forma REAL del error: alguien escribe una
 * consulta nueva, en una funcion nueva, y no filtra por ningun lado.
 *
 * VALE cuenta_id ADEMAS DE rut_emisor. Lo que importa es que haya un filtro de
 * tenant, no cual: dte_envio_correo cuelga de cuenta_id y sus consultas hacen
 * JOIN con dte_emitido filtrando por ahi, lo cual es igual de correcto.
 *
 * LO QUE ESTE TEST NO PUEDE HACER
 * -----------------------------------------------------------------------------
 * No ejecuta nada ni entiende SQL: comprueba que el nombre de la columna
 * APAREZCA. Una consulta con "WHERE rut_emisor = :rut OR 1=1" pasaria. No es un
 * sustituto de leer el codigo; es una red que atrapa el olvido, que es como se
 * cometen estos errores en la practica.
 *
 * Al escribirlo se revisaron las 116 consultas que tocan estas tablas y NINGUNA
 * estaba sin filtrar: las que no lo nombran en su texto lo reciben por variable
 * o son las excepciones declaradas abajo. O sea que este test nace en verde y
 * lo que protege es el futuro.
 */
final class FiltroTenantEnSqlTest extends TestCase
{
    /** Las tablas que guardan datos de un contribuyente concreto. */
    private const TABLAS = [
        'dte_emitido', 'dte_caf', 'dte_certificado', 'dte_folio', 'dte_folio_log',
        'dte_idempotencia', 'dte_libro', 'dte_boleta_rvd', 'dte_intercambio_respuesta',
        'dte_logo', 'dte_set_basico_sok', 'dte_set_pruebas_archivo',
    ];

    /** Cualquiera de las dos vale como filtro de tenant. */
    private const COLUMNAS_TENANT = ['rut_emisor', 'cuenta_id'];

    /** Donde se busca. */
    private const DIRECTORIOS = [
        'integration/plantiflex', 'src', 'public', 'panel/public', 'panel/src', 'scripts',
    ];

    /**
     * LAS EXCEPCIONES, UNA POR UNA Y CON SU MOTIVO.
     *
     * Esta lista es el corazon del test y no un escape para callarlo. Cada
     * entrada es una consulta que a proposito NO filtra por tenant, revisada a
     * mano. La clave es "archivo:funcion" -- no la linea, que se mueve con
     * cualquier edicion y convertiria el test en un estorbo.
     *
     * Agregar una entrada aqui deberia costar una conversacion, no un impulso:
     * lo que se esta declarando es "esta consulta puede ver los datos de todos
     * los contribuyentes, y esta bien".
     *
     * @var array<string, string>
     */
    private const EXCEPCIONES = [
        'integration/plantiflex/MySqlConsultaVentasRepository.php::filasAgregadas' =>
            'EL FILTRO LLEGA COMO PARAMETRO. consultar() arma $donde con '
            . '"WHERE rut_emisor = :rut AND ambiente = \'produccion\'" y se lo pasa a este '
            . 'metodo, que solo lo interpola. Este test resuelve las funciones a las que '
            . 'una llama, pero no las que la llaman a ella: eso seria transitividad hacia '
            . 'atras y terminaria aceptando casi cualquier cosa.',

        'integration/plantiflex/MySqlConsultaVentasRepository.php::filasDeDocumentos' =>
            'Mismo caso que filasAgregadas: el WHERE con rut_emisor lo arma consultar() y '
            . 'viaja como parametro.',

        'integration/plantiflex/MySqlFolioRepository.php::marcarCafAgotado' =>
            'ACTUA POR CLAVE PRIMARIA CON LA PROPIEDAD YA ESTABLECIDA. Recibe un caf_id '
            . 'que obtenerCafActivo() acaba de resolver filtrando por rut_emisor y '
            . 'ambiente; volver a filtrar aqui no agregaria seguridad, solo repetiria el '
            . 'dato. Es un patron correcto y a la vez el que mas cuidado pide: si algun dia '
            . 'ese id llegara de fuera -- de un formulario, de la URL -- esta excepcion '
            . 'dejaria de ser valida.',

        'scripts/estado_migraciones.php::avisoBackfill022' =>
            'PREGUNTA SOBRE EL ESQUEMA, NO SOBRE LOS DATOS DE NADIE: cuenta filas de '
            . 'dte_folio con proximo_folio_inicial NULL en toda la base para decidir si la '
            . 'migracion 023 se puede aplicar. Filtrarla por tenant no tendria sentido.',

        'scripts/consultar_veredictos_pendientes.php::(nivel de fichero)' =>
            'NO HAY TENANT QUE AISLAR: es un runner de cron que recorre los sobres de '
            . 'TODOS los emisores para preguntarle al SII como quedaron. No hay sesion ni '
            . 'api_key -- nadie esta pidiendo estos datos --, y el veredicto de cada sobre '
            . 'se persiste contra su propio (rut_emisor, ambiente). La consulta marcada es '
            . 'ademas un COUNT para el resumen del log, no devuelve datos de nadie.',

        'scripts/probar_boleta_set_referencias.php::cafActivo' =>
            'Script de prueba manual contra el ambiente de certificacion del SII, con el '
            . 'RUT fijado en el propio fichero. No corre en produccion ni sirve trafico de '
            . 'nadie.',
    ];

    public function testNingunaConsultaDteSeOlvidaDelFiltroDeTenant(): void
    {
        $raiz       = dirname(__DIR__);
        $sinFiltrar = [];
        $usadas     = [];

        foreach (self::DIRECTORIOS as $dir) {
            foreach ($this->archivosPhp("{$raiz}/{$dir}") as $ruta) {
                $rel = str_replace($raiz . '/', '', $ruta);

                foreach ($this->consultasDe($ruta) as $c) {
                    if ($this->tablaDte($c['sql']) === null) {
                        continue;
                    }
                    if ($this->tieneFiltro($c['sql'])
                        || $this->tieneFiltro($c['funcionTexto'])
                        || $this->filtroEnLoQueLlama($c['funcionTexto'], $c['funciones'])
                    ) {
                        continue;
                    }
                    $clave = "{$rel}::{$c['funcion']}";
                    if (array_key_exists($clave, self::EXCEPCIONES)) {
                        $usadas[$clave] = true;
                        continue;
                    }

                    $sinFiltrar[] = sprintf(
                        "  %s linea %d (tabla %s)\n    %s",
                        $clave,
                        $c['linea'],
                        $this->tablaDte($c['sql']),
                        substr(trim((string) preg_replace('/\s+/', ' ', $c['sql'])), 0, 160)
                    );
                }
            }
        }

        self::assertSame([], $sinFiltrar, sprintf(
            "Hay %d consulta(s) a tablas de DTE sin filtro de tenant (rut_emisor o cuenta_id) "
            . "ni en su sentencia ni en su funcion.\n\n%s\n\n"
            . "Si la consulta DEBE ver los datos de todos los contribuyentes, agregala a "
            . "EXCEPCIONES en este test con el motivo escrito. Si no, le falta el WHERE.",
            count($sinFiltrar),
            implode("\n", $sinFiltrar)
        ));
    }

    /**
     * QUE NO SOBRE NINGUNA EXCEPCION.
     *
     * Una entrada que ya no corresponde a ninguna consulta es peor que inutil:
     * dice que en tal sitio hay una consulta sin filtrar cuando ya no la hay, y
     * el dia que alguien escriba una nueva ahi la taparia en silencio. Es la
     * misma razon por la que la clasificacion de /admin/base-datos se deriva del
     * esquema en vez de leerse de una lista escrita a mano.
     */
    public function testNoSobraNingunaExcepcion(): void
    {
        $raiz   = dirname(__DIR__);
        $usadas = [];

        foreach (self::DIRECTORIOS as $dir) {
            foreach ($this->archivosPhp("{$raiz}/{$dir}") as $ruta) {
                $rel = str_replace($raiz . '/', '', $ruta);
                foreach ($this->consultasDe($ruta) as $c) {
                    if ($this->tablaDte($c['sql']) === null) {
                        continue;
                    }
                    if ($this->tieneFiltro($c['sql'])
                        || $this->tieneFiltro($c['funcionTexto'])
                        || $this->filtroEnLoQueLlama($c['funcionTexto'], $c['funciones'])
                    ) {
                        continue;
                    }
                    $usadas["{$rel}::{$c['funcion']}"] = true;
                }
            }
        }

        $sobrantes = array_values(array_diff(array_keys(self::EXCEPCIONES), array_keys($usadas)));

        self::assertSame([], $sobrantes, sprintf(
            "Estas excepciones ya no corresponden a ninguna consulta sin filtrar:\n  %s\n\n"
            . 'O la consulta se arreglo -- entonces borra la entrada -- o se movio de '
            . 'funcion y hay que actualizar la clave.',
            implode("\n  ", $sobrantes)
        ));
    }

    /** El test no sirve de nada si deja de encontrar las consultas: esto lo comprueba. */
    public function testElRecorridoEncuentraLasConsultasQueDeberia(): void
    {
        $raiz  = dirname(__DIR__);
        $total = 0;
        foreach (self::DIRECTORIOS as $dir) {
            foreach ($this->archivosPhp("{$raiz}/{$dir}") as $ruta) {
                foreach ($this->consultasDe($ruta) as $c) {
                    if ($this->tablaDte($c['sql']) !== null) {
                        $total++;
                    }
                }
            }
        }

        // Al escribirlo eran 116. El umbral es holgado a proposito: sirve para
        // detectar que el recorrido se ROMPIO (pasaria a 0 o a un punado), no
        // para vigilar cuantas consultas hay.
        self::assertGreaterThan(
            80,
            $total,
            'El recorrido encontro muy pocas consultas a tablas de DTE. Probablemente '
            . 'el extractor de SQL dejo de funcionar, y entonces el test de arriba pasa '
            . 'sin comprobar nada.'
        );
    }

    /**
     * ¿Alguna funcion del mismo fichero a la que ESTA llama nombra el filtro?
     *
     * UN SOLO NIVEL, a proposito (ver la cabecera). Se buscan las llamadas por
     * su forma textual -- nombre seguido de parentesis -- que es suficiente
     * aqui y no obliga a resolver el arbol de tipos: lo que se quiere atrapar es
     * el helper que arma el WHERE, no cualquier invocacion imaginable.
     *
     * @param list<array{nombre:string, desde:int, hasta:int, texto:string}> $funciones
     */
    private function filtroEnLoQueLlama(string $textoFuncion, array $funciones): bool
    {
        if ($textoFuncion === '') {
            return false;
        }

        foreach ($funciones as $f) {
            if ($f['nombre'] === '(anonima)' || ! $this->tieneFiltro($f['texto'])) {
                continue;
            }
            if (preg_match('/\\b' . preg_quote($f['nombre'], '/') . '\\s*\\(/', $textoFuncion) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function archivosPhp(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }
        $out = [];
        $it  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $out[] = $f->getPathname();
            }
        }
        sort($out);

        return $out;
    }

    private function tablaDte(string $sql): ?string
    {
        foreach (self::TABLAS as $t) {
            if (preg_match('/\b(?:FROM|JOIN|INTO|UPDATE)\s+`?' . $t . '`?\b/i', $sql) === 1) {
                return $t;
            }
        }

        return null;
    }

    private function tieneFiltro(string $texto): bool
    {
        $bajo = strtolower($texto);
        foreach (self::COLUMNAS_TENANT as $col) {
            if (str_contains($bajo, $col)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extrae, por sentencia, el texto de los literales SQL, junto con el nombre
     * y el texto completo de la funcion que la contiene.
     *
     * @return list<array{sql:string, linea:int, funcion:string, funcionTexto:string}>
     */
    private function consultasDe(string $ruta): array
    {
        $tokens = token_get_all((string) file_get_contents($ruta));

        // Primera pasada: el texto y el rango de lineas de cada funcion, para
        // poder preguntar despues "¿la funcion que envuelve a esta sentencia
        // nombra el filtro?".
        $funciones = $this->funcionesDe($tokens, $ruta);

        $out   = [];
        $acum   = '';
        $linea  = 0;
        $prof   = 0;
        $interp = 0;

        $cerrar = function () use (&$out, &$acum, &$linea, $funciones): void {
            if (trim(str_replace('{VAR}', '', $acum)) !== '') {
                [$nombre, $texto] = $this->funcionEnLinea($funciones, $linea);
                    $out[] = ['sql' => $acum, 'linea' => $linea, 'funcion' => $nombre,
                          'funcionTexto' => $texto, 'funciones' => $funciones];
            }
            $acum = '';
        };

        foreach ($tokens as $t) {
            if (is_array($t)) {
                [$id, $texto, $ln] = $t;

                if ($id === T_CONSTANT_ENCAPSED_STRING) {
                    if ($acum === '') {
                        $linea = $ln;
                    }
                    $acum .= ' ' . substr($texto, 1, -1);
                    continue;
                }
                if ($id === T_ENCAPSED_AND_WHITESPACE) {
                    if ($acum === '') {
                        $linea = $ln;
                    }
                    $acum .= ' ' . $texto;
                    continue;
                }
                // Una variable no corta la sentencia: deja marca de que ahi hay
                // algo que este test no puede leer.
                if (in_array($id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $interp++;
                    if ($acum !== '') {
                        $acum .= ' {VAR} ';
                    }
                    continue;
                }
                if ($id === T_VARIABLE || $id === T_STRING_VARNAME) {
                    if ($acum !== '') {
                        $acum .= ' {VAR} ';
                    }
                    continue;
                }
                continue;
            }

            // El '}' que cierra una interpolacion no termina una sentencia.
            if ($t === '}' && $interp > 0) {
                $interp--;
                continue;
            }
            if ($t === '(' || $t === '[') {
                $prof++;
                continue;
            }
            if ($t === ')' || $t === ']') {
                $prof = max(0, $prof - 1);
                continue;
            }
            // Fin de sentencia, solo a profundidad 0: un ';' dentro de un
            // for(...) no termina nada.
            if (($t === ';' || $t === '{' || $t === '}') && $prof === 0) {
                $cerrar();
            }
        }
        $cerrar();

        return $out;
    }

    /**
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     *
     * @return list<array{nombre:string, desde:int, hasta:int, texto:string}>
     */
    private function funcionesDe(array $tokens, string $ruta): array
    {
        $lineas = explode("\n", (string) file_get_contents($ruta));
        $out    = [];
        $n      = count($tokens);

        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (! is_array($t) || $t[0] !== T_FUNCTION) {
                continue;
            }
            $desde  = $t[2];
            $nombre = '(anonima)';
            for ($j = $i + 1; $j < $n; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $nombre = $tokens[$j][1];
                    break;
                }
                if ($tokens[$j] === '(') {
                    break;   // closure: se queda como anonima
                }
            }

            // Cierre por conteo de llaves desde la primera '{' que sigue.
            $llaves = 0;
            $visto  = false;
            $hasta  = $desde;
            // LAS LLAVES DE INTERPOLACION NO SON LLAVES DE BLOQUE. En "{$x}" el
            // tokenizador emite T_CURLY_OPEN al abrir pero un '}' pelado al
            // cerrar. Contando solo los '{' literales, ese '}' huerfano cerraba
            // la funcion antes de tiempo y el texto quedaba truncado -- con lo
            // que un $where declarado mas arriba se perdia y la consulta salia
            // marcada como sin filtrar. Se cuentan tambien las de interpolacion.
            $interp = 0;
            for ($j = $i + 1; $j < $n; $j++) {
                $tk = $tokens[$j];
                if (is_array($tk) && in_array($tk[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $interp++;
                    continue;
                }
                if ($tk === '}' && $interp > 0) {
                    $interp--;
                    continue;
                }
                if ($tk === '{') {
                    $llaves++;
                    $visto = true;
                } elseif ($tk === '}') {
                    $llaves--;
                    if ($visto && $llaves === 0) {
                        $hasta = is_array($tokens[$j]) ? $tokens[$j][2] : $hasta;
                        for ($k = $j; $k >= $i; $k--) {
                            if (is_array($tokens[$k])) {
                                $hasta = $tokens[$k][2];
                                break;
                            }
                        }
                        break;
                    }
                } elseif ($tk === ';' && ! $visto) {
                    break;   // declaracion sin cuerpo (interface/abstract)
                }
            }

            $out[] = [
                'nombre' => $nombre,
                'desde'  => $desde,
                'hasta'  => $hasta,
                'texto'  => implode("\n", array_slice($lineas, $desde - 1, max(1, $hasta - $desde + 1))),
            ];
        }

        return $out;
    }

    /**
     * @param list<array{nombre:string, desde:int, hasta:int, texto:string}> $funciones
     *
     * @return array{0:string, 1:string}
     */
    private function funcionEnLinea(array $funciones, int $linea): array
    {
        // La MAS INTERNA que contenga la linea: hay closures dentro de metodos,
        // y la que manda es la de adentro.
        $mejor = null;
        foreach ($funciones as $f) {
            if ($linea >= $f['desde'] && $linea <= $f['hasta']) {
                if ($mejor === null || $f['desde'] > $mejor['desde']) {
                    $mejor = $f;
                }
            }
        }

        return $mejor === null
            ? ['(nivel de fichero)', '']
            : [$mejor['nombre'], $mejor['texto']];
    }
}
