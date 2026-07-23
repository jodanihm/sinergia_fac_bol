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

    public static function cuentaId(): int
    {
        return (int) $_SESSION['cuenta_id'];
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
