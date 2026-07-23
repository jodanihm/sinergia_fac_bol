<?php

declare(strict_types=1);

/**
 * Conexion PDO a la base del SaaS (sinergia_fac_bol, contenedor
 * sinergia_mysql) -- NO la base de produccion de Plantiflex (facturacion_cl)
 * que usa public/index.php.
 *
 * DB_HOST/DB_NAME/DB_USER/DB_PASS NO tienen default: si alguna falta o viene
 * vacia, se lanza una excepcion en vez de conectar silenciosamente contra un
 * valor por defecto que podria ser la base equivocada. Solo DB_PORT mantiene
 * default (3306), por ser un dato de bajo riesgo.
 *
 * Cache estatica simple: una sola conexion por request (el panel toca la BD
 * varias veces por request: autenticacion + handler).
 */
final class Db
{
    private static ?PDO $conexion = null;

    public static function conexion(): PDO
    {
        if (self::$conexion === null) {
            self::$conexion = new PDO(
                sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    self::requerirEnv('DB_HOST'),
                    getenv('DB_PORT') ?: '3306',
                    self::requerirEnv('DB_NAME'),
                ),
                self::requerirEnv('DB_USER'),
                self::requerirEnv('DB_PASS'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        }

        return self::$conexion;
    }

    /**
     * Exige que la env var este definida y no vacia. Falla ruidoso en vez de
     * caer en un default silencioso que podria apuntar a la base equivocada.
     */
    private static function requerirEnv(string $nombre): string
    {
        $valor = getenv($nombre);
        if ($valor === false || $valor === '') {
            throw new RuntimeException("config de BD incompleta: falta {$nombre}");
        }

        return $valor;
    }
}
