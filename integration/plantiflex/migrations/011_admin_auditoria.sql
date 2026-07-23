-- =============================================================================
-- Migracion 011: tabla admin_auditoria (changelog append-only de acciones
-- administrativas de superadmin).
--
-- No agrega columna de rol: usuario.rol ya existe (VARCHAR(50) DEFAULT
-- 'owner', ver 001_tenancy.sql) y admite cualquier valor de texto. Esta
-- migracion NO la toca: 'superadmin' se define aqui solo como convencion de
-- valor reservado para ese campo, marcado manualmente por quien audite esta
-- tarea (ver UPDATE de ejemplo en el mensaje que acompana esta migracion).
--
-- Cada fila registra UNA accion administrativa ya ejecutada:
--   usuario_id      -> quien la ejecuto (FK a usuario.id).
--   accion          -> ej. 'cuenta.suspender' / 'cuenta.reactivar'.
--   entidad_tipo    -> ej. 'cuenta'.
--   entidad_id      -> id de la fila afectada (ej. cuenta.id).
--   valor_anterior  -> snapshot JSON del estado ANTES del cambio.
--   valor_nuevo     -> snapshot JSON del estado DESPUES del cambio.
--   created_at      -> cuando se registro.
--
-- SIN updated_at a proposito: un changelog es append-only, ninguna fila ya
-- escrita se edita despues (si hiciera falta corregir algo, se agrega una
-- fila nueva que lo explique, nunca se pisa la anterior).
--
-- 100% aditiva: tabla nueva, no se toca ninguna tabla existente.
--
-- Portable a MySQL 8.x (Oracle) y MariaDB 10.x: CREATE TABLE con IF NOT
-- EXISTS (soportado por ambos motores).
-- =============================================================================

CREATE TABLE IF NOT EXISTS admin_auditoria (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id     BIGINT UNSIGNED NOT NULL,
    accion         VARCHAR(100) NOT NULL,
    entidad_tipo   VARCHAR(50) NOT NULL,
    entidad_id     BIGINT UNSIGNED NOT NULL,
    valor_anterior JSON NULL,
    valor_nuevo    JSON NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_auditoria_entidad (entidad_tipo, entidad_id),
    KEY ix_auditoria_usuario (usuario_id),
    CONSTRAINT fk_auditoria_usuario FOREIGN KEY (usuario_id) REFERENCES usuario (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
