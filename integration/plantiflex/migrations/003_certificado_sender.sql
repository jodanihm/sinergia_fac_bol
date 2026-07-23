-- =============================================================================
-- Migracion 003: rut_sender en dte_certificado.
--
-- El RUT del certificado digital es el del FIRMANTE (persona natural
-- autorizada, ej. 13520634-2), distinto del rut_emisor de la empresa (ej.
-- 77724622-4). Ese RUT del firmante es el "sender" que el motor envia al SII
-- (antes una constante fija, FACT_RUT_SENDER). En multi-tenant cada
-- certificado tiene su propio sender, asi que se guarda por fila -- en
-- dte_certificado porque el sender es el titular del certificado, no del
-- emisor.
--
-- 100% aditiva: no se borra ni renombra ninguna columna existente, no se toca
-- ninguna fila. Columna NULLABLE: las filas existentes de dte_certificado no
-- se rompen; rut_sender se poblara al subir el certificado (puede quedar NULL
-- si no se logra extraer el RUT del certificado).
--
-- Portable a MySQL 8.x (Oracle) y MariaDB 10.x: SIN IF NOT EXISTS en la
-- clausula ADD COLUMN (esa variante es exclusiva de MariaDB; MySQL 8.4 falla
-- con error de sintaxis si se usa ahi).
--
-- Asume que dte_certificado ya existe con la columna dek_envuelta (agregada
-- por 001_tenancy.sql).
-- =============================================================================

ALTER TABLE dte_certificado
    ADD COLUMN rut_sender VARCHAR(20) NULL AFTER dek_envuelta;
