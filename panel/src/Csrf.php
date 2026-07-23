<?php

declare(strict_types=1);

/**
 * Proteccion CSRF para los formularios del panel, sobre la sesion PHP nativa
 * (ver Auth.php).
 *
 * Deliberadamente DESACOPLADA de Auth: ninguna de las dos clases llama a la
 * otra. El panel llama a Auth::iniciar() (session_start()) para CUALQUIER
 * visitante, autenticado o no -- por eso el token existe tambien en /login y
 * /registro, antes de que haya sesion de usuario. Es el LLAMADOR (el handler
 * de login) quien decide regenerar el token justo despues de Auth::login(),
 * igual que ya decide regenerar el session id ahi mismo -- Csrf no sabe
 * cuando alguien inicia sesion.
 *
 * Auth::logout() ya vacia $_SESSION por completo (`$_SESSION = []`) y
 * destruye la sesion, asi que el token queda invalidado con eso: no hace
 * falta un metodo Csrf::limpiar() que nadie mas llamaria.
 */
final class Csrf
{
    private const SESSION_KEY = 'csrf_token';

    /**
     * Token de la sesion actual: lo genera si no existe todavia, o retorna
     * el mismo que ya habia -- NUNCA regenera en cada request, o cualquier
     * formulario abierto en otra pestaña/ventana quedaria invalido.
     */
    public static function generarToken(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = self::crear();
        }

        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Fuerza un token NUEVO, descartando el anterior. Llamar justo despues
     * de Auth::login() (mismo momento en que ya se regenera el session id):
     * un token emitido antes de autenticarse no deberia seguir sirviendo
     * despues.
     */
    public static function regenerarToken(): string
    {
        $_SESSION[self::SESSION_KEY] = self::crear();

        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Compara contra el token de la sesion con hash_equals() -- NUNCA con
     * === directo, para no filtrar por timing cuanto del token coincide.
     */
    public static function validar(string $tokenRecibido): bool
    {
        $tokenSesion = (string) ($_SESSION[self::SESSION_KEY] ?? '');

        return $tokenSesion !== '' && $tokenRecibido !== '' && hash_equals($tokenSesion, $tokenRecibido);
    }

    private static function crear(): string
    {
        return bin2hex(random_bytes(32));
    }
}
