-- =============================================================================
-- Migracion 055: retira de pago_pasarela_cuenta las columnas que la 054 movio
--                al llavero.
--
-- *** DIFERIDA A PROPOSITO. NO SE APLICA CON LA 054. ***
-- =============================================================================
--
-- QUE BORRA: pago_pasarela_cuenta.credencial_publica, .credencial_cifrada y
-- .ambiente. Las tres quedaron deprecadas al aplicar la 054: el codigo nuevo no
-- las lee ni las escribe, y sus datos viven ahora en pago_pasarela_credencial
-- (las llaves) y en pago_pasarela_cuenta.ambiente_activo (la eleccion).
--
--
-- POR QUE NO VA JUNTO CON LA 054
-- -----------------------------------------------------------------------------
-- Porque un DROP de credencial_cifrada es la unica operacion de este modulo que
-- no se puede deshacer. Todo lo demas se regenera: una orden se vuelve a crear,
-- un correo se vuelve a mandar, un estado se vuelve a consultar. Un secretKey
-- cifrado que se borra por error hay que ir a pedirlo otra vez al panel de Flow,
-- y mientras tanto la empresa no cobra.
--
-- Entre la 054 y esta, las columnas viejas son un respaldo silencioso: nadie las
-- usa, pero si algo del modelo nuevo saliera mal se puede volver atras sin haber
-- perdido nada. Ese es todo el valor de esperar.
--
-- NO SON UNA SEGUNDA FUENTE DE VERDAD MIENTRAS TANTO, que es la objecion obvia:
-- una fuente de verdad es algo que alguien lee. Desde la 054 nadie las lee. Son
-- un residuo con fecha de retirada, y esta migracion existe desde el primer dia
-- justamente para que esa fecha no se olvide: aparece como pendiente en
-- `php scripts/estado_migraciones.php` hasta que se aplique.
--
--
-- CUANDO APLICARLA. Cuando se cumplan las dos:
--
--   1. El codigo del modelo nuevo lleva tiempo corriendo en produccion y se ha
--      visto al menos un ciclo completo: correo con link, pago, callback,
--      conciliador.
--   2. Se ha comprobado que pago_pasarela_credencial tiene una fila por cada
--      configuracion que estuviera en uso:
--
--        SELECT c.cuenta_id, c.proveedor, c.ambiente
--          FROM pago_pasarela_cuenta c
--          LEFT JOIN pago_pasarela_credencial cr
--                 ON cr.cuenta_id = c.cuenta_id
--                AND cr.proveedor = c.proveedor
--                AND cr.ambiente  = c.ambiente
--         WHERE (c.credencial_cifrada IS NOT NULL AND c.credencial_cifrada <> '')
--           AND cr.id IS NULL;
--
--      Tiene que devolver CERO filas. Si devuelve alguna, la 054 no copio esa
--      credencial y borrarla aqui la perderia.
--
-- IDEMPOTENTE, igual que las demas.
-- =============================================================================

-- Guarda: aborta si queda alguna credencial sin copiar al llavero.
SET @sin_copiar := (
    SELECT COUNT(*)
      FROM pago_pasarela_cuenta c
      LEFT JOIN pago_pasarela_credencial cr
             ON cr.cuenta_id = c.cuenta_id
            AND cr.proveedor = c.proveedor
            AND cr.ambiente  = c.ambiente
     WHERE (c.credencial_cifrada IS NOT NULL AND c.credencial_cifrada <> '')
       AND cr.id IS NULL
);
SET @aviso := CONCAT('055 ABORTA: ', @sin_copiar, ' credencial(es) de pago_pasarela_cuenta no estan en el llavero. NO se borra nada.');
SET @sql := IF(@sin_copiar = 0, 'SELECT 1', CONCAT('SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''', @aviso, ''''));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --- Las tres columnas, una por una ------------------------------------------
SET @sql := (
    SELECT IF(COUNT(*) = 1, 'ALTER TABLE pago_pasarela_cuenta DROP COLUMN credencial_cifrada', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pago_pasarela_cuenta'
      AND COLUMN_NAME = 'credencial_cifrada'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 1, 'ALTER TABLE pago_pasarela_cuenta DROP COLUMN credencial_publica', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pago_pasarela_cuenta'
      AND COLUMN_NAME = 'credencial_publica'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(COUNT(*) = 1, 'ALTER TABLE pago_pasarela_cuenta DROP COLUMN ambiente', 'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pago_pasarela_cuenta'
      AND COLUMN_NAME = 'ambiente'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
