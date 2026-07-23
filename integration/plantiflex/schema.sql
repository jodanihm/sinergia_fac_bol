-- =============================================================================
-- Esquema MySQL para gestion de folios y CAF (DTE Chile).
--
-- Pertenece al SISTEMA ANFITRION (Plantiflex), NO al paquete plantiflex/facturacion-cl.
-- El paquete solo define el contrato FolioRepositoryInterface; este SQL y el
-- MySqlFolioRepository.php son una implementacion concreta de referencia para
-- proveedores que NO administran CAF en su backend (ej: API Gateway).
--
-- Diseno:
--   - Multi-tenant: rut_emisor en todas las tablas. Una sola base puede atender
--     a varios emisores sin colisiones.
--   - Multi-ambiente: certificacion y produccion usan CAFs distintos y nunca
--     se mezclan.
--   - dte_folio_log es bitacora intocable: un folio jamas se repite por
--     (rut, tipo, ambiente).
--
-- REGLAS TRIBUTARIAS (no las viole la app):
--   1. La numeracion debe ser correlativa, sin saltos ni repeticiones.
--   2. Un folio se "quema" al asignarse, no al emitirse con exito.
--   3. Si el SII rechaza el DTE, el folio queda registrado con
--      emision_exitosa=FALSE; no se reutiliza desde esta capa.
--
-- IMPORTANTE: este archivo NO guarda certificado ni llave privada. Solo CAF
-- y folios. El certificado del emisor se administra en otra capa.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- CAF cargados por emisor. Cada fila = un archivo CAF.xml entregado por el SII.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dte_caf (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rut_emisor      VARCHAR(20)  NOT NULL COMMENT 'RUT con DV, ej 77724622-4',
    tipo_dte        INT          NOT NULL COMMENT '33,34,39,41,52,56,61',
    ambiente        ENUM('certificacion','produccion') NOT NULL,
    folio_desde     INT UNSIGNED NOT NULL,
    folio_hasta     INT UNSIGNED NOT NULL,
    -- El XML del CAF se guarda CIFRADO. El cifrado/descifrado lo inyecta el
    -- sistema anfitrion (ej: AES-256-GCM con llave maestra fuera de DB).
    -- TODO: el sistema anfitrion inyecta cifrado/descifrado (ej AES). No se
    -- implementa aqui: este SQL solo provee el contenedor.
    caf_xml_cifrado LONGTEXT     NOT NULL,
    estado          ENUM('activo','agotado') NOT NULL DEFAULT 'activo',
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- Evita cargar dos veces el mismo rango para el mismo emisor/tipo/ambiente.
    UNIQUE KEY uk_caf_rango (rut_emisor, tipo_dte, ambiente, folio_desde, folio_hasta),
    KEY ix_caf_busqueda (rut_emisor, tipo_dte, ambiente, estado, folio_desde),
    -- Sanity check: rango valido.
    CONSTRAINT chk_caf_rango CHECK (folio_hasta >= folio_desde)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Contador atomico por CAF. Una fila por CAF cargado.
-- proximo_folio es el SIGUIENTE folio a entregar (no el ultimo entregado).
-- La atomicidad se logra con SELECT ... FOR UPDATE dentro de una transaccion
-- en la implementacion PHP. NO basta con un UNIQUE: la condicion de carrera
-- esta en la lectura previa al UPDATE.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dte_folio (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    caf_id         BIGINT UNSIGNED NOT NULL,
    rut_emisor     VARCHAR(20) NOT NULL,
    tipo_dte       INT         NOT NULL,
    ambiente       ENUM('certificacion','produccion') NOT NULL,
    proximo_folio  INT UNSIGNED NOT NULL,
    folio_hasta    INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_folio_caf (caf_id),
    KEY ix_folio_busqueda (rut_emisor, tipo_dte, ambiente),
    CONSTRAINT fk_folio_caf FOREIGN KEY (caf_id) REFERENCES dte_caf (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Bitacora inmutable de folios asignados. Un registro por folio.
--
-- emision_exitosa:
--   NULL  -> asignado, todavia sin resultado del SII.
--   TRUE  -> SII acepto el DTE.
--   FALSE -> SII rechazo el DTE (el folio queda quemado contablemente).
--
-- El UNIQUE KEY garantiza que un mismo folio JAMAS se duplique aunque la
-- logica de aplicacion tuviera un bug.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dte_folio_log (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rut_emisor       VARCHAR(20) NOT NULL,
    tipo_dte         INT         NOT NULL,
    ambiente         ENUM('certificacion','produccion') NOT NULL,
    folio            INT UNSIGNED NOT NULL,
    emision_exitosa  TINYINT(1)  NULL,
    asignado_at      TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_log_folio (rut_emisor, tipo_dte, ambiente, folio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Certificado digital del SII por emisor + ambiente.
--
-- Un emisor suele tener un certificado distinto para certificacion y para
-- produccion. UNIQUE (rut_emisor, ambiente) hace explicito que solo hay UN
-- certificado vigente por par.
--
-- SEGURIDAD:
--   - cert_data_cifrado y pkey_data_cifrado se almacenan SIEMPRE cifrados con
--     AES-256-GCM (ver CertificadoCrypto). NUNCA en claro.
--   - La llave maestra de cifrado NO vive en esta base: la inyecta el sistema
--     anfitrion (env var / KMS) al instanciar CertificadoCrypto.
--   - Esta tabla NO guarda el .p12/.pfx original: ya viene extraido en PEM.
--
-- A PROPOSITO en tabla SEPARADA de dte_emisor: el certificado es material
-- sensible y va cifrado; los datos administrativos del emisor son publicos y
-- van en claro. Tratarlos como dos cosas distintas permite politicas de
-- respaldo y acceso diferentes.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dte_certificado (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rut_emisor        VARCHAR(20) NOT NULL,
    ambiente          ENUM('certificacion','produccion') NOT NULL,
    cert_data_cifrado LONGTEXT NOT NULL,
    pkey_data_cifrado LONGTEXT NOT NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_cert_emisor (rut_emisor, ambiente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Datos PUBLICOS del emisor (razon social, giro, acteco, resolucion).
--
-- Va en tabla aparte de dte_certificado a proposito: los datos aqui NO son
-- secretos y se guardan en claro. La separacion permite respaldarlos y
-- exponerlos a herramientas administrativas sin tocar la tabla cifrada.
--
-- resolucion_fecha / resolucion_numero: corresponden a la resolucion del SII
-- que autoriza al emisor a emitir DTE electronicos. Para resoluciones nuevas
-- el numero suele ser 0.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dte_emisor (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rut_emisor        VARCHAR(20) NOT NULL,
    ambiente          ENUM('certificacion','produccion') NOT NULL,
    razon_social      VARCHAR(255) NOT NULL,
    giro              VARCHAR(255) NOT NULL,
    acteco            INT          NOT NULL,
    dir_origen        VARCHAR(255) NOT NULL,
    cmna_origen       VARCHAR(100) NOT NULL,
    resolucion_fecha  DATE         NOT NULL,
    resolucion_numero INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uk_emisor (rut_emisor, ambiente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
