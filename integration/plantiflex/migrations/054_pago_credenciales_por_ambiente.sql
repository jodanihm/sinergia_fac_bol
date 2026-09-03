-- =============================================================================
-- Migracion 054: las credenciales de la pasarela dejan de ser una sola pareja
--                por cuenta, y cada orden congela el ambiente en que nacio.
-- =============================================================================
--
-- EL PROBLEMA QUE RESUELVE, MEDIDO Y NO SUPUESTO
-- -----------------------------------------------------------------------------
-- pago_pasarela_cuenta tiene UNIQUE(cuenta_id): una fila por empresa, con UNA
-- pareja de credenciales y UN ambiente. La pantalla guarda con
-- ON DUPLICATE KEY UPDATE, asi que pasar de sandbox a produccion SOBRESCRIBE las
-- llaves de sandbox y no quedan en ninguna parte.
--
-- Y como dte_pago_link no guardaba el ambiente, las tres rutas que consultan el
-- estado de una orden (callback, conciliador y la creacion) leian el ambiente y
-- las credenciales VIGENTES, no las de la orden. Consecuencia: en cuanto una
-- empresa pasa a produccion, toda orden suya que siguiera viva se consultaba
-- contra https://www.flow.cl/api con la apiKey de produccion y un token de
-- sandbox. Flow no conoce ese token: fallo permanente, en bucle, y el pago no se
-- registra jamas.
--
-- Habia ademas un camino peor y mas probable. La pantalla sobrescribe SIEMPRE
-- credencial_publica, pero credencial_cifrada SOLO si se escribe un secreto
-- nuevo -- y ensena, con razon, que dejarlo en blanco significa "no la toques".
-- El camino natural al pasar a produccion (pegar la apiKey nueva, dejar el
-- secreto en blanco) producia apiKey de produccion + secretKey de sandbox: todas
-- las firmas invalidas, y ningun mensaje que lo explicara.
--
--
-- EL MODELO: TRES RESPONSABILIDADES QUE NO SE MEZCLAN
-- -----------------------------------------------------------------------------
--   pago_pasarela_cuenta      LA ELECCION ACTIVA. Que hace esta empresa hoy:
--                             que proveedor, en que ambiente, si cobra o no.
--                             UNIQUE(cuenta_id) -- una decision, no varias.
--
--   pago_pasarela_credencial  EL LLAVERO. Con que llaves se habla con un
--                             proveedor en un ambiente.
--                             UNIQUE(cuenta_id, proveedor, ambiente).
--                             No decide nada: por eso puede haber varias filas
--                             sin que ninguna compita con otra.
--
--   dte_pago_link             LA HISTORIA. Con que proveedor y en que ambiente
--                             nacio ESTA orden. Inmutable.
--
-- POR QUE LA ELECCION ACTIVA SE IDENTIFICA POR cuenta_id A SECAS y no por
-- (cuenta_id, proveedor): si el proveedor formara parte de la clave, el dia que
-- exista un segundo proveedor una cuenta podria tener dos filas habilitadas y no
-- habria fuente inequivoca para decidir con cual crear. El proveedor y el
-- ambiente son ATRIBUTOS de la eleccion, no partes de su clave. La unicidad de
-- "un solo ambiente activo" queda garantizada por el esquema, no por una regla
-- que alguien tenga que recordar.
--
--
-- dte_pago_link.proveedor YA EXISTIA Y YA ERA HISTORICO. Se comprobo antes de
-- escribir esto: se escribe una sola vez, en el INSERT de
-- ResolutorLinkPago::reclamar(), y ninguno de los diez UPDATE del modulo lo
-- toca. Asi que aqui NO se agrega: se reutiliza. Lo unico que falta es ambiente.
--
--
-- LAS COLUMNAS VIEJAS NO SE BORRAN AQUI, y es deliberado. Quedan como estan,
-- deprecadas: el codigo nuevo no las lee ni las escribe. Se retiran en una
-- migracion aparte, DIFERIDA, cuando se haya visto al sistema funcionar con el
-- llavero. Borrarlas hoy dejaria sin vuelta atras el unico dato del modulo que
-- no se puede regenerar: un secreto.
--
-- IDEMPOTENTE. Todo va por information_schema + PREPARE/EXECUTE porque Oracle
-- MySQL no tiene ADD COLUMN IF NOT EXISTS.
--
--
-- SI ESTA MIGRACION ABORTA CON "054 ABORTA: N orden(es) ... sin ambiente
-- determinable", esto es lo que hay que hacer:
--
--   1. Ver cuales son:
--
--        SELECT id, dte_emitido_id, cuenta_id, proveedor, estado, url, creado_at
--          FROM dte_pago_link
--         WHERE ambiente IS NULL
--
--   2. Decidir el ambiente de cada una MIRANDO SU HISTORIA, no por comodidad:
--      la url del checkout si la tiene, la fecha de creacion contra cuando esa
--      empresa cambio de ambiente (queda en la auditoria, accion
--      'pago.pasarela.guardada'), o el panel de comercio de la pasarela.
--
--   3. Escribirlo a mano y volver a ejecutar la migracion, que es idempotente:
--
--        UPDATE dte_pago_link SET ambiente = 'produccion' WHERE id = ...
--
-- NO se resuelve poniendo 'sandbox' a todas para que pase. Una orden marcada con
-- el ambiente equivocado se consulta contra el endpoint equivocado, y si era de
-- produccion su pago no se registra nunca -- justo el fallo que esta migracion
-- viene a cerrar.
-- =============================================================================

