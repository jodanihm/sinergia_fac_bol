-- =============================================================================
-- Migracion 016: tabla producto (maestro de productos/servicios por tenant).
--
-- Segunda tabla del modulo M2 (Maestros). Mismo criterio de diseno que cliente
-- (015): escopado por cuenta_id (identidad estable del tenant), SIN columna
-- ambiente (el maestro es de la empresa; vale en certificacion y produccion),
-- baja LOGICA via la columna activo (nunca fisica).
--
-- codigo (SKU) es OPCIONAL y unico por cuenta SOLO cuando no es NULL:
-- UNIQUE(cuenta_id, codigo). En MySQL un indice UNIQUE permite MULTIPLES filas
-- con NULL en la columna indexada, asi que varios productos SIN codigo pueden
-- coexistir en la misma cuenta; la unicidad solo se exige entre codigos NO
-- nulos del mismo tenant. nombre es el unico campo obligatorio.
--
-- precio_unitario es DECIMAL y NULLABLE: un maestro puede tener el precio sin
-- definir y completarse despues. Se guarda con decimales para no perder
-- precision antes de tiempo; el motor redondea/valida al emitir (M3).
-- exento (IVA): 0 = afecto (default), 1 = exento; el motor lo usa en Detalle.
--
-- 100% aditiva: tabla nueva, no toca ninguna existente. CREATE TABLE con IF NOT
-- EXISTS (soportado por MySQL 8.x de Oracle y MariaDB 10.x). FK a cuenta(id)
-- con ON DELETE / ON UPDATE RESTRICT EXPLICITOS (no se borra una cuenta con
-- productos). El UNIQUE(cuenta_id, codigo) sirve ademas como indice de la FK
-- (prefijo izquierdo cuenta_id), por eso NO se agrega un KEY(cuenta_id)
-- redundante.
-- =============================================================================

CREATE TABLE IF NOT EXISTS producto (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cuenta_id       BIGINT UNSIGNED NOT NULL,
    codigo          VARCHAR(50)   NULL COMMENT 'SKU opcional; unico por cuenta solo cuando no es NULL',
    nombre          VARCHAR(255)  NOT NULL,
    descripcion     VARCHAR(500)  NULL,
    precio_unitario DECIMAL(14,4) NULL COMMENT 'Nullable; el motor redondea/valida al emitir (M3)',
    unidad          VARCHAR(20)   NULL COMMENT 'Unidad de medida (ej. UN, KG, HH)',
    exento          TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '0=afecto a IVA, 1=exento',
    activo          TINYINT(1)    NOT NULL DEFAULT 1 COMMENT '1=activo, 0=baja logica (nunca se borra fisico)',
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_producto_codigo (cuenta_id, codigo),
    CONSTRAINT fk_producto_cuenta FOREIGN KEY (cuenta_id)
        REFERENCES cuenta (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
