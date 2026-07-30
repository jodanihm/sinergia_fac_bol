-- =============================================================================
-- Migracion 024: cola de envio de DTE por correo al receptor.
--
-- ALCANCE DE ESTA MIGRACION: SOLO la tabla. Ningun codigo la usa todavia. El
-- encolado, el envio y el runner van en entregas posteriores.
--
-- POR QUE LA TABLA VA ANTES QUE EL CODIGO Y NO AL REVES: en este proyecto el
-- codigo entra al NAS por bind mount, o sea que se vuelve vivo apenas se
-- escribe el archivo, sin despliegue de por medio. Si el INSERT llegara antes
-- que la tabla, el panel reventaria con "tabla faltante" -- que es exactamente
-- el sintoma de los dos incidentes que ya tuvo el proyecto. Primero el esquema,
-- despues quien lo escribe.
--
-- 100% aditiva: CREATE TABLE IF NOT EXISTS, misma convencion que las otras 11
-- migraciones de creacion. Re-ejecutarla es inofensivo. No toca ninguna tabla
-- ni fila existente.
--
--
-- DECISIONES DE DISENO, para que nadie las deshaga por parecer redundantes
-- -----------------------------------------------------------------------------
--
-- 1. uk_envio_documento (dte_emitido_id) UNIQUE ES LA IDEMPOTENCIA.
--    Un documento no se puede encolar dos veces, y esa garantia vive en el
--    esquema y no en un if de PHP: un "consultar si ya existe y despues
--    insertar" deja una ventana entre las dos operaciones, y dos requests
--    simultaneas encolarian el mismo documento dos veces. Con el UNIQUE, el
--    segundo INSERT falla con 23000 pase lo que pase.
--
--    CONSECUENCIA A TENER PRESENTE EN LA 2b: un reintento NO puede ser un
--    INSERT nuevo. Reintentar es hacer UPDATE de la fila que ya existe
--    (subir intentos, reescribir ultimo_error). No hay forma de tener dos
--    filas para el mismo documento, ni siquiera para un reenvio manual.
--
-- 2. cuenta_id SE GUARDA AUNQUE dte_emitido NO LA TENGA.
--    Las tablas del proyecto viven en DOS COLLATIONS distintas: las 7 del
--    motor son utf8mb4_0900_ai_ci y las 13 creadas por las migraciones del
--    panel son utf8mb4_unicode_ci. Un JOIN entre familias sobre columnas de
--    texto (rut_emisor, por ejemplo) da "Illegal mix of collations" o fuerza
--    un COLLATE explicito en cada consulta. Guardando cuenta_id en el momento
--    de encolar, este modulo nunca necesita cruzar las dos familias para saber
--    de que tenant es un envio.
--
-- 3. destinatario ES UNA FOTO, NO UNA REFERENCIA.
--    Se guarda la direccion tal como estaba al encolar. Si despues cambia el
--    correo del cliente en el maestro, esta fila sigue diciendo a donde se
--    mando (o se iba a mandar) de verdad. Un JOIN a cliente para resolverlo al
--    vuelo ademas cruzaria las dos familias de collation del punto 2.
--
-- 4. ON DELETE CASCADE en la FK a dte_emitido, y NO RESTRICT.
--    Hay precedente real de borrar filas de dte_emitido por operacion. Un
--    correo pendiente de un documento que ya no existe no tiene a quien
--    describir: se va con el. La FK a cuenta, en cambio, queda en el RESTRICT
--    por defecto, igual que fk_nota_venta_cuenta y fk_cliente_cuenta: una
--    cuenta con envios no se borra de un descuido.
--
-- 5. estado ENUM y no VARCHAR: cuatro valores cerrados, validados por el motor.
--    'sin_destinatario' es un estado FINAL y no un error: el documento se
--    emitio bien y simplemente no hay a quien mandarselo. Distinguirlo de
--    'error' evita que el runner lo reintente para siempre.
--
-- 6. TIMESTAMP y no DATETIME. Las 8 tablas con prefijo dte_ del esquema usan
--    TIMESTAMP sin excepcion; las de tenancy y panel (cuenta, usuario, api_key,
--    cliente, producto, lote_carga, nota_venta, admin_auditoria) usan DATETIME.
--    Esta tabla es dte_ y cuelga de dte_emitido, asi que sigue esa convencion.
--
--    enviado_at lleva NULL DEFAULT NULL EXPLICITO. Con
--    explicit_defaults_for_timestamp = OFF, la PRIMERA columna TIMESTAMP de una
--    tabla recibe DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
--    automaticamente, y enviado_at esta declarada antes que created_at: sin el
--    DEFAULT NULL explicito, en un servidor con esa variable apagada esta
--    columna se auto-llenaria sola y mentiria diciendo que todo se envio.
--    Medido en el contenedor de prueba: MySQL 8.0.46 con la variable en 1 (ON),
--    donde no haria falta. Se declara igual porque es una variable de servidor
--    y no del esquema, y este archivo tiene que dar lo mismo en los dos casos.
--
-- 7. Collation utf8mb4_unicode_ci: la tabla la crea una migracion del panel y
--    la escribe el panel, asi que va en la familia del panel. Que su FK apunte
--    a dte_emitido, que es de la otra familia, NO es un problema: la collation
--    solo aplica a columnas de texto, y las dos FK son sobre BIGINT UNSIGNED.
--    Verificado contra MySQL 8.0.46 creando el caso a proposito.
--
-- Tipos de las dos columnas referenciadas, medidos y no supuestos:
--   dte_emitido.id  bigint unsigned NOT NULL AUTO_INCREMENT
--   cuenta.id       bigint unsigned NOT NULL AUTO_INCREMENT
-- Las dos FK declaran BIGINT UNSIGNED para calzar exacto: una FK con tipos que
-- no coinciden falla al crear.
-- =============================================================================

CREATE TABLE IF NOT EXISTS dte_envio_correo (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    dte_emitido_id BIGINT UNSIGNED NOT NULL COMMENT 'documento a enviar; UNIQUE, un documento se encola una sola vez',
    cuenta_id      BIGINT UNSIGNED NOT NULL COMMENT 'tenant, copiado al encolar para no cruzar las dos familias de collation',
    destinatario   VARCHAR(255) NULL COMMENT 'foto del correo del receptor al momento de encolar; NULL si no habia',
    estado         ENUM('pendiente','enviado','error','sin_destinatario') NOT NULL DEFAULT 'pendiente',
    intentos       INT UNSIGNED NOT NULL DEFAULT 0,
    ultimo_error   VARCHAR(500) NULL COMMENT 'solo si estado=error',
    enviado_at     TIMESTAMP NULL DEFAULT NULL COMMENT 'cuando se envio de verdad; NULL mientras no',
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_envio_documento (dte_emitido_id),
    KEY idx_estado (estado),
    CONSTRAINT fk_envio_documento FOREIGN KEY (dte_emitido_id)
        REFERENCES dte_emitido (id) ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT fk_envio_cuenta FOREIGN KEY (cuenta_id)
        REFERENCES cuenta (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
