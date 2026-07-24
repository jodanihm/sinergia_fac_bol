-- =============================================================================
-- Migracion 015: tabla cliente (maestro de clientes/receptores por tenant).
--
-- Primer tabla del modulo de producto M2 (Maestros). Un cliente es un receptor
-- al que el tenant emite documentos. El maestro es de la EMPRESA, no del
-- ambiente: por eso NO lleva columna ambiente (el mismo cliente vale en
-- certificacion y en produccion).
--
-- AISLAMIENTO POR cuenta_id (no por rut_emisor): cuenta_id es la identidad
-- estable del tenant (PK de cuenta). rut_emisor podria corregirse en teoria, y
-- si fuera la clave de aislamiento los maestros quedarian huerfanos si eso
-- pasara. Por eso el scope y la clave unica se anclan a cuenta_id. La clave
-- unica de un cliente dentro de un tenant es su RUT: UNIQUE(cuenta_id,
-- rut_cliente) -- el mismo RUT puede existir en cuentas distintas (cada tenant
-- tiene su propio maestro).
--
-- NORMALIZACION de rut_cliente: la columna almacena el RUT ya NORMALIZADO
-- (mayuscula, sin puntos ni espacios, con guion y digito verificador; mismo
-- criterio que Rut::normalizar del panel). La normalizacion es responsabilidad
-- del repo/handler en PHP ANTES de insertar; la base solo guarda el formato ya
-- normalizado. Asi el UNIQUE distingue por el valor canonico y no por variantes
-- con/sin puntos ("77.724.622-4" y "77724622-4" no deben coexistir).
--
-- CAMPOS REQUERIDOS: solo rut_cliente y razon_social. giro/direccion/comuna son
-- NULLABLE aqui, PERO el motor los EXIGE al emitir un documento real (ver
-- Dto\Receptor en src/ y validarDocumentoDte en public/index.php). En M3 la UI
-- del CRUD debera marcarlos como RECOMENDADOS / necesarios-para-emitir aunque
-- en la base no sean NOT NULL: un cliente puede darse de alta incompleto y
-- completarse antes de su primera emision.
--
-- 100% aditiva: tabla nueva, no toca ninguna tabla existente. CREATE TABLE con
-- IF NOT EXISTS (soportado por MySQL 8.x de Oracle y MariaDB 10.x). FK a
-- cuenta(id) con ON DELETE / ON UPDATE RESTRICT EXPLICITOS: no se borra una
-- cuenta que tenga clientes; la baja de un cliente es LOGICA via la columna
-- activo, nunca fisica. El UNIQUE(cuenta_id, rut_cliente) sirve ademas como
-- indice de la FK (prefijo izquierdo cuenta_id), por eso NO se agrega un
-- KEY(cuenta_id) redundante.
-- =============================================================================

CREATE TABLE IF NOT EXISTS cliente (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cuenta_id    BIGINT UNSIGNED NOT NULL,
    rut_cliente  VARCHAR(20)  NOT NULL COMMENT 'RUT normalizado (ej. 77724622-4); clave unica dentro de la cuenta',
    razon_social VARCHAR(255) NOT NULL,
    giro         VARCHAR(255) NULL COMMENT 'Nullable aqui; el motor lo exige al emitir (M3)',
    direccion    VARCHAR(255) NULL COMMENT 'Nullable aqui; el motor lo exige al emitir (M3)',
    comuna       VARCHAR(100) NULL COMMENT 'Nullable aqui; el motor lo exige al emitir (M3)',
    email        VARCHAR(255) NULL,
    telefono     VARCHAR(50)  NULL,
    activo       TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1=activo, 0=baja logica (nunca se borra fisico)',
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_cliente_rut (cuenta_id, rut_cliente),
    CONSTRAINT fk_cliente_cuenta FOREIGN KEY (cuenta_id)
        REFERENCES cuenta (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
