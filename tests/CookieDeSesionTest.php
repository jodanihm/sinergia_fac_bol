<?php

declare(strict_types=1);

namespace Plantiflex\FacturacionCl\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * LA COOKIE DE SESION DEL PANEL SALE ENDURECIDA.
 *
 * DE DONDE SALE ESTE TEST: de la auditoria del 04-09-2026. Auth::iniciar() era
 * un session_start() pelado y la cookie salia con los cuatro valores por
 * defecto de PHP -- comprobado contra el panel que estaba corriendo:
 *
 *     Set-Cookie: PHPSESSID=...; path=/
 *
 * Sin HttpOnly, sin Secure, sin SameSite y con use_strict_mode = 0.
 *
 * POR QUE EN UN SUBPROCESO Y NO AQUI DENTRO. Los parametros de la cookie solo
 * son observables sobre una sesion de VERDAD, y arrancar una dentro del proceso
 * de PHPUnit ensucia el resto de la corrida (y con --process-isolation apagado,
 * un session_start() se arrastra entre tests). Cada caso lanza su propio `php
 * -r`, con el $_SERVER que quiere probar, y lee lo que quedo configurado. Es mas
 * lento y es lo unico que prueba el comportamiento real.
 */
final class CookieDeSesionTest extends TestCase
{
    private const AUTH = __DIR__ . '/../panel/src/Auth.php';

    /**
     * Arranca la sesion en un proceso limpio y devuelve como quedo.
     *
     * @param array<string,string> $server valores a poner en $_SERVER antes
     * @return array<string,mixed>
     */
    private function iniciarEnSubproceso(array $server = []): array
    {
        $codigo = '$_SERVER = ' . var_export($server, true) . ';'
            . 'require ' . var_export(self::AUTH, true) . ';'
            . '\Auth::iniciar();'
            . 'echo json_encode(['
            . '  "cookie" => session_get_cookie_params(),'
            . '  "strict" => ini_get("session.use_strict_mode"),'
            . '  "activa" => session_status() === PHP_SESSION_ACTIVE,'
            . ']);';

        $salida = shell_exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=0 -r ' . escapeshellarg($codigo) . ' 2>/dev/null');
        $datos  = json_decode((string) $salida, true);

        self::assertIsArray($datos, "el subproceso no devolvio JSON. Salida: " . var_export($salida, true));

        return $datos;
    }

    // -----------------------------------------------------------------------
    //  Las banderas que faltaban
    // -----------------------------------------------------------------------

    public function testLaSesionArrancaYQuedaActiva(): void
    {
        $r = $this->iniciarEnSubproceso();
        self::assertTrue($r['activa'], 'Auth::iniciar() dejo de arrancar la sesion');
    }

    /**
     * LA MAS IMPORTANTE. Sin HttpOnly, cualquier XSS del panel lee
     * document.cookie y se lleva la sesion entera.
     */
    public function testLaCookieVaHttpOnly(): void
    {
        $r = $this->iniciarEnSubproceso();
        self::assertTrue($r['cookie']['httponly']);
    }

    /**
     * Lax y NO Strict: el retorno del pagador llega desde Flow, o sea desde otro
     * sitio. Hoy esa pantalla no usa sesion, pero Strict dejaria la trampa
     * puesta para la primera que si la use.
     */
    public function testLaCookieVaSameSiteLax(): void
    {
        $r = $this->iniciarEnSubproceso();
        self::assertSame('Lax', $r['cookie']['samesite']);
    }

    /** Fijacion de sesion: PHP no debe aceptar un ID que no emitio el. */
    public function testElModoEstrictoQuedaEncendido(): void
    {
        $r = $this->iniciarEnSubproceso();
        self::assertSame('1', $r['strict']);
    }

    /** Cookie de sesion, no persistente: muere al cerrar el navegador. */
    public function testLaCookieMuereAlCerrarElNavegador(): void
    {
        $r = $this->iniciarEnSubproceso();
        self::assertSame(0, $r['cookie']['lifetime']);
        self::assertSame('/', $r['cookie']['path']);
    }

    // -----------------------------------------------------------------------
    //  Secure: condicionado, y por que
    // -----------------------------------------------------------------------

    /**
     * Por el tunel de Cloudflare el TLS NO termina en este servidor, asi que
     * $_SERVER['HTTPS'] viene vacio y la unica pista es X-Forwarded-Proto. Sin
     * mirarla, la peticion de un usuario real se veria como HTTP plano y la
     * cookie nunca llevaria Secure -- que es como si no se hubiera arreglado.
     *
     * @param array<string,string> $server
     */
    #[DataProvider('peticionesHttps')]
    public function testPorHttpsLaCookieVaSecure(array $server): void
    {
        $r = $this->iniciarEnSubproceso($server);
        self::assertTrue($r['cookie']['secure']);
    }

