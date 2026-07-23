-- =============================================================================
-- Migracion 001: capa de tenancy (cuenta / usuario / api_key) para el SaaS
-- multi-tenant.
--
-- REGLAS DE ESTA MIGRACION:
--   - 100% aditiva: no se borra ni renombra ninguna tabla/columna existente,
--     no se toca ninguna fila de dte_caf, dte_folio, dte_folio_log,
--     dte_certificado, dte_emisor ni dte_emitido.
--   - El motor DTE (src/, integration/plantiflex/*Repository.php) NO se
--     modifica en este paso. Esta migracion solo agrega esquema.
--   - NO se ejecuta automaticamente contra ninguna base.
--   - Portable a MySQL 8.x (Oracle) y MariaDB 10.x: los CREATE TABLE usan
--     IF NOT EXISTS (soportado por ambos motores), pero los ALTER TABLE NO
--     usan IF NOT EXISTS en sus clausulas ADD COLUMN/ADD INDEX/ADD CONSTRAINT
--     porque esa variante es exclusiva de MariaDB y MySQL de Oracle falla con
--     error de sintaxis si se usa ahi.
--   - Asume que dte_emisor, dte_certificado y dte_idempotencia YA EXISTEN y
--     estan VACIAS: la base del SaaS se construye desde cero a partir de un
--     dump de SOLO ESTRUCTURA del motor probado (sin filas). Por eso la
--     seccion 6 aplica el cambio de PRIMARY KEY de forma directa, sin pasos
--     intermedios de backfill.
--
-- Relacion cuenta <-> emisor: el esquema permite 1:N (una cuenta_id puede
-- tener varios rut_emisor en dte_emisor a futuro). La restriccion a 1:1 se
-- aplica en la capa de aplicacion, NO aqui: por eso dte_emisor.cuenta_id NO
-- lleva UNIQUE.
-- =============================================================================


-- -----------------------------------------------------------------------------
-- 1. cuenta: el tenant del SaaS (quien contrata el servicio).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cuenta (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email      VARCHAR(255) NOT NULL,
    nombre     VARCHAR(255) NOT NULL,
    estado     ENUM('activa','suspendida') NOT NULL DEFAULT 'activa',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_cuenta_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- 2. usuario: personas que acceden a una cuenta (login humano, no API).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuario (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cuenta_id     BIGINT UNSIGNED NOT NULL,
    email         VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    rol           VARCHAR(50) NOT NULL DEFAULT 'owner',
    estado        ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_usuario_email (email),
    KEY ix_usuario_cuenta (cuenta_id),
    CONSTRAINT fk_usuario_cuenta FOREIGN KEY (cuenta_id) REFERENCES cuenta (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- 3. api_key: credenciales de maquina a maquina, ligadas a una cuenta y
-- acotadas (scope) a un rut_emisor. Reemplaza al X-Api-Key global actual.
--
-- key_hash guarda el hash de la key real (nunca la key en claro, igual que
-- dte_certificado con el cifrado del certificado). prefijo es la parte
-- visible/no secreta que permite identificar la key en logs/UI sin revelarla.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS api_key (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cuenta_id        BIGINT UNSIGNED NOT NULL,
    key_hash         VARCHAR(255) NOT NULL,
    prefijo          VARCHAR(16) NOT NULL,
    rut_emisor_scope VARCHAR(20) NOT NULL,
    estado           ENUM('activa','revocada') NOT NULL DEFAULT 'activa',
    last_used_at     DATETIME NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_apikey_prefijo (prefijo),
    KEY ix_apikey_cuenta (cuenta_id),
    CONSTRAINT fk_apikey_cuenta FOREIGN KEY (cuenta_id) REFERENCES cuenta (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- 4. dte_emisor: agrega cuenta_id (NULLABLE) para poder mapear emisores
-- existentes a una cuenta sin romper las filas actuales de Plantiflex (que
-- quedan con cuenta_id = NULL hasta que se migren explicitamente).
--
-- Sin UNIQUE en cuenta_id a proposito (ver nota de relacion 1:N al inicio).
-- -----------------------------------------------------------------------------
ALTER TABLE dte_emisor
    ADD COLUMN cuenta_id BIGINT UNSIGNED NULL AFTER rut_emisor;

ALTER TABLE dte_emisor
    ADD CONSTRAINT fk_emisor_cuenta FOREIGN KEY (cuenta_id) REFERENCES cuenta (id);

-- Indice de apoyo para filtrar/joinear por cuenta (no existia antes).
ALTER TABLE dte_emisor
    ADD INDEX ix_emisor_cuenta (cuenta_id);


-- -----------------------------------------------------------------------------
-- 5. dte_certificado: agrega dek_envuelta para migrar a envelope encryption
-- (DEK por certificado, cifrada con la KEK maestra) sin tocar cert_data_cifrado
-- / pkey_data_cifrado existentes ni el flujo de cifrado actual (AES-256-GCM
-- con llave maestra unica). Mientras dek_envuelta sea NULL, el certificado
-- sigue leyendose/escribiendose exactamente como hoy.
-- -----------------------------------------------------------------------------
ALTER TABLE dte_certificado
    ADD COLUMN dek_envuelta TEXT NULL AFTER pkey_data_cifrado;


-- -----------------------------------------------------------------------------
-- 6. dte_idempotencia: hoy su PK es (ambiente, clave) -- ver
-- docs/HANDOFF_CIERRE_2026-06-22.md. NO tiene columna rut_emisor. Para
-- soportar multi-tenant sin colisiones de Idempotency-Key entre cuentas
-- distintas, la PK pasa a (rut_emisor, ambiente, clave).
--
-- La tabla viene VACIA (dump de solo estructura), asi que rut_emisor se
-- agrega directamente como NOT NULL y el cambio de PK es inmediato: no hay
-- filas que puedan violar la restriccion ni que requieran backfill.
-- -----------------------------------------------------------------------------
ALTER TABLE dte_idempotencia
    ADD COLUMN rut_emisor VARCHAR(20) NOT NULL AFTER ambiente;

ALTER TABLE dte_idempotencia
    DROP PRIMARY KEY;

ALTER TABLE dte_idempotencia
    ADD PRIMARY KEY (rut_emisor, ambiente, clave);
