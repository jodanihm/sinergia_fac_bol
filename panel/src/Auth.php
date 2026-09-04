<?php

declare(strict_types=1);

/**
 * Sesion PHP nativa para el login humano del panel. Guarda solo usuario_id y
 * cuenta_id en $_SESSION; nunca password ni password_hash.
 */
final class Auth
{
    /**
     * Arranca la sesion CON LA COOKIE ENDURECIDA. Se llama una sola vez, en
     * panel/public/index.php, para cualquier peticion.
     *
     * HASTA EL 04-09-2026 ESTO ERA UN session_start() PELADO, y la cookie salia
     * asi -- comprobado contra el panel que estaba corriendo:
     *
     *     Set-Cookie: PHPSESSID=...; path=/
     *
     * Sin HttpOnly, sin Secure y sin SameSite, con
     * session.use_strict_mode = 0. Los cuatro valores por defecto de PHP, que
     * son los de 2005 y no los de hoy.
     *
     * QUE APORTA CADA UNO:
     *
     *   httponly   El de mas peso. Sin el, CUALQUIER XSS en el panel puede leer
     *              document.cookie y llevarse la sesion entera. Con el, un XSS
     *              sigue siendo un problema pero no es una cuenta tomada. El
     *              panel escapa con htmlspecialchars por todos lados; esta es la
     *              segunda capa, la que sirve el dia que uno se escape.
     *
     *   secure     Que la cookie no viaje nunca por HTTP plano. Va CONDICIONADO
     *              a que la peticion sea HTTPS (ver esHttps()) y no fijo en
     *              true: el panel tambien se sirve por http://127.0.0.1:8086
     *              para diagnostico, y una cookie Secure ahi no se guardaria --
     *              o sea que endurecer a ciegas romperia el login local sin
     *              avisar. Por el tunel, que es como entra todo el mundo, la
     *              bandera se activa sola.
     *
     *   samesite   Lax y NO Strict, a proposito. Strict no manda la cookie en
     *              ninguna navegacion que venga de otro sitio, y este panel
     *              recibe una: el retorno del pagador desde Flow. Esa pantalla
     *              no usa sesion -- la abre el cliente de nuestro cliente, que
     *              no tiene cuenta aqui --, asi que hoy Strict no romperia nada,
     *              pero deja una trampa puesta para la primera pantalla con
     *              sesion a la que se llegue desde fuera. Lax cubre el CSRF que
     *              importa y no deja esa trampa. El token CSRF sigue siendo la
     *              defensa principal; esto es refuerzo.
     *
     *   strict_mode  PHP acepta por defecto un ID de sesion que no emitio el,
     *              que es media fijacion de sesion servida. login() ya llama a
     *              session_regenerate_id(true), que corta el ataque clasico,
     *              pero eso depende de que alguien se acuerde de regenerar en
     *              cada camino nuevo. Esto no depende de nadie.
     *
     * EL ORDEN IMPORTA: los dos ajustes van ANTES de session_start(). Despues no
     * tienen efecto y no avisan -- fallarian en silencio, que es la unica forma
     * de fallo que este arreglo no puede permitirse.
     */
    public static function iniciar(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        ini_set('session.use_strict_mode', '1');

        session_set_cookie_params([
            // 0 = cookie de sesion, que es lo que habia y lo que corresponde:
            // la sesion muere al cerrar el navegador.
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'secure'   => self::esHttps(),
            'samesite' => 'Lax',
        ]);

        session_start();
    }

    /**
     * Si ESTA peticion llego por HTTPS.
     *
     * DOS FUENTES, y hacen falta las dos. PHP solo llena $_SERVER['HTTPS']
     * cuando el TLS termina en el mismo servidor, y aqui no termina: el sitio
     * sale por Cloudflare Tunnel, que habla HTTPS con el mundo y HTTP con nginx
     * en 127.0.0.1. Sin mirar la cabecera, la peticion de un usuario real se
     * veria como HTTP plano y la cookie nunca llevaria Secure.
     *
     * FIARSE DE LA CABECERA ES SEGURO AQUI, y conviene decir por que en vez de
     * dejarlo a la intuicion: (a) el puerto de nginx esta atado a 127.0.0.1, asi
     * que nadie de fuera del host le habla directo; y (b) aunque alguien lograra
     * inyectarla, lo unico que consigue es que su propia cookie salga marcada
     * Secure. La cabecera solo puede ENDURECER, nunca relajar.
     */
    private static function esHttps(): bool
    {
        $https = (string) ($_SERVER['HTTPS'] ?? '');
        if ($https !== '' && strtolower($https) !== 'off') {
            return true;
        }

        return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    public static function login(int $usuarioId, int $cuentaId): void
    {
        session_regenerate_id(true);
        $_SESSION['usuario_id'] = $usuarioId;
        $_SESSION['cuenta_id']  = $cuentaId;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function autenticado(): bool
    {
        return isset($_SESSION['usuario_id'], $_SESSION['cuenta_id']);
    }

    /**
     * Cuenta ACTIVA para esta peticion.
     *
     * Normalmente es la cuenta del usuario de la sesion. La excepcion es la
     * vista de superadmin: mientras esta activa devuelve la cuenta que se esta
     * mirando, para que las ~60 pantallas del tenant -- que ya filtran por
     * Auth::cuentaId() -- muestren los datos de ESE tenant sin tocar ni una de
     * ellas. Es lo que hace que la funcion sea aditiva en vez de una reescritura.
     *
     * EL usuario_id NO SE TOCA NUNCA. Sigue siendo el del superadmin real, y de
     * ahi salen la auditoria y exigirSuperadmin(). Suplantar la identidad,
     * ademas de la cuenta, haria que las acciones quedaran registradas a nombre
     * de otra persona -- justo lo contrario de lo que se quiere.
     */
    public static function cuentaId(): int
    {
        return self::viendoCuentaId() ?? (int) $_SESSION['cuenta_id'];
    }

    /** Cuenta propia del usuario de la sesion, ignorando la vista de superadmin. */
    public static function cuentaIdReal(): int
    {
        return (int) $_SESSION['cuenta_id'];
    }

    /**
     * Cuenta que un superadmin esta mirando, o null si no hay vista activa.
     *
     * ESTO NO AUTORIZA NADA POR SI SOLO. Es una marca de sesion; que solo la
     * pueda poner un superadmin lo garantiza el handler que la escribe, que
     * llama a exigirSuperadmin() como primera linea. Cualquier codigo que use
     * esta marca para decidir un permiso tiene que comprobar el rol contra la
     * BASE, no contra la sesion.
     */
    public static function viendoCuentaId(): ?int
    {
        $id = $_SESSION['superadmin_viendo_cuenta_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    public static function iniciarVista(int $cuentaId): void
    {
        $_SESSION['superadmin_viendo_cuenta_id'] = $cuentaId;
    }

    public static function terminarVista(): void
    {
        unset($_SESSION['superadmin_viendo_cuenta_id']);
    }

    public static function usuarioId(): int
    {
        return (int) $_SESSION['usuario_id'];
    }

    /** Redirige a /login si no hay sesion activa; si la hay, retorna normalmente. */
    public static function requerirSesion(): void
    {
        if (! self::autenticado()) {
            header('Location: /login');
            exit;
        }
    }
}
