-- =============================================================================
-- Migracion 051: la pasarela de pago de cada empresa, con sus credenciales.
--
-- QUE RESUELVE
-- -----------------------------------------------------------------------------
-- El link de cobro que va a llevar el correo (migracion 050) se crea contra la
-- pasarela de LA EMPRESA QUE EMITE, no contra una nuestra: el dinero es suyo y
-- llega a su cuenta. Eso obliga a guardar credenciales por tenant, y a poder
-- apagar la funcion para quien no la quiera.
--
--
-- POR QUE UNA TABLA Y NO COLUMNAS EN cuenta
-- -----------------------------------------------------------------------------
-- Aqui nos apartamos del patron por defecto del repo, y conviene decir por que.
--
-- La migracion 040 argumento -- y sigue teniendo razon -- que para UN ajuste
-- escalar por cuenta la columna gana a una tabla clave/valor: mantiene el tipo,
-- mantiene el default y no obliga a un JOIN. cuenta.chat_limite_diario,
-- cuenta.tipo y cuenta.plan son exactamente ese caso.
--
-- Esto no lo es. No es un ajuste: son CINCO campos que solo significan algo
-- juntos (sin credencial no hay pasarela, sin pasarela el interruptor no quiere
-- decir nada), y uno de ellos es UN SECRETO CIFRADO. Meterlos en cuenta
-- significaria que cada SELECT de la cuenta -- que ocurre en practicamente toda
-- pantalla del panel -- arrastra un secreto que ese codigo no necesita ni debe
-- ver. El precedente correcto no es la 040 sino la 031 (dte_logo): configuracion
-- que cuelga de la empresa y lleva un dato pesado o delicado se va a su tabla,
-- para que no viaje en consultas que no la piden.
--
--
-- POR QUE LA CLAVE ES cuenta_id Y NO rut_emisor
-- -----------------------------------------------------------------------------
-- Al reves que dte_logo, que se colgo de rut_emisor porque los tres caminos que
-- generan PDF no tienen cuenta_id a mano. Aqui pasa lo contrario: quien va a
-- leer esta tabla es el runner de correos, y arranca de dte_envio_correo, que YA
-- lleva cuenta_id. Ademas una cuenta de cobro es una relacion comercial de la
-- EMPRESA con la pasarela, no del RUT con el SII.
--
--
-- SIN AMBIENTE, PORQUE NO LO TIENE
-- -----------------------------------------------------------------------------
-- dte_emisor y dte_certificado llevan (rut_emisor, ambiente) porque el SII tiene
-- dos mundos. Una pasarela de pago no: la cuenta de Flow de una empresa es una
-- sola y mueve dinero de verdad. La proteccion no es una columna aqui, es la
-- condicion de PreparadorEnvio, que solo crea ordenes para documentos con
-- ambiente='produccion'. En certificacion no se le cobra a nadie.
--
--
-- EL SECRETO VA CIFRADO, IGUAL QUE EL CERTIFICADO
-- -----------------------------------------------------------------------------
-- credencial_cifrada guarda el secretKey pasado por CertificadoCrypto
-- (AES-256-GCM con CRYPTO_MASTER_KEY), el mismo mecanismo que protege la clave
-- privada del certificado digital. TEXT y no VARBINARY porque ese cifrador
-- devuelve base64, igual que en dte_certificado.
--
-- credencial_publica NO se cifra: el apiKey de Flow identifica al comercio y
-- viaja en claro en cada peticion. Cifrar lo que no es secreto es ceremonia que
-- confunde a quien lee despues sobre que hay que proteger de verdad.
--
-- La 031 dejo anotado que no cifro el logo para no obligar al runner de correos
-- a tener CRYPTO_MASTER_KEY. Aqui SI hace falta, y se comprobo que la tiene: el
-- cron entra por `docker exec sinergia_motor`, asi que hereda el entorno del
-- contenedor, que la lleva para los certificados y los CAF.
--
--
-- habilitado ARRANCA EN 0
-- -----------------------------------------------------------------------------
-- Nadie empieza cobrando por correo sin haberlo pedido. El default apagado
-- ademas hace que la fila pueda existir a medio llenar -- credenciales cargadas
-- pero todavia sin activar -- que es como se configura de verdad: primero se
-- pegan las llaves, se revisan, y despues se enciende.
--
--
-- IDEMPOTENCIA
-- -----------------------------------------------------------------------------
-- CREATE TABLE IF NOT EXISTS. No toca ninguna tabla existente ni ningun dato.
-- =============================================================================

CREATE TABLE IF NOT EXISTS pago_pasarela_cuenta (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cuenta_id          BIGINT UNSIGNED NOT NULL
                       COMMENT 'una pasarela por cuenta (migracion 051)',
    proveedor          VARCHAR(30) NOT NULL DEFAULT 'flow'
                       COMMENT 'flow es la unica implementada; el contrato admite mas (migracion 051)',
    habilitado         TINYINT(1) NOT NULL DEFAULT 0
                       COMMENT '1 = incluir link de pago en los correos de esta empresa (migracion 051)',
    credencial_publica VARCHAR(255) NULL
                       COMMENT 'apiKey de Flow; identifica al comercio, no es secreta (migracion 051)',
    credencial_cifrada TEXT NULL
                       COMMENT 'secretKey cifrada con CertificadoCrypto, nunca vuelve a la pantalla (migracion 051)',
    url_retorno        VARCHAR(500) NULL
                       COMMENT 'a donde vuelve el pagador tras pagar (migracion 051)',
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_pasarela_cuenta (cuenta_id),
    CONSTRAINT fk_pasarela_cuenta FOREIGN KEY (cuenta_id)
        REFERENCES cuenta (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- VERIFICACION POSTERIOR (solo lectura):
--
--   SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pago_pasarela_cuenta';
--   -- pago_pasarela_cuenta, utf8mb4_unicode_ci
--
--   SELECT INDEX_NAME, NON_UNIQUE FROM information_schema.STATISTICS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pago_pasarela_cuenta'
--      AND INDEX_NAME = 'uk_pasarela_cuenta';
--   -- NON_UNIQUE = 0
--
--   SELECT COLUMN_NAME, COLUMN_DEFAULT FROM information_schema.COLUMNS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pago_pasarela_cuenta'
--      AND COLUMN_NAME IN ('habilitado','proveedor');
--   -- habilitado 0, proveedor flow
--
--   SELECT COUNT(*) FROM pago_pasarela_cuenta;
--   -- 0 inmediatamente despues de aplicarla: nadie tiene pasarela hasta que la
--   -- configure desde /configuracion/pagos.
-- =============================================================================
