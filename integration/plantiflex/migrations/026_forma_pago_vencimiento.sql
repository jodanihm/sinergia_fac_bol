-- =============================================================================
-- Migracion 026: forma de pago y fecha de vencimiento, en dte_emitido y en
-- nota_venta.
--
-- POR QUE AHORA, Y POR QUE ESTE DATO NO SE PUEDE RECUPERAR DESPUES
-- -----------------------------------------------------------------------------
-- La fecha de vencimiento SOLO se puede capturar al emitir. Un documento que se
-- emitio sin ella no la tiene nunca, y no hay forma de reconstruirla: no esta en
-- el XML, no esta en el SII, no esta en ningun lado. Cada dia sin estas columnas
-- es un dia de documentos que quedan sin el dato para siempre.
--
-- Y hay un problema ya en curso: el Formato DTE v2.5 (pag. 4, cambio del
-- 31/05/2017, y pag. 14, campo 13) dice que factura, factura exenta y
-- liquidacion factura "deben informar obligatoriamente" el campo Forma de Pago,
-- y que "en caso de no existir este campo se entendera que tiene valor 2
-- (Credito)". El sistema NUNCA lo ha emitido, asi que TODOS los documentos
-- emitidos hasta hoy quedaron declarados a credito ante el SII sin que nadie lo
-- eligiera. Esta migracion es el primer paso para que eso deje de pasar.
--
--
-- LAS DOS TABLAS EN UNA SOLA MIGRACION
-- -----------------------------------------------------------------------------
-- nota_venta todavia no usa estas columnas -- la carga masiva es la entrega 2 --
-- pero van aqui igual para no partir en dos una migracion que es un solo cambio
-- conceptual. El costo de una columna sin usar es cero; el de dos migraciones
-- para el mismo hecho es una secuencia mas larga que mantener y verificar.
--
--
-- dte_emitido ES TABLA DEL MOTOR, Y ESTA EN LA OTRA FAMILIA DE COLLATION
-- -----------------------------------------------------------------------------
-- El esquema vive en dos familias: las tablas del motor son utf8mb4_0900_ai_ci y
-- las creadas por las migraciones del panel son utf8mb4_unicode_ci. dte_emitido
-- es de las primeras; nota_venta, de las segundas.
--
-- AQUI NO IMPORTA, y conviene dejar dicho por que: una collation solo aplica a
-- columnas de TEXTO -- define como se comparan y ordenan cadenas. Las dos
-- columnas que agrega esta migracion son TINYINT UNSIGNED y DATE, o sea tipos
-- numericos y de fecha, que no tienen character set ni collation. Se pueden
-- comparar entre tablas de familias distintas sin ningun COLLATE explicito y sin
-- riesgo de "Illegal mix of collations". Si alguna vez se agregara una glosa de
-- texto (TermPagoGlosa, por ejemplo), esa SI heredaria la collation de su tabla
-- y volveria a aplicar la regla de no cruzarlas por texto.
--
--
-- TIPOS ELEGIDOS
-- -----------------------------------------------------------------------------
-- forma_pago TINYINT UNSIGNED NULL: la enumeracion del SII tiene exactamente
-- tres valores (1 contado, 2 credito, 3 sin costo), asi que TINYINT sobra y
-- gasta un byte. NULL significa "no se informo", que es justo lo que pasa con
-- los documentos ya emitidos y con todo lo que emita la carga masiva hasta la
-- entrega 2. NO se pone DEFAULT 2 aunque el SII interprete asi el silencio:
-- guardar un 2 que nadie eligio convertiria una suposicion del SII en un hecho
-- de nuestra base, y despues nadie podria distinguir "el usuario eligio credito"
-- de "no se pregunto". El NULL preserva esa diferencia.
--
-- fecha_vencimiento DATE NULL: solo se llena con forma_pago = 2.
--
-- Sin CHECK de la enumeracion: la lista vive en el XSD del SII y en el
-- validador, que ademas puede devolver un mensaje util. Un CHECK aqui dejaria el
-- error en manos de MySQL.
--
-- Sin indice: nadie filtra por forma de pago. El dia que exista un modulo de
-- cobranzas que liste "vencidas al dia de hoy", ESE indice se agrega con su
-- propia migracion y su propia consulta a la vista.
--
--
-- IDEMPOTENCIA
-- -----------------------------------------------------------------------------
-- Mismo patron que la 025: ADD COLUMN no admite IF NOT EXISTS en MySQL 8 de
-- Oracle (esa variante es exclusiva de MariaDB), asi que se consulta
-- information_schema y se arma el ALTER solo si la columna falta. Si ya esta, se
-- ejecuta un SELECT 1 que no hace nada. Nunca borra ni pisa una columna
-- existente.
-- =============================================================================

-- 1. dte_emitido (motor): las dos columnas en un solo ALTER.
SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE dte_emitido
            ADD COLUMN forma_pago TINYINT UNSIGNED NULL
                COMMENT ''IdDoc/FmaPago: 1 contado, 2 credito, 3 sin costo; NULL = no se informo''
                AFTER total,
            ADD COLUMN fecha_vencimiento DATE NULL
                COMMENT ''IdDoc/FchVenc; solo con forma_pago = 2''
                AFTER forma_pago',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dte_emitido' AND COLUMN_NAME = 'forma_pago'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. nota_venta (panel): mismas columnas, sin usar hasta la entrega 2.
SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE nota_venta
            ADD COLUMN forma_pago TINYINT UNSIGNED NULL
                COMMENT ''IdDoc/FmaPago: 1 contado, 2 credito, 3 sin costo; NULL = no se informo''
                AFTER tipo_dte,
            ADD COLUMN fecha_vencimiento DATE NULL
                COMMENT ''IdDoc/FchVenc; solo con forma_pago = 2''
                AFTER forma_pago',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nota_venta' AND COLUMN_NAME = 'forma_pago'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =============================================================================
-- VERIFICACION POSTERIOR (solo lectura). Las cuatro columnas existen, son NULL,
-- y ninguna fila previa quedo con un valor inventado:
--
--   SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
--     FROM information_schema.COLUMNS
--    WHERE TABLE_SCHEMA = DATABASE()
--      AND COLUMN_NAME IN ('forma_pago', 'fecha_vencimiento')
--      AND TABLE_NAME IN ('dte_emitido', 'nota_venta');
--
--   SELECT COUNT(*) AS con_forma_pago FROM dte_emitido WHERE forma_pago IS NOT NULL;
--   -- debe dar 0 inmediatamente despues de aplicarla.
-- =============================================================================
