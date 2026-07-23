-- =============================================================================
-- Migracion 005: certificacion_confirmada_at en dte_emisor.
--
-- El SII NO expone webservice que informe "este RUT quedo certificado": la
-- aprobacion se declara manualmente en el portal del SII (pe_avance7,
-- irreversible). Por eso la estacion 6 del panel no puede autodetectarse; se
-- registra la CONFIRMACION EXPLICITA del tenant de que declaro cumplimiento y
-- fue autorizado como emisor electronico.
--
-- La columna vive en dte_emisor porque la certificacion es de la EMPRESA/RUT
-- ante el SII, no de la cuenta de login. dte_emisor ya esta scopeado por
-- cuenta_id + ambiente (uk_emisor: rut_emisor + ambiente).
--
--   certificacion_confirmada_at = NULL     -> no confirmada
--   certificacion_confirmada_at = DATETIME -> fecha en que el tenant confirmo
--
-- 100% aditiva: no se borra ni renombra ninguna columna existente, no se toca
-- ninguna fila. Columna NULLABLE: las filas existentes de dte_emisor no se
-- rompen (quedan "no confirmada", que es lo correcto).
--
-- Portable a MySQL 8.x (Oracle) y MariaDB 10.x: SIN IF NOT EXISTS en la
-- clausula ADD COLUMN (esa variante es exclusiva de MariaDB; MySQL 8.4 falla
-- con error de sintaxis si se usa ahi).
-- =============================================================================

ALTER TABLE dte_emisor
    ADD COLUMN certificacion_confirmada_at DATETIME NULL AFTER resolucion_numero;
