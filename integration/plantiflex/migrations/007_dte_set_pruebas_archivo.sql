-- =============================================================================
-- Migracion 007: tabla dte_set_pruebas_archivo (persistencia del archivo
-- SIISetDePruebas<RUT>.txt subido por el tenant, para el preview del panel).
--
-- Paso 2 del flujo de certificacion en el panel: el tenant sube el archivo que
-- el SII le entrega tras solicitar el set de pruebas; SetPruebasParser::parse()
-- (src/Sii/SetPruebasParser.php, validado 8/8 contra el archivo real de EASY
-- AGENDA SPA) lo interpreta para mostrar un preview ANTES de emitir nada. Esta
-- tabla solo persiste el archivo subido; el preview se recalcula en cada GET
-- re-parseando (no se guarda la estructura parseada, para no duplicar la
-- fuente de verdad ni quedar desincronizado si el parser mejora despues).
--
-- CONVENCIONES: replica las de dte_libro (006_dte_libro.sql) por ser tabla
-- hermana del dominio DTE: rut_emisor+ambiente como scope (no cuenta_id
-- directo), LONGBLOB para el contenido (el archivo del SII viene en
-- ISO-8859-1, no UTF-8 -- igual razon por la que dte_libro/dte_emitido
-- guardan el XML en LONGBLOB), mismo charset/collation (utf8mb4_0900_ai_ci).
--
-- El contenido se guarda en CLARO (no cifrado): es un archivo de pruebas de
-- certificacion emitido por el propio SII al tenant, no material secreto como
-- el CAF/certificado.
--
-- Un solo archivo vigente por tenant+ambiente (UNIQUE rut_emisor+ambiente): re-
-- subir reemplaza el anterior, no se guarda historial -- el preview solo
-- necesita reflejar el ultimo archivo que el tenant subio.
--
-- 100% aditiva: tabla nueva, no se toca ninguna tabla existente. Portable a
-- MySQL 8.x (Oracle) y MariaDB 10.x: CREATE TABLE usa IF NOT EXISTS.
-- =============================================================================

CREATE TABLE IF NOT EXISTS dte_set_pruebas_archivo (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rut_emisor     VARCHAR(20) NOT NULL,
    ambiente       ENUM('certificacion','produccion') NOT NULL,
    nombre_archivo VARCHAR(255) NOT NULL,
    contenido      LONGBLOB NOT NULL,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY ux_set_pruebas_emisor (rut_emisor, ambiente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
