-- =============================================================================
-- Migracion 041: las filas del Excel que formaron cada nota de venta, y cuantos
-- documentos produjo un lote.
--
-- POR QUE EXISTE
-- -----------------------------------------------------------------------------
-- Hasta aqui, la carga masiva creaba UNA nota de venta por FILA del Excel, sin
-- excepcion: dos filas del mismo RUT daban dos facturas. Desde esta entrega, las
-- filas del mismo cliente que comparten las condiciones del documento se agrupan
-- en UNA factura con varias lineas.
--
-- Y ESO ROMPE UNA GARANTIA SI NO SE HACE ALGO. nota_venta.identificador_externo
-- es la IDEMPOTENCIA DE NEGOCIO: UNIQUE(cuenta_id, identificador_externo), y su
-- proposito -- escrito en la migracion 020 -- es que recargar el mismo Excel
-- falle en vez de duplicar facturas. Hay UN identificador por FILA. Si tres filas
-- se funden en una nota, dos identificadores no tendrian donde vivir, y al
-- recargar el archivo esas dos filas pasarian la validacion como si fueran
-- nuevas. La proteccion se degradaria de "rechazo limpio y temprano" a "estallido
-- dentro de la transaccion".
--
-- SE DESCARTO concatenarlos en el campo actual ("A+B+C"): es VARCHAR(100) y con
-- identificadores de reserva reales se pasa de largo justo cuando el archivo es
-- grande, o sea cuando esto mas importa. Y la busqueda por fila dejaria de
-- encontrarlos.
--
--
-- LA TABLA
-- -----------------------------------------------------------------------------
-- Una fila por identificador de origen. El UNIQUE se mueve aqui, POR CUENTA, y es
-- el que de verdad protege: la comprobacion "¿este identificador ya se cargo?"
-- pasa a mirar esta tabla, que los tiene TODOS.
--
-- nota_venta.identificador_externo NO SE TOCA NI SE VACIA. Sigue guardando el
-- primero del grupo y su UNIQUE sigue en pie: quitarlo obligaria a reescribir la
-- 020 y dejaria sin proteccion a las notas ya cargadas. Las dos conviven, y esta
-- tabla es la que manda para el archivo nuevo.
--
-- ON DELETE CASCADE hacia nota_venta -- y NO RESTRICT como el resto del esquema
-- -- porque estas filas no son un hecho independiente: son de la nota. Si algun
-- dia se borra una nota, sus origenes no significan nada solos.
--
--
-- lote_carga.total_documentos
-- -----------------------------------------------------------------------------
-- total_filas / filas_validas / filas_error siguen contando FILAS, y se quedan
-- como estan: los errores se reportan por fila y esa cuenta tiene que seguir
-- calzando con el Excel que el usuario tiene delante. Lo que faltaba era el otro
-- numero -- cuantas FACTURAS salieron --, que desde el agrupamiento ya no es el
-- mismo. Sin el, la pantalla del lote diria "180 filas validas" y mostraria 40
-- notas, sin nada que explique la diferencia.
--
-- DEFAULT 0 y no NULL: los lotes ya cargados no tienen el dato, y 0 se distingue
-- de cualquier valor real. La pantalla lo trata como "lote anterior al
-- agrupamiento" y cae a contar notas, que para ellos es exacto.
--
--
-- IDEMPOTENCIA
-- -----------------------------------------------------------------------------
-- CREATE TABLE IF NOT EXISTS para la tabla. Para la columna, el patron de la 025
-- a la 030 y la 040: information_schema y el ALTER solo si falta.
-- =============================================================================

CREATE TABLE IF NOT EXISTS nota_venta_origen (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cuenta_id             BIGINT UNSIGNED NOT NULL,
    nota_venta_id         BIGINT UNSIGNED NOT NULL,
    identificador_externo VARCHAR(100) NOT NULL COMMENT 'el de UNA fila del Excel; varias filas pueden apuntar a la misma nota',
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- LA GARANTIA. Es el mismo UNIQUE de la 020, movido a donde estan TODOS los
    -- identificadores y no solo el primero de cada grupo.
    UNIQUE KEY uk_nota_venta_origen (cuenta_id, identificador_externo),
    KEY ix_nota_venta_origen_nota (nota_venta_id),
    CONSTRAINT fk_nota_venta_origen_nota FOREIGN KEY (nota_venta_id)
        REFERENCES nota_venta (id) ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT fk_nota_venta_origen_cuenta FOREIGN KEY (cuenta_id)
        REFERENCES cuenta (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE lote_carga
            ADD COLUMN total_documentos INT UNSIGNED NOT NULL DEFAULT 0
                COMMENT ''notas creadas tras agrupar por cliente; 0 = lote anterior al agrupamiento''
                AFTER filas_error',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'lote_carga'
      AND COLUMN_NAME  = 'total_documentos'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =============================================================================
-- VERIFICACION POSTERIOR (solo lectura):
--
--   SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT
--     FROM information_schema.COLUMNS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lote_carga'
--      AND COLUMN_NAME = 'total_documentos';
--   -- int unsigned, 0
--
--   SELECT COUNT(*) FROM nota_venta_origen;   -- 0 recien aplicada
--
--   -- Ninguna nota vieja pierde su proteccion: su identificador sigue en
--   -- nota_venta y su UNIQUE sigue vigente.
--   SELECT COUNT(*) FROM nota_venta WHERE identificador_externo IS NOT NULL;
-- =============================================================================