    public static function peticionesHttps(): array
    {
        return [
            'TLS termina aqui'                 => [['HTTPS' => 'on']],
            'TLS termina aqui, valor 1'        => [['HTTPS' => '1']],
            'detras del tunel'                 => [['HTTP_X_FORWARDED_PROTO' => 'https']],
            'la cabecera en mayusculas'        => [['HTTP_X_FORWARDED_PROTO' => 'HTTPS']],
        ];
    }

    /**
     * Y POR HTTP NO, que es lo que impide romper el login local. El panel
     * tambien se sirve por http://127.0.0.1:8086 para diagnostico, y una cookie
     * Secure ahi no se guardaria: fijar la bandera a ciegas dejaria ese camino
     * sin sesion y sin ningun mensaje de error.
     *
     * @param array<string,string> $server
     */
    #[DataProvider('peticionesHttp')]
    public function testPorHttpPlanoLaCookieNoExigeSecure(array $server): void
    {
        $r = $this->iniciarEnSubproceso($server);
        self::assertFalse($r['cookie']['secure']);
    }

    public static function peticionesHttp(): array
    {
        return [
            'sin nada'                    => [[]],
            'HTTPS=off, como manda Apache' => [['HTTPS' => 'off']],
            'el tunel dice http'          => [['HTTP_X_FORWARDED_PROTO' => 'http']],
        ];
    }

    // -----------------------------------------------------------------------
    //  El orden, que es la unica forma en que esto falla en silencio
    // -----------------------------------------------------------------------

    /**
     * session_set_cookie_params() e ini_set() DESPUES de session_start() no
     * tienen efecto sobre la cookie ya emitida, y PHP no lo grita. El test de
     * comportamiento de arriba no distingue los dos ordenes -- los parametros
     * quedan igual en memoria --, asi que el orden se comprueba leyendo la
     * fuente.
     */
    public function testLosAjustesVanAntesDelSessionStart(): void
    {
        $fuente = (string) file_get_contents(self::AUTH);

        $posParams = strpos($fuente, 'session_set_cookie_params(');
        $posStrict = strpos($fuente, "ini_set('session.use_strict_mode'");
        $posStart  = strpos($fuente, 'session_start();');

        self::assertIsInt($posParams, 'Auth::iniciar() ya no configura la cookie');
        self::assertIsInt($posStrict, 'Auth::iniciar() ya no enciende el modo estricto');
        self::assertIsInt($posStart, 'Auth::iniciar() ya no arranca la sesion');

        self::assertLessThan($posStart, $posParams, 'session_set_cookie_params() quedo DESPUES de session_start(): no tiene efecto');
        self::assertLessThan($posStart, $posStrict, 'use_strict_mode quedo DESPUES de session_start(): no tiene efecto');
    }

    /**
     * Y que nadie mas arranque sesiones por su cuenta: una segunda
     * session_start() en otro archivo se saltaria todo el endurecimiento.
     *
     * SE BUSCA POR TOKENS Y NO POR TEXTO. La primera version de este test hacia
     * str_contains('session_start(') y acusaba a panel/src/Csrf.php, que
     * MENCIONA la llamada en su docblock para explicar que es Auth quien la
     * hace. Un test que confunde una explicacion con una llamada acabaria
     * empujando a alguien a borrar el comentario.
     */
    public function testSoloAuthArrancaLaSesion(): void
    {
        $raiz        = (string) realpath(__DIR__ . '/../panel');
        $encontrados = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz));
        foreach ($it as $archivo) {
            if (! $archivo->isFile() || $archivo->getExtension() !== 'php') {
                continue;
            }
            $ruta = (string) $archivo->getRealPath();
            if ($ruta === realpath(self::AUTH)) {
                continue;
            }
            foreach (@token_get_all((string) file_get_contents($ruta)) as $token) {
                if (is_array($token) && $token[0] === T_STRING && $token[1] === 'session_start') {
                    $encontrados[] = str_replace($raiz . '/', '', $ruta) . ':' . $token[2];
                }
            }
        }

        self::assertSame(
            [],
            $encontrados,
            'estos archivos arrancan sesion sin pasar por Auth::iniciar(): ' . implode(', ', $encontrados)
        );
    }
}
