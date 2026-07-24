-- =============================================================================
-- Migracion 019: tabla lote_carga (M4, carga masiva de notas de venta).
--
-- Un lote_carga es UN archivo Excel subido. No lleva columna estado: el
-- estado real del lote se DERIVA contando nota_venta.estado de sus filas
-- (pendiente/en_proceso/facturada/error) -- otra columna de estado propia se
-- podria desincronizar de la realidad, asi que no se agrega.
--
-- Escopado por cuenta_id (identidad estable del tenant), mismo criterio que
-- cliente/producto (M2). usuario_id registra quien subio el archivo (no
-- confundir con cuenta_id: una cuenta puede tener varios usuarios a futuro).
--
-- 100% aditiva: tabla nueva, no toca ninguna existente. CREATE TABLE con IF
-- NOT EXISTS (soportado por MySQL 8.x de Oracle y MariaDB 10.x). FK a
-- cuenta(id) y usuario(id) con ON DELETE/ON UPDATE RESTRICT explicitos (no se
-- borra una cuenta ni un usuario que tenga lotes cargados).
-- =============================================================================

CREATE TABLE IF NOT EXISTS lote_carga (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cuenta_id      BIGINT UNSIGNED NOT NULL,
    usuario_id     BIGINT UNSIGNED NOT NULL COMMENT 'quien subio el archivo',
    nombre_archivo VARCHAR(255) NOT NULL,
    total_filas    INT UNSIGNED NOT NULL DEFAULT 0,
    filas_validas  INT UNSIGNED NOT NULL DEFAULT 0,
    filas_error    INT UNSIGNED NOT NULL DEFAULT 0,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_lote_carga_cuenta (cuenta_id, created_at),
    CONSTRAINT fk_lote_carga_cuenta FOREIGN KEY (cuenta_id)
        REFERENCES cuenta (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_lote_carga_usuario FOREIGN KEY (usuario_id)
        REFERENCES usuario (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
