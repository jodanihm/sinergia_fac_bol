-- =============================================================================
-- Migracion 059: usuario_recuperacion_clave -- "Olvidaste tu contrasena?".
--
-- QUE RESUELVE
-- -----------------------------------------------------------------------------
-- Hasta hoy no habia forma de recuperar una contrasena olvidada: ni enlace en el
-- login ni flujo detras. La unica salida era tocar la base a mano. Esta tabla
-- respalda el flujo de autoservicio: /recuperar pide el email, se manda un
-- enlace de un solo uso, y /recuperar/{token} fija la contrasena nueva.
--
-- UNA FILA POR SOLICITUD, EXISTA O NO EL USUARIO
-- -----------------------------------------------------------------------------
-- usuario_id y token_hash quedan en NULL cuando el email no corresponde a nadie
-- que pueda recuperar. La fila se guarda igual porque es la que cuenta para el
-- limite por IP: sin ella, probar emails inexistentes no costaria nada.
--
-- EL TOKEN NO SE GUARDA, SE GUARDA SU SHA-256
-- -----------------------------------------------------------------------------
-- El enlace lleva 64 hex aleatorios (random_bytes(32)). En la base vive solo su
-- hash: quien lea un respaldo o la tabla no obtiene enlaces utilizables. A
-- diferencia de activacion_token (texto plano en usuario), este da acceso a una
-- cuenta YA activa, asi que el estandar es mas alto. SHA-256 sin sal alcanza:
-- el token tiene 256 bits de entropia, no es una contrasena que adivinar.
--
-- UN SOLO USO Y VENCIMIENTO
-- -----------------------------------------------------------------------------
-- expira_en = solicitud + 60 minutos. usado_en se marca con un UPDATE atomico
-- (WHERE usado_en IS NULL AND expira_en > NOW()): dos envios simultaneos del
-- mismo enlace, solo uno gana. Al usarse uno, los demas pendientes del mismo
-- usuario se marcan usados tambien.
--
-- 100% aditiva: tabla nueva, no toca ninguna existente.
-- =============================================================================

CREATE TABLE IF NOT EXISTS usuario_recuperacion_clave (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id    BIGINT UNSIGNED NULL COMMENT 'NULL si el email no correspondia a un usuario que pueda recuperar',
    token_hash    CHAR(64) NULL COMMENT 'sha256 del token del enlace; NULL si no se emitio enlace',
    expira_en     DATETIME NULL,
    usado_en      DATETIME NULL COMMENT 'cuando se uso (o se invalido); NULL mientras siga vigente',
    solicitado_ip VARCHAR(45) NULL COMMENT 'IP de quien pidio (CF-Connecting-IP); base del limite por IP',
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_recuperacion_token (token_hash),
    KEY idx_recuperacion_usuario (usuario_id, created_at),
    KEY idx_recuperacion_ip (solicitado_ip, created_at),
    CONSTRAINT fk_recuperacion_usuario FOREIGN KEY (usuario_id)
        REFERENCES usuario (id) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
