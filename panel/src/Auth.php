<?php

declare(strict_types=1);

/**
 * Sesion PHP nativa para el login humano del panel. Guarda solo usuario_id y
 * cuenta_id en $_SESSION; nunca password ni password_hash.
 */
final class Auth
{
    public static function iniciar(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
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