-- --- 1. El llavero -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS pago_pasarela_credencial (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    cuenta_id          BIGINT UNSIGNED NOT NULL,
    proveedor          VARCHAR(30) NOT NULL DEFAULT 'flow'
                       COMMENT 'flow es la unica implementada; el contrato admite mas (migracion 054)',
    ambiente           ENUM('sandbox','produccion') NOT NULL DEFAULT 'sandbox'
                       COMMENT 'a que endpoint y con que llaves; sandbox NO cobra dinero real (migracion 054)',
    credencial_publica VARCHAR(255) NULL
                       COMMENT 'apiKey; identifica al comercio, no es secreta (migracion 054)',
    credencial_cifrada TEXT NULL
                       COMMENT 'secretKey cifrada con CertificadoCrypto, nunca vuelve a la pantalla (migracion 054)',
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- EL CORAZON DE LA TABLA. Una pareja de llaves por (empresa, proveedor,
    -- ambiente). Es lo que permite tener sandbox y produccion a la vez sin que
    -- una pise a la otra, y lo que hace imposible mezclar la apiKey de un
    -- ambiente con el secretKey de otro: viajan en la misma fila o no viajan.
    UNIQUE KEY uk_pasarela_credencial (cuenta_id, proveedor, ambiente),
    CONSTRAINT fk_pasarela_credencial_cuenta FOREIGN KEY (cuenta_id)
        REFERENCES cuenta (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- 2. La eleccion activa: ambiente_activo ----------------------------------
--
-- COLUMNA NUEVA Y NO RENAME DE 'ambiente'. Un RENAME dejaria el codigo viejo
-- roto entre el ALTER y el despliegue; con una columna nueva las dos conviven
-- los minutos que separan una cosa de la otra.
SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE pago_pasarela_cuenta
            ADD COLUMN ambiente_activo ENUM(''sandbox'',''produccion'') NOT NULL DEFAULT ''sandbox''
                COMMENT ''en que ambiente se crean las ordenes NUEVAS; el historico de cada orden vive en dte_pago_link (migracion 054)''
                AFTER ambiente',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'pago_pasarela_cuenta'
      AND COLUMN_NAME  = 'ambiente_activo'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Backfill: la eleccion activa es el ambiente que la empresa tenia puesto.
UPDATE pago_pasarela_cuenta SET ambiente_activo = ambiente WHERE ambiente_activo <> ambiente;

-- --- 3. Copiar las llaves al llavero -----------------------------------------
--
-- Solo las filas que TIENEN llaves: una fila de configuracion sin credenciales
-- no representa un llavero vacio, representa que nunca se configuro nada, y
-- crear una fila de credenciales en blanco haria creer que si.
INSERT INTO pago_pasarela_credencial (cuenta_id, proveedor, ambiente, credencial_publica, credencial_cifrada)
SELECT c.cuenta_id, c.proveedor, c.ambiente, c.credencial_publica, c.credencial_cifrada
FROM pago_pasarela_cuenta c
WHERE (c.credencial_publica IS NOT NULL AND c.credencial_publica <> '')
   OR (c.credencial_cifrada IS NOT NULL AND c.credencial_cifrada <> '')
ON DUPLICATE KEY UPDATE
    credencial_publica = VALUES(credencial_publica),
    credencial_cifrada = VALUES(credencial_cifrada);

-- --- 4. El ambiente historico de cada orden ----------------------------------
--
-- SECUENCIA SEGURA, EN CUATRO PASOS, y no un ALTER ... NOT NULL de golpe.
-- Anadir NOT NULL con default a una tabla con filas las rellena en silencio con
-- el default: toda orden de produccion existente quedaria marcada como sandbox,
-- que es exactamente el error que esta migracion existe para hacer imposible.
--
--   4a. columna NULLABLE
--   4b. backfill por una regla general
--   4c. ABORTAR si queda algun NULL
--   4d. recien entonces, NOT NULL

-- 4a.
SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE dte_pago_link
            ADD COLUMN ambiente ENUM(''sandbox'',''produccion'') NULL DEFAULT NULL
                COMMENT ''ambiente en que NACIO esta orden. INMUTABLE: la callback y el conciliador resuelven con ESTE, nunca con el activo de la cuenta (migracion 054)''
                AFTER proveedor',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'dte_pago_link'
      AND COLUMN_NAME  = 'ambiente'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4b. Backfill, en dos reglas y por este orden. NINGUNA DE LAS DOS ADIVINA.
--
-- NO HAY REGLA DE ULTIMO RECURSO, y esa ausencia es deliberada. Una version
-- anterior de esta migracion terminaba con
--
--     UPDATE dte_pago_link SET ambiente = 'sandbox' WHERE ambiente IS NULL;
--
-- "porque sandbox es el lado seguro". Era falso por dos motivos. Uno: marcar de
-- sandbox una orden que nacio en produccion la deja consultandose contra el
-- endpoint equivocado, o sea un cobro real que no se registra nunca -- el mismo
-- fallo que esta migracion viene a cerrar, reintroducido por la puerta de atras.
-- Dos, y peor: ese UPDATE hacia que no quedara jamas un NULL, asi que la guarda
-- de mas abajo no podia dispararse nunca. Era una guarda decorativa.
--
-- Si una orden no trae evidencia de su ambiente, la respuesta correcta no es
-- elegir uno: es NO MIGRAR y que una persona mire. Una orden puede mover dinero.

-- REGLA A -- LA URL, QUE ES EVIDENCIA DIRECTA. La devolvio la propia pasarela al
-- crear la orden, y su host dice de que ambiente salio. No es una inferencia
-- sobre la configuracion: es el dato que la orden ya trae.
--
-- SE EXIGE UN HOST RECONOCIBLE, en positivo. Antes bastaba con que la url NO
-- dijera "sandbox" para darla por produccion, y eso convertia cualquier url
-- rara, truncada o de otro proveedor en produccion sin fundamento. Ahora una url
-- que no case con ninguno de los dos patrones no decide nada y la fila sigue a
-- la regla B.
UPDATE dte_pago_link
   SET ambiente = 'sandbox'
 WHERE ambiente IS NULL
   AND url LIKE '%sandbox.flow.cl%';

UPDATE dte_pago_link
   SET ambiente = 'produccion'
 WHERE ambiente IS NULL
   AND (url LIKE '%//www.flow.cl%' OR url LIKE '%//flow.cl%');

-- REGLA B -- LA CONFIGURACION DE SU CUENTA, para las ordenes que nunca llegaron
-- a tener url (estado 'pendiente', 'error' u 'omitido': se reclamaron y la
-- pasarela no devolvio checkout). Es lo unico que se puede saber de ellas, y es
-- legitimo: se crearon con la configuracion que la cuenta tenia.
--
-- EL JOIN ES LA CONDICION. Si la cuenta no tiene fila en pago_pasarela_cuenta no
-- hay nada que copiar, el JOIN no encuentra pareja y la orden se queda en NULL.
-- Tambien se exige que el proveedor coincida: la configuracion de un proveedor
-- no dice nada del ambiente de una orden creada con otro.
UPDATE dte_pago_link p
  JOIN pago_pasarela_cuenta c
    ON c.cuenta_id = p.cuenta_id
   AND c.proveedor = p.proveedor
   SET p.ambiente = c.ambiente
 WHERE p.ambiente IS NULL
   AND c.ambiente IN ('sandbox', 'produccion');

-- 4c. ABORTA si algo quedo sin resolver, y ahora SI puede dispararse.
--
-- Corta la migracion ANTES del NOT NULL. Sin esto, el ALTER rellenaria las filas
-- restantes con el default en silencio y nadie se enteraria hasta que un pago no
-- se registrara.
--
-- POR QUE UN PROCEDIMIENTO Y NO UN SIGNAL SUELTO. La forma obvia era
--
--     SET @sql := IF(@n = 0, 'SELECT 1', 'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ...')
--     PREPARE stmt FROM @sql
--
-- y NO FUNCIONA: MySQL responde "1295 This command is not supported in the
-- prepared statement protocol yet". SIGNAL no se puede preparar. La migracion
-- abortaba igual -- por el 1295 --, pero con un mensaje que no dice nada de lo
-- que pasa, y quien lo leyera buscaria el problema en el sitio equivocado. Se
-- descubrio ejecutandola: leyendo el .sql las dos versiones se parecen.
--
-- CALL si se puede preparar, y un procedimiento de UNA SOLA SENTENCIA no lleva
-- ';' dentro, asi que tampoco necesita DELIMITER ni rompe a los ejecutores que
-- parten el archivo por punto y coma. El MESSAGE_TEXT acepta una variable de
-- usuario, de modo que el aviso puede decir cuantas filas son.
SET @sin_ambiente := (SELECT COUNT(*) FROM dte_pago_link WHERE ambiente IS NULL);
--
-- EL MENSAJE CABE EN 128 CARACTERES, que es el limite de MESSAGE_TEXT: pasarse
-- da "1648 Data too long for condition item", o sea otra vez un aborto con un
-- mensaje que no explica nada. El detalle largo va en la cabecera de este
-- archivo, que es donde se puede leer con calma.
SET @aviso := CONCAT(
    '054 ABORTA: ', @sin_ambiente,
    ' orden(es) de dte_pago_link sin ambiente determinable. Mira ambiente IS NULL. NO se aplico el NOT NULL.'
);

DROP PROCEDURE IF EXISTS mig054_abortar;
CREATE PROCEDURE mig054_abortar() SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = @aviso;

SET @sql := IF(@sin_ambiente = 0, 'SELECT 1', 'CALL mig054_abortar()');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

DROP PROCEDURE IF EXISTS mig054_abortar;

-- 4d. Ahora si: NOT NULL. Sin DEFAULT, a proposito -- quien cree una orden tiene
-- que decir su ambiente explicitamente. Un default aqui volveria a permitir que
-- una orden de produccion naciera marcada como sandbox por omision.
SET @sql := (
    SELECT IF(
        COUNT(*) = 1,
        'ALTER TABLE dte_pago_link
            MODIFY COLUMN ambiente ENUM(''sandbox'',''produccion'') NOT NULL
                COMMENT ''ambiente en que NACIO esta orden. INMUTABLE: la callback y el conciliador resuelven con ESTE, nunca con el activo de la cuenta (migracion 054)''',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'dte_pago_link'
      AND COLUMN_NAME  = 'ambiente'
      AND IS_NULLABLE  = 'YES'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --- 5. Indice para las dos rutas que resuelven por historia ------------------
SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE dte_pago_link
            ADD INDEX ix_pago_link_historia (cuenta_id, proveedor, ambiente)',
        'SELECT 1'
    )
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'dte_pago_link'
      AND INDEX_NAME   = 'ix_pago_link_historia'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
