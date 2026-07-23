-- =============================================================================
-- Migracion 004: dek_envuelta en dte_caf.
--
-- La etapa 4 (carga de CAF) usara envelope encryption igual que el
-- certificado digital (ver 001_tenancy.sql / dte_certificado.dek_envuelta):
-- una DEK aleatoria por CAF cifra el XML del CAF completo, y esa DEK se
-- guarda envuelta (cifrada) con la KEK maestra (CRYPTO_MASTER_KEY).
--
-- 100% aditiva: no se borra ni renombra ninguna columna existente, no se toca
-- ninguna fila. Columna NULLABLE: las filas existentes de dte_caf no se
-- rompen; dek_envuelta se poblara al cargar cada CAF nuevo. Si dek_envuelta
-- es NULL, se asume el camino legacy de cifrado directo de caf_xml_cifrado
-- con la KEK (mismo fallback que ya existe para el certificado).
--
-- Portable a MySQL 8.x (Oracle) y MariaDB 10.x: SIN IF NOT EXISTS en la
-- clausula ADD COLUMN (esa variante es exclusiva de MariaDB; MySQL 8.4 falla
-- con error de sintaxis si se usa ahi).
--
-- Asume que dte_caf ya existe con la columna caf_xml_cifrado (confirmado en
-- estructura_facturacion_cl.sql).
-- =============================================================================

ALTER TABLE dte_caf
    ADD COLUMN dek_envuelta TEXT NULL AFTER caf_xml_cifrado;
