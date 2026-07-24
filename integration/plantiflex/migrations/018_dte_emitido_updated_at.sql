-- =============================================================================
-- Migracion 018: columna updated_at en dte_emitido (M5, Panel de emision).
--
-- El listado de M5 necesita saber cuando se actualizo por ultima vez el estado
-- de un documento (ej. tras consultar estado SII). dte_emitido solo tenia
-- created_at (momento de la emision), sin rastro de actualizaciones
-- posteriores.
--
-- TIMESTAMP (no DATETIME) para calzar con el tipo de created_at existente en
-- esta misma tabla. ON UPDATE CURRENT_TIMESTAMP nativo de MySQL/MariaDB: se
-- actualiza solo con cualquier UPDATE que cambie la fila, sin tocar ningun
-- codigo existente. Mismo patron ya usado en cliente.updated_at (migracion
-- 015) y producto.updated_at (migracion 016).
--
-- Aditiva: ALTER TABLE ADD COLUMN con DEFAULT CURRENT_TIMESTAMP hace backfill
-- automatico de las filas existentes (se les asigna el momento del ALTER).
-- Sin IF NOT EXISTS en ADD COLUMN (exclusivo de MariaDB; MySQL 8.x de Oracle
-- falla con error de sintaxis si se usa ahi).
-- =============================================================================

ALTER TABLE dte_emitido
    ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP AFTER created_at;
