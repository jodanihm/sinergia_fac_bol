-- =============================================================================
-- Migracion 002: ambiente en api_key (modelo test/live).
--
-- Agrega la columna `ambiente` a la tabla `api_key`: cada key autenticada
-- resuelve SIEMPRE a un ambiente fijo (una key de test -> 'certificacion', una
-- key de produccion/live -> 'produccion'). Asi el ambiente se deriva de la key,
-- nunca del payload del cliente.
--
-- 100% aditiva: un unico ALTER TABLE, ninguna tabla/columna existente se toca.
-- Asume que `api_key` ya existe (creada por 001_tenancy.sql).
--
-- Portable a MySQL 8.x (Oracle): SIN IF NOT EXISTS en la clausula ADD COLUMN
-- (esa variante es exclusiva de MariaDB; MySQL 8.4 falla con error de sintaxis
-- si se usa ahi).
-- =============================================================================

ALTER TABLE api_key
    ADD COLUMN ambiente ENUM('certificacion','produccion') NOT NULL DEFAULT 'certificacion' AFTER rut_emisor_scope;
